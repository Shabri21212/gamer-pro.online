<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/payroll.php';

trait PagesSalary
{
    private static function pageSalary(array $user): void
    {
        $month = self::monthFromQuery();
        $eid = (int) ($user['employeeId'] ?? 0);
        $data = $eid ? Payroll::employee($eid, $month['key']) : null;
        if (!$data) {
            $action = can($user, 'staff.manage') ? '<a class="' . btn_class('outline') . '" href="' . h(url_to('/bar', ['tab' => 'salary'])) . '">Ведомость сотрудников</a>' : '';
            self::layout($user, page_head('Моя зарплата') . empty_state('wallet', 'Нет привязанной карточки сотрудника', 'Для личного расчёта владелец должен связать ваш доступ с карточкой сотрудника.', $action), ['title' => 'Моя зарплата']);
            return;
        }
        $selected = self::getStr('period', 1);
        $period = in_array($selected, ['1', '2'], true) ? (int) $selected : ($month['isCurrent'] && (int) $data['at']->format('j') <= 15 ? 1 : 2);
        $p = $data['periods'][$period];
        $nav = '<div class="flex items-center gap-2"><a class="' . btn_class('outline', 'icon-sm') . '" aria-label="Предыдущий месяц" href="' . h(url_to('/salary', ['m' => $month['prev'], 'period' => $period])) . '">' . icon('chevronleft') . '</a><span class="text-sm font-semibold">' . h($month['title']) . '</span><a class="' . btn_class('outline', 'icon-sm') . '" aria-label="Следующий месяц" href="' . h(url_to('/salary', ['m' => $month['next'], 'period' => $period])) . '">' . icon('chevronright') . '</a></div>';
        $html = page_head('Моя зарплата', 'Только ваши начисления. Будущие смены не входят в сумму к выплате.', $nav, 'Личное');
        $html .= segmented([
            ['href' => url_to('/salary', ['m' => $month['key'], 'period' => 1]), 'label' => '1–15 · ставка', 'active' => $period === 1],
            ['href' => url_to('/salary', ['m' => $month['key'], 'period' => 2]), 'label' => '16–' . substr($month['to'], 8, 2) . ' · ставка + процент', 'active' => $period === 2],
        ], 'Период начисления');
        $hero = '<div class="flex flex-col gap-5"><div class="flex flex-wrap items-start justify-between gap-3"><div><p class="text-sm text-muted-foreground">Расчётно к выплате · ' . h(format_day_month($p['from']) . ' — ' . format_day_month($p['to'])) . '</p><p class="mt-2 font-display text-3xl font-semibold tracking-tight tabular-nums sm:text-4xl">' . h(money_rub($p['total'])) . '</p></div>' . chip('На ' . $data['at']->format('d.m H:i'), 'outline', 'clock') . '</div>';
        $hero .= '<p class="text-sm text-muted-foreground">Началось ' . $p['units'] . ' из ' . $p['planned'] . ' смен по графику. Сутки считаются за две.</p>';
        $hero .= progress_bar($p['planned'] ? (int) round($p['units'] / $p['planned'] * 100) : 0, 'Доля начавшихся смен');
        $hero .= '<div class="grid gap-4 border-t border-border pt-4 sm:grid-cols-3">';
        foreach ([['За смены', money_rub($p['gross'])], ['Процент с продаж', $period === 1 ? 'Во второй половине' : money_rub($p['bar'])], ['Штрафы', money_rub($p['fines'])]] as [$label, $value]) {
            $hero .= '<div><p class="text-sm text-muted-foreground">' . h($label) . '</p><p class="mt-1 text-lg font-semibold tabular-nums">' . h($value) . '</p></div>';
        }
        $hero .= '</div></div>';
        $html .= card($hero, 'p-5 sm:p-6');
        $html .= '<p class="text-sm leading-relaxed text-muted-foreground">Ставка начисляется в момент начала смены по графику: день и сутки — в 08:00, ночь — в 20:00, время Тюмени. С 1 по 15 — только ставка; с 16 до конца месяца — ставка и процент с продаж за 16–' . substr($month['to'], 8, 2) . '. Учитываются персональные ставки и проценты, отменённые продажи исключены. Это расчёт, не отметка о фактической выплате.</p>';
        if ($period === 2 && $data['warnings']) {
            $html .= '<div class="rounded-lg border border-border bg-muted p-4 text-sm text-foreground" role="status"><p class="font-semibold">Процент может быть неполным</p><ul class="mt-2 list-inside list-disc">';
            foreach ($data['warnings'] as $warning) {
                $html .= '<li>' . h($warning) . '</li>';
            }
            $html .= '</ul><p class="mt-2">Учитываются только загруженные продажи. Обновление — в разделе «Продажи и аналитика».</p></div>';
        }
        $html .= self::salaryFinesCard($p['deductions'], $p['fines']);
        $body = card_head('Смены и начисления', 'За будущие выходы пока не начислено: ' . h(money_rub($p['future'])), '', 'calendar');
        if (!$p['shifts']) {
            $body .= '<p class="mt-4 text-sm text-muted-foreground">В этом периоде смен пока нет.</p>';
        } else {
            $body .= '<ul class="mt-4 divide-y divide-border">';
            foreach ($p['shifts'] as $s) {
                $body .= '<li class="flex items-start justify-between gap-4 py-4"><div class="min-w-0"><p class="text-sm font-semibold">' . h(format_day_month((string) $s['date']) . ' · ' . SHIFT_LABELS[$s['shiftType']]) . '</p><p class="mt-1 text-sm text-muted-foreground">' . h($s['branchName'] . ' · ' . SHIFT_HINTS[$s['shiftType']]) . '</p><p class="mt-1 text-sm text-muted-foreground">' . h($s['state'] === 'live' ? 'Смена идёт · начислено' : ($s['started'] ? 'Начислено' : 'Запланировано · не начислено')) . '</p></div><div class="shrink-0 text-right"><p class="text-sm font-semibold tabular-nums">' . h(money_rub($s['started'] ? $s['amount'] : 0)) . '</p><p class="mt-1 text-sm text-muted-foreground">' . h(money_rub($s['rate'])) . ($s['shiftType'] === 'full' ? ' × 2' : ' / смена') . '</p></div></li>';
            }
            $body .= '</ul>';
        }
        $html .= card($body, 'p-5');
        self::layout($user, $html, ['title' => 'Моя зарплата', 'width' => 'narrow']);
    }

