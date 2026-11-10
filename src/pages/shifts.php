<?php
declare(strict_types=1);

trait PagesShifts
{
    private static function greeting(DateTimeImmutable $now): string
    {
        $h = (int) $now->format('G');
        return match (true) {
            $h < 5 => 'Доброй ночи',
            $h < 12 => 'Доброе утро',
            $h < 18 => 'Добрый день',
            default => 'Добрый вечер',
        };
    }

    private static function pageHome(array $user): void
    {
        $now = now_tyumen();
        $today = date_key($now);
        $name = self::displayName($user);
        $eid = $user['employeeId'];
        $current = self::currentShiftFor($eid);
        $upcoming = $eid ? self::upcomingShiftsFor($eid, 21) : [];
        $next = null;
        foreach ($upcoming as $s) {
            if ($s['state']['state'] === 'upcoming') {
                $next = $s;
                break;
            }
        }
        $live = self::liveShifts();
        $soon = self::soonByBranch();
        $handover = self::handoverShift($user);
        $handoverPhase = $handover
            ? self::handoverNextPhase($handover, self::handoverReports((int) $handover['branchId'], (string) $handover['date'], (string) $handover['shiftType']))
            : null;
        $attention = [];
        if ($handoverPhase === 'close' && $handover['state']['state'] === 'done') {
            $attention[] = [url_to('/shift'), 'clipboard-check', 'Смена закончилась, но не сдана — заполните кассу и чек-лист'];
        }
        $wd = WEEKDAY_LONG[weekday_from_date_key($today)];
        $dateLabel = mb_strtoupper(mb_substr($wd, 0, 1)) . mb_substr($wd, 1) . ', ' . format_day_month($today);

        $html = '<header class="flex flex-col gap-2">' . eyebrow($dateLabel);
        $html .= '<h1 class="font-display text-2xl leading-tight font-semibold tracking-tight text-balance sm:text-[2rem]">' . h(self::greeting($now)) . ', ' . h(first_name($name)) . '</h1>';
        $html .= '<p class="flex items-center gap-2 text-sm text-muted-foreground">' . icon('clock', 'size-4') . '<span>Сейчас <time data-clock class="font-semibold text-foreground tabular-nums">' . $now->format('H:i') . '</time> по времени клуба</span></p></header>';

        $html .= '<div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1.55fr)_minmax(0,1fr)]">';
        $html .= '<div class="flex min-w-0 flex-col gap-6">';
        if ($eid) {
            $hero = $current ?? $next;
            $heroPhase = $hero && $handover && (int) $handover['id'] === (int) $hero['id'] ? $handoverPhase : null;
            $html .= self::shiftTicket($hero, $live, $today, $heroPhase);
            $taskDate = $current['date'] ?? (($next && $next['date'] === $today) ? $today : null);
            if ($taskDate !== null) {
                $html .= self::myTasksCard($user, $taskDate, $today);
            }
            $rest = array_values(array_filter($upcoming, static fn ($s) => !$hero || $s['id'] !== $hero['id']));
            $html .= self::upcomingCard(array_slice($rest, 0, 6));
        } else {
            $html .= self::clubsNowHero($live, $soon);
        }
        $html .= '</div><div class="flex min-w-0 flex-col gap-6">';
        $html .= self::attentionCard($user, $attention);
        if ($eid) {
            $html .= self::clubsNowCard($live, $soon);
        }
        $html .= self::latestNewsCard($user);
        $html .= '</div></div>';

        if (can($user, 'tasks.assign') && $live) {
            $html .= self::liveTasksOverview($user, $live);
        }
        self::layout($user, $html, ['title' => 'Мой день']);
    }

    /** Ближайшая следующая смена по каждому филиалу (сегодня–завтра). */
    private static function soonByBranch(): array
    {
        $now = now_tyumen();
        $rows = Db::all(
            'SELECT s.date, s."branchId", s."shiftType", s."employeeId", e.name AS "employeeName"
             FROM shifts s JOIN employees e ON e.id = s."employeeId"
             WHERE s.date IN (?, ?) ORDER BY e."sortOrder"',
            [date_key($now), $now->modify('+1 day')->format('Y-m-d')],
        );
        $best = [];
        foreach ($rows as $r) {
            $st = shift_state(substr((string) $r['date'], 0, 10), (string) $r['shiftType'], $now);
            if ($st['state'] !== 'upcoming') {
                continue;
            }
            $bid = (int) $r['branchId'];
            $start = $st['start']->getTimestamp();
            if (!isset($best[$bid]) || $start < $best[$bid]['start']) {
                $best[$bid] = ['start' => $start, 'startAt' => $st['start'], 'type' => $r['shiftType'], 'names' => [short_name((string) $r['employeeName'])]];
            } elseif ($start === $best[$bid]['start']) {
                $best[$bid]['names'][] = short_name((string) $r['employeeName']);
            }
        }
        return $best;
    }

    private static function shiftTicket(?array $s, array $live, string $today, ?string $handoverPhase = null): string
    {
        $html = '<section class="cut relative flex flex-col gap-6 bg-sidebar p-5 text-sidebar-accent-foreground sm:p-7" style="--cut:26px" aria-labelledby="my-shift-title">';
        $html .= '<img src="' . h(asset('img/club/hall-wide-sm.webp')) . '" alt="" class="pointer-events-none absolute inset-y-0 right-0 hidden h-full w-1/2 object-cover opacity-25 [mask-image:linear-gradient(to_left,black,transparent)] sm:block" aria-hidden="true">';
        $html .= '<div class="relative flex flex-wrap items-center justify-between gap-3"><p class="flex items-center gap-2 text-[11px] font-semibold tracking-[0.18em] text-sidebar-muted uppercase"><span class="cut-mark" aria-hidden="true"></span>Моя смена</p>';
        if (!$s) {
            $html .= '</div><div class="relative flex flex-col gap-2"><p id="my-shift-title" class="font-display text-2xl font-semibold">Смен пока нет</p><p class="max-w-md text-sm leading-relaxed text-sidebar-foreground">В ближайшие три недели вас нет в графике. Если вышли за кого-то — попросите поставить смену.</p></div>';
            $html .= '<a href="' . h(url_to('/schedule')) . '" class="relative inline-flex w-fit items-center gap-1.5 text-sm font-semibold hover:text-brand">Открыть график ' . icon('arrow', 'size-4') . '</a></section>';
            return $html;
        }
        $type = (string) $s['shiftType'];
        $st = $s['state'];
        $isLive = $st['state'] === 'live';
        if ($isLive) {
            $html .= '<span class="inline-flex items-center gap-2 rounded-[5px] bg-primary px-2 py-0.5 text-xs leading-5 font-semibold text-primary-foreground"><span class="live-dot" aria-hidden="true"></span>Идёт сейчас</span>';
        } else {
            $html .= '<span class="rounded-[5px] bg-sidebar-accent px-2 py-0.5 text-xs leading-5 font-semibold">' . ($s['date'] === $today ? 'Сегодня' : 'Следующая') . '</span>';
        }
        $html .= '</div>';
        $tone = $type === 'night' ? 'text-night-strong' : 'text-day-strong';
        $html .= '<div class="relative flex flex-col gap-2"><p id="my-shift-title" class="font-display text-[2rem] leading-none font-semibold tracking-tight sm:text-[2.6rem]">' . h($s['branchName']) . '</p>';
        $html .= '<p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-sidebar-foreground">' . icon(SHIFT_ICONS[$type] ?? 'sun', 'size-4 ' . $tone) . '<span class="font-semibold text-sidebar-accent-foreground">' . h(SHIFT_LABELS[$type] ?? $type) . '</span><span>' . $st['start']->format('H:i') . '–' . $st['end']->format('H:i') . '</span>';
        if ($s['date'] !== $today) {
            $html .= '<span>· ' . h(format_day_short($s['date'])) . '</span>';
        }
        $html .= '</p></div>';
        if ($isLive) {
            $html .= '<div class="relative flex max-w-lg flex-col gap-2"><div class="h-1.5 overflow-hidden rounded-full bg-sidebar-accent" role="progressbar" aria-label="Прошло смены" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $st['progress'] . '"><div class="h-full rounded-full bg-primary" style="width:' . $st['progress'] . '%"></div></div>';
            $html .= '<div class="flex justify-between gap-2 text-xs text-sidebar-muted tabular-nums"><span>' . $st['start']->format('H:i') . '</span><span class="font-semibold text-sidebar-accent-foreground">осталось ' . h(duration_label($st['minutesLeft'])) . '</span><span>' . $st['end']->format('H:i') . '</span></div></div>';
            $mates = array_values(array_filter($live, static fn ($r) => (int) $r['branchId'] === (int) $s['branchId'] && (int) $r['employeeId'] !== (int) $s['employeeId']));
            if ($mates) {
                $html .= '<p class="relative text-sm text-sidebar-foreground">С вами на смене: <span class="font-semibold text-sidebar-accent-foreground">' . h(implode(', ', array_map(static fn ($m) => short_name((string) $m['employeeName']), $mates))) . '</span></p>';
            }
        } else {
            $html .= '<p class="relative text-sm text-sidebar-foreground">Начнётся через <span class="font-semibold text-sidebar-accent-foreground">' . h(duration_label($st['minutesToStart'])) . '</span></p>';
        }
        $html .= '<div class="relative flex flex-wrap items-center gap-x-5 gap-y-3">';
        if ($handoverPhase !== null && isset(HANDOVER_PHASES[$handoverPhase])) {
            $meta = HANDOVER_PHASES[$handoverPhase];
            $html .= '<a href="' . h(url_to('/shift')) . '" class="' . btn_class('primary') . '">' . icon($meta['icon'], 'size-4') . h($meta['verb']) . '</a>';
        }
        $html .= '<a href="' . h(url_to('/schedule', ['branch' => self::branchById((int) $s['branchId'])['slug'] ?? null])) . '" class="inline-flex w-fit items-center gap-1.5 text-sm font-semibold hover:text-brand">График смен ' . icon('arrow', 'size-4') . '</a>';
        return $html . '</div></section>';
    }

