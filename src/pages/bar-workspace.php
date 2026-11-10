<?php

declare(strict_types=1);

trait BarWorkspace
{
    private static function barSections(): array
    {
        return [
            'overview' => ['Обзор', 'home', 'Главные показатели и сигналы за выбранный период.'],
            'sales' => ['Продажи', 'coins', 'Журнал товарных позиций: сотрудник, оплата и статус.'],
            'team' => ['Команда', 'users', 'Результаты администраторов и детализация их продаж.'],
            'goods' => ['Товары', 'tags', 'Популярные позиции и товары, которые пора пополнить.'],
            'occupancy' => ['Загрузка', 'monitor', 'Текущее состояние клуба по игровым сессиям Langame.'],
            'salary' => ['Зарплата', 'wallet', 'Расчётная ведомость по графику, продажам и штрафам.'],
        ];
    }

    private static function barWorkspaceHead(array $user, array $branch, array $month, array $settings, string $tab): string
    {
        $sections = self::barSections();
        $base = ['branch' => $branch['slug'], 'm' => $month['key']];
        $connected = boolish($settings['isEnabled'] ?? false);
        $actions = $user['isOwner'] ? '<a class="' . btn_class('outline', 'sm') . '" href="' . h(url_to('/manage/langame')) . '">' . icon('sliders', 'size-4') . 'Интеграция</a>' : '';
        if ($user['employeeId'] !== null) {
            $actions = '<a class="' . btn_class('quiet', 'sm') . '" href="' . h(url_to('/bar', $base + ['view' => 'me'])) . '">Мои продажи</a>' . $actions;
        }
        $html = page_head('Аналитика клуба', $sections[$tab][2], $actions, 'GAMER PRO / Управление');
        $html .= '<div class="crm-toolbar"><nav class="crm-tabs" aria-label="Разделы аналитики">';
        foreach ($sections as $key => [$label, $glyph]) {
            $html .= '<a href="' . h(url_to('/bar', $base + ['tab' => $key])) . '"' . ($key === $tab ? ' aria-current="page"' : '') . '>' . icon($glyph, 'size-4') . '<span>' . h($label) . '</span></a>';
        }
        $html .= '</nav><div class="crm-filters">';
        if ($tab === 'salary') {
            $html .= '<span class="crm-scope">' . icon('users', 'size-4') . 'Все филиалы</span>';
        } else {
            $html .= '<form method="get" action="' . h(url_to('/bar')) . '" class="crm-branch-form">' . hidden('tab', $tab) . hidden('m', $month['key']);
            $html .= '<label for="analytics-branch" class="sr-only">Филиал</label><select id="analytics-branch" name="branch">';
            foreach (self::branches() as $b) {
                $html .= '<option value="' . h($b['slug']) . '"' . ($b['id'] === $branch['id'] ? ' selected' : '') . '>' . h($b['name']) . '</option>';
            }
            $html .= '</select><button class="' . btn_class('quiet', 'sm') . '">Выбрать</button></form>';
        }
        if ($tab === 'occupancy') {
            $html .= '<span class="crm-scope">Сегодня · ' . h(format_day_month(now_tyumen()->format('Y-m-d'))) . '</span>';
        } else {
            $html .= '<div class="crm-period"><a class="' . btn_class('quiet', 'icon-sm') . '" aria-label="Предыдущий месяц" href="' . h(url_to('/bar', ['m' => $month['prev'], 'branch' => $branch['slug'], 'tab' => $tab])) . '">' . icon('chevronleft') . '</a><span>' . h($month['title']) . '</span><a class="' . btn_class('quiet', 'icon-sm') . '" aria-label="Следующий месяц" href="' . h(url_to('/bar', ['m' => $month['next'], 'branch' => $branch['slug'], 'tab' => $tab])) . '">' . icon('chevronright') . '</a></div>';
        }
        $html .= '</div></div>';
        $html .= '<div class="crm-source"><span>' . icon('link', 'size-4') . ($tab === 'salary' ? 'График CRM + продажи Langame' : 'Langame Open API') . '</span>';
        $html .= '<span>' . ($tab === 'salary' ? 'Полнота продаж зависит от подключения всех филиалов' : (!$connected ? 'Нет подключения для филиала' : (boolish($settings['lastSyncOk'] ?? false) ? 'Продажи обновлены ' . h(format_dt((string) ($settings['lastSyncAt'] ?? ''))) : 'Синхронизация не завершена · данные могут быть неполными'))) . '</span>';
        if ($connected && $tab !== 'salary') {
            $html .= form_open('sync_langame') . hidden('branchId', $branch['id']) . hidden('back', '/bar?' . http_build_query($base + ['tab' => $tab])) . '<button class="' . btn_class('quiet', 'sm') . '">' . icon('refresh', 'size-4') . 'Обновить продажи</button></form>';
        }
        $html .= '</div>';
        if (!empty($settings['syncedFrom']) && $month['from'] < substr((string) $settings['syncedFrom'], 0, 10) && $tab !== 'occupancy' && $tab !== 'salary') {
            $html .= '<p class="crm-notice">История загружена начиная с ' . h(format_day_month(substr((string) $settings['syncedFrom'], 0, 10))) . '. Итоги выбранного периода могут быть неполными.</p>';
        }
        return $html;
    }

