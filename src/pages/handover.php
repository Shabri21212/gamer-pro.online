<?php
declare(strict_types=1);

const HANDOVER_PHASES = [
    'open' => ['label' => 'Приём смены', 'verb' => 'Принять смену', 'done' => 'Принята', 'missing' => 'Не принята', 'icon' => 'clipboard-check'],
    'close' => ['label' => 'Сдача смены', 'verb' => 'Сдать смену', 'done' => 'Сдана', 'missing' => 'Не сдана', 'icon' => 'wallet'],
];

/** Принять смену можно заранее, сдать — с опозданием: админ часто заполняет кассу уже после пересменки. */
const HANDOVER_EARLY_MINUTES = 90;
const HANDOVER_LATE_MINUTES = 180;

/** Расхождение кассы: [текст, тон]. Тон: ok, over, short или '' — не посчитать. */
function cash_diff(?int $expected, ?int $actual): array
{
    if ($expected === null || $actual === null) {
        return ['—', ''];
    }
    $d = $actual - $expected;
    if ($d === 0) {
        return ['Сходится', 'ok'];
    }
    return $d > 0 ? ['+' . money_rub($d) . ' излишек', 'over'] : ['−' . money_rub(-$d) . ' недостача', 'short'];
}

function cash_diff_class(string $tone): string
{
    return $tone === 'short' ? 'text-primary-ink' : ($tone === '' ? 'text-muted-foreground' : 'text-foreground');
}

trait PagesHandover
{
    /** Смена сотрудника, по которой сейчас открыт чек-лист: идёт, скоро начнётся или недавно закончилась. */
    private static function handoverShift(array $user): ?array
    {
        $eid = $user['employeeId'] ?? null;
        if (!$eid) {
            return null;
        }
        $now = now_tyumen();
        $rows = Db::all(
            'SELECT s.id, s.date, s."branchId", b.name AS "branchName", b.slug AS "branchSlug", s."shiftType", s."employeeId"
             FROM shifts s JOIN branches b ON b.id = s."branchId"
             WHERE s."employeeId" = ? AND s.date >= ? AND s.date < ?
             ORDER BY s.date',
            [(int) $eid, $now->modify('-1 day')->format('Y-m-d'), $now->modify('+2 days')->format('Y-m-d')],
        );
        $best = null;
        $bestRank = PHP_INT_MAX;
        foreach ($rows as $r) {
            $r['date'] = substr((string) $r['date'], 0, 10);
            $st = shift_state($r['date'], (string) $r['shiftType'], $now);
            $rank = match ($st['state']) {
                'live' => 0,
                'upcoming' => $st['minutesToStart'] <= HANDOVER_EARLY_MINUTES ? 1 : null,
                default => ($now->getTimestamp() - $st['end']->getTimestamp()) <= HANDOVER_LATE_MINUTES * 60 ? 2 : null,
            };
            if ($rank !== null && $rank < $bestRank) {
                $r['state'] = $st;
                $best = $r;
                $bestRank = $rank;
            }
        }
        return $best;
    }

    /** Активные пункты чек-листа: ['open' => [...], 'close' => [...]]. */
    private static function checklistItems(): array
    {
        $out = ['open' => [], 'close' => []];
        foreach (Db::all('SELECT id, title, phase FROM shift_checklist_items WHERE "isActive" = 1 ORDER BY "sortOrder", id') as $it) {
            if (isset($out[$it['phase']])) {
                $out[$it['phase']][] = ['id' => (int) $it['id'], 'title' => (string) $it['title']];
            }
        }
        return $out;
    }

    /** Отчёты по смене филиала: phase => строка отчёта с отметками checks[itemId] => bool. */
    private static function handoverReports(int $branchId, string $date, string $type): array
    {
        $out = [];
        foreach (Db::all('SELECT * FROM shift_reports WHERE "branchId" = ? AND date = ? AND "shiftType" = ?', [$branchId, $date, $type]) as $r) {
            $r['checks'] = [];
            foreach (Db::all('SELECT "itemId", done FROM shift_report_checks WHERE "reportId" = ?', [(int) $r['id']]) as $c) {
                $r['checks'][(int) $c['itemId']] = boolish($c['done']);
            }
            $out[(string) $r['phase']] = $r;
        }
        return $out;
    }

