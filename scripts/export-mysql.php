<?php
/*
 * Собирает database/localhost.sql — полный дамп портала для MySQL/MariaDB (reg.ru, phpMyAdmin → «Импорт»).
 *
 *   php scripts/export-mysql.php                          из storage/gamerpro.sqlite
 *   php scripts/export-mysql.php путь/к/базе.sqlite out.sql
 *
 * То же самое владелец может скачать прямо из портала: «Управление» → «База данных».
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/src/schema.php';

$source = $argv[1] ?? $root . '/storage/gamerpro.sqlite';
$target = $argv[2] ?? $root . '/database/localhost.sql';

if (!is_file($source)) {
    fwrite(STDERR, "Нет файла базы: $source\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $source, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

if (!is_dir(dirname($target))) {
    mkdir(dirname($target), 0755, true);
}
$out = fopen($target, 'wb');
$counts = Schema::dumpMysql($pdo, 'sqlite', static function (string $chunk) use ($out): void {
    fwrite($out, $chunk);
}, basename($source));
fclose($out);

$total = array_sum($counts);
fwrite(STDOUT, sprintf("Готово: %s — %d таблиц, %d строк, %.1f КБ\n", $target, count($counts), $total, filesize($target) / 1024));
foreach ($counts as $table => $n) {
    if ($n > 0) {
        fwrite(STDOUT, sprintf("  %-28s %d\n", $table, $n));
    }
}
