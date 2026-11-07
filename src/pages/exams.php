<?php
declare(strict_types=1);

trait PagesExams
{
    private static function examBySlug(string $slug, bool $includeDrafts): ?array
    {
        $exam = Db::one('SELECT * FROM exams WHERE slug = ?', [$slug]);
        if (!$exam || (!$includeDrafts && boolish($exam['isDraft']))) {
            return null;
        }
        $exam['id'] = (int) $exam['id'];
        $exam['passPercent'] = (int) $exam['passPercent'];
        $exam['xpReward'] = max(1, (int) ($exam['xpReward'] ?? 100));
        $exam['shuffle'] = boolish($exam['shuffle']);
        $exam['isDraft'] = boolish($exam['isDraft']);
        $exam['questions'] = self::examQuestions($exam['id']);
        return $exam;
    }

    private static function examQuestions(int $examId): array
    {
        $qs = Db::all('SELECT * FROM exam_questions WHERE "examId" = ? ORDER BY "sortOrder", id', [$examId]);
        if (!$qs) {
            return [];
        }
        $byQ = [];
        $ids = implode(',', array_map(static fn ($q) => (int) $q['id'], $qs));
        foreach (Db::all('SELECT * FROM exam_options WHERE "questionId" IN (' . $ids . ') ORDER BY "sortOrder", id') as $o) {
            $byQ[(int) $o['questionId']][] = ['id' => (int) $o['id'], 'label' => (string) $o['label'], 'isCorrect' => boolish($o['isCorrect'])];
        }
        $out = [];
        foreach ($qs as $q) {
            $q['id'] = (int) $q['id'];
            $q['options'] = $byQ[$q['id']] ?? [];
            $out[] = $q;
        }
        return $out;
    }

    private static function examPrepareAttempt(array $questions, bool $shuffle, int $seed): array
    {
        $ordered = $shuffle ? exam_shuffle($questions, $seed) : array_values($questions);
        foreach ($ordered as $i => $q) {
            if ($shuffle && $q['kind'] !== 'boolean') {
                $ordered[$i]['options'] = exam_shuffle($q['options'], $seed + $i + 1);
            }
        }
        return $ordered;
    }

    private static function examMyProgress(int $examId, string $userId): array
    {
        $open = null;
        $best = null;
        $count = 0;
        foreach (Db::all('SELECT id, status, "scorePercent" FROM exam_attempts WHERE "examId" = ? AND "userId" = ? ORDER BY id DESC', [$examId, $userId]) as $a) {
            if ($a['status'] === 'in_progress') {
                $open ??= $a;
                continue;
            }
            $count++;
            $score = $a['scorePercent'] === null ? -1 : (int) $a['scorePercent'];
            if ($best === null || $score > ($best['scorePercent'] === null ? -1 : (int) $best['scorePercent'])) {
                $best = $a;
            }
        }
        return [
            'openAttemptId' => $open ? (int) $open['id'] : null,
            'attemptCount' => $count,
            'bestScore' => $best && $best['scorePercent'] !== null ? (int) $best['scorePercent'] : null,
            'status' => $best['status'] ?? null,
            'bestAttemptId' => $best ? (int) $best['id'] : null,
        ];
    }

    private static function examCatalog(array $user): array
    {
        $drafts = can($user, 'exams.manage');
        $counts = [];
        foreach (Db::all('SELECT "examId", COUNT(*) AS n FROM exam_questions GROUP BY "examId"') as $r) {
            $counts[(int) $r['examId']] = (int) $r['n'];
        }
        $out = [];
        foreach (Db::all('SELECT * FROM exams ORDER BY "sortOrder", id') as $e) {
            if (!$drafts && boolish($e['isDraft'])) {
                continue;
            }
            $e['id'] = (int) $e['id'];
            $e['xpReward'] = max(1, (int) ($e['xpReward'] ?? 100));
            $e['isDraft'] = boolish($e['isDraft']);
            $e['qCount'] = $counts[$e['id']] ?? 0;
            $e['progress'] = self::examMyProgress($e['id'], $user['id']);
            $out[] = $e;
        }
        return $out;
    }

