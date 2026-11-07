<?php
declare(strict_types=1);

trait PagesRules
{
    /* ---------------- База знаний ---------------- */

    private static function wikiArticles(array $user): array
    {
        return Db::all(
            'SELECT a.id, a.title, a.slug, a."isDraft", a."categoryId", a."contentHtml", a."updatedAt", c.title AS "categoryTitle"
             FROM knowledge_articles a JOIN knowledge_categories c ON c.id = a."categoryId"
             WHERE a.kind != \'fines\'' . (can($user, 'wiki.edit') ? '' : ' AND a."isDraft" = 0') . '
             ORDER BY c."sortOrder", c.id, a."sortOrder", a.id',
        );
    }

    private static function firstArticleSlug(array $user): ?string
    {
        return self::wikiArticles($user)[0]['slug'] ?? null;
    }

    private static function wikiCategories(): array
    {
        $out = [];
        foreach (Db::all('SELECT id, title FROM knowledge_categories ORDER BY "sortOrder", id') as $c) {
            $out[(int) $c['id']] = (string) $c['title'];
        }
        return $out;
    }

    private static function wikiNav(array $articles, string $active, bool $canEdit): string
    {
        $html = '<nav class="flex flex-col gap-4" aria-label="Статьи базы знаний" data-wiki-nav>';
        $html .= search_input('data-wiki-search', 'Поиск по базе знаний');
        $groups = [];
        foreach ($articles as $a) {
            $groups[(string) $a['categoryTitle']][] = $a;
        }
        foreach ($groups as $title => $items) {
            $html .= '<div class="flex flex-col gap-1" data-wiki-group><p class="px-2.5 text-[11px] font-semibold tracking-[0.14em] text-muted-foreground uppercase">' . h($title) . '</p><ul class="flex flex-col gap-px">';
            foreach ($items as $a) {
                $on = $a['slug'] === $active;
                $plain = mb_strtolower(trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $a['contentHtml']), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? ''));
                $html .= '<li data-wiki-item data-text="' . h(mb_strtolower((string) $a['title']) . ' ' . mb_substr($plain, 0, 4000)) . '"><a href="' . h(url_to('/wiki/' . rawurlencode((string) $a['slug']))) . '" class="relative flex items-center gap-2 rounded-md px-2.5 py-1.5 text-sm leading-snug transition-colors ' . ($on ? 'bg-muted font-semibold text-foreground' : 'text-muted-foreground hover:bg-muted/60 hover:text-foreground') . '"' . ($on ? ' aria-current="page"' : '') . '>';
                if ($on) {
                    $html .= '<span class="absolute top-1.5 bottom-1.5 left-0 w-0.5 rounded-full bg-primary" aria-hidden="true"></span>';
                }
                $html .= '<span class="min-w-0 flex-1">' . h($a['title']) . '</span>' . (boolish($a['isDraft']) ? chip('черновик', 'outline') : '') . '</a></li>';
            }
            $html .= '</ul></div>';
        }
        $html .= '<p class="hidden px-2.5 text-sm text-muted-foreground" data-wiki-empty>Ничего не нашлось</p>';
        if ($canEdit) {
            $html .= '<a href="' . h(url_to('/wiki/new')) . '" class="' . btn_class('outline', 'sm') . ' w-full">' . icon('plus', 'size-4') . 'Новая статья</a>';
        }
        return $html . '</nav>';
    }

    private static function wikiShell(array $user, array $articles, string $active, string $inner, string $title): void
    {
        $html = '<div class="grid items-start gap-8 lg:grid-cols-[17rem_minmax(0,1fr)] xl:gap-12">';
        $html .= '<aside class="order-2 lg:order-1 lg:sticky lg:top-8 lg:max-h-[calc(100dvh-4rem)] lg:overflow-y-auto lg:pb-6 scrollbar-thin">' . self::wikiNav($articles, $active, can($user, 'wiki.edit')) . '</aside>';
        $html .= '<div class="order-1 min-w-0 lg:order-2">' . $inner . '</div></div>';
        self::layout($user, $html, ['title' => $title, 'width' => 'wide']);
    }

    private static function pageWikiEmpty(array $user): void
    {
        $action = can($user, 'wiki.edit') ? '<a class="' . btn_class() . '" href="' . h(url_to('/wiki/new')) . '">' . icon('plus', 'size-4') . 'Создать статью</a>' : '';
        self::layout($user, page_head('База знаний') . empty_state('book', 'В базе знаний пока пусто', 'Здесь будут регламенты и инструкции для смены.', $action), ['title' => 'База знаний', 'width' => 'narrow']);
    }

    private static function pageWiki(array $user, string $slug): void
    {
        $articles = self::wikiArticles($user);
        $idx = null;
        foreach ($articles as $i => $a) {
            if ($a['slug'] === $slug) {
                $idx = $i;
            }
        }
        if ($idx === null) {
            self::notFound($user, 'Статья не найдена — возможно, её переименовали или удалили.');
            return;
        }
        $a = $articles[$idx];
        $actions = can($user, 'wiki.edit') ? '<a class="' . btn_class('outline', 'sm') . '" href="' . h(url_to('/wiki/' . rawurlencode($slug) . '/edit')) . '">' . icon('pencil', 'size-4') . 'Редактировать</a>' : '';
        $inner = '<article class="flex flex-col gap-8">';
        $inner .= '<header class="flex flex-col gap-3 border-b border-border pb-6">' . eyebrow((string) $a['categoryTitle']);
        $inner .= '<div class="flex flex-wrap items-start justify-between gap-3"><h1 class="font-display text-2xl leading-tight font-semibold tracking-tight text-balance sm:text-[2rem]">' . h($a['title']) . '</h1>' . $actions . '</div>';
        $inner .= '<p class="text-xs text-muted-foreground">' . (boolish($a['isDraft']) ? chip('Черновик — видят только редакторы', 'outline') . ' ' : '') . 'Обновлено ' . h(format_dt((string) $a['updatedAt'], false)) . '</p></header>';
        $content = trim((string) $a['contentHtml']);
        $inner .= $content !== '' ? '<div class="wiki-content">' . sanitize_html($content) . '</div>' : '<p class="text-sm text-muted-foreground">Статья пока пустая.</p>';
        $prev = $articles[$idx - 1] ?? null;
        $next = $articles[$idx + 1] ?? null;
        if ($prev || $next) {
            $inner .= '<nav class="grid gap-3 border-t border-border pt-6 sm:grid-cols-2" aria-label="Соседние статьи">';
            $inner .= $prev ? '<a class="group flex flex-col gap-1 rounded-lg border border-border p-4 transition-colors hover:bg-muted/50" href="' . h(url_to('/wiki/' . rawurlencode((string) $prev['slug']))) . '"><span class="flex items-center gap-1 text-xs text-muted-foreground">' . icon('arrow-left', 'size-3.5') . 'Назад</span><span class="text-sm font-semibold">' . h($prev['title']) . '</span></a>' : '<span></span>';
            $inner .= $next ? '<a class="group flex flex-col items-end gap-1 rounded-lg border border-border p-4 text-right transition-colors hover:bg-muted/50" href="' . h(url_to('/wiki/' . rawurlencode((string) $next['slug']))) . '"><span class="flex items-center gap-1 text-xs text-muted-foreground">Далее' . icon('arrow', 'size-3.5') . '</span><span class="text-sm font-semibold">' . h($next['title']) . '</span></a>' : '';
            $inner .= '</nav>';
        }
        $inner .= '</article>';
        self::wikiShell($user, $articles, $slug, $inner, (string) $a['title']);
    }

    private static function wikiEditor(string $html): string
    {
        $tools = [
            ['bold', 'bold', 'Жирный'], ['italic', 'italic', 'Курсив'], ['h2', 'heading', 'Заголовок'],
            ['ul', 'list', 'Список'], ['ol', 'list-ordered', 'Нумерованный список'], ['quote', 'quote', 'Цитата-заметка'],
            ['link', 'link', 'Ссылка'], ['table', 'table', 'Таблица 3×3'], ['clear', 'x', 'Убрать оформление'],
        ];
        $out = '<div class="flex flex-col overflow-hidden rounded-lg border border-input bg-card shadow-xs focus-within:border-ring focus-within:ring-3 focus-within:ring-ring/20" data-editor>';
        $out .= '<div class="flex flex-wrap items-center gap-0.5 border-b border-border bg-muted/40 p-1.5" role="toolbar" aria-label="Оформление текста">';
        foreach ($tools as [$cmd, $ic, $label]) {
            $out .= '<button type="button" class="' . btn_class('quiet', 'icon-sm') . '" data-cmd="' . $cmd . '" title="' . h($label) . '" aria-label="' . h($label) . '">' . icon($ic, 'size-4') . '</button>';
        }
        $out .= '<span class="mx-1 h-5 w-px bg-border" aria-hidden="true"></span><button type="button" class="' . btn_class('quiet', 'sm') . ' ml-auto" data-editor-source aria-pressed="false">' . icon('code', 'size-4') . 'HTML</button></div>';
        $out .= '<div class="wiki-content min-h-[26rem] max-w-none px-4 py-4 outline-none sm:px-6" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Текст статьи" data-editor-area>' . sanitize_html($html) . '</div>';
        $out .= '<textarea name="contentHtml" class="hidden min-h-[26rem] w-full resize-y bg-card px-4 py-4 font-mono text-sm leading-relaxed outline-none" aria-label="HTML статьи" data-editor-input>' . h(sanitize_html($html)) . '</textarea>';
        return $out . '</div>';
    }

    private static function wikiForm(array $user, ?array $a): string
    {
        $cats = self::wikiCategories();
        $f = form_open($a ? 'save_wiki' : 'create_wiki', 'class="flex flex-col gap-5" data-editor-form') . ($a ? hidden('id', (int) $a['id']) : '');
        $f .= '<div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_16rem]">' . ui_field('Заголовок', '<input name="title" class="' . field_class('h-11 text-base font-semibold') . '" value="' . h((string) ($a['title'] ?? '')) . '" required maxlength="120">');
        $catOptions = $cats + ['new' => '+ Новый раздел…'];
        $f .= ui_field('Раздел', ui_select('categoryId', $catOptions, $a['categoryId'] ?? array_key_first($cats), 'data-category-select', 'h-11')) . '</div>';
        $f .= '<div class="hidden" data-new-category>' . ui_field('Название нового раздела', '<input name="newCategory" class="' . field_class() . '" maxlength="80" placeholder="Например: Касса">') . '</div>';
        $f .= self::wikiEditor((string) ($a['contentHtml'] ?? ''));
        $f .= '<div class="flex flex-wrap items-center justify-between gap-3"><label class="flex items-center gap-2.5 text-sm"><input type="checkbox" name="isDraft" value="1" class="' . check_class() . '"' . (!empty($a['isDraft']) && boolish($a['isDraft']) ? ' checked' : '') . '>Черновик — видят только редакторы</label>';
        $f .= '<div class="flex gap-2"><a class="' . btn_class('ghost') . '" href="' . h(url_to($a ? '/wiki/' . rawurlencode((string) $a['slug']) : '/wiki')) . '">Отмена</a><button class="' . btn_class() . '">' . icon('save', 'size-4') . ($a ? 'Сохранить' : 'Создать статью') . '</button></div></div></form>';
        if ($a) {
            $f .= '<div class="flex justify-end border-t border-border pt-5">' . form_open('delete_wiki', confirm_attr('Удалить статью «' . $a['title'] . '» без возможности восстановления?')) . hidden('id', (int) $a['id']) . '<button class="' . btn_class('danger', 'sm') . '">' . icon('trash', 'size-4') . 'Удалить статью</button></form></div>';
        }
        return $f;
    }

    private static function pageWikiEdit(array $user, string $slug): void
    {
        $a = Db::one('SELECT * FROM knowledge_articles WHERE slug = ?', [$slug]);
        if (!$a || $a['kind'] === 'fines') {
            self::notFound($user, 'Статья не найдена.');
            return;
        }
        $html = back_link(url_to('/wiki/' . rawurlencode($slug)), 'К статье') . page_head('Редактирование статьи', 'Оформляйте кнопками на панели или вставьте готовый текст. Опасные вставки (скрипты, формы) удаляются при сохранении.', '', 'База знаний');
        $html .= self::wikiForm($user, $a);
        self::layout($user, $html, ['title' => 'Редактирование: ' . $a['title']]);
    }

    private static function pageWikiNew(array $user): void
    {
        $html = back_link(url_to('/wiki'), 'База знаний') . page_head('Новая статья', 'Статья появится в меню базы знаний в выбранном разделе.', '', 'База знаний');
        $html .= self::wikiForm($user, null);
        self::layout($user, $html, ['title' => 'Новая статья']);
    }

    /* ---------------- Тарифы ---------------- */

    private static function pageTariffs(array $user): void
    {
        $c = self::portal_json('tariffs', DEFAULT_TARIFFS);
        $actions = can($user, 'content.edit') ? '<a class="' . btn_class('outline') . '" href="' . h(url_to('/tariffs/edit')) . '">' . icon('pencil', 'size-4') . 'Изменить цены</a>' : '';
        $html = page_head('Тарифы', h((string) $c['intro']), $actions);
        $html .= '<div class="grid items-start gap-6 xl:grid-cols-2">';
        foreach ($c['branches'] as $b) {
            $zones = array_values((array) ($b['zones'] ?? []));
            $head = '<header class="flex flex-col gap-1 border-b border-border px-5 py-4 sm:flex-row sm:items-baseline sm:justify-between"><h2 class="font-display text-xl font-semibold tracking-tight">' . h((string) $b['branchName']) . '</h2>';
            $head .= '<p class="flex items-center gap-1.5 text-sm text-muted-foreground">' . icon('pin', 'size-3.5') . h((string) ($b['address'] ?? '')) . '</p></header>';
            $table = '<table class="table-cards w-full text-sm"><thead class="bg-muted/50"><tr>' . th_cell('Пакет');
            foreach ($zones as $z) {
                $table .= th_cell((string) $z, 'text-right');
            }
            $table .= '</tr></thead><tbody>';
            foreach ((array) ($b['packages'] ?? []) as $row) {
                $table .= '<tr class="border-t border-border"><th scope="row" class="px-4 py-3 text-left font-semibold" data-label="Пакет">' . h((string) $row['label']) . (!empty($row['hint']) ? '<span class="mt-0.5 block text-xs font-normal text-muted-foreground">' . h((string) $row['hint']) . '</span>' : '') . '</th>';
                foreach ($zones as $i => $z) {
                    $price = $row['prices'][$i] ?? null;
                    $table .= td((string) $z, $price === null || $price === '' ? '—' : '<span class="font-display text-base font-semibold tabular-nums">' . h(money_rub((int) $price)) . '</span>', 'text-right');
                }
                $table .= '</tr>';
            }
            $table .= '</tbody></table>';
            $html .= card($head . '<div class="overflow-x-auto">' . $table . '</div>', 'overflow-hidden');
        }
        $html .= '</div>';
        $footer = trim((string) ($c['footer'] ?? ''));
        $html .= '<div class="flex flex-col gap-3 rounded-lg border border-dashed border-border px-4 py-3 text-sm sm:flex-row sm:items-center sm:justify-between">' . ($footer !== '' ? '<p class="leading-relaxed text-muted-foreground">' . h($footer) . '</p>' : '<span></span>');
        $html .= '<div class="flex shrink-0 flex-wrap gap-2"><a class="' . btn_class('ghost', 'sm') . '" href="' . h(url_to('/loyalty')) . '">' . icon('percent', 'size-4') . 'Калькулятор времени</a><a class="' . btn_class('ghost', 'sm') . '" href="' . h(url_to('/wiki')) . '">' . icon('book', 'size-4') . 'База знаний</a></div></div>';
        self::layout($user, $html, ['title' => 'Тарифы', 'width' => 'wide']);
    }

    private static function pageTariffsEdit(array $user): void
    {
        $c = self::portal_json('tariffs', DEFAULT_TARIFFS);
        $html = back_link(url_to('/tariffs'), 'Тарифы') . page_head('Редактор тарифов', 'Пустая строка пакета удаляется при сохранении. Цены — целые рубли.');
        $f = form_open('save_tariffs', 'class="flex flex-col gap-6"');
        $f .= card(ui_field('Текст над прайсом', '<textarea name="intro" class="' . textarea_class('min-h-20') . '" maxlength="1000">' . h((string) $c['intro']) . '</textarea>') . ui_field('Подпись под прайсом', '<textarea name="footer" class="' . textarea_class('min-h-16') . '" maxlength="1000">' . h((string) ($c['footer'] ?? '')) . '</textarea>'), 'flex flex-col gap-4 p-5');
        foreach ($c['branches'] as $bi => $b) {
            $zones = array_values((array) ($b['zones'] ?? []));
            $zones = array_pad($zones, 3, '');
            $p = 'branches[' . $bi . ']';
            $body = '<div class="grid gap-4 sm:grid-cols-2">' . ui_field('Филиал', '<input name="' . $p . '[branchName]" class="' . field_class('font-semibold') . '" value="' . h((string) $b['branchName']) . '" required maxlength="60">') . ui_field('Адрес', '<input name="' . $p . '[address]" class="' . field_class() . '" value="' . h((string) ($b['address'] ?? '')) . '" maxlength="120">') . '</div>';
            $body .= '<div class="overflow-x-auto"><table class="w-full min-w-[36rem] text-sm" data-price-table><thead><tr><th scope="col" class="pb-2 text-left text-xs font-semibold text-muted-foreground uppercase">Пакет</th><th scope="col" class="pb-2 text-left text-xs font-semibold text-muted-foreground uppercase">Пояснение</th>';
            foreach ($zones as $zi => $z) {
                $body .= '<th scope="col" class="pb-2 pl-2 text-left"><input name="' . $p . '[zones][' . $zi . ']" class="' . field_class('h-8 text-xs font-semibold uppercase') . '" value="' . h((string) $z) . '" placeholder="Зона ' . ($zi + 1) . '" aria-label="Название зоны ' . ($zi + 1) . '"></th>';
            }
            $body .= '<th class="w-10"></th></tr></thead><tbody data-price-rows>';
            $packages = array_values((array) ($b['packages'] ?? []));
            $packages[] = ['label' => '', 'hint' => '', 'prices' => []];
            foreach ($packages as $ri => $row) {
                $rp = $p . '[packages][' . $ri . ']';
                $body .= '<tr data-price-row><td class="py-1 pr-2"><input name="' . $rp . '[label]" class="' . field_class('h-9') . '" value="' . h((string) $row['label']) . '" placeholder="Новый пакет" aria-label="Пакет"></td>';
                $body .= '<td class="py-1 pr-2"><input name="' . $rp . '[hint]" class="' . field_class('h-9') . '" value="' . h((string) ($row['hint'] ?? '')) . '" placeholder="—" aria-label="Пояснение"></td>';
                foreach ($zones as $zi => $z) {
                    $body .= '<td class="py-1 pl-2"><input name="' . $rp . '[prices][' . $zi . ']" type="number" min="0" class="' . field_class('h-9 tabular-nums') . '" value="' . h((string) ($row['prices'][$zi] ?? '')) . '" aria-label="Цена"></td>';
                }
                $body .= '<td class="py-1 pl-1"><button type="button" class="' . btn_class('danger-ghost', 'icon-sm') . '" data-price-remove aria-label="Удалить строку">' . icon('trash', 'size-4') . '</button></td></tr>';
            }
            $body .= '</tbody></table></div><button type="button" class="' . btn_class('outline', 'sm') . ' self-start" data-price-add>' . icon('plus', 'size-4') . 'Строка пакета</button>';
            $f .= card($body, 'flex flex-col gap-4 p-5', 'fieldset');
        }
        $f .= '<div class="sticky bottom-20 z-10 flex justify-end gap-2 rounded-lg border border-border bg-card/95 p-3 shadow-lg backdrop-blur lg:bottom-4"><a class="' . btn_class('ghost') . '" href="' . h(url_to('/tariffs')) . '">Отмена</a><button class="' . btn_class() . '">' . icon('save', 'size-4') . 'Сохранить тарифы</button></div></form>';
        self::layout($user, $html . $f, ['title' => 'Редактор тарифов', 'width' => 'wide']);
    }

    /* ---------------- Штрафы ---------------- */

    private static function fineRules(bool $activeOnly = true): array
    {
        return Db::all('SELECT * FROM fine_rules' . ($activeOnly ? ' WHERE "isActive" = 1' : '') . ' ORDER BY "sortOrder", id');
    }

    private static function pageFines(array $user): void
    {
        $manage = can($user, 'fines.manage');
        $rules = self::fineRules();
        $eid = (int) ($user['employeeId'] ?? 0);
        // Штрафы действуют только в месяце назначения: с 1-го числа список обнуляется. Записи не удаляются — управляющий видит архив.
        $current = now_tyumen()->modify('first day of this month')->setTime(0, 0);
        $view = $current;
        $mParam = (string) ($_GET['m'] ?? '');
        if ($manage && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mParam)) {
            $picked = DateTimeImmutable::createFromFormat('!Y-m', $mParam, $current->getTimezone());
            if ($picked && $picked <= $current) {
                $view = $picked;
            }
        }
        $from = $view->format('Y-m-d');
        $until = $view->modify('+1 month')->format('Y-m-d');
        $isCurrent = $view == $current;
        $assigned = $manage
            ? Db::all('SELECT * FROM employee_fines WHERE "createdAt" >= ? AND "createdAt" < ? ORDER BY "createdAt" DESC', [$from, $until])
            : ($eid ? Db::all('SELECT * FROM employee_fines WHERE "employeeId" = ? AND "createdAt" >= ? AND "createdAt" < ? ORDER BY "createdAt" DESC', [$eid, $from, $until]) : []);
        $actions = $manage ? '<a class="' . btn_class() . '" href="' . h(url_to('/fines/assign')) . '">' . icon('plus', 'size-4') . 'Назначить штраф</a><a class="' . btn_class('outline') . '" href="' . h(url_to('/fines/edit')) . '">' . icon('pencil', 'size-4') . 'Лист штрафов</a>' : '';
        $html = page_head('Штрафы', 'Лист штрафов клуба: за что и сколько. Повторное нарушение — повышенная сумма. Назначенные штрафы обнуляются 1-го числа каждого месяца.', $actions);

        $sumMonth = 0;
        foreach ($assigned as $r) {
            $sumMonth += (int) $r['amount'];
        }
        $monLabel = mb_strtolower(format_month_title((int) $view->format('Y'), (int) $view->format('n') - 1));
        $title = $manage ? 'Штрафы · ' . $monLabel : 'Ваши штрафы за ' . $monLabel;
        $meta = $assigned ? 'Итого ' . money_rub($sumMonth) . ' · ' . plural(count($assigned), 'штраф', 'штрафа', 'штрафов') : ($manage ? 'В этом месяце штрафов нет' : 'В этом месяце штрафов нет — так держать');
        $nav = '';
        if ($manage) {
            $nav = '<div class="flex items-center gap-1"><a class="' . btn_class('outline', 'icon-sm') . '" href="' . h(url_to('/fines', ['m' => $view->modify('-1 month')->format('Y-m')])) . '" aria-label="Предыдущий месяц">' . icon('chevronleft', 'size-4') . '</a>';
            $nav .= $isCurrent ? '' : '<a class="' . btn_class('outline', 'icon-sm') . '" href="' . h(url_to('/fines', $view->modify('+1 month') == $current ? [] : ['m' => $view->modify('+1 month')->format('Y-m')])) . '" aria-label="Следующий месяц">' . icon('chevronright', 'size-4') . '</a>';
            $nav .= '</div>';
        }
        $list = card_head($title, h($meta), $nav, 'wallet');
        if ($assigned) {
            $list .= '<ul class="mt-4 flex flex-col divide-y divide-border" data-show-more="8">';
            foreach ($assigned as $r) {
                $list .= '<li class="flex items-start gap-3 py-3 first:pt-0 last:pb-0" data-more-item><div class="flex min-w-0 flex-1 flex-col gap-0.5">';
                if ($manage) {
                    $list .= '<p class="text-sm font-semibold">' . h((string) $r['employeeName']) . '</p>';
                }
                $list .= '<p class="text-sm leading-relaxed text-pretty break-words' . ($manage ? ' text-muted-foreground' : '') . '">' . h((string) $r['reason']) . '</p><p class="text-xs text-muted-foreground">' . h(format_dt((string) $r['createdAt'])) . ' · ' . h(short_name((string) $r['createdByName'])) . '</p></div>';
                $list .= '<span class="shrink-0 font-display text-base font-semibold tabular-nums">' . h(money_rub((int) $r['amount'])) . '</span>';
                if ($manage) {
                    $list .= form_open('cancel_fine', 'class="shrink-0" ' . confirm_attr('Отменить штраф ' . money_rub((int) $r['amount']) . ' — ' . $r['employeeName'] . '?')) . hidden('id', (int) $r['id']) . '<button class="' . btn_class('danger-ghost', 'icon-sm') . '" aria-label="Отменить штраф">' . icon('x', 'size-4') . '</button></form>';
                }
                $list .= '</li>';
            }
            $list .= '</ul>';
            if (count($assigned) > 8) {
                $list .= '<button type="button" class="' . btn_class('outline', 'sm') . ' mt-4" data-show-more-btn>Показать ещё</button>';
            }
        }
        $html .= '<div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]">';

        $table = '<table class="table-cards w-full text-sm"><thead class="bg-muted/50"><tr>' . th_cell('Нарушение') . th_cell('Впервые', 'text-right whitespace-nowrap') . th_cell('Повторно', 'text-right whitespace-nowrap') . '</tr></thead><tbody>';
        foreach ($rules as $i => $r) {
            $first = format_fine_amount($r['primaryAmount'] === null ? null : (int) $r['primaryAmount'], boolish($r['primaryDismissal']));
            $again = format_fine_amount($r['repeatAmount'] === null ? null : (int) $r['repeatAmount'], boolish($r['repeatDismissal']));
            $table .= '<tr class="border-t border-border"><th scope="row" class="px-4 py-3 text-left font-normal leading-relaxed text-pretty" data-label="Нарушение"><span class="mr-2 text-xs font-semibold text-muted-foreground tabular-nums">' . ($i + 1) . '</span>' . h((string) $r['violation']) . '</th>';
            $table .= td('Впервые', '<span class="whitespace-nowrap font-semibold tabular-nums' . (boolish($r['primaryDismissal']) ? ' text-primary-ink' : '') . '">' . h($first) . '</span>', 'text-right');
            $table .= td('Повторно', '<span class="whitespace-nowrap tabular-nums' . (boolish($r['repeatDismissal']) ? ' font-semibold text-primary-ink' : ' text-muted-foreground') . '">' . h($again) . '</span>', 'text-right') . '</tr>';
        }
        $table .= '</tbody></table>';
        $html .= '<section class="flex min-w-0 flex-col gap-3">' . section_title('Лист штрафов', '', (string) count($rules)) . ($rules ? '<div class="overflow-x-auto rounded-lg border border-border bg-card shadow-xs">' . $table . '</div>' : empty_state('scale', 'Лист штрафов пуст')) . '</section>';
        $html .= '<div class="flex min-w-0 flex-col gap-6 lg:sticky lg:top-8">' . card($list, 'p-5') . '</div></div>';
        self::layout($user, $html, ['title' => 'Штрафы', 'width' => 'wide']);
    }

    private static function pageFinesAssign(array $user): void
    {
        $stash = form_stash_take('fine-assign') ?? [];
        $rules = self::fineRules();
        $ruleOpts = ['' => 'Без пункта — своя причина'];
        $ruleData = [];
        foreach ($rules as $i => $r) {
            $ruleOpts[(string) $r['id']] = ($i + 1) . '. ' . mb_substr((string) $r['violation'], 0, 90);
            $ruleData[(string) $r['id']] = [
                'violation' => (string) $r['violation'],
                'first' => boolish($r['primaryDismissal']) ? null : ($r['primaryAmount'] === null ? null : (int) $r['primaryAmount']),
                'repeat' => boolish($r['repeatDismissal']) ? null : ($r['repeatAmount'] === null ? null : (int) $r['repeatAmount']),
            ];
        }
        $html = back_link(url_to('/fines'), 'Штрафы') . page_head('Назначить штраф', 'Сотрудник увидит причину и сумму у себя в разделе «Штрафы».');
        $f = form_open('assign_fine', 'class="flex flex-col gap-5" data-fine-form data-submit-once');
        $f .= ui_field('Сотрудник', ui_select('employeeId', ['' => 'Выберите сотрудника'] + self::employeeOptions(), $stash['employeeId'] ?? '', 'required'));
        $f .= ui_field('Пункт листа', ui_select('ruleId', $ruleOpts, $stash['ruleId'] ?? '', 'data-fine-rule'), 'Подставит причину и сумму — их можно поправить.');
        $f .= '<script type="application/json" data-fine-rules>' . json_encode((object) $ruleData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
        $f .= '<div class="hidden gap-2" data-fine-repeat-box><label class="pill-check"><input type="radio" name="repeat" value="0" class="peer sr-only" checked data-fine-repeat><span>Впервые</span></label><label class="pill-check"><input type="radio" name="repeat" value="1" class="peer sr-only" data-fine-repeat><span>Повторно</span></label></div>';
        $f .= ui_field('Причина', '<textarea name="reason" class="' . textarea_class() . '" required minlength="3" maxlength="500" placeholder="За что штраф" data-fine-reason>' . h((string) ($stash['reason'] ?? '')) . '</textarea>');
        $f .= ui_field('Сумма, ₽', '<input name="amount" type="number" min="1" max="50000" class="' . field_class('max-w-48 tabular-nums') . '" required placeholder="200" value="' . h((string) ($stash['amount'] ?? '')) . '" data-fine-amount>');
        $f .= '<div class="flex justify-end gap-2"><a class="' . btn_class('ghost') . '" href="' . h(url_to('/fines')) . '">Отмена</a><button class="' . btn_class() . '">' . icon('check', 'size-4') . 'Назначить</button></div></form>';
        $html .= card($f, 'p-5 sm:p-6');
        self::layout($user, $html, ['title' => 'Назначить штраф', 'width' => 'narrow']);
    }

    private static function pageFinesEdit(array $user): void
    {
        $rules = self::fineRules(false);
        $html = back_link(url_to('/fines'), 'Штрафы') . page_head('Лист штрафов', 'Пустая сумма — «—». Отметка «увольнение» заменяет сумму. Отключённый пункт скрыт из листа, но остаётся в истории.');
        $row = static function (?array $r, int $n): string {
            $id = $r ? (int) $r['id'] : 0;
            $f = form_open('save_fine_rule', 'class="flex flex-col gap-3"') . hidden('id', $id);
            $f .= '<div class="flex items-start gap-3"><span class="mt-2.5 w-6 shrink-0 text-right text-xs font-semibold text-muted-foreground tabular-nums">' . ($r ? $n : '+') . '</span>';
            $f .= '<textarea name="violation" class="' . textarea_class('min-h-12') . '" rows="2" required maxlength="300" aria-label="Нарушение" placeholder="Новое нарушение">' . h((string) ($r['violation'] ?? '')) . '</textarea></div>';
            $f .= '<div class="flex flex-wrap items-end gap-x-4 gap-y-3 pl-9">';
            foreach ([['primary', 'Впервые'], ['repeat', 'Повторно']] as [$k, $label]) {
                $f .= '<div class="flex items-end gap-2">' . ui_field($label . ', ₽', '<input name="' . $k . 'Amount" type="number" min="0" class="' . field_class('h-9 w-28 tabular-nums') . '" value="' . h((string) ($r[$k . 'Amount'] ?? '')) . '">');
                $f .= '<label class="flex h-9 items-center gap-1.5 text-xs font-medium"><input type="checkbox" name="' . $k . 'Dismissal" value="1" class="' . check_class() . '"' . ($r && boolish($r[$k . 'Dismissal']) ? ' checked' : '') . '>увольнение</label></div>';
            }
            $f .= '<label class="flex h-9 items-center gap-1.5 text-xs font-medium"><input type="checkbox" name="isActive" value="1" class="' . check_class() . '"' . (!$r || boolish($r['isActive']) ? ' checked' : '') . '>в листе</label>';
            $f .= '<button class="' . btn_class($r ? 'outline' : 'primary', 'sm') . ' ml-auto">' . icon($r ? 'save' : 'plus', 'size-4') . ($r ? 'Сохранить' : 'Добавить') . '</button></div></form>';
            if ($r) {
                $f .= form_open('delete_fine_rule', 'class="flex justify-end" ' . confirm_attr('Удалить пункт из листа штрафов?')) . hidden('id', $id) . '<button class="' . btn_class('danger-ghost', 'xs') . '">' . icon('trash', 'size-3.5') . 'Удалить пункт</button></form>';
            }
            return card($f, 'flex flex-col gap-2 p-4' . ($r && !boolish($r['isActive']) ? ' opacity-70' : ''));
        };
        $html .= '<div class="flex flex-col gap-3">';
        foreach ($rules as $i => $r) {
            $html .= $row($r, $i + 1);
        }
        $html .= '</div>' . section_title('Новый пункт') . $row(null, 0);
        self::layout($user, $html, ['title' => 'Лист штрафов', 'width' => 'narrow']);
    }
}