    private static function myTasksCard(array $user, string $date, string $today): string
    {
        $tasks = self::staffTasksFor((int) $user['employeeId'], $date, $user['id']);
        $editable = $date === $today || $date === add_days($today, -1);
        $done = count(array_filter($tasks, static fn ($t) => !empty($t['done_at'])));
        $meta = h(format_date_human($date)) . ($tasks ? ' · выполнено ' . $done . ' из ' . count($tasks) : '');
        $html = card_head('Задания на смену', $meta, '', 'clipboard-list');
        if (!$tasks) {
            $html .= '<p class="mt-4 text-sm text-muted-foreground">На эту смену заданий нет. Раб��тайте по регламенту из базы знаний.</p>';
            return card($html, 'p-5');
        }
        $html .= '<ul class="mt-4 flex flex-col gap-2">';
        foreach ($tasks as $t) {
            $isDone = !empty($t['done_at']);
            $html .= '<li class="flex items-start gap-3 rounded-md border border-border p-3 ' . ($isDone ? 'bg-muted/40' : 'bg-card') . '">';
            if ($editable) {
                $html .= form_open($isDone ? 'uncomplete_task' : 'complete_task', 'class="shrink-0"') . hidden('taskId', (int) $t['id']);
                $html .= '<button class="flex size-6 items-center justify-center rounded-[5px] border-2 transition-colors ' . ($isDone ? 'border-foreground bg-foreground text-background' : 'border-input hover:border-foreground') . '" aria-label="' . ($isDone ? 'Снять отметку' : 'Отметить выполненным') . ': ' . h($t['title']) . '">' . ($isDone ? icon('check', 'size-4') : '') . '</button></form>';
            } else {
                $html .= '<span class="flex size-6 shrink-0 items-center justify-center rounded-[5px] border-2 ' . ($isDone ? 'border-foreground bg-foreground text-background' : 'border-input') . '">' . ($isDone ? icon('check', 'size-4') : '') . '</span>';
            }
            $html .= '<div class="flex min-w-0 flex-1 flex-col gap-1"><p class="font-semibold leading-snug text-pretty ' . ($isDone ? 'text-muted-foreground line-through decoration-1' : '') . '">' . h($t['title']) . '</p>';
            if (trim((string) $t['description']) !== '') {
                $html .= '<p class="text-sm leading-relaxed whitespace-pre-line text-muted-foreground break-words">' . h($t['description']) . '</p>';
            }
            if ($isDone) {
                $html .= '<p class="text-xs font-medium text-muted-foreground">Выполнено ' . h(format_dt((string) $t['done_at'])) . '</p>';
            }
            $html .= '</div></li>';
        }
        return card($html . '</ul>', 'p-5');
    }

    private static function upcomingCard(array $rows): string
    {
        $html = card_head('Ближайшие смены', $rows ? '' : 'Других смен в ближайшие три недели нет', '<a class="' . btn_class('quiet', 'sm') . '" href="' . h(url_to('/schedule')) . '">Весь график ' . icon('arrow', 'size-3.5') . '</a>', 'calendar');
        if ($rows) {
            $html .= '<ul class="mt-4 flex flex-col divide-y divide-border">';
            foreach ($rows as $r) {
                $type = (string) $r['shiftType'];
                $html .= '<li class="flex items-center gap-3 py-2.5 first:pt-0 last:pb-0">';
                $html .= '<span class="flex size-9 shrink-0 items-center justify-center rounded-md ' . shift_tone_class($type) . '">' . icon(SHIFT_ICONS[$type] ?? 'sun', 'size-4') . '</span>';
                $html .= '<div class="flex min-w-0 flex-1 flex-col"><span class="text-sm font-semibold capitalize">' . h(format_day_short($r['date'])) . '</span><span class="text-xs text-muted-foreground">' . h(SHIFT_LABELS[$type] ?? $type) . ' ' . h(SHIFT_HINTS[$type] ?? '') . '</span></div>';
                $html .= '<span class="text-sm text-muted-foreground">' . h($r['branchName']) . '</span></li>';
            }
            $html .= '</ul>';
        }
        return card($html, 'p-5');
    }

    private static function attentionCard(array $user, array $items = []): string
    {
        $c = self::counts($user);
        if ($c['news'] > 0) {
            $items[] = [url_to('/news'), 'megaphone', plural($c['news'], 'новое объявление', 'новых объявления', 'новых объявлений')];
        }
        if ($c['issues'] > 0) {
            $items[] = [url_to('/issues'), 'wrench', plural($c['issues'], 'открытая поломка', 'открытые поломки', 'открытых поломок')];
        }
        if ($c['reviews'] > 0) {
            $items[] = [url_to('/reviews', ['status' => 'pending']), 'star', plural($c['reviews'], 'заявка на бонусы', 'заявки на бонусы', 'заявок на бонусы')];
        }
        if ($c['compensations'] > 0) {
            $items[] = [url_to('/compensations', ['status' => 'pending']), 'coins', plural($c['compensations'], 'компенсация ждёт', 'компенсации ждут', 'компенсаций ждут')];
        }
        $html = card_head('Требует внимания', '', '', 'bell');
        if (!$items) {
            $html .= '<p class="mt-4 flex items-center gap-2 text-sm text-muted-foreground">' . icon('check-circle', 'size-4') . 'Всё спокойно — новых дел нет.</p>';
            return card($html, 'p-5');
        }
        $html .= '<ul class="mt-4 flex flex-col gap-2">';
        foreach ($items as [$href, $ic, $label]) {
            $html .= '<li><a href="' . h($href) . '" class="group flex items-center gap-3 rounded-md border border-border px-3 py-2.5 text-sm font-medium transition-colors hover:border-foreground/20 hover:bg-muted/50"><span class="text-primary">' . icon($ic, 'size-4') . '</span><span class="min-w-0 flex-1">' . h($label) . '</span>' . icon('chevronright', 'size-4 text-muted-foreground transition-transform group-hover:translate-x-0.5') . '</a></li>';
        }
        return card($html . '</ul>', 'p-5');
    }