    /** Уровень по XP: каждый опубликованный экзамен — ступень. */
    private static function examXpState(array $catalog): array
    {
        $total = 0;
        $earned = 0;
        $passed = 0;
        $thresholds = [0];
        foreach ($catalog as $e) {
            if ($e['isDraft']) {
                continue;
            }
            $total += $e['xpReward'];
            $thresholds[] = $total;
            if (($e['progress']['status'] ?? '') === 'passed') {
                $earned += $e['xpReward'];
                $passed++;
            }
        }
        $max = count($thresholds);
        $level = 1;
        foreach ($thresholds as $i => $need) {
            if ($earned >= $need) {
                $level = $i + 1;
            }
        }
        $level = min($level, $max);
        if ($level >= $max) {
            $toNext = 0;
            $bar = 100;
        } else {
            $prev = $thresholds[$level - 1];
            $next = $thresholds[$level];
            $toNext = max(0, $next - $earned);
            $bar = (int) round(($earned - $prev) / max(1, $next - $prev) * 100);
        }
        return ['earned' => $earned, 'total' => $total, 'level' => $level, 'maxLevel' => $max, 'toNext' => $toNext, 'bar' => $bar, 'passed' => $passed, 'published' => $max - 1];
    }

    private static function examStatusChip(array $e): string
    {
        $p = $e['progress'];
        if ($e['isDraft']) {
            return chip('Черновик', 'outline', 'pencil');
        }
        if ($p['openAttemptId']) {
            return chip('Не завершён', 'day', 'clock');
        }
        if (($p['status'] ?? '') === 'passed') {
            return chip('Сдан · ' . (int) $p['bestScore'] . '%', 'ink', 'check');
        }
        if ($p['attemptCount'] > 0) {
            return chip('Не сдан · ' . (int) $p['bestScore'] . '%', 'red', 'x');
        }
        return chip('Не начат', 'neutral');
    }

    private static function examHref(string $slug, string $tail = ''): string
    {
        return url_to('/exams/' . rawurlencode($slug) . $tail);
    }

