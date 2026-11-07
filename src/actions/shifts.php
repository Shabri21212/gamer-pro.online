<?php
declare(strict_types=1);

trait ActionsShifts
{
    private static function fail(string $msg, string $back = '/'): never
    {
        flash_set('error', $msg);
        redirect_back($back);
    }

    /* ---------- задания на смену (сотрудник) ---------- */

    private static function doCompleteTask(array $user): void
    {
        $task = Db::one('SELECT id, "employeeId", date, title FROM staff_tasks WHERE id = ?', [self::postInt('taskId')]);
        if (!$task) {
            self::fail('Задание не найдено — возможно, его уже сняли.');
        }
        if ((int) $task['employeeId'] !== (int) ($user['employeeId'] ?? 0)) {
            self::fail('Это задание назначено другому сотруднику.');
        }
        $today = date_key(now_tyumen());
        $date = substr((string) $task['date'], 0, 10);
        if ($date !== $today && $date !== add_days($today, -1)) {
            self::fail('Отмечать можно только задания текущей смены.');
        }
        Db::insertIgnore('staff_task_done', [
            'taskId' => (int) $task['id'],
            'userId' => $user['id'],
            'userName' => self::displayName($user),
            'completedAt' => now_iso(),
        ]);
        flash_set('ok', 'Задание «' . mb_substr((string) $task['title'], 0, 60) . '» выполнено');
        redirect_back('/');
    }

    private static function doUncompleteTask(array $user): void
    {
        Db::exec('DELETE FROM staff_task_done WHERE "taskId" = ? AND "userId" = ?', [self::postInt('taskId'), $user['id']]);
        flash_set('ok', 'Отметка снята');
        redirect_back('/');
    }

    /* ---------- график ---------- */

    private static function shiftSummary(string $name, string $type, string $date, string $branch): string
    {
        return $name . ' · ' . (SHIFT_LABELS[$type] ?? $type) . ' ' . (SHIFT_HINTS[$type] ?? '') . ' · ' . format_day_month($date) . ' · ' . $branch;
    }

    private static function scheduleHref(array $branch, string $date): string
    {
        return url_to('/schedule', ['branch' => $branch['slug'], 'm' => substr($date, 0, 7)]);
    }

    private static function doSaveShift(array $user): void
    {
        $branch = self::branchById(self::postInt('branchId'));
        if (!$branch) {
            self::fail('Филиал не найден', '/schedule');
        }
        self::permOrFail($user, 'schedule.edit', $branch['id'], '/schedule');
        $date = self::postStr('date', 10);
        $type = self::postStr('shiftType', 16);
        if (!is_date_key($date)) {
            self::fail('Не выбрана дата смены', '/schedule');
        }
        if (!in_array($type, SHIFT_TYPES, true)) {
            self::fail('Выберите тип смены: день, ночь или сутки', '/schedule');
        }
        $emp = Db::one('SELECT id, name, "homeBranchId", "isActive" FROM employees WHERE id = ?', [self::postInt('employeeId')]);
        if (!$emp || !boolish($emp['isActive'])) {
            self::fail('Выберите сотрудника из списка', '/schedule');
        }
        $name = (string) $emp['name'];
        $busy = Db::one('SELECT id, "branchId", "shiftType" FROM shifts WHERE "employeeId" = ? AND date = ?', [(int) $emp['id'], $date]);
        if ($busy && (int) $busy['branchId'] !== $branch['id']) {
            self::fail($name . ' в этот день уже стоит в смену в филиале ' . self::branchName((int) $busy['branchId']) . '.', '/schedule');
        }
        if ($busy) {
            if ($busy['shiftType'] === $type) {
                flash_set('ok', $name . ' уже стоит в эту смену');
                redirect_back('/schedule');
            }
            Db::exec('UPDATE shifts SET "shiftType" = ?, "updatedAt" = ? WHERE id = ?', [$type, now_iso(), (int) $busy['id']]);
            audit($user, 'schedule', 'update', 'Смена изменена: ' . self::shiftSummary($name, $type, $date, $branch['name']), self::scheduleHref($branch, $date));
            flash_set('ok', 'Смена ' . short_name($name) . ' изменена на «' . SHIFT_LABELS[$type] . '»');
            redirect_back('/schedule');
        }
        Db::insert('shifts', [
            'date' => $date,
            'branchId' => $branch['id'],
            'employeeId' => (int) $emp['id'],
            'shiftType' => $type,
            'note' => '',
            'createdAt' => now_iso(),
            'updatedAt' => now_iso(),
        ]);
        $guest = (int) $emp['homeBranchId'] !== $branch['id'];
        audit($user, 'schedule', 'create', ($guest ? 'Подмена: ' : 'Поставлен в смену: ') . self::shiftSummary($name, $type, $date, $branch['name']), self::scheduleHref($branch, $date));
        flash_set('ok', short_name($name) . ($guest ? ' поставлен подменой на ' . $branch['name'] : ' поставлен в смену') . ' · ' . format_day_month($date));
        redirect_back('/schedule');
    }

