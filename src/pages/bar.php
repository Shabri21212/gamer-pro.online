<?php
declare(strict_types=1);

trait PagesBar
{
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
                    $items[] = ['href' => url_to('/bar', ['branch' => $b['slug'], 'm' => $month['key'] ?? null]), 'label' => $b['name'], 'active' => $b['id'] === $branch['id']];
                }
            }
            $branchTabs = segmented($items, 'Филиал');
        }

        $html = page_head('Продажи бара', 'Всё, что вы пробили в Langame на своей смене, — и ваш процент с этого. Обновляется само каждые ' . (Langame::AUTO_SYNC_SECONDS / 60) . ' минуты.', $branchTabs, $branch['name']);

        $hero = '<section class="relative isolate overflow-hidden rounded-xl border border-border bg-sidebar text-sidebar-foreground shadow-sm">';
        $hero .= '<img src="' . h(asset('img/club/lounge.webp')) . '" srcset="' . h(asset('img/club/lounge-sm.webp')) . ' 800w, ' . h(asset('img/club/lounge.webp')) . ' 1600w" sizes="(min-width: 1024px) 60vw, 100vw" alt="" aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10 size-full object-cover opacity-35 [mask-image:linear-gradient(to_left,black_10%,transparent_85%)]">';
        $hero .= '<div class="flex flex-col gap-5 p-5 sm:p-7">';
        $hero .= '<div class="flex flex-wrap items-center justify-between gap-3"><p class="text-[11px] font-semibold tracking-[0.18em] text-sidebar-foreground/60 uppercase">Ваш процент · ' . h($month['name']) . '</p>';
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
        $hero .= '</div></section>';

        $nav = '<div class="flex items-center gap-1"><a class="' . btn_class('outline', 'icon-sm') . '" href="' . h(url_to('/bar', ['branch' => $branch['slug'], 'm' => $month['prev']])) . '" aria-label="Предыдущий месяц">' . icon('chevronleft') . '</a>';
        $nav .= '<span class="min-w-32 text-center font-display text-sm font-semibold">' . h($month['title']) . '</span>';
        $nav .= '<a class="' . btn_class('outline', 'icon-sm') . '" href="' . h(url_to('/bar', ['branch' => $branch['slug'], 'm' => $month['next']])) . '" aria-label="Следующий месяц">' . icon('chevronright') . '</a></div>';

        $sync = '<div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-card px-4 py-3 text-sm shadow-xs">';
        $ok = boolish($s['lastSyncOk'] ?? 0);
        $sync .= '<span class="flex items-center gap-2 text-muted-foreground"><span class="size-2 rounded-full ' . ($ok ? 'bg-foreground/60' : 'bg-primary') . '" aria-hidden="true"></span>';
        $sync .= $ok ? 'Обновлено ' . h(format_dt((string) ($s['lastSyncAt'] ?? ''))) : 'Последнее обновление не прошло' . ($user['isOwner'] && !empty($s['lastError']) ? ': ' . h((string) $s['lastError']) : '');
        $sync .= '</span>' . form_open('sync_langame') . hidden('branchId', $branch['id']) . hidden('back', '/bar?branch=' . $branch['slug'])
            . '<button class="' . btn_class('outline', 'sm') . '">' . icon('refresh', 'size-4') . 'Обновить</button></form></div>';

        $recent = $eid > 0 ? Langame::recentSales($eid, $month['from'], $month['to'], 15) : [];
        $top = $eid > 0 ? Langame::topGoods($eid, $month['from'], $month['to'], 5) : [];

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
        $html .= stat_tile('Ваш процент', h(kop_rub($mine['earn'])), h(pct_label($mine['percentX10'])) . ' — войдёт в зарплату', $seeAll ? url_to('/manage/grades') : '', 'wallet');
        $html .= '</div></section>';
        $html .= '<div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]"><div class="flex min-w-0 flex-col gap-6">' . card($list, 'p-5') . ($board !== '' ? card($board, 'p-5') : '') . '</div>' . card($topHtml, 'p-5') . '</div>';
        $html .= '</div>';

        self::layout($user, $html, ['title' => 'Продажи бара', 'width' => 'wide']);
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
            $tokenHint = $hasToken ? 'Сохранён токен ' . h((string) ($s['tokenHint'] ?? '')) . '. Оставьте поле пустым, чтобы не менять.' : 'Серверный ключ (X-Request-Token) из настроек Langame.';
            $c .= ui_field('Секретный токен', '<input name="token" type="password" class="' . field_class('font-mono') . '" maxlength="512" autocomplete="new-password" spellcheck="false" placeholder="' . ($hasToken ? '••••••••••••' : 'Вставьте токен') . '">', $tokenHint);
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
                $m .= '<ul class="flex flex-col divide-y divide-border">';
                foreach ($staff as $st) {
                    $lid = (int) $st['langameUserId'];
                    $eidL = $st['employeeId'] !== null ? (int) $st['employeeId'] : null;
                    $t = $eidL !== null ? ($totals[$eidL] ?? null) : null;
                    $label = (string) ($st['langameName'] ?: 'ID ' . $lid);
                    $m .= '<li class="flex flex-col gap-3 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-end">';
                    $m .= '<div class="flex min-w-0 flex-col gap-0.5 sm:w-48 sm:pb-2"><span class="truncate text-sm font-semibold">' . h($label) . '</span><span class="truncate text-xs text-muted-foreground">' . h((string) ($st['langameLogin'] ?: 'ID ' . $lid)) . ($t ? ' · ' . h(kop_rub($t['revenue'])) : '') . '</span></div>';
                    $m .= ui_field('Сотрудник портала', ui_select('link[' . $lid . ']', $empOptions, $eidL !== null ? (string) $eidL : '', 'aria-label="Сотрудник для ' . h($label) . '"'), '', 'min-w-0 flex-1');
                    $pv = $st['percentX10'] !== null ? rtrim(rtrim(number_format(((int) $st['percentX10']) / 10, 1, '.', ''), '0'), '.') : '';
                    $m .= ui_field('Свой %', '<input name="pct[' . $lid . ']" type="number" inputmode="decimal" min="0" max="100" step="0.1" class="' . field_class('tabular-nums') . '" placeholder="' . h(pct_label((int) ($s['barPercentX10'] ?? 0))) . '" value="' . h($pv) . '">', '', 'w-full sm:w-24');
                    $m .= '</li>';
                }
                $m .= '</ul><button class="' . btn_class() . ' sm:self-start">' . icon('save', 'size-4') . 'Сохранить привязки</button></form>';
            } elseif ($hasToken) {
                $m .= empty_state('users', 'Администраторов пока нет', 'Нажмите «Синхронизировать» — портал подтянет список учёток из Langame.');
            }

            $html .= '<section class="grid items-start gap-6 xl:grid-cols-[28rem_minmax(0,1fr)]">' . card($c, 'p-5') . ($m !== '' ? card($m, 'p-5') : '<div class="hidden xl:block"></div>') . '</section>';
        }

        $html .= '<p class="text-xs leading-relaxed text-muted-foreground text-pretty">Продажа засчитывается тому, на чьей смене в Langame она пробита. Отменённые продажи не учитываются. Процент считается от суммы продажи и автоматически добавляется к начислениям в разделе «Уровни и ставки».</p>';
        self::layout($user, $html, ['title' => 'Подключение Langame', 'width' => 'wide']);
    }
}
