<?php
declare(strict_types=1);

trait PagesGuests
{
    private static function guestStatusTabs(string $path, string $status, array $cnt, array $q): string
    {
        $tabs = [
            'all' => ['label' => 'Все', 'href' => url_to($path, $q), 'count' => array_sum($cnt)],
            'pending' => ['label' => 'Ждут решения', 'href' => url_to($path, $q + ['status' => 'pending']), 'count' => $cnt['pending'] ?? 0],
            'credited' => ['label' => 'Зачислено', 'href' => url_to($path, $q + ['status' => 'credited']), 'count' => $cnt['credited'] ?? 0],
            'rejected' => ['label' => 'Отказ', 'href' => url_to($path, $q + ['status' => 'rejected']), 'count' => $cnt['rejected'] ?? 0],
        ];
        return tabs_nav($tabs, $status, 'Статус');
    }

    /** Строки журнала гостей с фильтрами ?status= и ?branch=. */
    private static function guestRows(string $table, string $path): array
    {
        $status = self::getStr('status', 16);
        $status = in_array($status, ['pending', 'credited', 'rejected'], true) ? $status : 'all';
        [$branch, $branchNav] = self::branchFilter($path, $status !== 'all' ? ['status' => $status] : []);
        $where = $branch ? ' WHERE t."branchId" = ' . (int) $branch['id'] : '';
        $cnt = [];
        foreach (Db::all('SELECT t.status, COUNT(*) AS n FROM ' . $table . ' t' . $where . ' GROUP BY t.status') as $r) {
            $cnt[(string) $r['status']] = (int) $r['n'];
        }
        $cond = $where;
        if ($status !== 'all') {
            $cond .= ($cond === '' ? ' WHERE ' : ' AND ') . 't.status = \'' . $status . '\'';
        }
        $rows = Db::all('SELECT t.*, b.name AS "branchName" FROM ' . $table . ' t JOIN branches b ON b.id = t."branchId"' . $cond . ' ORDER BY t."createdAt" DESC LIMIT 300');
        $q = $branch ? ['branch' => $branch['slug']] : [];
        return [$rows, $status, self::guestStatusTabs($path, $status, $cnt, $q) . '', $branchNav];
    }

    private static function decideForm(string $action, int $id): string
    {
        return form_open($action, 'class="flex flex-wrap gap-1.5"') . hidden('id', $id)
            . '<button name="decision" value="credited" class="' . btn_class('ink', 'xs') . '">' . icon('check', 'size-3.5') . 'Зачислено</button>'
            . '<button name="decision" value="rejected" class="' . btn_class('danger', 'xs') . '" ' . confirm_attr('Отказать в бонусах?') . '>Отказ</button></form>';
    }

    private static function phoneCell(string $phone): string
    {
        return '<span class="font-mono text-[13px] whitespace-nowrap tabular-nums">' . h(format_phone($phone)) . '</span>';
    }

    /* ---------------- Бонусы за отзывы ---------------- */