    private static function barUnavailable(array $user): string
    {
        $action = $user['isOwner'] ? '<a class="' . btn_class('primary', 'sm') . '" href="' . h(url_to('/manage/langame')) . '">' . icon('link', 'size-4') . 'Настроить подключение</a>' : '';
        return '<section class="crm-empty">' . icon('link', 'size-8') . '<h2>Данные ещё не подключены</h2><p>Здесь появятся реальные показатели выбранного филиала. Подключите Langame и дождитесь первой синхронизации — мы не заменяем отсутствующие данные примерными цифрами.</p>' . $action . '<span class="crm-footnote">Зарплата по графику доступна отдельно, даже без API.</span></section>';
    }

    private static function barOccupancy(array $branch, ?array $live): string
    {
        if (!$live || $live['active'] === null) {
            return '<section class="crm-empty">' . icon('monitor', 'size-8') . '<h2>Нет данных о сессиях</h2><p>Langame не вернул текущее состояние клуба. Это не означает, что зал пуст. Проверьте доступ к API или повторите просмотр позднее.</p></section>';
        }
        if (!empty($live['sessionsPartial'])) {
            return '<section class="crm-empty"><h2>Получена только часть сессий</h2><p>API вернул больше записей, чем лимит одной загрузки. Не показываем неполную выборку как достоверную загрузку клуба.</p></section>';
        }
        $active = (int) $live['active'];
        $capacity = (int) ($branch['pcCount'] ?? 0);
        $pct = $capacity > 0 ? min(100, (int) round($active / $capacity * 100)) : null;
        $html = '<section class="crm-occupancy"><div><span class="crm-eyebrow">' . h($branch['name']) . ' / Сейчас</span><h2>Посадка в клубе</h2><p>По активным игровым сессиям. Обновлено ' . h(date('H:i', (int) $live['at'])) . '</p></div><div class="crm-occupancy-number"><strong>' . $active . '</strong><span>' . ($capacity > 0 ? 'из ' . $capacity . ' ПК' : 'активных ПК') . '</span></div>';
        if ($pct !== null) {
            $html .= '<div class="crm-capacity"><meter min="0" max="' . $capacity . '" value="' . min($active, $capacity) . '" aria-label="Занято компьютеров">' . $pct . '%</meter><div><span>Загрузка ' . $pct . '%</span><span>Свободно ' . max(0, $capacity - $active) . '</span></div></div>';
        }
        $html .= '</section><div class="crm-kpis">';
        foreach ([['Сессии сегодня', $live['sessionsToday'] ?? '—', 'Начались сегодня'], ['Гости', $live['guestsToday'] ?? '—', 'Уникальные ID из API'], ['По брони', $live['bookedToday'] ?? '—', 'Начавшиеся сессии по брони'], ['Среднее время', isset($live['avgMinutes']) ? self::minutesLabel((int) $live['avgMinutes']) : '—', 'С учётом текущих сессий']] as [$label, $value, $note]) {
            $html .= self::barMetric($label, (string) $value, $note);
        }
        $html .= '</div>';
        $html .= card(card_head('Активность по часам', 'Уникальные ПК с сессиями внутри каждого часа; не моментальная посадка', '', 'clock') . '<div class="crm-hourly">' . self::hourBars($live['hourly'], 'Уникальные ПК по часам', static fn ($v) => $v . ' ПК', (int) now_tyumen()->format('G')) . '</div>', 'p-5');
        return $html . '<p class="crm-footnote">API сессий не содержит достоверной разбивки по физическим залам. Показываем клуб целиком; вместимость берём только из настроек филиала, не вычисляем по посещениям. Кэш состояния — до двух минут.</p>';
    }

