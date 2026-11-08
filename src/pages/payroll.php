<?php
declare(strict_types=1);

trait PagesPayroll
{
    private static function pagePayroll(array $user): void
    {
        self::requirePerm($user, 'owner');
        $month = self::monthFromQuery();
        $period = substr($month['from'], 0, 7);
        $report = Payroll::report($month['from'], $month['to']);
        $rows = $report['rows'];
        $branch = (int) self::getStr('branch', 10);
        if ($branch) $rows = array_values(array_filter($rows, static fn ($r) => (int) $r['homeBranchId'] === $branch));
        if (self::getStr('export', 3) === 'csv') {
            self::payrollCsv($rows, $period);
            return;
        }
        $actions = '<a class="' . btn_class('outline') . '" href="' . h(url_to('/manage/payroll', ['m' => $period, 'branch' => $branch ?: null, 'export' => 'csv'])) . '">' . icon('download', 'size-4') . 'Скачать CSV</a>';
        $html = page_head('Зарплата и выплаты', 'Прозрачный расчёт по каждому сотруднику. Только для владельца.', $actions);
        $nav = '<div class="flex flex-wrap items-end justify-between gap-4"><div class="flex items-center gap-3"><a class="' . btn_class('outline', 'icon-sm') . '" href="' . h(url_to('/manage/payroll', ['m' => $month['prev'], 'branch' => $branch ?: null])) . '" aria-label="Предыдущий месяц">' . icon('chevronleft') . '</a><h2 class="text-xl font-semibold">' . h($month['title']) . '</h2><a class="' . btn_class('outline', 'icon-sm') . '" href="' . h(url_to('/manage/payroll', ['m' => $month['next'], 'branch' => $branch ?: null])) . '" aria-label="Следующий месяц">' . icon('chevronright') . '</a></div>';
        $nav .= '<form method="get" class="flex items-end gap-2">' . hidden('m', $period) . ui_field('Основной филиал', ui_select('branch', ['0' => 'Все филиалы'] + self::branchOptions(), (string) $branch)) . '<button class="' . btn_class('outline') . '">Показать</button></form></div>';
        $html .= $nav;
        $sum = ['completed' => 0, 'base' => 0, 'commission' => 0, 'fineTotal' => 0, 'net' => 0];
        $incomplete = 0;
        foreach ($rows as $row) {
            foreach ($sum as $key => $value) $sum[$key] += $row[$key] ?? 0;
            if ($row['incomplete']) $incomplete++;
        }
        $html .= '<div class="grid grid-cols-2 gap-3 xl:grid-cols-4">';
        $html .= stat_tile('За завершённые смены', h(Payroll::money($sum['base'])), plural($sum['completed'], 'смена', 'смены', 'смен'));
        $html .= stat_tile('Процент с продаж', h(Payroll::money($sum['commission'])), 'по доступным данным');
        $html .= stat_tile('Штрафы', h(Payroll::money($sum['fineTotal'])), 'за выбранный месяц', url_to('/fines', ['m' => $period]));
        $html .= stat_tile('Предварительно к выплате', h(Payroll::money($sum['net'])), $incomplete ? $incomplete . ' сотрудников требуют проверки' : 'до подтверждения владельцем');
        $html .= '</div>';
        if (!$report['cacheAvailable'] || $report['unassigned'] || $incomplete) {
            $notice = !$report['cacheAvailable'] ? 'В этой базе нет кеша продаж Langame. Укажите сумму продаж и процент в расчёте сотрудника — они сохранятся в базе.' : 'Используется сохранённый кеш Langame, не онлайн-данные. Проверьте время синхронизации, привязки сотрудников и полноту периода.';
            if ($report['unassigned']) $notice .= ' Не привязано к сотрудникам продаж на ' . Payroll::money($report['unassigned']) . '.';
            $html .= '<p role="note" class="rounded-lg border border-border bg-muted p-4 text-sm leading-relaxed text-muted-foreground">' . h($notice) . '</p>';
        }
        $dialogs = '';
        if (!$rows) {
            $html .= empty_state('users', 'Нет сотрудников', 'Добавьте сотрудников или выберите другой филиал.');
        } else {
            $html .= '<section class="overflow-hidden rounded-xl border border-border bg-card text-card-foreground"><div class="flex flex-wrap items-center justify-between gap-3 border-b border-border p-4"><h2 class="text-base font-semibold">Расчётная ведомость <span class="text-muted-foreground">· ' . count($rows) . '</span></h2><span class="text-sm text-muted-foreground">Суммы в рублях</span></div><div class="relative overflow-x-auto" tabindex="0" role="region" aria-label="Таблица начислений, прокрутите вправо"><table class="payroll-table"><caption class="sr-only">Зарплата за ' . h($month['title']) . '</caption><thead><tr>';
            foreach (['Сотрудник', 'Смены', 'По ставке', 'Продажи / %', 'Начислено с продаж', 'Штрафы', 'К выплате', ''] as $heading) $html .= '<th scope="col" class="font-medium">' . ($heading !== '' ? h($heading) : '<span class="sr-only">Действия</span>') . '</th>';
            $html .= '</tr></thead><tbody>';
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $rates = $row['rates'] ? implode(' / ', array_map(static fn ($p) => rtrim(rtrim(number_format($p / 100, 2, ',', ''), '0'), ',') . '%', $row['rates'])) : 'процент не задан';
                $html .= '<tr><th scope="row"><span class="block font-semibold whitespace-nowrap">' . h($row['name']) . '</span><span class="block text-sm font-normal text-muted-foreground">' . h((string) $row['branchName']) . (!boolish($row['isActive']) ? ' · архив' : '') . '</span></th>';
                $html .= '<td><span class="font-semibold">' . $row['completed'] . '</span><span class="block text-muted-foreground">из ' . $row['planned'] . ' в графике</span>' . ($row['live'] ? '<span class="block text-primary-ink">' . $row['live'] . ' сейчас</span>' : '') . '</td>';
                $html .= '<td>' . h(Payroll::money($row['base'])) . '<span class="block text-muted-foreground">' . h((string) ($row['gradeTitle'] ?? 'Без уровня')) . '</span></td>';
                $html .= '<td>' . ($row['sales'] === null ? '<span class="text-muted-foreground">Нет данных</span>' : h(Payroll::money($row['sales']))) . '<span class="block text-muted-foreground">' . h($rates) . '</span></td>';
                $html .= '<td>' . ($row['commission'] === null ? '—' : h(Payroll::money($row['commission']))) . '<span class="block text-muted-foreground">' . ($row['manual'] ? 'Введено вручную' : 'Кеш Langame') . '</span></td>';
                $html .= '<td>' . ($row['fineTotal'] ? '<span class="text-destructive">−' . h(Payroll::money($row['fineTotal'])) . '</span>' : '<span class="text-muted-foreground">Нет</span>') . '</td>';
                $html .= '<td><span class="text-base font-semibold">' . h(Payroll::money($row['net'])) . '</span><span class="block text-muted-foreground">' . ($row['incomplete'] ? 'Неполные данные' : 'Предварительно') . '</span></td>';
                $html .= '<td><button type="button" class="' . btn_class('outline', 'sm') . '" data-dialog-open="payroll-' . $id . '" aria-label="Расчёт: ' . h($row['name']) . '">Расчёт</button></td></tr>';
                $dialogs .= self::payrollDialog($row, $period);
            }
            $html .= '</tbody></table></div></section>';
        }
        $html .= '<p class="text-sm leading-relaxed text-muted-foreground">В оплату включены только завершённые смены по графику; сутки — две смены. Ночная смена относится к дате начала и начисляется после 08:00. Продажи относятся к дате продажи, отменённые исключены. При наличии истории применяется ставка на начало смены, иначе — текущая. Итог — начисления минус штрафы, а не подтверждение фактической выплаты.</p>';
        $html .= '<a class="inline-flex w-fit items-center gap-2 text-sm font-medium text-primary-ink" href="' . h(url_to('/manage/grades')) . '">' . icon('settings', 'size-4') . 'Настроить уровни и ставки</a>';
        self::layout($user, $html . $dialogs, ['title' => 'Зарплата и выплаты', 'width' => 'wide']);
    }

    private static function payrollDialog(array $row, string $month): string
    {
        $options = $row['options'];
        $inputValue = static fn ($value) => $value === null ? '' : number_format($value / 100, 2, '.', '');
        $body = '<p class="text-sm text-muted-foreground">' . h($month) . ' · Изменения действуют только в этом месяце.</p>';
        $body .= '<dl class="grid grid-cols-2 gap-3 rounded-lg bg-muted p-4 text-sm"><dt>Завершено смен</dt><dd class="text-right font-semibold">' . $row['completed'] . '</dd><dt>По ставке</dt><dd class="text-right font-semibold">' . h(Payroll::money($row['base'])) . '</dd><dt>С продаж</dt><dd class="text-right font-semibold">' . ($row['commission'] === null ? 'Нет данных' : h(Payroll::money($row['commission']))) . '</dd><dt>Штрафы</dt><dd class="text-right font-semibold">' . h(Payroll::money($row['fineTotal'])) . '</dd></dl>';
        if ($row['missingRate']) $body .= '<p class="text-sm text-destructive">Для части смен не задана ставка. Проверьте уровень сотрудника.</p>';
        if ($row['syncedAt']) $body .= '<p class="text-sm text-muted-foreground">Кеш Langame обновлён: ' . h(format_dt($row['syncedAt'])) . '</p>';
        if ($row['fines']) {
            $body .= '<div><h3 class="font-semibold">Удержания</h3><ul class="mt-2 flex flex-col gap-2">';
            foreach ($row['fines'] as $fine) $body .= '<li class="text-sm"><span class="font-medium">' . h(money_rub((int) $fine['amount'])) . '</span> · ' . h($fine['reason']) . ' <span class="text-muted-foreground">' . h(format_dt($fine['createdAt'])) . '</span></li>';
            $body .= '</ul></div>';
        }
        $body .= form_open('save_payroll', 'class="flex flex-col gap-4"') . hidden('employeeId', (int) $row['id']) . hidden('month', $month) . hidden('revision', $row['revision']);
        $body .= ui_field('Индивидуальный процент с продаж, %', '<input name="percent" type="text" inputmode="decimal" maxlength="6" class="' . field_class() . '" value="' . h($inputValue($options['percentX100'] ?? null)) . '" placeholder="Из настроек Langame">', 'От 0 до 100. Пусто — использовать процент сотрудника или филиала из Langame.');
        $body .= ui_field('Сумма продаж за месяц, ₽', '<input name="sales" inputmode="decimal" maxlength="15" class="' . field_class() . '" value="' . h($inputValue($options['salesX100'] ?? null)) . '" placeholder="Из кеша Langame">', 'Ручная сумма заменяет кеш, а не прибавляется к нему. Пусто — вернуть автоматический расчёт.');
        $body .= ui_field('Источник или причина корректировки', '<textarea name="note" maxlength="500" class="' . textarea_class() . '" placeholder="Например, сверено с отчётом продаж за месяц">' . h((string) ($options['note'] ?? '')) . '</textarea>');
        if (!empty($options['updatedAt'])) $body .= '<p class="text-sm text-muted-foreground">Изменил ' . h((string) $options['updatedBy']) . ' · ' . h(format_dt($options['updatedAt'])) . '</p>';
        $body .= '<div class="flex justify-end gap-2"><button type="button" class="' . btn_class('ghost') . '" data-dialog-close>Отмена</button><button class="' . btn_class() . '">Сохранить расчёт</button></div></form>';
        return dialog_html('payroll-' . (int) $row['id'], $row['name'], $body, 'max-w-lg');
    }

    private static function payrollCsv(array $rows, string $month): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="payroll-' . $month . '.csv"');
        header('Cache-Control: private, no-store');
        $file = fopen('php://output', 'w');
        fwrite($file, "\xEF\xBB\xBF");
        fputcsv($file, ['Сотрудник', 'Филиал', 'Завершено смен', 'В графике', 'По ставке, ₽', 'Продажи, ₽', 'Процент', 'С продаж, ₽', 'Штрафы, ₽', 'Предварительно к выплате, ₽', 'Данные'], ';', '"', '');
        foreach ($rows as $row) {
            $decimal = static fn ($v) => $v === null ? '' : number_format($v / 100, 2, ',', '');
            $cells = [$row['name'], $row['branchName'], $row['completed'], $row['planned'], $decimal($row['base']), $decimal($row['sales']), implode(' / ', array_map($decimal, $row['rates'])), $decimal($row['commission']), $decimal($row['fineTotal']), $decimal($row['net']), $row['incomplete'] ? 'Неполные' : 'Предварительный расчёт'];
            $cells = array_map(static fn ($v) => preg_match('/^[\s]*[=+@\-]/u', (string) $v) ? "'" . $v : (string) $v, $cells);
            fputcsv($file, $cells, ';', '"', '');
        }
        fclose($file);
    }
}
