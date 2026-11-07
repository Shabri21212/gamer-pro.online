<?php
declare(strict_types=1);

trait PortalActions
{
    private static function ownerOrFail(array $user): void
    {
        if (!$user['isOwner']) {
            flash_set('error', 'Действие доступно только владельцу');
            redirect_back('/');
        }
    }

    private static function displayName(array $user): string
    {
        return (string) ($user['employeeName'] ?? $user['name']);
    }

    private static function postInt(string $key): int
    {
        return (int) ($_POST[$key] ?? 0);
    }

    private static function postStr(string $key, int $max = 2000): string
    {
        return mb_substr(trim((string) ($_POST[$key] ?? '')), 0, $max);
    }

    private static function doCompleteTask(array $user): void
    {
        $taskId = self::postInt('taskId');
        $task = $taskId > 0 ? Db::one('SELECT * FROM staff_tasks WHERE id=?', [$taskId]) : null;
        if (!$task) {
            flash_set('error', 'Задание не найдено');
            redirect_back('/');
        }
        $eid = (int) ($user['employeeId'] ?? 0);
        if ($eid < 1 || (int) $task['employeeId'] !== $eid) {
            flash_set('error', 'Это задание назначено не вам');
            redirect_back('/');
        }
        $my = self::myShift($user);
        if (!$my || $my['date'] !== $task['date']) {
            flash_set('error', 'Задание относится к другой смене');
            redirect_back('/');
        }
        $name = self::displayName($user);
        $existing = Db::one('SELECT id FROM staff_task_done WHERE "taskId"=? AND "userId"=?', [$taskId, $user['id']]);
        if ($existing) {
            Db::exec('UPDATE staff_task_done SET "userName"=?, "completedAt"=? WHERE id=?', [$name, now_iso(), $existing['id']]);
        } else {
            Db::exec(
                'INSERT INTO staff_task_done ("taskId","userId","userName","completedAt") VALUES (?,?,?,?)',
                [$taskId, $user['id'], $name, now_iso()],
            );
        }
        flash_set('ok', 'Задание отмечено выполненным');
        redirect_back('/');
    }

    private static function doUncompleteTask(array $user): void
    {
        $taskId = self::postInt('taskId');
        Db::exec('DELETE FROM staff_task_done WHERE "taskId"=? AND "userId"=?', [$taskId, $user['id']]);
        flash_set('ok', 'Отметка снята');
        redirect_back('/');
    }