    private static function doDeleteShift(array $user): void
    {
        $shift = Db::one(
            'SELECT s.id, s.date, s."branchId", s."shiftType", e.name AS "employeeName" FROM shifts s JOIN employees e ON e.id = s."employeeId" WHERE s.id = ?',
            [self::postInt('id')],
        );
        if (!$shift) {
            self::fail('Смена не найдена — возможно, её уже убрали.', '/schedule');
        }
        $branch = self::branchById((int) $shift['branchId']) ?? ['id' => (int) $shift['branchId'], 'name' => '—', 'slug' => ''];
        self::permOrFail($user, 'schedule.edit', $branch['id'], '/schedule');
        Db::exec('DELETE FROM shifts WHERE id = ?', [(int) $shift['id']]);
        $date = substr((string) $shift['date'], 0, 10);
        audit($user, 'schedule', 'delete', 'Смена убрана: ' . self::shiftSummary((string) $shift['employeeName'], (string) $shift['shiftType'], $date, $branch['name']), self::scheduleHref($branch, $date));
        flash_set('ok', 'Смена ' . short_name((string) $shift['employeeName']) . ' убрана');
        redirect_back('/schedule');
    }

    private static function doCopyWeek(array $user): void
    {
        $branch = self::branchById(self::postInt('branchId'));
        if (!$branch) {
            self::fail('Филиал не найден', '/schedule');
        }
        self::permOrFail($user, 'schedule.edit', $branch['id'], '/schedule');
        $from = self::postStr('from', 10);
        $to = self::postStr('to', 10);
        if (!is_date_key($from) || !is_date_key($to)) {
            self::fail('Укажите обе недели — любой день каждой из них', '/schedule');
        }
        $a = monday_key(date_from_key($from));
        $b = monday_key(date_from_key($to));
        if ($a === $b) {
            self::fail('Выбрана одна и та же неделя — укажите другую неделю назначения', '/schedule');
        }
        $offset = (int) date_from_key($a)->diff(date_from_key($b))->format('%r%a');
        $source = Db::all(
            'SELECT s.date, s."employeeId", s."shiftType", e."isActive" FROM shifts s JOIN employees e ON e.id = s."employeeId"
             WHERE s."branchId" = ? AND s.date >= ? AND s.date <= ? ORDER BY s.date',
            [$branch['id'], $a, add_days($a, 6)],
        );
        if (!$source) {
            self::fail('На неделе ' . week_label($a) . ' в филиале ' . $branch['name'] . ' нет смен — копировать нечего.', '/schedule');
        }
        [$copied, $skipped] = Db::tx(static function () use ($source, $offset, $branch): array {
            $copied = 0;
            $skipped = 0;
            foreach ($source as $s) {
                $day = add_days(substr((string) $s['date'], 0, 10), $offset);
                if (!boolish($s['isActive']) || Db::one('SELECT id FROM shifts WHERE "employeeId" = ? AND date = ?', [(int) $s['employeeId'], $day])) {
                    $skipped++;
                    continue;
                }
                Db::insert('shifts', [
                    'date' => $day,
                    'branchId' => $branch['id'],
                    'employeeId' => (int) $s['employeeId'],
                    'shiftType' => (string) $s['shiftType'],
                    'note' => '',
                    'createdAt' => now_iso(),
                    'updatedAt' => now_iso(),
                ]);
                $copied++;
            }
            return [$copied, $skipped];
        });
        $summary = 'Неделя ' . week_label($a) . ' → ' . week_label($b) . ' · ' . $branch['name'] . ': ' . plural($copied, 'смена', 'смены', 'смен');
        if ($copied > 0) {
            audit($user, 'schedule', 'copy_week', $summary, self::scheduleHref($branch, $b));
        }
        $msg = $copied > 0 ? 'Скопировано ' . plural($copied, 'смена', 'смены', 'смен') . ' на ' . week_label($b) : 'Ничего не скопировано';
        if ($skipped > 0) {
            $msg .= ' · пропущено ' . $skipped . ' — сотрудник уже в графике или уволен';
        }
        flash_set($copied > 0 ? 'ok' : 'error', $msg);
        redirect_to('/schedule', ['branch' => $branch['slug'], 'm' => substr($b, 0, 7)]);
    }

    /* ---------- чистка клавиатур ---------- */

