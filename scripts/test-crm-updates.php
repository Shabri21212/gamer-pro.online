<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
(new ReflectionProperty(Db::class, 'pdo'))->setValue(null, $pdo);
foreach (array_keys(SCHEMA) as $table) {
    $pdo->exec(Schema::sqliteCreate($table));
}
$checks = 0;
function check(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $checks++;
}
Db::insert('branches', ['id' => 1, 'name' => 'Гондатти', 'slug' => 'gondatti', 'pcCount' => 40]);
Db::insert('branches', ['id' => 2, 'name' => 'Киевская', 'slug' => 'kievskaya', 'pcCount' => 27]);
$tariffs = DEFAULT_TARIFFS;
array_pop($tariffs['branches']);
$tariffs['branches'][0]['packages'][0]['prices'][0] = 999;
Db::insert('portal_content', ['key' => 'tariffs', 'valueJson' => json_encode($tariffs)]);
Db::insert('app_meta', ['k' => 'schema', 'v' => '8']);
Schema::migrate($pdo, 'sqlite');
BranchUpgrade::run($pdo);
check((int) Db::value('SELECT COUNT(*) FROM branches') === 3, 'Idempotent third branch');
check(Schema::storedVersion($pdo) === 9, 'Migration version');
$tariffs = json_decode(Db::value('SELECT "valueJson" FROM portal_content WHERE "key" = ?', ['tariffs']), true);
check(count($tariffs['branches']) === 3 && $tariffs['branches'][0]['packages'][0]['prices'][0] === 999, 'Preserve existing prices');
check($tariffs['branches'][2]['packages'][2]['prices'] === [600, 720, 900, 1200], 'Gazovikov prices');
check(Db::value('SELECT "baseUrl" FROM langame_settings WHERE "branchId" = 3') === 'https://643.langame.ru/public_api', 'Gazovikov API URL');
check((int) Db::value('SELECT COUNT(*) FROM knowledge_articles WHERE slug = ?', ['gazovikov-zony']) === 1, 'One hardware article');
check(str_contains((string) Db::value('SELECT "contentHtml" FROM knowledge_articles WHERE slug = ?', ['gazovikov-zony']), '380 Hz'), 'Hardware specifications');

$employee = ['isOwner' => false, 'role' => 'admin', 'homeBranchId' => 1, 'employeeId' => 1, 'perms' => [], 'scopeBranchId' => null];
check(can_view_schedule($employee, 1) && !can_view_schedule($employee, 2), 'Own branch only');
check(!can_view_schedule(array_replace($employee, ['homeBranchId' => null]), 1), 'Fail closed without branch');
check(can_view_schedule(array_replace($employee, ['perms' => ['schedule.view_all']]), 3), 'Grant all branches');
check(!can(array_replace($employee, ['perms' => ['schedule.edit']]), 'schedule.edit', 2), 'Edit does not bypass visibility');
check(can(array_replace($employee, ['perms' => ['schedule.edit']]), 'schedule.edit', 1), 'Home branch editor');
check(!can(array_replace($employee, ['perms' => ['schedule.view_all']]), 'schedule.edit', 1), 'Read permission cannot edit');
check(!can(array_replace($employee, ['perms' => ['schedule.edit', 'schedule.view_all'], 'scopeBranchId' => 1]), 'schedule.edit', 2), 'Editing scope preserved');

