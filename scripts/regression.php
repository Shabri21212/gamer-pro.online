<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';

$file = tempnam(sys_get_temp_dir(), 'gamerpro-regression-');
putenv('GAMERPRO_DB=' . $file);
putenv('GAMERPRO_DB_DRIVER=sqlite');
$pdo = new PDO('sqlite:' . $file);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
Schema::migrate($pdo, 'sqlite');
$keep = in_array('--browser-fixture', $argv, true);
if (!$keep) register_shutdown_function(static fn () => is_file($file) ? unlink($file) : null);
$count = 0;
$check = static function (bool $ok, string $message) use (&$count): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $message);
    $count++;
    print 'PASS: ' . $message . PHP_EOL;
};

foreach ([['2026-11-08T02:00:00+05:00', 'live'], ['2026-11-08T07:59:59+05:00', 'live'], ['2026-11-08T08:00:00+05:00', 'done'], ['2026-11-07T19:59:59+05:00', 'upcoming'], ['2026-11-07T20:00:00+05:00', 'live'], ['2026-11-07T21:00:00Z', 'live']] as [$time, $state]) {
    $check(shift_state('2026-11-07', 'night', new DateTimeImmutable($time))['state'] === $state, 'night boundary ' . $time);
}
$check(shift_state('2026-10-31', 'night', new DateTimeImmutable('2026-11-01T02:00:00+05:00'))['state'] === 'live', 'night crosses month');
$check(shift_state('2026-12-31', 'night', new DateTimeImmutable('2027-01-01T02:00:00+05:00'))['state'] === 'live', 'night crosses year');
$check(shift_state('2026-11-07T00:00:00.000Z', 'full', new DateTimeImmutable('2026-11-08T02:00:00+05:00'))['state'] === 'live', 'legacy full-day timestamp');
$check(!is_date_key('2026-02-30') && is_date_key('2028-02-29'), 'date validation');
$check(Payroll::decimal('1 234,56', 99999999) === 123456, 'decimal input to kopecks');
$check(Payroll::decimal('0', 10000) === 0 && Payroll::decimal('', 10000) === null, 'zero is distinct from unset');
foreach (['-1', '100.01', '1e3', 'Infinity', 'abc', '1.234'] as $invalid) {
    $threw = false;
    try { Payroll::decimal($invalid, 10000); } catch (InvalidArgumentException) { $threw = true; }
    $check($threw, 'reject invalid percentage ' . $invalid);
}
$check(Payroll::commission(123456, 750) === 9259, 'commission rounded to kopeck');
$check(can(['isOwner' => true], 'owner') && !can(['isOwner' => false, 'perms' => ['staff.manage']], 'owner'), 'owner-only permissions');
Db::insert('branches', ['id' => 1, 'name' => 'Тестовый клуб', 'slug' => 'test']);
Db::insert('branches', ['id' => 2, 'name' => 'Второй клуб', 'slug' => 'second']);
Db::insert('employee_grades', ['id' => 1, 'title' => 'Администратор', 'shiftRate' => 2000]);
Db::insert('employees', ['id' => 1, 'name' => 'Тестов Алексей', 'homeBranchId' => 1, 'gradeId' => 1]);
Db::insert('employee_grade_history', ['employeeId' => 1, 'shiftRate' => 1500, 'changedAt' => '2026-11-01T00:00:00+05:00']);
Db::insert('employee_grade_history', ['employeeId' => 1, 'shiftRate' => 2000, 'changedAt' => '2026-11-05T00:00:00+05:00']);
foreach ([['2026-11-01', 'day'], ['2026-11-06', 'full'], ['2026-11-07T00:00:00.000Z', 'night'], ['2026-11-30T00:00:00.000Z', 'night']] as [$date, $type]) Db::insert('shifts', ['employeeId' => 1, 'branchId' => 1, 'date' => $date, 'shiftType' => $type]);
Db::insert('employee_fines', ['employeeId' => 1, 'reason' => 'Тестовый штраф', 'amount' => 300, 'createdAt' => '2026-11-04T12:00:00+05:00']);
$now = new DateTimeImmutable('2026-11-08T02:00:00+05:00');
$live = (new ReflectionMethod(Portal::class, 'liveShifts'))->invoke(null, 1, $now);
$check(count($live) === 1 && $live[0]['date'] === '2026-11-07', 'live query includes previous night in legacy storage');
$report = Payroll::report('2026-11-01', '2026-11-30', $now);
$row = $report['rows'][0];
$check($row['planned'] === 5 && $row['completed'] === 3 && $row['live'] === 1, 'completed vs future shifts, full-day weights');
$check($row['base'] === 550000 && $row['fineTotal'] === 30000, 'historical shift rates and fines');
$check($row['sales'] === null && $row['commission'] === null && $row['incomplete'], 'missing cache is not zero sales');
Db::insert('portal_content', ['key' => Payroll::key('2026-11', 1), 'valueJson' => json_encode(['percentX100' => 750, 'salesX100' => 1000000])]);
$row = Payroll::report('2026-11-01', '2026-11-30', $now)['rows'][0];
$check($row['commission'] === 75000 && $row['net'] === 595000 && !$row['incomplete'], 'manual sales replace cache, net calculation');