    private static function cleaningTarget(): array
    {
        $branch = self::branchById(self::postInt('branchId'));
        $pc = self::postInt('pcNumber');
        $week = self::postStr('weekStart', 10);
        if (!$branch || $pc < 1 || $pc > (int) $branch['pcCount']) {
            self::fail('Такого места нет в этом клубе', '/cleaning');
        }
        if (!is_date_key($week)) {
            self::fail('Не выбрана неделя', '/cleaning');
        }
        return [$branch, $pc, monday_key(date_from_key($week))];
    }

    private static function doToggleClean(array $user): void
    {
        [$branch, $pc, $week] = self::cleaningTarget();
        if (self::postInt('employeeId') > 0) {
            self::assignSeat($user, $branch, $pc, $week, self::postInt('employeeId'));
            return;
        }
        $canAssign = can($user, 'cleaning.assign', $branch['id']);
        $asg = Db::one(
            'SELECT a."employeeId", e.name FROM cleaning_seat_assignments a JOIN employees e ON e.id = a."employeeId"
             WHERE a."branchId" = ? AND a."weekStart" = ? AND a."pcNumber" = ?',
            [$branch['id'], $week, $pc],
        );
        if ($asg && !$canAssign && (int) $asg['employeeId'] !== (int) ($user['employeeId'] ?? 0)) {
            self::fail('Место ' . $pc . ' на этой неделе закреплено за ' . $asg['name'], '/cleaning');
        }
        $mark = Db::one('SELECT id, "doneByUserId", "doneByName" FROM keyboard_cleanings WHERE "branchId" = ? AND "pcNumber" = ? AND "weekStart" = ?', [$branch['id'], $pc, $week]);
        if ($mark) {
            if ($mark['doneByUserId'] !== $user['id'] && !$canAssign) {
                self::fail('Отметку поставил(а) ' . $mark['doneByName'] . ' — снять её может он(а) или тот, кто распределяет чистку.', '/cleaning');
            }
            Db::exec('DELETE FROM keyboard_cleanings WHERE id = ?', [(int) $mark['id']]);
        } else {
            Db::insert('keyboard_cleanings', [
                'branchId' => $branch['id'],
                'pcNumber' => $pc,
                'weekStart' => $week,
                'doneByUserId' => $user['id'],
                'doneByName' => self::displayName($user),
                'doneAt' => now_iso(),
            ]);
        }
        redirect_back('/cleaning');
    }

    private static function doAssignSeats(array $user): void
    {
        [$branch, $pc, $week] = self::cleaningTarget();
        self::assignSeat($user, $branch, $pc, $week, self::postInt('employeeId'));
    }

    /** Закрепляет место за сотрудником; повторное нажатие тем же сотрудником снимает закрепление. */
    private static function assignSeat(array $user, array $branch, int $pc, string $week, int $employeeId): void
    {
        self::permOrFail($user, 'cleaning.assign', $branch['id'], '/cleaning');
        $emp = Db::one('SELECT id, "isActive" FROM employees WHERE id = ?', [$employeeId]);
        if (!$emp || !boolish($emp['isActive'])) {
            self::fail('Сотрудник не найден', '/cleaning');
        }
        $existing = Db::one('SELECT id, "employeeId" FROM cleaning_seat_assignments WHERE "branchId" = ? AND "weekStart" = ? AND "pcNumber" = ?', [$branch['id'], $week, $pc]);
        if ($existing && (int) $existing['employeeId'] === $employeeId) {
            Db::exec('DELETE FROM cleaning_seat_assignments WHERE id = ?', [(int) $existing['id']]);
        } elseif ($existing) {
            Db::exec('UPDATE cleaning_seat_assignments SET "employeeId" = ? WHERE id = ?', [$employeeId, (int) $existing['id']]);
        } else {
            Db::insert('cleaning_seat_assignments', [
                'branchId' => $branch['id'],
                'weekStart' => $week,
                'employeeId' => $employeeId,
                'pcNumber' => $pc,
                'createdAt' => now_iso(),
            ]);
        }
        redirect_back('/cleaning');
    }

    /* ---------- задания на смены (назначение) ---------- */