    private static function clubBranchRows(array $live, array $soon, bool $dark): string
    {
        $muted = $dark ? 'text-sidebar-muted' : 'text-muted-foreground';
        $html = '';
        foreach (self::branches() as $b) {
            $people = array_values(array_filter($live, static fn ($r) => (int) $r['branchId'] === $b['id']));
            $html .= '<div class="flex min-w-0 flex-col gap-2.5">';
            $html .= '<div class="flex items-baseline justify-between gap-2"><p class="font-semibold">' . h($b['name']) . '</p><span class="text-xs ' . $muted . '">' . ($people ? h(plural(count($people), 'человек', 'человека', 'человек')) : 'никого') . '</span></div>';
            if ($people) {
                $html .= '<div class="flex flex-wrap gap-1.5">';
                foreach ($people as $p) {
                    $type = (string) $p['shiftType'];
                    $html .= '<span class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-xs font-semibold ' . shift_tone_class($type) . '">' . icon(SHIFT_ICONS[$type] ?? 'sun', 'size-3.5') . h(short_name((string) $p['employeeName'])) . '</span>';
                }
                $html .= '</div>';
            } else {
                $html .= '<p class="text-sm ' . $muted . '">Сейчас на смене никого нет</p>';
            }
            if (isset($soon[$b['id']])) {
                $n = $soon[$b['id']];
                $html .= '<p class="text-xs leading-relaxed ' . $muted . '">Далее · ' . h(SHIFT_LABELS[$n['type']] ?? '') . ' с ' . $n['startAt']->format('H:i') . ': ' . h(implode(', ', $n['names'])) . '</p>';
            }
            $html .= '</div>';
        }
        return $html;
    }

    private static function clubsNowCard(array $live, array $soon): string
    {
        $html = card_head('Сейчас в клубах', '', '', 'building');
        $html .= '<div class="mt-4 flex flex-col gap-5 divide-border">' . self::clubBranchRows($live, $soon, false) . '</div>';
        return card($html, 'p-5');
    }

    private static function clubsNowHero(array $live, array $soon): string
    {
        $html = '<section class="cut flex flex-col gap-6 bg-sidebar p-5 text-sidebar-accent-foreground sm:p-7" style="--cut:26px" aria-labelledby="clubs-now">';
        $html .= '<div class="flex flex-wrap items-center justify-between gap-3"><p id="clubs-now" class="flex items-center gap-2 text-[11px] font-semibold tracking-[0.18em] text-sidebar-muted uppercase"><span class="cut-mark" aria-hidden="true"></span>Сейчас в клубах</p>';
        $html .= '<span class="inline-flex items-center gap-2 rounded-[5px] bg-sidebar-accent px-2 py-0.5 text-xs leading-5 font-semibold"><span class="live-dot" aria-hidden="true"></span>' . h(plural(count($live), 'человек', 'человека', 'человек')) . ' на смене</span></div>';
        $html .= '<div class="grid gap-6 sm:grid-cols-2">' . self::clubBranchRows($live, $soon, true) . '</div>';
        $html .= '<a href="' . h(url_to('/schedule')) . '" class="inline-flex w-fit items-center gap-1.5 text-sm font-semibold hover:text-brand">График смен ' . icon('arrow', 'size-4') . '</a>';
        return $html . '</section>';
    }

    private static function latestNewsCard(array $user): string
    {
        $rows = Db::all(
            'SELECT a.id, a.title, a.body, a."isPinned", a."createdAt", a."createdByName",
                    (SELECT 1 FROM announcement_reads r WHERE r."announcementId" = a.id AND r."userId" = ?) AS read_me
             FROM announcements a WHERE a."isDraft" = 0 ORDER BY a."isPinned" DESC, a."createdAt" DESC LIMIT 3',
            [$user['id']],
        );
        $html = card_head('Объявления', '', '<a class="' . btn_class('quiet', 'sm') . '" href="' . h(url_to('/news')) . '">Все ' . icon('arrow', 'size-3.5') . '</a>', 'megaphone');
        if (!$rows) {
            $html .= '<p class="mt-4 text-sm text-muted-foreground">Пока ничего не публиковали.</p>';
            return card($html, 'p-5');
        }
        $html .= '<ul class="mt-4 flex flex-col divide-y divide-border">';
        foreach ($rows as $r) {
            $unread = empty($r['read_me']);
            $html .= '<li class="py-3 first:pt-0 last:pb-0"><a class="group flex flex-col gap-1" href="' . h(url_to('/news', ['open' => (int) $r['id']])) . '">';
            $html .= '<span class="flex items-start gap-2"><span class="min-w-0 flex-1 text-sm font-semibold leading-snug text-pretty group-hover:underline">' . h($r['title']) . '</span>';
            if ($unread) {
                $html .= chip('Новое', 'brand');
            } elseif (boolish($r['isPinned'])) {
                $html .= '<span class="text-muted-foreground" title="Закреплено">' . icon('pushpin', 'size-3.5') . '</span>';
            }
            $html .= '</span><span class="text-xs text-muted-foreground">' . h(format_dt((string) $r['createdAt'], false)) . ' · ' . h((string) $r['createdByName']) . '</span></a></li>';
        }
        return card($html . '</ul>', 'p-5');
    }

    private static function liveTasksOverview(array $user, array $live): string
    {
        $scope = perm_scope($user, 'tasks.assign');
        $people = array_values(array_filter($live, static fn ($p) => $scope === null || (int) $p['branchId'] === $scope || (int) $p['homeBranchId'] === $scope));
        if (!$people) {
            return '';
        }
        $html = '<section class="flex flex-col gap-4">' . section_title('Задания на текущих сменах', '<a class="' . btn_class('outline', 'sm') . '" href="' . h(url_to('/manage/tasks')) . '">' . icon('plus', 'size-4') . 'Назначить</a>');
        $html .= '<div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">';
        foreach ($people as $p) {
            $tasks = self::staffTasksFor((int) $p['employeeId'], $p['date']);
            $done = count(array_filter($tasks, static fn ($t) => !empty($t['done_at'])));
            $type = (string) $p['shiftType'];
            $inner = '<div class="flex items-start justify-between gap-3"><div class="flex min-w-0 flex-col"><p class="truncate font-semibold">' . h($p['employeeName']) . '</p><p class="text-xs text-muted-foreground">' . h($p['branchName']) . ' · ' . h(SHIFT_LABELS[$type] ?? $type) . '</p></div>';
            $inner .= $tasks ? chip($done . '/' . count($tasks), $done === count($tasks) ? 'ink' : 'neutral', 'check') : '';
            $inner .= '</div>';
            if (!$tasks) {
                $inner .= '<p class="mt-3 text-sm text-muted-foreground">Заданий нет. <a class="font-medium text-primary-ink hover:underline" href="' . h(url_to('/manage/tasks', ['employeeId' => (int) $p['employeeId'], 'from' => $p['date']]) . '#assign') . '">Назначить</a></p>';
            } else {
                $inner .= '<ul class="mt-3 flex flex-col gap-1.5">';
                foreach ($tasks as $t) {
                    $ok = !empty($t['done_at']);
                    $inner .= '<li class="flex items-start gap-2 text-sm"><span class="mt-0.5 ' . ($ok ? 'text-foreground' : 'text-muted-foreground') . '">' . icon($ok ? 'check-circle' : 'clock', 'size-4') . '</span><span class="min-w-0 ' . ($ok ? 'text-muted-foreground line-through' : '') . '">' . h($t['title']) . '</span></li>';
                }
                $inner .= '</ul>';
            }
            $html .= card($inner, 'p-4', 'article');
        }
        return $html . '</div></section>';
    }

    /* ---------------- График ---------------- */

