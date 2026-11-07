<?php
declare(strict_types=1);

trait PagesClub
{
    /** Фильтр филиалов со вкладкой «Все». Возвращает [выбранный филиал|null, html]. */
    private static function branchFilter(string $path, array $query = [], string $label = 'Филиал'): array
    {
        $slug = self::getStr('branch', 64);
        $picked = null;
        foreach (self::branches() as $b) {
            if ($b['slug'] === $slug) {
                $picked = $b;
            }
        }
        $items = [['href' => url_to($path, $query), 'label' => 'Все филиалы', 'active' => $picked === null]];
        foreach (self::branches() as $b) {
            $items[] = ['href' => url_to($path, $query + ['branch' => $b['slug']]), 'label' => $b['name'], 'active' => $picked && $picked['id'] === $b['id']];
        }
        return [$picked, segmented($items, $label)];
    }

    private static function branchOptions(): array
    {
        $out = [];
        foreach (self::branches() as $b) {
            $out[$b['id']] = $b['name'];
        }
        return $out;
    }

    /* ---------------- Поломки ---------------- */

    private static function pageIssues(array $user): void
    {
        $status = self::getStr('status', 16);
        $status = in_array($status, ['open', 'done', 'all'], true) ? $status : 'open';
        [$branch, $branchNav] = self::branchFilter('/issues', $status !== 'open' ? ['status' => $status] : []);
        $where = [];
        $params = [];
        if ($branch) {
            $where[] = 'i."branchId" = ?';
            $params[] = $branch['id'];
        }
        $countsRows = Db::all('SELECT i.status, COUNT(*) AS n FROM incidents i' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' GROUP BY i.status', $params);
        $cnt = ['new' => 0, 'in_progress' => 0, 'done' => 0];
        foreach ($countsRows as $r) {
            $cnt[(string) $r['status']] = (int) $r['n'];
        }
        if ($status === 'open') {
            $where[] = 'i.status != \'done\'';
        } elseif ($status === 'done') {
            $where[] = 'i.status = \'done\'';
        }
        $rows = Db::all(
            'SELECT i.*, b.name AS "branchName" FROM incidents i JOIN branches b ON b.id = i."branchId"'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY CASE i.status WHEN \'new\' THEN 0 WHEN \'in_progress\' THEN 1 ELSE 2 END, i."createdAt" DESC LIMIT 200',
            $params,
        );

        $html = page_head('Поломки и происшествия', 'Сломался ПК, не работает техника или случилось что-то в зале — запишите здесь. Ответственный увидит заявку и отметит, когда исправит.', '<button type="button" class="' . btn_class() . '" data-dialog-open="issue-new">' . icon('plus', 'size-4') . 'Сообщить</button>');

        $q = $branch ? ['branch' => $branch['slug']] : [];
        $tabs = [
            'open' => ['label' => 'Открытые', 'href' => url_to('/issues', $q), 'count' => $cnt['new'] + $cnt['in_progress']],
            'done' => ['label' => 'Закрытые', 'href' => url_to('/issues', $q + ['status' => 'done']), 'count' => $cnt['done']],
            'all' => ['label' => 'Все', 'href' => url_to('/issues', $q + ['status' => 'all'])],
        ];
        $html .= '<div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">' . tabs_nav($tabs, $status, 'Статус заявок') . $branchNav . '</div>';

        if (!$rows) {
            $html .= empty_state($status === 'done' ? 'inbox' : 'check-circle', $status === 'done' ? 'Закрытых заявок нет' : 'Открытых заявок нет', $status === 'done' ? '' : 'Всё работает. Если что-то сломается — нажмите «Сообщить».');
        } else {
            $html .= '<div class="grid gap-4 md:grid-cols-2">';
            foreach ($rows as $r) {
                $html .= self::issueCard($user, $r);
            }
            $html .= '</div>';
        }

        $form = form_open('save_incident', 'class="flex flex-col gap-4" data-submit-once');
        $form .= '<fieldset class="flex flex-col gap-1.5"><legend class="mb-1.5 text-sm font-medium">Что случилось</legend><div class="grid grid-cols-2 gap-2">';
        foreach (INCIDENT_KIND as $k => $label) {
            $form .= '<label class="radio-card"><input type="radio" name="kind" value="' . h($k) . '"' . ($k === 'tech' ? ' checked' : '') . ' class="peer sr-only"><span>' . icon($k === 'tech' ? 'monitor' : 'alert', 'size-4') . h($label) . '<small>' . ($k === 'tech' ? 'ПК, периферия, техника' : 'Гости, конфликты, порча') . '</small></span></label>';
        }
        $form .= '</div></fieldset>';
        $form .= '<div class="grid grid-cols-[minmax(0,1fr)_7rem] gap-3">' . ui_field('Филиал', ui_select('branchId', self::branchOptions(), $user['homeBranchId'] ?? null, 'required'));
        $form .= ui_field('ПК №', '<input name="pcNumber" class="' . field_class() . '" inputmode="numeric" pattern="[0-9]*" maxlength="3" placeholder="—">') . '</div>';
        $form .= ui_field('Коротко', '<input name="title" class="' . field_class() . '" required minlength="3" maxlength="200" placeholder="Например: не включается монитор">');
        $form .= ui_field('Подробности', '<textarea name="details" class="' . textarea_class() . '" maxlength="2000" placeholder="Что пробовали сделать, когда началось"></textarea>');
        $form .= '<div class="flex flex-wrap justify-end gap-2"><button type="button" class="' . btn_class('ghost') . '" data-dialog-close>Отмена</button><button class="' . btn_class() . '">' . icon('send', 'size-4') . 'Отправить</button></div></form>';
        $html .= dialog_html('issue-new', 'Новая заявка', $form, 'max-w-lg');

        self::layout($user, $html, ['title' => 'Поломки']);
    }