    private static function pageExams(array $user): void
    {
        $manage = can($user, 'exams.manage');
        $catalog = self::examCatalog($user);
        $xp = self::examXpState($catalog);
        $actions = $manage ? '<a class="' . btn_class('outline') . '" href="' . h(url_to('/manage/exams')) . '">' . icon('clipboard-check', 'size-4') . 'Результаты</a><button type="button" class="' . btn_class() . '" data-dialog-open="exam-new">' . icon('plus', 'size-4') . 'Новый экзамен</button>' : '';
        $html = page_head('Экзамены', 'Короткие тесты по регламентам. За сдачу начисляется XP — уровень растёт с каждым сданным экзаменом. Пересдавать можно сколько угодно.', $actions);

        $hero = '<div class="relative flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between">';
        $hero .= '<div class="flex items-center gap-5"><div class="cut flex size-20 shrink-0 flex-col items-center justify-center bg-primary text-primary-foreground" style="--cut:14px"><span class="text-[10px] font-semibold tracking-[0.18em] uppercase">Ур.</span><span class="font-display text-3xl leading-none font-semibold tabular-nums">' . $xp['level'] . '</span></div>';
        $hero .= '<div class="flex flex-col gap-1"><p class="font-display text-2xl font-semibold tabular-nums">' . num_fmt($xp['earned']) . ' <span class="text-base text-sidebar-muted">/ ' . num_fmt($xp['total']) . ' XP</span></p>';
        $hero .= '<p class="text-sm text-sidebar-foreground">' . ($xp['total'] < 1 ? 'Опубликованных экзаменов пока нет' : ($xp['toNext'] > 0 ? 'До уровня ' . ($xp['level'] + 1) . ' — ' . $xp['toNext'] . ' XP' : 'Максимальный уровень — все экзамены сданы')) . '</p></div></div>';
        $hero .= '<dl class="grid grid-cols-2 gap-6 text-sm"><div><dt class="text-sidebar-muted">Сдано</dt><dd class="font-display text-xl font-semibold tabular-nums">' . $xp['passed'] . ' из ' . $xp['published'] . '</dd></div><div><dt class="text-sidebar-muted">Курс</dt><dd class="font-display text-xl font-semibold tabular-nums">' . ($xp['total'] > 0 ? (int) round($xp['earned'] / $xp['total'] * 100) : 0) . '%</dd></div></dl></div>';
        $hero .= '<div class="relative h-1.5 overflow-hidden rounded-full bg-sidebar-accent" role="progressbar" aria-label="Прогресс до следующего уровня" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $xp['bar'] . '"><div class="h-full rounded-full bg-primary" style="width:' . $xp['bar'] . '%"></div></div>';
        $html .= '<section class="cut flex flex-col gap-5 bg-sidebar p-5 text-sidebar-accent-foreground sm:p-7" style="--cut:22px" aria-label="Ваш уровень">' . $hero . '</section>';

        if (!$catalog) {
            $html .= empty_state('grad', 'Экзаменов пока нет', $manage ? 'Создайте первый тест — кнопка «Новый экзамен».' : 'Когда руководитель добавит тесты, они появятся здесь.');
        } else {
            $html .= '<div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">';
            foreach ($catalog as $i => $e) {
                $p = $e['progress'];
                $cta = $p['openAttemptId'] ? 'Продолжить' : ($p['attemptCount'] > 0 ? (($p['status'] ?? '') === 'passed' ? 'Пересдать' : 'Попробовать снова') : 'Начать');
                $inner = '<div class="flex items-start justify-between gap-3"><span class="font-display text-sm font-semibold text-muted-foreground tabular-nums">' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) . '</span>' . self::examStatusChip($e) . '</div>';
                $inner .= '<h2 class="font-display text-lg leading-snug font-semibold text-pretty">' . h($e['title']) . '</h2>';
                $inner .= '<p class="text-sm text-muted-foreground">' . h(plural($e['qCount'], 'вопрос', 'вопроса', 'вопросов')) . ' · проходной ' . (int) $e['passPercent'] . '% · <span class="font-semibold text-foreground">+' . $e['xpReward'] . ' XP</span></p>';
                $inner .= '<span class="mt-auto inline-flex items-center gap-1.5 pt-2 text-sm font-semibold text-primary-ink">' . h($cta) . icon('arrow', 'size-4 transition-transform group-hover:translate-x-0.5') . '</span>';
                $html .= '<a href="' . h(self::examHref((string) $e['slug'])) . '" class="group flex min-h-48 flex-col gap-2 rounded-lg border border-border bg-card p-5 shadow-xs transition-colors hover:border-foreground/25">' . $inner . '</a>';
            }
            $html .= '</div>';
        }
        if ($manage) {
            $f = form_open('create_exam', 'class="flex flex-col gap-4"') . ui_field('Название', '<input name="title" class="' . field_class() . '" required maxlength="140" placeholder="Например: Приём смены">');
            $f .= '<p class="text-sm text-muted-foreground">Экзамен создастся черновиком — добавьте вопросы и опубликуйте.</p><div class="flex justify-end gap-2"><button type="button" class="' . btn_class('ghost') . '" data-dialog-close>Отмена</button><button class="' . btn_class() . '">Создать</button></div></form>';
            $html .= dialog_html('exam-new', 'Новый экзамен', $f, 'max-w-md');
        }
        self::layout($user, $html, ['title' => 'Экзамены']);
    }

    private static function pageExamStart(array $user, string $slug): void
    {
        $manage = can($user, 'exams.manage');
        $exam = self::examBySlug($slug, $manage);
        if (!$exam) {
            self::notFound($user, 'Экзамен не найден или ещё не опубликован.');
            return;
        }
        $p = self::examMyProgress($exam['id'], $user['id']);
        $qn = count($exam['questions']);
        $html = back_link(url_to('/exams'), 'Все экзамены');
        $actions = $manage ? '<a class="' . btn_class('outline', 'sm') . '" href="' . h(self::examHref($slug, '/edit')) . '">' . icon('pencil', 'size-4') . 'Редактировать</a>' : '';
        $html .= page_head((string) $exam['title'], '', $actions, $exam['isDraft'] ? 'Черновик · виден только руководителям' : 'Экзамен');
        $intro = trim((string) $exam['intro']);
        $body = '<p class="leading-relaxed text-pretty">' . ($intro !== '' ? nl2br(h($intro), false) : 'Тест с вариантами ответов. После сдачи сразу видно процент и разбор каждого вопроса.') . '</p>';
        $body .= '<dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">';
        foreach ([['Вопросов', (string) $qn], ['Проходной', $exam['passPercent'] . '%'], ['Награда', '+' . $exam['xpReward'] . ' XP'], ['Лучший', $p['bestScore'] !== null ? $p['bestScore'] . '%' : '—']] as [$k, $v]) {
            $body .= '<div class="flex flex-col gap-1 rounded-md bg-muted/60 px-3 py-2.5"><dt class="text-xs text-muted-foreground">' . h($k) . '</dt><dd class="font-display text-xl font-semibold tabular-nums">' . h($v) . '</dd></div>';
        }
        $body .= '</dl><ul class="flex flex-col gap-1.5 text-sm text-muted-foreground">';
        if ($exam['shuffle']) {
            $body .= '<li class="flex items-center gap-2">' . icon('refresh', 'size-4') . 'Вопросы и варианты идут в случайном порядке</li>';
        }
        $body .= '<li class="flex items-center gap-2">' . icon('history', 'size-4') . 'Пересдавать можно сколько угодно — в зачёт идёт лучший результат</li>';
        if (($p['status'] ?? '') === 'passed') {
            $body .= '<li class="flex items-center gap-2 text-foreground">' . icon('trophy', 'size-4') . 'Экзамен сдан, ' . $exam['xpReward'] . ' XP уже в зачёте</li>';
        }
        $body .= '</ul><div class="flex flex-wrap items-center gap-3 border-t border-border pt-5">';
        if ($qn === 0) {
            $body .= '<p class="text-sm text-muted-foreground">В экзамене пока нет вопросов.</p>';
        } else {
            $label = $p['openAttemptId'] ? 'Продолжить' : ($p['attemptCount'] > 0 ? 'Пересдать' : 'Начать экзамен');
            $body .= form_open('start_attempt') . hidden('slug', $slug) . '<button class="' . btn_class('primary', 'lg') . '">' . h($label) . icon('arrow', 'size-4') . '</button></form>';
        }
        if ($p['bestAttemptId']) {
            $body .= '<a class="' . btn_class('ghost') . '" href="' . h(self::examHref($slug, '/result/' . $p['bestAttemptId'])) . '">' . icon('file-text', 'size-4') . 'Мой лучший результат</a>';
        }
        $body .= '</div>';
        $html .= card($body, 'flex flex-col gap-5 p-5 sm:p-7');
        if ($manage) {
            $html .= '<div class="flex justify-end">' . form_open('delete_exam', confirm_attr('Удалить экзамен «' . $exam['title'] . '» вместе со всеми результатами?')) . hidden('slug', $slug) . '<button class="' . btn_class('danger', 'sm') . '">' . icon('trash', 'size-4') . 'Удалить экзамен</button></form></div>';
        }
        self::layout($user, $html, ['title' => (string) $exam['title'], 'width' => 'narrow']);
    }

    private static function examEditorQuestion(int $index, array $q): string
    {
        $kind = isset(EXAM_KIND_LABELS[$q['kind'] ?? '']) ? (string) $q['kind'] : 'single';
        $options = $q['options'] ?? [];
        if ($kind === 'boolean') {
            $options = [
                ['label' => 'Да', 'isCorrect' => !empty($options[0]['isCorrect'])],
                ['label' => 'Нет', 'isCorrect' => !empty($options[1]['isCorrect'])],
            ];
        }
        while (count($options) < 2) {
            $options[] = ['label' => '', 'isCorrect' => false];
        }
        $p = 'questions[' . $index . ']';
        $html = '<li class="flex flex-col gap-3 rounded-lg border border-border bg-card p-4 shadow-xs" data-question>';
        $html .= '<div class="flex items-start gap-3"><span class="mt-2 w-6 shrink-0 text-right font-display text-sm font-semibold text-muted-foreground tabular-nums" data-q-num>' . ($index + 1) . '</span>';
        $html .= '<textarea name="' . $p . '[prompt]" rows="2" maxlength="600" class="' . textarea_class('min-h-14') . '" placeholder="Текст вопроса" aria-label="Текст вопроса" data-prompt>' . h((string) ($q['prompt'] ?? '')) . '</textarea>';
        $html .= '<button type="button" class="' . btn_class('danger-ghost', 'icon-sm') . ' mt-1" data-q-remove aria-label="Удалить вопрос">' . icon('trash', 'size-4') . '</button></div>';
        $html .= '<div class="flex flex-col gap-3 pl-9">' . ui_select($p . '[kind]', EXAM_KIND_LABELS, $kind, 'aria-label="Тип вопроса" data-kind', 'h-9 sm:w-64');
        $html .= '<div class="flex flex-col gap-2" data-options>';
        foreach ($options as $j => $opt) {
            $type = $kind === 'multiple' ? 'checkbox' : 'radio';
            $name = $kind === 'multiple' ? $p . '[options][' . $j . '][correct]' : $p . '[correct]';
            $value = $kind === 'multiple' ? '1' : (string) $j;
            $html .= '<div class="flex items-center gap-2" data-option><input type="' . $type . '" name="' . h($name) . '" value="' . $value . '" class="' . check_class('size-4.5') . '"' . (!empty($opt['isCorrect']) ? ' checked' : '') . ' aria-label="Верный ответ" data-correct>';
            $html .= '<input name="' . $p . '[options][' . $j . '][label]" class="' . field_class('h-9') . '" maxlength="300" placeholder="Вариант ' . ($j + 1) . '" value="' . h((string) ($opt['label'] ?? '')) . '"' . ($kind === 'boolean' ? ' readonly' : '') . ' aria-label="Вариант ответа" data-opt-label>';
            $html .= '<button type="button" class="' . btn_class('danger-ghost', 'icon-sm') . ($kind === 'boolean' || count($options) <= 2 ? ' invisible' : '') . '" data-opt-remove aria-label="Удалить вариант">' . icon('x', 'size-4') . '</button></div>';
        }
        $html .= '</div><button type="button" class="' . btn_class('quiet', 'sm') . ' w-fit' . ($kind === 'boolean' ? ' hidden' : '') . '" data-opt-add>' . icon('plus', 'size-4') . 'Вариант</button>';
        $html .= '<input name="' . $p . '[explanation]" class="' . field_class('h-9') . '" maxlength="1000" placeholder="Пояснение для разбора после сдачи — необязательно" value="' . h((string) ($q['explanation'] ?? '')) . '" aria-label="Пояснение" data-expl>';
        return $html . '<p class="text-xs text-muted-foreground">Отметьте кружком или галочкой верный ответ.</p></div></li>';
    }

    private static function pageExamEdit(array $user, string $slug): void
    {
        $exam = self::examBySlug($slug, true);
        if (!$exam) {
            self::notFound($user, 'Экзамен не найден.');
            return;
        }
        $stash = form_stash_take('exam-edit:' . $slug);
        if ($stash) {
            foreach (['title', 'intro', 'passPercent', 'xpReward'] as $k) {
                $exam[$k] = $stash[$k] ?? $exam[$k];
            }
            $exam['shuffle'] = !empty($stash['shuffle']);
            $exam['isDraft'] = !empty($stash['isDraft']);
            $exam['questions'] = is_array($stash['questions'] ?? null) ? $stash['questions'] : $exam['questions'];
        }
        $html = back_link(self::examHref($slug), 'К экзамену') . page_head('Редактор экзамена', 'Порядок вопросов можно не продумывать — если включено перемешивание, каждый получит свой порядок.', '', 'Экзамены');
        $f = '<form method="post" class="flex flex-col gap-6" data-exam-editor>' . csrf_field() . hidden('_action', 'save_exam') . hidden('slug', $slug);
        $s = ui_field('Название', '<input name="title" class="' . field_class('h-11 text-base font-semibold') . '" maxlength="140" required value="' . h((string) $exam['title']) . '">');
        $s .= ui_field('Вступление', '<textarea name="intro" class="' . textarea_class('min-h-20') . '" maxlength="2000" placeholder="Что проверяет экзамен и к чему готовиться">' . h((string) $exam['intro']) . '</textarea>');
        $s .= '<div class="grid gap-4 sm:grid-cols-2">' . ui_field('Проходной балл, %', '<input name="passPercent" type="number" min="1" max="100" class="' . field_class() . '" value="' . h((string) $exam['passPercent']) . '">') . ui_field('XP за сдачу', '<input name="xpReward" type="number" min="1" max="5000" class="' . field_class() . '" value="' . h((string) $exam['xpReward']) . '">') . '</div>';
        $s .= '<div class="flex flex-col gap-2.5"><label class="flex items-center gap-2.5 text-sm"><input type="checkbox" name="shuffle" value="1" class="' . check_class() . '"' . ($exam['shuffle'] ? ' checked' : '') . '>Перемешивать вопросы и варианты</label>';
        $s .= '<label class="flex items-center gap-2.5 text-sm"><input type="checkbox" name="isDraft" value="1" class="' . check_class() . '"' . ($exam['isDraft'] ? ' checked' : '') . '>Черновик — сотрудники не видят</label></div>';
        $f .= card($s, 'flex flex-col gap-4 p-5');
        $questions = $exam['questions'] ?: [['prompt' => '', 'kind' => 'single', 'explanation' => '', 'options' => []]];
        $f .= '<section class="flex flex-col gap-3">' . section_title('Вопросы', '', (string) count($exam['questions'])) . '<ol class="flex flex-col gap-3" data-questions>';
        foreach (array_values($questions) as $i => $q) {
            $f .= self::examEditorQuestion($i, $q);
        }
        $f .= '</ol><template data-q-tpl>' . self::examEditorQuestion(0, ['prompt' => '', 'kind' => 'single', 'explanation' => '', 'options' => []]) . '</template>';
        $f .= '<button type="button" class="' . btn_class('outline') . ' self-start" data-add-question>' . icon('plus', 'size-4') . 'Добавить вопрос</button></section>';
        $f .= '<div class="sticky bottom-20 z-10 flex items-center justify-between gap-2 rounded-lg border border-border bg-card/95 p-3 shadow-lg backdrop-blur lg:bottom-4"><span class="text-sm text-muted-foreground"><span data-q-count>' . count($questions) . '</span> в экзамене</span><div class="flex gap-2"><a class="' . btn_class('ghost') . '" href="' . h(self::examHref($slug)) . '">Отмена</a><button class="' . btn_class() . '">' . icon('save', 'size-4') . 'Сохранить</button></div></div></form>';
        self::layout($user, $html . $f, ['title' => 'Редактор: ' . $exam['title'], 'width' => 'narrow']);
    }

    private static function pageExamAttempt(array $user, string $slug, int $attemptId): void
    {
        $attempt = Db::one('SELECT * FROM exam_attempts WHERE id = ?', [$attemptId]);
        if (!$attempt || $attempt['userId'] !== $user['id']) {
            self::notFound($user, 'Попытка не найдена.');
            return;
        }
        if ($attempt['status'] !== 'in_progress') {
            redirect_to('/exams/' . rawurlencode($slug) . '/result/' . $attemptId);
        }
        $exam = self::examBySlug($slug, can($user, 'exams.manage'));
        if (!$exam || $exam['id'] !== (int) $attempt['examId']) {
            self::notFound($user, 'Экзамен не найден.');
            return;
        }
        $questions = self::examPrepareAttempt($exam['questions'], $exam['shuffle'], $attemptId);
        $total = count($questions);
        $html = '<div class="flex flex-col gap-6" data-exam-take data-total="' . $total . '">';
        $html .= '<header class="flex flex-col gap-3"><div class="flex items-center justify-between gap-3"><a class="text-sm font-medium text-muted-foreground hover:text-foreground" href="' . h(self::examHref($slug)) . '">' . icon('x', 'mr-1 inline size-4') . 'Выйти</a><span class="text-sm font-semibold tabular-nums" data-exam-counter>Вопрос 1 из ' . $total . '</span></div>';
        $html .= '<h1 class="font-display text-xl font-semibold text-balance">' . h((string) $exam['title']) . '</h1>' . progress_bar((int) round(100 / max(1, $total)), 'Пройдено вопросов', 'exam-progress') . '</header>';
        $html .= '<form method="post" id="exam-take" class="flex flex-col gap-6">' . csrf_field() . hidden('_action', 'submit_attempt') . hidden('slug', $slug) . hidden('attemptId', $attemptId);
        foreach ($questions as $i => $q) {
            $multi = $q['kind'] === 'multiple';
            $html .= '<fieldset class="exam-step flex flex-col gap-4' . ($i === 0 ? ' is-on' : '') . '" data-exam-step="' . ($i + 1) . '"><legend class="mb-1 flex flex-col gap-2"><span class="text-xs font-semibold tracking-wide text-muted-foreground uppercase">Вопрос ' . ($i + 1) . '</span><span class="font-display text-lg leading-snug font-semibold text-pretty">' . h((string) $q['prompt']) . '</span>' . ($multi ? '<span class="text-sm font-normal text-muted-foreground">Отметьте все верные варианты</span>' : '') . '</legend>';
            foreach ($q['options'] as $opt) {
                $html .= '<label class="answer-card"><input type="' . ($multi ? 'checkbox' : 'radio') . '" name="a[' . (int) $q['id'] . ']' . ($multi ? '[]' : '') . '" value="' . (int) $opt['id'] . '" class="peer sr-only"><span class="answer-mark" aria-hidden="true">' . icon('check', 'size-3.5') . '</span><span class="min-w-0 flex-1 leading-snug">' . h($opt['label']) . '</span></label>';
            }
            $html .= '</fieldset>';
        }
        $html .= '<div class="flex items-center justify-between gap-3 border-t border-border pt-5"><button type="button" class="' . btn_class('ghost') . '" data-exam-prev>' . icon('arrow-left', 'size-4') . 'Назад</button>';
        $html .= '<button type="button" class="' . btn_class('primary', 'lg') . '" data-exam-next>Дальше' . icon('arrow', 'size-4') . '</button><button class="' . btn_class('primary', 'lg') . ' hidden" data-exam-submit>' . icon('check', 'size-4') . 'Сдать экзамен</button></div></form></div>';
        self::layout($user, card($html, 'p-5 sm:p-8'), ['title' => (string) $exam['title'], 'width' => 'narrow']);
    }

    private static function pageExamResult(array $user, string $slug, int $attemptId): void
    {
        $manage = can($user, 'exams.manage');
        $attempt = Db::one('SELECT * FROM exam_attempts WHERE id = ?', [$attemptId]);
        if (!$attempt || ($attempt['userId'] !== $user['id'] && !$manage)) {
            self::notFound($user, 'Результат не найден.');
            return;
        }
        $exam = self::examBySlug($slug, $manage);
        if (!$exam || $exam['id'] !== (int) $attempt['examId']) {
            self::notFound($user, 'Экзамен не найден.');
            return;
        }
        if ($attempt['status'] === 'in_progress') {
            redirect_to('/exams/' . rawurlencode($slug) . '/attempt/' . $attemptId);
        }
        $byQ = [];
        foreach (Db::all('SELECT * FROM exam_answers WHERE "attemptId" = ?', [$attemptId]) as $a) {
            $byQ[(int) $a['questionId']] = $a;
        }
        $other = $attempt['userId'] !== $user['id'] ? (string) (Db::value('SELECT COALESCE(e.name, u.name) FROM "user" u LEFT JOIN employees e ON e."userId" = u.id WHERE u.id = ?', [$attempt['userId']]) ?? 'Сотрудник') : null;
        $passed = $attempt['status'] === 'passed';
        $score = (int) $attempt['scorePercent'];
        $html = back_link($other ? url_to('/manage/exams') : self::examHref($slug), $other ? 'Результаты сотрудников' : 'К экзамену');
        $hero = '<div class="flex flex-col gap-1"><p class="text-[11px] font-semibold tracking-[0.18em] text-sidebar-muted uppercase">' . h((string) $exam['title']) . ($other ? ' · ' . h($other) : '') . '</p>';
        $hero .= '<p class="font-display text-3xl font-semibold">' . ($passed ? 'Экзамен сдан' : 'Пока не сдан') . '</p>';
        $hero .= '<p class="text-sm text-sidebar-foreground">' . (int) $attempt['earnedPoints'] . ' из ' . (int) $attempt['maxPoints'] . ' верно · проходной ' . $exam['passPercent'] . '% · ' . h(format_dt((string) $attempt['submittedAt'])) . '</p></div>';
        $hero .= '<span class="font-display text-6xl leading-none font-semibold tabular-nums ' . ($passed ? 'text-sidebar-accent-foreground' : 'text-brand') . '">' . $score . '<span class="text-3xl">%</span></span>';
        $html .= '<section class="cut flex flex-col justify-between gap-5 bg-sidebar p-6 text-sidebar-accent-foreground sm:flex-row sm:items-end sm:p-8" style="--cut:22px">' . $hero . '</section>';
        if (!$other) {
            $html .= '<div class="flex flex-wrap gap-2">' . form_open('start_attempt') . hidden('slug', $slug) . '<button class="' . btn_class($passed ? 'outline' : 'primary') . '">' . icon('refresh', 'size-4') . 'Пересдать</button></form><a class="' . btn_class('ghost') . '" href="' . h(url_to('/exams')) . '">Все экзамены</a></div>';
        }
        $html .= section_title('Разбор ответов');
        $html .= '<ol class="flex flex-col gap-3">';
        foreach ($exam['questions'] as $i => $q) {
            $ans = $byQ[(int) $q['id']] ?? null;
            $chosen = exam_parse_selected((string) ($ans['selectedOptionIds'] ?? ''));
            $ok = $ans !== null && $ans['isCorrect'] !== null ? boolish($ans['isCorrect']) : null;
            $inner = '<div class="flex items-start gap-3"><span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-bold ' . ($ok === true ? 'bg-foreground text-background' : ($ok === false ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground')) . '">' . ($ok === true ? icon('check', 'size-3.5') : ($ok === false ? icon('x', 'size-3.5') : '?')) . '</span>';
            $inner .= '<p class="font-semibold leading-snug text-pretty">' . ($i + 1) . '. ' . h((string) $q['prompt']) . '</p></div><ul class="flex flex-col gap-1.5 pl-9">';
            foreach ($q['options'] as $opt) {
                $picked = in_array($opt['id'], $chosen, true);
                $cls = $opt['isCorrect'] ? 'border-foreground/30 bg-muted/70 font-medium' : ($picked ? 'border-primary/40 bg-primary/5 text-primary-ink' : 'border-border text-muted-foreground');
                $note = $opt['isCorrect'] ? ($picked ? 'верно, выбран' : 'верный ответ') : ($picked ? 'выбран' : '');
                $inner .= '<li class="flex items-center justify-between gap-3 rounded-md border px-3 py-2 text-sm ' . $cls . '"><span class="min-w-0">' . h($opt['label']) . '</span>' . ($note !== '' ? '<span class="shrink-0 text-xs font-semibold">' . h($note) . '</span>' : '') . '</li>';
            }
            $inner .= '</ul>';
            if (trim((string) $q['explanation']) !== '') {
                $inner .= '<p class="ml-9 flex items-start gap-2 rounded-md bg-muted/50 px-3 py-2 text-sm leading-relaxed text-muted-foreground">' . icon('info', 'mt-0.5 size-4 shrink-0') . h((string) $q['explanation']) . '</p>';
            }
            if ($ans === null) {
                $inner .= '<p class="ml-9 text-xs text-muted-foreground">Вопрос изменён после этой попытки — ответ не сохранился.</p>';
            }
            $html .= '<li>' . card($inner, 'flex flex-col gap-3 p-4') . '</li>';
        }
        $html .= '</ol>';
        self::layout($user, $html, ['title' => 'Результат: ' . $exam['title'], 'width' => 'narrow']);
    }

    private static function pageManageExams(array $user): void
    {
        $examId = (int) ($_GET['exam'] ?? 0);
        $exams = Db::all('SELECT id, title, slug, "passPercent", "isDraft" FROM exams ORDER BY "sortOrder", id');
        $params = ['in_progress'];
        $where = 'a.status != ?';
        if ($examId > 0) {
            $where .= ' AND a."examId" = ?';
            $params[] = $examId;
        }
        $rows = Db::all(
            'SELECT a.id, a.status, a."scorePercent", a."submittedAt", a."userId", e.title AS "examTitle", e.slug AS "examSlug",
                    COALESCE(emp.name, u.name) AS "userName"
             FROM exam_attempts a
             JOIN exams e ON e.id = a."examId"
             LEFT JOIN "user" u ON u.id = a."userId"
             LEFT JOIN employees emp ON emp."userId" = a."userId"
             WHERE ' . $where . ' ORDER BY a."submittedAt" DESC, a.id DESC LIMIT 300',
            $params,
        );
        $staff = Db::all('SELECT e.name, e."userId" FROM employees e WHERE e."isActive" = 1 AND e."userId" IS NOT NULL ORDER BY e.name');
        $best = [];
        foreach ($rows as $r) {
            $k = $r['userId'] . '|' . $r['examSlug'];
            if (!isset($best[$k]) || (int) $r['scorePercent'] > (int) $best[$k]['scorePercent']) {
                $best[$k] = $r;
            }
        }
        $items = [['href' => url_to('/manage/exams'), 'label' => 'Все экзамены', 'active' => $examId === 0]];
        foreach ($exams as $e) {
            $items[] = ['href' => url_to('/manage/exams', ['exam' => (int) $e['id']]), 'label' => (string) $e['title'], 'active' => $examId === (int) $e['id']];
        }
        $html = page_head('Результаты экзаменов', 'Все сданные попытки сотрудников. Нажмите на строку — откроется разбор ответов.', '<a class="' . btn_class('outline') . '" href="' . h(url_to('/exams')) . '">' . icon('grad', 'size-4') . 'К экзаменам</a>');
        $html .= segmented($items, 'Экзамен');
        if ($examId > 0 && $staff) {
            $slug = '';
            foreach ($exams as $e) {
                if ((int) $e['id'] === $examId) {
                    $slug = (string) $e['slug'];
                }
            }
            $done = 0;
            $chips = '';
            foreach ($staff as $s) {
                $b = $best[$s['userId'] . '|' . $slug] ?? null;
                $ok = $b && $b['status'] === 'passed';
                $done += $ok ? 1 : 0;
                $chips .= chip(short_name((string) $s['name']) . ($b ? ' · ' . (int) $b['scorePercent'] . '%' : ''), $ok ? 'ink' : ($b ? 'red' : 'outline'), $ok ? 'check' : '');
            }
            $html .= card(card_head('Кто сдал', 'Сдали ' . $done . ' из ' . count($staff) . ' сотрудников с доступом', '', 'users') . '<div class="mt-4 flex flex-wrap gap-1.5">' . $chips . '</div>', 'p-5');
        }
        if (!$rows) {
            $html .= empty_state('clipboard-check', 'Попыток пока нет', 'Когда сотрудники начнут сдавать экзамены, результаты появятся здесь.');
        } else {
            $html .= table_open(['Сотрудник', 'Экзамен', 'Результат', 'Когда', ['', 'w-0']]);
            foreach ($rows as $r) {
                $href = self::examHref((string) $r['examSlug'], '/result/' . (int) $r['id']);
                $st = (string) $r['status'];
                $html .= '<tr class="border-t border-border transition-colors hover:bg-muted/40">';
                $html .= td('Сотрудник', '<a class="font-semibold hover:underline" href="' . h($href) . '">' . h((string) ($r['userName'] ?? '—')) . '</a>');
                $html .= td('Экзамен', h((string) $r['examTitle']), 'text-muted-foreground');
                $html .= td('Результат', chip((ATTEMPT_STATUS_LABELS[$st] ?? $st) . ($r['scorePercent'] !== null ? ' · ' . (int) $r['scorePercent'] . '%' : ''), $st === 'passed' ? 'ink' : ($st === 'failed' ? 'red' : 'day')));
                $html .= td('Когда', '<span class="whitespace-nowrap">' . h(format_dt((string) $r['submittedAt'])) . '</span>', 'text-muted-foreground');
                $html .= td('', '<a class="' . btn_class('quiet', 'icon-sm') . '" href="' . h($href) . '" aria-label="Открыть разбор">' . icon('chevronright', 'size-4') . '</a>');
                $html .= '</tr>';
            }
            $html .= table_close();
        }
        self::layout($user, $html, ['title' => 'Результаты экзаменов']);
    }
}