    private static function pageSchedule(array $user): void
    {
        $branch = self::pickBranch($user);
        if (!$branch) {
            self::layout($user, empty_state('building', 'Филиалы ещё не добавлены'), ['title' => 'График смен']);
            return;
        }
        $now = now_tyumen();
        $today = date_key($now);
        $ym = self::getStr('m', 7);
        if (preg_match('/^(\d{4})-(\d{2})$/', $ym, $mm) && (int) $mm[2] >= 1 && (int) $mm[2] <= 12) {
            $year = (int) $mm[1];
            $month = (int) $mm[2] - 1;
        } else {
            $year = (int) $now->format('Y');
            $month = (int) $now->format('n') - 1;
        }
        $last = days_in_month($year, $month);
        $from = to_date_key($year, $month, 1);
        $to = to_date_key($year, $month, $last);
        $canEdit = can($user, 'schedule.edit', $branch['id']);
        $meId = (int) ($user['employeeId'] ?? 0);
        $monthParam = sprintf('%04d-%02d', $year, $month + 1);
        $isCurrentMonth = $monthParam === $now->format('Y-m');
        $first = new DateTimeImmutable($from);
        $prevM = $first->modify('-1 month')->format('Y-m');
        $nextM = $first->modify('+1 month')->format('Y-m');

        $shifts = Db::all(
            'SELECT s.id, s.date, s."employeeId", s."shiftType", s."branchId", e.name AS "employeeName", e."homeBranchId", hb.name AS "homeBranchName"
             FROM shifts s
             JOIN employees e ON e.id = s."employeeId"
             JOIN branches hb ON hb.id = e."homeBranchId"
             WHERE s."branchId" = ? AND s.date >= ? AND s.date <= ?
             ORDER BY s.date, CASE s."shiftType" WHEN \'day\' THEN 0 WHEN \'full\' THEN 1 ELSE 2 END, e."sortOrder"',
            [$branch['id'], $from, $to],
        );
        $away = Db::all(
            'SELECT s.id, s.date, s."employeeId", s."shiftType", e.name AS "employeeName", b.name AS "atBranchName"
             FROM shifts s
             JOIN employees e ON e.id = s."employeeId"
             JOIN branches b ON b.id = s."branchId"
             WHERE e."homeBranchId" = ? AND s."branchId" != ? AND s.date >= ? AND s.date <= ?
             ORDER BY s.date',
            [$branch['id'], $branch['id'], $from, $to],
        );
        $byDate = [];
        for ($d = 1; $d <= $last; $d++) {
            $byDate[to_date_key($year, $month, $d)] = ['day' => $d, 'shifts' => [], 'away' => []];
        }
        $filterEmps = [];
        foreach (self::employees(true) as $e) {
            if ((int) $e['homeBranchId'] === $branch['id']) {
                $filterEmps[(int) $e['id']] = (string) $e['name'];
            }
        }
        foreach ($shifts as $s) {
            $k = substr((string) $s['date'], 0, 10);
            if (!isset($byDate[$k])) {
                continue;
            }
            $s['guestFromBranchName'] = (int) $s['homeBranchId'] === $branch['id'] ? null : $s['homeBranchName'];
            $byDate[$k]['shifts'][] = $s;
            $filterEmps[(int) $s['employeeId']] ??= (string) $s['employeeName'];
        }
        foreach ($away as $a) {
            $k = substr((string) $a['date'], 0, 10);
            if (isset($byDate[$k])) {
                $a['awayAtBranchName'] = $a['atBranchName'];
                $byDate[$k]['away'][] = $a;
            }
        }

        $half = [];
        foreach (self::employees() as $e) {
            $half[(int) $e['id']] = ['name' => (string) $e['name'], 'first' => 0, 'second' => 0, 'month' => 0];
        }
        foreach (Db::all('SELECT "employeeId", date, "shiftType" FROM shifts WHERE date >= ? AND date <= ?', [$from, $to]) as $r) {
            $id = (int) $r['employeeId'];
            if (!isset($half[$id])) {
                continue;
            }
            $w = shift_weight((string) $r['shiftType']);
            $half[$id][(int) substr((string) $r['date'], 8, 2) <= 15 ? 'first' : 'second'] += $w;
            $half[$id]['month'] += $w;
        }
        // Ночная смена после полуночи всё ещё идёт, хотя её дата уже «вчера» — такие смены и их день не гасим.
        $liveIds = [];
        foreach (self::liveShifts() as $ls) {
            $liveIds[(int) $ls['id']] = true;
        }

        $html = page_head('График смен', 'День 08:00–20:00, ночь 20:00–08:00, сутки 08:00–08:00 — по времени клуба.', self::branchSwitch('/schedule', $branch, ['m' => $monthParam]));

        $monthHref = static fn (?string $m): string => h(url_to('/schedule', ['branch' => $branch['slug'], 'm' => $m]));
        $html .= '<div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-3"><div class="flex items-center gap-1.5">';
        $html .= '<a class="' . btn_class('outline', 'icon-sm') . '" href="' . $monthHref($prevM) . '" aria-label="Предыдущий месяц">' . icon('chevronleft') . '</a>';
        $html .= '<h2 class="min-w-40 px-1 text-center font-display text-lg font-semibold tracking-tight">' . h(format_month_title($year, $month)) . '</h2>';
        $html .= '<a class="' . btn_class('outline', 'icon-sm') . '" href="' . $monthHref($nextM) . '" aria-label="Следующий месяц">' . icon('chevronright') . '</a>';
        if (!$isCurrentMonth) {
            $html .= '<a class="' . btn_class('quiet', 'sm') . '" href="' . $monthHref(null) . '">Сегодня</a>';
        }
        $html .= '</div>';
        if ($canEdit) {
            $html .= '<div class="flex flex-wrap items-center gap-2">';
            $html .= '<button type="button" class="' . btn_class('primary', 'sm') . '" data-paint-toggle aria-expanded="false" aria-controls="paint-panel">' . icon('pencil', 'size-4') . 'Быстрое заполнение</button>';
            $html .= '<button type="button" class="' . btn_class('outline', 'sm') . '" data-dialog-open="copy-week">' . icon('copy', 'size-4') . 'Повторить неделю</button></div>';
        }
        $html .= '</div>';
        if ($canEdit) {
            $html .= self::paintPanel($branch, $filterEmps);
        }

        if (count($filterEmps) > 1) {
            $html .= '<div class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 scrollbar-none sm:mx-0 sm:flex-wrap sm:px-0" role="group" aria-label="Показать смены сотрудника">';
            $html .= '<button type="button" class="filter-chip" data-emp-filter="" aria-pressed="true">Все</button>';
            if ($meId && isset($filterEmps[$meId])) {
                $html .= '<button type="button" class="filter-chip" data-emp-filter="' . $meId . '" aria-pressed="false">' . icon('user', 'size-3.5') . 'Мои смены</button>';
            }
            foreach ($filterEmps as $id => $n) {
                if ($id !== $meId) {
                    $html .= '<button type="button" class="filter-chip" data-emp-filter="' . $id . '" aria-pressed="false">' . h(short_name($n)) . '</button>';
                }
            }
            $html .= '</div>';
        }

        $lead = monday_first_weekday($year, $month, 1);
        $trail = (7 - ($lead + $last) % 7) % 7;
        $html .= '<section class="cal" aria-label="' . h('Смены · ' . format_month_title($year, $month) . ' · ' . $branch['name']) . '" data-month-grid data-year="' . $year . '" data-month="' . $month . '" data-last="' . $last . '" data-can-edit="' . ($canEdit ? '1' : '0') . '">';
        $html .= '<div class="cal-head" aria-hidden="true">';
        foreach (WEEKDAY_SHORT as $lab) {
            $html .= '<div>' . $lab . '</div>';
        }
        $html .= '</div><div class="cal-grid">' . str_repeat('<div class="cal-pad" aria-hidden="true"></div>', $lead);
        foreach ($byDate as $key => $cell) {
            $wd = weekday_from_date_key($key);
            $count = count($cell['shifts']);
            $hasDay = $hasNight = $hasLive = false;
            $items = '';
            foreach ($cell['shifts'] as $s) {
                $live = isset($liveIds[(int) $s['id']]);
                $hasLive = $hasLive || $live;
                $hasDay = $hasDay || $s['shiftType'] !== 'night';
                $hasNight = $hasNight || $s['shiftType'] !== 'day';
                $items .= self::calItem($s, $meId, $live);
            }
            foreach ($cell['away'] as $a) {
                $live = isset($liveIds[(int) $a['id']]);
                $hasLive = $hasLive || $live;
                $items .= self::calItem($a, $meId, $live, (string) $a['atBranchName']);
            }
            foreach (['day' => $hasDay, 'night' => $hasNight] as $slot => $taken) {
                $items .= '<span class="cal-slot' . ($taken ? ' hidden' : '') . '" data-slot-empty="' . $slot . '" aria-hidden="true">' . icon(SHIFT_ICONS[$slot], 'cal-chip-icon') . '—</span>';
            }
            $cls = 'cal-day' . ($hasLive ? ' has-live' : '') . ($wd >= 5 ? ' is-weekend' : '');
            if ($key === $today) {
                $cls .= ' is-today';
            } elseif ($key < $today && !$hasLive) {
                $cls .= ' is-past';
            }
            $label = format_date_human($key) . ($key === $today ? ', сегодня' : '') . ': ' . ($count ? plural($count, 'смена', 'смены', 'смен') : 'смен нет') . ($hasLive ? ', смена идёт сейчас' : '');
            $html .= '<div class="' . $cls . '" role="button" tabindex="0" data-date-key="' . h($key) . '" aria-label="' . h($label) . '">';
            $html .= '<div class="cal-date"><span class="cal-num">' . $cell['day'] . '</span><span class="cal-wd">' . h(mb_strtolower(WEEKDAY_SHORT[$wd])) . '</span></div>';
            $html .= '<div class="cal-shifts">' . $items . '</div></div>';
        }
        $html .= str_repeat('<div class="cal-pad" aria-hidden="true"></div>', $trail) . '</div>';
        $html .= '<div class="cal-legend" aria-label="Обозначения">';
        foreach (['day' => 'День', 'night' => 'Ночь', 'full' => 'Сутки'] as $tone => $lab) {
            $html .= '<span class="cal-legend-item"><span class="cal-key" data-tone="' . $tone . '" aria-hidden="true"></span>' . $lab . '</span>';
        }
        $html .= '<span class="cal-legend-item">' . icon('arrow-up-right', 'size-3.5') . 'Вышел в другом филиале</span>';
        $html .= '<span class="cal-legend-item"><span class="cal-chip-live"><span class="live-dot" aria-hidden="true"></span></span>Идёт сейчас</span></div></section>';

        $sumIds = array_values(array_filter(array_keys($filterEmps), static fn ($id) => isset($half[$id])));
        if ($sumIds) {
            $num = static fn (int $n): string => $n > 0 ? (string) $n : '<span class="text-muted-foreground">—</span>';
            $thc = 'px-3 py-2 text-right text-xs font-semibold tracking-wide whitespace-nowrap uppercase sm:px-4';
            $tdc = 'px-3 py-2.5 text-right tabular-nums sm:px-4';
            $total = ['first' => 0, 'second' => 0, 'month' => 0];
            $html .= '<section class="overflow-hidden rounded-xl border border-border bg-card shadow-xs" aria-labelledby="sum-title" data-summary>';
            $html .= '<div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-3 sm:px-5"><div class="flex min-w-0 flex-col gap-0.5"><h3 id="sum-title" class="text-sm font-semibold">Смены за ' . h(mb_strtolower(MONTH_NAMES[$month])) . '</h3>';
            $html .= '<p class="text-xs leading-relaxed text-pretty text-muted-foreground">Сутки считаются за две смены. В счёт идут и выходы в другом филиале.</p></div>';
            $html .= '<p class="hidden text-xs text-muted-foreground lg:block" data-range-hint>Протяните мышью по дням, чтобы посчитать смены за период</p>';
            $html .= '<div class="flex items-center gap-2 text-sm" data-range-info hidden><span class="text-muted-foreground">Период</span><span class="font-semibold" data-range-label></span><button type="button" class="' . btn_class('outline', 'xs') . '" data-range-clear>Сбросить</button></div></div>';
            $html .= '<div class="overflow-x-auto border-t border-border"><table class="w-full text-sm"><thead class="bg-muted/50 text-muted-foreground"><tr><th scope="col" class="px-4 py-2 text-left text-xs font-semibold tracking-wide uppercase sm:px-5">Сотрудник</th>';
            $html .= '<th scope="col" class="' . $thc . '">1–15</th><th scope="col" class="' . $thc . '">16–' . $last . '</th><th scope="col" class="' . $thc . '">Месяц</th><th scope="col" class="' . $thc . ' text-primary-ink" data-range-col hidden>Период</th></tr></thead><tbody>';
            foreach ($sumIds as $id) {
                $r = $half[$id];
                foreach ($total as $k => $v) {
                    $total[$k] = $v + (int) $r[$k];
                }
                $html .= '<tr class="sum-row border-t border-border' . ($id === $meId ? ' is-mine' : '') . '" data-sum-emp="' . $id . '"><th scope="row" class="px-4 py-2.5 text-left font-medium sm:px-5"><span class="flex items-center gap-2"><span class="min-w-0 truncate">' . h((string) $r['name']) . '</span>' . ($id === $meId ? chip('вы', 'red') : '') . '</span></th>';
                $html .= '<td class="' . $tdc . '">' . $num((int) $r['first']) . '</td><td class="' . $tdc . '">' . $num((int) $r['second']) . '</td><td class="' . $tdc . ' font-semibold">' . $num((int) $r['month']) . '</td>';
                $html .= '<td class="' . $tdc . ' font-semibold text-primary-ink" data-range-cell hidden>0</td></tr>';
            }
            $html .= '</tbody><tfoot><tr class="border-t border-border bg-muted/40 font-semibold"><th scope="row" class="px-4 py-2.5 text-left sm:px-5">Всего</th>';
            $html .= '<td class="' . $tdc . '">' . $num($total['first']) . '</td><td class="' . $tdc . '">' . $num($total['second']) . '</td><td class="' . $tdc . '">' . $num($total['month']) . '</td>';
            $html .= '<td class="' . $tdc . ' text-primary-ink" data-range-total hidden>0</td></tr></tfoot></table></div></section>';
        }

        $body = '<div class="flex flex-col gap-2" data-existing></div>';
        if ($canEdit) {
            $opts = ['0' => '— никого —'] + self::employeeOptions(null, $branch['id']);
            $body .= form_open('save_day', 'class="flex flex-col gap-4 border-t border-border pt-4" data-day-form') . hidden('branchId', $branch['id']) . '<input type="hidden" name="date" value="" data-dialog-date>';
            $body .= '<p class="text-sm font-semibold">Поставить на этот день · ' . h($branch['name']) . '</p>';
            $body .= '<div class="grid gap-3 sm:grid-cols-2">';
            // ui_field() экранирует подпись, поэтому подпись с иконкой собираем здесь.
            foreach ([['day', 'sun', 'text-day-strong', 'Днём · 08–20', 'dayEmployeeId'], ['night', 'moon', 'text-night-strong', 'Ночью · 20–08', 'nightEmployeeId']] as [$slot, $ic, $tone, $text, $name]) {
                $body .= '<label class="flex min-w-0 flex-col gap-1.5"><span class="inline-flex items-center gap-1.5 text-sm font-medium text-foreground">' . icon($ic, 'size-4 ' . $tone) . h($text) . '</span>' . ui_select($name, $opts, '0', 'data-day-slot="' . $slot . '"') . '</label>';
            }
            $body .= '</div><p class="text-xs leading-relaxed text-muted-foreground">Один и тот же сотрудник днём и ночью — встанет на сутки. Сотрудник другого филиала встанет как подмена.</p>';
            $body .= '<div class="flex flex-wrap justify-end gap-2"><button type="button" class="' . btn_class('ghost') . '" data-dialog-close>Отмена</button><button class="' . btn_class() . '">' . icon('check', 'size-4') . 'Сохранить день</button></div></form>';
        }
        $html .= dialog_html('day-dialog', 'Смены дня', $body, 'max-w-md', 'data-day-dialog');

        if ($canEdit) {
            $weekStart = $isCurrentMonth ? monday_key($now) : monday_key($first);
            $cw = form_open('copy_week', 'class="flex flex-col gap-4"') . hidden('branchId', $branch['id']);
            $cw .= '<p class="text-sm leading-relaxed text-muted-foreground">Смены филиала «' . h($branch['name']) . '» за выбранную неделю встанут на другую неделю в те же дни. Если сотрудник в этот день уже в графике, его смена пропускается.</p>';
            $cw .= '<div class="grid gap-3 sm:grid-cols-2">' . ui_field('Откуда — любой день недели', '<input type="date" name="from" class="' . field_class() . '" value="' . h(add_days($weekStart, -7)) . '" required>');
            $cw .= ui_field('Куда — любой день недели', '<input type="date" name="to" class="' . field_class() . '" value="' . h($weekStart) . '" required>') . '</div>';
            $cw .= '<div class="flex flex-wrap justify-end gap-2"><button type="button" class="' . btn_class('ghost') . '" data-dialog-close>Отмена</button><button class="' . btn_class() . '">' . icon('copy', 'size-4') . 'Скопировать</button></div></form>';
            $html .= dialog_html('copy-week', 'Повторить неделю', $cw, 'max-w-lg');
        }
        self::layout($user, $html, ['title' => 'График смен', 'width' => 'wide', 'scripts' => ['schedule']]);
    }