    private static function issueCard(array $user, array $r): string
    {
        $status = (string) $r['status'];
        $branchId = (int) $r['branchId'];
        $canResolve = can($user, 'issues.resolve', $branchId) || $r['createdByUserId'] === $user['id'];
        $meta = h($r['branchName']) . ($r['pcNumber'] !== null && $r['pcNumber'] !== '' ? ' · ПК ' . (int) $r['pcNumber'] : '');
        $html = '<div class="flex flex-wrap items-center gap-2">' . incident_chip($status) . chip(INCIDENT_KIND[$r['kind']] ?? (string) $r['kind'], 'outline', $r['kind'] === 'tech' ? 'monitor' : 'alert') . '<span class="text-xs font-medium text-muted-foreground">' . $meta . '</span></div>';
        $html .= '<div class="flex flex-col gap-1.5"><h3 class="font-semibold leading-snug text-pretty break-words">' . h($r['title']) . '</h3>';
        if (trim((string) $r['details']) !== '') {
            $html .= '<p class="text-sm leading-relaxed whitespace-pre-line text-muted-foreground break-words">' . h($r['details']) . '</p>';
        }
        $html .= '</div>';
        if ($status === 'done') {
            $html .= '<div class="rounded-md bg-muted/60 px-3 py-2 text-sm"><p class="font-medium">' . icon('check', 'mr-1 inline size-3.5') . 'Закрыл(а) ' . h((string) $r['resolvedByName']) . ' · ' . h(format_dt((string) $r['resolvedAt'])) . '</p>';
            if (trim((string) $r['resolutionNote']) !== '') {
                $html .= '<p class="mt-1 leading-relaxed whitespace-pre-line text-muted-foreground">' . h($r['resolutionNote']) . '</p>';
            }
            $html .= '</div>';
        } elseif ($status === 'in_progress' && !empty($r['resolvedByName'])) {
            $html .= '<p class="text-xs text-muted-foreground">Взял(а) в работу: <span class="font-medium text-foreground">' . h((string) $r['resolvedByName']) . '</span></p>';
        }
        $html .= '<div class="mt-auto flex flex-wrap items-center justify-between gap-2 border-t border-border pt-3"><p class="text-xs text-muted-foreground">' . h(short_name((string) $r['createdByName'])) . ' · ' . h(format_dt((string) $r['createdAt'])) . '</p>';
        if ($canResolve) {
            $id = (int) $r['id'];
            $html .= '<div class="flex flex-wrap items-center gap-2">';
            if ($status === 'new' && can($user, 'issues.resolve', $branchId)) {
                $html .= form_open('incident_status') . hidden('id', $id) . hidden('status', 'in_progress') . '<button class="' . btn_class('outline', 'sm') . '">' . icon('wrench', 'size-3.5') . 'В работу</button></form>';
            }
            if ($status !== 'done') {
                $html .= '<details class="group relative"><summary class="' . btn_class('ink', 'sm') . ' list-none [&::-webkit-details-marker]:hidden">' . icon('check', 'size-3.5') . 'Закрыть</summary>';
                $html .= '<div class="absolute right-0 bottom-full z-20 mb-2 w-72 rounded-lg border border-border bg-popover p-3 text-popover-foreground shadow-lg">' . form_open('incident_status', 'class="flex flex-col gap-2"') . hidden('id', $id) . hidden('status', 'done');
                $html .= ui_field('Что сделано', '<textarea name="note" class="' . textarea_class('min-h-16') . '" maxlength="1000" placeholder="Необязательно"></textarea>');
                $html .= '<button class="' . btn_class('primary', 'sm') . '">Закрыть заявку</button></form></div></details>';
            } else {
                $html .= form_open('incident_status') . hidden('id', $id) . hidden('status', 'new') . '<button class="' . btn_class('quiet', 'sm') . '">' . icon('refresh', 'size-3.5') . 'Открыть снова</button></form>';
            }
            $html .= '</div>';
        }
        $html .= '</div>';
        return card($html, 'flex flex-col gap-3 p-4', 'article');
    }

