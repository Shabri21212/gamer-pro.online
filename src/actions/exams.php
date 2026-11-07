<?php
declare(strict_types=1);

trait ActionsExams
{
    private static function doCreateExam(array $user): void
    {
        self::permOrFail($user, 'exams.manage', null, '/exams');
        $title = self::postStr('title', 140);
        if (mb_strlen($title) < 2) {
            self::fail('Впишите название экзамена', '/exams');
        }
        $slug = unique_slug('exams', slugify($title, 'ekzamen'));
        Db::insert('exams', [
            'title' => $title,
            'slug' => $slug,
            'intro' => '',
            'passPercent' => 80,
            'shuffle' => 1,
            'isDraft' => 1,
            'sortOrder' => (int) Db::value('SELECT COALESCE(MAX("sortOrder"), 0) FROM exams') + 1,
            'xpReward' => 100,
            'updatedByUserId' => $user['id'],
            'createdAt' => now_iso(),
            'updatedAt' => now_iso(),
        ]);
        audit($user, 'exams', 'create', 'Новый экзамен: ' . $title, url_to('/exams/' . rawurlencode($slug)));
        flash_set('ok', 'Экзамен создан — добавьте вопросы');
        redirect_to('/exams/' . rawurlencode($slug) . '/edit');
    }

    private static function examWipeQuestions(int $examId): void
    {
        $ids = array_map(static fn ($r) => (int) $r['id'], Db::all('SELECT id FROM exam_questions WHERE "examId" = ?', [$examId]));
        if ($ids) {
            $in = implode(',', $ids);
            Db::exec('DELETE FROM exam_answers WHERE "questionId" IN (' . $in . ')');
            Db::exec('DELETE FROM exam_options WHERE "questionId" IN (' . $in . ')');
        }
        Db::exec('DELETE FROM exam_questions WHERE "examId" = ?', [$examId]);
    }

    /** Вопросы из формы редактора — для повторного показа формы при ошибке. */
    private static function examPostedQuestions(): array
    {
        $out = [];
        foreach ((array) ($_POST['questions'] ?? []) as $q) {
            if (!is_array($q)) {
                continue;
            }
            $kind = isset(EXAM_KIND_LABELS[$q['kind'] ?? '']) ? (string) $q['kind'] : 'single';
            $options = [];
            foreach ((array) ($q['options'] ?? []) as $j => $opt) {
                if (!is_array($opt)) {
                    continue;
                }
                $options[] = [
                    'label' => mb_substr(trim((string) ($opt['label'] ?? '')), 0, 300),
                    'isCorrect' => $kind === 'multiple' ? !empty($opt['correct']) : (string) ($q['correct'] ?? '') === (string) $j,
                ];
            }
            $out[] = [
                'prompt' => mb_substr(trim((string) ($q['prompt'] ?? '')), 0, 600),
                'kind' => $kind,
                'explanation' => mb_substr(trim((string) ($q['explanation'] ?? '')), 0, 1000),
                'options' => $options,
            ];
        }
        return $out;
    }

    private static function saveExamFail(string $slug, string $msg): never
    {
        form_stash_set('exam-edit:' . $slug, [
            'title' => self::postStr('title', 140),
            'intro' => self::postStr('intro', 2000),
            'passPercent' => self::postStr('passPercent', 4),
            'xpReward' => self::postStr('xpReward', 6),
            'shuffle' => isset($_POST['shuffle']),
            'isDraft' => isset($_POST['isDraft']),
            'questions' => self::examPostedQuestions(),
        ]);
        flash_set('error', $msg);
        redirect_to('/exams/' . rawurlencode($slug) . '/edit');
    }