    /** Смена в ячейке календаря; data-атрибуты читает schedule.js (фильтр, подсчёт периода, окно дня). */
    private static function calItem(array $s, int $meId, bool $live, ?string $awayAt = null): string
    {
        $type = (string) $s['shiftType'];
        $attrs = ' data-emp="' . (int) $s['employeeId'] . '" data-emp-name="' . h((string) $s['employeeName']) . '" data-shift-id="' . (int) $s['id'] . '" data-weight="' . shift_weight($type) . '" data-type="' . h($type) . '"';
        if ($awayAt !== null) {
            $attrs .= ' data-away="' . h($awayAt) . '"';
        }
        return '<div class="cal-item"' . $attrs . ($live ? ' data-live' : '') . '>' . shift_chip($s, ['me' => $meId, 'live' => $live]) . '</div>';
    }

    /** Панель «кисти»: выбрал сотрудника и смену — тыкаешь по дням, всё сохраняется о��ной кнопкой. */
    private static function paintPanel(array $branch, array $emps): string
    {
        // На телефоне и планшете сверху закреплена шапка высотой 3.5rem — панель встаёт под неё, а не за неё.
        $html = '<section id="paint-panel" class="sticky top-16 z-20 flex flex-col gap-3 rounded-xl border border-primary/40 bg-card p-4 shadow-lg lg:top-2" data-paint-panel hidden aria-label="Быстрое заполнение графика">';
        $html .= '<div class="flex flex-wrap items-start justify-between gap-2"><div class="flex flex-col gap-0.5"><h3 class="text-sm font-semibold">Быстрое заполнение</h3>';
        $html .= '<p class="text-xs leading-relaxed text-pretty text-muted-foreground">Выберите сотрудника и смену, затем нажимайте на дни. Повторное нажатие убирает смену. Сохраняется всё разом.</p></div>';
        $html .= '<button type="button" class="' . btn_class('quiet', 'icon-sm') . '" data-paint-cancel aria-label="Закрыть без сохранения">' . icon('x', 'size-4') . '</button></div>';

        $html .= '<div class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 scrollbar-none sm:mx-0 sm:flex-wrap sm:px-0" role="group" aria-label="Сотрудник">';
        $first = true;
        foreach ($emps as $id => $name) {
            $html .= '<button type="button" class="filter-chip paint-emp" data-paint-emp="' . (int) $id . '" data-name="' . h($name) . '" data-short="' . h(short_name($name)) . '" aria-pressed="' . ($first ? 'true' : 'false') . '">' . h(short_name($name)) . '</button>';
            $first = false;
        }
        $others = self::employeeOptions(null, $branch['id']);
        unset($others[$branch['name']]);
        if ($others) {
            $html .= '<select class="' . h(select_class('h-8 max-w-44 shrink-0 text-xs sm:text-xs')) . '" data-paint-other aria-label="Сотрудник другого филиала"><option value="">Другой филиал…</option>';
            foreach ($others as $group => $list) {
                if (!is_array($list)) {
                    continue;
                }
                $html .= '<optgroup label="' . h((string) $group) . '">';
                foreach ($list as $v => $l) {
                    $html .= '<option value="' . (int) $v . '" data-name="' . h((string) $l) . '" data-short="' . h(short_name((string) $l)) . '">' . h((string) $l) . '</option>';
                }
                $html .= '</optgroup>';
            }
            $html .= '</select>';
        }
        $html .= '</div>';

        $html .= '<div class="flex flex-wrap items-center justify-between gap-3"><div class="flex gap-2" role="group" aria-label="Смена">';
        foreach (SHIFT_TYPES as $i => $t) {
            $html .= '<button type="button" class="filter-chip paint-type" data-paint-type="' . $t . '" data-hint="' . h(SHIFT_LABELS[$t] . ' · ' . SHIFT_HINTS[$t]) . '" aria-pressed="' . ($i === 0 ? 'true' : 'false') . '">' . icon(SHIFT_ICONS[$t], 'size-3.5') . h(SHIFT_LABELS[$t]) . '</button>';
        }
        $html .= '</div>' . form_open('save_shifts_bulk', 'class="flex items-center gap-3" data-paint-form') . hidden('branchId', $branch['id']) . '<input type="hidden" name="ops" value="" data-paint-ops>';
        $html .= '<span class="text-xs text-muted-foreground tabular-nums" data-paint-count aria-live="polite">Изменений нет</span>';
        $html .= '<button class="' . btn_class('primary', 'sm') . '" data-paint-save disabled>' . icon('check', 'size-4') . 'Сохранить</button></form></div>';
        return $html . '</section>';
    }