    /* ---------------- Объявления ---------------- */

    private static function pageNews(array $user): void
    {
        $canPublish = can($user, 'news.publish');
        $rows = Db::all(
            'SELECT a.*, (SELECT r."readAt" FROM announcement_reads r WHERE r."announcementId" = a.id AND r."userId" = ? LIMIT 1) AS read_at
             FROM announcements a' . ($canPublish ? '' : ' WHERE a."isDraft" = 0') . ' ORDER BY a."isPinned" DESC, a."createdAt" DESC LIMIT 100',
            [$user['id']],
        );
        $readers = [];
        if ($canPublish && $rows) {
            $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
            foreach (Db::all('SELECT "announcementId", "userName", "readAt" FROM announcement_reads WHERE "announcementId" IN (' . implode(',', $ids) . ') ORDER BY "readAt"') as $rd) {
                $readers[(int) $rd['announcementId']][] = $rd;
            }
        }
        $staff = $canPublish ? (int) Db::value('SELECT COUNT(*) FROM employees WHERE "isActive" = 1 AND "userId" IS NOT NULL') : 0;
        $openId = (int) ($_GET['open'] ?? 0);
        $unread = count(array_filter($rows, static fn ($r) => empty($r['read_at']) && !boolish($r['isDraft'])));

        $lead = $unread > 0 ? 'У вас ' . plural($unread, 'непрочитанное объявление', 'непрочитанных объявления', 'непрочитанных объявлений') . '. Прочитали — нажмите «Прочитал(а)», чтобы руководитель это видел.' : 'Важное от руководства. Всё прочитано.';
        $actions = $canPublish ? '<button type="button" class="' . btn_class() . '" data-dialog-open="news-new">' . icon('plus', 'size-4') . 'Новое объявление</button>' : '';
        $html = page_head('Объявления', h($lead), $actions);

        if (!$rows) {
            $html .= empty_state('megaphone', 'Объявлений пока нет', $canPublish ? 'Напишите первое — его увидят все сотрудники.' : 'Когда руководство что-то опубликует, оно появится здесь.');
        } else {
            $html .= '<div class="flex flex-col gap-4" data-keep-scroll>';
            foreach ($rows as $r) {
                $html .= self::newsCard($user, $r, $canPublish, $readers[(int) $r['id']] ?? [], $staff, $openId === (int) $r['id']);
            }
            $html .= '</div>';
        }

        if ($canPublish) {
            $form = form_open('save_news', 'class="flex flex-col gap-4" data-submit-once');
            $form .= ui_field('Заголовок', '<input name="title" class="' . field_class() . '" required minlength="3" maxlength="200">');
            $form .= ui_field('Текст', '<textarea name="body" class="' . textarea_class('min-h-40') . '" maxlength="5000" placeholder="Что важно знать сотрудникам"></textarea>');
            $form .= '<label class="flex items-center gap-2.5 text-sm"><input type="checkbox" name="isPinned" value="1" class="' . check_class() . '">Закрепить наверху</label>';
            $form .= '<div class="flex flex-wrap justify-end gap-2"><button type="button" class="' . btn_class('ghost') . '" data-dialog-close>Отмена</button><button class="' . btn_class() . '">' . icon('send', 'size-4') . 'Опубликовать</button></div></form>';
            $html .= dialog_html('news-new', 'Новое объявление', $form, 'max-w-xl');
        }
        self::layout($user, $html, ['title' => 'Объявления', 'width' => 'narrow']);
    }