    /** Последняя сдача смены на филиале перед этой — её заметку и кассу читает сменщик. */
    private static function previousHandover(int $branchId, string $date, string $type): ?array
    {
        return Db::one(
            'SELECT * FROM shift_reports
             WHERE "branchId" = ? AND phase = ? AND date >= ? AND date <= ? AND NOT (date = ? AND "shiftType" = ?)
             ORDER BY date DESC, "createdAt" DESC LIMIT 1',
            [$branchId, 'close', add_days($date, -3), $date, $date, $type],
        );
    }

    /** Какую фазу предложить на «Моём дне»: open, close или null, если всё заполнено или сдавать рано. */
    private static function handoverNextPhase(array $shift, array $reports): ?string
    {
        if (!isset($reports['open']) && $shift['state']['state'] !== 'done') {
            return 'open';
        }
        if (!isset($reports['close']) && ($shift['state']['state'] === 'done' || $shift['state']['minutesLeft'] <= 120)) {
            return 'close';
        }
        return null;
    }

    private static function pageHandover(array $user): void
    {
        $canHistory = can($user, 'journal.view');
        $html = page_head('Приём и сдача смены', 'Чек-лист в начале и в конце смены, касса и заметка сменщику. Отметки сохраняются — управляющий видит, кто и как сдал смену.');
        $shift = self::handoverShift($user);
        if ($shift) {
            $html .= self::handoverWorkspace($shift);
        } else {
            $text = $user['employeeId']
                ? 'Чек-лист открывается за полтора часа до начала смены и доступен ещё три часа после её конца. Если вышли за кого-то — попросите поставить смену в графике.'
                : 'Учётная запись не привязана к сотруднику — чек-листы заполняют администраторы на своих сменах.';
            $html .= empty_state('clipboard-check', 'Сейчас у вас нет смены', $text, '<a class="' . btn_class('outline') . '" href="' . h(url_to('/schedule')) . '">' . icon('calendar') . 'График смен</a>');
        }
        if ($canHistory) {
            $html .= self::handoverHistory($user);
        }
        self::layout($user, $html, ['title' => 'Приём и сдача']);
    }

    private static function handoverWorkspace(array $shift): string
    {
        $branchId = (int) $shift['branchId'];
        $date = (string) $shift['date'];
        $type = (string) $shift['shiftType'];
        $reports = self::handoverReports($branchId, $date, $type);
        $items = self::checklistItems();
        $prev = self::previousHandover($branchId, $date, $type);

        $aside = '<div class="flex min-w-0 flex-col gap-6 lg:sticky lg:top-9 lg:col-start-2 lg:row-start-1">';
        $aside .= self::handoverTicket($shift, $reports) . self::previousHandoverCard($prev) . '</div>';

        $forms = '<div class="flex min-w-0 flex-col gap-6 lg:col-start-1 lg:row-start-1">';
        foreach (array_keys(HANDOVER_PHASES) as $phase) {
            $forms .= self::handoverForm($shift, $phase, $items[$phase], $reports[$phase] ?? null);
        }
        $forms .= '</div>';

        return '<div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">' . $aside . $forms . '</div>';
    }