    /* ---------------- Чистка ---------------- */

    private static function pageCleaning(array $user): void
    {
        $branch = self::pickBranch($user);
        if (!$branch) {
            self::layout($user, empty_state('building', 'Филиалы ещё не добавлены'), ['title' => 'Чистка клавиатур']);
            return;
        }
        $now = now_tyumen();
        $thisWeek = monday_key($now);
        $w = self::getStr('w', 10);
        $week = is_date_key($w) ? monday_key(date_from_key($w)) : $thisWeek;
        $pc = max(1, (int) $branch['pcCount']);
        $canAssign = can($user, 'cleaning.assign', $branch['id']);
        $meId = (int) ($user['employeeId'] ?? 0);
        $done = [];
        foreach (Db::all('SELECT * FROM keyboard_cleanings WHERE "branchId" = ? AND "weekStart" = ?', [$branch['id'], $week]) as $m) {
            $done[(int) $m['pcNumber']] = $m;
        }
        $bySeat = [];
        foreach (Db::all('SELECT a.*, e.name AS "employeeName" FROM cleaning_seat_assignments a JOIN employees e ON e.id = a."employeeId" WHERE a."branchId" = ? AND a."weekStart" = ?', [$branch['id'], $week]) as $a) {
            $bySeat[(int) $a['pcNumber']] = $a;
        }
        $doneCount = count(array_filter(array_keys($done), static fn ($n) => $n <= $pc));
        $myTotal = 0;
        $myDone = 0;
        foreach ($bySeat as $n => $a) {
            if ((int) $a['employeeId'] === $meId) {
                $myTotal++;
                if (isset($done[$n])) {
                    $myDone++;
                }
            }
        }

        $html = page_head('Чистка клавиатур', 'Каждое место чистится раз в неделю. Нажми��е на место после чистки — отметка сохранится за вами.', self::branchSwitch('/cleaning', $branch, ['w' => $week]));
        $html .= '<div class="flex flex-wrap items-center justify-between gap-3"><div class="flex items-center gap-2">';
        $html .= '<a class="' . btn_class('outline', 'icon-sm') . '" href="' . h(url_to('/cleaning', ['branch' => $branch['slug'], 'w' => add_days($week, -7)])) . '" aria-label="Предыдущая неделя">' . icon('chevronleft') . '</a>';
        $html .= '<h2 class="min-w-44 text-center font-display text-lg font-semibold tracking-tight">' . h(week_label($week)) . '</h2>';
        $html .= '<a class="' . btn_class('outline', 'icon-sm') . '" href="' . h(url_to('/cleaning', ['branch' => $branch['slug'], 'w' => add_days($week, 7)])) . '" aria-label="Следующая неделя">' . icon('chevronright') . '</a>';
        if ($week !== $thisWeek) {
            $html .= '<a class="' . btn_class('quiet', 'sm') . '" href="' . h(url_to('/cleaning', ['branch' => $branch['slug']])) . '">Эта неделя</a>';
        }
        $html .= '</div>';
        $html .= '<div class="flex min-w-56 flex-1 flex-col gap-1.5 sm:max-w-xs"><div class="flex justify-between text-xs"><span class="font-semibold">Почищено ' . $doneCount . ' из ' . $pc . '</span><span class="text-muted-foreground tabular-nums">' . (int) round($doneCount / $pc * 100) . '%</span></div>' . progress_bar((int) round($doneCount / $pc * 100), 'Прогресс чистки') . '</div></div>';

        if ($myTotal > 0) {
            $html .= '<p class="flex items-center gap-2 rounded-md border border-primary/30 bg-primary/5 px-3 py-2 text-sm">' . icon('keyboard', 'size-4 text-primary') . 'За вами ' . h(plural($myTotal, 'место', 'места', 'мест')) . ' на этой неделе, готово ' . $myDone . '.</p>';
        }
        if ($canAssign) {
            $html .= '<section class="flex flex-col gap-3 rounded-lg border border-border bg-card p-4 shadow-xs sm:flex-row sm:items-end">';
            $html .= ui_field('Распределить места', ui_select('', ['' => 'Не распределять — только отметки'] + self::employeeOptions(null, $branch['id']), null, 'data-assign-emp aria-describedby="assign-hint"'), '', 'flex-1 sm:max-w-sm');
            $html .= '<p id="assign-hint" class="flex-1 text-xs leading-relaxed text-muted-foreground">Выберите сотрудника и нажимайте на места — они закрепятся за ним. Повторное нажатие снимает закрепление.</p>';
            $html .= '<button type="button" class="' . btn_class('outline', 'sm') . ' hidden" data-assign-done>' . icon('check', 'size-4') . 'Готово</button></section>';
        }

        $html .= '<div class="grid grid-cols-4 gap-2 sm:grid-cols-6 md:grid-cols-8 xl:grid-cols-10" data-keep-scroll>';
        for ($n = 1; $n <= $pc; $n++) {
            $mark = $done[$n] ?? null;
            $asg = $bySeat[$n] ?? null;
            $isMine = $asg && (int) $asg['employeeId'] === $meId;
            $canToggle = $canAssign || !$asg || $isMine;
            if ($mark) {
                $state = 'done';
                $sub = short_name((string) $mark['doneByName']);
            } elseif ($asg) {
                $state = $isMine ? 'mine' : 'taken';
                $sub = $isMine ? 'ваше' : short_name((string) $asg['employeeName']);
            } else {
                $state = 'free';
                $sub = 'свободно';
            }
            $title = 'Место ' . $n . ($mark ? ' — почищено: ' . $mark['doneByName'] . ', ' . format_dt((string) $mark['doneAt']) : '') . ($asg ? ' · закреплено за ' . $asg['employeeName'] : '');
            $inner = '<span class="keycap-num">' . $n . '</span><span class="keycap-sub">' . ($state === 'done' ? icon('check', 'size-3') : '') . h($sub) . '</span>';
            if ($canToggle) {
                $html .= form_open('toggle_clean', 'data-clean-form') . hidden('branchId', $branch['id']) . hidden('weekStart', $week) . hidden('pcNumber', $n) . '<input type="hidden" name="employeeId" value="" data-assign-id>';
                $html .= '<button class="keycap" data-state="' . $state . '" title="' . h($title) . '" aria-label="' . h($title) . '">' . $inner . '</button></form>';
            } else {
                $html .= '<div class="keycap" data-state="' . $state . '" aria-disabled="true" title="' . h($title) . '">' . $inner . '</div>';
            }
        }
        $html .= '</div>';
        $html .= '<div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-muted-foreground" aria-label="Обозначения"><span class="flex items-center gap-2"><span class="keycap-dot" data-state="done"></span>почищено</span><span class="flex items-center gap-2"><span class="keycap-dot" data-state="mine"></span>ваше место</span><span class="flex items-center gap-2"><span class="keycap-dot" data-state="taken"></span>закреплено за другим</span><span class="flex items-center gap-2"><span class="keycap-dot" data-state="free"></span>свободное — может отметить любой</span></div>';
        self::layout($user, $html, ['title' => 'Чистка клавиатур', 'width' => 'wide']);
    }

