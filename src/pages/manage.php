<?php
declare(strict_types=1);

trait PagesManage
{
    /* ---------- общие помощники управления ---------- */

    private static function loginOf(string $email): string
    {
        $suffix = '@' . LOGIN_DOMAIN;
        return str_ends_with($email, $suffix) ? substr($email, 0, -strlen($suffix)) : $email;
    }

    /** Смены за период в «сменах к оплате»: сутки считаются за две. [employeeId => n] */
    private static function shiftUnits(string $from, string $to): array
    {
        $out = [];
        $rows = Db::all(
            'SELECT "employeeId", "shiftType", COUNT(*) AS n FROM shifts WHERE date >= ? AND date < ? GROUP BY "employeeId", "shiftType"',
            [$from, add_days($to, 1)],
        );
        foreach ($rows as $r) {
            $id = (int) $r['employeeId'];
            $out[$id] = ($out[$id] ?? 0) + shift_weight((string) $r['shiftType']) * (int) $r['n'];
        }
        return $out;
    }

    /** Сумма штрафов за период. [employeeId => ₽] */
    private static function finesTotals(string $from, string $to): array
    {
        $out = [];
        $rows = Db::all(
            'SELECT "employeeId", SUM(amount) AS s FROM employee_fines WHERE "createdAt" >= ? AND "createdAt" < ? GROUP BY "employeeId"',
            [$from, add_days($to, 1)],
        );
        foreach ($rows as $r) {
            $out[(int) $r['employeeId']] = (int) $r['s'];
        }
        return $out;
    }

    private static function gradeRows(): array
    {
        return array_map(static function (array $g): array {
            $g['id'] = (int) $g['id'];
            $g['shiftRate'] = (int) $g['shiftRate'];
            return $g;
        }, Db::all('SELECT id, title, "shiftRate", "sortOrder" FROM employee_grades ORDER BY "sortOrder", "shiftRate", id'));
    }

    private static function gradeOptions(array $grades): array
    {
        $opts = ['' => 'Без уровня'];
        foreach ($grades as $g) {
            $opts[(string) $g['id']] = $g['title'] . ' · ' . money_rub($g['shiftRate']);
        }
        return $opts;
    }