    private static function handoverTicket(array $shift, array $reports): string
    {
        $type = (string) $shift['shiftType'];
        $st = $shift['state'];
        $badge = match ($st['state']) {
            'live' => '<span class="inline-flex items-center gap-2 rounded-[5px] bg-primary px-2 py-0.5 text-xs leading-5 font-semibold text-primary-foreground"><span class="live-dot" aria-hidden="true"></span>Идёт сейчас</span>',
            'upcoming' => '<span class="rounded-[5px] bg-sidebar-accent px-2 py-0.5 text-xs leading-5 font-semibold">Через ' . h(duration_label($st['minutesToStart'])) . '</span>',
            default => '<span class="rounded-[5px] bg-sidebar-accent px-2 py-0.5 text-xs leading-5 font-semibold">Закончилась</span>',
        };
        $tone = $type === 'night' ? 'text-night-strong' : 'text-day-strong';
        $html = '<section class="cut flex flex-col gap-5 bg-sidebar p-5 text-sidebar-accent-foreground" style="--cut:20px" aria-labelledby="handover-shift">';
        $html .= '<div class="flex flex-wrap items-center justify-between gap-2"><p class="flex items-center gap-2 text-[11px] font-semibold tracking-[0.18em] text-sidebar-muted uppercase"><span class="cut-mark" aria-hidden="true"></span>Ваша смена</p>' . $badge . '</div>';
        $html .= '<div class="flex flex-col gap-1.5"><p id="handover-shift" class="font-display text-2xl leading-none font-semibold tracking-tight">' . h((string) $shift['branchName']) . '</p>';
        $html .= '<p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-sidebar-foreground">' . icon(SHIFT_ICONS[$type] ?? 'sun', 'size-4 ' . $tone);
        $html .= '<span class="font-semibold text-sidebar-accent-foreground">' . h(SHIFT_LABELS[$type] ?? $type) . '</span><span class="tabular-nums">' . $st['start']->format('H:i') . '–' . $st['end']->format('H:i') . '</span><span class="first-letter:uppercase">· ' . h(format_day_short((string) $shift['date'])) . '</span></p></div>';
        $html .= '<ol class="flex flex-col gap-3 border-t border-sidebar-border pt-4">';
        foreach (HANDOVER_PHASES as $phase => $meta) {
            $r = $reports[$phase] ?? null;
            $html .= '<li class="flex items-start gap-3">';
            $html .= $r
                ? '<span class="flex size-6 shrink-0 items-center justify-center rounded-[5px] bg-sidebar-accent-foreground text-sidebar">' . icon('check', 'size-4') . '</span>'
                : '<span class="size-6 shrink-0 rounded-[5px] border-2 border-sidebar-border" aria-hidden="true"></span>';
            $html .= '<div class="flex min-w-0 flex-col"><span class="text-sm font-semibold">' . h($meta['label']) . '</span>';
            $html .= '<span class="text-xs text-sidebar-muted">' . ($r ? h(short_name((string) $r['userName']) . ' · ' . format_dt((string) $r['createdAt'])) : h($meta['missing'])) . '</span></div></li>';
        }
        return $html . '</ol></section>';
    }

    private static function previousHandoverCard(?array $prev): string
    {
        if (!$prev) {
            $html = card_head('От прошлой смены', 'Предыдущая смена на этом филиале не оставила отчёта о сдаче.', '', 'inbox');
            return card($html, 'p-5');
        }
        $type = (string) $prev['shiftType'];
        $meta = h(short_name((string) $prev['userName'])) . ' · ' . h(mb_strtolower(SHIFT_LABELS[$type] ?? $type)) . ' · ' . h(format_dt((string) $prev['createdAt']));
        $html = card_head('От прошлой смены', $meta, '', 'inbox');
        $note = trim((string) ($prev['noteForNext'] ?? ''));
        $html .= $note !== ''
            ? '<p class="mt-4 rounded-md bg-muted/60 px-3 py-2.5 text-sm leading-relaxed whitespace-pre-line text-pretty break-words">' . h($note) . '</p>'
            : '<p class="mt-4 text-sm text-muted-foreground">Заметок сменщику не оставили.</p>';
        if ($prev['cashActual'] !== null && $prev['cashActual'] !== '') {
            [$diffText, $diffTone] = cash_diff($prev['cashExpected'] === null ? null : (int) $prev['cashExpected'], (int) $prev['cashActual']);
            $html .= '<dl class="mt-4 flex flex-col gap-1.5 border-t border-border pt-4 text-sm">';
            $html .= '<div class="flex items-baseline justify-between gap-3"><dt class="text-muted-foreground">Касса на конец</dt><dd class="font-display font-semibold tabular-nums">' . h(money_rub((int) $prev['cashActual'])) . '</dd></div>';
            if ($diffTone !== '') {
                $html .= '<div class="flex items-baseline justify-between gap-3"><dt class="text-muted-foreground">Сверка</dt><dd class="font-medium tabular-nums ' . cash_diff_class($diffTone) . '">' . h($diffText) . '</dd></div>';
            }
            $html .= '</dl>';
        }
        return card($html, 'p-5');
    }