    private static function doAssignStaffTask(array $user): void
    {
        self::permOrFail($user, 'tasks.assign', null, '/manage/tasks');
        $scope = perm_scope($user, 'tasks.assign');
        $week = self::postStr('week', 10);
        $back = ['w' => is_date_key($week) ? $week : monday_key(now_tyumen())];
        $go = static function (string $type, string $msg) use ($back): never {
            flash_set($type, $msg);
            redirect_to('/manage/tasks', $back);
        };
        $title = self::postStr('title', 200);
        if (mb_strlen($title) < 3) {
            $go('error', 'Напишите задание хотя бы парой слов');
        }
        $from = self::postStr('from', 10);
        $to = self::postStr('to', 10) ?: $from;
        $days = dates_between($from, $to, 31);
        if (!$days) {
            $go('error', 'Укажите даты: один день или промежуток до 31 дня');
        }
        $active = [];
        foreach (self::employees(true) as $e) {
            if ($scope === null || (int) $e['homeBranchId'] === $scope) {
                $active[(int) $e['id']] = (string) $e['name'];
            }
        }
        $who = self::postStr('who', 16);
        $byShift = $who === 'day' || $who === 'night';
        $types = $who === 'day' ? ['day', 'full'] : ['night', 'full'];
        $pairs = [];
        if ($byShift) {
            $rows = Db::all(
                'SELECT "employeeId", date FROM shifts WHERE date IN (' . implode(',', array_fill(0, count($days), '?')) . ') AND "shiftType" IN (?, ?)',
                array_merge($days, $types),
            );
            foreach ($rows as $r) {
                $eid = (int) $r['employeeId'];
                if (isset($active[$eid])) {
                    $pairs[] = [$eid, substr((string) $r['date'], 0, 10)];
                }
            }
            if (!$pairs) {
                $go('error', 'В графике на эти даты нет ' . ($who === 'day' ? 'дневных' : 'ночных') . ' смен' . ($scope !== null ? ' в вашем филиале' : ''));
            }
        } else {
            $raw = $_POST['employees'] ?? [];
            $ids = array_values(array_unique(array_filter(array_map('intval', is_array($raw) ? $raw : [$raw]), static fn ($id) => isset($active[$id]))));
            if (!$ids) {
                $go('error', 'Отметьте, кому назначить задание');
            }
            foreach ($ids as $eid) {
                foreach ($days as $day) {
                    $pairs[] = [$eid, $day];
                }
            }
        }
        $desc = self::postStr('description', 2000);
        $author = self::displayName($user);
        $n = Db::tx(static function () use ($pairs, $title, $desc, $user, $author): int {
            $n = 0;
            foreach ($pairs as [$eid, $day]) {
                $dup = Db::one('SELECT id FROM staff_tasks WHERE "employeeId" = ? AND date = ? AND title = ?', [$eid, $day, $title]);
                if ($dup) {
                    Db::exec('UPDATE staff_tasks SET description = ? WHERE id = ?', [$desc, (int) $dup['id']]);
                } else {
                    Db::insert('staff_tasks', [
                        'employeeId' => $eid,
                        'date' => $day,
                        'title' => $title,
                        'description' => $desc,
                        'createdByUserId' => $user['id'],
                        'createdByName' => $author,
                        'createdAt' => now_iso(),
                    ]);
                }
                $n++;
            }
            return $n;
        });
        $people = array_values(array_unique(array_map(static fn ($p) => $active[$p[0]], $pairs)));
        $whom = $byShift ? ($who === 'day' ? 'дневным сменам' : 'ночным сменам') : implode(', ', array_map('short_name', $people));
        $range = count($days) > 1 ? format_day_month($days[0]) . ' – ' . format_day_month(end($days)) : format_day_month($days[0]);
        audit($user, 'tasks', 'assign', '«' . $title . '» · ' . $whom . ' · ' . $range, url_to('/manage/tasks', $back));
        $go('ok', 'Назначено ' . plural($n, 'задание', 'задания', 'заданий') . ' · ' . $whom);
    }

    private static function doDeleteStaffTask(array $user): void
    {
        $week = self::postStr('week', 10);
        $back = ['w' => is_date_key($week) ? $week : monday_key(now_tyumen())];
        $task = Db::one(
            'SELECT t.id, t.title, t.date, e.name, e."homeBranchId" FROM staff_tasks t LEFT JOIN employees e ON e.id = t."employeeId" WHERE t.id = ?',
            [self::postInt('id')],
        );
        if (!$task) {
            flash_set('error', 'Задание уже снято');
            redirect_to('/manage/tasks', $back);
        }
        self::permOrFail($user, 'tasks.assign', $task['homeBranchId'] !== null ? (int) $task['homeBranchId'] : null, '/manage/tasks');
        Db::tx(static function () use ($task): void {
            Db::exec('DELETE FROM staff_task_done WHERE "taskId" = ?', [(int) $task['id']]);
            Db::exec('DELETE FROM staff_tasks WHERE id = ?', [(int) $task['id']]);
        });
        audit($user, 'tasks', 'delete', 'Снято задание «' . $task['title'] . '» · ' . ($task['name'] ?? '—') . ' · ' . format_day_month((string) $task['date']), url_to('/manage/tasks', $back));
        flash_set('ok', 'Задание снято');
        redirect_to('/manage/tasks', $back);
    }
}