    private static function pageReviews(array $user): void
    {
        $promo = self::portal_json('review_promo', DEFAULT_REVIEW_PROMO);
        [$rows, $status, $tabs, $branchNav] = self::guestRows('review_bonuses', '/reviews');
        $known = [];
        foreach (Db::all('SELECT "branchId", "guestPhone", status, "createdAt" FROM review_bonuses WHERE status != \'rejected\'') as $r) {
            $digits = normalize_phone((string) $r['guestPhone']) ?? (string) $r['guestPhone'];
            $known[(int) $r['branchId']][$digits] = (REVIEW_STATUS[$r['status']] ?? $r['status']) . ', ' . format_dt((string) $r['createdAt'], false);
        }
        $actions = can($user, 'content.edit') ? '<a class="' . btn_class('outline') . '" href="' . h(url_to('/reviews/edit')) . '">' . icon('sliders', 'size-4') . 'Условия акции</a>' : '';
        $html = page_head('Бонусы за отзывы', h((string) $promo['intro']), $actions);
        $html .= '<div class="grid items-start gap-6 lg:grid-cols-[21rem_minmax(0,1fr)]">';

        $form = form_open('save_review', 'class="flex flex-col gap-4" data-review-form data-submit-once');
        $form .= '<div class="flex items-start justify-between gap-3"><h2 class="font-display text-base font-semibold">Новая заявка</h2><span class="font-display text-2xl leading-none font-semibold text-primary-ink tabular-nums">+' . (int) $promo['textBonus'] . '</span></div>';
        $form .= ui_field('Филиал', ui_select('branchId', self::branchOptions(), $user['homeBranchId'] ?? null, 'required data-review-branch'));
        $form .= ui_field('Телефон гостя', '<input name="guestPhone" class="' . field_class('font-mono') . '" required inputmode="tel" autocomplete="off" placeholder="+7 900 000-00-00" data-review-phone>');
        $form .= '<p class="-mt-2 min-h-5 text-xs leading-relaxed text-muted-foreground" data-phone-hint aria-live="polite">Бонус даётся один раз на филиал — номер проверится сам.</p>';
        $form .= '<script type="application/json" data-review-known>' . json_encode((object) $known, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
        $form .= '<button class="' . btn_class() . '">' . icon('send', 'size-4') . 'Отправить на зачисление</button></form>';
        $html .= card($form, 'p-5 lg:sticky lg:top-8');

        $html .= '<div class="flex min-w-0 flex-col gap-4">';
        $html .= '<div class="flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between">' . $tabs . $branchNav . '</div>';
        $html .= search_input('data-table-filter="reviews-table"', 'Поиск по телефону', 'max-w-sm');
        if (!$rows) {
            $html .= empty_state('star', 'Заявок нет', 'Когда гость оставит отзыв, добавьте его номер в форме слева.');
        } else {
            $canAny = can($user, 'guests.decide');
            $html .= table_open(['Когда', 'Филиал', 'Телефон', 'Сумма', 'Статус', 'Добавил(а)'] + ($canAny ? [6 => ['', 'w-0']] : []), 'id="reviews-table"');
            foreach ($rows as $r) {
                $digits = normalize_phone((string) $r['guestPhone']) ?? '';
                $html .= '<tr class="border-t border-border" data-filter-text="' . h($digits . ' ' . $r['guestPhone']) . '">';
                $html .= td('Когда', '<span class="whitespace-nowrap">' . h(format_dt((string) $r['createdAt'])) . '</span>', 'text-muted-foreground');
                $html .= td('Филиал', h($r['branchName']));
                $html .= td('Телефон', self::phoneCell((string) $r['guestPhone']));
                $html .= td('Сумма', '<span class="font-semibold tabular-nums">' . num_fmt((int) $r['bonusAmount']) . '</span>');
                $html .= td('Статус', status_chip((string) $r['status']) . ($r['status'] !== 'pending' && !empty($r['decidedByName']) ? '<span class="mt-1 block text-xs text-muted-foreground">' . h(short_name((string) $r['decidedByName'])) . '</span>' : ''));
                $html .= td('Добавил(а)', h(short_name((string) $r['createdByName'])), 'text-muted-foreground');
                if ($canAny) {
                    $html .= td('', $r['status'] === 'pending' && can($user, 'guests.decide', (int) $r['branchId']) ? self::decideForm('decide_review', (int) $r['id']) : '');
                }
                $html .= '</tr>';
            }
            $html .= table_close();
            $html .= '<p class="hidden text-sm text-muted-foreground" data-filter-empty="reviews-table">По этому номеру заявок нет — бонус ему ещё не начисляли.</p>';
        }
        $html .= '</div></div>';
        self::layout($user, $html, ['title' => 'Бонусы за отзывы', 'width' => 'wide']);
    }

    private static function pageReviewsEdit(array $user): void
    {
        $promo = self::portal_json('review_promo', DEFAULT_REVIEW_PROMO);
        $html = back_link(url_to('/reviews'), 'Бонусы за отзывы');
        $html .= page_head('Условия акции за отзывы', 'Текст видят все сотрудники над формой заявки. Сумма подставляется в каждую новую заявку.');
        $form = form_open('save_promo', 'class="flex flex-col gap-5"');
        $form .= ui_field('Условия для сотрудников', '<textarea name="intro" class="' . textarea_class('min-h-32') . '" maxlength="2000" required>' . h((string) $promo['intro']) . '</textarea>');
        $form .= ui_field('Бонус за отзыв', '<input name="textBonus" type="number" min="1" max="100000" class="' . field_class('max-w-40') . '" value="' . (int) $promo['textBonus'] . '" required>', 'В бонусных рублях, целое число');
        $form .= '<div class="flex justify-end gap-2"><a class="' . btn_class('ghost') . '" href="' . h(url_to('/reviews')) . '">Отмена</a><button class="' . btn_class() . '">' . icon('save', 'size-4') . 'Сохранить</button></div></form>';
        $html .= card($form, 'p-5 sm:p-6');
        self::layout($user, $html, ['title' => 'Условия акции', 'width' => 'narrow']);
    }

    /* ---------------- Компенсации ---------------- */

    private static function pageCompensations(array $user): void
    {
        [$rows, $status, $tabs, $branchNav] = self::guestRows('compensations', '/compensations');
        $html = page_head('Компенсации гостям', 'Если у гостя что-то пошло не так по вине клуба — запишите, что случилось и что вы сделали. Бонусы зачисляет ответственный.');
        $html .= '<div class="grid items-start gap-6 lg:grid-cols-[21rem_minmax(0,1fr)]">';

        $form = form_open('save_comp', 'class="flex flex-col gap-4" data-submit-once');
        $form .= '<h2 class="font-display text-base font-semibold">Новая компенсация</h2>';
        $form .= '<div class="grid grid-cols-2 gap-3">' . ui_field('Филиал', ui_select('branchId', self::branchOptions(), $user['homeBranchId'] ?? null, 'required'));
        $form .= ui_field('Бонусов', '<input name="bonusAmount" type="number" min="1" max="5000" class="' . field_class() . '" required placeholder="до 5000">') . '</div>';
        $form .= ui_field('Телефон гостя', '<input name="guestPhone" class="' . field_class('font-mono') . '" required inputmode="tel" autocomplete="off" placeholder="+7 900 000-00-00">');
        $form .= ui_field('Что случилось', '<textarea name="reason" class="' . textarea_class('min-h-20') . '" required minlength="5" maxlength="1000" placeholder="Например: ПК завис на 40 минут"></textarea>');
        $form .= ui_field('Что сделали', '<textarea name="actionsTaken" class="' . textarea_class('min-h-16') . '" maxlength="1000" placeholder="Пересадили, вернули время…"></textarea>');
        $form .= '<button class="' . btn_class() . '">' . icon('send', 'size-4') . 'Записать</button></form>';
        $html .= card($form, 'p-5 lg:sticky lg:top-8');

        $html .= '<div class="flex min-w-0 flex-col gap-4">';
        $html .= '<div class="flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between">' . $tabs . $branchNav . '</div>';
        $html .= search_input('data-table-filter="comp-table"', 'Поиск по телефону или причине', 'max-w-sm');
        if (!$rows) {
            $html .= empty_state('coins', 'Записей нет');
        } else {
            $canAny = can($user, 'guests.decide');
            $html .= table_open(['Когда', 'Филиал', 'Телефон', 'Причина и что сделали', 'Бонусов', 'Статус'] + ($canAny ? [6 => ['', 'w-0']] : []), 'id="comp-table"');
            foreach ($rows as $r) {
                $digits = normalize_phone((string) $r['guestPhone']) ?? '';
                $done = trim((string) $r['actionsTaken']);
                $html .= '<tr class="border-t border-border" data-filter-text="' . h(mb_strtolower($digits . ' ' . $r['guestPhone'] . ' ' . $r['reason'] . ' ' . $done)) . '">';
                $html .= td('Когда', '<span class="whitespace-nowrap">' . h(format_dt((string) $r['createdAt'])) . '</span><span class="mt-0.5 block text-xs">' . h(short_name((string) $r['createdByName'])) . '</span>', 'text-muted-foreground');
                $html .= td('Филиал', h($r['branchName']));
                $html .= td('Телефон', self::phoneCell((string) $r['guestPhone']));
                $html .= td('Причина', '<p class="max-w-md leading-relaxed text-pretty break-words">' . h($r['reason']) . '</p>' . ($done !== '' ? '<p class="mt-1 max-w-md text-xs leading-relaxed text-muted-foreground break-words">' . icon('check', 'mr-1 inline size-3') . h($done) . '</p>' : ''));
                $html .= td('Бонусов', '<span class="font-semibold tabular-nums">' . num_fmt((int) $r['bonusAmount']) . '</span>');
                $html .= td('Статус', status_chip((string) $r['status']));
                if ($canAny) {
                    $html .= td('', $r['status'] === 'pending' && can($user, 'guests.decide', (int) $r['branchId']) ? self::decideForm('decide_comp', (int) $r['id']) : '');
                }
                $html .= '</tr>';
            }
            $html .= table_close();
            $html .= '<p class="hidden text-sm text-muted-foreground" data-filter-empty="comp-table">Ничего не нашлось.</p>';
        }
        $html .= '</div></div>';
        self::layout($user, $html, ['title' => 'Компенсации', 'width' => 'wide']);
    }

    /* ---------------- Бонусная система ---------------- */

    private static function loyaltyTiers(): array
    {
        return Db::all('SELECT * FROM loyalty_tiers ORDER BY "sortOrder", "minHours", id');
    }

    private static function pageLoyalty(array $user): void
    {
        $tiers = self::loyaltyTiers();
        $tariffs = self::portal_json('tariffs', DEFAULT_TARIFFS);
        $actions = can($user, 'content.edit') ? '<a class="' . btn_class('outline') . '" href="' . h(url_to('/loyalty/edit')) . '">' . icon('pencil', 'size-4') . 'Изменить уровни</a>' : '';
        $html = page_head('Бонусная система', 'Кэшбек возвращается бонусами с каждого пополнения. Уровень гостя зависит от сыгранных часов.', $actions);
        $html .= '<div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]">';
        if (!$tiers) {
            $html .= '<div class="lg:col-start-1 lg:row-span-2 lg:row-start-1">' . empty_state('percent', 'Уровни не настроены', 'Добавьте уровни, чтобы считать кэшбек.') . '</div>';
        } else {
            $first = $tiers[0];
            $calc = card_head('Кэшбек с пополнения', 'Выберите уровень гостя и введите сумму.', '', 'gift');
            $calc .= '<div class="mt-5 flex flex-col gap-4">' . ui_field('Сумма пополнения, ₽', '<input class="' . field_class('h-11 text-lg tabular-nums') . '" inputmode="numeric" autocomplete="off" placeholder="1000" data-topup>');
            $calc .= '<div class="flex flex-wrap gap-1.5" role="group" aria-label="Быстрая сумма">';
            foreach ([300, 500, 1000, 2000] as $preset) {
                $calc .= '<button type="button" class="' . btn_class('outline', 'xs') . ' tabular-nums" data-topup-preset="' . $preset . '">' . h(num_fmt($preset)) . '</button>';
            }
            $calc .= '</div><div class="flex flex-col gap-1.5 rounded-lg bg-muted/60 px-4 py-3.5" aria-live="polite"><span class="text-xs font-semibold tracking-wide text-muted-foreground uppercase">Вернётся бонусами</span>';
            $calc .= '<output class="font-display text-3xl leading-none font-semibold text-primary-ink tabular-nums" data-cashback>—</output>';
            $calc .= '<span class="text-sm text-muted-foreground">Уровень <span class="font-semibold text-foreground" data-tier-name>' . h((string) $first['title']) . '</span> · кэшбек <span class="tabular-nums" data-tier-percent>' . (int) $first['cashbackPercent'] . '</span>%</span></div></div>';
            $html .= card($calc, 'p-5 lg:col-start-2', 'section', 'data-cashback-calc');

            $list = '<div class="flex items-baseline justify-between gap-3 border-b border-border px-4 py-3"><h2 id="tiers-title" class="text-sm font-semibold">Уровень гостя</h2><span class="text-xs text-muted-foreground">сыгранные часы · кэшбек</span></div>';
            $list .= '<div class="flex flex-col" role="radiogroup" aria-labelledby="tiers-title" data-tiers>';
            foreach ($tiers as $i => $t) {
                $range = $t['maxHours'] === null || $t['maxHours'] === '' ? 'от ' . (int) $t['minHours'] . ' ч' : (int) $t['minHours'] . '–' . (int) $t['maxHours'] . ' ч';
                $list .= '<button type="button" role="radio" aria-checked="' . ($i === 0 ? 'true' : 'false') . '" tabindex="' . ($i === 0 ? '0' : '-1') . '" class="tier-card" data-tier data-percent="' . (int) $t['cashbackPercent'] . '" data-title="' . h((string) $t['title']) . '">';
                $list .= '<span class="tier-radio" aria-hidden="true"></span>';
                $list .= '<span class="flex min-w-0 flex-col gap-0.5"><span class="flex flex-wrap items-baseline gap-x-2"><span class="font-semibold">' . h((string) $t['title']) . '</span><span class="text-xs text-muted-foreground tabular-nums">' . h($range) . '</span></span>';
                $list .= '<span class="flex items-center gap-1.5 text-xs text-muted-foreground">' . icon('cake', 'size-3.5') . 'В день рождения ' . h(money_rub((int) $t['birthdayBonus'])) . '</span></span>';
                $list .= '<span class="flex flex-col items-end gap-1"><span class="font-display text-xl leading-none font-semibold tabular-nums">' . (int) $t['cashbackPercent'] . '%</span><span class="text-xs font-semibold text-primary-ink tabular-nums" data-tier-cash hidden></span></span></button>';
            }
            $html .= card($list . '</div>', 'overflow-hidden lg:col-start-1 lg:row-span-2 lg:row-start-1');
        }
        $time = card_head('Стоимость времени', 'Поминутно по цене часа на базовом тарифе', '', 'timer');
        $branchesT = $tariffs['branches'] ?? [];
        $time .= '<div class="mt-5 flex flex-col gap-4" data-time-calc>';
        if (count($branchesT) > 1) {
            $time .= '<div class="flex gap-1 self-start rounded-lg border border-border bg-muted/40 p-1" role="tablist">';
            foreach ($branchesT as $i => $b) {
                $time .= '<button type="button" role="tab" class="seg-btn" aria-selected="' . ($i === 0 ? 'true' : 'false') . '" data-branch-tab="' . $i . '">' . h((string) $b['branchName']) . '</button>';
            }
            $time .= '</div>';
        }
        $time .= '<div class="flex gap-2"><input class="' . field_class('h-11 text-lg tabular-nums') . '" inputmode="numeric" placeholder="90" aria-label="Длительность" data-duration>' . ui_select('', ['minutes' => 'минут', 'hours' => 'часов'], 'minutes', 'aria-label="Единицы" data-unit', 'h-11 w-32') . '</div>';
        foreach ($branchesT as $i => $b) {
            $time .= '<div class="grid gap-2 sm:grid-cols-3' . ($i === 0 ? '' : ' hidden') . '" data-branch-panel="' . $i . '">';
            foreach (($b['zones'] ?? []) as $zi => $z) {
                $hour = (int) ($b['packages'][0]['prices'][$zi] ?? 0);
                $time .= '<div class="flex flex-col gap-1 rounded-md border border-border px-3 py-2.5"><span class="text-sm font-semibold">' . h((string) $z) . '</span><span class="text-xs text-muted-foreground">' . $hour . ' ₽/час</span><span class="font-display text-xl font-semibold tabular-nums" data-zone-price data-hour="' . $hour . '">—</span></div>';
            }
            $time .= '</div>';
        }
        $time .= '</div>';
        $html .= card($time, 'p-5 lg:col-start-2') . '</div>';
        self::layout($user, $html, ['title' => 'Бонусная система']);
    }

    private static function pageLoyaltyEdit(array $user): void
    {
        $tiers = self::loyaltyTiers();
        $html = back_link(url_to('/loyalty'), 'Бонусная система');
        $html .= page_head('Уровни лояльности', 'Пустое «до» — уровень без верхней границы. Изменения сразу видят все сотрудники.');
        $row = static function (?array $t): string {
            $id = $t ? (int) $t['id'] : 0;
            $f = form_open('save_loyalty', 'class="grid grid-cols-2 items-end gap-3 sm:grid-cols-[minmax(0,1.4fr)_repeat(4,minmax(0,1fr))_auto]"') . hidden('id', $id);
            $f .= ui_field('Название', '<input name="title" class="' . field_class() . '" value="' . h((string) ($t['title'] ?? '')) . '" required maxlength="80"' . ($t ? '' : ' placeholder="Новый уровень"') . '>', '', 'col-span-2 sm:col-span-1');
            $f .= ui_field('От, часов', '<input name="minHours" type="number" min="0" class="' . field_class() . '" value="' . h((string) ($t['minHours'] ?? '0')) . '">');
            $f .= ui_field('До, часов', '<input name="maxHours" type="number" min="0" class="' . field_class() . '" value="' . h((string) ($t['maxHours'] ?? '')) . '" placeholder="∞">');
            $f .= ui_field('Кэшбек, %', '<input name="cashbackPercent" type="number" min="0" max="100" class="' . field_class() . '" value="' . h((string) ($t['cashbackPercent'] ?? '0')) . '">');
            $f .= ui_field('ДР, ₽', '<input name="birthdayBonus" type="number" min="0" class="' . field_class() . '" value="' . h((string) ($t['birthdayBonus'] ?? '0')) . '">');
            $f .= '<button class="' . btn_class($t ? 'outline' : 'primary') . ' col-span-2 sm:col-span-1">' . icon($t ? 'save' : 'plus', 'size-4') . ($t ? 'Сохранить' : 'Добавить') . '</button></form>';
            return card($f, 'p-4');
        };
        $html .= '<div class="flex flex-col gap-3">';
        foreach ($tiers as $t) {
            $html .= $row($t);
        }
        $html .= '</div>' . section_title('Добавить уровень') . $row(null);
        self::layout($user, $html, ['title' => 'Уровни лояльности']);
    }
}