    private static function doSaveExam(array $user): void
    {
        self::permOrFail($user, 'exams.manage', null, '/exams');
        $slug = self::postStr('slug', 128);
        $exam = Db::one('SELECT id FROM exams WHERE slug = ?', [$slug]);
        if (!$exam) {
            self::fail('Экзамен не найден', '/exams');
        }
        $title = self::postStr('title', 140);
        if ($title === '') {
            self::saveExamFail($slug, 'Впишите название экзамена');
        }
        $pass = self::postInt('passPercent');
        if ($pass < 1 || $pass > 100) {
            self::saveExamFail($slug, 'Проходной балл — от 1 до 100 процентов');
        }
        $xp = self::postInt('xpReward');
        if ($xp < 1 || $xp > 5000) {
            self::saveExamFail($slug, 'XP за сдачу — целое число от 1 до 5000');
        }
        $questions = [];
        foreach (self::examPostedQuestions() as $i => $q) {
            $n = $i + 1;
            $options = array_values(array_filter($q['options'], static fn ($o) => $o['label'] !== ''));
            if ($q['prompt'] === '' && !$options) {
                continue;
            }
            if ($q['prompt'] === '') {
                self::saveExamFail($slug, 'Вопрос ' . $n . ': впишите текст вопроса');
            }
            if ($q['kind'] === 'boolean') {
                $options = [
                    ['label' => 'Да', 'isCorrect' => !empty($q['options'][0]['isCorrect'])],
                    ['label' => 'Нет', 'isCorrect' => !empty($q['options'][1]['isCorrect'])],
                ];
            }
            if (count($options) < 2) {
                self::saveExamFail($slug, 'Вопрос ' . $n . ': нужно минимум два варианта ответа');
            }
            $correct = count(array_filter($options, static fn ($o) => $o['isCorrect']));
            if ($correct === 0) {
                self::saveExamFail($slug, 'Вопрос ' . $n . ': отметьте верный ответ');
            }
            if ($q['kind'] !== 'multiple' && $correct > 1) {
                self::saveExamFail($slug, 'Вопрос ' . $n . ': у этого типа верный ответ должен быть один');
            }
            $q['options'] = $options;
            $questions[] = $q;
        }
        $isDraft = isset($_POST['isDraft']) ? 1 : 0;
        if (!$isDraft && !$questions) {
            self::saveExamFail($slug, 'Нельзя опубликовать экзамен без вопросов — оставьте его черновиком');
        }
        $examId = (int) $exam['id'];
        try {
            Db::tx(static function () use ($examId, $title, $pass, $xp, $isDraft, $questions, $user): void {
                Db::exec(
                    'UPDATE exams SET title = ?, intro = ?, "passPercent" = ?, shuffle = ?, "isDraft" = ?, "xpReward" = ?, "updatedByUserId" = ?, "updatedAt" = ? WHERE id = ?',
                    [$title, self::postStr('intro', 2000), $pass, isset($_POST['shuffle']) ? 1 : 0, $isDraft, $xp, $user['id'], now_iso(), $examId],
                );
                self::examWipeQuestions($examId);
                foreach ($questions as $i => $q) {
                    $qid = Db::insert('exam_questions', ['examId' => $examId, 'prompt' => $q['prompt'], 'kind' => $q['kind'], 'explanation' => $q['explanation'], 'sortOrder' => $i + 1]);
                    foreach ($q['options'] as $j => $o) {
                        Db::insert('exam_options', ['questionId' => $qid, 'label' => $o['label'], 'isCorrect' => $o['isCorrect'] ? 1 : 0, 'sortOrder' => $j + 1]);
                    }
                }
            });
        } catch (Throwable $e) {
            error_log('[gamerpro] save exam: ' . $e->getMessage());
            self::saveExamFail($slug, 'Не удалось сохранить экзамен — попробуйте ещё раз');
        }
        audit($user, 'exams', 'update', 'Экзамен «' . $title . '»: ' . plural(count($questions), 'вопрос', 'вопроса', 'вопросов') . ($isDraft ? ', черновик' : ', опубликован'), url_to('/exams/' . rawurlencode($slug)));
        flash_set('ok', $isDraft ? 'Экзамен сохранён черновиком' : 'Экзамен сохранён и опубликован');
        redirect_to('/exams/' . rawurlencode($slug));
    }

    private static function doDeleteExam(array $user): void
    {
        self::permOrFail($user, 'exams.manage', null, '/exams');
        $exam = Db::one('SELECT id, title FROM exams WHERE slug = ?', [self::postStr('slug', 128)]);
        if (!$exam) {
            self::fail('Экзамен не найден', '/exams');
        }
        $examId = (int) $exam['id'];
        Db::tx(static function () use ($examId): void {
            $ids = array_map(static fn ($r) => (int) $r['id'], Db::all('SELECT id FROM exam_attempts WHERE "examId" = ?', [$examId]));
            if ($ids) {
                Db::exec('DELETE FROM exam_answers WHERE "attemptId" IN (' . implode(',', $ids) . ')');
            }
            Db::exec('DELETE FROM exam_attempts WHERE "examId" = ?', [$examId]);
            self::examWipeQuestions($examId);
            Db::exec('DELETE FROM exams WHERE id = ?', [$examId]);
        });
        audit($user, 'exams', 'delete', 'Удалён экзамен: ' . $exam['title'], url_to('/exams'));
        flash_set('ok', 'Экзамен удалён');
        redirect_to('/exams');
    }

