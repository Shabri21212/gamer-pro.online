<?php
declare(strict_types=1);

require_once __DIR__ . '/bar-workspace.php';

trait PagesBar
{
    use BarWorkspace;
    private static function barBranch(array $user): ?array
    {
        $enabled = Langame::enabledBranchIds();
        if (!$enabled) {
            return null;
        }
        $slug = (string) ($_GET['branch'] ?? '');
        $pick = null;
        foreach (self::branches() as $b) {
            if (!in_array($b['id'], $enabled, true)) {
                continue;
            }
            if ($slug !== '' && $b['slug'] === $slug) {
                return $b;
            }
            if ($pick === null || ($user['homeBranchId'] !== null && (int) $user['homeBranchId'] === $b['id'])) {
                $pick = $b;
            }
        }
        return $pick;
    }

    private static function pageBar(array $user): void
    {
        $branch = self::barBranch($user);
        if (can($user, 'staff.manage') && self::getStr('view', 8) !== 'me') {
            foreach (self::branches() as $candidate) {
                if ($branch === null || $candidate['slug'] === self::getStr('branch', 80)) {
                    $branch = $candidate;
                }
            }
            if ($branch) {
                Langame::maybeSync((int) $branch['id']);
                self::pageBarTeam($user, $branch, Langame::settings((int) $branch['id']) ?? [], self::monthFromQuery());
                return;
            }
        }
        if (!$branch) {
            $html = page_head('Продажи бара', 'Здесь будет видно, сколько вы продали в баре и сколько из этого ваш процент.');
            $action = $user['isOwner'] ? '<a class="' . btn_class() . '" href="' . h(url_to('/manage/langame')) . '">' . icon('link', 'size-4') . 'Подключить Langame</a>' : '';
            $html .= empty_state('coins', 'Langame пока не подключён', $user['isOwner'] ? 'Вставьте серверный токен Langame для филиала — продажи начнут подтягиваться автоматически.' : 'Как только владелец подключит Langame, здесь появятся ваши продажи.', $action);
            self::layout($user, $html, ['title' => 'Продажи бара']);
            return;
        }

        Langame::maybeSync($branch['id']);
        $s = Langame::settings($branch['id']) ?? [];
        $month = self::monthFromQuery();
        $today = now_tyumen()->format('Y-m-d');
        $eid = $user['employeeId'] !== null ? (int) $user['employeeId'] : 0;
        $totals = Langame::totalsByEmployee($month['from'], $month['to'], $branch['id']);
        $todayTotals = $month['isCurrent'] ? Langame::totalsByEmployee($today, $today, $branch['id']) : [];
        $linked = $eid > 0 && (bool) Db::value('SELECT 1 FROM langame_staff WHERE "branchId" = ? AND "employeeId" = ?', [$branch['id'], $eid]);
        $mine = $totals[$eid] ?? ['revenue' => 0, 'qty' => 0, 'lines' => 0, 'earn' => 0, 'percentX10' => (int) ($s['barPercentX10'] ?? 0)];
        if (!isset($totals[$eid]) && $linked) {
            $own = Db::value('SELECT "percentX10" FROM langame_staff WHERE "branchId" = ? AND "employeeId" = ?', [$branch['id'], $eid]);
            if ($own !== null) {
                $mine['percentX10'] = (int) $own;
            }
        }
        $mineToday = $todayTotals[$eid] ?? null;
        $seeAll = can($user, 'staff.manage');

        $enabledIds = Langame::enabledBranchIds();
        $branchTabs = '';
        if (count($enabledIds) > 1) {
            $items = [];
            foreach (self::branches() as $b) {
                if (in_array($b['id'], $enabledIds, true)) {
                    $items[] = ['href' => url_to('/bar', ['branch' => $b['slug'], 'm' => $month['key'] ?? null, 'view' => 'me']), 'label' => $b['name'], 'active' => $b['id'] === $branch['id']];
                }
            }
            $branchTabs = segmented($items, 'Филиал');
        }

        if ($seeAll) {
            $branchTabs = self::barViewSwitch($branch, $month, 'me') . $branchTabs;
        }
        $html = page_head('Продажи бара', 'Всё, что вы пробили в Langame на своей смене, — и ваш процент с этого. Обновляется само каждые ' . (Langame::AUTO_SYNC_SECONDS / 60) . ' минуты.', $branchTabs, $branch['name']);

        $hero = '<section class="relative isolate overflow-hidden hero-panel rounded-xl text-sidebar-foreground shadow-sm">';
        $hero .= '<img src="' . h(asset('img/club/lounge.webp')) . '" srcset="' . h(asset('img/club/lounge-sm.webp')) . ' 800w, ' . h(asset('img/club/lounge.webp')) . ' 1600w" sizes="(min-width: 1024px) 60vw, 100vw" alt="" aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10 size-full object-cover opacity-35 [mask-image:linear-gradient(to_left,black_10%,transparent_85%)]">';
        $hero .= '<div class="flex flex-col gap-5 p-5 sm:p-7">';
        $hero .= '<div class="flex flex-wrap items-center justify-between gap-3"><p class="text-[11px] font-semibold tracking-[0.18em] text-sidebar-foreground/60 uppercase">Процент по продажам · ' . h($month['name']) . '</p>';
        $hero .= '<span class="inline-flex items-center gap-1.5 rounded-full border border-sidebar-foreground/15 px-2.5 py-1 text-xs font-medium tabular-nums">' . icon('percent', 'size-3.5') . h(pct_label($mine['percentX10'])) . ' с продаж</span></div>';
        if ($linked || $mine['revenue'] > 0) {
            $hero .= '<div class="flex flex-col gap-1"><p class="font-display text-5xl leading-none font-semibold tracking-tight tabular-nums sm:text-6xl">' . h(kop_rub($mine['earn'])) . '</p>';
            $hero .= '<p class="text-sm text-sidebar-foreground/70">с продаж на <span class="font-semibold text-sidebar-foreground tabular-nums">' . h(kop_rub($mine['revenue'])) . '</span> · ' . h(plural($mine['qty'], 'позиция', 'позиции', 'позиций')) . '</p></div>';
            if ($month['isCurrent']) {
                $hero .= '<div class="flex flex-wrap items-center gap-x-6 gap-y-2 border-t border-sidebar-foreground/10 pt-4 text-sm">';
                $hero .= '<span class="flex items-baseline gap-2"><span class="text-sidebar-foreground/60">Сегодня</span><span class="font-display text-lg font-semibold tabular-nums">' . h(kop_rub($mineToday['revenue'] ?? 0)) . '</span></span>';
                $hero .= '<span class="flex items-baseline gap-2"><span class="text-sidebar-foreground/60">ваши</span><span class="font-display text-lg font-semibold text-primary tabular-nums">+' . h(kop_rub($mineToday['earn'] ?? 0)) . '</span></span></div>';
            }
        } else {
            $hero .= '<div class="flex max-w-md flex-col gap-2"><p class="font-display text-2xl font-semibold text-balance">Учётка Langame ещё не привязана</p>';
            $hero .= '<p class="text-sm leading-relaxed text-sidebar-foreground/70 text-pretty">Попросите владельца связать вашу запись в портале с вашим логином в админке Langame — после этого здесь появятся ваши продажи.</p></div>';
        }
        $hero .= '<p class="text-sm leading-relaxed text-sidebar-foreground/70">Это процент по всем продажам выбранного месяца. В зарплату входят продажи с 16-го числа. <a class="font-semibold underline underline-offset-4" href="' . h(url_to('/salary', ['m' => $month['key']])) . '">Открыть расчёт зарплаты</a></p>';
        $hero .= '</div></section>';

        $nav = '<div class="flex items-center gap-1"><a class="' . btn_class('outline', 'icon-sm') . '" href="' . h(url_to('/bar', ['branch' => $branch['slug'], 'm' => $month['prev'], 'view' => 'me'])) . '" aria-label="Предыдущий месяц">' . icon('chevronleft') . '</a>';
        $nav .= '<span class="min-w-32 text-center font-display text-sm font-semibold">' . h($month['title']) . '</span>';
        $nav .= '<a class="' . btn_class('outline', 'icon-sm') . '" href="' . h(url_to('/bar', ['branch' => $branch['slug'], 'm' => $month['next'], 'view' => 'me'])) . '" aria-label="Следующ��й месяц">' . icon('chevronright') . '</a></div>';

        $sync = '<div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-card px-4 py-3 text-sm shadow-xs">';
        $ok = boolish($s['lastSyncOk'] ?? 0);
        $sync .= '<span class="flex items-center gap-2 text-muted-foreground"><span class="size-2 rounded-full ' . ($ok ? 'bg-foreground/60' : 'bg-primary') . '" aria-hidden="true"></span>';
        $sync .= $ok ? 'Обновлено ' . h(format_dt((string) ($s['lastSyncAt'] ?? ''))) : 'Последнее обновление не прошло' . ($user['isOwner'] && !empty($s['lastError']) ? ': ' . h((string) $s['lastError']) : '');
        $sync .= '</span>' . form_open('sync_langame') . hidden('branchId', $branch['id']) . hidden('back', '/bar?' . http_build_query(['branch' => $branch['slug'], 'm' => $month['key'], 'view' => 'me']))
            . '<button class="' . btn_class('outline', 'sm') . '">' . icon('refresh', 'size-4') . 'Обновить</button></form></div>';

        $recent = $eid > 0 ? Langame::recentSales($eid, $month['from'], $month['to'], 15, (int) $branch['id']) : [];
        $top = $eid > 0 ? Langame::topGoods($eid, $month['from'], $month['to'], 5, (int) $branch['id']) : [];

        $list = card_head('Последние продажи', $recent ? 'Что вы пробили в этом месяце' : 'Пока ничего', '', 'file-text');
        if ($recent) {
            $list .= '<ul class="mt-4 flex flex-col divide-y divide-border">';
            foreach ($recent as $r) {
                $cancel = boolish($r['isCancelled']);
                $list .= '<li class="flex items-center justify-between gap-3 py-2.5 first:pt-0 last:pb-0' . ($cancel ? ' opacity-60' : '') . '">';
                $list .= '<span class="flex min-w-0 flex-col gap-0.5"><span class="truncate text-sm font-medium' . ($cancel ? ' line-through' : '') . '">' . h((string) ($r['goodsName'] ?? 'Товар')) . ((int) $r['qty'] > 1 ? ' <span class="text-muted-foreground">× ' . (int) $r['qty'] . '</span>' : '') . '</span>';
                $list .= '<span class="text-xs text-muted-foreground">' . h(format_dt(str_replace(' ', 'T', (string) $r['saleAt']))) . ($cancel ? ' · отменена' : (boolish($r['isCashless']) ? ' · безнал' : '')) . '</span></span>';
                $list .= '<span class="shrink-0 font-display text-sm font-semibold tabular-nums">' . h(kop_rub((int) $r['amountX100'])) . '</span></li>';
            }
            $list .= '</ul>';
        } else {
            $list .= '<p class="mt-3 text-sm leading-relaxed text-muted-foreground">Продажи появятся через пару минут после того, как вы пробьёте товар в админке Langame.</p>';
        }

        $topHtml = card_head('Чаще всего продаёте', '', '', 'star');
        if ($top) {
            $max = max(1, (int) $top[0]['amount']);
            $topHtml .= '<ol class="mt-4 flex flex-col gap-3">';
            foreach ($top as $t) {
                $w = max(4, (int) round(((int) $t['amount']) / $max * 100));
                $topHtml .= '<li class="flex flex-col gap-1.5"><div class="flex items-baseline justify-between gap-3 text-sm"><span class="truncate font-medium">' . h((string) $t['name']) . '</span><span class="shrink-0 tabular-nums text-muted-foreground">' . (int) $t['qty'] . ' шт · <span class="font-semibold text-foreground">' . h(kop_rub((int) $t['amount'])) . '</span></span></div>';
                $topHtml .= '<div class="h-1.5 overflow-hidden rounded-full bg-muted"><div class="h-full rounded-full bg-primary" style="width:' . $w . '%"></div></div></li>';
            }
            $topHtml .= '</ol>';
        } else {
            $topHtml .= '<p class="mt-3 text-sm text-muted-foreground">Здесь будет топ ваших позиций за месяц.</p>';
        }

        $board = '';
        if ($totals) {
            uasort($totals, static fn ($a, $b) => $b['revenue'] <=> $a['revenue']);
            $names = [];
            foreach (self::employees() as $e) {
                $names[(int) $e['id']] = (string) $e['name'];
            }
            $board = card_head('Рейтинг за ' . $month['name'], $seeAll ? 'Выручка бара и процент каждого' : 'Кто сколько продал в баре', '', 'trophy');
            $board .= '<ol class="mt-4 flex flex-col divide-y divide-border">';
            $place = 0;
            $max = max(1, (int) reset($totals)['revenue']);
            foreach ($totals as $id => $t) {
                $place++;
                $me = $id === $eid;
                $board .= '<li class="flex items-center gap-3 py-2.5 first:pt-0 last:pb-0">';
                $board .= '<span class="flex size-7 shrink-0 items-center justify-center rounded-full font-display text-xs font-semibold tabular-nums ' . ($place === 1 ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground') . '">' . $place . '</span>';
                $board .= '<span class="flex min-w-0 flex-1 flex-col gap-1"><span class="flex items-baseline justify-between gap-3 text-sm"><span class="truncate ' . ($me ? 'font-semibold' : 'font-medium') . '">' . h(short_name($names[$id] ?? 'Сотрудник')) . ($me ? ' <span class="text-xs font-normal text-muted-foreground">— вы</span>' : '') . '</span>';
                $board .= '<span class="shrink-0 tabular-nums">' . h(kop_rub($t['revenue'])) . ($seeAll || $me ? ' <span class="text-xs text-muted-foreground">· ' . h(kop_rub($t['earn'])) . '</span>' : '') . '</span></span>';
                $board .= '<span class="h-1 overflow-hidden rounded-full bg-muted"><span class="block h-full rounded-full ' . ($me ? 'bg-primary' : 'bg-foreground/30') . '" style="width:' . max(3, (int) round($t['revenue'] / $max * 100)) . '%"></span></span></span></li>';
            }
            $board .= '</ol>';
        }

        $html .= '<div class="flex flex-col gap-6">' . $hero . $sync;
        $html .= '<section class="flex flex-col gap-4">' . section_title('Детали', $nav);
        $html .= '<div class="grid gap-3 grid-cols-2 lg:grid-cols-4">';
        $html .= stat_tile('Выручка', h(kop_rub($mine['revenue'])), 'без отменённых', '', 'coins');
        $html .= stat_tile('Позиций', h(num_fmt($mine['qty'])), '', '', 'list');
        $html .= stat_tile('Средняя позиция', h(kop_rub($mine['qty'] > 0 ? (int) round($mine['revenue'] / $mine['qty']) : 0)), '', '', 'tags');
        $html .= stat_tile('Процент по продажам', h(kop_rub($mine['earn'])), 'За весь месяц; сумма к выплате — в «Моя зарплата»', url_to('/salary', ['m' => $month['key']]), 'wallet');
        $html .= '</div></section>';
        $html .= '<div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]"><div class="flex min-w-0 flex-col gap-6">' . card($list, 'p-5') . ($board !== '' ? card($board, 'p-5') : '') . '</div>' . card($topHtml, 'p-5') . '</div>';
        $html .= '</div>';

        self::layout($user, $html, ['title' => 'Продажи бара', 'width' => 'wide']);
    }

    private static function barViewSwitch(array $branch, array $month, string $active): string
    {
        return segmented([
            ['href' => url_to('/bar', ['branch' => $branch['slug'], 'm' => $month['key']]), 'label' => 'Команда', 'active' => $active === 'team'],
            ['href' => url_to('/bar', ['branch' => $branch['slug'], 'm' => $month['key'], 'view' => 'me']), 'label' => 'Мои продажи', 'active' => $active === 'me'],
        ], 'Режим');
    }

    private static function barBranchTabs(array $branch, array $month): string
    {
        $enabledIds = Langame::enabledBranchIds();
        if (count($enabledIds) < 2) {
            return '';
        }
        $items = [];
        foreach (self::branches() as $b) {
            if (in_array($b['id'], $enabledIds, true)) {
                $items[] = ['href' => url_to('/bar', ['branch' => $b['slug'], 'm' => $month['key']]), 'label' => $b['name'], 'active' => $b['id'] === $branch['id']];
            }
        }
        return segmented($items, 'Филиал');
    }

    private static function barSaleRow(array $r, array $names, bool $showWho = true): string
    {
        $who = $r['eid'] !== null && isset($names[(int) $r['eid']]) ? short_name($names[(int) $r['eid']]) : (string) ($r['staffName'] ?: 'без привязки');
        $html = '<li class="flex items-center justify-between gap-3 py-2.5 first:pt-0 last:pb-0">';
        $html .= '<span class="flex min-w-0 flex-col gap-0.5"><span class="truncate text-sm font-medium">' . h((string) ($r['goodsName'] ?? 'Товар')) . ((int) $r['qty'] > 1 ? ' <span class="text-muted-foreground">× ' . (int) $r['qty'] . '</span>' : '') . '</span>';
        $html .= '<span class="truncate text-xs text-muted-foreground">' . h(format_dt(str_replace(' ', 'T', (string) $r['saleAt']))) . ($showWho ? ' · ' . h($who) : '') . '</span></span>';
        return $html . '<span class="shrink-0 font-display text-sm font-semibold tabular-nums">' . h(kop_rub((int) $r['amountX100'])) . '</span></li>';
    }

    /** Мини-гистограмма по часам суток. */
    private static function hourBars(array $values, string $label, callable $fmt, ?int $highlightUpTo = null): string
    {
        $max = max(1, ...$values);
        $peak = array_search(max($values), $values, true);
        $html = '<div class="flex flex-col gap-2"><div class="flex h-24 items-end gap-0.5" role="img" aria-label="' . h($label) . '">';
        foreach ($values as $h => $v) {
            $pct = $v > 0 ? max(4, (int) round($v / $max * 100)) : 0;
            $future = $highlightUpTo !== null && $h > $highlightUpTo;
            $tone = $h === $peak && $v > 0 ? 'bg-primary' : ($future ? 'bg-muted' : 'bg-foreground/25');
            $html .= '<span class="flex h-full min-w-0 flex-1 items-end" title="' . h(sprintf('%02d:00 — ', $h) . $fmt($v)) . '"><span class="block w-full rounded-t-[2px] ' . $tone . '" style="height:' . ($future ? 2 : max(2, $pct)) . '%"></span></span>';
        }
        $html .= '</div><div class="flex justify-between text-[10px] text-muted-foreground tabular-nums" aria-hidden="true"><span>00</span><span>06</span><span>12</span><span>18</span><span>23</span></div></div>';
        return $html;
    }

    /** Сводка для владельца и управляющих: продажи каждого, отмены, загрузка клуба. */
    private static function pageBarTeam(array $user, array $branch, array $s, array $month): void
    {
        $bid = (int) $branch['id'];
        $now = now_tyumen();
        $today = $now->format('Y-m-d');
        $names = [];
        foreach (self::employees() as $e) {
            $names[(int) $e['id']] = (string) $e['name'];
        }

        $base = ['branch' => $branch['slug'], 'm' => $month['key']];
        $tab = self::getStr('tab', 12);
        if (!isset(self::barSections()[$tab])) {
            $tab = self::getStr('emp', 10) !== '' ? 'team' : 'overview';
        }
        $q = $base + ['tab' => $tab];
        $empId = (int) self::getStr('emp', 10);
        if ($tab === 'salary' && self::getStr('export', 6) === 'xlsx') {
            self::barPayrollExport($month);
        }
        $html = self::barWorkspaceHead($user, $branch, $month, $s, $tab);
        $connected = boolish($s['isEnabled'] ?? false);
        $ready = $connected && (!empty($s['syncedFrom']) || boolish($s['lastSyncOk'] ?? false));
        $content = null;
        if ($tab === 'salary') {
            $content = '<p class="crm-notice">Расчёт по начавшимся сменам: будущие выходы не начисляются. 1–15 — ставка; 16–конец месяца — ставка и процент с продаж за вторую половину месяца. Это не подтверждение фактической выплаты.' . (!$ready ? ' Продажи выбранного филиала не синхронизированы — процент с бара может быть неполным.' : '') . '</p>' . self::barPayroll($user, $month, $q);
        } elseif (!$connected) {
            $content = self::barUnavailable($user);
        } elseif ($tab === 'occupancy') {
            $content = self::barOccupancy($branch, Langame::live($bid));
        } elseif (!$ready) {
            $content = '<section class="crm-empty"><h2>Ожидаем первую синхронизацию</h2><p>Подключение настроено. Нажмите «Обновить продажи», чтобы загрузить данные. До этого итоги не отображаются.</p></section>';
        } elseif ($tab === 'sales') {
            $content = self::barLedger($branch, $month, $base);
        } elseif ($tab === 'goods') {
            $content = self::barGoods($branch, $month, Langame::live($bid));
        }
        if ($content !== null) {
            self::layout($user, '<div class="crm-workspace">' . $html . '<div class="crm-content">' . $content . '</div></div>', ['title' => self::barSections()[$tab][0] . ' · Аналитика', 'width' => 'wide']);
            return;
        }
        $html .= '<div class="crm-content">';
        $stats = Langame::teamStats($bid, $month['from'], $month['to']);
        $todayStats = $month['isCurrent'] && $tab === 'overview' ? Langame::teamStats($bid, $today, $today) : [];
        $shifts = Langame::shiftCounts($bid, $month['from'], $month['to']);
        $live = null;
        $sum = ['revenue' => 0, 'qty' => 0, 'lines' => 0, 'cancelAmount' => 0, 'cancelCount' => 0, 'cashless' => 0, 'earn' => 0];
        foreach ($stats as $t) {
            foreach ($sum as $k => $_) {
                $sum[$k] += $t[$k];
            }
        }
        $todayRevenue = array_sum(array_column($todayStats, 'revenue'));
        $unlinked = $stats[0]['revenue'] ?? 0;
        $daysPassed = $month['isCurrent'] ? (int) $now->format('j') : (int) (new DateTimeImmutable($month['to']))->format('j');
        $avgDay = $daysPassed > 0 ? (int) round($sum['revenue'] / $daysPassed) : 0;

        /* --- итоги месяца --- */
        $kpi = '<div class="crm-kpis">';
        $kpi .= self::barMetric('Выручка бара', kop_rub($sum['revenue']), 'Без отмен · ' . kop_rub($avgDay) . ' в день');
        $kpi .= self::barMetric('Продано позиций', num_fmt($sum['qty']), 'Средняя стоимость ' . kop_rub($sum['qty'] > 0 ? (int) round($sum['revenue'] / $sum['qty']) : 0));
        $kpi .= self::barMetric('Безналичная оплата', $sum['revenue'] > 0 ? round($sum['cashless'] / $sum['revenue'] * 100) . '%' : '—', kop_rub($sum['cashless']));
        $kpi .= self::barMetric('Отменённые позиции', kop_rub($sum['cancelAmount']), plural($sum['cancelCount'], 'запись', 'записи', 'записей'));
        $kpi .= '</div>';
        if ($tab === 'overview') {
            $html .= $kpi;
        }

        /* --- таблица сотрудников --- */
        $rows = $stats;
        unset($rows[0]);
        uasort($rows, static fn ($a, $b) => $b['revenue'] <=> $a['revenue']);
        $team = card_head('Администраторы за ' . $month['name'], 'Выручка и процент по выбранному филиалу · нажмите на сотрудника', '', 'users');
        if ($rows) {
            $maxRev = max(1, (int) reset($rows)['revenue']);
            $team .= '<div class="-mx-5 mt-4 overflow-x-auto"><table class="w-full min-w-[44rem] text-sm"><thead><tr class="border-y border-border bg-muted/40 text-left text-xs font-semibold text-muted-foreground">';
            foreach (['Сотрудник', 'Выручка', 'Позиций', 'Смен', 'За смену', 'Отмены', 'Начислено'] as $i => $col) {
                $team .= '<th scope="col" class="px-4 py-2.5 font-semibold' . ($i > 0 ? ' text-right' : '') . '">' . $col . '</th>';
            }
            $team .= '</tr></thead><tbody class="divide-y divide-border">';
            foreach ($rows as $id => $t) {
                $n = $shifts[$id] ?? 0;
                $sel = $empId === $id;
                $href = url_to('/bar', $q + ($sel ? [] : ['emp' => $id])) . '#emp';
                $team .= '<tr class="transition-colors hover:bg-muted/40' . ($sel ? ' bg-muted/60' : '') . '">';
                $team .= '<td class="px-4 py-3"><a href="' . h($href) . '" class="flex min-w-0 flex-col gap-1.5 font-medium hover:underline"' . ($sel ? ' aria-current="true"' : '') . '><span class="flex items-center gap-2">' . avatar($names[$id] ?? 'Сотрудник', 'size-7 text-[10px]') . '<span class="truncate">' . h(short_name($names[$id] ?? 'Сотрудник #' . $id)) . '</span></span>';
                $team .= '<span class="block h-1 w-full max-w-40 overflow-hidden rounded-full bg-muted"><span class="block h-full rounded-full bg-primary" style="width:' . max(3, (int) round($t['revenue'] / $maxRev * 100)) . '%"></span></span></a></td>';
                $team .= '<td class="px-4 py-3 text-right font-display font-semibold tabular-nums">' . h(kop_rub($t['revenue'])) . '</td>';
                $team .= '<td class="px-4 py-3 text-right tabular-nums">' . num_fmt($t['qty']) . '</td>';
                $team .= '<td class="px-4 py-3 text-right tabular-nums">' . ($n ?: '—') . '</td>';
                $team .= '<td class="px-4 py-3 text-right tabular-nums">' . ($n ? h(kop_rub((int) round($t['revenue'] / $n))) : '—') . '</td>';
                $team .= '<td class="px-4 py-3 text-right tabular-nums' . ($t['cancelCount'] >= 3 ? ' font-semibold text-primary-ink' : '') . '">' . ($t['cancelCount'] ? $t['cancelCount'] . ' · ' . h(kop_rub($t['cancelAmount'])) : '—') . '</td>';
                $team .= '<td class="px-4 py-3 text-right tabular-nums">' . h(kop_rub($t['earn'])) . ' <span class="text-xs text-muted-foreground">' . h(pct_label($t['percentX10'])) . '</span></td></tr>';
            }
            $team .= '</tbody></table></div>';
        } else {
            $team .= '<p class="mt-3 text-sm leading-relaxed text-muted-foreground">За ' . h($month['name']) . ' нет привязанных продаж. ' . ($user['isOwner'] ? 'Привяжите учётки Langame к сотрудникам в разделе «Подключение Langame».' : '') . '</p>';
        }

        /* --- карточка выбранного сотрудника --- */
        $detail = '';
        if ($empId > 0 && isset($names[$empId])) {
            $t = $stats[$empId] ?? ['revenue' => 0, 'qty' => 0, 'lines' => 0, 'cancelAmount' => 0, 'cancelCount' => 0, 'cashless' => 0, 'percentX10' => 0, 'earn' => 0];
            $recent = Langame::recentSales($empId, $month['from'], $month['to'], 20, $bid);
            $top = Langame::topGoods($empId, $month['from'], $month['to'], 6, $bid);
            $detail = '<div class="flex flex-wrap items-start justify-between gap-3"><div class="flex items-center gap-3">' . avatar($names[$empId], 'size-11 text-sm') . '<div class="flex flex-col"><h2 class="font-display text-lg font-semibold">' . h($names[$empId]) . '</h2><p class="text-sm text-muted-foreground">' . h($month['title']) . ' · ' . h(plural($shifts[$empId] ?? 0, 'смена', 'смены', 'смен')) . '</p></div></div>';
            $detail .= '<a class="' . btn_class('quiet', 'icon-sm') . '" href="' . h(url_to('/bar', $q)) . '" aria-label="Закрыть карточку">' . icon('x', 'size-4') . '</a></div>';
            $detail .= '<dl class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">';
            foreach ([['Выручка', kop_rub($t['revenue'])], ['Позиций', num_fmt($t['qty'])], ['Средняя', kop_rub($t['qty'] > 0 ? (int) round($t['revenue'] / $t['qty']) : 0)], ['Начислено', kop_rub($t['earn'])]] as [$k, $v]) {
                $detail .= '<div class="flex flex-col gap-1 rounded-md bg-muted/50 p-3"><dt class="text-xs text-muted-foreground">' . $k . '</dt><dd class="font-display text-lg font-semibold tabular-nums">' . h($v) . '</dd></div>';
            }
            $detail .= '</dl><div class="mt-5 grid gap-6 md:grid-cols-2"><div><h3 class="text-sm font-semibold">Последние продажи</h3>';
            if ($recent) {
                $detail .= '<ul class="mt-3 flex max-h-96 flex-col divide-y divide-border overflow-y-auto pr-1 scrollbar-thin">';
                foreach ($recent as $r) {
                    $cancel = boolish($r['isCancelled']);
                    $detail .= '<li class="flex items-center justify-between gap-3 py-2' . ($cancel ? ' text-primary-ink' : '') . '"><span class="flex min-w-0 flex-col"><span class="truncate text-sm' . ($cancel ? ' line-through' : '') . '">' . h((string) ($r['goodsName'] ?? 'Товар')) . ((int) $r['qty'] > 1 ? ' × ' . (int) $r['qty'] : '') . '</span><span class="text-xs text-muted-foreground">' . h(format_dt(str_replace(' ', 'T', (string) $r['saleAt']))) . ($cancel ? ' · отменена' : '') . '</span></span><span class="shrink-0 text-sm font-semibold tabular-nums">' . h(kop_rub((int) $r['amountX100'])) . '</span></li>';
                }
                $detail .= '</ul>';
            } else {
                $detail .= '<p class="mt-2 text-sm text-muted-foreground">Продаж нет.</p>';
            }
            $detail .= '</div><div><h3 class="text-sm font-semibold">Что продаёт чаще</h3>';
            if ($top) {
                $mx = max(1, (int) $top[0]['amount']);
                $detail .= '<ol class="mt-3 flex flex-col gap-3">';
                foreach ($top as $g) {
                    $detail .= '<li class="flex flex-col gap-1.5"><div class="flex items-baseline justify-between gap-3 text-sm"><span class="truncate">' . h((string) $g['name']) . '</span><span class="shrink-0 tabular-nums text-muted-foreground">' . (int) $g['qty'] . ' шт · <span class="font-semibold text-foreground">' . h(kop_rub((int) $g['amount'])) . '</span></span></div><div class="h-1.5 overflow-hidden rounded-full bg-muted"><div class="h-full rounded-full bg-primary" style="width:' . max(4, (int) round(((int) $g['amount']) / $mx * 100)) . '%"></div></div></li>';
                }
                $detail .= '</ol>';
            } else {
                $detail .= '<p class="mt-2 text-sm text-muted-foreground">Пока пусто.</p>';
            }
            $detail .= '</div></div>';
            $detail = card($detail, 'p-5 scroll-mt-20', 'section', 'id="emp" aria-label="Продажи сотрудника"');
        }

        /* --- на что обратить внимание --- */
        $alerts = [];
        if ($unlinked > 0) {
            $alerts[] = ['link', 'Продажи без привязки', h(kop_rub($unlinked)) . ' за месяц не привязаны ни к кому — проценты по ним не считаются.', $user['isOwner'] ? url_to('/manage/langame') : ''];
        }
        foreach ($rows as $id => $t) {
            if ($t['cancelCount'] >= 3 || ($t['revenue'] > 0 && $t['cancelAmount'] > $t['revenue'] * 0.1)) {
                $alerts[] = ['alert', 'Много отмен: ' . short_name($names[$id] ?? ''), h(plural($t['cancelCount'], 'отмена', 'отмены', 'отмен')) . ' на ' . h(kop_rub($t['cancelAmount'])) . ' — стоит проверить чеки.', url_to('/bar', $base + ['tab' => 'team', 'emp' => $id]) . '#emp'];
            }
        }
        $perShift = [];
        foreach ($rows as $id => $t) {
            if (($shifts[$id] ?? 0) >= 2) {
                $perShift[$id] = $t['revenue'] / $shifts[$id];
            }
        }
        if (count($perShift) >= 2) {
            $avgPs = array_sum($perShift) / count($perShift);
            foreach ($perShift as $id => $v) {
                if ($v < $avgPs * 0.5) {
                    $alerts[] = ['trophy', 'Слабые продажи: ' . short_name($names[$id] ?? ''), 'В среднем ' . h(kop_rub((int) round($v))) . ' за смену при среднем по команде ' . h(kop_rub((int) round($avgPs))) . '.', url_to('/bar', $base + ['tab' => 'team', 'emp' => $id]) . '#emp'];
                }
            }
        }
        foreach (($live['lowStock'] ?? []) as $g) {
            $alerts[] = ['tags', 'Заканчивается: ' . $g['name'], 'Осталось ' . (int) $g['count'] . ' шт, уходит около ' . (int) $g['perWeek'] . ' в неделю.', ''];
        }
        if ($month['isCurrent'] && $avgDay > 0 && (int) $now->format('G') >= 20 && $todayRevenue < $avgDay * 0.5) {
            $alerts[] = ['coins', 'Тихий день в баре', 'Сегодня ' . h(kop_rub($todayRevenue)) . ' — меньше половины от среднего ' . h(kop_rub($avgDay)) . '.', ''];
        }
        $watch = card_head('На что обратить внимание', $alerts ? '' : 'Всё спокойно', '', 'bell');
        if ($alerts) {
            $watch .= '<ul class="mt-4 flex flex-col divide-y divide-border">';
            foreach (array_slice($alerts, 0, 10) as [$ic, $title, $text, $href]) {
                $inner = '<span class="mt-0.5 text-primary">' . icon($ic, 'size-4') . '</span><span class="flex min-w-0 flex-col gap-0.5"><span class="text-sm font-semibold">' . h($title) . '</span><span class="text-sm leading-relaxed text-muted-foreground">' . $text . '</span></span>';
                $watch .= '<li class="py-3 first:pt-0 last:pb-0">' . ($href !== '' ? '<a href="' . h($href) . '" class="-mx-2 flex gap-3 rounded-md px-2 py-1 transition-colors hover:bg-muted/50">' . $inner . '</a>' : '<div class="flex gap-3">' . $inner . '</div>') . '</li>';
            }
            $watch .= '</ul>';
        } else {
            $watch .= '<p class="mt-3 text-sm leading-relaxed text-muted-foreground">По синхронизированным продажам нет сигналов, требующих проверки. Остатки и загрузка доступны в отдельных вкладках.</p>';
        }
        if (!empty($live['errors']) && $user['isOwner']) {
            $watch .= '<p class="mt-4 text-xs text-muted-foreground">Не всё получено из Langame: ' . h(implode('; ', $live['errors'])) . '</p>';
        }

        $hours = $tab === 'overview' ? Langame::hourlyRevenue($bid, $month['from'], $month['to']) : [];
        $hoursHtml = '';
        if (array_sum($hours) > 0) {
            $peak = (int) array_search(max($hours), $hours, true);
            $hoursHtml = card(card_head('Когда покупают', 'Пик — с ' . sprintf('%02d:00 до %02d:00', $peak, ($peak + 1) % 24) . ', выручка бара по часам за месяц', '', 'clock') . '<div class="mt-4">' . self::hourBars($hours, 'Выручка бара по часам за месяц', static fn ($v) => kop_rub($v)) . '</div>', 'p-5');
        }

        if ($tab === 'overview') {
            $html .= '<div class="crm-split"><div class="crm-stack">' . ($hoursHtml !== '' ? $hoursHtml : card(card_head('Когда покупают', 'Нет синхронизированных продаж за месяц', '', 'clock'), 'p-5')) . '<a class="crm-drilldown" href="' . h(url_to('/bar', $base + ['tab' => 'sales'])) . '"><span>Все продажи за период</span>' . icon('arrow', 'size-4') . '</a></div>';
            $html .= card($watch, 'p-5') . '</div>';
        } else {
            $html .= '<div class="crm-kpis">' . self::barMetric('Сотрудники с продажами', (string) count($rows), 'Привязанные учётные записи') . self::barMetric('Начислено с бара', kop_rub($sum['earn']), 'По настроенному проценту') . self::barMetric('Без привязки', kop_rub($unlinked), 'Не включено в зарплату') . self::barMetric('Период', $month['name'], 'Выбранный филиал') . '</div>';
            $html .= $detail . card($team, 'p-5');
        }

        self::layout($user, '<div class="crm-workspace">' . $html . '</div></div>', ['title' => self::barSections()[$tab][0] . ' · Аналитика', 'width' => 'wide']);
    }

    /* ---------- Зарплата за месяц ---------- */

    /** Строки ведомости: смены по типам, ставка, процент с бара, штрафы. Суммы в рублях. */
    private static function barPayrollRows(array $month): array
    {
        $rows = [];
        foreach (self::employees() as $e) {
            $data = Payroll::employee((int) $e['id'], $month['key']);
            if (!$data) {
                continue;
            }
            [$first, $second] = [$data['periods'][1], $data['periods'][2]];
            $types = ['day' => 0, 'night' => 0, 'full' => 0];
            foreach (array_merge($first['shifts'], $second['shifts']) as $shift) {
                if ($shift['started']) {
                    $types[$shift['shiftType']]++;
                }
            }
            $units = $first['units'] + $second['units'];
            $fine = $first['fines'] + $second['fines'];
            if (!boolish($e['isActive']) && $units === 0 && $fine === 0 && $second['barRev'] === 0) {
                continue;
            }
            $rows[] = [
                'id' => (int) $e['id'], 'name' => (string) $e['name'], 'active' => boolish($e['isActive']), 'grade' => $data['employee']['gradeTitle'] ?? '',
                'day' => $types['day'], 'night' => $types['night'], 'full' => $types['full'], 'units' => $units,
                'rate' => (int) ($data['employee']['shiftRate'] ?? 0), 'gross' => $first['gross'] + $second['gross'],
                'barRev' => $second['barRev'], 'barPct' => $second['barPct'], 'bar' => $second['bar'], 'fines' => $fine,
                'total' => $first['total'] + $second['total'],
            ];
        }
        usort($rows, static fn ($a, $b) => [$b['active'], $b['total']] <=> [$a['active'], $a['total']]);
        return $rows;
    }

    private static function barPayroll(array $user, array $month, array $q): string
    {
        $rows = self::barPayrollRows($month);
        $sum = ['units' => 0, 'gross' => 0, 'bar' => 0, 'fines' => 0, 'total' => 0, 'barRev' => 0];
        foreach ($rows as $r) {
            foreach ($sum as $k => $_) {
                $sum[$k] += $r[$k];
            }
        }
        $period = format_day_month($month['from']) . ' — ' . format_day_month($month['to']);

        $html = '<div class="grid grid-cols-2 gap-3 lg:grid-cols-4">';
        $html .= stat_tile('Начавшиеся смены', (string) $sum['units'], 'без будущих выходов · сутки за две', '', 'calendar');
        $html .= stat_tile('По ставкам', h(money_rub($sum['gross'])), h(plural($sum['units'], 'смена', 'смены', 'смен')) . ' · сутки за две', '', 'calendar');
        $html .= stat_tile('% с бара', h(money_rub($sum['bar'])), 'с продаж на ' . h(money_rub($sum['barRev'])), '', 'coins');
        $html .= stat_tile('Штрафы', h(money_rub($sum['fines'])), '', url_to('/fines'), 'scale', $sum['fines'] > 0);
        $html .= '</div>';

        $head = '<div class="flex flex-wrap items-center justify-between gap-3">' . card_head('Ведомость за ' . $month['name'], h($period) . ' · ' . h(plural(count($rows), 'сотрудник', 'сотрудника', 'сотрудников')), '', 'file-text');
        $head .= '<div class="flex flex-wrap gap-2"><a class="' . btn_class('quiet', 'sm') . '" href="' . h(url_to('/manage/grades')) . '">' . icon('settings', 'size-4') . 'Ставки</a>';
        $head .= '<a class="' . btn_class('primary', 'sm') . '" href="' . h(url_to('/bar', $q + ['export' => 'xlsx'])) . '" download>' . icon('download', 'size-4') . 'Скачать Excel</a></div></div>';

        if (!$rows) {
            return $html . card($head . '<p class="mt-4 text-sm text-muted-foreground">За ' . h($month['name']) . ' нет ни смен, ни продаж.</p>', 'p-5');
        }

        $maxTotal = max(1, ...array_map(static fn ($r) => $r['total'], $rows));
        $t = '<div class="-mx-5 mt-5 overflow-x-auto"><table class="w-full min-w-[56rem] text-sm"><thead><tr class="border-y border-border bg-muted/40 text-left text-xs font-semibold text-muted-foreground">';
        foreach (['Сотрудник', 'День', 'Ночь', 'Сутки', 'Ставка', 'По ставке', 'Бар', '% с бара', 'Штрафы', 'К выплате'] as $i => $c) {
            $t .= '<th scope="col" class="px-4 py-2.5 font-semibold whitespace-nowrap' . ($i > 0 ? ' text-right' : '') . '">' . $c . '</th>';
        }
        $t .= '</tr></thead><tbody class="divide-y divide-border">';
        foreach ($rows as $r) {
            $num = static fn (int $v): string => $v ? (string) $v : '<span class="text-muted-foreground/60">—</span>';
            $t .= '<tr class="transition-colors hover:bg-muted/40' . ($r['active'] ? '' : ' opacity-60') . '">';
            $t .= '<td class="px-4 py-3"><span class="flex items-center gap-3">' . avatar($r['name'], 'size-8 text-[11px]') . '<span class="flex min-w-0 flex-col gap-1"><span class="truncate font-medium">' . h(short_name($r['name'])) . '</span>';
            $t .= '<span class="text-xs text-muted-foreground">' . h($r['grade'] !== '' ? $r['grade'] : 'без уровня') . ($r['active'] ? '' : ' · в архиве') . '</span></span></span></td>';
            $t .= '<td class="px-4 py-3 text-right tabular-nums">' . $num($r['day']) . '</td>';
            $t .= '<td class="px-4 py-3 text-right tabular-nums">' . $num($r['night']) . '</td>';
            $t .= '<td class="px-4 py-3 text-right tabular-nums">' . $num($r['full']) . '</td>';
            $t .= '<td class="px-4 py-3 text-right whitespace-nowrap tabular-nums text-muted-foreground">' . ($r['rate'] ? h(money_rub($r['rate'])) : '—') . '</td>';
            $t .= '<td class="px-4 py-3 text-right whitespace-nowrap tabular-nums">' . h(money_rub($r['gross'])) . '</td>';
            $t .= '<td class="px-4 py-3 text-right whitespace-nowrap tabular-nums text-muted-foreground">' . ($r['barRev'] ? h(money_rub($r['barRev'])) : '—') . '</td>';
            $t .= '<td class="px-4 py-3 text-right whitespace-nowrap tabular-nums">' . ($r['bar'] ? '+' . h(money_rub($r['bar'])) : '—') . '</td>';
            $t .= '<td class="px-4 py-3 text-right whitespace-nowrap tabular-nums' . ($r['fines'] ? ' text-primary-ink' : '') . '">' . ($r['fines'] ? '−' . h(money_rub($r['fines'])) : '—') . '</td>';
            $t .= '<td class="px-4 py-3 text-right"><span class="flex flex-col items-end gap-1.5"><span class="font-display font-semibold whitespace-nowrap tabular-nums">' . h(money_rub($r['total'])) . '</span>';
            $t .= '<span class="block h-1 w-20 overflow-hidden rounded-full bg-muted"><span class="block h-full rounded-full bg-primary" style="width:' . max(3, (int) round(max(0, $r['total']) / $maxTotal * 100)) . '%"></span></span></span></td></tr>';
        }
        $t .= '</tbody><tfoot><tr class="border-t-2 border-border bg-muted/40 font-semibold">';
        $t .= '<td class="px-4 py-3">Итого</td><td class="px-4 py-3 text-right tabular-nums" colspan="3">' . h(plural($sum['units'], 'смена', 'смены', 'смен')) . '</td><td></td>';
        $t .= '<td class="px-4 py-3 text-right whitespace-nowrap tabular-nums">' . h(money_rub($sum['gross'])) . '</td><td class="px-4 py-3 text-right whitespace-nowrap tabular-nums">' . h(money_rub($sum['barRev'])) . '</td>';
        $t .= '<td class="px-4 py-3 text-right whitespace-nowrap tabular-nums">' . h(money_rub($sum['bar'])) . '</td><td class="px-4 py-3 text-right whitespace-nowrap tabular-nums">' . h(money_rub($sum['fines'])) . '</td>';
        $t .= '<td class="px-4 py-3 text-right font-display whitespace-nowrap tabular-nums">' . h(money_rub($sum['total'])) . '</td></tr></tfoot></table></div>';
        $t .= '<p class="mt-4 text-xs leading-relaxed text-muted-foreground">Начисляются только начавшиеся смены, сутки считаются за две. Для прошедших смен используется история ставок; столбец «Ставка» показывает текущую. Процент с бара — с продаж за 16–конец месяца, без отмен. Штрафы — выписанные за этот месяц.</p>';

        return $html . card($head . $t, 'p-5');
    }

    private static function barPayrollExport(array $month): never
    {
        $rows = self::barPayrollRows($month);
        $out = [['Сотрудник', 'Уровень', 'Статус', 'Смен день', 'Смен ночь', 'Смен сутки', 'Всего смен (сутки ×2)', 'Ставка, ₽', 'По ставке, ₽', 'Продажи бара, ₽', '% с бара', 'Процент с бара, ₽', 'Штрафы, ₽', 'К выплате, ₽']];
        $sum = array_fill_keys(['day', 'night', 'full', 'units', 'gross', 'barRev', 'bar', 'fines', 'total'], 0);
        foreach ($rows as $r) {
            $out[] = [$r['name'], $r['grade'], $r['active'] ? 'работает' : 'в архиве', $r['day'], $r['night'], $r['full'], $r['units'], $r['rate'], $r['gross'], $r['barRev'], pct_label($r['barPct']), $r['bar'], $r['fines'], $r['total']];
            foreach ($sum as $k => $_) {
                $sum[$k] += $r[$k];
            }
        }
        $out[] = ['Итого', '', '', $sum['day'], $sum['night'], $sum['full'], $sum['units'], null, $sum['gross'], $sum['barRev'], '', $sum['bar'], $sum['fines'], $sum['total']];
        $name = 'Зарплата ' . $month['title'] . '.xlsx';
        xlsx_send($name, 'Зарплата ' . $month['title'], $out, [30, 14, 11, 10, 10, 11, 14, 11, 13, 15, 9, 15, 11, 14]);
    }

    /* ---------- Подключение Langame (владелец) ---------- */

    private static function pageLangame(array $user): void
    {
        $html = page_head('Подключение Langame', 'Токен хранится в базе только в зашифрованном виде и больше нигде не показывается. Здесь же — процент с бара и привязка администраторов Langame к сотрудникам портала.', '<a class="' . btn_class('outline') . '" href="' . h(url_to('/bar')) . '">' . icon('coins', 'size-4') . 'Продажи бара</a>');

        $empOptions = ['' => '— не привязан —'];
        $byBranch = [];
        foreach (self::employees(true) as $e) {
            $byBranch[self::branchName((int) $e['homeBranchId'])][(string) $e['id']] = (string) $e['name'];
        }
        foreach ($byBranch as $bn => $list) {
            $empOptions[$bn] = $list;
        }

        $month = self::monthFromQuery();
        foreach (self::branches() as $b) {
            $s = Langame::settings($b['id']);
            $on = $s && boolish($s['isEnabled']);
            $hasToken = $s && !empty($s['tokenEnc']);

            $status = $on ? chip('Подключено', 'ink', 'check') : ($hasToken ? chip('Выключено', 'neutral') : chip('Не настроено', 'neutral'));
            $c = card_head($b['name'], $s && $s['lastSyncAt'] ? 'Обновлено ' . h(format_dt((string) $s['lastSyncAt'])) . (!boolish($s['lastSyncOk']) && !empty($s['lastError']) ? ' · <span class="text-primary-ink">' . h((string) $s['lastError']) . '</span>' : '') : 'Синхронизаций ещё не было', $status, 'link');

            $c .= form_open('save_langame', 'class="mt-5 flex flex-col gap-4" autocomplete="off"') . hidden('branchId', $b['id']);
            $c .= ui_field('Адрес клуба в Langame', '<input name="baseUrl" type="url" inputmode="url" class="' . field_class() . '" maxlength="255" placeholder="' . h(Langame::BASE_EXAMPLE) . '" value="' . h((string) ($s['baseUrl'] ?? '')) . '">', 'Номер клуба в адресе — как в ссылке на вашу админку Langame');
            $tokenHint = $hasToken ? 'Сохранён токен ' . h((string) ($s['tokenHint'] ?? '')) . '. Оставьте п��ле пустым, чтобы не менять.' : 'Серверный ключ (X-Request-Token) из настроек Langame.';
            $c .= ui_field('Секретный токен', '<input name="token" type="password" class="' . field_class('font-mono') . '" maxlength="512" autocomplete="new-password" spellcheck="false" placeholder="' . ($hasToken ? '••••••••••••' : 'Вставьте токен') . '">', $tokenHint);
            $c .= ui_field('Количество компьютеров', '<input name="pcCount" type="number" min="0" max="500" class="' . field_class('max-w-40') . '" value="' . (int) $b['pcCount'] . '" required>', 'Для чистки и расчёта загрузки. 0 — количество пока не указано; консоли не включать.');
            $c .= '<div class="flex flex-wrap items-end gap-4">';
            $c .= ui_field('Процент с бара по умолчанию', '<div class="relative"><input name="barPercent" type="number" inputmode="decimal" min="0" max="100" step="0.1" class="' . field_class('pr-8 tabular-nums') . '" value="' . h(rtrim(rtrim(number_format(((int) ($s['barPercentX10'] ?? 0)) / 10, 1, '.', ''), '0'), '.')) . '"><span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-muted-foreground">%</span></div>', '', 'w-44');
            $c .= '<label class="flex cursor-pointer items-center gap-2 pb-2.5 text-sm font-medium"><input type="checkbox" name="isEnabled" value="1" class="' . check_class() . '"' . ($on || !$s ? ' checked' : '') . '>Подтягивать продажи</label></div>';
            $c .= '<div class="flex flex-wrap gap-2"><button class="' . btn_class() . '">' . icon('save', 'size-4') . 'Сохранить</button></form>';
            if ($hasToken) {
                $c .= form_open('test_langame') . hidden('branchId', $b['id']) . '<button class="' . btn_class('outline') . '">' . icon('check-circle', 'size-4') . 'Проверить связь</button></form>';
                $c .= form_open('sync_langame') . hidden('branchId', $b['id']) . hidden('back', '/manage/langame') . '<button class="' . btn_class('outline') . '">' . icon('refresh', 'size-4') . 'Синхронизировать</button></form>';
                $c .= form_open('forget_langame_token', confirm_attr('Удалить токен ' . $b['name'] . '? Подключение выключится, уже загруженные продажи останутся.')) . hidden('branchId', $b['id']) . '<button class="' . btn_class('danger-ghost') . '">' . icon('trash', 'size-4') . 'Удалить токен</button></form>';
            }
            $c .= '</div>';

            $staff = $s ? Db::all('SELECT * FROM langame_staff WHERE "branchId" = ? ORDER BY "langameName", id', [$b['id']]) : [];
            $m = '';
            if ($staff) {
                $totals = Langame::totalsByEmployee($month['from'], $month['to'], $b['id']);
                $unlinked = Langame::unlinkedRevenue($b['id'], $month['from'], $month['to']);
                $m .= card_head('Администраторы Langame', 'Кто под какой учёткой пробивает бар. Пустой процент — значит по умолчанию.', '', 'users');
                if ($unlinked > 0) {
                    $m .= '<p class="mt-4 rounded-md border border-primary/30 bg-primary/10 px-3 py-2 text-sm text-primary-ink">За ' . h($month['name']) . ' ' . h(kop_rub($unlinked)) . ' продаж ни к кому не привязано — проверьте список ниже.</p>';
                }
                $m .= form_open('link_langame_staff', 'class="mt-4 flex flex-col gap-4"') . hidden('branchId', $b['id']);
                $lastShift = Langame::lastShiftByStaff($b['id']);
                $recentCut = now_tyumen()->modify('-90 days')->format('Y-m-d');
                $hasShiftData = $lastShift !== [];
                $main = [];
                $rest = [];
                foreach ($staff as $st) {
                    $lid = (int) $st['langameUserId'];
                    $recent = isset($lastShift[$lid]) && strcmp($lastShift[$lid], $recentCut) >= 0;
                    $flagActive = boolish($st['isActive'] ?? 1) && !Langame::isPlaceholder($st);
                    $show = $st['employeeId'] !== null || $recent || (!$hasShiftData && $flagActive);
                    if ($show) {
                        $main[] = $st;
                    } else {
                        $rest[] = $st;
                    }
                }
                usort($main, static fn ($x, $y) => ($y['employeeId'] !== null) <=> ($x['employeeId'] !== null) ?: strcmp((string) ($lastShift[(int) $y['langameUserId']] ?? ''), (string) ($lastShift[(int) $x['langameUserId']] ?? '')));
                $renderRow = static function (array $st) use ($totals, $empOptions, $s, $lastShift): string {
                    $lid = (int) $st['langameUserId'];
                    $eidL = $st['employeeId'] !== null ? (int) $st['employeeId'] : null;
                    $t = $eidL !== null ? ($totals[$eidL] ?? null) : null;
                    $label = (string) ($st['langameName'] ?: 'ID ' . $lid);
                    $login = (string) ($st['langameLogin'] ?: 'ID ' . $lid);
                    $meta = [$login];
                    if (!empty($st['adminStatus'])) {
                        $meta[] = (string) $st['adminStatus'];
                    }
                    $meta[] = isset($lastShift[$lid]) ? 'смена ' . substr($lastShift[$lid], 8, 2) . '.' . substr($lastShift[$lid], 5, 2) . '.' . substr($lastShift[$lid], 2, 2) : 'смен не было';
                    $out = '<li class="flex flex-col gap-3 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-end" data-filter-text="' . h(mb_strtolower($label . ' ' . $login)) . '">';
                    $out .= '<div class="flex min-w-0 flex-col gap-0.5 sm:w-52 sm:pb-2"><span class="flex items-center gap-2 truncate text-sm font-semibold">' . h($label) . ($eidL !== null ? chip('привязан', 'ink') : '') . '</span><span class="truncate text-xs text-muted-foreground">' . h(implode(' · ', $meta)) . ($t ? ' · ' . h(kop_rub($t['revenue'])) : '') . '</span></div>';
                    $out .= ui_field('Сотрудник портала', ui_select('link[' . $lid . ']', $empOptions, $eidL !== null ? (string) $eidL : '', 'aria-label="Сотрудник для ' . h($label) . '"'), '', 'min-w-0 flex-1');
                    $pv = $st['percentX10'] !== null ? rtrim(rtrim(number_format(((int) $st['percentX10']) / 10, 1, '.', ''), '0'), '.') : '';
                    $out .= ui_field('Свой %', '<input name="pct[' . $lid . ']" type="number" inputmode="decimal" min="0" max="100" step="0.1" class="' . field_class('tabular-nums') . '" placeholder="' . h(pct_label((int) ($s['barPercentX10'] ?? 0))) . '" value="' . h($pv) . '">', '', 'w-full sm:w-24');
                    return $out . '</li>';
                };
                $listId = 'lg-staff-' . $b['id'];
                $m .= '<label class="relative block"><span class="sr-only">Поиск по учёткам Langame</span><span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-muted-foreground">' . icon('search', 'size-4') . '</span><input type="search" class="' . field_class('pl-9') . '" placeholder="Найти по имени или логину" autocomplete="off" data-filter-input="' . h($listId) . '"></label>';
                $m .= '<div id="' . h($listId) . '" class="flex flex-col gap-4">';
                $m .= '<p class="text-xs font-semibold tracking-wide text-muted-foreground uppercase">Действующие · ' . count($main) . '</p>';
                $m .= $main ? '<ul class="flex flex-col divide-y divide-border">' . implode('', array_map($renderRow, $main)) . '</ul>' : '<p class="text-sm text-muted-foreground">Не нашли действующих — раскройте список ниже.</p>';
                if ($rest) {
                    $m .= '<details class="group rounded-lg border border-border" data-filter-details><summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm font-medium [&::-webkit-details-marker]:hidden"><span>Неактивные, удалё��ные и служебные · ' . count($rest) . '</span><span class="text-muted-foreground transition-transform group-open:rotate-180">' . icon('chevrondown', 'size-4') . '</span></summary>';
                    $m .= '<ul class="flex max-h-[28rem] flex-col divide-y divide-border overflow-y-auto border-t border-border px-4 py-3 scrollbar-thin">' . implode('', array_map($renderRow, $rest)) . '</ul></details>';
                }
                $m .= '</div><button class="' . btn_class() . ' sm:self-start">' . icon('save', 'size-4') . 'Сохранить привязки</button></form>';
            } elseif ($hasToken) {
                $m .= empty_state('users', 'Администраторов пока нет', 'Нажмите «Синхронизировать» — портал подтянет список учёток из Langame.');
            }

            $html .= '<section class="grid items-start gap-6 xl:grid-cols-[28rem_minmax(0,1fr)]">' . card($c, 'p-5') . ($m !== '' ? card($m, 'p-5') : '<div class="hidden xl:block"></div>') . '</section>';
        }

        $html .= '<p class="text-xs leading-relaxed text-muted-foreground text-pretty">Продажа засчитывается тому, на чьей смене в Langame она пробита. Отменённые продажи не учитываются. Процент считается от суммы продажи и автоматически добавляется к начислениям в разделе «Уровни и ставки».</p>';
        self::layout($user, $html, ['title' => 'Подключение Langame', 'width' => 'wide']);
    }
}