    /* ---------------- Задания на смены ---------------- */

    private static function pageTasks(array $user): void
    {
        $now = now_tyumen();
        $today = date_key($now);
        $w = self::getStr('w', 10);
        $week = is_date_key($w) ? monday_key(date_from_key($w)) : monday_key($now);
        $weekEnd = add_days($week, 6);
        $days = dates_between($week, $weekEnd, 7);
        $scope = perm_scope($user, 'tasks.assign');
        $preEmp = (int) ($_GET['employeeId'] ?? 0);
        $f = self::getStr('from', 10);
        $preFrom = is_date_key($f) ? $f : $today;
        $t = self::getStr('to', 10);
        $preTo = is_date_key($t) ? $t : $preFrom;

        $emps = array_values(array_filter(self::employees(true), static fn ($e) => $scope === null || (int) $e['homeBranchId'] === $scope));
        $shiftMap = [];
        foreach (Db::all('SELECT "employeeId", date, "shiftType", "branchId" FROM shifts WHERE date >= ? AND date <= ?', [$week, $weekEnd]) as $s) {
            $shiftMap[(int) $s['employeeId'] . '|' . substr((string) $s['date'], 0, 10)] = $s;
        }
        $taskMap = [];
        foreach (Db::all(
            'SELECT t.*, (SELECT d."completedAt" FROM staff_task_done d WHERE d."taskId" = t.id LIMIT 1) AS done_at
             FROM staff_tasks t WHERE t.date >= ? AND t.date <= ? ORDER BY t.id',
            [$week, $weekEnd],
        ) as $task) {
            $taskMap[(int) $task['employeeId'] . '|' . substr((string) $task['date'], 0, 10)][] = $task;
        }
        $templates = array_values(array_filter(
            Db::all('SELECT id, weekday, title, description FROM daily_tasks WHERE "isActive" = 1 ORDER BY weekday, "sortOrder", id'),
            static fn ($r) => mb_strlen(trim((string) $r['title'])) >= 4,
        ));

        $lead = 'Задание видно сотруднику на странице «Мой день» в его смену. Ночная смена после полуночи продолжает видеть задание своей даты.';
        if ($scope !== null) {
            $lead .= ' Вы назначаете задания сотрудникам филиала ' . h(self::branchName($scope)) . '.';
        }
        $html = page_head('Задания на смены', $lead);
        $html .= '<div class="grid items-start gap-6 xl:grid-cols-[22rem_minmax(0,1fr)]">';

        $form = form_open('assign_staff_task', 'id="assign" class="flex flex-col gap-4 scroll-mt-24"') . hidden('week', $week);
        $form .= '<h2 class="font-display text-base font-semibold">Назначить задание</h2>';
        $form .= '<fieldset class="flex flex-col gap-2"><legend class="mb-1 text-sm font-medium">Кому</legend>';
        $form .= '<label class="flex items-center gap-2 text-sm"><input type="radio" name="who" value="picked" class="' . check_class() . '" checked data-who> Выбранным сотрудникам</label>';
        $form .= '<div class="flex flex-wrap gap-1.5 pl-6" data-who-picked>';
        foreach ($emps as $e) {
            $chk = $preEmp === (int) $e['id'] ? ' checked' : '';
            $form .= '<label class="pill-check"><input type="checkbox" name="employees[]" value="' . (int) $e['id'] . '"' . $chk . ' class="peer sr-only"><span>' . h(short_name((string) $e['name'])) . '</span></label>';
        }
        $form .= '</div>';
        $form .= '<label class="flex items-start gap-2 text-sm"><input type="radio" name="who" value="day" class="mt-0.5 ' . check_class() . '" data-who> <span>Всем дневным по графику <span class="text-muted-foreground">(день и сут��и)</span></span></label>';
        $form .= '<label class="flex items-start gap-2 text-sm"><input type="radio" name="who" value="night" class="mt-0.5 ' . check_class() . '" data-who> <span>Всем ночным по графику <span class="text-muted-foreground">(ночь и сутки)</span></span></label></fieldset>';
        $form .= '<div class="grid grid-cols-2 gap-3">' . ui_field('С даты', '<input type="date" name="from" class="' . field_class() . '" value="' . h($preFrom) . '" required>') . ui_field('По дату', '<input type="date" name="to" class="' . field_class() . '" value="' . h($preTo) . '" required>') . '</div>';
        if ($templates) {
            $opts = ['' => 'Выбрать шаблон…'];
            foreach ($templates as $tpl) {
                $opts[(string) $tpl['id']] = WEEKDAY_SHORT[(int) $tpl['weekday'] % 7] . ' · ' . mb_substr((string) $tpl['title'], 0, 60);
            }
            $form .= ui_field('Шаблон из «Задания дня»', ui_select('', $opts, '', 'data-task-template'), 'Заполнит название и описание — их можно поправить.');
            $tplJson = [];
            foreach ($templates as $tpl) {
                $tplJson[(string) $tpl['id']] = ['title' => (string) $tpl['title'], 'description' => (string) $tpl['description']];
            }
            $form .= '<script type="application/json" data-task-templates>' . json_encode($tplJson, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
        }
        $form .= ui_field('Задание', '<input name="title" class="' . field_class() . '" required maxlength="200" placeholder="Что сделать на смене" data-task-title>');
        $form .= ui_field('Подробности', '<textarea name="description" class="' . textarea_class('min-h-20') . '" maxlength="2000" placeholder="Необязательно" data-task-desc></textarea>');
        $form .= '<button class="' . btn_class() . '">' . icon('send', 'size-4') . 'Назначить</button></form>';
        $html .= card($form, 'p-5 xl:sticky xl:top-8');

        $nav = '<div class="flex flex-wrap items-center justify-between gap-3"><div class="flex items-center gap-2">';
        $nav .= '<a class="' . btn_class('outline', 'icon-sm') . '" href="' . h(url_to('/manage/tasks', ['w' => add_days($week, -7)])) . '" aria-label="Предыдущая неделя">' . icon('chevronleft') . '</a>';
        $nav .= '<h2 class="min-w-44 text-center font-display text-base font-semibold">' . h(week_label($week)) . '</h2>';
        $nav .= '<a class="' . btn_class('outline', 'icon-sm') . '" href="' . h(url_to('/manage/tasks', ['w' => add_days($week, 7)])) . '" aria-label="Следующая неделя">' . icon('chevronright') . '</a></div>';
        $nav .= '<p class="text-xs text-muted-foreground">Нажмите «+» в ячейке, чтобы заполнить форму на этот день</p></div>';

        $table = '<div class="hidden overflow-x-auto rounded-lg border border-border bg-card shadow-xs md:block"><table class="w-full min-w-[48rem] text-sm"><thead class="bg-muted/50"><tr><th scope="col" class="w-44 px-3 py-2.5 text-left text-xs font-semibold tracking-wide text-muted-foreground uppercase">Сотрудник</th>';
        foreach ($days as $d) {
            $isToday = $d === $today;
            $table .= '<th scope="col" class="px-2 py-2.5 text-center text-xs font-semibold uppercase ' . ($isToday ? 'text-primary-ink' : 'text-muted-foreground') . '">' . h(WEEKDAY_SHORT[weekday_from_date_key($d)]) . '<span class="block font-display text-sm text-foreground tabular-nums">' . (int) substr($d, 8, 2) . '</span></th>';
        }
        $table .= '</tr></thead><tbody>';
        $cards = '<div class="flex flex-col gap-3 md:hidden">';
        foreach ($emps as $e) {
            $eid = (int) $e['id'];
            $table .= '<tr class="border-t border-border align-top"><th scope="row" class="px-3 py-3 text-left font-semibold"><span class="block truncate">' . h(short_name((string) $e['name'])) . '</span><span class="text-xs font-normal text-muted-foreground">' . h(self::branchName((int) $e['homeBranchId'])) . '</span></th>';
            $cards .= '<article class="rounded-lg border border-border bg-card p-4 shadow-xs"><h3 class="font-semibold">' . h($e['name']) . ' <span class="font-normal text-muted-foreground">· ' . h(self::branchName((int) $e['homeBranchId'])) . '</span></h3><div class="mt-3 flex flex-col divide-y divide-border">';
            foreach ($days as $d) {
                $key = $eid . '|' . $d;
                $shift = $shiftMap[$key] ?? null;
                $items = $taskMap[$key] ?? [];
                $href = url_to('/manage/tasks', ['w' => $week, 'employeeId' => $eid, 'from' => $d, 'to' => $d]) . '#assign';
                $cell = '';
                if ($shift) {
                    $type = (string) $shift['shiftType'];
                    $cell .= '<span class="mb-1.5 inline-flex items-center gap-1 rounded-[4px] px-1.5 py-px text-[10px] font-bold uppercase ' . shift_tone_class($type) . '">' . icon(SHIFT_ICONS[$type], 'size-3') . h(SHIFT_LABELS[$type]) . '</span>';
                }
                foreach ($items as $task) {
                    $ok = !empty($task['done_at']);
                    $cell .= '<div class="mb-1 flex items-start gap-1 rounded-md border border-border bg-background px-1.5 py-1 text-xs leading-snug">';
                    $cell .= '<span class="mt-px shrink-0 ' . ($ok ? 'text-foreground' : 'text-muted-foreground') . '" title="' . ($ok ? 'Выполнено' : 'Не выполнено') . '">' . icon($ok ? 'check-circle' : 'clock', 'size-3.5') . '</span>';
                    $cell .= '<span class="min-w-0 flex-1 break-words ' . ($ok ? 'text-muted-foreground line-through' : '') . '">' . h($task['title']) . '</span>';
                    $cell .= form_open('delete_staff_task', 'class="shrink-0" ' . confirm_attr('Снять задание «' . $task['title'] . '»?')) . hidden('id', (int) $task['id']) . hidden('week', $week) . '<button class="text-muted-foreground hover:text-primary-ink" aria-label="Снять задание">' . icon('x', 'size-3.5') . '</button></form></div>';
                }
                $cell .= '<a class="inline-flex items-center gap-0.5 rounded-sm text-[11px] font-medium text-muted-foreground hover:text-foreground" href="' . h($href) . '" data-fill-task data-emp="' . $eid . '" data-date="' . h($d) . '">' . icon('plus', 'size-3') . '<span class="sr-only">Назначить на ' . h(format_day_month($d)) . '</span></a>';
                $table .= '<td class="border-l border-border px-1.5 py-2 ' . ($d === $today ? 'bg-primary/5' : '') . '">' . $cell . '</td>';
                $cards .= '<div class="flex flex-col gap-1.5 py-2.5 first:pt-0 last:pb-0' . ($d === $today ? ' rounded-md bg-primary/5 px-2' : '') . '"><div class="flex items-center justify-between gap-2 text-xs font-semibold capitalize"><span>' . h(format_day_short($d)) . '</span>' . ($shift ? '' : '<span class="font-medium normal-case text-muted-foreground">нет в графике</span>') . '</div>' . $cell . '</div>';
            }
            $table .= '</tr>';
            $cards .= '</div></article>';
        }
        $table .= '</tbody></table></div>';
        $cards .= '</div>';
        if (!$emps) {
            $table = empty_state('users', 'Нет сотрудников', 'Добавьте сотрудников в разделе «Сотрудники».');
            $cards = '';
        }
        $html .= '<div class="flex min-w-0 flex-col gap-4">' . $nav . $table . $cards . '</div></div>';
        self::layout($user, $html, ['title' => 'Задания на смены', 'width' => 'wide']);
    }
}
