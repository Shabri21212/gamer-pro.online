<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('Asia/Yekaterinburg');
mb_internal_encoding('UTF-8');

const LOGIN_DOMAIN = 'shifts.local';
const THEME_BOOT = "(function(){try{var t=localStorage.getItem('gamerpro-theme');var d=t==='dark';var r=document.documentElement;r.classList.toggle('dark',d);r.classList.toggle('light',!d);r.style.colorScheme=d?'dark':'light'}catch(e){document.documentElement.classList.add('light')}})();";

$src = __DIR__;
require $src . '/Scrypt.php';
require $src . '/helpers.php';
require $src . '/portal.php';

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo) {
            return self::$pdo;
        }
        $path = dirname(__DIR__) . '/storage/gamerpro.sqlite';
        if (!is_file($path)) {
            http_response_code(500);
            exit('Нет базы storage/gamerpro.sqlite');
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS employee_fines (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                "employeeId" INTEGER NOT NULL,
                "employeeName" TEXT NOT NULL,
                reason TEXT NOT NULL,
                amount INTEGER NOT NULL,
                "createdByUserId" TEXT NOT NULL,
                "createdByName" TEXT NOT NULL,
                "createdAt" TEXT NOT NULL
            )');
        } catch (PDOException $e) {
            // превью без записи в sqlite — остальной портал не падает
        }
        try {
            $pdo->exec('CREATE TABLE IF NOT EXISTS staff_tasks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                "employeeId" INTEGER NOT NULL,
                date TEXT NOT NULL,
                title TEXT NOT NULL,
                description TEXT NOT NULL DEFAULT \'\',
                "createdByUserId" TEXT NOT NULL,
                "createdByName" TEXT NOT NULL,
                "createdAt" TEXT NOT NULL
            )');
            $pdo->exec('CREATE TABLE IF NOT EXISTS staff_task_done (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                "taskId" INTEGER NOT NULL,
                "userId" TEXT NOT NULL,
                "userName" TEXT NOT NULL,
                "completedAt" TEXT NOT NULL,
                UNIQUE("taskId", "userId")
            )');
        } catch (PDOException $e) {
            // превью без записи в sqlite
        }
        try {
            $pdo->exec('ALTER TABLE exams ADD COLUMN "xpReward" INTEGER NOT NULL DEFAULT 100');
        } catch (PDOException $e) {
            // колонка уже есть
        }
        self::$pdo = $pdo;
        return $pdo;
    }

    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function exec(string $sql, array $params = []): void
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
    }

    public static function lastId(): string
    {
        return self::pdo()->lastInsertId();
    }
}

function app_base(): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    // php -S отдаёт в SCRIPT_NAME путь запроса (/sign-in), а не index.php —
    // из этого нельзя выводить префикс, иначе cookie сессии пишется на /wiki/
    // и вход сразу отвечает «Сессия устарела».
    if (!str_ends_with($script, '/index.php') && basename($script) !== 'index.php') {
        return '';
    }
    $dir = rtrim(dirname($script), '/');
    return ($dir === '' || $dir === '/' || $dir === '.') ? '' : $dir;
}

function url_to(string $path, array $query = []): string
{
    $path = $path === '' ? '/' : $path;
    if ($path[0] !== '/') {
        $path = '/' . $path;
    }
    $base = app_base();
    $q = $query ? ('?' . http_build_query($query)) : '';
    return $base . $path . $q;
}

function asset(string $path): string
{
    return app_base() . '/assets/' . ltrim($path, '/');
}

function request_path(): string
{
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = app_base();
    if ($base !== '' && str_starts_with($uri, $base)) {
        $uri = substr($uri, strlen($base)) ?: '/';
    }
    if ($uri !== '/' && str_ends_with($uri, '/')) {
        $uri = rtrim($uri, '/');
    }
    return $uri === '' ? '/' : $uri;
}

function redirect_to(string $path, array $query = []): never
{
    header('Location: ' . url_to($path, $query), true, 302);
    exit;
}

function redirect_back(string $fallback = '/'): never
{
    $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    if ($ref !== '') {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $ok = $host !== '' && (str_contains($ref, '://' . $host) || str_starts_with($ref, '/'));
        if ($ok) {
            header('Location: ' . $ref, true, 302);
            exit;
        }
    }
    redirect_to($fallback);
}

function now_iso(): string
{
    return now_tyumen()->format('Y-m-d\TH:i:s.000P');
}

function new_id(): string
{
    return bin2hex(random_bytes(16));
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function flash_set(string $type, string $msg): void
{
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function flash_take(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function form_stash_set(string $key, array $data): void
{
    $_SESSION['form_stash'][$key] = $data;
}

function form_stash_take(string $key): ?array
{
    $data = $_SESSION['form_stash'][$key] ?? null;
    unset($_SESSION['form_stash'][$key]);
    return is_array($data) ? $data : null;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    $ok = hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['_csrf'] ?? ''));
    if ($ok) {
        return;
    }
    if (($_POST['_action'] ?? '') === 'login') {
        flash_set('error', 'Обновите страницу и войдите снова');
        redirect_to('/sign-in');
    }
    http_response_code(400);
    exit('Сессия устарела, обновите страницу');
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function start_session(): void
{
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_name('gamerpro');
    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
