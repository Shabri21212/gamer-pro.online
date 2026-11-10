<?php
declare(strict_types=1);

final class Payroll
{
    /** Расчёт по началу смены в Тюмени, не подтверждение фактической выплаты. */
    public static function employee(int $employeeId, string $month, ?DateTimeImmutable $now = null): ?array
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new InvalidArgumentException('Неверный месяц');
        }
        $now = ($now ?? now_tyumen())->setTimezone(new DateTimeZone(APP_TZ));
        $first = new DateTimeImmutable($month . '-01 00:00:00', new DateTimeZone(APP_TZ));
        $end = $first->modify('first day of next month');
        $employee = Db::one('SELECT e.*, g.title AS "gradeTitle", g."shiftRate" FROM employees e LEFT JOIN employee_grades g ON g.id = e."gradeId" WHERE e.id = ?', [$employeeId]);
        if (!$employee) {
            return null;
        }
        $periods = [];
        foreach ([1 => ['01', '15'], 2 => ['16', $first->format('t')]] as $key => [$from, $to]) {
            $periods[$key] = ['from' => $month . '-' . $from, 'to' => $month . '-' . $to, 'units' => 0, 'planned' => 0, 'gross' => 0, 'future' => 0, 'bar' => 0, 'barRev' => 0, 'barPct' => 0, 'fines' => 0, 'total' => 0, 'shifts' => [], 'deductions' => []];
        }
        $history = Db::all('SELECT "shiftRate", "changedAt" FROM employee_grade_history WHERE "employeeId" = ? ORDER BY "changedAt", id', [$employeeId]);
        usort($history, static fn ($a, $b) => (new DateTimeImmutable((string) $a['changedAt'])) <=> (new DateTimeImmutable((string) $b['changedAt'])));
        $shifts = Db::all('SELECT s.id, s.date, s."shiftType", s."branchId", b.name AS "branchName" FROM shifts s JOIN branches b ON b.id = s."branchId" WHERE s."employeeId" = ? AND s.date >= ? AND s.date < ? ORDER BY s.date, s."shiftType"', [$employeeId, $first->format('Y-m-d'), $end->format('Y-m-d')]);
        $branchIds = [(int) $employee['homeBranchId']];
        foreach ($shifts as $shift) {
            $date = substr((string) $shift['date'], 0, 10);
            $state = shift_state($date, (string) $shift['shiftType'], $now);
            $period = (int) substr($date, 8, 2) <= 15 ? 1 : 2;
            $units = shift_weight((string) $shift['shiftType']);
            $started = $state['start'] <= $now;
            $rate = (int) ($employee['shiftRate'] ?? 0);
            if ($started && $history) {
                // Самая ранняя известная ставка — резерв для импортированных смен до начала истории.
                $rate = (int) $history[0]['shiftRate'];
                foreach ($history as $change) {
                    if (new DateTimeImmutable((string) $change['changedAt']) <= $state['start']) {
                        $rate = (int) $change['shiftRate'];
                    }
                }
            }
            $amount = $rate * $units;
            $periods[$period]['planned'] += $units;
            $periods[$period][$started ? 'gross' : 'future'] += $amount;
            if ($started) {
                $periods[$period]['units'] += $units;
            }
            $periods[$period]['shifts'][] = $shift + ['started' => $started, 'rate' => $rate, 'amount' => $amount, 'state' => $state['state']];
            $branchIds[] = (int) $shift['branchId'];
        }
        $fines = Db::all('SELECT id, reason, amount, "createdAt" FROM employee_fines WHERE "employeeId" = ? AND "createdAt" >= ? AND "createdAt" < ? ORDER BY "createdAt" DESC', [$employeeId, $first->modify('-1 day')->format('Y-m-d'), $end->modify('+1 day')->format('Y-m-d')]);
        foreach ($fines as $fine) {
            $at = (new DateTimeImmutable((string) $fine['createdAt']))->setTimezone(new DateTimeZone(APP_TZ));
            if ($at > $now || $at < $first || $at >= $end) {
                continue;
            }
            $period = (int) $at->format('j') <= 15 ? 1 : 2;
            $periods[$period]['fines'] += (int) $fine['amount'];
            $periods[$period]['deductions'][] = $fine;
        }
        $commissionStart = new DateTimeImmutable($month . '-16 00:00:00', new DateTimeZone(APP_TZ));
        $cutoff = min($now, $end->modify('-1 second'));
        if ($cutoff >= $commissionStart) {
            $sales = Db::all('SELECT l."percentX10" AS pct, st."barPercentX10" AS def, SUM(s."amountX100") AS revenue
                FROM bar_sales s
                JOIN langame_shifts w ON w."branchId" = s."branchId" AND w."extId" = s."shiftExtId"
                JOIN langame_staff l ON l."branchId" = s."branchId" AND l."langameUserId" = w."langameUserId"
                JOIN langame_settings st ON st."branchId" = s."branchId"
                WHERE l."employeeId" = ? AND s."isCancelled" = 0 AND s."saleAt" >= ? AND s."saleAt" <= ?
                GROUP BY l."percentX10", st."barPercentX10"', [$employeeId, $commissionStart->format('Y-m-d H:i:s'), $cutoff->format('Y-m-d H:i:s')]);
            $earned = $revenue = 0;
            foreach ($sales as $sale) {
                $pct = (int) ($sale['pct'] ?? $sale['def']);
                $revenue += (int) $sale['revenue'];
                $earned += (int) round((int) $sale['revenue'] * $pct / 1000);
            }
            $periods[2]['bar'] = (int) round($earned / 100);
            $periods[2]['barRev'] = (int) round($revenue / 100);
            $periods[2]['barPct'] = $revenue > 0 ? (int) round($earned / $revenue * 1000) : 0;
        }
        $warnings = [];
        foreach (array_unique(array_filter($branchIds)) as $bid) {
            $settings = Db::one('SELECT b.name, s."isEnabled", s."lastSyncOk", s."lastSyncAt", s."syncedFrom" FROM branches b LEFT JOIN langame_settings s ON s."branchId" = b.id WHERE b.id = ?', [$bid]);
            $linked = Db::value('SELECT 1 FROM langame_staff WHERE "employeeId" = ? AND "branchId" = ?', [$employeeId, $bid]);
            if (!$settings || !$settings['isEnabled'] || !$linked || !$settings['lastSyncOk'] || empty($settings['syncedFrom']) || substr((string) $settings['syncedFrom'], 0, 10) > $commissionStart->format('Y-m-d')) {
                $warnings[] = ($settings['name'] ?? 'Филиал') . ': продажи или привязка Langame неполные';
            } elseif (empty($settings['lastSyncAt']) || $now->getTimestamp() - (new DateTimeImmutable((string) $settings['lastSyncAt']))->getTimestamp() > 900) {
                $warnings[] = $settings['name'] . ': продажи требуют обновления';
            }
        }
        foreach ($periods as &$period) {
            $period['total'] = $period['gross'] + $period['bar'] - $period['fines'];
        }
        unset($period);
        return ['employee' => $employee, 'periods' => $periods, 'warnings' => $warnings, 'at' => $now];
    }
}
