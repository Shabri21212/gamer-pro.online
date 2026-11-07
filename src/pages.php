<?php
declare(strict_types=1);

trait PortalPages
{
    private static function firstArticleSlug(bool $owner): ?string
    {
        $sql = 'SELECT a.slug FROM knowledge_articles a
                JOIN knowledge_categories c ON c.id = a."categoryId"
                WHERE a.kind != \'fines\'' . ($owner ? '' : ' AND a."isDraft" = 0') . '
                ORDER BY c."sortOrder", a."sortOrder", a.id LIMIT 1';
        $row = Db::one($sql);
        return $row['slug'] ?? null;
    }

    private static function pageSchedule(array $user): void
    {
        $branches = self::branches();
        $now = now_tyumen();
        $slug = (string) ($_GET['branch'] ?? '');
        $default = null;
        foreach ($branches as $b) {
            if ((int) $b['id'] === (int) ($user['homeBranchId'] ?? 0)) {
                $default = $b;
            }
        }
        $branch = $branches[0] ?? null;
        foreach ($branches as $b) {
            if ($b['slug'] === $slug) {
                $branch = $b;
            }
        }
        if (!$branch && $default) {
            $branch = $default;
        }
        if ($default && $slug === '') {
            $branch = $default;
        }
        if (!$branch) {
            self::layout($user, '<main class="mx-auto max-w-md px-4 py-16"><p class="text-muted-foreground text-sm">Филиалы ещё не добавлены.</p></main>');
            return;
        }
        $ym = $_GET['m'] ?? $now->format('Y-m');
        if (!preg_match('/^(\d{4})-(\d{2})$/', $ym, $mm)) {
            $year = (int) $now->format('Y');
            $month = (int) $now->format('n') - 1;
        } else {
            $year = (int) $mm[1];
            $month = (int) $mm[2] - 1;
        }
        $from = to_date_key($year, $month, 1);
        $to = to_date_key($year, $month, days_in_month($year, $month));
        $today = date_key($now);
        $shifts = Db::all(
            'SELECT s.*, e.name AS "employeeName", e."homeBranchId", hb.name AS "homeBranchName"
             FROM shifts s
             JOIN employees e ON e.id = s."employeeId"
             JOIN branches hb ON hb.id = e."homeBranchId"
             WHERE s."branchId" = ? AND s.date >= ? AND s.date <= ?
             ORDER BY s.date, s."shiftType"',
            [$branch['id'], $from, $to],
        );
        $away = Db::all(
            'SELECT s.id, s.date, s."employeeId", e.name AS "employeeName", s."shiftType", b.name AS "atBranchName"
             FROM shifts s
             JOIN employees e ON e.id = s."employeeId"
             JOIN branches b ON b.id = s."branchId"
             WHERE e."homeBranchId" = ? AND s."branchId" != ? AND s.date >= ? AND s.date <= ?
             ORDER BY s.date',
            [$branch['id'], $branch['id'], $from, $to],
        );
        $allEmp = self::employees();
        $branchEmp = array_values(array_filter($allEmp, fn ($e) => (int) $e['homeBranchId'] === (int) $branch['id'] && boolish($e['isActive'])));
        $meId = (int) ($user['employeeId'] ?? 0);
        $monthParam = sprintf('%04d-%02d', $year, $month + 1);
        $prev = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month + 1)))->modify('-1 month');
        $next = (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month + 1)))->modify('+1 month');

        $byDate = [];
        $last = days_in_month($year, $month);
        for ($d = 1; $d <= $last; $d++) {
            $k = to_date_key($year, $month, $d);
            $byDate[$k] = ['day' => $d, 'dateKey' => $k, 'shifts' => [], 'away' => []];
        }
        foreach ($shifts as $s) {
            $k = substr((string) $s['date'], 0, 10);
            if (!isset($byDate[$k])) {
                continue;
            }
            $s['guestFromBranchName'] = ((int) $s['homeBranchId'] === (int) $branch['id']) ? null : $s['homeBranchName'];
            $byDate[$k]['shifts'][] = $s;
        }
        foreach ($away as $a) {
            $k = substr((string) $a['date'], 0, 10);
            if (isset($byDate[$k])) {
                $a['awayAtBranchName'] = $a['atBranchName'];
                $byDate[$k]['away'][] = $a;
            }
        }

        $meId = (int) ($user['employeeId'] ?? 0);
        $halfSql = Db::all('SELECT "employeeId", date, "shiftType" FROM shifts WHERE date >= ? AND date <= ?', [$from, $to]);
        $half = [];
        foreach ($allEmp as $e) {
            $half[(int) $e['id']] = ['name' => $e['name'], 'first' => 0, 'second' => 0, 'month' => 0];
        }
        foreach ($halfSql as $r) {
            $id = (int) $r['employeeId'];
            if (!isset($half[$id])) {
                continue;
            }
            $dayN = (int) substr((string) $r['date'], 8, 10);
            $w = shift_weight((string) $r['shiftType']);
            if ($dayN <= 15) {
                $half[$id]['first'] += $w;
            } else {
                $half[$id]['second'] += $w;
            }
            $half[$id]['month'] += $w;
        }
        $showHalf = $meId && isset($half[$meId]);

        $html = '<main class="mx-auto flex w-full max-w-5xl flex-col gap-5 px-4 py-6 sm:px-6 sm:py-8">';
        $html .= '<h1 class="text-xl font-semibold tracking-tight">График смен</h1>';
        $html .= '<div class="flex flex-wrap gap-1 rounded-lg border border-border p-1">';
        foreach ($branches as $b) {
            $onb = (int) $b['id'] === (int) $branch['id'];
            $html .= '<a class="rounded-md px-3 py-1.5 text-sm font-medium ' . ($onb ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted') . '" href="' . h(url_to('/schedule', ['branch' => $b['slug'], 'm' => $monthParam])) . '">' . h($b['name']) . '</a>';
        }
        $html .= '</div>';
        $html .= '<div class="flex items-center justify-between gap-3"><a class="' . btn_class('outline') . '" href="' . h(url_to('/schedule', ['branch' => $branch['slug'], 'm' => $prev->format('Y-m')])) . '">' . icon('chevronleft') . '</a>';
        $html .= '<h2 class="min-w-0 flex-1 text-center text-base font-semibold sm:text-lg">' . h(format_month_title($year, $month)) . '</h2>';
        $html .= '<a class="' . btn_class('outline') . '" href="' . h(url_to('/schedule', ['branch' => $branch['slug'], 'm' => $next->format('Y-m')])) . '">' . icon('chevronright') . '</a></div>';

        $html .= '<section class="border-border bg-muted/20 rounded-xl border border-dashed px-4 py-3" data-range-empty><p class="text-muted-foreground text-sm">Период для подсчёта смен: <button type="button" class="text-primary font-medium" data-range-preset="1-15">1–15</button> / <button type="button" class="text-primary font-medium" data-range-preset="16-end">16–' . $last . '</button><span class="hidden sm:inline">. На компьютере можно выделить дни мышью.</span></p></section>';
        $html .= '<section class="border-primary/30 from-primary/8 bg-card hidden rounded-xl border bg-gradient-to-br to-card p-4 shadow-sm sm:p-5" data-range-box></section>';
        $c = $showHalf ? $half[$meId] : ['first' => 0, 'second' => 0, 'month' => 0];
        $html .= '<section class="border-border bg-card rounded-xl border p-4 shadow-sm" data-half-box data-me="' . $meId . '" data-half="' . h(json_encode($half, JSON_UNESCAPED_UNICODE)) . '"' . ($showHalf ? '' : ' hidden') . '>';
        $html .= '<div class="flex flex-wrap items-baseline gap-x-2"><h2 class="text-sm font-medium" data-half-title>Ваши смены</h2><span class="text-muted-foreground text-xs">для расчёта зарплаты · сутки = 2</span></div>';
        $html .= '<dl class="mt-3 grid grid-cols-3 gap-2">';
        $html .= '<div class="bg-muted/40 rounded-lg px-3 py-2"><dt class="text-muted-foreground text-xs">1–15</dt><dd class="text-lg font-semibold tabular-nums" data-half-first>' . (int) $c['first'] . ' <span class="text-muted-foreground text-xs font-normal">' . h(shift_count_label((int) $c['first'])) . '</span></dd></div>';
        $html .= '<div class="bg-muted/40 rounded-lg px-3 py-2"><dt class="text-muted-foreground text-xs">16–' . $last . '</dt><dd class="text-lg font-semibold tabular-nums" data-half-second>' . (int) $c['second'] . ' <span class="text-muted-foreground text-xs font-normal">' . h(shift_count_label((int) $c['second'])) . '</span></dd></div>';
        $html .= '<div class="bg-muted/40 rounded-lg px-3 py-2"><dt class="text-muted-foreground text-xs">Месяц</dt><dd class="text-lg font-semibold tabular-nums" data-half-month>' . (int) $c['month'] . ' <span class="text-muted-foreground text-xs font-normal">' . h(shift_count_label((int) $c['month'])) . '</span></dd></div>';
        $html .= '</dl></section>';

        if ($user['isOwner']) {
            $chip = emp_filter_class();
            $html .= '<div class="flex flex-wrap gap-2" role="group" aria-label="Показать смены сотрудника">';
            $html .= '<button type="button" class="' . $chip . '" data-emp-filter="" aria-pressed="true">Все</button>';
            foreach ($branchEmp as $e) {
                $html .= '<button type="button" class="' . $chip . '" data-emp-filter="' . (int) $e['id'] . '" aria-pressed="false">' . h($e['name']) . '</button>';
            }
            $html .= '</div>';
        }

        $html .= '<p class="text-muted-foreground mb-3 hidden text-xs sm:block sm:text-sm">Клик по дню открывает смену. Зажмите и потяните мышью — выделятся даты, сверху посчитаются смены.</p>';
        $html .= '<p class="text-muted-foreground mb-3 text-xs sm:hidden">Нажмите день — откроются полные имена и тип смены. Период: кнопки 1–15 / 16–' . $last . ' выше.</p>';
        $html .= '<div class="gp-cal-weekdays text-muted-foreground mb-2 grid grid-cols-7 gap-1.5 text-xs font-medium sm:gap-2 sm:text-sm">';
        foreach (WEEKDAY_SHORT as $i => $lab) {
            $html .= '<div class="px-0.5 sm:px-1' . ($i >= 5 ? ' text-muted-foreground/60' : '') . '">' . $lab . '</div>';
        }
        $html .= '</div><div class="gp-cal-grid grid grid-cols-7 gap-1.5 sm:gap-2" data-month-grid data-year="' . $year . '" data-month="' . $month . '" data-last="' . $last . '" data-me="' . (int) ($user['employeeId'] ?? 0) . '" data-can-edit="' . ($user['isOwner'] ? '1' : '0') . '">';
        $lead = monday_first_weekday($year, $month, 1);
        for ($i = 0; $i < $lead; $i++) {
            $html .= '<div class="gp-cal-pad" aria-hidden="true"></div>';
        }
        foreach ($byDate as $cell) {
            $isToday = $cell['dateKey'] === $today;
            $wd = monday_first_weekday($year, $month, $cell['day']);
            $weekend = $wd >= 5;
            $cls = 'gp-cal-day focus-visible:ring-ring flex min-h-40 cursor-pointer flex-col gap-2 rounded-lg border p-2 text-left transition-colors sm:min-h-44 sm:p-2.5 ';
            $cls .= $isToday ? 'border-primary bg-primary/5 ring-primary/20 ring-1' : 'border-border';
            if ($weekend && !$isToday) {
                $cls .= ' bg-muted/30';
            }
            $html .= '<div role="button" tabindex="0" class="' . $cls . '" data-date-key="' . h($cell['dateKey']) . '" data-day="' . $cell['day'] . '">';
            $html .= '<div class="gp-cal-meta flex items-baseline justify-between gap-1">';
            $html .= '<span class="gp-cal-num text-base font-semibold tabular-nums sm:text-lg' . ($isToday ? ' text-primary' : '') . '">' . $cell['day'] . '</span>';
            $html .= '<span class="gp-cal-wd">' . h(WEEKDAY_LONG[$wd]) . '</span>';
            if ($isToday) {
                $html .= '<span class="gp-cal-today bg-primary text-primary-foreground rounded-full px-1.5 py-0.5 text-[10px] font-semibold">сегодня</span>';
            }
            $html .= '</div><div class="gp-cal-shifts flex min-w-0 flex-col gap-2">';
            foreach ($cell['shifts'] as $s) {
                $html .= '<div class="min-w-0" data-emp="' . (int) $s['employeeId'] . '" data-emp-name="' . h($s['employeeName']) . '" data-shift-id="' . (int) $s['id'] . '" data-weight="' . shift_weight($s['shiftType']) . '" data-type="' . h($s['shiftType']) . '">' . shift_badge($s, true, $user['employeeId']) . '</div>';
            }
            foreach ($cell['away'] as $a) {
                $html .= '<div class="min-w-0" data-emp="' . (int) $a['employeeId'] . '" data-emp-name="' . h($a['employeeName']) . '" data-shift-id="' . (int) $a['id'] . '" data-weight="' . shift_weight($a['shiftType']) . '" data-type="' . h($a['shiftType']) . '" data-away="1">' . shift_badge($a, true, $user['employeeId']) . '</div>';
            }
            if ($user['isOwner'] && !$cell['shifts'] && !$cell['away']) {
                $html .= '<span class="gp-cal-empty text-muted-foreground/50 text-xs">Нажмите, чтобы поставить</span>';
            }
            $html .= '</div></div>';
        }
        $html .= '</div>';

        $html .= '<dialog class="border-border bg-card text-card-foreground mx-auto w-full max-w-md rounded-xl border p-0 shadow-xl" data-day-dialog><div class="flex flex-col gap-4 p-5">';
        $html .= '<h3 class="text-lg font-semibold" data-dialog-title>Смена</h3>';
        $html .= '<div class="flex flex-col gap-2" data-existing></div>';
        if ($user['isOwner']) {
            $html .= '<form method="post" class="flex flex-col gap-4" data-day-edit-form>' . csrf_field();
            $html .= '<input type="hidden" name="_action" value="save_shift"><input type="hidden" name="branchId" value="' . (int) $branch['id'] . '"><input type="hidden" name="date" value="" data-dialog-date>';
            $html .= '<label class="flex flex-col gap-1.5 text-sm font-medium">Сотрудник<select name="employeeId" class="' . input_class() . '">';
            $empGroups = [];
            foreach ($allEmp as $e) {
                if (!boolish($e['isActive'])) {
                    continue;
                }
                $empGroups[(int) $e['homeBranchId']][] = $e;
            }
            $groupIds = array_keys($empGroups);
            usort($groupIds, function ($a, $b) use ($branch) {
                $cur = (int) $branch['id'];
                if ($a === $cur) {
                    return -1;
                }
                if ($b === $cur) {
                    return 1;
                }
                return $a <=> $b;
            });
            $branchTitle = [];
            foreach ($branches as $br) {
                $branchTitle[(int) $br['id']] = $br['name'];
            }
            foreach ($groupIds as $gid) {
                $html .= '<optgroup label="' . h(mb_strtoupper($branchTitle[$gid] ?? 'Филиал')) . '">';
                foreach ($empGroups[$gid] as $e) {
                    $html .= '<option value="' . (int) $e['id'] . '">' . h($e['name']) . '</option>';
                }
                $html .= '</optgroup>';
            }
            $html .= '</select></label>';
            $html .= '<label class="flex flex-col gap-1.5 text-sm font-medium">Тип<select name="shiftType" class="' . input_class() . '"><option value="day">День</option><option value="night">Ночь</option><option value="full">Сутки</option></select></label>';
            $html .= '<div class="flex flex-wrap justify-end gap-2"><button type="button" class="' . btn_class('ghost') . '" data-dialog-close>Закрыть</button><button class="' . btn_class() . '">Поставить</button></div></form>';
        } else {
            $html .= '<div class="flex justify-end"><button type="button" class="' . btn_class('ghost') . '" data-dialog-close>Закрыть</button></div>';
        }
        $html .= '</div></dialog>';
        $html .= '</main>';
        self::layout($user, $html);
    }

    private static function pageShift(array $user): void
    {
        $shift = self::myShift($user);
        $html = '<main class="mx-auto flex w-full max-w-3xl flex-col gap-5 px-4 py-6 sm:px-6 sm:py-8">';
        $html .= '<h1 class="text-xl font-semibold tracking-tight">Приём и сдача</h1>';
        if (!$shift) {
            $html .= '<div class="border-border bg-card rounded-xl border p-6 text-center"><p class="font-medium">На сегодня смена за вами не записана</p><p class="text-muted-foreground mt-1 text-sm">Если вышли за кого-то — попросите поставить смену в графике.</p><a class="' . btn_class('outline', 'sm') . ' mt-4" href="' . h(url_to('/schedule')) . '">Открыть график смен</a></div>';
            self::layout($user, $html . '</main>');
            return;
        }
        $items = Db::all('SELECT * FROM shift_checklist_items WHERE "isActive"=1 ORDER BY "sortOrder", id');
        $html .= '<div class="border-border bg-card rounded-xl border p-5 shadow-sm"><p class="text-muted-foreground text-sm">' . h($shift['branchName']) . ' · ' . h(SHIFT_LABELS[$shift['shiftType']] ?? '') . ' · ' . h($shift['date']) . '</p>';
        foreach (['open' => 'Приём смены', 'close' => 'Сдача смены'] as $phase => $title) {
            $html .= '<form method="post" class="mt-6 flex flex-col gap-3">' . csrf_field();
            $html .= '<input type="hidden" name="_action" value="save_handover"><input type="hidden" name="phase" value="' . $phase . '">';
            $html .= '<input type="hidden" name="branchId" value="' . (int) $shift['branchId'] . '"><input type="hidden" name="date" value="' . h($shift['date']) . '"><input type="hidden" name="shiftType" value="' . h($shift['shiftType']) . '">';
            $html .= '<h2 class="text-base font-semibold">' . $title . '</h2>';
            foreach ($items as $it) {
                if ($it['phase'] !== $phase) {
                    continue;
                }
                $html .= '<label class="flex items-start gap-2 text-sm"><input type="checkbox" name="check[]" value="' . (int) $it['id'] . '" class="mt-1 accent-primary"> <span>' . h($it['title']) . '</span></label>';
            }
            if ($phase === 'close') {
                $html .= '<label class="flex flex-col gap-1.5 text-sm font-medium">Касса факт, ₽<input name="cashActual" class="' . input_class() . '" inputmode="numeric"></label>';
                $html .= '<label class="flex flex-col gap-1.5 text-sm font-medium">Касса план, ₽<input name="cashExpected" class="' . input_class() . '" inputmode="numeric"></label>';
                $html .= '<label class="flex flex-col gap-1.5 text-sm font-medium">Заметка следующей смене<textarea name="noteForNext" class="' . input_class('min-h-16') . '"></textarea></label>';
            }
            $html .= '<button class="' . btn_class() . '">Сохранить ' . h(mb_strtolower($title)) . '</button></form>';
        }
        $html .= '</div></main>';
        self::layout($user, $html);
    }

    private static function pageCleaning(array $user): void
    {
        $branches = self::branches();
        $now = now_tyumen();
        $thisWeek = monday_key($now);
        $week = (string) ($_GET['w'] ?? $thisWeek);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $week)) {
            $week = $thisWeek;
        }
        $slug = (string) ($_GET['branch'] ?? '');
        $branch = $branches[0] ?? null;
        foreach ($branches as $b) {
            if ($b['slug'] === $slug || ($slug === '' && (int) $b['id'] === (int) ($user['homeBranchId'] ?? 0))) {
                $branch = $b;
            }
        }
        if (!$branch) {
            self::layout($user, '<main class="mx-auto max-w-md px-4 py-16"><p class="text-muted-foreground">Клубы ещё не добавлены.</p></main>');
            return;
        }
        $pc = max(1, (int) ($branch['pcCount'] ?? 40));
        $marks = Db::all('SELECT * FROM keyboard_cleanings WHERE "branchId"=? AND "weekStart"=?', [$branch['id'], $week]);
        $done = [];
        foreach ($marks as $m) {
            $done[(int) $m['pcNumber']] = $m;
        }
        $assign = Db::all(
            'SELECT a.*, e.name AS "employeeName" FROM cleaning_seat_assignments a JOIN employees e ON e.id=a."employeeId" WHERE a."branchId"=? AND a."weekStart"=?',
            [$branch['id'], $week],
        );
        $bySeat = [];
        foreach ($assign as $a) {
            $bySeat[(int) $a['pcNumber']] = $a;
        }
        $html = '<main class="mx-auto flex w-full max-w-5xl flex-col gap-5 px-4 py-6 sm:px-6 sm:py-8"><h1 class="text-xl font-semibold tracking-tight">Чистка клавиатур</h1>';
        $html .= '<div class="flex flex-wrap gap-1 rounded-lg border border-border p-1">';
        foreach ($branches as $b) {
            $on = (int) $b['id'] === (int) $branch['id'];
            $html .= '<a class="rounded-md px-3 py-1.5 text-sm font-medium ' . ($on ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted') . '" href="' . h(url_to('/cleaning', ['branch' => $b['slug'], 'w' => $week])) . '">' . h($b['name']) . '</a>';
        }
        $html .= '</div>';
        $prev = (new DateTimeImmutable($week))->modify('-7 days')->format('Y-m-d');
        $next = (new DateTimeImmutable($week))->modify('+7 days')->format('Y-m-d');
        $html .= '<div class="flex flex-wrap items-center justify-between gap-2"><a class="' . btn_class('outline', 'sm') . '" href="' . h(url_to('/cleaning', ['branch' => $branch['slug'], 'w' => $prev])) . '">← неделя</a><span class="min-w-0 text-center text-sm font-medium">' . h($week) . '</span><a class="' . btn_class('outline', 'sm') . '" href="' . h(url_to('/cleaning', ['branch' => $branch['slug'], 'w' => $next])) . '">неделя →</a></div>';
        if ($user['isOwner']) {
            $emps = array_values(array_filter(self::employees(), fn ($e) => boolish($e['isActive'])));
            $html .= '<div class="border-border bg-card flex flex-wrap items-center gap-2 rounded-xl border p-3"><span class="text-sm font-medium">Закрепить места:</span><select class="' . input_class('w-full sm:w-56') . '" data-assign-emp><option value="">не назначать — только отметки</option>';
            foreach ($emps as $e) {
                $html .= '<option value="' . (int) $e['id'] . '">' . h($e['name']) . '</option>';
            }
            $html .= '</select><button type="button" class="' . btn_class('outline', 'sm') . '" data-assign-done>Готово</button><p class="text-muted-foreground text-xs">Сотрудник остаётся выбранным — можно закрепить несколько мест подряд.</p></div>';
        }
        $html .= '<div class="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-8">';
        for ($n = 1; $n <= $pc; $n++) {
            $isDone = isset($done[$n]);
            $who = $bySeat[$n]['employeeName'] ?? '';
            $cls = $isDone ? 'border-emerald-500/35 bg-emerald-500/10' : 'border-border bg-card hover:border-primary/60';
            $html .= '<form method="post" data-clean-form>' . csrf_field() . '<input type="hidden" name="_action" value="toggle_clean"><input type="hidden" name="branchId" value="' . (int) $branch['id'] . '"><input type="hidden" name="weekStart" value="' . h($week) . '"><input type="hidden" name="pcNumber" value="' . $n . '"><input type="hidden" name="employeeId" value="" data-assign-id>';
            $html .= '<button class="w-full rounded-lg border px-2 py-2 text-left text-sm ' . $cls . '"><span class="font-semibold">' . $n . '</span>';
            if ($who) {
                $html .= '<span class="text-muted-foreground gp-break block text-[11px] leading-tight">' . h($who) . '</span>';
            }
            if ($isDone) {
                $html .= '<span class="text-emerald-700 block text-[11px]">готово</span>';
            }
            $html .= '</button></form>';
        }
        $html .= '</div></main>';
        self::layout($user, $html);
    }

    private static function pageIssues(array $user): void
    {
        $branches = self::branches();
        $rows = Db::all('SELECT i.*, b.name AS "branchName" FROM incidents i JOIN branches b ON b.id=i."branchId" ORDER BY CASE i.status WHEN \'done\' THEN 1 ELSE 0 END, i."createdAt" DESC');
        $html = '<main class="mx-auto flex w-full max-w-4xl flex-col gap-5 px-4 py-6 sm:px-6 sm:py-8"><h1 class="text-xl font-semibold tracking-tight">Поломки</h1>';
        $html .= '<form method="post" class="border-border bg-card flex flex-col gap-3 rounded-xl border p-4 shadow-sm">' . csrf_field() . '<input type="hidden" name="_action" value="save_incident">';
        $html .= '<div class="grid grid-cols-1 gap-3 sm:grid-cols-2"><label class="flex flex-col gap-1.5 text-sm font-medium">Филиал<select name="branchId" class="' . input_class() . '">';
        foreach ($branches as $b) {
            $sel = (int) $b['id'] === (int) ($user['homeBranchId'] ?? 0) ? ' selected' : '';
            $html .= '<option value="' . (int) $b['id'] . '"' . $sel . '>' . h($b['name']) . '</option>';
        }
        $html .= '</select></label><label class="flex flex-col gap-1.5 text-sm font-medium">Тип<select name="kind" class="' . input_class() . '"><option value="tech">Поломка</option><option value="incident">Происшествие</option></select></label></div>';
        $html .= '<label class="flex flex-col gap-1.5 text-sm font-medium">Заголовок<input name="title" class="' . input_class() . '" required></label>';
        $html .= '<label class="flex flex-col gap-1.5 text-sm font-medium">ПК<input name="pcNumber" class="' . input_class() . '" inputmode="numeric"></label>';
        $html .= '<label class="flex flex-col gap-1.5 text-sm font-medium">Подробности<textarea name="details" class="' . input_class('min-h-16') . '"></textarea></label>';
        $html .= '<button class="' . btn_class() . '">Добавить</button></form>';
        $html .= '<ul class="flex flex-col gap-3">';
        foreach ($rows as $r) {
            $open = $r['status'] !== 'done';
            $html .= '<li class="border-border bg-card rounded-xl border p-4 shadow-sm"><div class="flex flex-wrap items-start justify-between gap-2"><div class="min-w-0"><p class="text-xs uppercase tracking-wide text-muted-foreground">' . h($r['branchName']) . ' · ' . h(INCIDENT_KIND[$r['kind']] ?? $r['kind']) . ($r['pcNumber'] ? ' · ПК ' . (int) $r['pcNumber'] : '') . '</p><h2 class="gp-break font-semibold">' . h($r['title']) . '</h2><p class="text-muted-foreground gp-break text-sm whitespace-pre-wrap">' . h($r['details']) . '</p><p class="text-muted-foreground mt-2 text-xs">' . h($r['createdByName']) . ' · ' . h(INCIDENT_STATUS[$r['status']] ?? $r['status']) . '</p></div>';
            if ($open && $user['isOwner']) {
                $html .= '<form method="post">' . csrf_field() . '<input type="hidden" name="_action" value="resolve_incident"><input type="hidden" name="id" value="' . (int) $r['id'] . '"><button class="' . btn_class('outline', 'sm') . '">Закрыть</button></form>';
            }
            $html .= '</div></li>';
        }
        $html .= '</ul></main>';
        self::layout($user, $html);
    }

    private static function pageNews(array $user): void
    {
        $sql = 'SELECT a.*, (SELECT 1 FROM announcement_reads r WHERE r."announcementId"=a.id AND r."userId"=?) AS read_me FROM announcements a ';
        $sql .= $user['isOwner'] ? '' : 'WHERE a."isDraft"=0 ';
        $sql .= 'ORDER BY a."isPinned" DESC, a."createdAt" DESC';
        $rows = Db::all($sql, [$user['id']]);
        $html = '<main class="mx-auto flex w-full max-w-3xl flex-col gap-5 px-4 py-6 sm:px-6 sm:py-8"><h1 class="text-xl font-semibold tracking-tight">Объявления</h1>';
        if ($user['isOwner']) {
            $html .= '<form method="post" class="border-border bg-card flex flex-col gap-3 rounded-xl border p-4 shadow-sm">' . csrf_field() . '<input type="hidden" name="_action" value="save_news">';
            $html .= '<input name="title" class="' . input_class() . '" placeholder="Заголовок" required>';
            $html .= '<textarea name="body" class="' . input_class('min-h-20 gp-break') . '" placeholder="Текст"></textarea>';
            $html .= '<label class="flex items-center gap-2 text-sm"><input type="checkbox" name="isPinned" value="1" class="accent-primary"> Закрепить</label>';
            $html .= '<button class="' . btn_class() . '">Опубликовать</button></form>';
        }
        if (!$rows) {
            $html .= '<p class="text-muted-foreground text-sm">Объявлений пока нет.</p>';
        } else {
            $html .= '<ul class="flex flex-col gap-2">';
            foreach ($rows as $r) {
                $preview = news_preview((string) $r['body']);
                $unread = empty($r['read_me']);
                $pinned = boolish($r['isPinned']);
                $html .= '<li class="border-border bg-card relative overflow-hidden rounded-xl border shadow-sm" data-news-card data-id="' . (int) $r['id'] . '" data-unread="' . ($unread ? '1' : '0') . '" data-pinned="' . ($pinned ? '1' : '0') . '">';
                if ($user['isOwner']) {
                    $html .= '<form method="post" class="absolute top-2 right-2 z-10" onsubmit="return confirm(\'Удалить объявление без возврата?\');">' . csrf_field();
                    $html .= '<input type="hidden" name="_action" value="delete_news"><input type="hidden" name="id" value="' . (int) $r['id'] . '">';
                    $html .= '<button type="submit" class="' . btn_class('ghost', 'sm') . ' text-muted-foreground hover:text-destructive" title="Удалить объявление">' . icon('trash', 'size-4') . '<span class="hidden sm:inline">Удалить</span></button></form>';
                }
                $html .= '<button type="button" class="hover:bg-muted/40 flex w-full flex-col gap-1.5 p-3.5 text-left' . ($user['isOwner'] ? ' pr-24 sm:pr-28' : '') . '" data-news-open>';
                $html .= '<div class="flex items-start gap-2">';
                if ($pinned) {
                    $html .= '<span class="bg-primary/15 text-primary shrink-0 rounded px-1.5 py-0.5 text-[10px] font-semibold tracking-wide uppercase">Закреплено</span>';
                }
                $html .= '<span class="gp-break min-w-0 flex-1 text-sm font-medium sm:text-base" data-news-title>' . h($r['title']) . '</span>';
                $html .= icon('chevronright', 'text-muted-foreground mt-0.5 size-4 shrink-0');
                $html .= '</div>';
                if ($preview !== '') {
                    $html .= '<p class="gp-break gp-clamp-2 text-muted-foreground text-sm leading-relaxed">' . h($preview) . '</p>';
                }
                $html .= '<div class="flex flex-wrap items-center gap-x-2 gap-y-1">';
                $html .= '<span class="text-muted-foreground text-xs" data-news-meta>' . h($r['createdByName']) . '</span>';
                if ($unread) {
                    $html .= '<span class="bg-primary/15 text-primary rounded-full px-2 py-0.5 text-[11px] font-medium">Новое</span>';
                } else {
                    $html .= '<span class="text-primary text-[11px]">' . icon('check', 'size-3') . ' Прочитано</span>';
                }
                $html .= '</div></button>';
                $html .= '<template data-news-full>' . h((string) $r['body']) . '</template>';
                $html .= '</li>';
            }
            $html .= '</ul>';
        }
        $html .= '<dialog class="gp-news-dialog border-border bg-card text-card-foreground rounded-xl border p-0 shadow-xl" data-news-dialog data-can-delete="' . ($user['isOwner'] ? '1' : '0') . '">';
        $html .= '<div class="flex max-h-[85vh] flex-col gap-4 p-5">';
        $html .= '<div class="flex items-start justify-between gap-3"><div class="min-w-0 flex-1">';
        $html .= '<p class="text-primary mb-1 hidden text-xs font-semibold uppercase tracking-wide" data-news-pin>Закреплено</p>';
        $html .= '<h3 class="gp-break text-lg font-semibold" data-news-dlg-title></h3>';
        $html .= '<p class="text-muted-foreground mt-1 text-xs" data-news-dlg-meta></p></div>';
        $html .= '<button type="button" class="' . btn_class('ghost', 'icon') . '" data-news-close aria-label="Закрыть">' . icon('x', 'size-5') . '</button></div>';
        $html .= '<div class="gp-break min-h-0 flex-1 overflow-y-auto text-base leading-relaxed whitespace-pre-wrap" data-news-dlg-body></div>';
        $html .= '<div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">';
        $html .= '<form method="post" class="w-full sm:w-auto" data-news-read-form>' . csrf_field();
        $html .= '<input type="hidden" name="_action" value="read_news"><input type="hidden" name="id" value="" data-news-id-field>';
        $html .= '<button class="' . btn_class('default', 'xl') . ' w-full shadow-md sm:w-auto">' . icon('check', 'size-5') . ' Прочитал</button></form>';
        if ($user['isOwner']) {
            $html .= '<form method="post" data-news-del-form onsubmit="return confirm(\'Удалить объявление без возврата?\');">' . csrf_field();
            $html .= '<input type="hidden" name="_action" value="delete_news"><input type="hidden" name="id" value="" data-news-id-field>';
            $html .= '<button class="' . btn_class('destructive') . '">' . icon('trash', 'size-4') . ' Удалить</button></form>';
        }
        $html .= '<button type="button" class="' . btn_class('outline') . ' min-h-11 w-full sm:ml-auto sm:w-auto" data-news-close>Закрыть</button>';
        $html .= '</div></div></dialog></main>';
        self::layout($user, $html);
    }

    private static function pageLoyalty(array $user): void
    {
        $tiers = Db::all('SELECT * FROM loyalty_tiers ORDER BY "sortOrder", id');
        $tariffs = self::portal_json('tariffs', DEFAULT_TARIFFS);
        $pricing = [];
        foreach ($tariffs['branches'] as $b) {
            $zones = [];
            foreach ($b['zones'] as $i => $z) {
                $hour = (int) ($b['packages'][0]['prices'][$i] ?? 0);
                $zones[] = ['name' => $z, 'hourPrice' => $hour];
            }
            $pricing[] = ['branchName' => $b['branchName'], 'zones' => $zones];
        }
        $html = '<main class="mx-auto flex w-full max-w-5xl flex-col gap-6 px-4 py-8 sm:px-6"><div class="flex flex-wrap items-start justify-between gap-3"><h1 class="text-2xl font-semibold tracking-tight">Бонусная система</h1>';
        if ($user['isOwner']) {
            $html .= '<a class="' . btn_class('outline', 'sm') . '" href="' . h(url_to('/loyalty/edit')) . '">' . icon('pencil') . ' Изменить уровни</a>';
        }
        $html .= '</div><section class="flex flex-col gap-3"><h2 class="text-base font-semibold">Уровни</h2><ul class="flex flex-col gap-2" data-loyalty>';
        foreach ($tiers as $t) {
            $upper = $t['maxHours'] === null || $t['maxHours'] === '' ? 'и больше' : '— ' . (int) $t['maxHours'];
            $html .= '<li><button type="button" class="flex w-full flex-wrap items-center gap-x-6 gap-y-2 rounded-lg border border-border bg-card px-4 py-3 text-left hover:border-muted-foreground/40" data-tier data-percent="' . (int) $t['cashbackPercent'] . '">';
            $html .= '<div class="flex min-w-40 flex-1 flex-col"><span class="font-semibold">' . h($t['title']) . '</span><span class="text-muted-foreground text-xs">' . (int) $t['minHours'] . ' ' . h($upper) . ' игровых часов</span></div>';
            $html .= '<div class="flex items-center gap-1.5">' . icon('gift', 'text-muted-foreground size-4') . '<span class="text-muted-foreground text-xs">кэшбек</span><span class="text-primary font-semibold">' . (int) $t['cashbackPercent'] . '%</span></div>';
            $html .= '<div class="flex items-center gap-1.5">' . icon('cake', 'text-muted-foreground size-4') . '<span class="text-muted-foreground text-xs">на день рождения</span><span class="font-semibold">' . money_rub((int) $t['birthdayBonus']) . '</span></div></button></li>';
        }
        $html .= '</ul><div class="border-primary/40 bg-accent/40 hidden rounded-lg border p-4 sm:flex-row sm:items-end" data-topup-box><div class="flex flex-1 flex-col gap-1.5"><label class="text-sm font-medium">Сумма пополнения, ₽</label><input class="' . input_class() . '" data-topup placeholder="например, 500"></div><div class="mt-3"><span class="text-muted-foreground text-xs uppercase tracking-wide">Вернётся бонусами</span><p class="text-primary text-2xl font-semibold" data-cashback>—</p></div></div></section>';
        $html .= '<section class="border-border bg-card flex flex-col gap-4 rounded-xl border p-4 sm:p-6" data-time-calc><h2 class="text-base font-semibold">Стоимость времени по базовому тарифу</h2>';
        $html .= '<div class="flex gap-1 rounded-lg border border-border p-1" data-branch-tabs>';
        foreach ($pricing as $i => $p) {
            $html .= '<button type="button" class="rounded-md px-3 py-1.5 text-sm font-medium ' . ($i === 0 ? 'bg-primary text-primary-foreground' : 'text-muted-foreground') . '" data-branch-tab="' . $i . '">' . h($p['branchName']) . '</button>';
        }
        $html .= '</div><div class="flex min-w-0 flex-col gap-2 sm:flex-row"><input class="' . input_class() . '" data-duration placeholder="время"><select class="' . input_class('sm:w-32') . '" data-unit><option value="minutes">минут</option><option value="hours">часов</option></select></div>';
        foreach ($pricing as $i => $p) {
            $html .= '<div class="' . ($i === 0 ? '' : 'hidden ') . 'grid grid-cols-1 gap-2 sm:grid-cols-3" data-branch-panel="' . $i . '">';
            foreach ($p['zones'] as $z) {
                $html .= '<div class="border-border rounded-lg border px-3 py-2"><p class="text-sm font-medium">' . h($z['name']) . '</p><p class="text-muted-foreground text-xs">' . (int) $z['hourPrice'] . ' ₽/час</p><p class="text-lg font-semibold" data-zone-price data-hour="' . (int) $z['hourPrice'] . '">—</p></div>';
            }
            $html .= '</div>';
        }
        $html .= '</section></main>';
        self::layout($user, $html);
    }

    private static function pageLoyaltyEdit(array $user): void
    {
        $tiers = Db::all('SELECT * FROM loyalty_tiers ORDER BY "sortOrder", id');
        $html = '<main class="mx-auto flex w-full max-w-3xl flex-col gap-5 px-4 py-6"><h1 class="text-xl font-semibold">Уровни лояльности</h1>';
        foreach ($tiers as $t) {
            $html .= '<form method="post" class="border-border bg-card grid grid-cols-1 gap-2 rounded-xl border p-4 sm:grid-cols-5">' . csrf_field() . '<input type="hidden" name="_action" value="save_loyalty"><input type="hidden" name="id" value="' . (int) $t['id'] . '">';
            $html .= '<input name="title" class="' . input_class() . '" value="' . h($t['title']) . '">';
            $html .= '<input name="minHours" class="' . input_class() . '" value="' . (int) $t['minHours'] . '" placeholder="от часов">';
            $html .= '<input name="maxHours" class="' . input_class() . '" value="' . h((string) ($t['maxHours'] ?? '')) . '" placeholder="до">';
            $html .= '<input name="cashbackPercent" class="' . input_class() . '" value="' . (int) $t['cashbackPercent'] . '" placeholder="%">';
            $html .= '<button class="' . btn_class() . '">Сохранить</button></form>';
        }
        $html .= '</main>';
        self::layout($user, $html);
    }

    private static function pageReviews(array $user): void
    {
        $promo = self::portal_json('review_promo', DEFAULT_REVIEW_PROMO);
        $branches = self::branches();
        $rows = Db::all('SELECT r.*, b.name AS "branchName" FROM review_bonuses r JOIN branches b ON b.id=r."branchId" ORDER BY r."createdAt" DESC LIMIT 300');
        $html = '<main class="mx-auto flex w-full max-w-5xl flex-col gap-6 px-4 py-6 sm:px-6 sm:py-8"><div class="flex flex-wrap items-start justify-between gap-3"><h1 class="text-xl font-semibold tracking-tight">Бонусы за отзывы</h1>';
        if ($user['isOwner']) {
            $html .= '<a class="' . btn_class('outline', 'sm') . '" href="' . h(url_to('/reviews/edit')) . '">' . icon('pencil') . ' Условия акции</a>';
        }
        $html .= '</div><p class="text-sm leading-relaxed">' . h($promo['intro']) . '</p>';
        $html .= '<form method="post" class="border-border bg-card flex flex-col gap-3 rounded-xl border p-4 shadow-sm" data-review-form>' . csrf_field() . '<input type="hidden" name="_action" value="save_review">';
        $html .= '<div class="grid grid-cols-1 gap-3 sm:grid-cols-2"><label class="text-sm font-medium">Филиал<select name="branchId" class="' . input_class() . '">';
        foreach ($branches as $b) {
            $sel = (int) $b['id'] === (int) ($user['homeBranchId'] ?? 0) ? ' selected' : '';
            $html .= '<option value="' . (int) $b['id'] . '"' . $sel . '>' . h($b['name']) . '</option>';
        }
        $html .= '</select></label><label class="text-sm font-medium">Телефон<input name="guestPhone" class="' . input_class() . '" required placeholder="+7 ..." inputmode="tel" autocomplete="tel" data-review-phone></label></div>';
        $html .= '<p class="text-sm">За текстовый отзыв: <span class="font-semibold">' . (int) $promo['textBonus'] . ' бонусов</span></p>';
        $html .= '<button class="' . btn_class() . '">Добавить заявку</button></form>';
        $html .= '<input class="' . input_class() . '" placeholder="Фильтр по телефону или филиалу" data-review-filter>';
        $html .= '<p class="text-muted-foreground hidden text-sm" data-review-empty>Нет заявок с таким началом номера — бонуса на этом префиксе не было.</p>';
        $html .= '<div class="overflow-x-auto"><table class="gp-cards w-full text-sm" data-review-table><thead><tr class="bg-muted/60 text-left text-xs uppercase"><th class="px-3 py-2">Когда</th><th class="px-3 py-2">Филиал</th><th class="px-3 py-2">Телефон</th><th class="px-3 py-2">Тип</th><th class="px-3 py-2">Сумма</th><th class="px-3 py-2">Статус</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $phoneDigits = preg_replace('/\D+/', '', (string) $r['guestPhone']) ?? '';
            if (strlen($phoneDigits) === 11 && ($phoneDigits[0] === '7' || $phoneDigits[0] === '8')) {
                $phoneDigits = substr($phoneDigits, 1);
            }
            $html .= '<tr class="border-t border-border" data-phone="' . h($phoneDigits) . '" data-row-text="' . h(mb_strtolower($r['guestPhone'] . ' ' . $r['branchName'])) . '">'
                . td_th('Когда', h(substr((string) $r['createdAt'], 0, 16)))
                . td_th('Филиал', h($r['branchName']))
                . td_th('Телефон', h($r['guestPhone']), 'px-3 py-2 font-mono')
                . td_th('Тип', h(REVIEW_KIND[$r['reviewKind']] ?? $r['reviewKind']))
                . td_th('Сумма', money_rub((int) $r['bonusAmount']))
                . td_th('Статус', h(REVIEW_STATUS[$r['status']] ?? $r['status']))
                . '<td class="px-3 py-2" data-th="">';
            if ($user['isOwner'] && $r['status'] === 'pending') {
                $html .= '<form method="post" class="inline">' . csrf_field() . '<input type="hidden" name="_action" value="decide_review"><input type="hidden" name="id" value="' . (int) $r['id'] . '"><button name="decision" value="credited" class="' . btn_class('default', 'sm') . '">Зачислить</button> <button name="decision" value="rejected" class="' . btn_class('destructive', 'sm') . '">Отказ</button></form>';
            }
            $html .= '</td></tr>';
        }
        $html .= '</tbody></table></div></main>';
        self::layout($user, $html);
    }

    private static function pageReviewsEdit(array $user): void
    {
        $promo = self::portal_json('review_promo', DEFAULT_REVIEW_PROMO);
        $html = '<main class="mx-auto max-w-xl px-4 py-8"><h1 class="text-xl font-semibold">Акция за отзывы</h1><form method="post" class="mt-4 flex flex-col gap-3">' . csrf_field() . '<input type="hidden" name="_action" value="save_promo">';
        $html .= '<label class="text-sm font-medium">Текст условий<textarea name="intro" class="' . input_class('min-h-24') . '">' . h($promo['intro']) . '</textarea></label>';
        $html .= '<label class="text-sm font-medium">Бонус за текст<input name="textBonus" class="' . input_class() . '" value="' . (int) $promo['textBonus'] . '"></label>';
        $html .= '<button class="' . btn_class() . '">Сохранить</button></form></main>';
        self::layout($user, $html);
    }

    private static function pageCompensations(array $user): void
    {
        $branches = self::branches();
        $rows = Db::all('SELECT c.*, b.name AS "branchName" FROM compensations c JOIN branches b ON b.id=c."branchId" ORDER BY c."createdAt" DESC LIMIT 300');
        $html = '<main class="mx-auto flex w-full max-w-5xl flex-col gap-6 px-4 py-6"><h1 class="text-xl font-semibold">Компенсации</h1>';
        $html .= '<form method="post" class="border-border bg-card flex flex-col gap-3 rounded-xl border p-4">' . csrf_field() . '<input type="hidden" name="_action" value="save_comp">';
        $html .= '<div class="grid gap-3 sm:grid-cols-2"><select name="branchId" class="' . input_class() . '">';
        foreach ($branches as $b) {
            $html .= '<option value="' . (int) $b['id'] . '">' . h($b['name']) . '</option>';
        }
        $html .= '</select><input name="guestPhone" class="' . input_class() . '" placeholder="Телефон" required></div>';
        $html .= '<input name="reason" class="' . input_class() . '" placeholder="Причина" required>';
        $html .= '<textarea name="actionsTaken" class="' . input_class('min-h-16') . '" placeholder="Что вы сделали?"></textarea>';
        $html .= '<input name="bonusAmount" class="' . input_class() . '" placeholder="Сумма, ₽" required>';
        $html .= '<button class="' . btn_class() . '">Добавить</button></form>';
        $html .= '<input class="' . input_class() . '" data-filter-table placeholder="Фильтр">';
        $html .= '<div class="overflow-x-auto"><table class="gp-cards w-full text-sm"><thead><tr class="bg-muted/60 text-left text-xs uppercase"><th class="px-3 py-2">Когда</th><th class="px-3 py-2">Филиал</th><th class="px-3 py-2">Телефон</th><th class="px-3 py-2">Причина</th><th class="px-3 py-2">Что сделали</th><th class="px-3 py-2">Сумма</th><th class="px-3 py-2">Статус</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $done = trim((string) ($r['actionsTaken'] ?? ''));
            $html .= '<tr class="border-t border-border" data-row-text="' . h(mb_strtolower($r['guestPhone'] . ' ' . $r['branchName'] . ' ' . $r['reason'] . ' ' . $done)) . '">'
                . td_th('Когда', h(substr((string) $r['createdAt'], 0, 16)))
                . td_th('Филиал', h($r['branchName']))
                . td_th('Телефон', h($r['guestPhone']), 'px-3 py-2 font-mono')
                . td_th('Причина', h($r['reason']))
                . td_th('Что сделали', ($done !== '' ? h($done) : '—'), 'px-3 py-2 max-w-xs whitespace-pre-wrap')
                . td_th('Сумма', money_rub((int) $r['bonusAmount']))
                . td_th('Статус', h(REVIEW_STATUS[$r['status']] ?? $r['status']))
                . '<td data-th="">';
            if ($user['isOwner'] && $r['status'] === 'pending') {
                $html .= '<form method="post">' . csrf_field() . '<input type="hidden" name="_action" value="decide_comp"><input type="hidden" name="id" value="' . (int) $r['id'] . '"><button name="decision" value="credited" class="' . btn_class('default', 'sm') . '">Ок</button> <button name="decision" value="rejected" class="' . btn_class('destructive', 'sm') . '">Нет</button></form>';
            }
            $html .= '</td></tr>';
        }
        $html .= '</tbody></table></div></main>';
        self::layout($user, $html);
    }

    private static function wikiNav(array $user, string $active): string
    {
        $cats = Db::all('SELECT * FROM knowledge_categories ORDER BY "sortOrder", id');
        $arts = Db::all('SELECT id, title, slug, "isDraft", "categoryId", "contentHtml" FROM knowledge_articles WHERE kind != \'fines\'' . ($user['isOwner'] ? '' : ' AND "isDraft"=0') . ' ORDER BY "sortOrder", id');
        $html = '<div class="flex flex-col gap-3" data-wiki-nav><div class="relative">' . icon('search', 'text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2') . '<input class="' . input_class('pl-8') . '" placeholder="Поиск по базе…" data-wiki-search></div>';
        foreach ($cats as $c) {
            $items = array_filter($arts, fn ($a) => (int) $a['categoryId'] === (int) $c['id']);
            if (!$items) {
                continue;
            }
            $html .= '<div class="border-border bg-card overflow-hidden rounded-xl border shadow-sm"><p class="border-border bg-muted/50 flex items-center gap-2 border-b px-3 py-2.5"><span class="bg-primary h-3.5 w-0.5 rounded-full"></span><span class="text-[11px] font-semibold uppercase tracking-[0.14em]">' . h($c['title']) . '</span></p><ul class="flex flex-col gap-0.5 p-2">';
            foreach ($items as $a) {
                $on = $a['slug'] === $active;
                $plain = mb_strtolower(trim(html_entity_decode(strip_tags((string) $a['contentHtml']))));
                $html .= '<li><a data-wiki-item data-text="' . h(mb_strtolower($a['title'] . ' ' . $plain)) . '" class="flex w-full items-center gap-2 rounded-lg px-2.5 py-2 text-left text-sm ' . ($on ? 'bg-accent text-accent-foreground font-medium' : 'text-muted-foreground hover:bg-muted hover:text-foreground') . '" href="' . h(url_to('/wiki/' . rawurlencode($a['slug']))) . '"><span class="min-w-0 flex-1 leading-snug">' . h($a['title']) . '</span>' . (boolish($a['isDraft']) ? '<span class="border-border text-muted-foreground rounded border px-1 text-[10px] uppercase">черновик</span>' : '') . '</a></li>';
            }
            $html .= '</ul></div>';
        }
        return $html . '</div>';
    }

    private static function pageWikiEmpty(array $user): void
    {
        self::layout($user, '<main class="py-10"><p class="text-muted-foreground text-sm">В базе знаний пока нет разделов.</p></main>');
    }

    private static function pageWiki(array $user, string $slug): void
    {
        $a = Db::one('SELECT a.*, c.title AS "categoryTitle" FROM knowledge_articles a JOIN knowledge_categories c ON c.id=a."categoryId" WHERE a.slug=?' . ($user['isOwner'] ? '' : ' AND a."isDraft"=0'), [$slug]);
        if (!$a) {
            http_response_code(404);
            self::layout($user, '<main class="px-4 py-10"><p>Раздел не найден.</p></main>');
            return;
        }
        $inner = '<div class="min-w-0"><div class="border-border flex flex-col gap-2 border-b pb-6"><p class="text-primary text-[11px] font-semibold uppercase tracking-wider">' . h($a['categoryTitle']) . '</p><div class="flex flex-wrap items-center justify-between gap-3"><h1 class="text-2xl font-semibold tracking-tight sm:text-3xl">' . h($a['title']) . '</h1>';
        if ($user['isOwner']) {
            $inner .= '<a class="' . btn_class('outline', 'sm') . '" href="' . h(url_to('/wiki/' . rawurlencode($slug) . '/edit')) . '">' . icon('pencil') . ' Редактировать</a>';
        }
        $inner .= '</div></div><div class="wiki-content mt-6">' . $a['contentHtml'] . '</div></div>';
        $html = '<div class="mx-auto flex w-full max-w-6xl flex-col gap-6 px-4 py-6 sm:px-6 sm:py-8 lg:flex-row lg:gap-10"><aside class="lg:w-72 lg:shrink-0"><div class="lg:sticky lg:top-20">' . self::wikiNav($user, $slug) . '</div></aside>' . $inner . '</div>';
        self::layout($user, $html);
    }

    private static function pageWikiEdit(array $user, string $slug): void
    {
        $a = Db::one('SELECT * FROM knowledge_articles WHERE slug=?', [$slug]);
        if (!$a) {
            redirect_to('/wiki');
        }
        $html = '<main class="mx-auto max-w-3xl px-4 py-8"><h1 class="text-xl font-semibold">Редактировать: ' . h($a['title']) . '</h1><form method="post" class="mt-4 flex flex-col gap-3">' . csrf_field() . '<input type="hidden" name="_action" value="save_wiki"><input type="hidden" name="id" value="' . (int) $a['id'] . '">';
        $html .= '<input name="title" class="' . input_class() . '" value="' . h($a['title']) . '">';
        $html .= '<textarea name="contentHtml" class="' . input_class('min-h-[420px] font-mono text-sm') . '">' . h($a['contentHtml']) . '</textarea>';
        $html .= '<button class="' . btn_class() . '">Сохранить</button></form></main>';
        self::layout($user, $html);
    }

    private static function pageTariffs(array $user): void
    {
        $c = self::portal_json('tariffs', DEFAULT_TARIFFS);
        $html = '<main class="mx-auto flex w-full max-w-4xl flex-col gap-6 px-4 py-6 sm:px-6 sm:py-8"><div class="flex flex-wrap items-start justify-between gap-3"><div><h1 class="text-2xl font-semibold tracking-tight">Тарифы</h1><p class="text-muted-foreground mt-1 text-sm">Пакеты обоих филиалов — как на прайс-листах у стойки.</p></div>';
        if ($user['isOwner']) {
            $html .= '<a class="' . btn_class('outline', 'sm') . '" href="' . h(url_to('/tariffs/edit')) . '">' . icon('pencil') . ' Редактировать</a>';
        }
        $html .= '</div><div class="border-primary/25 bg-primary/5 flex flex-col gap-2 rounded-xl border px-4 py-3 sm:flex-row sm:items-center sm:justify-between"><p class="text-sm leading-relaxed">' . h($c['intro']) . '</p><a class="text-primary inline-flex items-center gap-1 text-sm font-medium hover:underline" href="' . h(url_to('/wiki/prais-i-tarify')) . '">' . icon('book') . ' В базе знаний ' . icon('arrow', 'size-3.5') . '</a></div>';
        foreach ($c['branches'] as $b) {
            $html .= '<section class="border-border bg-card overflow-hidden rounded-xl border shadow-sm"><header class="border-border flex flex-col gap-1 border-b px-4 py-4 sm:flex-row sm:items-baseline sm:justify-between sm:px-5"><h2 class="text-xl font-semibold tracking-tight">' . h($b['branchName']) . '</h2><p class="text-muted-foreground flex items-center gap-1.5 text-sm">' . icon('pin', 'size-3.5') . ' ' . h($b['address']) . '</p></header><div class="overflow-x-auto"><table class="gp-cards w-full text-sm lg:min-w-[28rem]"><thead><tr class="bg-muted/50"><th class="text-muted-foreground px-4 py-3 text-left text-xs font-semibold uppercase">Пакет</th>';
            foreach ($b['zones'] as $z) {
                $html .= '<th class="px-4 py-3 text-left text-xs font-semibold uppercase">' . h($z) . '</th>';
            }
            $html .= '</tr></thead><tbody>';
            foreach ($b['packages'] as $row) {
                $html .= '<tr class="border-border border-t">' . th_th('Пакет', h($row['label']) . (!empty($row['hint']) ? '<span class="text-muted-foreground mt-0.5 block text-xs font-normal">' . h($row['hint']) . '</span>' : ''), 'px-4 py-3 text-left font-medium');
                foreach ($row['prices'] as $i => $price) {
                    $zone = (string) ($b['zones'][$i] ?? '');
                    $html .= td_th($zone, money_rub((int) $price), 'px-4 py-3 tabular-nums');
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table></div></section>';
        }
        $html .= '<p class="text-muted-foreground text-sm leading-relaxed">' . h($c['footer']) . '</p></main>';
        self::layout($user, $html);
    }

    private static function pageTariffsEdit(array $user): void
    {
        $c = self::portal_json('tariffs', DEFAULT_TARIFFS);
        $html = '<main class="mx-auto max-w-3xl px-4 py-8"><h1 class="text-xl font-semibold">Редактор тарифов</h1><p class="text-muted-foreground text-sm mt-1">JSON как в живом портале. Сохраняется в базу.</p><form method="post" class="mt-4 flex flex-col gap-3">' . csrf_field() . '<input type="hidden" name="_action" value="save_tariffs">';
        $html .= '<textarea name="json" class="' . input_class('min-h-[420px] font-mono text-sm') . '">' . h(json_encode($c, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . '</textarea>';
        $html .= '<button class="' . btn_class() . '">Сохранить</button></form></main>';
        self::layout($user, $html);
    }

    private static function assignedFines(array $user, bool $allIfOwner): array
    {
        if ($allIfOwner && !empty($user['isOwner'])) {
            return Db::all('SELECT * FROM employee_fines ORDER BY "createdAt" DESC LIMIT 200');
        }
        $eid = (int) ($user['employeeId'] ?? 0);
        if ($eid < 1) {
            return [];
        }
        return Db::all('SELECT * FROM employee_fines WHERE "employeeId"=? ORDER BY "createdAt" DESC LIMIT 100', [$eid]);
    }

    private static function htmlAssignedFines(array $rows, bool $showPerson, string $title, string $hint = ''): string
    {
        if (!$rows) {
            $html = '<section class="border-border bg-card rounded-xl border px-4 py-3"><h2 class="text-base font-semibold">' . h($title) . '</h2>';
            if ($hint !== '') {
                $html .= '<p class="text-muted-foreground mt-1 text-sm">' . h($hint) . '</p>';
            }
            return $html . '<p class="text-muted-foreground mt-2 text-sm">Штрафов пока нет.</p></section>';
        }
        $sum = 0;
        foreach ($rows as $r) {
            $sum += (int) $r['amount'];
        }
        $n = count($rows);
        $html = '<details class="border-border bg-card rounded-xl border" data-fines-list data-fines-step="10">';
        $html .= '<summary class="flex cursor-pointer items-center gap-3 px-4 py-3">';
        $html .= '<span class="min-w-0 flex-1"><span class="block text-base font-semibold">' . h($title) . '</span>';
        $html .= '<span class="text-muted-foreground mt-0.5 block text-sm">' . h(plural($n, 'запись', 'записи', 'записей')) . ', ' . money_rub($sum) . '</span></span>';
        $html .= icon('chevrondown', 'text-muted-foreground size-4 shrink-0 transition-transform') . '</summary>';
        $html .= '<div class="px-4 pb-4">';
        if ($hint !== '') {
            $html .= '<p class="text-muted-foreground mb-3 text-sm">' . h($hint) . '</p>';
        }
        $html .= '<div class="border-border overflow-x-auto rounded-lg border"><table class="gp-cards w-full text-sm"><thead><tr class="bg-muted/60"><th class="px-3 py-2.5 text-left text-xs font-semibold uppercase">Когда</th>';
        if ($showPerson) {
            $html .= '<th class="px-3 py-2.5 text-left text-xs font-semibold uppercase">Кому</th>';
        }
        $html .= '<th class="px-3 py-2.5 text-left text-xs font-semibold uppercase">За что</th><th class="w-28 px-3 py-2.5 text-right text-xs font-semibold uppercase">Сумма</th></tr></thead><tbody>';
        foreach ($rows as $i => $r) {
            $when = str_replace('T', ' ', substr((string) $r['createdAt'], 0, 16));
            $html .= '<tr class="border-t border-border' . ($i >= 10 ? ' hidden' : '') . '" data-fine-row>';
            $html .= td_th('Когда', h($when), 'text-muted-foreground whitespace-nowrap px-3 py-2.5');
            if ($showPerson) {
                $html .= td_th('Кому', h((string) $r['employeeName']), 'px-3 py-2.5');
            }
            $html .= td_th('За что', h((string) $r['reason']), 'px-3 py-2.5') . td_th('Сумма', money_rub((int) $r['amount']), 'px-3 py-2.5 text-right font-medium tabular-nums');
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';
        if ($n > 10) {
            $left = $n - 10;
            $html .= '<button type="button" class="' . btn_class('outline', 'sm') . ' mt-3" data-fines-more>Ещё ' . min(10, $left) . '</button>';
        }
        $html .= '</div></details>';
        return $html;
    }

    private static function pageFines(array $user): void
    {
        $article = Db::one('SELECT * FROM knowledge_articles WHERE slug=? OR kind=?', ['fines', 'fines']);
        $rules = Db::all('SELECT * FROM fine_rules WHERE "isActive"=1 ORDER BY "sortOrder", id');
        $assigned = self::assignedFines($user, true);
        $html = '<main class="mx-auto flex w-full max-w-4xl flex-col gap-6 px-4 py-6 sm:px-6 sm:py-8"><div class="flex flex-wrap items-center justify-between gap-3"><h1 class="text-2xl font-semibold tracking-tight">Лист штрафов</h1>';
        if ($user['isOwner']) {
            $html .= '<div class="flex flex-wrap items-center gap-2">';
            $html .= '<a class="' . btn_class('default', 'sm') . '" href="' . h(url_to('/fines/assign')) . '">Назначить штраф</a>';
            $html .= '<a class="' . btn_class('outline', 'sm') . '" href="' . h(url_to('/fines/edit')) . '">' . icon('pencil') . ' Редактировать</a>';
            $html .= '</div>';
        }
        $html .= '</div>';
        $html .= self::htmlAssignedFines(
            $assigned,
            (bool) $user['isOwner'],
            $user['isOwner'] ? 'Назначенные штрафы' : 'Ваши штрафы',
            $user['isOwner'] ? 'Кому выписали, за что и сколько.' : 'За что выписали и сколько.',
        );
        if ($article && $article['contentHtml']) {
            $html .= '<div class="wiki-content">' . $article['contentHtml'] . '</div>';
        }
        $html .= '<div class="border-border overflow-x-auto rounded-lg border"><table class="gp-cards w-full text-sm"><thead><tr class="bg-muted/60"><th class="px-3 py-2.5 text-left text-xs font-semibold uppercase">Нарушение</th><th class="w-32 px-3 py-2.5 text-right text-xs font-semibold uppercase">Штраф</th></tr></thead><tbody>';
        foreach ($rules as $r) {
            $dis = boolish($r['primaryDismissal']);
            $html .= '<tr class="border-t border-border">' . th_th('Нарушение', h($r['violation']), 'px-3 py-2.5 text-left font-normal') . td_th('Штраф', h(format_fine_amount($r['primaryAmount'] === null ? null : (int) $r['primaryAmount'], $dis)), 'px-3 py-2.5 text-right tabular-nums' . ($dis ? ' text-primary font-medium' : '')) . '</tr>';
        }
        $html .= '</tbody></table></div></main>';
        self::layout($user, $html);
    }

    private static function pageFinesAssign(array $user): void
    {
        $emps = array_values(array_filter(self::employees(), static fn ($e) => boolish($e['isActive'])));
        $bnames = [];
        foreach (self::branches() as $b) {
            $bnames[(int) $b['id']] = (string) $b['name'];
        }
        $stash = form_stash_take('fine-assign') ?? [];
        $picked = (int) ($stash['employeeId'] ?? 0);
        $reason = (string) ($stash['reason'] ?? '');
        $amount = (string) ($stash['amount'] ?? '');
        $html = '<main class="mx-auto max-w-lg px-4 py-8"><a href="' . h(url_to('/fines')) . '" class="text-muted-foreground hover:text-foreground mb-4 inline-flex text-sm">← К листу штрафов</a>';
        $html .= '<h1 class="text-xl font-semibold">Назначить штраф</h1>';
        $html .= '<p class="text-muted-foreground mt-1 text-sm">Причина и сумма появятся у администратора в листе штрафов и в профиле.</p>';
        $html .= '<form method="post" class="border-border mt-5 flex flex-col gap-3 rounded-xl border p-4">' . csrf_field() . '<input type="hidden" name="_action" value="assign_fine">';
        $html .= '<label class="flex flex-col gap-1.5 text-sm font-medium">Сотрудник<select name="employeeId" class="' . input_class() . '" required><option value="">Выберите</option>';
        foreach ($emps as $e) {
            $label = (string) $e['name'];
            $bn = $bnames[(int) $e['homeBranchId']] ?? '';
            if ($bn !== '') {
                $label .= ' · ' . $bn;
            }
            $sel = (int) $e['id'] === $picked ? ' selected' : '';
            $html .= '<option value="' . (int) $e['id'] . '"' . $sel . '>' . h($label) . '</option>';
        }
        $html .= '</select></label>';
        $html .= '<label class="flex flex-col gap-1.5 text-sm font-medium">Причина<textarea name="reason" class="' . input_class('min-h-16') . '" required placeholder="За что штраф">' . h($reason) . '</textarea></label>';
        $html .= '<label class="flex flex-col gap-1.5 text-sm font-medium">Сумма, ₽<input name="amount" class="' . input_class() . '" inputmode="numeric" required placeholder="например, 200" value="' . h($amount) . '"></label>';
        $html .= '<button class="' . btn_class() . '">Назначить</button></form></main>';
        self::layout($user, $html);
    }

    private static function pageFinesEdit(array $user): void
    {
        $rules = Db::all('SELECT * FROM fine_rules ORDER BY "sortOrder", id');
        $html = '<main class="mx-auto max-w-3xl px-4 py-8"><a href="' . h(url_to('/fines')) . '" class="text-muted-foreground hover:text-foreground mb-4 inline-flex text-sm">← К листу штрафов</a>';
        $html .= '<h1 class="text-xl font-semibold">Редактор штрафов</h1>';
        $html .= '<p class="text-muted-foreground mt-1 text-sm">Пункты листа: нарушение, сумма или увольнение.</p>';
        foreach ($rules as $r) {
            $html .= '<form method="post" class="border-border mt-3 grid gap-2 rounded-xl border p-3 sm:grid-cols-[1fr_8rem_auto]">' . csrf_field() . '<input type="hidden" name="_action" value="save_fines"><input type="hidden" name="id" value="' . (int) $r['id'] . '">';
            $html .= '<input name="violation" class="' . input_class() . '" value="' . h($r['violation']) . '">';
            $html .= '<input name="primaryAmount" class="' . input_class() . '" value="' . h((string) $r['primaryAmount']) . '" placeholder="₽ или пусто">';
            $html .= '<label class="flex items-center gap-2 text-sm"><input type="checkbox" name="primaryDismissal" value="1"' . (boolish($r['primaryDismissal']) ? ' checked' : '') . '> увольнение</label>';
            $html .= '<button class="' . btn_class() . ' sm:col-span-3">Сохранить</button></form>';
        }
        $html .= '<h2 class="mt-8 text-base font-semibold">Новый пункт листа</h2>';
        $html .= '<form method="post" class="border-border mt-3 grid gap-2 rounded-xl border p-3 sm:grid-cols-[1fr_8rem_auto]">' . csrf_field() . '<input type="hidden" name="_action" value="save_fines"><input type="hidden" name="id" value="">';
        $html .= '<input name="violation" class="' . input_class() . '" placeholder="Нарушение" required>';
        $html .= '<input name="primaryAmount" class="' . input_class() . '" placeholder="₽ или пусто">';
        $html .= '<label class="flex items-center gap-2 text-sm"><input type="checkbox" name="primaryDismissal" value="1"> увольнение</label>';
        $html .= '<button class="' . btn_class() . ' sm:col-span-3">Добавить</button></form>';
        $html .= '</main>';
        self::layout($user, $html);
    }

    private static function examBySlug(string $slug, bool $includeDrafts): ?array
    {
        $exam = Db::one('SELECT * FROM exams WHERE slug = ?', [$slug]);
        if (!$exam) {
            return null;
        }
        if (!$includeDrafts && boolish($exam['isDraft'])) {
            return null;
        }
        $exam['id'] = (int) $exam['id'];
        $exam['passPercent'] = (int) $exam['passPercent'];
        $exam['xpReward'] = self::examXpReward($exam);
        $exam['shuffle'] = boolish($exam['shuffle']);
        $exam['isDraft'] = boolish($exam['isDraft']);
        $exam['questions'] = self::examQuestions($exam['id']);
        return $exam;
    }

    private static function examQuestions(int $examId): array
    {
        $rows = Db::all('SELECT * FROM exam_questions WHERE "examId" = ? ORDER BY "sortOrder", id', [$examId]);
        $out = [];
        foreach ($rows as $q) {
            $opts = Db::all('SELECT * FROM exam_options WHERE "questionId" = ? ORDER BY "sortOrder", id', [(int) $q['id']]);
            $q['id'] = (int) $q['id'];
            $q['options'] = array_map(static fn ($o) => [
                'id' => (int) $o['id'],
                'label' => (string) $o['label'],
                'isCorrect' => boolish($o['isCorrect']),
            ], $opts);
            $out[] = $q;
        }
        return $out;
    }

    private static function examPrepareAttempt(array $questions, bool $shuffle, int $seed): array
    {
        $ordered = $shuffle ? exam_shuffle($questions, $seed) : array_values($questions);
        $out = [];
        foreach ($ordered as $index => $q) {
            $opts = $q['options'];
            if ($shuffle) {
                $opts = exam_shuffle($opts, $seed + $index + 1);
            }
            $out[] = [
                'id' => (int) $q['id'],
                'prompt' => (string) $q['prompt'],
                'kind' => (string) $q['kind'],
                'options' => array_map(static fn ($o) => [
                    'id' => (int) $o['id'],
                    'label' => (string) $o['label'],
                ], $opts),
            ];
        }
        return $out;
    }

    private static function examMyProgress(int $examId, string $userId): array
    {
        $attempts = Db::all(
            'SELECT * FROM exam_attempts WHERE "examId" = ? AND "userId" = ? ORDER BY "startedAt" DESC, id DESC',
            [$examId, $userId],
        );
        $open = null;
        $finished = [];
        foreach ($attempts as $a) {
            if ($a['status'] === 'in_progress') {
                $open = $a;
            } else {
                $finished[] = $a;
            }
        }
        $best = null;
        foreach ($finished as $a) {
            $score = $a['scorePercent'] === null || $a['scorePercent'] === '' ? -1 : (int) $a['scorePercent'];
            $bestScore = $best === null || $best['scorePercent'] === null || $best['scorePercent'] === ''
                ? -1
                : (int) $best['scorePercent'];
            if ($best === null || $score > $bestScore) {
                $best = $a;
            }
        }
        return [
            'openAttemptId' => $open ? (int) $open['id'] : null,
            'attemptCount' => count($finished),
            'bestScore' => ($best && $best['scorePercent'] !== null && $best['scorePercent'] !== '') ? (int) $best['scorePercent'] : null,
            'status' => $best['status'] ?? null,
            'bestAttemptId' => $best ? (int) $best['id'] : null,
        ];
    }

    private static function examXpReward(array $exam): int
    {
        $n = (int) ($exam['xpReward'] ?? 0);
        return $n > 0 ? $n : 100;
    }

    private static function examCatalog(array $user): array
    {
        $rows = Db::all('SELECT * FROM exams ORDER BY "sortOrder", id');
        $out = [];
        foreach ($rows as $e) {
            if (!$user['isOwner'] && boolish($e['isDraft'])) {
                continue;
            }
            $e['id'] = (int) $e['id'];
            $e['xpReward'] = self::examXpReward($e);
            $e['isDraft'] = boolish($e['isDraft']);
            $e['qCount'] = (int) (Db::one('SELECT COUNT(*) c FROM exam_questions WHERE "examId"=?', [$e['id']])['c'] ?? 0);
            $e['progress'] = self::examMyProgress($e['id'], $user['id']);
            $out[] = $e;
        }
        return $out;
    }

    private static function examXpState(array $user): array
    {
        $catalog = self::examCatalog($user);
        $published = [];
        foreach ($catalog as $e) {
            if (!$e['isDraft']) {
                $published[] = $e;
            }
        }
        $total = 0;
        $earned = 0;
        $passedN = 0;
        $thresholds = [0];
        foreach ($published as $e) {
            $total += $e['xpReward'];
            $thresholds[] = $total;
            if (($e['progress']['status'] ?? '') === 'passed') {
                $earned += $e['xpReward'];
                $passedN++;
            }
        }
        $maxLevel = max(1, count($published) + 1);
        $level = 1;
        foreach ($thresholds as $i => $need) {
            if ($earned >= $need) {
                $level = $i + 1;
            }
        }
        $level = min($level, $maxLevel);
        if ($level >= $maxLevel) {
            $toNext = 0;
            $bar = 100;
        } else {
            $prevAt = $thresholds[$level - 1] ?? 0;
            $nextAt = $thresholds[$level] ?? $total;
            $span = max(1, $nextAt - $prevAt);
            $toNext = max(0, $nextAt - $earned);
            $bar = (int) round((($earned - $prevAt) / $span) * 100);
        }
        $course = $total > 0 ? (int) round($earned / $total * 100) : 0;
        return [
            'catalog' => $catalog,
            'earned' => $earned,
            'total' => $total,
            'level' => $level,
            'maxLevel' => $maxLevel,
            'toNext' => $toNext,
            'bar' => max(0, min(100, $bar)),
            'course' => $course,
            'passedN' => $passedN,
        ];
    }

    private static function examRail(array $user, string $activeSlug = ''): string
    {
        $xp = self::examXpState($user);
        $html = '<div class="gp-exam-xp"><div class="gp-exam-xp-top"><div class="gp-exam-xp-lvl">Уровень<b>' . (int) $xp['level'] . '</b></div>';
        $html .= '<div class="gp-exam-xp-num">XP<b>' . (int) $xp['earned'] . '</b></div></div>';
        $html .= '<div class="gp-exam-xp-bar" aria-hidden="true"><span style="width:' . (int) $xp['bar'] . '%"></span></div>';
        if ($xp['level'] >= $xp['maxLevel'] && $xp['total'] > 0) {
            $meta = 'Максимум · курс ' . (int) $xp['course'] . '%';
        } elseif ($xp['total'] < 1) {
            $meta = 'Пока нет опубликованных экзаменов';
        } else {
            $meta = (int) $xp['toNext'] . ' XP до уровня ' . ((int) $xp['level'] + 1) . ' · курс ' . (int) $xp['course'] . '%';
        }
        $html .= '<p class="gp-exam-xp-meta">' . h($meta) . '</p></div>';
        $html .= '<div class="gp-exam-chapters">';
        $n = 0;
        $icons = ['book', 'clipboard-list', 'grad', 'tags', 'scale', 'clipboard-check'];
        foreach ($xp['catalog'] as $e) {
            $n++;
            $progress = $e['progress'];
            if ($e['isDraft']) {
                $st = 'Черновик';
            } elseif (($progress['status'] ?? '') === 'passed') {
                $st = 'Готово';
            } else {
                $st = 'Открыт';
            }
            $on = $activeSlug !== '' && $activeSlug === (string) $e['slug'];
            $cls = 'gp-exam-ch';
            if ($on) {
                $cls .= ' is-on';
            }
            if ($st === 'Готово') {
                $cls .= ' is-done';
            }
            if ($e['isDraft']) {
                $cls .= ' is-draft';
            }
            $ico = $icons[($n - 1) % count($icons)];
            $html .= '<a class="' . $cls . '" href="' . h(url_to('/exams/' . rawurlencode($e['slug']))) . '">';
            $html .= '<span class="gp-exam-ch-ico">' . icon($ico, 'size-4') . '</span>';
            $html .= '<span class="min-w-0 flex-1"><span class="gp-exam-ch-title">' . h($e['title']) . '</span>';
            $html .= '<span class="gp-exam-ch-sub">' . h(self::examPad($n)) . ' · ' . h(plural($e['qCount'], 'вопрос', 'вопроса', 'вопросов'));
            if ($e['isDraft']) {
                $html .= ' · черновик';
            }
            $html .= '</span></span>';
            $html .= '<span class="gp-exam-ch-st"><span>' . h($st) . '</span>';
            $html .= $st === 'Готово' ? icon('check', 'size-3.5') : icon('chevronright', 'size-3.5');
            $html .= '</span></a>';
        }
        if (!$xp['catalog']) {
            $html .= '<p class="gp-exam-xp-meta px-1">Экзаменов пока нет</p>';
        }
        $html .= '</div>';
        $html .= '<a class="gp-exam-to-all" href="' . h(url_to('/exams')) . '">К экзаменам</a>';
        return $html;
    }

    private static function examPad(int $n): string
    {
        return str_pad((string) max(0, $n), 2, '0', STR_PAD_LEFT);
    }

    private static function examFrame(string $kicker, string $num, string $body, string $nav = '', string $extra = ''): string
    {
        $kicker = mb_strtoupper(trim($kicker), 'UTF-8');
        $html = '<article class="gp-exam"' . ($extra !== '' ? ' ' . $extra : '') . '>';
        $html .= '<header class="gp-exam-hero">';
        $html .= '<svg class="gp-exam-mark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.15" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.42 10.922a1 1 0 0 0-.019-1.838L12.83 5.18a2 2 0 0 0-1.66 0L2.6 9.08a1 1 0 0 0 0 1.832l8.57 3.908a2 2 0 0 0 1.66 0z"/><path d="M22 10v6"/><path d="M6 12.5V16a6 3 0 0 0 12 0v-3.5"/></svg>';
        $html .= '<p class="gp-exam-kicker" data-exam-kicker>' . h($kicker) . '</p>';
        $html .= '<div class="gp-exam-num" data-exam-num>' . h($num) . '</div></header>';
        $html .= '<div class="gp-exam-body">' . $body . '</div>';
        if ($nav !== '') {
            $html .= '<footer class="gp-exam-nav">' . $nav . '</footer>';
        }
        return $html . '</article>';
    }

    private static function examPage(array $user, string $inner, string $activeSlug = ''): string
    {
        return '<main class="gp-exam-wrap"><aside class="gp-exam-rail">' . self::examRail($user, $activeSlug) . '</aside><div class="gp-exam-stage">' . $inner . '</div></main>';
    }

    private static function examBackLink(): string
    {
        return '<a href="' . h(url_to('/exams')) . '" class="gp-exam-back">Назад</a>';
    }

    private static function examDeleteForm(string $slug): string
    {
        $html = '<form method="post" class="m-0" onsubmit="return confirm(\'Удалить экзамен вместе со всеми результатами?\');">';
        $html .= csrf_field();
        $html .= '<input type="hidden" name="_action" value="delete_exam"><input type="hidden" name="slug" value="' . h($slug) . '">';
        $html .= '<button class="gp-exam-del" type="submit">' . icon('trash', 'size-4') . ' Удалить экзамен</button></form>';
        return $html;
    }

    private static function examEditorQuestionHtml(int $index, array $question): string
    {
        $kind = $question['kind'] ?? 'single';
        if (!isset(EXAM_KIND_LABELS[$kind])) {
            $kind = 'single';
        }
        $prompt = (string) ($question['prompt'] ?? '');
        $explanation = (string) ($question['explanation'] ?? '');
        $options = $question['options'] ?? [
            ['label' => '', 'isCorrect' => false],
            ['label' => '', 'isCorrect' => false],
        ];
        if (count($options) < 2) {
            $options[] = ['label' => '', 'isCorrect' => false];
        }
        $html = '<li class="border-border bg-card flex flex-col gap-3 rounded-xl border p-4" data-question>';
        $html .= '<div class="flex items-start gap-2"><span class="text-muted-foreground mt-2.5 w-5 shrink-0 text-sm tabular-nums" data-q-num>' . ($index + 1) . '.</span>';
        $html .= '<textarea name="questions[' . $index . '][prompt]" data-prompt rows="2" maxlength="600" placeholder="Текст вопроса" class="border-input bg-background focus-visible:ring-ring w-full resize-y rounded-lg border px-3 py-2 text-sm leading-relaxed outline-none focus-visible:ring-2">' . h($prompt) . '</textarea>';
        $html .= '<button type="button" class="' . btn_class('ghost', 'icon') . ' text-muted-foreground hover:text-destructive mt-1" data-q-remove aria-label="Удалить вопрос">' . icon('trash', 'size-4') . '</button></div>';
        $html .= '<div class="flex flex-wrap items-center gap-2 pl-7"><select name="questions[' . $index . '][kind]" data-kind class="' . input_class('h-9 w-full sm:w-56') . '">';
        foreach (EXAM_KIND_LABELS as $k => $label) {
            $sel = $k === $kind ? ' selected' : '';
            $html .= '<option value="' . h($k) . '"' . $sel . '>' . h($label) . '</option>';
        }
        $html .= '</select></div><div class="flex flex-col gap-2 pl-7" data-options>';
        foreach ($options as $j => $opt) {
            $label = (string) ($opt['label'] ?? '');
            $correct = !empty($opt['isCorrect']);
            $boolLock = $kind === 'boolean' ? ' readonly' : '';
            $html .= '<div class="flex items-center gap-2" data-option>';
            if ($kind === 'multiple') {
                $html .= '<input type="checkbox" class="accent-primary size-4 shrink-0" data-correct name="questions[' . $index . '][options][' . $j . '][correct]" value="1"' . ($correct ? ' checked' : '') . ' aria-label="Верный вариант">';
            } else {
                $html .= '<input type="radio" class="accent-primary size-4 shrink-0" data-correct name="questions[' . $index . '][correct]" value="' . $j . '"' . ($correct ? ' checked' : '') . ' aria-label="Верный вариант">';
            }
            $html .= '<input data-opt-label name="questions[' . $index . '][options][' . $j . '][label]" class="' . input_class('h-9') . '" maxlength="300" placeholder="Вариант ' . ($j + 1) . '" value="' . h($label) . '"' . $boolLock . '>';
            $html .= '<button type="button" class="' . btn_class('ghost', 'icon') . ' text-muted-foreground hover:text-destructive' . ($kind === 'boolean' || count($options) <= 2 ? ' hidden' : '') . '" data-opt-remove aria-label="Удалить вариант">' . icon('trash', 'size-4') . '</button></div>';
        }
        $html .= '</div><button type="button" class="' . btn_class('ghost', 'sm') . ' text-muted-foreground w-fit gap-1.5 ml-7' . ($kind === 'boolean' ? ' hidden' : '') . '" data-opt-add>' . icon('plus', 'size-4') . ' Вариант</button>';
        $html .= '<div class="pl-7"><input data-expl name="questions[' . $index . '][explanation]" class="' . input_class('h-9') . '" maxlength="1000" placeholder="Пояснение — покажется в разборе после сдачи (необязательно)" value="' . h($explanation) . '"></div></li>';
        return $html;
    }

    private static function pageExams(array $user): void
    {
        $catalog = self::examCatalog($user);
        $xp = self::examXpState($user);
        $body = '<p>За сдачу экзамена начисляется XP. Уровень считается по сумме XP опубликованных тестов — новый экзамен поднимает потолок.</p>';
        $body .= '<p>Уровень <strong>' . (int) $xp['level'] . '</strong>, XP <strong>' . (int) $xp['earned'] . '</strong> из ' . (int) $xp['total'] . '. Сдано <strong>' . (int) $xp['passedN'] . '</strong>.</p>';
        if ($user['isOwner']) {
            $body .= '<form method="post" class="mt-6 flex flex-col gap-3">' . csrf_field();
            $body .= '<input type="hidden" name="_action" value="create_exam">';
            $body .= '<input name="title" class="' . input_class() . '" maxlength="140" placeholder="Название экзамена" required>';
            $body .= '<button class="gp-exam-next w-fit" type="submit">' . icon('plus', 'size-4') . ' Создать</button></form>';
        }
        $nav = $user['isOwner']
            ? '<span></span><a class="gp-exam-next" href="' . h(url_to('/manage/exams')) . '">Проверка</a>'
            : '';
        $html = self::examPage($user, self::examFrame('Экзамены', self::examPad(count($catalog)), $body, $nav));
        self::layout($user, $html);
    }

    private static function pageExamStart(array $user, string $slug): void
    {
        $exam = self::examBySlug($slug, $user['isOwner']);
        if (!$exam) {
            http_response_code(404);
            self::layout($user, self::examPage($user, '<p class="text-muted-foreground text-sm">Экзамен не найден.</p>'));
            return;
        }
        $progress = self::examMyProgress($exam['id'], $user['id']);
        $qCount = count($exam['questions']);
        $label = $progress['openAttemptId'] ? 'Продолжить' : ($progress['attemptCount'] > 0 ? 'Пересдать' : 'Дальше');
        $body = '';
        if ($exam['intro']) {
            $body .= '<p>' . nl2br(h($exam['intro']), false) . '</p>';
        } else {
            $body .= '<p>Тест с вариантами ответов. После сдачи сразу видно процент и разбор каждого вопроса.</p>';
        }
        $body .= '<p>Вопросов: <strong>' . $qCount . '</strong>. Проходной балл: <strong>' . $exam['passPercent'] . '%</strong>.';
        if ($progress['bestScore'] !== null) {
            $body .= ' Лучший результат: <strong>' . $progress['bestScore'] . '%</strong>.';
        }
        $body .= '</p>';
        if (($progress['status'] ?? '') === 'passed') {
            $body .= '<p>XP за этот экзамен уже в зачёте: <strong>' . (int) $exam['xpReward'] . '</strong>.</p>';
        } else {
            $body .= '<p>За сдачу начислим <strong>' . (int) $exam['xpReward'] . ' XP</strong>.</p>';
        }
        if ($exam['shuffle']) {
            $body .= '<p>Вопросы и варианты показываются в случайном порядке.</p>';
        }
        $body .= '<p>Пересдавать можно сколько нужно — в зачёт идёт лучший результат.</p>';
        if ($exam['isDraft']) {
            $body .= '<p>Черновик — виден только владельцу.</p>';
        }
        $nav = self::examBackLink();
        if ($qCount === 0) {
            if ($user['isOwner']) {
                $nav .= '<a class="gp-exam-next" href="' . h(url_to('/exams/' . rawurlencode($slug) . '/edit')) . '">Добавить вопросы</a>';
            } else {
                $nav .= '<span></span>';
            }
        } else {
            $nav .= '<form method="post" class="m-0">' . csrf_field() . '<input type="hidden" name="_action" value="start_attempt"><input type="hidden" name="slug" value="' . h($slug) . '">';
            $nav .= '<button class="gp-exam-next">' . h($label) . '</button></form>';
        }
        if ($user['isOwner']) {
            $body .= '<div class="gp-exam-owner"><a class="text-primary font-semibold" href="' . h(url_to('/exams/' . rawurlencode($slug) . '/edit')) . '">Редактировать</a>';
            $body .= self::examDeleteForm($slug) . '</div>';
            if ($progress['bestAttemptId']) {
                $body .= '<p><a class="text-primary font-semibold" href="' . h(url_to('/exams/' . rawurlencode($slug) . '/result/' . $progress['bestAttemptId'])) . '">Мой результат</a></p>';
            }
        } elseif ($progress['bestAttemptId']) {
            $body .= '<p><a class="text-primary font-semibold" href="' . h(url_to('/exams/' . rawurlencode($slug) . '/result/' . $progress['bestAttemptId'])) . '">Открыть лучший результат</a></p>';
        }
        $html = self::examPage($user, self::examFrame($exam['title'], self::examPad(max(1, $qCount)), $body, $nav), $slug);
        self::layout($user, $html);
    }

    private static function pageExamEdit(array $user, string $slug): void
    {
        $exam = self::examBySlug($slug, true);
        if (!$exam) {
            http_response_code(404);
            self::layout($user, self::examPage($user, '<p class="text-muted-foreground text-sm">Экзамен не найден.</p>'));
            return;
        }
        $stash = form_stash_take('exam-edit:' . $slug);
        if ($stash) {
            $exam['title'] = (string) ($stash['title'] ?? $exam['title']);
            $exam['intro'] = (string) ($stash['intro'] ?? $exam['intro']);
            $exam['passPercent'] = $stash['passPercent'] ?? $exam['passPercent'];
            $exam['xpReward'] = $stash['xpReward'] ?? $exam['xpReward'];
            $exam['shuffle'] = !empty($stash['shuffle']);
            $exam['isDraft'] = !empty($stash['isDraft']);
            if (isset($stash['questions']) && is_array($stash['questions'])) {
                $exam['questions'] = $stash['questions'];
            }
        }
        $inner = '<form id="exam-save" method="post" class="flex flex-col gap-6" data-exam-editor novalidate>' . csrf_field();
        $inner .= '<input type="hidden" name="_action" value="save_exam"><input type="hidden" name="slug" value="' . h($slug) . '">';
        $inner .= '<section class="flex flex-col gap-4"><div class="flex flex-col gap-1.5"><label class="text-sm font-medium" for="exam-title">Название</label>';
        $inner .= '<input id="exam-title" name="title" class="' . input_class() . '" maxlength="140" value="' . h($exam['title']) . '" required></div>';
        $inner .= '<div class="flex flex-col gap-1.5"><label class="text-sm font-medium" for="exam-intro">Вступление</label>';
        $inner .= '<textarea id="exam-intro" name="intro" rows="3" maxlength="2000" placeholder="Что проверяет экзамен и к чему готовиться" class="border-input bg-background focus-visible:ring-ring w-full resize-y rounded-lg border px-3 py-2.5 text-sm leading-relaxed outline-none focus-visible:ring-2">' . h($exam['intro']) . '</textarea></div>';
        $inner .= '<div class="grid gap-4 sm:grid-cols-2"><div class="flex flex-col gap-1.5"><label class="text-sm font-medium" for="exam-pass">Проходной балл, %</label>';
        $inner .= '<input id="exam-pass" name="passPercent" type="number" min="1" max="100" class="' . input_class() . '" value="' . h((string) $exam['passPercent']) . '"></div>';
        $inner .= '<div class="flex flex-col gap-1.5"><label class="text-sm font-medium" for="exam-xp">XP за сдачу</label>';
        $inner .= '<input id="exam-xp" name="xpReward" type="number" min="1" max="5000" class="' . input_class() . '" value="' . h((string) ($exam['xpReward'] ?? 100)) . '"></div>';
        $inner .= '<div class="flex flex-col justify-end gap-2.5 sm:col-span-2">';
        $inner .= '<label class="flex items-center gap-2 text-sm"><input type="checkbox" name="shuffle" value="1" class="accent-primary size-4"' . ($exam['shuffle'] ? ' checked' : '') . '> Перемешивать вопросы и варианты</label>';
        $inner .= '<label class="flex items-center gap-2 text-sm"><input type="checkbox" name="isDraft" value="1" class="accent-primary size-4"' . ($exam['isDraft'] ? ' checked' : '') . '> Черновик — сотрудники не увидят</label>';
        $inner .= '</div></div></section>';
        $inner .= '<section class="flex flex-col gap-4"><h2 class="text-xs font-semibold uppercase tracking-wider text-[#9a9a9a]">Вопросы, <span data-q-count>' . count($exam['questions']) . '</span></h2>';
        $inner .= '<ol class="flex flex-col gap-4" data-questions>';
        $toEdit = $exam['questions'];
        if (!$toEdit) {
            $toEdit = [[
                'prompt' => '',
                'kind' => 'single',
                'explanation' => '',
                'options' => [
                    ['label' => '', 'isCorrect' => false],
                    ['label' => '', 'isCorrect' => false],
                ],
            ]];
        }
        foreach ($toEdit as $i => $q) {
            $inner .= self::examEditorQuestionHtml($i, $q);
        }
        $inner .= '</ol>';
        $inner .= '<template data-q-tpl>' . self::examEditorQuestionHtml(0, [
            'prompt' => '',
            'kind' => 'single',
            'explanation' => '',
            'options' => [
                ['label' => '', 'isCorrect' => false],
                ['label' => '', 'isCorrect' => false],
            ],
        ]) . '</template>';
        $inner .= '<button type="button" class="gp-exam-back w-fit" data-add-question>' . icon('plus', 'size-4') . ' Добавить вопрос</button></section></form>';
        $nav = '<a href="' . h(url_to('/exams/' . rawurlencode($slug))) . '" class="gp-exam-back">Назад</a>';
        $nav .= '<span class="gp-exam-nav-actions">' . self::examDeleteForm($slug);
        $nav .= '<button class="gp-exam-next" type="submit" form="exam-save">Сохранить</button></span>';
        self::layout($user, self::examPage($user, self::examFrame($exam['title'], self::examPad(max(1, count($exam['questions']))), $inner, $nav), $slug));
    }

    private static function pageExamAttempt(array $user, string $slug, int $attemptId): void
    {
        $attempt = Db::one('SELECT * FROM exam_attempts WHERE id = ?', [$attemptId]);
        if (!$attempt || $attempt['userId'] !== $user['id']) {
            http_response_code(404);
            self::layout($user, self::examPage($user, '<p class="text-muted-foreground text-sm">Попытка не найдена.</p>'));
            return;
        }
        if ($attempt['status'] !== 'in_progress') {
            redirect_to('/exams/' . rawurlencode($slug) . '/result/' . $attemptId);
        }
        $exam = self::examBySlug($slug, $user['isOwner']);
        if (!$exam || $exam['id'] !== (int) $attempt['examId']) {
            http_response_code(404);
            self::layout($user, self::examPage($user, '<p class="text-muted-foreground text-sm">Экзамен не найден.</p>'));
            return;
        }
        $questions = self::examPrepareAttempt($exam['questions'], $exam['shuffle'], $attemptId);
        $exit = url_to('/exams/' . rawurlencode($slug));
        $body = '<form id="exam-take" method="post">' . csrf_field();
        $body .= '<input type="hidden" name="_action" value="submit_attempt"><input type="hidden" name="slug" value="' . h($slug) . '"><input type="hidden" name="attemptId" value="' . $attemptId . '">';
        foreach ($questions as $i => $q) {
            $n = $i + 1;
            $body .= '<fieldset class="gp-exam-step' . ($i === 0 ? ' is-on' : '') . '" data-exam-step="' . $n . '">';
            $body .= '<p>' . h($q['prompt']) . '</p>';
            if ($q['kind'] === 'multiple') {
                $body .= '<p>Отметьте все верные варианты.</p>';
            }
            foreach ($q['options'] as $opt) {
                $name = $q['kind'] === 'multiple' ? 'a[' . $q['id'] . '][]' : 'a[' . $q['id'] . ']';
                $type = $q['kind'] === 'multiple' ? 'checkbox' : 'radio';
                $body .= '<label class="gp-exam-opt"><input type="' . $type . '" class="accent-primary size-4 shrink-0" name="' . h($name) . '" value="' . (int) $opt['id'] . '">';
                $body .= '<span>' . h($opt['label']) . '</span></label>';
            }
            $body .= '</fieldset>';
        }
        $body .= '</form>';
        $nav = '<button type="button" class="gp-exam-back" data-exam-prev>Назад</button>';
        $nav .= '<button type="button" class="gp-exam-next" data-exam-next>Дальше</button>';
        $nav .= '<button type="submit" form="exam-take" class="gp-exam-next hidden" data-exam-submit>Сдать</button>';
        self::layout($user, self::examPage($user, self::examFrame($exam['title'], self::examPad(1), $body, $nav, 'data-exam-take data-exam-exit="' . h($exit) . '"'), $slug));
    }

    private static function pageExamResult(array $user, string $slug, int $attemptId): void
    {
        $attempt = Db::one('SELECT * FROM exam_attempts WHERE id = ?', [$attemptId]);
        if (!$attempt) {
            http_response_code(404);
            self::layout($user, '<main class="mx-auto max-w-md px-4 py-16"><p class="text-muted-foreground text-sm">Попытка не найдена.</p></main>');
            return;
        }
        $exam = self::examBySlug($slug, $user['isOwner']);
        if (!$exam || $exam['id'] !== (int) $attempt['examId']) {
            http_response_code(404);
            self::layout($user, '<main class="mx-auto max-w-md px-4 py-16"><p class="text-muted-foreground text-sm">Экзамен не найден.</p></main>');
            return;
        }
        if ($attempt['userId'] !== $user['id'] && !$user['isOwner']) {
            http_response_code(404);
            self::layout($user, '<main class="mx-auto max-w-md px-4 py-16"><p class="text-muted-foreground text-sm">Попытка не найдена.</p></main>');
            return;
        }
        if ($attempt['status'] === 'in_progress') {
            redirect_to('/exams/' . rawurlencode($slug) . '/attempt/' . $attemptId);
        }
        $answers = Db::all('SELECT * FROM exam_answers WHERE "attemptId"=?', [$attemptId]);
        $byQ = [];
        foreach ($answers as $a) {
            $byQ[(int) $a['questionId']] = $a;
        }
        $status = (string) $attempt['status'];
        $banner = match ($status) {
            'passed' => ['title' => 'Экзамен сдан', 'cls' => 'bg-shift-full text-shift-full-foreground'],
            'pending_review' => ['title' => 'Ждёт проверки', 'cls' => 'bg-shift-day text-shift-day-foreground'],
            default => ['title' => 'Экзамен не сдан', 'cls' => 'bg-destructive/15 text-destructive'],
        };
        $ownName = null;
        if ($attempt['userId'] !== $user['id']) {
            $u = Db::one('SELECT name FROM "user" WHERE id=?', [$attempt['userId']]);
            $ownName = $u['name'] ?? 'Сотрудник';
        }
        $score = $attempt['scorePercent'];
        $earned = (int) $attempt['earnedPoints'];
        $max = (int) $attempt['maxPoints'];
        $num = $status === 'pending_review' ? '—' : self::examPad((int) $score);
        $body = '<p><strong>' . h($banner['title']) . '</strong>';
        if ($ownName) {
            $body .= ' · ' . h($ownName);
        }
        $body .= '</p>';
        if ($status === 'pending_review') {
            $body .= '<p>Открытые ответы проверяет владелец.</p>';
        } else {
            $body .= '<p>' . (int) $score . '%, ' . $earned . ' из ' . $max . ', проходной ' . $exam['passPercent'] . '%.</p>';
        }
        foreach ($exam['questions'] as $i => $q) {
            $ans = $byQ[(int) $q['id']] ?? null;
            $selected = exam_parse_selected((string) ($ans['selectedOptionIds'] ?? ''));
            $isCorrect = $ans === null ? null : ($ans['isCorrect'] === null || $ans['isCorrect'] === '' ? null : boolish($ans['isCorrect']));
            if ($isCorrect === true) {
                $mark = '<span class="gp-exam-mark-ok">Верно</span>';
            } elseif ($isCorrect === false) {
                $mark = '<span class="gp-exam-mark-bad">Неверно</span>';
            } else {
                $mark = '<span class="gp-exam-mark-wait">Ждёт проверки</span>';
            }
            $body .= '<p class="mt-5 font-semibold">' . ($i + 1) . '. ' . h($q['prompt']) . ' ' . $mark . '</p>';
            $body .= '<div class="gp-exam-ans-list">';
            foreach ($q['options'] as $opt) {
                $chosen = in_array((int) $opt['id'], $selected, true);
                $cls = 'gp-exam-ans';
                $note = '';
                if ($opt['isCorrect']) {
                    $cls .= ' is-ok';
                    if ($chosen) {
                        $note = $ownName ? 'верный ответ, выбран' : 'верный ответ, ваш выбор';
                    } else {
                        $note = 'верный ответ';
                    }
                } elseif ($chosen) {
                    $cls .= ' is-bad';
                    $note = $ownName ? 'выбран' : 'ваш выбор';
                }
                $body .= '<div class="' . $cls . '">' . h($opt['label']);
                if ($note !== '') {
                    $body .= '<span class="gp-exam-ans-note">' . h($note) . '</span>';
                }
                $body .= '</div>';
            }
            $body .= '</div>';
            if (!empty($q['explanation'])) {
                $body .= '<p class="text-sm text-[#9a9a9a]">' . h($q['explanation']) . '</p>';
            }
        }
        if ($ownName) {
            $nav = '<a href="' . h(url_to('/manage/exams')) . '" class="gp-exam-back">К проверке</a>';
            $nav .= '<a class="gp-exam-next" href="' . h(url_to('/exams')) . '">К экзаменам</a>';
        } else {
            $nav = '<a href="' . h(url_to('/exams/' . rawurlencode($slug))) . '" class="gp-exam-back">Назад</a>';
            $nav .= '<a class="gp-exam-next" href="' . h(url_to('/exams')) . '">К экзаменам</a>';
        }
        self::layout($user, self::examPage($user, self::examFrame($exam['title'], $num, $body, $nav), $slug));
    }

    private static function pageAccount(array $user): void
    {
        $html = '<main class="mx-auto max-w-lg px-4 py-8"><h1 class="text-xl font-semibold">Мой профиль</h1>';
        $html .= '<div class="border-border bg-card mt-4 rounded-xl border p-4"><p class="font-medium">' . h($user['employeeName'] ?? $user['name']) . '</p><p class="text-muted-foreground text-sm">Логин: ' . h($user['login']) . '</p><p class="text-muted-foreground text-sm">' . ($user['isOwner'] ? 'Владелец' : 'Администратор') . '</p></div>';
        $mine = self::assignedFines($user, false);
        if ($mine || empty($user['isOwner'])) {
            $html .= '<div class="mt-6">' . self::htmlAssignedFines($mine, false, 'Мои штрафы', 'За что выписали и сколько.') . '</div>';
        }
        $html .= '<form method="post" class="mt-6 flex flex-col gap-3">' . csrf_field() . '<input type="hidden" name="_action" value="change_password">';
        $html .= '<h2 class="font-semibold">Сменить пароль</h2>';
        $html .= '<input type="password" name="current" class="' . input_class() . '" placeholder="Текущий пароль" required>';
        $html .= '<input type="password" name="next" class="' . input_class() . '" placeholder="Новый пароль" required>';
        $html .= '<button class="' . btn_class() . '">Сохранить пароль</button></form></main>';
        self::layout($user, $html);
    }

    private static function pageJournal(array $user): void
    {
        $handovers = Db::all(
            'SELECT r.*, b.name AS "branchName" FROM shift_reports r JOIN branches b ON b.id=r."branchId" ORDER BY r."createdAt" DESC LIMIT 40',
        );
        $incidents = Db::all(
            'SELECT i.*, b.name AS "branchName" FROM incidents i JOIN branches b ON b.id=i."branchId" ORDER BY i."createdAt" DESC LIMIT 40',
        );
        $tasks = Db::all(
            'SELECT d."completedAt", d."userName", t.title, t.date, e.name AS "employeeName", b.name AS "branchName"
             FROM staff_task_done d
             JOIN staff_tasks t ON t.id = d."taskId"
             JOIN employees e ON e.id = t."employeeId"
             LEFT JOIN branches b ON b.id = e."homeBranchId"
             ORDER BY d."completedAt" DESC LIMIT 40',
        );
        $cleans = Db::all(
            'SELECT k.*, b.name AS "branchName" FROM keyboard_cleanings k JOIN branches b ON b.id=k."branchId" ORDER BY k."doneAt" DESC LIMIT 40',
        );
        $html = '<main class="mx-auto max-w-5xl px-4 py-6 sm:px-6"><h1 class="text-xl font-semibold">Журнал</h1><div class="mt-4 flex flex-wrap gap-2" data-tabs>';
        $tabs = [
            'handovers' => ['Касса', 'sky'],
            'incidents' => ['Заявки', 'amber'],
            'tasks' => ['Задания', 'violet'],
            'cleanings' => ['Чистка', 'emerald'],
        ];
        $i = 0;
        foreach ($tabs as $id => $t) {
            $html .= '<button type="button" class="rounded-lg border px-3 py-1.5 text-sm ' . ($i === 0 ? 'border-' . $t[1] . '-500 bg-' . $t[1] . '-500/10' : 'border-border') . '" data-tab="' . $id . '">' . $t[0] . '</button>';
            $i++;
        }
        $html .= '</div>';
        $html .= '<div data-tab-panel="handovers" class="mt-4 overflow-x-auto"><table class="gp-cards w-full text-sm"><thead><tr class="bg-muted/60 text-left text-xs uppercase"><th class="px-3 py-2">Дата</th><th class="px-3 py-2">Филиал</th><th class="px-3 py-2">Кто</th><th class="px-3 py-2">Фаза</th><th class="px-3 py-2">Касса</th></tr></thead><tbody>';
        foreach ($handovers as $r) {
            $diff = ($r['cashActual'] !== null && $r['cashExpected'] !== null) ? ((int) $r['cashActual'] - (int) $r['cashExpected']) : null;
            $html .= '<tr class="border-t border-border">'
                . td_th('Дата', h($r['date']))
                . td_th('Филиал', h($r['branchName']))
                . td_th('Кто', h($r['userName']))
                . td_th('Фаза', $r['phase'] === 'open' ? 'Приём' : 'Сдача')
                . td_th('Касса', $diff === null ? '—' : ($diff . ' ₽'))
                . '</tr>';
        }
        $html .= '</tbody></table></div>';
        $html .= '<div data-tab-panel="incidents" class="mt-4 hidden overflow-x-auto"><table class="gp-cards w-full text-sm"><thead><tr class="bg-muted/60 text-left text-xs uppercase"><th class="px-3 py-2">Когда</th><th class="px-3 py-2">Филиал</th><th class="px-3 py-2">Тема</th><th class="px-3 py-2">Статус</th></tr></thead><tbody>';
        foreach ($incidents as $r) {
            $html .= '<tr class="border-t border-border">'
                . td_th('Когда', h(substr((string) $r['createdAt'], 0, 16)))
                . td_th('Филиал', h($r['branchName']))
                . td_th('Тема', h($r['title']))
                . td_th('Статус', h(INCIDENT_STATUS[$r['status']] ?? $r['status']))
                . '</tr>';
        }
        $html .= '</tbody></table></div>';
        $html .= '<div data-tab-panel="tasks" class="mt-4 hidden overflow-x-auto"><table class="gp-cards w-full text-sm"><thead><tr class="bg-muted/60 text-left text-xs uppercase"><th class="px-3 py-2">Дата</th><th class="px-3 py-2">Филиал</th><th class="px-3 py-2">Задание</th><th class="px-3 py-2">Кто</th></tr></thead><tbody>';
        foreach ($tasks as $r) {
            $html .= '<tr class="border-t border-border">'
                . td_th('Дата', h($r['date']))
                . td_th('Филиал', h((string) ($r['branchName'] ?? '')))
                . td_th('Задание', h($r['title']))
                . td_th('Кто', h($r['userName']))
                . '</tr>';
        }
        $html .= '</tbody></table></div>';
        $html .= '<div data-tab-panel="cleanings" class="mt-4 hidden overflow-x-auto"><table class="gp-cards w-full text-sm"><thead><tr class="bg-muted/60 text-left text-xs uppercase"><th class="px-3 py-2">Неделя</th><th class="px-3 py-2">Филиал</th><th class="px-3 py-2">ПК</th><th class="px-3 py-2">Кто</th></tr></thead><tbody>';
        foreach ($cleans as $r) {
            $html .= '<tr class="border-t border-border">'
                . td_th('Неделя', h($r['weekStart']))
                . td_th('Филиал', h($r['branchName']))
                . td_th('ПК', (string) (int) $r['pcNumber'])
                . td_th('Кто', h($r['doneByName']))
                . '</tr>';
        }
        $html .= '</tbody></table></div></main>';
        self::layout($user, $html);
    }

    private static function pageEmployees(array $user): void
    {
        $emps = Db::all('SELECT e.*, b.name AS "branchName", g.title AS "gradeTitle" FROM employees e JOIN branches b ON b.id=e."homeBranchId" LEFT JOIN employee_grades g ON g.id=e."gradeId" WHERE e."isActive"=1 ORDER BY b."sortOrder", e."sortOrder"');
        $branches = self::branches();
        $grades = Db::all('SELECT * FROM employee_grades ORDER BY "sortOrder"');
        $html = '<main class="mx-auto max-w-4xl px-4 py-8"><h1 class="text-xl font-semibold">Сотрудники</h1>';
        $html .= '<form method="post" class="border-border bg-card mt-4 grid gap-2 rounded-xl border p-4 sm:grid-cols-4">' . csrf_field() . '<input type="hidden" name="_action" value="save_employee"><input type="hidden" name="id" value="">';
        $html .= '<input name="name" class="' . input_class() . '" placeholder="Имя" required><select name="homeBranchId" class="' . input_class() . '">';
        foreach ($branches as $b) {
            $html .= '<option value="' . (int) $b['id'] . '">' . h($b['name']) . '</option>';
        }
        $html .= '</select><button class="' . btn_class() . '">Добавить</button></form><ul class="mt-4 flex flex-col gap-2">';
        foreach ($emps as $e) {
            $loginNote = !empty($e['userId']) ? ' · есть вход' : '';
            $html .= '<li class="border-border bg-card grid items-center gap-3 rounded-xl border px-4 py-3 sm:grid-cols-[minmax(0,1fr)_minmax(13rem,18rem)_auto]">';
            $html .= '<div class="min-w-0"><p class="gp-break text-sm font-medium leading-snug">' . h($e['name']) . '</p>';
            $html .= '<p class="text-muted-foreground gp-break text-xs">' . h($e['branchName']) . ($e['gradeTitle'] ? ' · ' . h($e['gradeTitle']) : '') . $loginNote . '</p></div>';
            $html .= '<form method="post" class="flex min-w-0 items-center gap-2">' . csrf_field() . '<input type="hidden" name="_action" value="assign_grade"><input type="hidden" name="employeeId" value="' . (int) $e['id'] . '">';
            $html .= '<select name="gradeId" class="' . input_class() . ' min-w-0 flex-1"><option value="">уровень</option>';
            foreach ($grades as $g) {
                $sel = (int) ($e['gradeId'] ?? 0) === (int) $g['id'] ? ' selected' : '';
                $html .= '<option value="' . (int) $g['id'] . '"' . $sel . '>' . h($g['title']) . '</option>';
            }
            $html .= '</select><button class="' . btn_class('outline', 'sm') . ' shrink-0">Ок</button></form>';
            $confirm = json_encode('Удалить «' . $e['name'] . '» вместе со сменами в графике?', JSON_UNESCAPED_UNICODE);
            $html .= '<form method="post" class="justify-self-start sm:justify-self-end" onsubmit="return confirm(' . $confirm . ');">' . csrf_field();
            $html .= '<input type="hidden" name="_action" value="delete_employee"><input type="hidden" name="id" value="' . (int) $e['id'] . '">';
            $html .= '<button class="' . btn_class('destructive', 'sm') . '">Удалить</button></form></li>';
        }
        $html .= '</ul></main>';
        self::layout($user, $html);
    }

    private static function pageGrades(array $user): void
    {
        $grades = Db::all('SELECT * FROM employee_grades ORDER BY "sortOrder"');
        $html = '<main class="mx-auto max-w-xl px-4 py-8"><h1 class="text-xl font-semibold">Уровни и ставки</h1>';
        foreach ($grades as $g) {
            $html .= '<form method="post" class="border-border mt-3 grid grid-cols-1 gap-2 rounded-xl border p-3 sm:grid-cols-[1fr_8rem_auto]">' . csrf_field() . '<input type="hidden" name="_action" value="save_grade"><input type="hidden" name="id" value="' . (int) $g['id'] . '">';
            $html .= '<input name="title" class="' . input_class() . '" value="' . h($g['title']) . '"><input name="shiftRate" class="' . input_class() . '" value="' . (int) $g['shiftRate'] . '"><button class="' . btn_class() . '">Ок</button></form>';
        }
        $html .= '<form method="post" class="mt-6 grid grid-cols-1 gap-2 sm:grid-cols-[1fr_8rem_auto]">' . csrf_field() . '<input type="hidden" name="_action" value="save_grade"><input name="title" class="' . input_class() . '" placeholder="Новый уровень"><input name="shiftRate" class="' . input_class() . '" placeholder="₽/смена"><button class="' . btn_class() . '">Добавить</button></form></main>';
        self::layout($user, $html);
    }

    private static function pageTasks(array $user): void
    {
        $now = now_tyumen();
        $today = date_key($now);
        $weekRaw = (string) ($_GET['w'] ?? '');
        $week = preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekRaw) ? $weekRaw : monday_key($now);
        if (weekday_from_date_key($week) !== 0) {
            $week = monday_key(new DateTimeImmutable($week, new DateTimeZone('Asia/Yekaterinburg')));
        }
        $weekEnd = (new DateTimeImmutable($week, new DateTimeZone('Asia/Yekaterinburg')))->modify('+6 days')->format('Y-m-d');
        $prev = (new DateTimeImmutable($week))->modify('-7 days')->format('Y-m-d');
        $next = (new DateTimeImmutable($week))->modify('+7 days')->format('Y-m-d');
        $days = dates_between($week, $weekEnd, 7);
        $preEmp = (int) ($_GET['employeeId'] ?? 0);
        $preFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : $today;
        $preTo = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? (string) $_GET['to'] : $preFrom;

        $emps = array_values(array_filter(self::employees(), static fn ($e) => boolish($e['isActive'])));
        $bnames = [];
        foreach (self::branches() as $b) {
            $bnames[(int) $b['id']] = (string) $b['name'];
        }
        $shifts = Db::all(
            'SELECT "employeeId", date, "shiftType" FROM shifts WHERE date >= ? AND date <= ?',
            [$week, $weekEnd],
        );
        $shiftMap = [];
        foreach ($shifts as $s) {
            $shiftMap[(int) $s['employeeId'] . '|' . substr((string) $s['date'], 0, 10)] = (string) $s['shiftType'];
        }
        $assigned = Db::all(
            'SELECT * FROM staff_tasks WHERE date >= ? AND date <= ? ORDER BY id',
            [$week, $weekEnd],
        );
        $taskMap = [];
        foreach ($assigned as $t) {
            $taskMap[(int) $t['employeeId'] . '|' . substr((string) $t['date'], 0, 10)][] = $t;
        }

        $html = '<main class="mx-auto max-w-6xl px-4 py-8"><h1 class="text-xl font-semibold">Задания на смены</h1>';
        $html .= '<p class="text-muted-foreground mt-1 text-sm leading-relaxed">Время клуба — Тюмень. Ночная смена 20:00–08:00: после полуночи у ночного остаётся задание той даты, на которую он поставлен в графике, а не календарного «сегодня».</p>';
        $html .= self::clockLine($now);

        $html .= '<form id="assign" method="post" class="border-border bg-card mt-6 flex flex-col gap-3 rounded-xl border p-4">' . csrf_field();
        $html .= '<input type="hidden" name="_action" value="assign_staff_task"><input type="hidden" name="week" value="' . h($week) . '">';
        $html .= '<h2 class="font-semibold">Назначить</h2>';
        $html .= '<fieldset class="flex flex-col gap-2"><legend class="mb-1 text-sm font-medium">Кому</legend>';
        $html .= '<label class="flex items-center gap-2 text-sm"><input type="radio" name="who" value="picked" checked> выбранным сотрудникам</label>';
        $html .= '<div class="flex flex-wrap gap-x-3 gap-y-1 pl-6">';
        foreach ($emps as $e) {
            $chk = $preEmp === (int) $e['id'] ? ' checked' : '';
            $bn = $bnames[(int) $e['homeBranchId']] ?? '';
            $html .= '<label class="flex items-center gap-1.5 text-sm"><input type="checkbox" name="employees[]" value="' . (int) $e['id'] . '"' . $chk . '> ' . h($e['name']) . ($bn !== '' ? '<span class="text-muted-foreground text-xs"> · ' . h($bn) . '</span>' : '') . '</label>';
        }
        $html .= '</div>';
        $html .= '<label class="flex items-center gap-2 text-sm"><input type="radio" name="who" value="day"> всем дневным в графике на эти даты <span class="text-muted-foreground">(08:00–20:00 и сутки)</span></label>';
        $html .= '<label class="flex items-center gap-2 text-sm"><input type="radio" name="who" value="night"> всем ночным в графике на эти даты <span class="text-muted-foreground">(20:00–08:00 и сутки)</span></label>';
        $html .= '</fieldset>';
        $html .= '<div class="grid gap-3 sm:grid-cols-2"><label class="flex flex-col gap-1.5 text-sm font-medium">С даты<input type="date" name="from" class="' . input_class() . '" value="' . h($preFrom) . '" required></label>';
        $html .= '<label class="flex flex-col gap-1.5 text-sm font-medium">По дату<input type="date" name="to" class="' . input_class() . '" value="' . h($preTo) . '" required></label></div>';
        $html .= '<p class="text-muted-foreground text-xs">Один и тот же день — задание на смену. Разные даты — одно задание на каждый день промежутка.</p>';
        $html .= '<label class="flex flex-col gap-1.5 text-sm font-medium">Задание<input name="title" class="' . input_class() . '" required placeholder="Что сделать на смене"></label>';
        $html .= '<label class="flex flex-col gap-1.5 text-sm font-medium">Подробности<textarea name="description" class="' . input_class('min-h-16') . '" placeholder="Необязательно"></textarea></label>';
        $html .= '<button class="' . btn_class() . '">Назначить</button></form>';

        $weekLabel = (int) (new DateTimeImmutable($week))->format('j') . '–' . (int) (new DateTimeImmutable($weekEnd))->format('j') . ' ' . MONTH_NAMES_GENITIVE[(int) (new DateTimeImmutable($weekEnd))->format('n') - 1];
        $html .= '<div class="mt-8 flex flex-wrap items-center justify-between gap-3"><a class="' . btn_class('outline', 'sm') . '" href="' . h(url_to('/manage/tasks', ['w' => $prev])) . '">← неделя</a>';
        $html .= '<span class="min-w-0 flex-1 text-center text-sm font-medium">' . h($weekLabel) . '</span>';
        $html .= '<a class="' . btn_class('outline', 'sm') . '" href="' . h(url_to('/manage/tasks', ['w' => $next])) . '">неделя →</a></div>';
        $html .= '<div class="gp-week-table mt-3 overflow-x-auto"><table class="w-full min-w-[44rem] text-sm"><thead><tr class="bg-muted/60"><th class="px-2 py-2 text-left text-xs font-semibold uppercase">Сотрудник</th>';
        foreach ($days as $d) {
            $p = parse_date_key($d);
            $isToday = $d === $today;
            $html .= '<th class="px-2 py-2 text-center text-xs font-semibold uppercase' . ($isToday ? ' text-primary' : '') . '">' . h(WEEKDAY_SHORT[weekday_from_date_key($d)]) . '<span class="block tabular-nums text-sm font-semibold">' . ($p['day'] ?? '') . '</span></th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($emps as $e) {
            $eid = (int) $e['id'];
            $html .= '<tr class="border-t border-border align-top"><th class="px-2 py-2 text-left font-medium"><span class="block">' . h($e['name']) . '</span><span class="text-muted-foreground text-xs font-normal">' . h($bnames[(int) $e['homeBranchId']] ?? '') . '</span></th>';
            foreach ($days as $d) {
                $key = $eid . '|' . $d;
                $st = $shiftMap[$key] ?? '';
                $items = $taskMap[$key] ?? [];
                $href = url_to('/manage/tasks', ['w' => $week, 'employeeId' => $eid, 'from' => $d, 'to' => $d]) . '#assign';
                $html .= '<td class="border-border px-1.5 py-1.5' . ($d === $today ? ' bg-primary/5' : '') . '">';
                if ($st !== '') {
                    $ic = $st === 'night' ? 'moon-star' : ($st === 'full' ? 'sun-moon' : 'sun');
                    $html .= '<p class="text-muted-foreground mb-1 flex items-center gap-1 text-[10px] uppercase">' . icon($ic, 'size-3') . ' ' . h(SHIFT_LABELS[$st] ?? $st) . '</p>';
                }
                foreach ($items as $t) {
                    $html .= '<div class="border-border mb-1 flex items-start justify-between gap-1 rounded-md border px-1.5 py-1"><span class="min-w-0 text-xs leading-snug">' . h($t['title']) . '</span>';
                    $html .= '<form method="post" class="shrink-0" onsubmit="return confirm(\'Снять это задание?\');">' . csrf_field();
                    $html .= '<input type="hidden" name="_action" value="delete_staff_task"><input type="hidden" name="id" value="' . (int) $t['id'] . '"><input type="hidden" name="week" value="' . h($week) . '">';
                    $html .= '<button class="text-muted-foreground hover:text-destructive" title="Снять">' . icon('trash', 'size-3.5') . '</button></form></div>';
                }
                $html .= '<a class="text-muted-foreground hover:text-foreground inline-flex items-center gap-0.5 text-[11px]" href="' . h($href) . '">' . icon('plus', 'size-3') . ' назначить</a>';
                $html .= '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';
        $html .= '<div class="gp-week-cards mt-3">';
        foreach ($emps as $e) {
            $eid = (int) $e['id'];
            $bn = $bnames[(int) $e['homeBranchId']] ?? '';
            $html .= '<article class="gp-week-card"><h3>' . h($e['name']);
            if ($bn !== '') {
                $html .= '<span class="text-muted-foreground font-medium"> · ' . h($bn) . '</span>';
            }
            $html .= '</h3>';
            foreach ($days as $d) {
                $key = $eid . '|' . $d;
                $st = $shiftMap[$key] ?? '';
                $items = $taskMap[$key] ?? [];
                $href = url_to('/manage/tasks', ['w' => $week, 'employeeId' => $eid, 'from' => $d, 'to' => $d]) . '#assign';
                $p = parse_date_key($d);
                $wdn = WEEKDAY_LONG[weekday_from_date_key($d)] ?? '';
                $html .= '<div class="gp-week-day' . ($d === $today ? ' is-today' : '') . '">';
                $html .= '<div class="gp-week-day-h"><span>' . h($wdn) . ', ' . (int) ($p['day'] ?? 0) . '</span>';
                if ($st !== '') {
                    $html .= '<span>' . h(SHIFT_LABELS[$st] ?? $st) . (isset(SHIFT_HINTS[$st]) ? ' · ' . h(SHIFT_HINTS[$st]) : '') . '</span>';
                } else {
                    $html .= '<span class="text-muted-foreground font-medium">нет в графике</span>';
                }
                $html .= '</div>';
                foreach ($items as $t) {
                    $html .= '<div class="border-border flex items-start justify-between gap-2 rounded-md border px-2 py-1.5"><span class="min-w-0 text-sm leading-snug">' . h($t['title']) . '</span>';
                    $html .= '<form method="post" class="shrink-0" onsubmit="return confirm(\'Снять это задание?\');">' . csrf_field();
                    $html .= '<input type="hidden" name="_action" value="delete_staff_task"><input type="hidden" name="id" value="' . (int) $t['id'] . '"><input type="hidden" name="week" value="' . h($week) . '">';
                    $html .= '<button class="text-muted-foreground hover:text-destructive" title="Снять">' . icon('trash', 'size-3.5') . '</button></form></div>';
                }
                $html .= '<a class="text-primary inline-flex items-center gap-0.5 text-sm font-medium" href="' . h($href) . '">' . icon('plus', 'size-3.5') . ' назначить</a>';
                $html .= '</div>';
            }
            $html .= '</article>';
        }
        $html .= '</div></main>';
        self::layout($user, $html);
    }

    private static function pageAccounts(array $user): void
    {
        $acc = Db::all(
            'SELECT u.id, u.name, u.email, u.role, e.name AS "empName", b.name AS "branchName"
             FROM "user" u
             LEFT JOIN employees e ON e."userId" = u.id
             LEFT JOIN branches b ON b.id = e."homeBranchId"
             ORDER BY CASE u.role WHEN \'owner\' THEN 0 ELSE 1 END, u.name',
        );
        $unlinked = Db::all(
            'SELECT e.id, e.name, b.name AS "branchName"
             FROM employees e JOIN branches b ON b.id = e."homeBranchId"
             WHERE e."userId" IS NULL AND e."isActive" = 1
             ORDER BY e.name',
        );
        $branches = self::branches();
        $html = '<main class="mx-auto max-w-xl px-4 py-8"><h1 class="text-xl font-semibold">Доступы</h1>';
        $html .= '<p class="text-muted-foreground mt-1 text-sm">Новый администратор сразу появляется в графике смен — его можно ставить на слот. Старый доступ можно удалить.</p>';
        $html .= '<ul class="mt-4 flex flex-col gap-3">';
        foreach ($acc as $a) {
            $login = str_replace('@' . LOGIN_DOMAIN, '', (string) $a['email']);
            $roleLabel = $a['role'] === 'owner' ? 'владелец' : 'администратор';
            $isMe = $a['id'] === $user['id'];
            $html .= '<li class="border-border rounded-xl border p-4"><p class="font-medium">' . h($a['name']);
            if ($isMe) {
                $html .= ' <span class="text-muted-foreground text-xs font-normal">это вы</span>';
            }
            $html .= '</p><p class="text-muted-foreground text-sm">' . h($login) . ' · ' . h($roleLabel);
            if (!empty($a['empName'])) {
                $html .= ' · ' . h($a['empName']);
                if (!empty($a['branchName'])) {
                    $html .= ', ' . h($a['branchName']);
                }
            }
            $html .= '</p>';
            $html .= '<form method="post" class="mt-2 flex flex-wrap gap-2">' . csrf_field() . '<input type="hidden" name="_action" value="reset_password"><input type="hidden" name="userId" value="' . h($a['id']) . '"><input name="password" class="' . input_class() . '" placeholder="Новый пароль" autocomplete="new-password"><button class="' . btn_class('outline', 'sm') . '">Сбросить</button></form>';
            if (!$isMe) {
                $confirm = json_encode('Удалить доступ «' . $a['name'] . '»? Войти больше не получится, из графика сотрудник пропадёт.', JSON_UNESCAPED_UNICODE);
                $html .= '<form method="post" class="mt-2" onsubmit="return confirm(' . $confirm . ');">' . csrf_field();
                $html .= '<input type="hidden" name="_action" value="delete_account"><input type="hidden" name="userId" value="' . h($a['id']) . '">';
                $html .= '<button class="' . btn_class('destructive', 'sm') . '">Удалить</button></form>';
            }
            $html .= '</li>';
        }
        $html .= '</ul><form method="post" class="border-border mt-8 flex flex-col gap-3 rounded-xl border p-4">' . csrf_field() . '<input type="hidden" name="_action" value="save_account"><h2 class="font-semibold">Новый доступ</h2>';
        $html .= '<label class="flex flex-col gap-1.5 text-sm font-medium">Сотрудник<select name="employeeId" class="' . input_class() . '"><option value="">Новый — создать карточку в графике</option>';
        foreach ($unlinked as $e) {
            $html .= '<option value="' . (int) $e['id'] . '">' . h($e['name']) . ' · ' . h($e['branchName']) . '</option>';
        }
        $html .= '</select></label>';
        $html .= '<label class="flex flex-col gap-1.5 text-sm font-medium">Имя<input name="name" class="' . input_class() . '" placeholder="Как в графике, можно пустым если выбран сотрудник"></label>';
        $html .= '<label class="flex flex-col gap-1.5 text-sm font-medium">Логин<input name="login" class="' . input_class() . '" placeholder="латиницей, без пробелов" required autocapitalize="none" autocomplete="off"></label>';
        $html .= '<label class="flex flex-col gap-1.5 text-sm font-medium">Пароль<input name="password" type="text" class="' . input_class() . '" placeholder="не короче 8 символов" required autocomplete="off"></label>';
        $html .= '<label class="flex flex-col gap-1.5 text-sm font-medium">Филиал<select name="homeBranchId" class="' . input_class() . '">';
        foreach ($branches as $b) {
            $html .= '<option value="' . (int) $b['id'] . '">' . h($b['name']) . '</option>';
        }
        $html .= '</select></label>';
        $html .= '<label class="flex flex-col gap-1.5 text-sm font-medium">Роль<select name="role" class="' . input_class() . '"><option value="admin">Администратор</option><option value="owner">Владелец</option></select></label>';
        $html .= '<p class="text-muted-foreground text-xs leading-relaxed">Администратору нужна карточка сотрудника: либо выберите уже добавленного без входа, либо укажите имя и филиал — появится в графике. Пароль виден сейчас, чтобы передать менеджеру.</p>';
        $html .= '<button class="' . btn_class() . '">Создать</button></form></main>';
        self::layout($user, $html);
    }

    private static function pageManageExams(array $user): void
    {
        $rows = Db::all(
            'SELECT a.id, a.status, a."scorePercent", a."submittedAt", a."startedAt",
                    e.title AS "examTitle", e.slug AS "examSlug", e."passPercent",
                    u.name AS "userName"
             FROM exam_attempts a
             JOIN exams e ON e.id = a."examId"
             JOIN "user" u ON u.id = a."userId"
             WHERE a.status != ?
             ORDER BY a."submittedAt" DESC, a."startedAt" DESC',
            ['in_progress'],
        );
        $body = '';
        if (!$rows) {
            $body .= '<p>Сотрудники пока не сдавали экзамены.</p>';
        } else {
            foreach ($rows as $i => $row) {
                $status = (string) $row['status'];
                $st = ATTEMPT_STATUS_LABELS[$status] ?? $status;
                if ($row['scorePercent'] !== null && $row['scorePercent'] !== '') {
                    $st .= ', ' . (int) $row['scorePercent'] . '%';
                }
                $date = (string) ($row['submittedAt'] ?: $row['startedAt']);
                $href = url_to('/exams/' . rawurlencode($row['examSlug']) . '/result/' . (int) $row['id']);
                $cls = $status === 'passed' ? ' is-done' : '';
                $body .= '<a class="gp-exam-row' . $cls . '" href="' . h($href) . '">';
                $body .= '<span class="gp-exam-row-ico">' . icon('grad', 'size-5') . '</span>';
                $body .= '<span class="min-w-0 flex-1"><span class="block font-semibold leading-snug">' . h($row['userName']) . '</span>';
                $body .= '<span class="text-muted-foreground mt-0.5 block text-xs">' . h($row['examTitle']) . ' · ' . h(substr($date, 0, 10)) . '</span></span>';
                $body .= '<span class="gp-exam-row-meta"><span>' . h($st) . '</span>' . icon('chevronright', 'size-4') . '</span></a>';
            }
        }
        $nav = '<a href="' . h(url_to('/exams')) . '" class="gp-exam-back">Назад</a><span></span>';
        self::layout($user, self::examPage($user, self::examFrame('Проверка экзаменов', self::examPad(count($rows)), $body, $nav)));
    }
}