    private static function cashField(string $name, string $label, string $hint, ?string $value, string $attr): string
    {
        $control = '<span class="relative block"><input name="' . h($name) . '" inputmode="numeric" autocomplete="off" maxlength="12" class="' . h(field_class('pr-9 tabular-nums')) . '" value="' . h((string) $value) . '" placeholder="0" ' . $attr . '>';
        $control .= '<span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-muted-foreground" aria-hidden="true">₽</span></span>';
        return ui_field($label, $control, $hint);
    }

    private static function handoverForm(array $shift, string $phase, array $items, ?array $report): string
    {
        $meta = HANDOVER_PHASES[$phase];
        $stash = form_stash_take('handover_' . $phase);
        $checked = [];
        if ($stash !== null) {
            $checked = array_map('intval', array_filter((array) ($stash['check'] ?? []), 'is_scalar'));
        } elseif ($report) {
            $checked = array_keys(array_filter($report['checks']));
        }
        $total = count($items);
        $doneNow = count(array_filter($items, static fn ($it) => in_array($it['id'], $checked, true)));

        $sub = $report
            ? 'Заполнил ' . h(short_name((string) $report['userName'])) . ' · ' . h(format_dt((string) $report['createdAt'])) . '. Можно поправить и сохранить ещё раз.'
            : h($phase === 'open' ? 'В начале смены пройдите по залу и отметьте, что проверили.' : 'В конце смены — касса, порядок в зале и заметка сменщику.');
        $status = $report ? chip($meta['done'], 'ink', 'check') : chip($meta['missing'], 'outline');

        $html = form_open('save_handover', 'class="flex flex-col gap-5" data-checklist data-submit-once');
        $html .= hidden('phase', $phase) . hidden('branchId', (int) $shift['branchId']) . hidden('date', (string) $shift['date']) . hidden('shiftType', (string) $shift['shiftType']);
        $html .= card_head($meta['label'], $sub, $status, $meta['icon']);

        if ($items) {
            $html .= '<fieldset class="flex flex-col gap-2"><legend class="sr-only">Чек-лист: ' . h(mb_strtolower($meta['label'])) . '</legend>';
            $html .= '<div class="flex items-center justify-between gap-3 pb-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase"><span>Чек-лист</span><span class="tabular-nums" data-check-count aria-live="polite">' . $doneNow . ' из ' . $total . '</span></div>';
            foreach ($items as $it) {
                $on = in_array($it['id'], $checked, true);
                $html .= '<label class="flex cursor-pointer items-start gap-3 rounded-md border border-border p-3 transition-colors hover:bg-muted/40 has-checked:border-foreground/25 has-checked:bg-muted/50">';
                $html .= '<input type="checkbox" name="check[]" value="' . $it['id'] . '" class="' . check_class('mt-0.5 size-5') . '" data-check' . ($on ? ' checked' : '') . '>';
                $html .= '<span class="text-sm leading-relaxed text-pretty">' . h($it['title']) . '</span></label>';
            }
            $html .= '</fieldset>';
        } else {
            $html .= '<p class="text-sm text-muted-foreground">Пункты чек-листа для этой фазы не заданы.</p>';
        }

        if ($phase === 'close') {
            $exp = $stash['cashExpected'] ?? ($report && $report['cashExpected'] !== null ? (string) $report['cashExpected'] : '');
            $act = $stash['cashActual'] ?? ($report && $report['cashActual'] !== null ? (string) $report['cashActual'] : '');
            $note = $stash['noteForNext'] ?? (string) ($report['noteForNext'] ?? '');
            $numExp = preg_match('/^\d+$/', (string) $exp) ? (int) $exp : null;
            $numAct = preg_match('/^\d+$/', (string) $act) ? (int) $act : null;
            [$diffText, $diffTone] = cash_diff($numExp, $numAct);
            $html .= '<div class="flex flex-col gap-3 border-t border-border pt-5" data-cash>';
            $html .= '<div class="grid gap-4 sm:grid-cols-2">';
            $html .= self::cashField('cashExpected', 'По отчёту LANGAME', 'Сколько должно быть в кассе', (string) $exp, 'data-cash-expected');
            $html .= self::cashField('cashActual', 'В кассе по факту', 'Пересчитанные наличные', (string) $act, 'data-cash-actual');
            $html .= '</div>';
            $html .= '<p class="flex items-center justify-between gap-3 rounded-md bg-muted/60 px-3 py-2.5 text-sm"><span class="text-muted-foreground">Расхождение</span><output class="font-display font-semibold tabular-nums ' . cash_diff_class($diffTone) . '" data-cash-diff aria-live="polite">' . h($diffText) . '</output></p>';
            $html .= '</div>';
            $html .= ui_field('Заметка следующей смене', '<textarea name="noteForNext" maxlength="2000" rows="3" class="' . h(textarea_class()) . '" placeholder="Что сломалось, что закончилось на складе, о чём предупредить">' . h((string) $note) . '</textarea>', 'Сменщик увидит её, когда будет принимать смену.');
        }

        $html .= '<div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">';
        $html .= '<p class="text-xs leading-relaxed text-muted-foreground">Неотмеченные пункты сохранятся как невыполненные.</p>';
        $html .= '<button class="' . btn_class($report ? 'outline' : 'primary') . ' sm:shrink-0">' . icon($report ? 'save' : 'check', 'size-4') . h($report ? 'Сохранить изменения' : $meta['verb']) . '</button></div>';
        return card($html . '</form>', 'p-5');
    }