$pdo->exec('CREATE TABLE langame_settings (branchId INTEGER, barPercentX10 INTEGER, lastSyncAt TEXT, syncedFrom TEXT, lastSyncOk INTEGER)');
$pdo->exec('CREATE TABLE langame_staff (branchId INTEGER, langameUserId INTEGER, employeeId INTEGER, percentX10 INTEGER)');
$pdo->exec('CREATE TABLE langame_shifts (branchId INTEGER, extId INTEGER, langameUserId INTEGER)');
$pdo->exec('CREATE TABLE bar_sales (branchId INTEGER, shiftExtId INTEGER, amountX100 INTEGER, saleAt TEXT, isCancelled INTEGER)');
$pdo->exec("INSERT INTO langame_settings VALUES (1,50,'2026-12-01T00:00:00+05:00','2026-11-01',1),(2,100,'2026-12-01T00:00:00+05:00','2026-11-01',1)");
$pdo->exec('INSERT INTO langame_staff VALUES (1,10,1,NULL),(2,20,1,75)');
$pdo->exec('INSERT INTO langame_shifts VALUES (1,1,10),(2,1,20)');
$pdo->exec("INSERT INTO bar_sales VALUES (1,1,100000,'2026-11-07 21:00:00',0),(2,1,200000,'2026-11-08 02:00:00',0),(1,1,900000,'2026-11-08 03:00:00',1),(1,99,10000,'2026-11-08 04:00:00',0),(1,1,990000,'2026-12-01 00:00:00',0)");
Db::exec('DELETE FROM portal_content WHERE "key" = ?', [Payroll::key('2026-11', 1)]);
$report = Payroll::report('2026-11-01', '2026-11-30', $now);
$row = $report['rows'][0];
$check($row['sales'] === 300000 && $row['commission'] === 20000, 'cached commission, branch-scoped joins, cancellation exclusion');
$check($report['unassigned'] === 10000, 'unmapped sales surfaced');
$check(count($row['rates']) === 2, 'per-branch percentages preserved');
$pdo->exec("UPDATE langame_settings SET syncedFrom = '2026-11-05'");
$check(Payroll::report('2026-11-01', '2026-11-30', $now)['rows'][0]['incomplete'], 'incomplete sync coverage flagged');

if ($keep) {
    foreach (['owner', 'manager'] as $role) {
        Db::insert('user', ['id' => 'test-' . $role, 'name' => 'Тест ' . $role, 'email' => 'test-' . $role . '@shifts.local', 'role' => $role]);
        Db::insert('account', ['id' => 'test-' . $role, 'userId' => 'test-' . $role, 'providerId' => 'credential', 'password_php' => password_hash('Only-Test-2026!', PASSWORD_DEFAULT)]);
    }
    foreach (['Бронза' => 5, 'Серебро' => 7, 'Золото' => 10] as $title => $percent) Db::insert('loyalty_tiers', ['title' => $title, 'cashbackPercent' => $percent, 'minHours' => ($percent - 5) * 50, 'birthdayBonus' => $percent * 100]);
    foreach ([2 => 'Крылова Анна', 3 => 'Лебедев Максим', 4 => 'Миронов Иван'] as $id => $name) Db::insert('employees', ['id' => $id, 'name' => $name, 'homeBranchId' => $id === 4 ? 2 : 1, 'gradeId' => 1]);
    $month = date_key(now_tyumen()->modify('first day of this month'));
    foreach (dates_between($month, now_tyumen()->format('Y-m-t')) as $index => $date) {
        if (Db::value('SELECT COUNT(*) FROM shifts WHERE SUBSTR(date,1,10) = ?', [$date])) continue;
        Db::insert('shifts', ['employeeId' => $index % 2 === 0 ? 1 : 2, 'branchId' => 1, 'date' => $date, 'shiftType' => 'day']);
        Db::insert('shifts', ['employeeId' => 3, 'branchId' => 1, 'date' => $date, 'shiftType' => 'night']);
    }
    print 'Browser fixture: ' . $file . PHP_EOL;
}
print $count . ' checks passed.' . PHP_EOL;