Db::insert('employee_grades', ['id' => 1, 'title' => 'Администратор', 'shiftRate' => 2200]);
Db::insert('employees', ['id' => 1, 'name' => 'Иванов Александр Константинович', 'homeBranchId' => 1, 'gradeId' => 1]);
Db::insert('employees', ['id' => 2, 'name' => 'Другой сотрудник', 'homeBranchId' => 2, 'gradeId' => 1]);
Db::insert('employee_grade_history', ['employeeId' => 1, 'shiftRate' => 1800, 'changedAt' => '2026-10-01T00:00:00+05:00']);
Db::insert('employee_grade_history', ['employeeId' => 1, 'shiftRate' => 2200, 'changedAt' => '2026-11-16T00:00:00+05:00']);
foreach ([['2026-11-01', 'day'], ['2026-11-15', 'night'], ['2026-11-16', 'full'], ['2026-11-30', 'night']] as [$date, $type]) {
    Db::insert('shifts', ['employeeId' => 1, 'branchId' => 1, 'date' => $date, 'shiftType' => $type]);
}
Db::insert('shifts', ['employeeId' => 2, 'branchId' => 2, 'date' => '2026-11-01', 'shiftType' => 'day']);
Db::insert('employee_fines', ['employeeId' => 1, 'reason' => 'Первый период', 'amount' => 100, 'createdAt' => '2026-11-10T12:00:00+05:00']);
Db::insert('employee_fines', ['employeeId' => 1, 'reason' => 'Второй период', 'amount' => 200, 'createdAt' => '2026-11-16T12:00:00+05:00']);
Db::insert('employee_fines', ['employeeId' => 2, 'reason' => 'Чужой штраф', 'amount' => 9000, 'createdAt' => '2026-11-10T12:00:00+05:00']);
Db::insert('langame_settings', ['branchId' => 1, 'barPercentX10' => 100, 'isEnabled' => 1, 'lastSyncOk' => 1, 'lastSyncAt' => '2026-11-16T12:00:00+05:00', 'syncedFrom' => '2026-11-01']);
Db::insert('langame_shifts', ['branchId' => 1, 'extId' => 10, 'langameUserId' => 1]);
Db::insert('langame_staff', ['branchId' => 1, 'langameUserId' => 1, 'employeeId' => 1, 'percentX10' => 50]);
foreach ([['2026-11-15 23:59:59', 0], ['2026-11-16 00:00:00', 0], ['2026-11-16 10:00:00', 1], ['2026-11-17 10:00:00', 0]] as $i => [$at, $cancelled]) {
    Db::insert('bar_sales', ['branchId' => 1, 'extId' => $i + 1, 'shiftExtId' => 10, 'saleAt' => $at, 'amountX100' => 100000, 'isCancelled' => $cancelled]);
}
$calc = static fn (string $at): array => Payroll::employee(1, '2026-11', new DateTimeImmutable($at . '+05:00'));
$before = $calc('2026-11-01T07:59:59');
check($before['periods'][1]['gross'] === 0 && $before['periods'][1]['planned'] === 2, 'Future shifts not earned');
$start = $calc('2026-11-01T08:00:00');
check($start['periods'][1]['gross'] === 1800 && $start['periods'][1]['units'] === 1, 'Accrue at exact day start using historical rate');
check($calc('2026-11-15T19:59:59')['periods'][1]['gross'] === 1800, 'Night not started at 19:59');
check($calc('2026-11-15T20:00:00')['periods'][1]['gross'] === 3600, 'Accrue at exact night start');
$midnight = $calc('2026-11-16T00:00:00');
check($midnight['periods'][1]['gross'] === 3600 && $midnight['periods'][2]['gross'] === 0, 'Overnight stays in first pay period');
check($midnight['periods'][1]['bar'] === 0 && $midnight['periods'][2]['bar'] === 50, 'Commission from second half only, personal percent');
$full = $calc('2026-11-16T12:00:00');
check($full['periods'][2]['gross'] === 4400 && $full['periods'][2]['units'] === 2, 'Full day is two units at updated rate');
check($full['periods'][2]['bar'] === 50, 'Cancelled and future sales excluded');
check($full['periods'][1]['fines'] === 100 && $full['periods'][2]['fines'] === 200, 'Fine period and employee isolation');
check($full['periods'][1]['total'] === 3500 && $full['periods'][2]['total'] === 4250, 'Net calculation');
check(count($full['periods'][1]['shifts']) === 2 && count($full['periods'][2]['shifts']) === 2, 'Other employee shifts not exposed');
check(Payroll::employee(1, '2028-02', new DateTimeImmutable('2028-03-01+05:00'))['periods'][2]['to'] === '2028-02-29', 'Leap year boundary');
check($calc('2026-11-30T20:00:00')['periods'][2]['gross'] === 6600, 'Last night belongs to month of start');
check($full['warnings'] === [], 'Complete sales do not raise warning');
Db::exec('UPDATE langame_settings SET "lastSyncOk" = 0 WHERE "branchId" = 1');
check(count($calc('2026-11-16T12:00:00')['warnings']) === 1, 'Failed sync explicitly marked incomplete');
$chip = shift_chip(['employeeName' => 'Иванов <Александр> Константинович', 'shiftType' => 'day']);
check(str_contains($chip, 'Иванов &lt;Александр&gt; Константинович') && str_contains($chip, 'cal-chip-label'), 'Full name escaped with shift label');
$fineCard = (new ReflectionMethod(Portal::class, 'salaryFinesCard'))->invoke(null, [['reason' => 'Штраф <проверка>', 'amount' => 200, 'createdAt' => '2026-11-16T12:00:00+05:00']], 200);
check(str_contains($fineCard, 'Назначенные штрафы') && str_contains($fineCard, 'Штраф &lt;проверка&gt;'), 'Fine card with escaped reason');
Db::insert('employee_fines', ['employeeId' => 1, 'reason' => 'На границе месяца UTC', 'amount' => 50, 'createdAt' => '2026-10-31T20:30:00Z']);
check($calc('2026-11-01T08:00:00')['periods'][1]['fines'] === 50, 'Fine month determined in Tyumen timezone');
fwrite(STDOUT, $checks . " CRM checks passed; isolated in-memory database.\n");