    private static function salaryFinesCard(array $fines, int $total): string
    {
        if (!$fines) {
            return '';
        }
        $body = card_head('Назначенные штрафы', h('Удержания за период: ' . money_rub($total)), '<a class="' . btn_class('quiet', 'sm') . '" href="' . h(url_to('/fines')) . '">Подробнее ' . icon('arrow') . '</a>', 'scale');
        $body .= '<ul class="mt-4 divide-y divide-border">';
        foreach ($fines as $fine) {
            $body .= '<li class="flex items-start justify-between gap-4 py-3"><div class="min-w-0"><p class="text-sm leading-relaxed break-words">' . h((string) $fine['reason']) . '</p><p class="mt-1 text-sm text-muted-foreground">' . h(format_dt((string) $fine['createdAt'])) . '</p></div><span class="shrink-0 text-sm font-semibold text-primary-ink tabular-nums">−' . h(money_rub((int) $fine['amount'])) . '</span></li>';
        }
        return card($body . '</ul>', 'p-5');
    }

    private static function personalPayCard(array $user): string
    {
        $eid = (int) ($user['employeeId'] ?? 0);
        $now = now_tyumen();
        $data = $eid ? Payroll::employee($eid, $now->format('Y-m'), $now) : null;
        if (!$data) {
            return '';
        }
        $period = (int) $now->format('j') <= 15 ? 1 : 2;
        $p = $data['periods'][$period];
        $body = card_head('Моя зарплата', h(format_day_month($p['from']) . ' — ' . format_day_month($p['to'])), '<a class="' . btn_class('quiet', 'sm') . '" href="' . h(url_to('/salary')) . '">Расчёт ' . icon('arrow') . '</a>', 'wallet');
        $body .= '<p class="mt-4 font-display text-2xl font-semibold tabular-nums">' . h(money_rub($p['total'])) . '</p><p class="mt-2 text-sm text-muted-foreground">Начислено за ' . h(plural($p['units'], 'смену', 'смены', 'смен')) . '. Будущие смены не включены.</p>';
        $fines = array_merge($data['periods'][1]['deductions'], $data['periods'][2]['deductions']);
        return card($body, 'p-5') . self::salaryFinesCard($fines, $data['periods'][1]['fines'] + $data['periods'][2]['fines']);
    }
}