    private static function doStartAttempt(array $user): void
    {
        $slug = self::postStr('slug', 128);
        $exam = Db::one('SELECT id, "isDraft" FROM exams WHERE slug = ?', [$slug]);
        if (!$exam || (boolish($exam['isDraft']) && !can($user, 'exams.manage'))) {
            self::fail('Экзамен не найден или ещё не опубликован', '/exams');
        }
        $count = (int) Db::value('SELECT COUNT(*) FROM exam_questions WHERE "examId" = ?', [(int) $exam['id']]);
        if ($count === 0) {
            self::fail('В экзамене пока нет вопросов', '/exams');
        }
        $open = Db::one('SELECT id FROM exam_attempts WHERE "examId" = ? AND "userId" = ? AND status = ?', [(int) $exam['id'], $user['id'], 'in_progress']);
        $id = $open ? (int) $open['id'] : Db::insert('exam_attempts', [
            'examId' => (int) $exam['id'],
            'userId' => $user['id'],
            'status' => 'in_progress',
            'earnedPoints' => 0,
            'maxPoints' => $count,
            'startedAt' => now_iso(),
            'reviewNote' => '',
        ]);
        redirect_to('/exams/' . rawurlencode($slug) . '/attempt/' . $id);
    }

    private static function doSubmitAttempt(array $user): void
    {
        $slug = self::postStr('slug', 128);
        $attemptId = self::postInt('attemptId');
        $attempt = Db::one('SELECT * FROM exam_attempts WHERE id = ?', [$attemptId]);
        if (!$attempt || $attempt['userId'] !== $user['id']) {
            self::fail('Попытка не найдена', '/exams');
        }
        if ($attempt['status'] !== 'in_progress') {
            redirect_to('/exams/' . rawurlencode($slug) . '/result/' . $attemptId);
        }
        $exam = Db::one('SELECT id, title, "passPercent", "xpReward" FROM exams WHERE id = ?', [(int) $attempt['examId']]);
        $questions = $exam ? self::examQuestions((int) $exam['id']) : [];
        if (!$questions) {
            self::fail('В экзамене нет вопросов', '/exams');
        }
        $posted = is_array($_POST['a'] ?? null) ? $_POST['a'] : [];
        [$earned, $max] = Db::tx(static function () use ($questions, $posted, $attemptId): array {
            Db::exec('DELETE FROM exam_answers WHERE "attemptId" = ?', [$attemptId]);
            $earned = 0;
            foreach ($questions as $q) {
                $allowed = [];
                $correct = [];
                foreach ($q['options'] as $o) {
                    $allowed[$o['id']] = true;
                    if ($o['isCorrect']) {
                        $correct[] = $o['id'];
                    }
                }
                $raw = $posted[$q['id']] ?? [];
                $chosen = [];
                foreach ((is_array($raw) ? $raw : [$raw]) as $v) {
                    $v = (int) $v;
                    if (isset($allowed[$v])) {
                        $chosen[$v] = $v;
                    }
                }
                $chosen = array_values($chosen);
                sort($chosen);
                sort($correct);
                $ok = $correct !== [] && $chosen === $correct;
                $earned += $ok ? 1 : 0;
                Db::insert('exam_answers', ['attemptId' => $attemptId, 'questionId' => $q['id'], 'selectedOptionIds' => implode(',', $chosen), 'textAnswer' => '', 'isCorrect' => $ok ? 1 : 0]);
            }
            return [$earned, count($questions)];
        });
        $score = (int) round($earned / max(1, $max) * 100);
        $status = $score >= (int) $exam['passPercent'] ? 'passed' : 'failed';
        Db::exec(
            'UPDATE exam_attempts SET status = ?, "earnedPoints" = ?, "maxPoints" = ?, "scorePercent" = ?, "submittedAt" = ? WHERE id = ?',
            [$status, $earned, $max, $score, now_iso(), $attemptId],
        );
        audit($user, 'exams', $status, self::displayName($user) . ' — «' . $exam['title'] . '»: ' . $score . '% (' . ($status === 'passed' ? 'сдан' : 'не сдан') . ')', url_to('/exams/' . rawurlencode($slug) . '/result/' . $attemptId));
        flash_set('ok', $status === 'passed' ? 'Экзамен сдан на ' . $score . '%! +' . max(1, (int) $exam['xpReward']) . ' XP' : 'Результат ' . $score . '% — не хватило до ' . (int) $exam['passPercent'] . '%. Посмотрите разбор и попробуйте снова.');
        redirect_to('/exams/' . rawurlencode($slug) . '/result/' . $attemptId);
    }
}