    /* ---------- журнал для управляющих ---------- */

    private static function handoverHistory(array $user): string
    {
        $branch = self::pickBranch($user);
        if (!$branch) {
            return '';
        }
        $now = now_tyumen();
        $today = date_key($now);
        $from = add_days($today, -13);
        $bid = $branch['id'];

        $rows = [];
        $shifts = Db::all(
            'SELECT s.date, s."shiftType", e.name AS "employeeName" FROM shifts s JOIN employees e ON e.id = s."employeeId"
             WHERE s."branchId" = ? AND s.date >= ? AND s.date <= ? ORDER BY e."sortOrder", e.id',
            [$bid, $from, $today],
        );
        foreach ($shifts as $s) {
            $date = substr((string) $s['date'], 0, 10);
            $type = (string) $s['shiftType'];
            $k = $date . '|' . $type;
            $rows[$k] ??= ['date' => $date, 'type' => $type, 'names' => []];
            $rows[$k]['names'][] = short_name((string) $s['employeeName']);
        }
        $reports = Db::all('SELECT * FROM shift_reports WHERE "branchId" = ? AND date >= ? AND date <= ?', [$bid, $from, $today]);
        $missed = [];
        $totals = [];
        if ($reports) {
            $checks = Db::all(
                'SELECT c."reportId", c.done, i.title FROM shift_report_checks c
                 JOIN shift_reports r ON r.id = c."reportId"
                 LEFT JOIN shift_checklist_items i ON i.id = c."itemId"
                 WHERE r."branchId" = ? AND r.date >= ? AND r.date <= ?',
                [$bid, $from, $today],
            );
            foreach ($checks as $c) {
                $rid = (int) $c['reportId'];
                $totals[$rid] ??= [0, 0];
                $totals[$rid][1]++;
                if (boolish($c['done'])) {
                    $totals[$rid][0]++;
                } else {
                    $missed[$rid][] = (string) ($c['title'] ?? 'Пункт удалён');
                }
            }
        }
        foreach ($reports as $r) {
            $date = substr((string) $r['date'], 0, 10);
            $k = $date . '|' . $r['shiftType'];
            $rows[$k] ??= ['date' => $date, 'type' => (string) $r['shiftType'], 'names' => []];
            $rows[$k][(string) $r['phase']] = $r;
        }
        foreach ($rows as $k => $row) {
            $st = shift_state($row['date'], $row['type'], $now);
            if ($st['state'] === 'upcoming') {
                unset($rows[$k]);
                continue;
            }
            $rows[$k]['state'] = $st;
        }
        uasort($rows, static fn ($a, $b) => $b['state']['start'] <=> $a['state']['start']);

        $head = '<div class="flex flex-col gap-1"><h2 class="font-display text-lg leading-tight font-semibold">Журнал приёма и сдачи</h2><p class="text-sm text-muted-foreground">Последние две недели. Раздел виден тем, у кого есть право «Журнал действий».</p></div>';
        $html = '<section class="flex flex-col gap-4 border-t border-border pt-8" aria-label="Журнал приёма и сдачи смен">';
        $html .= '<div class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">' . $head . self::branchSwitch('/shift', $branch) . '</div>';
        if (!$rows) {
            return $html . empty_state('history', 'Смен за две недели нет', 'Когда администраторы начнут принимать и сдавать смены на филиале ' . $branch['name'] . ', отчёты появятся здесь.') . '</section>';
        }

        $html .= table_open(['Смена', 'Приём', 'Сдача', 'Касса', 'Заметка']);
        foreach ($rows as $row) {
            $type = $row['type'];
            $cell = '<div class="flex flex-col gap-1"><span class="font-semibold first-letter:uppercase">' . h(format_day_short($row['date'])) . '</span>';
            $cell .= '<span class="flex flex-wrap items-center gap-1.5">' . chip(SHIFT_LABELS[$type] ?? $type, $type === 'night' ? 'night' : ($type === 'full' ? 'outline' : 'day'), SHIFT_ICONS[$type] ?? 'sun');
            if ($row['names']) {
                $cell .= '<span class="text-xs text-muted-foreground">' . h(implode(', ', array_unique($row['names']))) . '</span>';
            }
            $cell .= '</span></div>';
            $html .= '<tr class="border-t border-border">' . td('Смена', $cell);
            foreach (HANDOVER_PHASES as $phase => $meta) {
                $html .= td($meta['label'], self::historyPhaseCell($row, $phase, $totals, $missed));
            }
            $close = $row['close'] ?? null;
            $cash = '<span class="text-muted-foreground">—</span>';
            if ($close && $close['cashActual'] !== null && $close['cashActual'] !== '') {
                [$diffText, $diffTone] = cash_diff($close['cashExpected'] === null ? null : (int) $close['cashExpected'], (int) $close['cashActual']);
                $cash = '<div class="flex flex-col gap-0.5"><span class="font-semibold tabular-nums">' . h(money_rub((int) $close['cashActual'])) . '</span>';
                if ($diffTone !== '') {
                    $cash .= '<span class="text-xs font-medium tabular-nums ' . cash_diff_class($diffTone) . '">' . h($diffText) . '</span>';
                }
                $cash .= '</div>';
            }
            $html .= td('Касса', $cash, 'whitespace-nowrap');
            $note = trim((string) ($close['noteForNext'] ?? ''));
            $html .= td('Заметка', $note !== ''
                ? '<p class="line-clamp-3 max-w-xs text-xs leading-relaxed whitespace-pre-line text-pretty break-words" title="' . h($note) . '">' . h($note) . '</p>'
                : '<span class="text-muted-foreground">—</span>');
            $html .= '</tr>';
        }
        return $html . table_close() . '</section>';
    }

    private static function historyPhaseCell(array $row, string $phase, array $totals, array $missed): string
    {
        $r = $row[$phase] ?? null;
        if (!$r) {
            if ($phase === 'close' && $row['state']['state'] === 'live') {
                return chip('Смена идёт', 'neutral', 'clock');
            }
            return chip(HANDOVER_PHASES[$phase]['missing'], 'red', 'alert');
        }
        $rid = (int) $r['id'];
        [$done, $all] = $totals[$rid] ?? [0, 0];
        $html = '<div class="flex flex-col gap-0.5"><span class="font-medium">' . h(short_name((string) $r['userName'])) . '</span>';
        $html .= '<span class="text-xs text-muted-foreground tabular-nums">' . h(format_dt((string) $r['createdAt'])) . ($all ? ' · ' . $done . '/' . $all : '') . '</span>';
        if (!empty($missed[$rid])) {
            $html .= '<span class="text-xs leading-relaxed text-primary-ink text-pretty">Не отмечено: ' . h(implode('; ', $missed[$rid])) . '</span>';
        }
        return $html . '</div>';
    }
}