    /** ?m=YYYY-MM → границы и подписи месяца. */
    private static function monthFromQuery(): array
    {
        $now = now_tyumen();
        $m = self::getStr('m', 7);
        $first = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $m . '-01', new DateTimeZone(APP_TZ))
            : false;
        if (!$first) {
            $first = $now->modify('first day of this month')->setTime(0, 0);
        }
        $monthName = MONTH_NAMES[(int) $first->format('n') - 1];
        return [
            'key' => $first->format('Y-m'),
            'from' => $first->format('Y-m-01'),
            'to' => $first->format('Y-m-t'),
            'title' => $monthName . ' ' . $first->format('Y'),
            'name' => mb_strtolower($monthName),
            'prev' => $first->modify('-1 month')->format('Y-m'),
            'next' => $first->modify('+1 month')->format('Y-m'),
            'isCurrent' => $first->format('Y-m') === $now->format('Y-m'),
        ];
    }

    private static function passwordInput(string $name, string $autocomplete, string $attrs = ''): string
    {
        return '<span class="relative block"><input type="password" name="' . h($name) . '" class="' . field_class('pr-11') . '" autocomplete="' . h($autocomplete) . '" required ' . $attrs . '>'
            . '<button type="button" class="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-muted-foreground hover:text-foreground" data-toggle-pass aria-label="Показать пароль" aria-pressed="false">' . icon('eye') . '</button></span>';
    }

    /** Поле пароля с кнопкой «Придумать» — для владельца, который выдаёт пароль сотруднику. */
    private static function generatedPasswordInput(string $value = ''): string
    {
        return '<span class="flex gap-2"><input name="password" type="text" class="' . field_class('font-mono') . '" required minlength="8" maxlength="72" autocomplete="new-password" autocapitalize="none" spellcheck="false" value="' . h($value) . '" data-pass-target>'
            . '<button type="button" class="' . btn_class('outline') . '" data-gen-pass>' . icon('refresh', 'size-4') . 'Придумать</button></span>';
    }

    private static function profileStat(string $label, string $value, string $hint = ''): string
    {
        return '<div class="flex min-w-0 flex-col gap-1 px-5 py-4"><dt class="text-xs font-medium text-muted-foreground">' . h($label) . '</dt><dd class="font-display text-xl leading-tight font-semibold tabular-nums">' . $value
            . ($hint !== '' ? '<span class="ml-1 font-sans text-xs font-medium text-muted-foreground">' . h($hint) . '</span>' : '') . '</dd></div>';
    }

    private static function auditIcon(string $entity): string
    {
        return [
            'schedule' => 'calendar', 'handover' => 'clipboard-check', 'tasks' => 'clipboard-list', 'cleaning' => 'keyboard', 'issues' => 'wrench',
            'news' => 'megaphone', 'guests' => 'star', 'content' => 'tags', 'wiki' => 'book', 'fines' => 'scale',
            'exams' => 'grad', 'staff' => 'users', 'access' => 'key',
        ][$entity] ?? 'history';
    }

    private static function collapsible(string $title, string $count, string $inner, string $iconName = '', bool $open = false): string
    {
        $html = '<details class="group rounded-lg border border-border bg-card shadow-xs"' . ($open ? ' open' : '') . '>';
        $html .= '<summary class="flex cursor-pointer list-none items-center justify-between gap-3 rounded-lg px-4 py-3 text-sm font-semibold hover:bg-muted/40 [&::-webkit-details-marker]:hidden"><span class="flex items-center gap-2">';
        if ($iconName !== '') {
            $html .= icon($iconName, 'size-4 text-muted-foreground');
        }
        $html .= h($title);
        if ($count !== '') {
            $html .= '<span class="rounded-full bg-muted px-2 py-0.5 text-xs font-semibold text-muted-foreground tabular-nums">' . h($count) . '</span>';
        }
        $html .= '</span>' . icon('chevrondown', 'size-4 text-muted-foreground transition-transform group-open:rotate-180') . '</summary>';
        return $html . '<div class="border-t border-border">' . $inner . '</div></details>';
    }

    /* ---------- Мой профиль ---------- */

    private static function pageAccount(array $user): void
    {
        $name = self::displayName($user);
        $eid = $user['employeeId'];
        $now = now_tyumen();
        $mon = mb_strtolower(MONTH_NAMES[(int) $now->format('n') - 1]);
        $monthFrom = $now->format('Y-m-01');
        $grade = $user['gradeId'] ? Db::one('SELECT title, "shiftRate" FROM employee_grades WHERE id = ?', [$user['gradeId']]) : null;
        $units = $eid ? (self::shiftUnits($monthFrom, $now->format('Y-m-t'))[$eid] ?? 0) : 0;
        $finesMonth = $eid ? (int) Db::value('SELECT COALESCE(SUM(amount), 0) FROM employee_fines WHERE "employeeId" = ? AND "createdAt" >= ?', [$eid, $monthFrom]) : 0;
        $fines = $eid ? Db::all('SELECT reason, amount, "createdAt" FROM employee_fines WHERE "employeeId" = ? ORDER BY "createdAt" DESC LIMIT 3', [$eid]) : [];
        $upcoming = $eid ? self::upcomingShiftsFor($eid, 14) : [];
        $xp = self::examXpState(self::examCatalog($user));

        $actions = form_open('logout') . '<button class="' . btn_class('outline') . '">' . icon('logout', 'size-4') . 'Выйти</button></form>';
        $html = page_head('Мой профиль', 'Ваши данные, права в портале и смена пароля.', $actions, 'Личное');

        $id = '<div class="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:gap-5">' . avatar($name, 'size-16 text-lg');
        $id .= '<div class="flex min-w-0 flex-1 flex-col gap-2"><h2 class="font-display text-xl leading-tight font-semibold text-balance">' . h($name) . '</h2>';
        $id .= '<div class="flex flex-wrap items-center gap-1.5">' . chip($user['roleLabel'], $user['isOwner'] ? 'brand' : 'ink', 'shield');
        if ($user['homeBranchId']) {
            $id .= chip(self::branchName($user['homeBranchId']), 'outline', 'building');
        }
        if ($grade) {
            $id .= chip((string) $grade['title'], 'neutral', 'star');
        }
        $id .= '</div><p class="text-sm text-muted-foreground">Логин для входа: <span class="font-mono text-foreground">' . h($user['login']) . '</span></p></div></div>';
        if ($eid) {
            $id .= '<dl class="grid grid-cols-1 divide-y divide-border border-t border-border sm:grid-cols-3 sm:divide-x sm:divide-y-0">';
            $id .= self::profileStat('Смен за ' . $mon, (string) $units, shift_count_label($units));
            $id .= self::profileStat('Ставка за смену', $grade ? h(money_rub((int) $grade['shiftRate'])) : '—');
            $id .= self::profileStat('Штрафы за ' . $mon, h(money_rub($finesMonth)));
            $id .= '</dl>';
        }

        $perm = card_head('Что вам доступно', h($user['isOwner'] ? 'Владелец видит и меняет всё, включая доступы и пароли других.' : ROLES[$user['role']]['hint']), '', 'key');
        if (!$user['isOwner']) {
            if ($user['perms']) {
                $perm .= '<ul class="mt-4 flex flex-col gap-2.5">';
                foreach ($user['perms'] as $p) {
                    $scoped = !empty(PERMISSIONS[$p]['scoped']) && $user['scopeBranchId'] !== null;
                    $perm .= '<li class="flex items-start gap-2.5 text-sm">' . icon('check', 'mt-0.5 size-4 shrink-0 text-primary') . '<span class="leading-relaxed">' . h(PERMISSIONS[$p]['label'])
                        . ($scoped ? ' <span class="text-muted-foreground">— только ' . h(self::branchName($user['scopeBranchId'])) . '</span>' : '') . '</span></li>';
                }
                $perm .= '</ul>';
            } else {
                $perm .= '<p class="mt-4 text-sm leading-relaxed text-muted-foreground">Вы работаете на смене: смотрите график, отмечаете задания и чистку, оформляете поломки и заявки гостей, сдаёте экзамены. Дополнительные права выдаёт владелец.</p>';
            }
        }

        $left = card($id, 'overflow-hidden') . card($perm, 'p-5');
        if ($eid) {
            $sh = card_head('Ближайшие смены', $upcoming ? 'На две недели вперёд' : 'В графике на две недели вперёд смен нет', '<a class="' . btn_class('ghost', 'sm') . '" href="' . h(url_to('/schedule')) . '">График' . icon('arrow', 'size-4') . '</a>', 'calendar');
            if ($upcoming) {
                $sh .= '<ul class="mt-4 flex flex-col divide-y divide-border">';
                foreach (array_slice($upcoming, 0, 6) as $s) {
                    $type = (string) $s['shiftType'];
                    $sh .= '<li class="flex items-center gap-3 py-2.5 first:pt-0 last:pb-0"><span class="flex size-9 shrink-0 items-center justify-center rounded-md ' . shift_tone_class($type) . '">' . icon(SHIFT_ICONS[$type] ?? 'sun', 'size-4') . '</span>';
                    $sh .= '<div class="flex min-w-0 flex-1 flex-col"><span class="text-sm font-semibold first-letter:uppercase">' . h(format_day_short((string) $s['date'])) . '</span><span class="truncate text-xs text-muted-foreground">' . h((SHIFT_LABELS[$type] ?? $type) . ' · ' . (SHIFT_HINTS[$type] ?? '') . ' · ' . $s['branchName']) . '</span></div>';
                    if (($s['state']['state'] ?? '') === 'live') {
                        $sh .= chip('Сейчас', 'brand');
                    }
                    $sh .= '</li>';
                }
                $sh .= '</ul>';
            }
            $left .= card($sh, 'p-5');
        }

        $ex = card_head('Экзамены', $xp['published'] ? 'Сдано ' . $xp['passed'] . ' из ' . $xp['published'] : 'Пока нет опубликованных экзаменов', '', 'grad');
        if ($xp['published']) {
            $ex .= '<div class="mt-4 flex items-end justify-between gap-3"><p class="font-display text-3xl leading-none font-semibold tabular-nums">' . $xp['level'] . '<span class="ml-1.5 font-sans text-sm font-medium text-muted-foreground">уровень</span></p><p class="text-xs text-muted-foreground tabular-nums">' . h(num_fmt($xp['earned']) . ' / ' . num_fmt($xp['total'])) . ' XP</p></div>';
            $ex .= progress_bar($xp['bar'], 'Прогресс до следующего уровня', 'mt-3');
            $ex .= '<p class="mt-2 text-xs text-muted-foreground">' . h($xp['toNext'] > 0 ? 'До следующего уровня ' . num_fmt($xp['toNext']) . ' XP' : 'Все экзамены сданы') . '</p>';
            $ex .= '<a class="' . btn_class('outline', 'sm') . ' mt-4 w-full" href="' . h(url_to('/exams')) . '">К экзаменам' . icon('arrow', 'size-4') . '</a>';
        }
        $right = card($ex, 'p-5');

        if ($eid) {
            $fi = card_head('Штрафы', h($fines ? 'За ' . $mon . ' — ' . money_rub($finesMonth) . '. Ниже — последние назначения.' : 'Штрафов нет — так держать'), '<a class="' . btn_class('ghost', 'sm') . '" href="' . h(url_to('/fines')) . '">Лист' . icon('arrow', 'size-4') . '</a>', 'wallet');
            if ($fines) {
                $fi .= '<ul class="mt-4 flex flex-col divide-y divide-border">';
                foreach (array_slice($fines, 0, 3) as $f) {
                    $fi .= '<li class="flex items-start gap-3 py-2.5 first:pt-0 last:pb-0"><div class="flex min-w-0 flex-1 flex-col gap-0.5"><p class="text-sm leading-relaxed text-pretty break-words">' . h((string) $f['reason']) . '</p><p class="text-xs text-muted-foreground">' . h(format_dt((string) $f['createdAt'], false)) . '</p></div><span class="shrink-0 font-display text-sm font-semibold tabular-nums">' . h(money_rub((int) $f['amount'])) . '</span></li>';
                }
                $fi .= '</ul>';
            }
            $right .= card($fi, 'p-5');
        }

        $pw = form_open('change_password', 'class="flex flex-col gap-4" data-submit-once');
        $pw .= card_head('Смена пароля', 'Не короче 8 символов. После смены входите с новым паролем.', '', 'lock');
        $pw .= ui_field('Текущий пароль', self::passwordInput('current', 'current-password'));
        $pw .= ui_field('Новый пароль', self::passwordInput('next', 'new-password', 'minlength="8" maxlength="72"'));
        $pw .= ui_field('Повторите новый пароль', self::passwordInput('confirm', 'new-password', 'minlength="8" maxlength="72"'));
        $pw .= '<button class="' . btn_class() . ' sm:self-start">' . icon('save', 'size-4') . 'Сменить пароль</button></form>';
        $right .= card($pw, 'p-5');

        $html .= '<div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]"><div class="flex min-w-0 flex-col gap-6">' . $left . '</div><div class="flex min-w-0 flex-col gap-6">' . $right . '</div></div>';
        self::layout($user, $html, ['title' => 'Мой профиль']);
    }

    /* ---------- Журнал действий ---------- */

    private static function pageJournal(array $user): void
    {
        $entity = self::getStr('entity', 32);
        $entity = isset(AUDIT_ENTITIES[$entity]) ? $entity : '';
        $actor = self::getStr('actor', 64);
        $per = 40;
        $page = max(1, (int) ($_GET['page'] ?? 1));

        $byEntity = [];
        foreach (Db::all('SELECT entity, COUNT(*) AS n FROM audit_log' . ($actor !== '' ? ' WHERE "actorUserId" = ?' : '') . ' GROUP BY entity', $actor !== '' ? [$actor] : []) as $r) {
            $byEntity[(string) $r['entity']] = (int) $r['n'];
        }
        $where = [];
        $params = [];
        if ($entity !== '') {
            $where[] = 'entity = ?';
            $params[] = $entity;
        }
        if ($actor !== '') {
            $where[] = '"actorUserId" = ?';
            $params[] = $actor;
        }
        $sqlWhere = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $total = (int) Db::value('SELECT COUNT(*) FROM audit_log' . $sqlWhere, $params);
        $pages = max(1, (int) ceil($total / $per));
        $page = min($page, $pages);
        $rows = Db::all('SELECT * FROM audit_log' . $sqlWhere . ' ORDER BY "createdAt" DESC, id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), $params);
        $actors = Db::all('SELECT "actorUserId" AS id, MAX("actorName") AS name, COUNT(*) AS n FROM audit_log GROUP BY "actorUserId" ORDER BY MAX("actorName")');

        $html = page_head('Журнал действий', 'Кто, что и когда изменил в портале: смены, штрафы, права, тарифы. Записи хранятся год.');

        $keep = $actor !== '' ? ['actor' => $actor] : [];
        $seg = [['href' => url_to('/manage/journal', $keep), 'label' => 'Все', 'active' => $entity === '', 'count' => array_sum($byEntity)]];
        foreach (AUDIT_ENTITIES as $key => $label) {
            if (empty($byEntity[$key]) && $key !== $entity) {
                continue;
            }
            $seg[] = ['href' => url_to('/manage/journal', $keep + ['entity' => $key]), 'label' => $label, 'active' => $key === $entity, 'count' => $byEntity[$key] ?? 0];
        }
        $filter = '<form method="get" action="' . h(url_to('/manage/journal')) . '" class="flex items-center gap-2">';
        if ($entity !== '') {
            $filter .= hidden('entity', $entity);
        }
        $opts = ['' => 'Все сотрудники'];
        foreach ($actors as $a) {
            $opts[(string) $a['id']] = $a['name'] . ' · ' . $a['n'];
        }
        $filter .= '<label class="sr-only" for="journal-actor">Чьи действия показать</label>' . ui_select('actor', $opts, $actor, 'id="journal-actor" data-autosubmit', 'sm:w-60');
        $filter .= '<noscript><button class="' . btn_class('outline', 'sm') . '">Показать</button></noscript></form>';
        $html .= '<div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">' . segmented($seg, 'Раздел') . $filter . '</div>';

        if (!$rows) {
            $html .= empty_state('history', $total === 0 && $entity === '' && $actor === '' ? 'Журнал пока пуст' : 'Нет записей', $total === 0 && $entity === '' && $actor === '' ? 'Здесь появятся действия сотрудников: кто поставил смену, выписал штраф, поменял права или тарифы.' : 'По этому фильтру действий не найдено.');
            self::layout($user, $html, ['title' => 'Журнал действий']);
            return;
        }

        $now = now_tyumen();
        $today = $now->format('Y-m-d');
        $yesterday = $now->modify('-1 day')->format('Y-m-d');
        $tz = new DateTimeZone(APP_TZ);
        $list = '<div class="flex flex-col gap-6">';
        $day = null;
        foreach ($rows as $r) {
            try {
                $dt = (new DateTimeImmutable((string) $r['createdAt']))->setTimezone($tz);
            } catch (Exception) {
                $dt = $now;
            }
            $key = $dt->format('Y-m-d');
            if ($key !== $day) {
                if ($day !== null) {
                    $list .= '</ol></section>';
                }
                $day = $key;
                $label = $key === $today ? 'Сегодня' : ($key === $yesterday ? 'Вчера' : format_date_human($key));
                $list .= '<section class="flex flex-col gap-2"><h2 class="text-xs font-semibold tracking-wide text-muted-foreground uppercase">' . h($label) . '</h2>';
                $list .= '<ol class="flex flex-col divide-y divide-border rounded-lg border border-border bg-card px-4 shadow-xs">';
            }
            $ent = (string) $r['entity'];
            $href = (string) ($r['href'] ?? '');
            $summary = h((string) $r['summary']);
            if (str_starts_with($href, '/') && !str_starts_with($href, '//') && $href !== '/') {
                $summary = '<a class="underline-offset-4 hover:underline" href="' . h($href) . '">' . $summary . '</a>';
            }
            $list .= '<li class="flex items-start gap-3 py-3"><span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-md bg-muted text-foreground" title="' . h(AUDIT_ENTITIES[$ent] ?? $ent) . '">' . icon(self::auditIcon($ent), 'size-4') . '</span>';
            $list .= '<div class="flex min-w-0 flex-1 flex-col gap-1"><p class="text-sm leading-relaxed text-pretty break-words">' . $summary . '</p>';
            $list .= '<p class="flex flex-wrap items-center gap-x-1.5 text-xs text-muted-foreground"><span class="font-medium text-foreground">' . h(short_name((string) $r['actorName'])) . '</span><span>· ' . h(role_label((string) $r['actorRole'])) . '</span><span>· ' . h(AUDIT_ENTITIES[$ent] ?? $ent) . '</span></p></div>';
            $list .= '<time class="shrink-0 pt-0.5 text-xs text-muted-foreground tabular-nums" datetime="' . h($dt->format(DATE_ATOM)) . '">' . $dt->format('H:i') . '</time></li>';
        }
        $list .= '</ol></section></div>';
        $html .= $list;

        if ($pages > 1) {
            $q = array_filter(['entity' => $entity, 'actor' => $actor], static fn ($v) => $v !== '');
            $pager = '<nav class="flex items-center justify-between gap-3" aria-label="Страницы журнала">';
            $pager .= $page > 1 ? '<a class="' . btn_class('outline', 'sm') . '" href="' . h(url_to('/manage/journal', $q + ['page' => $page - 1])) . '">' . icon('chevronleft') . 'Новее</a>' : '<span></span>';
            $pager .= '<span class="text-sm text-muted-foreground tabular-nums">Страница ' . $page . ' из ' . $pages . '</span>';
            $pager .= $page < $pages ? '<a class="' . btn_class('outline', 'sm') . '" href="' . h(url_to('/manage/journal', $q + ['page' => $page + 1])) . '">Раньше' . icon('chevronright') . '</a>' : '<span></span>';
            $html .= $pager . '</nav>';
        }
        self::layout($user, $html, ['title' => 'Журнал действий']);
    }

    /* ---------- Сотрудники ---------- */

    private static function employeeForm(string $action, ?array $e, array $grades, ?array $old = null): string
    {
        $name = (string) ($old['name'] ?? $e['name'] ?? '');
        $branch = $old['homeBranchId'] ?? ($e ? (string) (int) $e['homeBranchId'] : null);
        $grade = $old['gradeId'] ?? ($e && $e['gradeId'] !== null ? (string) (int) $e['gradeId'] : '');
        $f = form_open($action, 'class="flex flex-col gap-4" data-submit-once');
        if ($e) {
            $f .= hidden('id', (int) $e['id']);
        }
        $f .= ui_field('Фамилия и имя', '<input name="name" class="' . field_class() . '" required minlength="2" maxlength="60" value="' . h($name) . '" placeholder="Иванов Пётр" autocomplete="off">', 'Можно указать полное ФИО — в графике оно отображается без сокращения');
        $f .= '<div class="grid gap-4 sm:grid-cols-2">';
        $f .= ui_field('Филиал', ui_select('homeBranchId', self::branchOptions(), $branch, 'required'));
        $f .= ui_field('Уровень', ui_select('gradeId', self::gradeOptions($grades), $grade));
        $f .= '</div><div class="flex flex-wrap justify-end gap-2 pt-1"><button type="button" class="' . btn_class('ghost') . '" data-dialog-close>Отмена</button>';
        $f .= '<button class="' . btn_class() . '">' . icon($e ? 'save' : 'user-plus', 'size-4') . ($e ? 'Сохранить' : 'Добавить') . '</button></div></form>';
        return $f;
    }

    private static function pageEmployees(array $user): void
    {
        $branches = self::branches();
        $grades = self::gradeRows();
        $logins = [];
        foreach (Db::all('SELECT id, email, role FROM "user"') as $u) {
            $logins[(string) $u['id']] = ['login' => self::loginOf((string) $u['email']), 'role' => normalize_role($u['role'])];
        }
        $now = now_tyumen();
        $today = date_key($now);
        $mon = mb_strtolower(MONTH_NAMES[(int) $now->format('n') - 1]);
        $units = self::shiftUnits($now->format('Y-m-01'), $now->format('Y-m-t'));
        $nextShift = [];
        foreach (Db::all('SELECT "employeeId", MIN(date) AS d FROM shifts WHERE date >= ? GROUP BY "employeeId"', [$today]) as $r) {
            $nextShift[(int) $r['employeeId']] = substr((string) $r['d'], 0, 10);
        }
        $all = self::employees();
        $active = array_values(array_filter($all, static fn ($e) => boolish($e['isActive'])));
        $archived = array_values(array_filter($all, static fn ($e) => !boolish($e['isActive'])));

        $slug = self::getStr('branch', 64);
        $current = null;
        foreach ($branches as $b) {
            if ($b['slug'] === $slug) {
                $current = $b;
            }
        }
        $countBy = [];
        foreach ($active as $e) {
            $countBy[(int) $e['homeBranchId']] = ($countBy[(int) $e['homeBranchId']] ?? 0) + 1;
        }
        $seg = [['href' => url_to('/manage/employees'), 'label' => 'Все', 'active' => $current === null, 'count' => count($active)]];
        foreach ($branches as $b) {
            $seg[] = ['href' => url_to('/manage/employees', ['branch' => $b['slug']]), 'label' => $b['name'], 'active' => $current !== null && $current['id'] === $b['id'], 'count' => $countBy[$b['id']] ?? 0];
        }
        $list = $current ? array_values(array_filter($active, static fn ($e) => (int) $e['homeBranchId'] === $current['id'])) : $active;

        $actions = '<button type="button" class="' . btn_class() . '" data-dialog-open="emp-new">' . icon('user-plus', 'size-4') . 'Добавить сотрудника</button>';
        $actions .= '<a class="' . btn_class('outline') . '" href="' . h(url_to('/manage/grades')) . '">' . icon('wallet', 'size-4') . 'Уровни и ставки</a>';
        $html = page_head('Сотрудники', 'Карточки сотрудников: филиал, уровень и ставка. Архивные не попадают в график, но их история смен и штрафов сохраняется.', $actions);
        $html .= '<div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">' . segmented($seg, 'Филиал') . search_input('data-table-filter="emp-table"', 'Поиск по имени или логину', 'w-full sm:max-w-xs') . '</div>';

        $dialogs = '';
        if ($list) {
            $headers = ['Сотрудник'];
            if (!$current) {
                $headers[] = 'Филиал';
            }
            $headers = array_merge($headers, ['Уровень', ['Смен за ' . $mon, 'text-right whitespace-nowrap'], 'Ближайшая смена', ['Действия', 'sr-only']]);
            $table = table_open($headers, 'id="emp-table"');
            foreach ($list as $e) {
                $eid = (int) $e['id'];
                $name = (string) $e['name'];
                $acc = $e['userId'] ? ($logins[(string) $e['userId']] ?? null) : null;
                $table .= '<tr class="border-t border-border" data-filter-text="' . h(mb_strtolower($name . ' ' . ($acc['login'] ?? ''))) . '">';
                $table .= '<th scope="row" class="px-4 py-3 text-left font-normal" data-label="Сотрудник"><div class="flex items-center gap-3">' . avatar($name) . '<div class="flex min-w-0 flex-col"><span class="font-semibold">' . h($name) . '</span><span class="text-xs text-muted-foreground">'
                    . ($acc ? 'вход: <span class="font-mono">' . h($acc['login']) . '</span> · ' . h(role_label($acc['role'])) : 'без входа в портал') . '</span></div></div></th>';
                if (!$current) {
                    $table .= td('Филиал', h(self::branchName((int) $e['homeBranchId'])), 'whitespace-nowrap');
                }
                $gradeForm = form_open('assign_grade', 'class="min-w-44"') . hidden('employeeId', $eid)
                    . '<label class="sr-only" for="grade-' . $eid . '">Уровень: ' . h($name) . '</label>'
                    . ui_select('gradeId', self::gradeOptions($grades), $e['gradeId'] === null ? '' : (string) (int) $e['gradeId'], 'id="grade-' . $eid . '" data-autosubmit')
                    . '<noscript><button class="' . btn_class('outline', 'xs') . ' mt-1">Сохранить</button></noscript></form>';
                $table .= td('Уровень', $gradeForm);
                $table .= td('Смен за ' . $mon, '<span class="font-semibold tabular-nums">' . ($units[$eid] ?? 0) . '</span>', 'text-right');
                $next = $nextShift[$eid] ?? null;
                $table .= td('Ближайшая смена', $next ? '<span class="whitespace-nowrap first-letter:uppercase">' . h($next === $today ? 'сегодня' : format_day_short($next)) . '</span>' : '<span class="text-muted-foreground">нет в графике</span>');
                $act = '<div class="flex items-center justify-end gap-1">';
                $act .= '<button type="button" class="' . btn_class('ghost', 'icon-sm') . '" data-dialog-open="emp-' . $eid . '" aria-label="Изменить карточку: ' . h($name) . '" title="Изменить">' . icon('pencil') . '</button>';
                $act .= form_open('toggle_employee', confirm_attr('Отправить «' . $name . '» в архив? Будущие смены и задания уберутся из графика, история сохранится.')) . hidden('id', $eid)
                    . '<button class="' . btn_class('danger-ghost', 'icon-sm') . '" aria-label="В архив: ' . h($name) . '" title="В архив">' . icon('inbox') . '</button></form></div>';
                $table .= td('Действия', $act, 'text-right') . '</tr>';
                $dialogs .= dialog_html('emp-' . $eid, 'Карточка сотрудника', self::employeeForm('update_employee', $e, $grades));
            }
            $html .= $table . table_close();
            $html .= '<p class="hidden text-sm text-muted-foreground" data-filter-empty="emp-table">Никого не нашлось — проверьте написание или выберите «Все».</p>';
        } else {
            $html .= empty_state('users', $current ? 'В филиале ' . $current['name'] . ' пока никого' : 'Сотрудников пока нет', 'Добавьте карточку — после этого сотрудника можно ставить в график и выдать ему вход в портал.', '<button type="button" class="' . btn_class() . '" data-dialog-open="emp-new">' . icon('user-plus', 'size-4') . 'Добавить сотрудника</button>');
        }

        if ($archived) {
            $arch = '<ul class="flex flex-col divide-y divide-border">';
            foreach ($archived as $e) {
                $eid = (int) $e['id'];
                $name = (string) $e['name'];
                $arch .= '<li class="flex flex-wrap items-center gap-3 px-4 py-3"><div class="flex min-w-0 flex-1 flex-col"><span class="text-sm font-medium">' . h($name) . '</span><span class="text-xs text-muted-foreground">' . h(self::branchName((int) $e['homeBranchId'])) . ($e['userId'] ? ' · вход в портал сохранён' : '') . '</span></div>';
                $arch .= '<div class="flex items-center gap-2">' . form_open('toggle_employee') . hidden('id', $eid) . '<button class="' . btn_class('outline', 'sm') . '">' . icon('refresh', 'size-4') . 'Вернуть</button></form>';
                $arch .= form_open('delete_employee', confirm_attr('Удалить «' . $name . '» навсегда? Удалятся его смены, задания, закреплённые места и штрафы. Отменить это нельзя.')) . hidden('id', $eid)
                    . '<button class="' . btn_class('danger-ghost', 'sm') . '">' . icon('trash', 'size-4') . 'Удалить</button></form></div></li>';
            }
            $html .= self::collapsible('А��хив', (string) count($archived), $arch . '</ul>', 'inbox');
        }

        $old = form_stash_take('employee-new');
        $html .= $dialogs . dialog_html('emp-new', 'Новый сотрудник', self::employeeForm('save_employee', null, $grades, $old), 'max-w-lg', $old ? 'data-dialog-autoopen' : '');
        self::layout($user, $html, ['title' => 'Сотрудники', 'width' => 'wide']);
    }

    /* ---------- Уровни, ставки и начисления ---------- */

    private static function pageGrades(array $user): void
    {
        $grades = self::gradeRows();
        $gradeById = [];
        foreach ($grades as $g) {
            $gradeById[$g['id']] = $g;
        }
        $month = self::monthFromQuery();
        $units = self::shiftUnits($month['from'], $month['to']);
        $fines = self::finesTotals($month['from'], $month['to']);
        $bar = Langame::totalsByEmployee($month['from'], $month['to']);
        $hasBar = (bool) Langame::enabledBranchIds() || $bar;
        $emps = self::employees();
        $countByGrade = [];
        foreach ($emps as $e) {
            if (boolish($e['isActive']) && $e['gradeId'] !== null) {
                $countByGrade[(int) $e['gradeId']] = ($countByGrade[(int) $e['gradeId']] ?? 0) + 1;
            }
        }

        $html = page_head('Уровни и ставки', 'Ставка — сумма за одну смену 12 часов. Сутки считаются за две смены. Начисления считаются по графику смен минус штрафы.', '<a class="' . btn_class('outline') . '" href="' . h(url_to('/manage/employees')) . '">' . icon('users', 'size-4') . 'Сотрудники</a>');

        $gl = card_head('Уровни', $grades ? h(plural(count($grades), 'уровень', 'уровня', 'уровней')) : 'Пока нет ни одного', '', 'star');
        if ($grades) {
            $gl .= '<ul class="mt-4 flex flex-col divide-y divide-border">';
            foreach ($grades as $g) {
                $n = $countByGrade[$g['id']] ?? 0;
                $title = (string) $g['title'];
                $gl .= '<li class="flex flex-col gap-2 py-3 first:pt-0 last:pb-0">';
                $gl .= form_open('save_grade', 'class="flex items-end gap-2"') . hidden('id', $g['id']);
                $gl .= ui_field('Название', '<input name="title" class="' . field_class() . '" required minlength="2" maxlength="40" value="' . h($title) . '">', '', 'flex-1');
                $gl .= ui_field('₽ за смену', '<input name="shiftRate" type="number" inputmode="numeric" min="0" max="100000" step="50" class="' . field_class('tabular-nums') . '" required value="' . $g['shiftRate'] . '">', '', 'w-28 shrink-0');
                $gl .= '<button class="' . btn_class('outline', 'icon') . '" aria-label="Сохранить уровень «' . h($title) . '»" title="Сохранить">' . icon('save') . '</button></form>';
                $gl .= '<div class="flex items-center justify-between gap-2"><span class="text-xs text-muted-foreground">' . h($n ? plural($n, 'сотрудник', 'сотрудника', 'сотрудников') : 'Никому не назначен') . '</span>';
                $gl .= form_open('delete_grade', confirm_attr('Удалить уровень «' . $title . '»?' . ($n ? ' У ' . plural($n, 'сотрудника', 'сотрудников', 'сотрудников') . ' уровень станет пустым.' : ''))) . hidden('id', $g['id'])
                    . '<button class="' . btn_class('danger-ghost', 'xs') . '">' . icon('trash', 'size-3.5') . 'Удалить</button></form></div></li>';
            }
            $gl .= '</ul>';
        }
        $gl .= form_open('save_grade', 'class="mt-5 flex flex-col gap-3 border-t border-border pt-5"') . hidden('id', 0);
        $gl .= '<p class="text-sm font-semibold">Новый уровень</p><div class="flex items-end gap-2">';
        $gl .= ui_field('Название', '<input name="title" class="' . field_class() . '" required minlength="2" maxlength="40" placeholder="Например, Старший">', '', 'flex-1');
        $gl .= ui_field('₽ за смену', '<input name="shiftRate" type="number" inputmode="numeric" min="0" max="100000" step="50" class="' . field_class('tabular-nums') . '" required placeholder="2000">', '', 'w-28 shrink-0');
        $gl .= '</div><button class="' . btn_class() . ' sm:self-start">' . icon('plus', 'size-4') . 'Добавить уровень</button></form>';

        $history = Db::all('SELECT h.*, e.name AS "employeeName" FROM employee_grade_history h LEFT JOIN employees e ON e.id = h."employeeId" ORDER BY h."changedAt" DESC, h.id DESC LIMIT 12');
        $hist = card_head('История назначений', $history ? 'Последние изменения уровней' : 'Изменений пока не было', '', 'history');
        if ($history) {
            $hist .= '<ul class="mt-4 flex flex-col divide-y divide-border">';
            foreach ($history as $r) {
                $hist .= '<li class="flex flex-col gap-0.5 py-2.5 first:pt-0 last:pb-0"><p class="text-sm"><span class="font-semibold">' . h(short_name((string) ($r['employeeName'] ?? 'Удалённый сотрудник'))) . '</span> → ' . h((string) $r['gradeTitle'])
                    . ((int) $r['shiftRate'] > 0 ? ' <span class="text-muted-foreground tabular-nums">· ' . h(money_rub((int) $r['shiftRate'])) . '</span>' : '') . '</p><p class="text-xs text-muted-foreground">' . h(format_dt((string) $r['changedAt']) . ' · ' . short_name((string) $r['changedByName'])) . '</p></li>';
            }
            $hist .= '</ul>';
        }

        $rows = [];
        $sum = ['units' => 0, 'gross' => 0, 'fines' => 0, 'bar' => 0];
        foreach ($emps as $e) {
            $eid = (int) $e['id'];
            $u = $units[$eid] ?? 0;
            $fn = $fines[$eid] ?? 0;
            $br = isset($bar[$eid]) ? (int) round($bar[$eid]['earn'] / 100) : 0;
            if (!boolish($e['isActive']) && $u === 0 && $fn === 0 && $br === 0) {
                continue;
            }
            $g = $e['gradeId'] !== null ? ($gradeById[(int) $e['gradeId']] ?? null) : null;
            $gross = $g ? $u * $g['shiftRate'] : 0;
            $rows[] = ['e' => $e, 'g' => $g, 'units' => $u, 'gross' => $gross, 'fines' => $fn, 'bar' => $br, 'barRev' => $bar[$eid]['revenue'] ?? 0];
            $sum['bar'] += $br;
            $sum['units'] += $u;
            $sum['gross'] += $gross;
            $sum['fines'] += $fn;
        }

        $nav = '<div class="flex items-center gap-1"><a class="' . btn_class('outline', 'icon-sm') . '" href="' . h(url_to('/manage/grades', ['m' => $month['prev']])) . '" aria-label="Предыдущий месяц">' . icon('chevronleft') . '</a>';
        $nav .= '<span class="min-w-36 text-center font-display text-sm font-semibold">' . h($month['title']) . '</span>';
        $nav .= '<a class="' . btn_class('outline', 'icon-sm') . '" href="' . h(url_to('/manage/grades', ['m' => $month['next']])) . '" aria-label="Следующий месяц">' . icon('chevronright') . '</a></div>';
        $pay = '<section class="flex min-w-0 flex-col gap-4">' . section_title('Начисления за ' . $month['name'], $nav);
        $pay .= '<div class="grid grid-cols-2 gap-3 ' . ($hasBar ? 'xl:grid-cols-5' : 'xl:grid-cols-4') . '">';
        $pay .= stat_tile('Смен', h(num_fmt($sum['units'])), 'сутки — за две');
        $pay .= stat_tile('По ставкам', h(money_rub($sum['gross'])));
        if ($hasBar) {
            $pay .= stat_tile('% с бара', h(money_rub($sum['bar'])), 'из Langame', url_to('/bar'), 'coins');
        }
        $pay .= stat_tile('Штрафы', h(money_rub($sum['fines'])), '', url_to('/fines'), 'scale', $sum['fines'] > 0);
        $pay .= stat_tile('К выплате', h(money_rub($sum['gross'] + $sum['bar'] - $sum['fines'])), $month['isCurrent'] ? 'месяц ещё идёт' : '');
        $pay .= '</div>';
        if ($rows) {
            $pay .= table_open(array_merge(['Сотрудник', 'Уровень', ['Смен', 'text-right'], ['По ставке', 'text-right whitespace-nowrap']], $hasBar ? [['% с бара', 'text-right whitespace-nowrap']] : [], [['Штрафы', 'text-right'], ['К выплате', 'text-right whitespace-nowrap']]));
            foreach ($rows as $r) {
                $e = $r['e'];
                $net = $r['gross'] + $r['bar'] - $r['fines'];
                $pay .= '<tr class="border-t border-border"><th scope="row" class="px-4 py-3 text-left font-normal" data-label="Сотрудник"><span class="block font-semibold">' . h((string) $e['name']) . '</span><span class="text-xs text-muted-foreground">' . h(self::branchName((int) $e['homeBranchId'])) . (!boolish($e['isActive']) ? ' · в архиве' : '') . '</span></th>';
                $pay .= td('Уровень', $r['g'] ? h((string) $r['g']['title']) . '<span class="block text-xs text-muted-foreground tabular-nums">' . h(money_rub($r['g']['shiftRate'])) . ' за смену</span>' : '<a class="text-sm text-primary-ink underline-offset-4 hover:underline" href="' . h(url_to('/manage/employees')) . '">Назначить уровень</a>');
                $pay .= td('Смен', '<span class="tabular-nums">' . $r['units'] . '</span>', 'text-right');
                $pay .= td('По ставке', '<span class="whitespace-nowrap tabular-nums">' . h(money_rub($r['gross'])) . '</span>', 'text-right');
                if ($hasBar) {
                    $pay .= td('% с бара', $r['bar'] > 0 ? '<span class="whitespace-nowrap tabular-nums">+' . h(money_rub($r['bar'])) . '</span><span class="block text-xs text-muted-foreground tabular-nums">с ' . h(kop_rub((int) $r['barRev'])) . '</span>' : '<span class="text-muted-foreground">—</span>', 'text-right');
                }
                $pay .= td('Штрафы', $r['fines'] > 0 ? '<span class="whitespace-nowrap text-primary-ink tabular-nums">−' . h(money_rub($r['fines'])) . '</span>' : '<span class="text-muted-foreground">—</span>', 'text-right');
                $pay .= td('К выплате', '<span class="whitespace-nowrap font-display font-semibold tabular-nums">' . h(money_rub($net)) . '</span>', 'text-right');
                $pay .= '</tr>';
            }
            $pay .= table_close();
            $pay .= '<p class="text-xs leading-relaxed text-muted-foreground">Расчёт по графику: если смена не состоялась или её отработал другой человек — поправьте график, и сумма пересчитается.</p>';
        } else {
            $pay .= empty_state('wallet', 'Нет смен за ' . $month['name'], 'Начисления появятся, когда в графике будут смены этого месяца.');
        }
        $pay .= '</section>';

        $html .= '<div class="grid items-start gap-6 xl:grid-cols-[24rem_minmax(0,1fr)]"><div class="flex min-w-0 flex-col gap-6">' . card($gl, 'p-5') . card($hist, 'p-5') . '</div>' . $pay . '</div>';
        self::layout($user, $html, ['title' => 'Уровни и ставки', 'width' => 'wide']);
    }

    /* ---------- Доступы и права (владелец) ---------- */

    private static function roleRadios(string $current): string
    {
        $h = '<fieldset class="flex flex-col"><legend class="mb-2 text-sm font-medium">Роль</legend><div class="grid gap-2 sm:grid-cols-2">';
        foreach (ROLES as $key => $r) {
            $h .= '<label class="flex cursor-pointer items-start gap-3 rounded-md border border-border p-3 transition-colors hover:bg-muted/50 has-[:checked]:border-foreground has-[:checked]:bg-muted/60">';
            $h .= '<input type="radio" name="role" value="' . h($key) . '" class="mt-0.5 ' . check_class() . '"' . ($key === $current ? ' checked' : '') . ' data-role-radio>';
            $h .= '<span class="flex min-w-0 flex-col gap-0.5"><span class="text-sm font-semibold">' . h($r['label']) . '</span><span class="text-xs leading-relaxed text-muted-foreground">' . h($r['hint']) . '</span></span></label>';
        }
        return $h . '</div></fieldset>';
    }

    private static function permChecks(array $selected, bool $disabled): string
    {
        $groups = [];
        foreach (PERMISSIONS as $key => $p) {
            $groups[$p['group']][$key] = $p;
        }
        $h = '<fieldset class="flex flex-col gap-4 disabled:opacity-60" data-access-perms' . ($disabled ? ' disabled' : '') . '><legend class="mb-1 text-sm font-medium">Права <span class="font-normal text-muted-foreground">— у владельца есть все</span></legend>';
        foreach ($groups as $g => $items) {
            $h .= '<div class="flex flex-col gap-0.5"><p class="text-[11px] font-semibold tracking-[0.14em] text-muted-foreground uppercase">' . h($g) . '</p>';
            foreach ($items as $key => $p) {
                $h .= '<label class="flex cursor-pointer items-start gap-3 rounded-md px-1.5 py-2 hover:bg-muted/50"><input type="checkbox" name="perms[]" value="' . h($key) . '" class="mt-0.5 ' . check_class() . '"' . (in_array($key, $selected, true) ? ' checked' : '') . '>';
                $h .= '<span class="flex min-w-0 flex-col gap-0.5"><span class="text-sm font-medium">' . h($p['label']);
                if (!empty($p['scoped'])) {
                    $h .= ' <span class="ml-1 rounded-sm bg-muted px-1 py-px text-[10px] font-semibold tracking-wide text-muted-foreground uppercase">филиал</span>';
                }
                $h .= '</span><span class="text-xs leading-relaxed text-muted-foreground">' . h($p['hint']) . '</span></span></label>';
            }
            $h .= '</div>';
        }
        return $h . '</fieldset>';
    }

    private static function scopeSelect(?int $scope, bool $disabled): string
    {
        return ui_field(
            'Ограничить филиалом',
            ui_select('scopeBranchId', ['' => 'Все филиалы'] + self::branchOptions(), $scope === null ? '' : (string) $scope, 'data-access-scope' . ($disabled ? ' disabled' : '')),
            'Права с пометкой «филиал» будут действовать только в нём. Остальные — во всём клубе.',
        );
    }

    private static function rolePresetsAttr(): string
    {
        $presets = [];
        foreach (array_keys(ROLES) as $r) {
            $presets[$r] = role_preset($r);
        }
        return 'data-access-form data-presets="' . h((string) json_encode($presets)) . '"';
    }

    private static function accessForm(array $a): string
    {
        $isOwner = $a['role'] === 'owner';
        $f = form_open('update_access', 'class="flex flex-col gap-5" data-submit-once ' . self::rolePresetsAttr()) . hidden('userId', (string) $a['id']);
        $f .= self::roleRadios($a['role']);
        $f .= self::permChecks($a['perms'], $isOwner);
        $f .= self::scopeSelect($a['scope'], $isOwner);
        $f .= '<div class="flex flex-wrap justify-end gap-2 border-t border-border pt-4"><button type="button" class="' . btn_class('ghost') . '" data-dialog-close>Отмена</button><button class="' . btn_class() . '">' . icon('save', 'size-4') . 'Сохранить права</button></div></form>';
        return $f;
    }

    private static function newAccountForm(array $empOpts, ?array $old): string
    {
        $f = form_open('save_account', 'class="flex flex-col gap-4" data-submit-once data-new-account');
        $f .= ui_field('Сотрудник', ui_select('employeeId', $empOpts, (string) ($old['employeeId'] ?? ''), 'data-acc-employee'), 'Выберите карточку без входа или создайте новую.');
        $f .= '<div class="flex flex-col gap-4" data-acc-new-fields><div class="grid gap-4 sm:grid-cols-2">';
        $f .= ui_field('Фамилия и имя', '<input name="name" class="' . field_class() . '" maxlength="60" placeholder="Иванов Пётр" autocomplete="off" value="' . h((string) ($old['name'] ?? '')) . '">');
        $f .= ui_field('Филиал', ui_select('homeBranchId', self::branchOptions(), (string) ($old['homeBranchId'] ?? '')));
        $f .= '</div><label class="flex items-start gap-2.5 text-sm"><input type="checkbox" name="onShifts" value="1" class="mt-0.5 ' . check_class() . '"' . (($old['onShifts'] ?? true) ? ' checked' : '') . '><span class="leading-relaxed">Работает на сменах <span class="text-muted-foreground">— появится в графике и списках сотрудников</span></span></label></div>';
        $f .= '<div class="grid gap-4 sm:grid-cols-2">';
        $f .= ui_field('Логин', '<input name="login" class="' . field_class('font-mono') . '" required minlength="3" maxlength="32" pattern="[A-Za-z0-9._\-]{3,32}" autocomplete="off" autocapitalize="none" spellcheck="false" placeholder="ivanov" value="' . h((string) ($old['login'] ?? '')) . '">', 'Латиница, цифры, точка, дефис');
        $f .= ui_field('Роль', ui_select('role', array_map(static fn (array $r): string => $r['label'], ROLES), (string) ($old['role'] ?? 'admin')));
        $f .= '</div>';
        $f .= ui_field('Пароль', self::generatedPasswordInput(), 'Не короче 8 символов. Скопируйте и передайте сотруднику — потом пароль нигде не показывается.');
        $f .= ui_field('Ограничить филиалом', ui_select('scopeBranchId', ['' => 'Все филиалы'] + self::branchOptions(), (string) ($old['scopeBranchId'] ?? '')), 'Для старших админов: график и задания только своего филиала.');
        $f .= '<div class="flex flex-wrap justify-end gap-2 border-t border-border pt-4"><button type="button" class="' . btn_class('ghost') . '" data-dialog-close>Отмена</button><button class="' . btn_class() . '">' . icon('key', 'size-4') . 'Создать доступ</button></div></form>';
        return $f;
    }

    private static function pageAccounts(array $user): void
    {
        $rows = Db::all(
            'SELECT u.id, u.name, u.email, u.role, u.permissions, u."scopeBranchId", e.id AS "employeeId", e.name AS "employeeName", e."homeBranchId", e."isActive"
             FROM "user" u LEFT JOIN employees e ON e."userId" = u.id',
        );
        $accounts = [];
        foreach ($rows as $r) {
            $id = (string) $r['id'];
            if (isset($accounts[$id])) {
                continue;
            }
            $r['role'] = normalize_role($r['role']);
            $r['perms'] = perms_from_row($r);
            $r['custom'] = $r['role'] !== 'owner' && is_string($r['permissions']) && $r['permissions'] !== '';
            $r['scope'] = $r['role'] !== 'owner' && $r['scopeBranchId'] !== null && $r['scopeBranchId'] !== '' ? (int) $r['scopeBranchId'] : null;
            $r['display'] = (string) ($r['employeeName'] ?: $r['name']);
            $r['login'] = self::loginOf((string) $r['email']);
            $accounts[$id] = $r;
        }
        $order = array_flip(array_keys(ROLES));
        uasort($accounts, static fn ($a, $b) => [$order[$a['role']], mb_strtolower($a['display'])] <=> [$order[$b['role']], mb_strtolower($b['display'])]);

        $byRole = array_fill_keys(array_keys(ROLES), 0);
        foreach ($accounts as $a) {
            $byRole[$a['role']]++;
        }
        $free = [];
        foreach (self::branches() as $b) {
            $list = [];
            foreach (self::employees(true) as $e) {
                if (empty($e['userId']) && (int) $e['homeBranchId'] === $b['id']) {
                    $list[(string) (int) $e['id']] = (string) $e['name'];
                }
            }
            if ($list) {
                $free[$b['name']] = $list;
            }
        }

        $actions = '<button type="button" class="' . btn_class() . '" data-dialog-open="acc-new">' . icon('user-plus', 'size-4') . 'Выдать доступ</button>';
        $html = page_head('Доступы и права', 'Кто может войти в портал и что ему разрешено. Права применяются сразу — сотруднику не нужно перезаходить.', $actions);

        $summary = [];
        foreach (ROLES as $key => $r) {
            if ($byRole[$key] > 0) {
                $summary[] = $r['label'] . ' — ' . $byRole[$key];
            }
        }
        $html .= '<div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><p class="text-sm text-muted-foreground">' . h(plural(count($accounts), 'доступ', 'доступа', 'доступов') . ($summary ? ': ' . implode(', ', $summary) : '')) . '</p>'
            . search_input('data-table-filter="acc-table"', 'Поиск по имени или логину', 'w-full sm:max-w-xs') . '</div>';

        $dialogs = '';
        $table = table_open(['Сотрудник', 'Роль', 'Права', ['Действия', 'sr-only']], 'id="acc-table"');
        foreach ($accounts as $a) {
            $uid = (string) $a['id'];
            $isMe = $uid === $user['id'];
            $did = 'acc-' . preg_replace('/[^A-Za-z0-9_-]/', '', $uid);
            $table .= '<tr class="border-t border-border" data-filter-text="' . h(mb_strtolower($a['display'] . ' ' . $a['login'])) . '">';
            $table .= '<th scope="row" class="px-4 py-3 text-left font-normal" data-label="Сотрудник"><div class="flex items-center gap-3">' . avatar($a['display']) . '<div class="flex min-w-0 flex-col"><span class="font-semibold">' . h($a['display']) . ($isMe ? ' <span class="font-normal text-muted-foreground">(вы)</span>' : '') . '</span>';
            $table .= '<span class="text-xs text-muted-foreground"><span class="font-mono">' . h($a['login']) . '</span>' . h($a['employeeId'] ? ' · ' . self::branchName((int) $a['homeBranchId']) . (!boolish($a['isActive']) ? ' · карточка в архиве' : '') : ' · без карточки сотрудника') . '</span></div></div></th>';
            $table .= td('Роль', chip(role_label($a['role']), $a['role'] === 'owner' ? 'brand' : ($a['role'] === 'admin' ? 'neutral' : 'ink')));
            if ($a['role'] === 'owner') {
                $perm = '<span class="text-muted-foreground">Все права</span>';
            } else {
                $n = count($a['perms']);
                $perm = '<span class="tabular-nums">' . h($n ? plural($n, 'право', 'права', 'прав') : 'Только работа на смене') . '</span>';
                if ($a['custom']) {
                    $perm .= chip('настроено вручную', 'outline', 'sliders');
                }
                if ($a['scope'] !== null) {
                    $perm .= chip('только ' . self::branchName($a['scope']), 'outline', 'building');
                }
            }
            $table .= td('Права', '<div class="flex flex-wrap items-center gap-1.5 text-sm">' . $perm . '</div>');
            $act = '<div class="flex items-center justify-end gap-1">';
            if (!$isMe) {
                $act .= '<button type="button" class="' . btn_class('outline', 'sm') . '" data-dialog-open="' . h($did) . '-access">' . icon('shield', 'size-4') . 'Права</button>';
            }
            $act .= '<button type="button" class="' . btn_class('ghost', 'icon-sm') . '" data-dialog-open="' . h($did) . '-pass" aria-label="Новый пароль: ' . h($a['display']) . '" title="Новый пароль">' . icon('key') . '</button>';
            if (!$isMe) {
                $act .= form_open('delete_account', confirm_attr('Удалить доступ «' . $a['display'] . '»? Войти под логином ' . $a['login'] . ' будет нельзя. Карточка сотрудника уйдёт в архив, история смен сохранится.')) . hidden('userId', $uid)
                    . '<button class="' . btn_class('danger-ghost', 'icon-sm') . '" aria-label="Удалить доступ: ' . h($a['display']) . '" title="Удалить доступ">' . icon('trash') . '</button></form>';
            }
            $table .= td('Действия', $act . '</div>', 'text-right') . '</tr>';

            if (!$isMe) {
                $dialogs .= dialog_html($did . '-access', 'Права: ' . $a['display'], self::accessForm($a), 'max-w-2xl');
            }
            $pass = form_open('reset_password', 'class="flex flex-col gap-4" data-submit-once') . hidden('userId', $uid);
            $pass .= '<p class="text-sm leading-relaxed text-muted-foreground">Логин <span class="font-mono text-foreground">' . h($a['login']) . '</span>. Задайте новый пароль, скопируйте его и передайте сотруднику — после сохранения он нигде не показывается.</p>';
            $pass .= ui_field('Новый пароль', self::generatedPasswordInput(), 'Не короче 8 символов');
            $pass .= '<div class="flex flex-wrap justify-end gap-2 pt-1"><button type="button" class="' . btn_class('ghost') . '" data-dialog-close>Отмена</button><button class="' . btn_class() . '">' . icon('save', 'size-4') . 'Сохранить пароль</button></div></form>';
            $dialogs .= dialog_html($did . '-pass', 'Новый пароль: ' . $a['display'], $pass);
        }
        $html .= $table . table_close();
        $html .= '<p class="hidden text-sm text-muted-foreground" data-filter-empty="acc-table">Никого не нашлось.</p>';

        $matrix = '<div class="overflow-x-auto"><table class="w-full min-w-[40rem] text-sm"><thead class="bg-muted/50"><tr>' . th_cell('Право');
        foreach (ROLES as $r) {
            $matrix .= th_cell($r['label'], 'text-center');
        }
        $matrix .= '</tr></thead><tbody>';
        foreach (PERMISSIONS as $key => $p) {
            $matrix .= '<tr class="border-t border-border"><th scope="row" class="px-4 py-2.5 text-left font-normal">' . h($p['label']) . '</th>';
            foreach (array_keys(ROLES) as $role) {
                $has = in_array($key, role_preset($role), true);
                $matrix .= '<td class="px-4 py-2.5 text-center">' . ($has ? icon('check', 'mx-auto size-4 text-foreground') . '<span class="sr-only">есть</span>' : '<span class="text-muted-foreground" aria-hidden="true">—</span><span class="sr-only">нет</span>') . '</td>';
            }
            $matrix .= '</tr>';
        }
        $matrix .= '<tr class="border-t border-border"><th scope="row" class="px-4 py-2.5 text-left font-normal">Доступы, пароли и база данных</th>';
        foreach (array_keys(ROLES) as $role) {
            $matrix .= '<td class="px-4 py-2.5 text-center">' . ($role === 'owner' ? icon('check', 'mx-auto size-4 text-foreground') . '<span class="sr-only">есть</span>' : '<span class="text-muted-foreground" aria-hidden="true">—</span><span class="sr-only">нет</span>') . '</td>';
        }
        $matrix .= '</tr></tbody></table></div><p class="border-t border-border px-4 py-3 text-xs leading-relaxed text-muted-foreground">Это шаблоны. Каждому человеку права можно донастроить кнопкой «Права» — добавить или убрать отдельные пункты.</p>';
        $html .= self::collapsible('Что умеет каждая роль', '', $matrix, 'shield');

        $old = form_stash_take('account-new');
        $empOpts = ['' => 'Новый сотрудник — заполнить ниже'] + $free;
        $html .= $dialogs . dialog_html('acc-new', 'Выдать доступ в портал', self::newAccountForm($empOpts, $old), 'max-w-xl', $old ? 'data-dialog-autoopen' : '');
        self::layout($user, $html, ['title' => 'Доступы и права', 'width' => 'wide']);
    }

    /* ---------- База данных (владелец) ---------- */

    private static function tableLabel(string $table): string
    {
        return [
            'app_meta' => 'Служебная информация', 'user' => 'Учётные записи', 'account' => 'Пароли (в виде хешей)', 'branches' => 'Филиалы',
            'employees' => 'Сотрудники', 'employee_grades' => 'Уровни и ставки', 'employee_grade_history' => 'История уровней', 'shifts' => 'Смены',
            'staff_tasks' => 'Задания на смену', 'staff_task_done' => 'Выполненные задания', 'daily_tasks' => 'Задания дня', 'daily_task_completions' => 'Отметки заданий дня',
            'cleaning_seat_assignments' => 'Закреплённые места', 'keyboard_cleanings' => 'Чистка клавиатур', 'incidents' => 'Поломки и происшествия',
            'announcements' => 'Объявления', 'announcement_reads' => 'Прочтения объявлений', 'review_bonuses' => 'Бонусы за отзывы', 'compensations' => 'Компенсации',
            'loyalty_tiers' => 'Уровни кэшбека', 'portal_content' => 'Тарифы и условия акций', 'knowledge_categories' => 'Разделы базы знаний',
            'knowledge_articles' => 'Статьи базы знаний', 'fine_rules' => 'Лист штрафов', 'employee_fines' => 'Назначенные штрафы', 'exams' => 'Экзамены',
            'exam_questions' => 'Вопросы экзаменов', 'exam_options' => 'Варианты ответов', 'exam_attempts' => 'Попытки сдачи', 'exam_answers' => 'Ответы сотрудников',
            'shift_checklist_items' => 'Чек-лист смены', 'shift_reports' => 'Сдача смен', 'shift_report_checks' => 'Отметки чек-листа',
            'audit_log' => 'Журнал действий', 'login_attempts' => 'Неудачные попытки входа',
        ][$table] ?? $table;
    }

    private static function pageDatabase(array $user): void
    {
        $driver = Db::driver();
        $counts = [];
        $total = 0;
        foreach (array_keys(SCHEMA) as $t) {
            try {
                $n = (int) Db::value('SELECT COUNT(*) FROM "' . $t . '"');
            } catch (Throwable) {
                $n = null;
            }
            $counts[$t] = $n;
            $total += (int) $n;
        }
        $isMysql = $driver === 'mysql';
        $html = page_head('База данных', 'Резервная копия портала и перенос на хостинг reg.ru одним файлом.');

        $html .= '<div class="grid grid-cols-2 gap-3 lg:grid-cols-4">';
        $html .= stat_tile('Где хранится', $isMysql ? 'MySQL' : 'SQLite', $isMysql ? 'база на хостинге' : 'файл storage/' . h(basename(Db::path())), '', 'database');
        $html .= stat_tile('Таблиц', (string) count(SCHEMA), 'версия схемы ' . SCHEMA_VERSION, '', 'table');
        $html .= stat_tile('Записей', h(num_fmt($total)), 'во всех таблицах', '', 'list');
        $html .= stat_tile('Сотрудников', (string) (int) ($counts['employees'] ?? 0), plural((int) ($counts['user'] ?? 0), 'доступ', 'доступа', 'доступов') . ' в портал', '', 'users');
        $html .= '</div>';

        $dl = card_head('Скачать localhost.sql', 'Полная копия: сотрудники, график, штрафы, экзамены, база знаний и пароли (в виде хешей — сами пароли не узнать). Подходит для MySQL 8 и MariaDB.', '', 'download');
        $dl .= form_open('download_dump', 'class="mt-5 flex flex-col gap-3 sm:flex-row sm:items-center"') . '<button class="' . btn_class('primary', 'lg') . '">' . icon('download', 'size-4') . 'Скачать localhost.sql</button>';
        $dl .= '<p class="text-xs leading-relaxed text-muted-foreground">Файл собирается из текущих данных в момент нажатия. Храните его в надёжном месте — в нём доступы сотрудников.</p></form>';

        $steps = [
            ['В панели reg.ru', 'Откройте «Хостинг» → «Базы данных MySQL». Создайте базу и пользователя или возьмите су��ествующую, например <span class="font-mono">u3622481_default</span>.'],
            ['Импорт в phpMyAdmin', 'Войдите в phpMyAdmin, слева выберите базу → вкладка «Импорт» → выберите <span class="font-mono">localhost.sql</span> → «Вперёд». Таблицы портала создадутся заново.'],
            ['Файлы сайта', 'Залейте папку портала в корень сайта (обычно <span class="font-mono">public_html</span>) вместе со скрытым файлом <span class="font-mono">.htaccess</span>. Версия PHP — 8.1 или новее.'],
            ['Подключение к базе', 'Скопируйте <span class="font-mono">config.example.php</span> в <span class="font-mono">config.php</span> и впишите имя базы, пользователя и пароль из панели reg.ru.'],
            ['Вход', 'Откройте сайт и войдите своим логином — пароли у всех сотрудников остаются прежними.'],
        ];
        $how = card_head('Как перенести на reg.ru', 'Пять шагов, около десяти минут', '', 'info');
        $how .= '<ol class="mt-5 flex flex-col gap-4">';
        foreach ($steps as $i => [$title, $text]) {
            $how .= '<li class="flex gap-3"><span class="cut flex size-7 shrink-0 items-center justify-center bg-foreground font-display text-xs font-semibold text-background tabular-nums" style="--cut:6px">' . ($i + 1) . '</span><div class="flex min-w-0 flex-col gap-0.5"><p class="text-sm font-semibold">' . h($title) . '</p><p class="text-sm leading-relaxed text-muted-foreground">' . $text . '</p></div></li>';
        }
        $how .= '</ol>';

        $list = '<ul class="grid divide-y divide-border sm:grid-cols-2 sm:divide-y-0">';
        foreach ($counts as $t => $n) {
            $list .= '<li class="flex items-center justify-between gap-3 border-border px-4 py-2.5 text-sm sm:border-b"><span class="flex min-w-0 flex-col"><span class="truncate">' . h(self::tableLabel($t)) . '</span><span class="font-mono text-[11px] text-muted-foreground">' . h($t) . '</span></span><span class="shrink-0 tabular-nums ' . ($n === null ? 'text-primary-ink' : 'text-muted-foreground') . '">' . ($n === null ? 'нет таблицы' : h(num_fmt($n))) . '</span></li>';
        }
        $list .= '</ul>';

        $html .= '<div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]"><div class="flex min-w-0 flex-col gap-6">' . card($dl, 'p-5') . self::collapsible('Что внутри', (string) count(SCHEMA), $list, 'table') . '</div>' . card($how, 'p-5') . '</div>';
        self::layout($user, $html, ['title' => 'База данных']);
    }
}