    private static function newsCard(array $user, array $r, bool $canPublish, array $readers, int $staff, bool $open): string
    {
        $id = (int) $r['id'];
        $pinned = boolish($r['isPinned']);
        $isUnread = empty($r['read_at']) && !boolish($r['isDraft']);
        $html = '<div class="flex flex-wrap items-center gap-2">';
        if ($isUnread) {
            $html .= chip('Новое', 'brand');
        }
        if ($pinned) {
            $html .= chip('Закреплено', 'ink', 'pushpin');
        }
        $html .= '<span class="text-xs text-muted-foreground">' . h(format_dt((string) $r['createdAt'])) . ' · ' . h((string) $r['createdByName']) . '</span></div>';
        $html .= '<h2 class="font-display text-lg leading-snug font-semibold text-pretty break-words">' . h($r['title']) . '</h2>';
        if (trim((string) $r['body']) !== '') {
            $html .= '<div class="text-[15px] leading-relaxed whitespace-pre-line break-words">' . h($r['body']) . '</div>';
        }
        $html .= '<div class="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-4">';
        if ($isUnread) {
            $html .= form_open('read_news') . hidden('id', $id) . '<button class="' . btn_class('primary') . '">' . icon('check', 'size-4') . 'Прочитал(а)</button></form>';
        } else {
            $html .= '<p class="flex items-center gap-1.5 text-sm text-muted-foreground">' . icon('check-circle', 'size-4') . 'Прочитано' . (!empty($r['read_at']) ? ' ' . h(format_dt((string) $r['read_at'])) : '') . '</p>';
        }
        if ($canPublish) {
            $html .= '<div class="flex flex-wrap items-center gap-1">';
            $html .= form_open('pin_news') . hidden('id', $id) . '<button class="' . btn_class('quiet', 'sm') . '">' . icon('pushpin', 'size-3.5') . ($pinned ? 'Открепить' : 'Закрепить') . '</button></form>';
            $html .= form_open('delete_news', confirm_attr('Удалить объявление «' . $r['title'] . '»? Отметки о прочтении тоже удалятся.')) . hidden('id', $id) . '<button class="' . btn_class('danger-ghost', 'sm') . '">' . icon('trash', 'size-3.5') . 'Удалить</button></form>';
            $html .= '</div>';
        }
        $html .= '</div>';
        if ($canPublish) {
            $n = count($readers);
            $html .= '<details class="group rounded-md bg-muted/50 px-3 py-2 text-sm"><summary class="flex cursor-pointer list-none items-center justify-between gap-2 font-medium [&::-webkit-details-marker]:hidden"><span>Прочитали: ' . $n . ($staff > 0 ? ' из ' . $staff : '') . '</span>' . icon('chevrondown', 'size-4 text-muted-foreground transition-transform group-open:rotate-180') . '</summary>';
            if ($readers) {
                $html .= '<ul class="mt-2 flex flex-wrap gap-1.5">';
                foreach ($readers as $rd) {
                    $html .= '<li class="rounded-[5px] bg-card px-2 py-0.5 text-xs" title="' . h(format_dt((string) $rd['readAt'])) . '">' . h(short_name((string) $rd['userName'])) . '</li>';
                }
                $html .= '</ul>';
            } else {
                $html .= '<p class="mt-2 text-muted-foreground">Пока никто не отметил прочтение.</p>';
            }
            $html .= '</details>';
        }
        $cls = 'flex scroll-mt-24 flex-col gap-3 p-5 sm:p-6' . ($isUnread ? ' border-l-0 ring-1 ring-primary/40' : '') . ($open ? ' outline-2 outline-offset-2 outline-primary' : '');
        return card($html, $cls, 'article', 'id="news-' . $id . '"' . ($open ? ' data-scroll-into' : ''));
    }
}
