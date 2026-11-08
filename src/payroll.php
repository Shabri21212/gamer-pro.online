<?php
declare(strict_types=1);

final class Payroll
{
    public static function key(string $month, int $employeeId): string
    {
        return 'payroll_' . $month . '_' . $employeeId;
    }

    public static function decimal(string $raw, int $max): ?int
    {
        $raw = str_replace([',', ' ', "\u{00A0}", "\u{202F}"], ['.', '', '', ''], trim($raw));
        if ($raw === '') return null;
        if (!preg_match('/^\d{1,9}(?:\.\d{1,2})?$/D', $raw)) {
            throw new InvalidArgumentException('Введите неотрицательное число, не более двух знаков после запятой.');
        }
        [$whole, $fraction] = array_pad(explode('.', $raw), 2, '');
        $value = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        if ($value > $max) throw new InvalidArgumentException('Значение превышает допустимый предел.');
        return $value;
    }

    public static function money(int $kopecks): string
    {
        return number_format($kopecks / 100, $kopecks % 100 === 0 ? 0 : 2, ',', ' ') . ' ₽';
    }

    public static function commission(int $sales, int $percent): int
    {
        return (int) round($sales * $percent / 10000, 0, PHP_ROUND_HALF_UP);
    }

    private static function hasSalesCache(): bool
    {
        $tables = ['bar_sales', 'langame_shifts', 'langame_staff', 'langame_settings'];
        $sql = Db::driver() === 'mysql'
            ? 'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN (?,?,?,?)'
            : "SELECT name FROM sqlite_master WHERE type = 'table' AND name IN (?,?,?,?)";
        return count(Db::all($sql, $tables)) === count($tables);
    }