    private static function doSaveShift(array $user): void
    {
        self::ownerOrFail($user);
        $date = self::postStr('date', 10);
        $branchId = self::postInt('branchId');
        $employeeId = self::postInt('employeeId');
        $shiftType = self::postStr('shiftType', 16);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            flash_set('error', 'Некорректная дата');
            redirect_back('/schedule');
        }
        if (!in_array($shiftType, ['day', 'night', 'full'], true)) {
            flash_set('error', 'Выберите тип смены');
            redirect_back('/schedule');
        }
        $employee = Db::one('SELECT id, name, "homeBranchId" FROM employees WHERE id=? AND "isActive"=1', [$employeeId]);
        $branch = Db::one('SELECT id, name FROM branches WHERE id=?', [$branchId]);
        if (!$employee || !$branch) {
            flash_set('error', 'Выберите филиал и сотрудника');
            redirect_back('/schedule');
        }
        $busy = Db::one('SELECT id, "branchId" FROM shifts WHERE "employeeId"=? AND date=?', [$employeeId, $date]);
        if ($busy) {
            if ((int) $busy['branchId'] === $branchId) {
                Db::exec('UPDATE shifts SET "shiftType"=?, "updatedAt"=? WHERE id=?', [$shiftType, now_iso(), $busy['id']]);
                flash_set('ok', 'Смена ' . $employee['name'] . ' обновлена');
                redirect_back('/schedule');
            }
            $other = Db::one('SELECT name FROM branches WHERE id=?', [$busy['branchId']]);
            flash_set('error', $employee['name'] . ' в этот день уже стоит в смену на ' . ($other['name'] ?? 'другом филиале'));
            redirect_back('/schedule');
        }
        Db::exec(
            'INSERT INTO shifts (date,"branchId","employeeId","shiftType","createdAt","updatedAt") VALUES (?,?,?,?,?,?)',
            [$date, $branchId, $employeeId, $shiftType, now_iso(), now_iso()],
        );
        $msg = (int) $employee['homeBranchId'] === $branchId
            ? $employee['name'] . ' поставлен в смену'
            : $employee['name'] . ' поставлен как подмена на ' . $branch['name'];
        flash_set('ok', $msg);
        redirect_back('/schedule');
    }

    private static function doDeleteShift(array $user): void
    {
        self::ownerOrFail($user);
        $id = self::postInt('id');
        if ($id < 1) {
            flash_set('error', 'Смена не найдена');
            redirect_back('/schedule');
        }
        Db::exec('DELETE FROM shifts WHERE id=?', [$id]);
        flash_set('ok', 'Смена убрана');
        redirect_back('/schedule');
    }

    private static function doSaveHandover(array $user): void
    {
        $branchId = self::postInt('branchId');
        $date = self::postStr('date', 10);
        $shiftType = self::postStr('shiftType', 16);
        $phase = self::postStr('phase', 8);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !in_array($shiftType, ['day', 'night', 'full'], true) || !in_array($phase, ['open', 'close'], true)) {
            flash_set('error', 'Неверные данные смены');
            redirect_back('/shift');
        }
        $cashExpected = self::postStr('cashExpected', 16);
        $cashActual = self::postStr('cashActual', 16);
        $exp = $cashExpected === '' ? null : (int) $cashExpected;
        $act = $cashActual === '' ? null : (int) $cashActual;
        foreach ([$exp, $act] as $amount) {
            if ($amount === null) {
                continue;
            }
            if ($amount < 0 || $amount > 10_000_000) {
                flash_set('error', 'Проверьте суммы по кассе');
                redirect_back('/shift');
            }
        }
        $note = self::postStr('noteForNext', 2000);
        $name = self::displayName($user);
        $existing = Db::one(
            'SELECT id FROM shift_reports WHERE "branchId"=? AND date=? AND "shiftType"=? AND phase=?',
            [$branchId, $date, $shiftType, $phase],
        );
        if ($existing) {
            $reportId = (int) $existing['id'];
            Db::exec(
                'UPDATE shift_reports SET "userId"=?, "userName"=?, "cashExpected"=?, "cashActual"=?, "noteForNext"=? WHERE id=?',
                [$user['id'], $name, $exp, $act, $note, $reportId],
            );
            Db::exec('DELETE FROM shift_report_checks WHERE "reportId"=?', [$reportId]);
        } else {
            Db::exec(
                'INSERT INTO shift_reports ("branchId",date,"shiftType",phase,"userId","userName","cashExpected","cashActual","noteForNext","createdAt") VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$branchId, $date, $shiftType, $phase, $user['id'], $name, $exp, $act, $note, now_iso()],
            );
            $reportId = (int) Db::lastId();
        }
        $allowed = Db::all('SELECT id FROM shift_checklist_items WHERE phase=? AND "isActive"=1', [$phase]);
        $ids = array_map(fn ($r) => (int) $r['id'], $allowed);
        $checked = array_map('intval', (array) ($_POST['check'] ?? []));
        foreach ($ids as $itemId) {
            Db::exec(
                'INSERT INTO shift_report_checks ("reportId","itemId",done,comment) VALUES (?,?,?,?)',
                [$reportId, $itemId, in_array($itemId, $checked, true) ? 1 : 0, ''],
            );
        }
        $cut = now_tyumen()->modify('-70 days')->format('Y-m-d');
        $old = Db::all('SELECT id FROM shift_reports WHERE date < ?', [$cut]);
        foreach ($old as $row) {
            Db::exec('DELETE FROM shift_report_checks WHERE "reportId"=?', [$row['id']]);
            Db::exec('DELETE FROM shift_reports WHERE id=?', [$row['id']]);
        }
        flash_set('ok', $phase === 'open' ? 'Смена принята' : 'Смена сдана');
        redirect_back('/shift');
    }

    private static function doToggleClean(array $user): void
    {
        $branchId = self::postInt('branchId');
        $pc = self::postInt('pcNumber');
        $week = self::postStr('weekStart', 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $week)) {
            flash_set('error', 'Неверная неделя');
            redirect_back('/cleaning');
        }
        $branch = Db::one('SELECT "pcCount" FROM branches WHERE id=?', [$branchId]);
        if (!$branch || $pc < 1 || $pc > (int) $branch['pcCount']) {
            flash_set('error', 'Такого места нет в этом клубе');
            redirect_back('/cleaning');
        }
        if (!$user['isOwner']) {
            $assignment = Db::one(
                'SELECT a."employeeId", e.name FROM cleaning_seat_assignments a JOIN employees e ON e.id=a."employeeId"
                 WHERE a."branchId"=? AND a."weekStart"=? AND a."pcNumber"=?',
                [$branchId, $week, $pc],
            );
            if (!$assignment) {
                flash_set('error', 'Чистить можно только места, которые закрепил владелец');
                redirect_back('/cleaning');
            }
            if ((int) $assignment['employeeId'] !== (int) $user['employeeId']) {
                flash_set('error', 'Место ' . $pc . ' закреплено за ' . $assignment['name']);
                redirect_back('/cleaning');
            }
        }
        $existing = Db::one(
            'SELECT id, "doneByUserId" FROM keyboard_cleanings WHERE "branchId"=? AND "pcNumber"=? AND "weekStart"=?',
            [$branchId, $pc, $week],
        );
        if ($existing) {
            if ($existing['doneByUserId'] !== $user['id'] && !$user['isOwner']) {
                flash_set('error', 'Снять чужую отметку может только владелец');
                redirect_back('/cleaning');
            }
            Db::exec('DELETE FROM keyboard_cleanings WHERE id=?', [$existing['id']]);
        } else {
            Db::exec(
                'INSERT INTO keyboard_cleanings ("branchId","pcNumber","weekStart","doneByUserId","doneByName","doneAt") VALUES (?,?,?,?,?,?)',
                [$branchId, $pc, $week, $user['id'], self::displayName($user), now_iso()],
            );
        }
        redirect_back('/cleaning');
    }

    private static function doAssignSeats(array $user): void
    {
        self::ownerOrFail($user);
        $branchId = self::postInt('branchId');
        $week = self::postStr('weekStart', 10);
        $employeeId = self::postInt('employeeId');
        $pc = self::postInt('pcNumber');
        $branch = Db::one('SELECT "pcCount" FROM branches WHERE id=?', [$branchId]);
        $emp = Db::one('SELECT id FROM employees WHERE id=? AND "isActive"=1', [$employeeId]);
        if (!$branch || !$emp || $pc < 1 || $pc > (int) $branch['pcCount']) {
            flash_set('error', 'Не удалось закрепить место');
            redirect_back('/cleaning');
        }
        $existing = Db::one(
            'SELECT id, "employeeId" FROM cleaning_seat_assignments WHERE "branchId"=? AND "weekStart"=? AND "pcNumber"=?',
            [$branchId, $week, $pc],
        );
        if ($existing) {
            if ((int) $existing['employeeId'] === $employeeId) {
                Db::exec('DELETE FROM cleaning_seat_assignments WHERE id=?', [$existing['id']]);
            } else {
                Db::exec('UPDATE cleaning_seat_assignments SET "employeeId"=? WHERE id=?', [$employeeId, $existing['id']]);
            }
        } else {
            Db::exec(
                'INSERT INTO cleaning_seat_assignments ("branchId","weekStart","employeeId","pcNumber","createdAt") VALUES (?,?,?,?,?)',
                [$branchId, $week, $employeeId, $pc, now_iso()],
            );
        }
        redirect_back('/cleaning');
    }

    private static function doSaveIncident(array $user): void
    {
        $branchId = self::postInt('branchId');
        $kind = self::postStr('kind', 16);
        $title = self::postStr('title', 200);
        $details = self::postStr('details', 2000);
        if (!in_array($kind, ['incident', 'tech'], true)) {
            flash_set('error', 'Неверный тип записи');
            redirect_back('/issues');
        }
        if (mb_strlen($title) < 3) {
            flash_set('error', 'Опишите проблему хотя бы парой слов');
            redirect_back('/issues');
        }
        if (!Db::one('SELECT id FROM branches WHERE id=?', [$branchId])) {
            flash_set('error', 'Выберите филиал');
            redirect_back('/issues');
        }
        $pcRaw = self::postStr('pcNumber', 8);
        $pc = ($kind === 'tech' && $pcRaw !== '') ? (int) $pcRaw : null;
        Db::exec(
            'INSERT INTO incidents ("branchId",kind,"pcNumber",title,details,status,"createdByUserId","createdByName","createdAt","resolutionNote") VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$branchId, $kind, $pc, $title, $details, 'new', $user['id'], self::displayName($user), now_iso(), ''],
        );
        flash_set('ok', $kind === 'tech' ? 'Заявка по технике создана' : 'Происшествие записано');
        redirect_back('/issues');
    }

    private static function doResolveIncident(array $user): void
    {
        $id = self::postInt('id');
        $row = Db::one('SELECT id, status FROM incidents WHERE id=?', [$id]);
        if (!$row) {
            flash_set('error', 'Запись не найдена');
            redirect_back('/issues');
        }
        Db::exec(
            'UPDATE incidents SET status=?, "resolvedByUserId"=?, "resolvedByName"=?, "resolvedAt"=? WHERE id=?',
            ['done', $user['id'], self::displayName($user), now_iso(), $id],
        );
        flash_set('ok', 'Запись закрыта');
        redirect_back('/issues');
    }

    private static function doSaveNews(array $user): void
    {
        self::ownerOrFail($user);
        $title = self::postStr('title', 200);
        $body = self::postStr('body', 5000);
        if (mb_strlen($title) < 3) {
            flash_set('error', 'Нужен заголовок');
            redirect_back('/news');
        }
        $pinned = isset($_POST['isPinned']) ? 1 : 0;
        Db::exec(
            'INSERT INTO announcements (title,body,"isPinned","isDraft","createdByUserId","createdByName","createdAt","updatedAt") VALUES (?,?,?,?,?,?,?,?)',
            [$title, $body, $pinned, 0, $user['id'], $user['name'], now_iso(), now_iso()],
        );
        flash_set('ok', 'Объявление опубликовано');
        redirect_back('/news');
    }

    private static function doDeleteNews(array $user): void
    {
        self::ownerOrFail($user);
        $id = self::postInt('id');
        $row = Db::one('SELECT id FROM announcements WHERE id=?', [$id]);
        if (!$row) {
            flash_set('error', 'Объявление не найдено');
            redirect_back('/news');
        }
        Db::exec('DELETE FROM announcement_reads WHERE "announcementId"=?', [$id]);
        Db::exec('DELETE FROM announcements WHERE id=?', [$id]);
        flash_set('ok', 'Объявление удалено');
        redirect_back('/news');
    }

    private static function doReadNews(array $user): void
    {
        $id = self::postInt('id');
        $target = Db::one('SELECT id, "isDraft" FROM announcements WHERE id=?', [$id]);
        if (!$target || boolish($target['isDraft'])) {
            redirect_back('/news');
        }
        $already = Db::one('SELECT id FROM announcement_reads WHERE "announcementId"=? AND "userId"=?', [$id, $user['id']]);
        if (!$already) {
            Db::exec(
                'INSERT INTO announcement_reads ("announcementId","userId","userName","readAt") VALUES (?,?,?,?)',
                [$id, $user['id'], self::displayName($user), now_iso()],
            );
        }
        redirect_back('/news');
    }

    private static function doSaveReview(array $user): void
    {
        $phone = normalize_phone(self::postStr('guestPhone', 32));
        if (!$phone) {
            flash_set('error', 'Номер телефона указан неверно — нужно 10 цифр');
            redirect_back('/reviews');
        }
        $promo = self::portal_json('review_promo', DEFAULT_REVIEW_PROMO);
        $amount = (int) $promo['textBonus'];
        if ($amount <= 0) {
            flash_set('error', 'Сумма бонуса не может быть нулевой — проверьте условия акции');
            redirect_back('/reviews');
        }
        $kind = 'text';
        $branchId = self::postInt('branchId');
        $branch = Db::one('SELECT id, name FROM branches WHERE id=?', [$branchId]);
        if (!$branch) {
            flash_set('error', 'Выберите филиал');
            redirect_back('/reviews');
        }
        $existing = Db::one(
            'SELECT id FROM review_bonuses WHERE "branchId"=? AND "guestPhone"=? AND status != ?',
            [$branchId, $phone, 'rejected'],
        );
        if ($existing) {
            flash_set('error', format_phone($phone) . ' уже получал бонусы за отзыв в «' . $branch['name'] . '» — повторно на этом филиале нельзя');
            redirect_back('/reviews');
        }
        Db::exec(
            'INSERT INTO review_bonuses ("branchId","guestPhone","reviewKind","bonusAmount",status,note,"createdByUserId","createdByName","createdAt") VALUES (?,?,?,?,?,?,?,?,?)',
            [$branchId, $phone, $kind, $amount, 'pending', '', $user['id'], self::displayName($user), now_iso()],
        );
        flash_set('ok', 'Заявка отправлена: ' . $amount . ' бонусов для ' . format_phone($phone));
        redirect_back('/reviews');
    }

    private static function doDecideReview(array $user): void
    {
        self::ownerOrFail($user);
        $id = self::postInt('id');
        $status = self::postStr('decision', 16);
        if (!in_array($status, ['credited', 'rejected'], true)) {
            flash_set('error', 'Неизвестное решение');
            redirect_back('/reviews');
        }
        if (!Db::one('SELECT id FROM review_bonuses WHERE id=?', [$id])) {
            flash_set('error', 'Заявка не найдена');
            redirect_back('/reviews');
        }
        Db::exec(
            'UPDATE review_bonuses SET status=?, "decidedByUserId"=?, "decidedByName"=?, "decidedAt"=? WHERE id=?',
            [$status, $user['id'], self::displayName($user), now_iso(), $id],
        );
        flash_set('ok', $status === 'credited' ? 'Бонусы отмечены как зачисленные' : 'Заявка отклонена');
        redirect_back('/reviews');
    }

    private static function doSaveComp(array $user): void
    {
        $phone = normalize_phone(self::postStr('guestPhone', 32));
        if (!$phone) {
            flash_set('error', 'Номер телефона указан неверно — нужно 10 цифр');
            redirect_back('/compensations');
        }
        $reason = self::postStr('reason', 1000);
        if (mb_strlen($reason) < 5) {
            flash_set('error', 'Опишите причину: что случилось у гостя');
            redirect_back('/compensations');
        }
        $amount = (int) preg_replace('/\D+/', '', self::postStr('bonusAmount', 16));
        if ($amount < 1 || $amount > 5000) {
            flash_set('error', 'Сумма бонусов — целое число от 1 до 5000');
            redirect_back('/compensations');
        }
        $branchId = self::postInt('branchId');
        if (!Db::one('SELECT id FROM branches WHERE id=?', [$branchId])) {
            flash_set('error', 'Выберите филиал');
            redirect_back('/compensations');
        }
        Db::exec(
            'INSERT INTO compensations ("branchId","guestPhone",reason,"actionsTaken","bonusAmount",status,"createdByUserId","createdByName","createdAt") VALUES (?,?,?,?,?,?,?,?,?)',
            [$branchId, $phone, $reason, self::postStr('actionsTaken', 1000), $amount, 'pending', $user['id'], self::displayName($user), now_iso()],
        );
        flash_set('ok', 'Компенсация записана: ' . $amount . ' бонусов для ' . format_phone($phone));
        redirect_back('/compensations');
    }

    private static function doDecideComp(array $user): void
    {
        self::ownerOrFail($user);
        $id = self::postInt('id');
        $status = self::postStr('decision', 16);
        if (!in_array($status, ['credited', 'rejected'], true) || !Db::one('SELECT id FROM compensations WHERE id=?', [$id])) {
            flash_set('error', 'Запись не найдена');
            redirect_back('/compensations');
        }
        Db::exec(
            'UPDATE compensations SET status=?, "decidedByUserId"=?, "decidedByName"=?, "decidedAt"=? WHERE id=?',
            [$status, $user['id'], self::displayName($user), now_iso(), $id],
        );
        flash_set('ok', $status === 'credited' ? 'Отмечено как зачисленное' : 'Компенсация отклонена');
        redirect_back('/compensations');
    }

    private static function doSaveWiki(array $user): void
    {
        self::ownerOrFail($user);
        $id = self::postInt('id');
        $title = self::postStr('title', 120);
        $html = (string) ($_POST['contentHtml'] ?? '');
        if ($title === '' || !Db::one('SELECT id, slug FROM knowledge_articles WHERE id=?', [$id])) {
            flash_set('error', 'Раздел не найден');
            redirect_back('/wiki');
        }
        if (strlen($html) > 200000) {
            flash_set('error', 'Текст слишком большой');
            redirect_back('/wiki');
        }
        $clean = preg_replace('#<(script|iframe|object|embed)[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $row = Db::one('SELECT slug FROM knowledge_articles WHERE id=?', [$id]);
        Db::exec(
            'UPDATE knowledge_articles SET title=?, "contentHtml"=?, "updatedByUserId"=?, "updatedAt"=? WHERE id=?',
            [$title, $clean, $user['id'], now_iso(), $id],
        );
        flash_set('ok', 'Раздел сохранён');
        redirect_to('/wiki/' . rawurlencode((string) $row['slug']));
    }

    private static function doSaveTariffs(array $user): void
    {
        self::ownerOrFail($user);
        $decoded = json_decode((string) ($_POST['json'] ?? ''), true);
        if (!is_array($decoded)) {
            flash_set('error', 'Некорректный JSON');
            redirect_back('/tariffs/edit');
        }
        $payload = array_replace_recursive(DEFAULT_TARIFFS, $decoded);
        if (empty($payload['branches']) || !is_array($payload['branches'])) {
            flash_set('error', 'Добавьте хотя бы один филиал');
            redirect_back('/tariffs/edit');
        }
        self::save_portal_json('tariffs', $payload);
        flash_set('ok', 'Тарифы сохранены');
        redirect_to('/tariffs');
    }

    private static function doSaveFines(array $user): void
    {
        self::ownerOrFail($user);
        $id = self::postInt('id');
        $violation = self::postStr('violation', 300);
        if ($violation === '') {
            flash_set('error', 'Напишите, за что штраф');
            redirect_back('/fines/edit');
        }
        $dismissal = isset($_POST['primaryDismissal']) ? 1 : 0;
        $raw = trim((string) ($_POST['primaryAmount'] ?? ''));
        $amount = ($dismissal || $raw === '') ? null : (int) $raw;
        if ($id < 1) {
            $max = (int) (Db::one('SELECT COALESCE(MAX("sortOrder"),0) c FROM fine_rules')['c'] ?? 0);
            Db::exec(
                'INSERT INTO fine_rules (violation,"primaryAmount","primaryDismissal","repeatAmount","repeatDismissal","sortOrder","isActive","createdAt","updatedAt") VALUES (?,?,?,?,?,?,1,?,?)',
                [$violation, $amount, $dismissal, null, 0, $max + 1, now_iso(), now_iso()],
            );
            flash_set('ok', 'Штраф добавлен в лист');
            redirect_back('/fines/edit');
        }
        if (!Db::one('SELECT id FROM fine_rules WHERE id=?', [$id])) {
            flash_set('error', 'Нарушение не найдено');
            redirect_back('/fines/edit');
        }
        Db::exec(
            'UPDATE fine_rules SET violation=?, "primaryAmount"=?, "primaryDismissal"=?, "updatedAt"=? WHERE id=?',
            [$violation, $amount, $dismissal, now_iso(), $id],
        );
        flash_set('ok', 'Лист штрафов сохранён');
        redirect_back('/fines/edit');
    }

    private static function doAssignFine(array $user): void
    {
        self::ownerOrFail($user);
        $employeeId = self::postInt('employeeId');
        $employee = Db::one('SELECT id, name FROM employees WHERE id=? AND "isActive"=1', [$employeeId]);
        if (!$employee) {
            form_stash_set('fine-assign', $_POST);
            flash_set('error', 'Выберите сотрудника');
            redirect_to('/fines/assign');
        }
        $reason = self::postStr('reason', 500);
        if (mb_strlen($reason) < 3) {
            form_stash_set('fine-assign', $_POST);
            flash_set('error', 'Напишите причину штрафа');
            redirect_to('/fines/assign');
        }
        $amount = (int) preg_replace('/\D+/', '', self::postStr('amount', 16));
        if ($amount < 1 || $amount > 50000) {
            form_stash_set('fine-assign', $_POST);
            flash_set('error', 'Сумма — целое число от 1 до 50 000 ₽');
            redirect_to('/fines/assign');
        }
        Db::exec(
            'INSERT INTO employee_fines ("employeeId","employeeName",reason,amount,"createdByUserId","createdByName","createdAt") VALUES (?,?,?,?,?,?,?)',
            [$employeeId, $employee['name'], $reason, $amount, $user['id'], self::displayName($user), now_iso()],
        );
        flash_set('ok', 'Штраф назначен: ' . money_rub($amount) . ' — ' . $employee['name']);
        redirect_to('/fines');
    }

    private static function doSaveLoyalty(array $user): void
    {
        self::ownerOrFail($user);
        $id = self::postInt('id');
        $title = self::postStr('title', 80);
        if ($title === '' || !Db::one('SELECT id FROM loyalty_tiers WHERE id=?', [$id])) {
            flash_set('error', 'Уровень не найден');
            redirect_back('/loyalty/edit');
        }
        $min = max(0, self::postInt('minHours'));
        $maxRaw = trim((string) ($_POST['maxHours'] ?? ''));
        $max = $maxRaw === '' ? null : max(0, (int) $maxRaw);
        $pct = min(100, max(0, self::postInt('cashbackPercent')));
        Db::exec(
            'UPDATE loyalty_tiers SET title=?, "minHours"=?, "maxHours"=?, "cashbackPercent"=? WHERE id=?',
            [$title, $min, $max, $pct, $id],
        );
        flash_set('ok', 'Бонусная система сохранена');
        redirect_back('/loyalty/edit');
    }

    private static function doSavePromo(array $user): void
    {
        self::ownerOrFail($user);
        $payload = [
            'intro' => self::postStr('intro', 2000) ?: DEFAULT_REVIEW_PROMO['intro'],
            'textBonus' => max(0, self::postInt('textBonus')),
            'photoBonus' => 0,
        ];
        if ($payload['textBonus'] === 0) {
            flash_set('error', 'Укажите сумму бонуса за текстовый отзыв');
            redirect_back('/reviews/edit');
        }
        self::save_portal_json('review_promo', $payload);
        flash_set('ok', 'Условия акции сохранены');
        redirect_to('/reviews');
    }

    private static function doSaveEmployee(array $user): void
    {
        self::ownerOrFail($user);
        $name = self::postStr('name', 80);
        $home = self::postInt('homeBranchId');
        if ($name === '' || !Db::one('SELECT id FROM branches WHERE id=?', [$home])) {
            flash_set('error', 'Укажите имя и филиал');
            redirect_back('/manage/employees');
        }
        $max = (int) (Db::one('SELECT COALESCE(MAX("sortOrder"),0) c FROM employees WHERE "homeBranchId"=?', [$home])['c'] ?? 0);
        Db::exec(
            'INSERT INTO employees (name,"homeBranchId","isActive","sortOrder","createdAt") VALUES (?,?,1,?,?)',
            [$name, $home, $max + 1, now_iso()],
        );
        flash_set('ok', $name . ' добавлен');
        redirect_back('/manage/employees');
    }

    private static function doDeleteEmployee(array $user): void
    {
        self::ownerOrFail($user);
        $id = self::postInt('id');
        $emp = Db::one('SELECT id, name FROM employees WHERE id=?', [$id]);
        if (!$emp) {
            flash_set('error', 'Сотрудник не найден');
            redirect_back('/manage/employees');
        }
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            Db::exec('DELETE FROM shifts WHERE "employeeId"=?', [$id]);
            Db::exec('DELETE FROM cleaning_seat_assignments WHERE "employeeId"=?', [$id]);
            Db::exec('DELETE FROM employee_grade_history WHERE "employeeId"=?', [$id]);
            Db::exec('DELETE FROM staff_task_done WHERE "taskId" IN (SELECT id FROM staff_tasks WHERE "employeeId"=?)', [$id]);
            Db::exec('DELETE FROM staff_tasks WHERE "employeeId"=?', [$id]);
            Db::exec('DELETE FROM employee_fines WHERE "employeeId"=?', [$id]);
            Db::exec('DELETE FROM employees WHERE id=?', [$id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash_set('error', 'Не удалось удалить сотрудника');
            redirect_back('/manage/employees');
        }
        flash_set('ok', $emp['name'] . ' удалён');
        redirect_back('/manage/employees');
    }

    private static function doSaveGrade(array $user): void
    {
        self::ownerOrFail($user);
        $id = self::postInt('id');
        $title = self::postStr('title', 80);
        $rate = max(0, self::postInt('shiftRate'));
        if ($title === '') {
            flash_set('error', 'Впишите название уровня');
            redirect_back('/manage/grades');
        }
        if ($id > 0) {
            Db::exec('UPDATE employee_grades SET title=?, "shiftRate"=? WHERE id=?', [$title, $rate, $id]);
            flash_set('ok', 'Уровень сохранён');
        } else {
            $n = (int) (Db::one('SELECT COUNT(*) c FROM employee_grades')['c'] ?? 0);
            Db::exec(
                'INSERT INTO employee_grades (title,"shiftRate","sortOrder","createdAt") VALUES (?,?,?,?)',
                [$title, $rate, $n, now_iso()],
            );
            flash_set('ok', 'Уровень «' . $title . '» добавлен');
        }
        redirect_back('/manage/grades');
    }

    private static function doAssignGrade(array $user): void
    {
        self::ownerOrFail($user);
        $employeeId = self::postInt('employeeId');
        $raw = trim((string) ($_POST['gradeId'] ?? ''));
        $gradeId = $raw === '' ? null : (int) $raw;
        $employee = Db::one('SELECT id, name FROM employees WHERE id=?', [$employeeId]);
        if (!$employee) {
            flash_set('error', 'Сотрудник не найден');
            redirect_back('/manage/employees');
        }
        $grade = null;
        if ($gradeId !== null) {
            $grade = Db::one('SELECT title, "shiftRate" FROM employee_grades WHERE id=?', [$gradeId]);
            if (!$grade) {
                flash_set('error', 'Уровень не найден');
                redirect_back('/manage/employees');
            }
        }
        Db::exec('UPDATE employees SET "gradeId"=? WHERE id=?', [$gradeId, $employeeId]);
        Db::exec(
            'INSERT INTO employee_grade_history ("employeeId","gradeTitle","shiftRate","changedByUserId","changedByName","changedAt") VALUES (?,?,?,?,?,?)',
            [$employeeId, $grade['title'] ?? 'Без уровня', (int) ($grade['shiftRate'] ?? 0), $user['id'], $user['name'], now_iso()],
        );
        flash_set('ok', $grade ? $employee['name'] . ': уровень «' . $grade['title'] . '»' : $employee['name'] . ': уровень снят');
        redirect_back('/manage/employees');
    }

    private static function doAssignStaffTask(array $user): void
    {
        self::ownerOrFail($user);
        $week = self::postStr('week', 10);
        $back = preg_match('/^\d{4}-\d{2}-\d{2}$/', $week) ? $week : monday_key(now_tyumen());
        $go = static function () use ($back): never {
            redirect_to('/manage/tasks', ['w' => $back]);
        };
        $title = self::postStr('title', 200);
        if (mb_strlen($title) < 3) {
            flash_set('error', 'Напишите задание хотя бы парой слов');
            $go();
        }
        $from = self::postStr('from', 10);
        $to = self::postStr('to', 10);
        if ($to === '') {
            $to = $from;
        }
        $days = dates_between($from, $to, 31);
        if (!$days) {
            flash_set('error', 'Укажите даты: один день или промежуток');
            $go();
        }
        $who = self::postStr('who', 16);
        $ids = [];
        if ($who === 'day' || $who === 'night') {
            $types = $who === 'day' ? ['day', 'full'] : ['night', 'full'];
            $ph = implode(',', array_fill(0, count($days), '?'));
            $inTypes = implode(',', array_fill(0, count($types), '?'));
            $rows = Db::all(
                'SELECT DISTINCT "employeeId" FROM shifts WHERE date IN (' . $ph . ') AND "shiftType" IN (' . $inTypes . ')',
                array_merge($days, $types),
            );
            foreach ($rows as $r) {
                $ids[] = (int) $r['employeeId'];
            }
        } else {
            $raw = $_POST['employees'] ?? [];
            if (!is_array($raw)) {
                $raw = [$raw];
            }
            foreach ($raw as $v) {
                $id = (int) $v;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
            $ids = array_values(array_unique($ids));
        }
        if (!$ids) {
            flash_set('error', $who === 'day' || $who === 'night'
                ? 'В графике на эти даты нет таких смен'
                : 'Выберите сотрудников');
            $go();
        }
        $active = [];
        foreach (self::employees() as $e) {
            if (boolish($e['isActive'])) {
                $active[(int) $e['id']] = (string) $e['name'];
            }
        }
        $ids = array_values(array_filter($ids, static fn ($id) => isset($active[$id])));
        if (!$ids) {
            flash_set('error', 'Выберите сотрудников');
            $go();
        }
        $desc = self::postStr('description', 2000);
        $n = 0;
        foreach ($ids as $eid) {
            foreach ($days as $day) {
                if ($who === 'day' || $who === 'night') {
                    $types = $who === 'day' ? ['day', 'full'] : ['night', 'full'];
                    $inTypes = implode(',', array_fill(0, count($types), '?'));
                    $on = Db::one(
                        'SELECT id FROM shifts WHERE "employeeId"=? AND date=? AND "shiftType" IN (' . $inTypes . ')',
                        array_merge([$eid, $day], $types),
                    );
                    if (!$on) {
                        continue;
                    }
                }
                $dup = Db::one(
                    'SELECT id FROM staff_tasks WHERE "employeeId"=? AND date=? AND title=?',
                    [$eid, $day, $title],
                );
                if ($dup) {
                    Db::exec('UPDATE staff_tasks SET description=? WHERE id=?', [$desc, $dup['id']]);
                    $n++;
                    continue;
                }
                Db::exec(
                    'INSERT INTO staff_tasks ("employeeId",date,title,description,"createdByUserId","createdByName","createdAt") VALUES (?,?,?,?,?,?,?)',
                    [$eid, $day, $title, $desc, $user['id'], self::displayName($user), now_iso()],
                );
                $n++;
            }
        }
        if ($n < 1) {
            flash_set('error', 'Некого назначить на эти даты');
            $go();
        }
        $whoLabel = $who === 'day' ? 'дневным' : ($who === 'night' ? 'ночным' : implode(', ', array_map(static fn ($id) => $active[$id], $ids)));
        flash_set('ok', 'Назначено: ' . $n . ' · ' . $whoLabel);
        $go();
    }

    private static function doDeleteStaffTask(array $user): void
    {
        self::ownerOrFail($user);
        $id = self::postInt('id');
        $week = self::postStr('week', 10);
        $back = preg_match('/^\d{4}-\d{2}-\d{2}$/', $week) ? $week : monday_key(now_tyumen());
        if ($id > 0) {
            Db::exec('DELETE FROM staff_task_done WHERE "taskId"=?', [$id]);
            Db::exec('DELETE FROM staff_tasks WHERE id=?', [$id]);
            flash_set('ok', 'Задание снято');
        }
        redirect_to('/manage/tasks', ['w' => $back]);
    }

    private static function doSaveAccount(array $user): void
    {
        self::ownerOrFail($user);
        $name = self::postStr('name', 60);
        $login = strtolower(self::postStr('login', 32));
        $password = (string) ($_POST['password'] ?? '');
        $role = self::postStr('role', 16) === 'owner' ? 'owner' : 'admin';
        $linkId = self::postInt('employeeId');
        $home = self::postInt('homeBranchId');
        if (!preg_match('/^[a-z0-9._-]{3,32}$/', $login)) {
            flash_set('error', 'Логин: 3–32 символа, латиница, цифры, точка, дефис или подчёркивание');
            redirect_back('/manage/accounts');
        }
        if (strlen($password) < 8) {
            flash_set('error', 'Пароль должен быть не короче 8 символов');
            redirect_back('/manage/accounts');
        }

        $link = null;
        if ($linkId > 0) {
            $link = Db::one('SELECT id, name, "homeBranchId", "userId", "isActive" FROM employees WHERE id=?', [$linkId]);
            if (!$link || !boolish($link['isActive'])) {
                flash_set('error', 'Сотрудник не найден');
                redirect_back('/manage/accounts');
            }
            if (!empty($link['userId'])) {
                flash_set('error', 'У ' . $link['name'] . ' уже есть аккаунт');
                redirect_back('/manage/accounts');
            }
            if ($name === '') {
                $name = (string) $link['name'];
            }
        }

        if ($name === '') {
            flash_set('error', 'Укажите имя или выберите сотрудника');
            redirect_back('/manage/accounts');
        }

        $needCard = $role === 'admin' || $link;
        if ($needCard && !$link) {
            if (!Db::one('SELECT id FROM branches WHERE id=?', [$home])) {
                flash_set('error', 'Укажите филиал — сотрудник появится в графике смен');
                redirect_back('/manage/accounts');
            }
        }

        $email = $login . '@' . LOGIN_DOMAIN;
        if (Db::one('SELECT id FROM "user" WHERE email=?', [$email])) {
            flash_set('error', 'Такой логин уже занят');
            redirect_back('/manage/accounts');
        }

        $uid = new_id();
        $now = now_iso();
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            Db::exec(
                'INSERT INTO "user" (id,name,email,"emailVerified",role,"createdAt","updatedAt") VALUES (?,?,?,0,?,?,?)',
                [$uid, $name, $email, $role, $now, $now],
            );
            Db::exec(
                'INSERT INTO account (id,"accountId","providerId","userId",password,"createdAt","updatedAt",password_php) VALUES (?,?,?,?,?,?,?,?)',
                [new_id(), $email, 'credential', $uid, '', $now, $now, password_hash($password, PASSWORD_DEFAULT)],
            );
            if ($link) {
                Db::exec('UPDATE employees SET "userId"=?, name=? WHERE id=?', [$uid, $name, (int) $link['id']]);
            } elseif ($needCard) {
                $max = (int) (Db::one('SELECT COALESCE(MAX("sortOrder"),0) c FROM employees WHERE "homeBranchId"=?', [$home])['c'] ?? 0);
                Db::exec(
                    'INSERT INTO employees (name,"homeBranchId","userId","isActive","sortOrder","createdAt") VALUES (?,?,?,1,?,?)',
                    [$name, $home, $uid, $max + 1, $now],
                );
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash_set('error', 'Не удалось создать аккаунт. Попробуйте другой логин.');
            redirect_back('/manage/accounts');
        }
        flash_set('ok', 'Аккаунт «' . $login . '» создан — можно ставить на смену');
        redirect_back('/manage/accounts');
    }

    private static function doResetPassword(array $user): void
    {
        self::ownerOrFail($user);
        $uid = self::postStr('userId', 64);
        $password = (string) ($_POST['password'] ?? '');
        if (strlen($password) < 8) {
            flash_set('error', 'Пароль должен быть не короче 8 символов');
            redirect_back('/manage/accounts');
        }
        if (!Db::one('SELECT id FROM "user" WHERE id=?', [$uid])) {
            flash_set('error', 'Аккаунт не найден');
            redirect_back('/manage/accounts');
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        Db::exec('UPDATE account SET password=?, password_php=?, "updatedAt"=? WHERE "userId"=? AND "providerId"=?', ['', $hash, now_iso(), $uid, 'credential']);
        flash_set('ok', 'Пароль изменён');
        redirect_back('/manage/accounts');
    }

    private static function doDeleteAccount(array $user): void
    {
        self::ownerOrFail($user);
        $uid = self::postStr('userId', 64);
        if ($uid === '' || $uid === $user['id']) {
            flash_set('error', 'Нельзя удалить аккаунт, под которым вы вошли');
            redirect_back('/manage/accounts');
        }
        $target = Db::one('SELECT id, name, email, role FROM "user" WHERE id=?', [$uid]);
        if (!$target) {
            flash_set('error', 'Аккаунт не найден');
            redirect_back('/manage/accounts');
        }
        if (($target['role'] ?? '') === 'owner') {
            $left = (int) (Db::one('SELECT COUNT(*) c FROM "user" WHERE role=? AND id!=?', ['owner', $uid])['c'] ?? 0);
            if ($left < 1) {
                flash_set('error', 'Это последний владелец — его нельзя удалить');
                redirect_back('/manage/accounts');
            }
        }
        $email = (string) $target['email'];
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            Db::exec('DELETE FROM session WHERE "userId"=?', [$uid]);
            Db::exec('DELETE FROM account WHERE "userId"=?', [$uid]);
            if ($email !== '') {
                Db::exec('DELETE FROM verification WHERE identifier=?', [$email]);
            }
            Db::exec('UPDATE employees SET "userId"=NULL, "isActive"=0 WHERE "userId"=?', [$uid]);
            Db::exec('DELETE FROM "user" WHERE id=?', [$uid]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash_set('error', 'Не удалось удалить аккаунт');
            redirect_back('/manage/accounts');
        }
        flash_set('ok', 'Доступ «' . $target['name'] . '» удалён');
        redirect_back('/manage/accounts');
    }

    private static function doChangePassword(array $user): void
    {
        $current = (string) ($_POST['current'] ?? '');
        $next = (string) ($_POST['next'] ?? '');
        if ($current === '' || strlen($next) < 8) {
            flash_set('error', 'Пароль должен быть не короче 8 символов');
            redirect_back('/account');
        }
        if ($next === $current) {
            flash_set('error', 'Новый пароль совпадает с текущим');
            redirect_back('/account');
        }
        $acc = Db::one('SELECT id, password, password_php FROM account WHERE "userId"=? AND "providerId"=?', [$user['id'], 'credential']);
        $ok = false;
        if ($acc) {
            if (!empty($acc['password_php']) && password_verify($current, $acc['password_php'])) {
                $ok = true;
            } elseif (!empty($acc['password'])) {
                $ok = Scrypt::verifyBetterAuth((string) $acc['password'], $current);
            }
        }
        if (!$ok) {
            flash_set('error', 'Текущий пароль неверный');
            redirect_back('/account');
        }
        Db::exec(
            'UPDATE account SET password=?, password_php=?, "updatedAt"=? WHERE id=?',
            ['', password_hash($next, PASSWORD_DEFAULT), now_iso(), $acc['id']],
        );
        flash_set('ok', 'Пароль изменён');
        redirect_back('/account');
    }

    private static function doCreateExam(array $user): void
    {
        self::ownerOrFail($user);
        $title = self::postStr('title', 140);
        if ($title === '') {
            flash_set('error', 'Впишите название экзамена');
            redirect_back('/exams');
        }
        $max = (int) (Db::one('SELECT COALESCE(MAX("sortOrder"), 0) c FROM exams')['c'] ?? 0);
        $slug = exam_unique_slug(exam_slugify($title));
        $now = now_iso();
        Db::exec(
            'INSERT INTO exams (title, slug, intro, "passPercent", shuffle, "isDraft", "sortOrder", "xpReward", "updatedByUserId", "createdAt", "updatedAt") VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [$title, $slug, '', 80, 0, 1, $max + 1, 100, $user['id'], $now, $now],
        );
        flash_set('ok', 'Экзамен создан');
        redirect_to('/exams/' . rawurlencode($slug) . '/edit');
    }

    private static function examWipeQuestions(int $examId): void
    {
        $qs = Db::all('SELECT id FROM exam_questions WHERE "examId"=?', [$examId]);
        foreach ($qs as $q) {
            $qid = (int) $q['id'];
            Db::exec('DELETE FROM exam_answers WHERE "questionId"=?', [$qid]);
            Db::exec('DELETE FROM exam_options WHERE "questionId"=?', [$qid]);
        }
        Db::exec('DELETE FROM exam_questions WHERE "examId"=?', [$examId]);
    }

    private static function examPostedEditorState(): array
    {
        $raw = $_POST['questions'] ?? [];
        $questions = [];
        if (is_array($raw)) {
            foreach ($raw as $q) {
                if (!is_array($q)) {
                    continue;
                }
                $kind = (string) ($q['kind'] ?? 'single');
                if (!isset(EXAM_KIND_LABELS[$kind])) {
                    $kind = 'single';
                }
                $optRaw = is_array($q['options'] ?? null) ? $q['options'] : [];
                $options = [];
                foreach ($optRaw as $j => $opt) {
                    if (!is_array($opt)) {
                        continue;
                    }
                    $isCorrect = $kind === 'multiple'
                        ? !empty($opt['correct'])
                        : (string) ($q['correct'] ?? '') === (string) $j;
                    $options[] = [
                        'label' => (string) ($opt['label'] ?? ''),
                        'isCorrect' => $isCorrect,
                    ];
                }
                while (count($options) < 2) {
                    $options[] = ['label' => '', 'isCorrect' => false];
                }
                $questions[] = [
                    'prompt' => (string) ($q['prompt'] ?? ''),
                    'kind' => $kind,
                    'explanation' => (string) ($q['explanation'] ?? ''),
                    'options' => $options,
                ];
            }
        }
        return [
            'title' => (string) ($_POST['title'] ?? ''),
            'intro' => (string) ($_POST['intro'] ?? ''),
            'passPercent' => (string) ($_POST['passPercent'] ?? ''),
            'xpReward' => (string) ($_POST['xpReward'] ?? ''),
            'shuffle' => isset($_POST['shuffle']),
            'isDraft' => isset($_POST['isDraft']),
            'questions' => $questions,
        ];
    }

    private static function saveExamFail(string $slug, string $msg): never
    {
        form_stash_set('exam-edit:' . $slug, self::examPostedEditorState());
        flash_set('error', $msg);
        redirect_to('/exams/' . rawurlencode($slug) . '/edit');
    }

    private static function doSaveExam(array $user): void
    {
        self::ownerOrFail($user);
        $slug = self::postStr('slug', 80);
        $title = self::postStr('title', 140);
        $exam = Db::one('SELECT id FROM exams WHERE slug=?', [$slug]);
        if (!$exam) {
            flash_set('error', 'Экзамен не найден');
            redirect_back('/exams');
        }
        if ($title === '') {
            self::saveExamFail($slug, 'Впишите название экзамена');
        }
        $pass = self::postInt('passPercent');
        if ($pass < 1 || $pass > 100) {
            self::saveExamFail($slug, 'Проходной балл — от 1 до 100 процентов');
        }
        $xpReward = self::postInt('xpReward');
        if ($xpReward < 1 || $xpReward > 5000) {
            self::saveExamFail($slug, 'XP за сдачу — целое число от 1 до 5000');
        }
        $raw = $_POST['questions'] ?? [];
        if (!is_array($raw)) {
            $raw = [];
        }
        $questions = [];
        foreach ($raw as $index => $q) {
            if (!is_array($q)) {
                continue;
            }
            $number = (int) $index + 1;
            $prompt = mb_substr(trim((string) ($q['prompt'] ?? '')), 0, 600);
            $kind = (string) ($q['kind'] ?? 'single');
            $optRaw = is_array($q['options'] ?? null) ? $q['options'] : [];
            $options = [];
            $anyLabel = false;
            foreach ($optRaw as $j => $opt) {
                if (!is_array($opt)) {
                    continue;
                }
                $label = mb_substr(trim((string) ($opt['label'] ?? '')), 0, 300);
                if ($label === '') {
                    continue;
                }
                $anyLabel = true;
                if ($kind === 'multiple') {
                    $isCorrect = !empty($opt['correct']);
                } else {
                    $isCorrect = (string) ($q['correct'] ?? '') === (string) $j;
                }
                $options[] = ['label' => $label, 'isCorrect' => $isCorrect];
            }
            if ($prompt === '' && !$anyLabel) {
                continue;
            }
            if ($prompt === '') {
                self::saveExamFail($slug, 'Вопрос ' . $number . ': впишите текст вопроса');
            }
            if (!isset(EXAM_KIND_LABELS[$kind])) {
                self::saveExamFail($slug, 'Вопрос ' . $number . ': неизвестный тип');
            }
            if ($kind === 'boolean' && count($options) < 2) {
                $options = [
                    ['label' => 'Да', 'isCorrect' => (string) ($q['correct'] ?? '') === '0'],
                    ['label' => 'Нет', 'isCorrect' => (string) ($q['correct'] ?? '') === '1'],
                ];
            }
            if (count($options) < 2) {
                self::saveExamFail($slug, 'Вопрос ' . $number . ': нужно минимум два варианта');
            }
            $correctN = count(array_filter($options, static fn ($o) => $o['isCorrect']));
            if ($correctN === 0) {
                self::saveExamFail($slug, 'Вопрос ' . $number . ': отметьте верный вариант');
            }
            if ($kind !== 'multiple' && $correctN > 1) {
                self::saveExamFail($slug, 'Вопрос ' . $number . ': для этого типа верный вариант должен быть один');
            }
            $questions[] = [
                'prompt' => $prompt,
                'kind' => $kind,
                'explanation' => mb_substr(trim((string) ($q['explanation'] ?? '')), 0, 1000),
                'options' => $options,
            ];
        }
        $isDraft = isset($_POST['isDraft']) ? 1 : 0;
        $shuffle = isset($_POST['shuffle']) ? 1 : 0;
        if ($isDraft === 0 && $questions === []) {
            self::saveExamFail($slug, 'Нельзя опубликовать экзамен без вопросов');
        }
        $examId = (int) $exam['id'];
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            Db::exec(
                'UPDATE exams SET title=?, intro=?, "passPercent"=?, shuffle=?, "isDraft"=?, "xpReward"=?, "updatedByUserId"=?, "updatedAt"=? WHERE id=?',
                [$title, self::postStr('intro', 2000), $pass, $shuffle, $isDraft, $xpReward, $user['id'], now_iso(), $examId],
            );
            self::examWipeQuestions($examId);
            foreach ($questions as $i => $q) {
                Db::exec(
                    'INSERT INTO exam_questions ("examId", prompt, kind, explanation, "sortOrder") VALUES (?,?,?,?,?)',
                    [$examId, $q['prompt'], $q['kind'], $q['explanation'], $i + 1],
                );
                $qid = (int) Db::lastId();
                foreach ($q['options'] as $j => $opt) {
                    Db::exec(
                        'INSERT INTO exam_options ("questionId", label, "isCorrect", "sortOrder") VALUES (?,?,?,?)',
                        [$qid, $opt['label'], $opt['isCorrect'] ? 1 : 0, $j + 1],
                    );
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            self::saveExamFail($slug, 'Не удалось сохранить экзамен');
        }
        flash_set('ok', 'Экзамен сохранён');
        redirect_to('/exams/' . rawurlencode($slug) . '/edit');
    }

    private static function doDeleteExam(array $user): void
    {
        self::ownerOrFail($user);
        $slug = self::postStr('slug', 80);
        $exam = Db::one('SELECT id FROM exams WHERE slug=?', [$slug]);
        if (!$exam) {
            flash_set('error', 'Экзамен не найден');
            redirect_back('/exams');
        }
        $examId = (int) $exam['id'];
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $attempts = Db::all('SELECT id FROM exam_attempts WHERE "examId"=?', [$examId]);
            foreach ($attempts as $a) {
                Db::exec('DELETE FROM exam_answers WHERE "attemptId"=?', [(int) $a['id']]);
            }
            Db::exec('DELETE FROM exam_attempts WHERE "examId"=?', [$examId]);
            self::examWipeQuestions($examId);
            Db::exec('DELETE FROM exams WHERE id=?', [$examId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash_set('error', 'Не удалось удалить экзамен');
            redirect_back('/exams');
        }
        flash_set('ok', 'Экзамен удалён');
        redirect_to('/exams');
    }

    private static function doStartAttempt(array $user): void
    {
        $slug = self::postStr('slug', 80);
        $exam = Db::one('SELECT id, "isDraft" FROM exams WHERE slug=?', [$slug]);
        if (!$exam) {
            flash_set('error', 'Экзамен не найден');
            redirect_back('/exams');
        }
        if (boolish($exam['isDraft']) && !$user['isOwner']) {
            flash_set('error', 'Экзамен ещё не опубликован');
            redirect_back('/exams');
        }
        $count = (int) (Db::one('SELECT COUNT(*) c FROM exam_questions WHERE "examId"=?', [(int) $exam['id']])['c'] ?? 0);
        if ($count === 0) {
            flash_set('error', 'В экзамене пока нет вопросов');
            redirect_to('/exams/' . rawurlencode($slug));
        }
        $open = Db::one(
            'SELECT id FROM exam_attempts WHERE "examId"=? AND "userId"=? AND status=?',
            [(int) $exam['id'], $user['id'], 'in_progress'],
        );
        if ($open) {
            redirect_to('/exams/' . rawurlencode($slug) . '/attempt/' . (int) $open['id']);
        }
        Db::exec(
            'INSERT INTO exam_attempts ("examId", "userId", status, "maxPoints", "startedAt") VALUES (?,?,?,?,?)',
            [(int) $exam['id'], $user['id'], 'in_progress', $count, now_iso()],
        );
        redirect_to('/exams/' . rawurlencode($slug) . '/attempt/' . (int) Db::lastId());
    }

    private static function doSubmitAttempt(array $user): void
    {
        $slug = self::postStr('slug', 80);
        $attemptId = self::postInt('attemptId');
        $attempt = Db::one('SELECT * FROM exam_attempts WHERE id=?', [$attemptId]);
        if (!$attempt || $attempt['userId'] !== $user['id']) {
            flash_set('error', 'Попытка не найдена');
            redirect_back('/exams');
        }
        if ($attempt['status'] !== 'in_progress') {
            redirect_to('/exams/' . rawurlencode($slug) . '/result/' . $attemptId);
        }
        $exam = Db::one('SELECT * FROM exams WHERE id=?', [(int) $attempt['examId']]);
        if (!$exam) {
            flash_set('error', 'Экзамен не найден');
            redirect_back('/exams');
        }
        $questions = Db::all('SELECT * FROM exam_questions WHERE "examId"=? ORDER BY "sortOrder", id', [(int) $exam['id']]);
        if (!$questions) {
            flash_set('error', 'В экзамене нет вопросов');
            redirect_to('/exams/' . rawurlencode($slug));
        }
        $posted = $_POST['a'] ?? [];
        if (!is_array($posted)) {
            $posted = [];
        }
        $earned = 0;
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            Db::exec('DELETE FROM exam_answers WHERE "attemptId"=?', [$attemptId]);
            foreach ($questions as $q) {
                $qid = (int) $q['id'];
                $opts = Db::all('SELECT id, "isCorrect" FROM exam_options WHERE "questionId"=?', [$qid]);
                $allowed = [];
                $correct = [];
                foreach ($opts as $o) {
                    $oid = (int) $o['id'];
                    $allowed[$oid] = true;
                    if (boolish($o['isCorrect'])) {
                        $correct[] = $oid;
                    }
                }
                sort($correct);
                $raw = $posted[$qid] ?? ($posted[(string) $qid] ?? []);
                if (!is_array($raw)) {
                    $raw = $raw === '' || $raw === null ? [] : [$raw];
                }
                $chosen = [];
                foreach ($raw as $id) {
                    $id = (int) $id;
                    if ($id > 0 && isset($allowed[$id])) {
                        $chosen[$id] = true;
                    }
                }
                $chosen = array_keys($chosen);
                sort($chosen);
                $isCorrect = $correct !== [] && $correct === $chosen;
                if ($isCorrect) {
                    $earned++;
                }
                Db::exec(
                    'INSERT INTO exam_answers ("attemptId", "questionId", "selectedOptionIds", "textAnswer", "isCorrect") VALUES (?,?,?,?,?)',
                    [$attemptId, $qid, implode(',', $chosen), '', $isCorrect ? 1 : 0],
                );
            }
            $max = count($questions);
            $score = (int) round(($earned / $max) * 100);
            $status = $score >= (int) $exam['passPercent'] ? 'passed' : 'failed';
            Db::exec(
                'UPDATE exam_attempts SET status=?, "earnedPoints"=?, "maxPoints"=?, "scorePercent"=?, "submittedAt"=? WHERE id=?',
                [$status, $earned, $max, $score, now_iso(), $attemptId],
            );
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash_set('error', 'Не удалось сохранить ответы');
            redirect_to('/exams/' . rawurlencode($slug) . '/attempt/' . $attemptId);
        }
        redirect_to('/exams/' . rawurlencode($slug) . '/result/' . $attemptId);
    }
}