    private static function barMetric(string $label, string $value, string $note): string
    {
        return '<div class="crm-metric"><span>' . h($label) . '</span><strong>' . h($value) . '</strong><small>' . h($note) . '</small></div>';
    }

    private static function barLedger(array $branch, array $month, array $base): string
    {
        $status = self::getStr('status', 12);
        $status = in_array($status, ['completed', 'cancelled'], true) ? $status : 'all';
        $search = self::getStr('search', 80);
        $page = max(1, min(100000, (int) self::getStr('page', 6)));
        $result = Langame::salesLedger((int) $branch['id'], $month['from'], $month['to'], $status, $search, $page);
        $html = '<section class="crm-ledger"><div class="crm-section-head"><div><h2>Журнал продаж</h2><p>Одна строка — одна товарная позиция, не полный чек</p></div><span class="crm-count">' . num_fmt($result['total']) . ' записей</span></div>';
        $html .= '<form action="' . h(url_to('/bar')) . '" method="get" class="crm-search">' . hidden('tab', 'sales') . hidden('m', $month['key']) . hidden('branch', $branch['slug']);
        $html .= '<label class="crm-search-field"><span class="sr-only">Товар или сотрудник</span>' . icon('search', 'size-4') . '<input name="search" type="search" maxlength="80" placeholder="Товар или сотрудник" value="' . h($search) . '"></label><label><span class="sr-only">Статус продажи</span><select name="status">';
        foreach (['all' => 'Все статусы', 'completed' => 'Продажи', 'cancelled' => 'Отмены'] as $key => $label) {
            $html .= '<option value="' . $key . '"' . ($status === $key ? ' selected' : '') . '>' . $label . '</option>';
        }
        $html .= '</select></label><button class="' . btn_class('outline', 'sm') . '">Показать</button>';
        if ($search !== '' || $status !== 'all') {
            $html .= '<a class="' . btn_class('quiet', 'sm') . '" href="' . h(url_to('/bar', $base + ['tab' => 'sales'])) . '">Сбросить</a>';
        }
        $html .= '</form>';
        if (!$result['rows']) {
            $html .= '<div class="crm-empty"><h3>Записей не найдено</h3><p>' . ($search !== '' || $status !== 'all' ? 'Измените поиск или сбросьте фильтры.' : 'За выбранный месяц ещё нет синхронизированных продаж.') . '</p></div>';
        } else {
            $html .= '<div class="crm-table-scroll" tabindex="0" role="region" aria-label="Журнал продаж"><table class="crm-table"><thead><tr>';
            foreach (['Дата', 'Товар', 'Сотрудник', 'Кол-во', 'Сумма', 'Оплата', 'Статус'] as $label) {
                $html .= '<th scope="col">' . $label . '</th>';
            }
            $html .= '</tr></thead><tbody>';
            foreach ($result['rows'] as $r) {
                $cancelled = boolish($r['isCancelled']);
                $html .= '<tr><td class="crm-nowrap">' . h(format_dt(str_replace(' ', 'T', $r['saleAt']))) . '</td><td><span class="crm-cell-title">' . h($r['goodsName'] ?? 'Товар') . '</span></td><td>' . h($r['employeeName'] ?: ($r['staffName'] ?: 'Без привязки')) . '</td><td class="crm-number">' . (int) $r['qty'] . '</td><td class="crm-number crm-nowrap"><strong>' . h(kop_rub((int) $r['amountX100'])) . '</strong></td><td>' . (boolish($r['isCashless']) ? 'Безнал' : 'Наличные') . '</td><td><span class="crm-status' . ($cancelled ? ' crm-status-cancelled' : '') . '">' . ($cancelled ? 'Отменена' : 'Продажа') . '</span></td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        $pages = max(1, (int) ceil($result['total'] / 25));
        $html .= '<div class="crm-pagination"><span>Страница ' . $result['page'] . ' из ' . $pages . '</span><div>';
        foreach ([-1 => 'Назад', 1 => 'Далее'] as $step => $label) {
            $target = $result['page'] + $step;
            $html .= $target >= 1 && $target <= $pages ? '<a class="' . btn_class('outline', 'sm') . '" href="' . h(url_to('/bar', $base + ['tab' => 'sales', 'status' => $status, 'search' => $search, 'page' => $target])) . '">' . $label . '</a>' : '<button class="' . btn_class('outline', 'sm') . '" disabled>' . $label . '</button>';
        }
        return $html . '</div></div></section>';
    }

    private static function barGoods(array $branch, array $month, ?array $live): string
    {
        $goods = Langame::branchTopGoods((int) $branch['id'], $month['from'], $month['to'], 30);
        $html = '<div class="crm-split"><section class="crm-ledger"><div class="crm-section-head"><div><h2>Ассортимент в продажах</h2><p>До 30 позиций по выручке · без отмен</p></div>' . icon('tags', 'size-5') . '</div>';
        if (!$goods) {
            $html .= '<div class="crm-empty"><h3>Пока нет продаж</h3><p>Товары появятся после синхронизации выбранного месяца.</p></div>';
        } else {
            $html .= '<div class="crm-table-scroll" tabindex="0" role="region" aria-label="Продажи товаров"><table class="crm-table"><thead><tr><th scope="col">Товар</th><th scope="col">Продано</th><th scope="col">Выручка</th></tr></thead><tbody>';
            foreach ($goods as $g) {
                $html .= '<tr><td>' . h($g['name']) . '</td><td class="crm-number">' . (int) $g['qty'] . '</td><td class="crm-number crm-nowrap">' . h(kop_rub((int) $g['amount'])) . '</td></tr>';
            }
            $html .= '</tbody></table></div>';
        }
        $html .= '</section><section class="crm-ledger"><div class="crm-section-head"><div><h2>Пополнить запасы</h2><p>Состояние сейчас, не за выбранный месяц</p></div></div><div class="crm-stock">';
        if (!($live['stockAvailable'] ?? false)) {
            $html .= '<p>Остатки не получены из API. Отсутствие предупреждений не означает, что товары в наличии.</p>';
        } elseif (empty($live['lowStock'])) {
            $html .= '<p>Среди проверенных продаваемых товаров нет низких остатков.</p>';
        } else {
            foreach ($live['lowStock'] as $g) {
                $html .= '<div class="crm-stock-row"><div><strong>' . h($g['name']) . '</strong><span>Около ' . (int) $g['perWeek'] . ' шт. в неделю</span></div><span class="crm-status crm-status-cancelled">' . (int) $g['count'] . ' шт.</span></div>';
            }
        }
        return $html . '<p class="crm-footnote">Проверка по остатку и темпу продаж за 30 дней. Показываем до 8 приоритетных позиций.</p></div></section></div>';
    }
}
