<?php

declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
(new ReflectionProperty(Db::class, 'pdo'))->setValue(null, $pdo);
$pdo->exec('CREATE TABLE bar_sales (id INTEGER PRIMARY KEY, branchId INTEGER, saleAt TEXT, goodsId INTEGER, shiftExtId INTEGER, qty INTEGER, amountX100 INTEGER, unitPriceX100 INTEGER, isCancelled INTEGER, isCashless INTEGER)');
$pdo->exec('CREATE TABLE langame_shifts (branchId INTEGER, extId INTEGER, langameUserId INTEGER)');
$pdo->exec('CREATE TABLE langame_staff (branchId INTEGER, langameUserId INTEGER, employeeId INTEGER, langameName TEXT)');
$pdo->exec('CREATE TABLE langame_products (branchId INTEGER, extId INTEGER, name TEXT)');
$pdo->exec('CREATE TABLE employees (id INTEGER, name TEXT)');
$pdo->exec("INSERT INTO employees VALUES (1, 'Анна Тестовая')");
foreach ([1, 2] as $bid) {
    Db::exec('INSERT INTO langame_shifts VALUES (?, 10, 1)', [$bid]);
    Db::exec('INSERT INTO langame_staff VALUES (?, 1, 1, ?)', [$bid, 'Тест']);
    Db::exec('INSERT INTO langame_products VALUES (?, 1, ?)', [$bid, 'Кофе <test>']);
}
for ($i = 1; $i <= 28; $i++) {
    Db::exec('INSERT INTO bar_sales VALUES (?, ?, ?, 1, 10, 1, 10000, 10000, ?, 1)', [$i, $i === 28 ? 2 : 1, $i === 27 ? '2026-10-01 12:00:00' : '2026-11-10 12:00:00', $i === 26 ? 1 : 0]);
}
$checks = 0;
function check(bool $value, string $label): void
{
    global $checks;
    if (!$value) {
        throw new RuntimeException($label);
    }
    $checks++;
}
$all = Langame::salesLedger(1, '2026-11-01', '2026-11-30', 'all', '', 1);
check($all['total'] === 26 && count($all['rows']) === 25, 'Branch, date scope and page size');
$last = Langame::salesLedger(1, '2026-11-01', '2026-11-30', 'all', '', 999);
check($last['page'] === 2 && count($last['rows']) === 1, 'Clamp page to actual last page');
check($all['rows'][0]['isCancelled'] === 1, 'Stable latest-first order');
check(Langame::salesLedger(1, '2026-11-01', '2026-11-30', 'cancelled', '', 1)['total'] === 1, 'Cancellation filter');
check(Langame::salesLedger(1, '2026-11-01', '2026-11-30', 'completed', '', 1)['total'] === 25, 'Completed filter');
check(Langame::salesLedger(1, '2026-11-01', '2026-11-30', 'all', 'кофе', 1)['total'] === 26, 'Case-insensitive Cyrillic search');
check(Langame::salesLedger(1, '2026-11-01', '2026-11-30', 'all', 'анна', 1)['total'] === 26, 'Employee search');
foreach (['%', '_', "' OR 1=1 --"] as $search) {
    check(Langame::salesLedger(1, '2026-11-01', '2026-11-30', 'all', $search, 1)['total'] === 0, 'Literal safe search');
}
check(count(Langame::recentSales(1, '2026-11-01', '2026-11-30', 100, 2)) === 1, 'Employee detail branch scope');
check((int) Langame::topGoods(1, '2026-11-01', '2026-11-30', 5, 2)[0]['qty'] === 1, 'Employee product scope');
$month = ['key' => '2026-11', 'from' => '2026-11-01', 'to' => '2026-11-30'];
$branch = ['id' => 1, 'slug' => 'test', 'name' => 'Тест', 'pcCount' => 30];
$ledger = (new ReflectionMethod(Portal::class, 'barLedger'))->invoke(null, $branch, $month, ['branch' => 'test', 'm' => '2026-11']);
check(str_contains($ledger, '&lt;test&gt;') && !str_contains($ledger, '<test>'), 'Escaped API product names');
check(str_contains($ledger, 'Далее') && str_contains($ledger, 'Журнал продаж'), 'Ledger rendered');
$stock = (new ReflectionMethod(Portal::class, 'barGoods'))->invoke(null, $branch, $month, null);
check(str_contains($stock, 'Остатки не получены'), 'Unavailable stock is not zero');
$occupancy = new ReflectionMethod(Portal::class, 'barOccupancy');
check(str_contains($occupancy->invoke(null, $branch, null), 'Нет данных о сессиях'), 'Unavailable occupancy');
$live = ['at' => time(), 'active' => 12, 'sessionsToday' => 20, 'guestsToday' => 18, 'bookedToday' => 2, 'avgMinutes' => 70, 'hourly' => array_fill(0, 24, 3)];
check(str_contains($occupancy->invoke(null, $branch, $live), 'Загрузка 40%'), 'Occupancy uses configured capacity');
check(str_contains($occupancy->invoke(null, $branch, $live + ['sessionsPartial' => true]), 'только часть сессий'), 'Partial API response is explicit');
fwrite(STDOUT, $checks . " analytics checks passed; isolated in-memory database.\n");