    private static function sales(string $from, string $until): array
    {
        if (!self::hasSalesCache()) return ['available' => false, 'employees' => [], 'unassigned' => 0];
        $employees = [];
        $links = Db::all('SELECT st."employeeId", st."branchId", st."langameUserId", st."percentX10", cfg."barPercentX10", cfg."lastSyncAt", cfg."syncedFrom", cfg."lastSyncOk"
            FROM langame_staff st LEFT JOIN langame_settings cfg ON cfg."branchId" = st."branchId" WHERE st."employeeId" IS NOT NULL');
        foreach ($links as $link) {
            $id = (int) $link['employeeId'];
            $employees[$id] ??= ['sales' => 0, 'commission' => 0, 'rates' => [], 'syncedAt' => [], 'complete' => true];
            $rate = max(0, min(1000, (int) ($link['percentX10'] ?? $link['barPercentX10'] ?? 0))) * 10;
            $employees[$id]['rates'][$rate] = $rate;
            if ($link['percentX10'] === null && $link['barPercentX10'] === null) $employees[$id]['complete'] = false;
            $employees[$id]['syncedAt'][] = (string) ($link['lastSyncAt'] ?? '');
            $employees[$id]['complete'] = $employees[$id]['complete'] && boolish($link['lastSyncOk'])
                && !empty($link['syncedFrom']) && substr((string) $link['syncedFrom'], 0, 10) <= $from
                && !empty($link['lastSyncAt']) && substr((string) $link['lastSyncAt'], 0, 10) >= min(add_days($until, -1), date_key(now_tyumen()));
        }
        $sales = Db::all('SELECT st."employeeId", st."percentX10", cfg."barPercentX10", SUM(s."amountX100") AS amount
            FROM bar_sales s
            LEFT JOIN langame_shifts sh ON sh."branchId" = s."branchId" AND sh."extId" = s."shiftExtId"
            LEFT JOIN langame_staff st ON st."branchId" = sh."branchId" AND st."langameUserId" = sh."langameUserId"
            LEFT JOIN langame_settings cfg ON cfg."branchId" = s."branchId"
            WHERE s."saleAt" >= ? AND s."saleAt" < ? AND s."isCancelled" = 0
            GROUP BY st."employeeId", st."percentX10", cfg."barPercentX10"', [$from, $until]);
        $unassigned = 0;
        foreach ($sales as $sale) {
            $id = (int) $sale['employeeId'];
            if (!isset($employees[$id])) {
                $unassigned += (int) $sale['amount'];
                continue;
            }
            $amount = (int) $sale['amount'];
            $rate = max(0, min(1000, (int) ($sale['percentX10'] ?? $sale['barPercentX10'] ?? 0))) * 10;
            $employees[$id]['sales'] += $amount;
            $employees[$id]['commission'] += self::commission($amount, $rate);
        }
        return ['available' => true, 'employees' => $employees, 'unassigned' => $unassigned];
    }

    public static function report(string $from, string $to, ?DateTimeImmutable $now = null): array
    {
        $now ??= now_tyumen();
        $until = add_days($to, 1);
        $month = substr($from, 0, 7);
        $cached = self::sales($from, $until);
        $settings = [];
        foreach (Db::all('SELECT "key", "valueJson" FROM portal_content WHERE "key" LIKE ?', ['payroll_' . $month . '_%']) as $setting) {
            $settings[$setting['key']] = ['data' => json_decode((string) $setting['valueJson'], true) ?: [], 'revision' => hash('sha256', (string) $setting['valueJson'])];
        }
        $history = [];
        foreach (Db::all('SELECT "employeeId", "shiftRate", "changedAt" FROM employee_grade_history ORDER BY "changedAt", id') as $event) {
            $history[(int) $event['employeeId']][] = $event;
        }
        $rows = [];
        foreach (Db::all('SELECT e.*, g.title AS "gradeTitle", g."shiftRate", b.name AS "branchName" FROM employees e LEFT JOIN employee_grades g ON g.id = e."gradeId" LEFT JOIN branches b ON b.id = e."homeBranchId" ORDER BY b."sortOrder", e."sortOrder", e.name') as $employee) {
            $id = (int) $employee['id'];
            $saved = $settings[self::key($month, $id)] ?? ['data' => [], 'revision' => ''];
            $option = $saved['data'];
            $sales = $cached['employees'][$id] ?? null;
            $manual = isset($option['salesX100']);
            $percent = $option['percentX100'] ?? null;
            $amount = $manual ? (int) $option['salesX100'] : ($sales['sales'] ?? null);
            $commission = null;
            if ($amount !== null) {
                if ($percent !== null) $commission = self::commission($amount, (int) $percent);
                elseif (!$manual && $sales !== null) $commission = $sales['commission'];
                elseif ($sales !== null && count($sales['rates']) === 1) $commission = self::commission($amount, (int) array_values($sales['rates'])[0]);
            }
            $rates = $percent !== null ? [(int) $percent] : array_values($sales['rates'] ?? []);
            $rows[$id] = $employee + ['planned' => 0, 'completed' => 0, 'live' => 0, 'base' => 0, 'fineTotal' => 0, 'fines' => [], 'missingRate' => false,
                'sales' => $amount, 'commission' => $commission, 'rates' => $rates, 'manual' => $manual,
                'cacheComplete' => $sales['complete'] ?? false, 'syncedAt' => $sales ? min($sales['syncedAt']) : '',
                'options' => $option, 'revision' => $saved['revision']];
        }
        foreach (Db::all('SELECT "employeeId", date, "shiftType" FROM shifts WHERE date >= ? AND date < ?', [$from, $until]) as $shift) {
            $id = (int) $shift['employeeId'];
            if (!isset($rows[$id])) continue;
            $weight = shift_weight($shift['shiftType']);
            $rows[$id]['planned'] += $weight;
            $state = shift_state($shift['date'], $shift['shiftType'], $now);
            if ($state['state'] === 'live') $rows[$id]['live'] += $weight;
            if ($state['state'] !== 'done') continue;
            $rows[$id]['completed'] += $weight;
            $rate = $rows[$id]['shiftRate'];
            foreach ($history[$id] ?? [] as $event) {
                if (new DateTimeImmutable($event['changedAt']) <= $state['start']) $rate = (int) $event['shiftRate'];
            }
            if ($rate === null) $rows[$id]['missingRate'] = true;
            $rows[$id]['base'] += (int) $rate * $weight * 100;
        }
        foreach (Db::all('SELECT "employeeId", reason, amount, "createdAt" FROM employee_fines WHERE "createdAt" >= ? AND "createdAt" < ? ORDER BY "createdAt" DESC', [$from, $until]) as $fine) {
            $id = (int) $fine['employeeId'];
            if (!isset($rows[$id])) continue;
            $rows[$id]['fines'][] = $fine;
            $rows[$id]['fineTotal'] += (int) $fine['amount'] * 100;
        }
        foreach ($rows as $id => &$row) {
            if (!boolish($row['isActive']) && !$row['planned'] && !$row['fineTotal'] && !$row['sales']) {
                unset($rows[$id]);
                continue;
            }
            $row['net'] = $row['base'] + ($row['commission'] ?? 0) - $row['fineTotal'];
            $row['incomplete'] = $row['missingRate'] || $row['commission'] === null || (!$row['manual'] && !$row['cacheComplete']);
        }
        unset($row);
        return ['rows' => array_values($rows), 'cacheAvailable' => $cached['available'], 'unassigned' => $cached['unassigned']];
    }
}
