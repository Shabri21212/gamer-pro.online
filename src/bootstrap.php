<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('session.use_strict_mode', '1');
date_default_timezone_set('Asia/Yekaterinburg');
mb_internal_encoding('UTF-8');

const APP_TZ = 'Asia/Yekaterinburg';
const APP_NAME = 'GAMER PRO CRM';
const LOGIN_DOMAIN = 'shifts.local';
const THEME_BOOT = "(function(){try{var s=localStorage.getItem('gamerpro-theme');var d=s?s==='dark':window.matchMedia('(prefers-color-scheme: dark)').matches;var r=document.documentElement;r.classList.toggle('dark',d);r.classList.toggle('light',!d);r.style.colorScheme=d?'dark':'light'}catch(e){document.documentElement.classList.add('light')}})();";

$src = __DIR__;
require $src . '/Scrypt.php';
require $src . '/helpers.php';
require $src . '/access.php';
require $src . '/ui.php';
require $src . '/portal.php';

final class Db
{
    private static ?PDO $pdo = null;

    public static function path(): string
    {
        $env = getenv('GAMERPRO_DB');
        return is_string($env) && $env !== '' ? $env : dirname(__DIR__) . '/storage/gamerpro.sqlite';
    }

    public static function pdo(): PDO
    {
        if (self::$pdo) {
            return self::$pdo;
        }
        $path = self::path();
        if (!is_file($path)) {
            http_response_code(500);
            exit('Нет базы данных: storage/gamerpro.sqlite');
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        self::$pdo = $pdo;
        self::migrate($pdo);
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

    public static function value(string $sql, array $params = []): mixed
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
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

    /** Выполняет $fn в транзакции; при исключении откатывает и пробрасывает дальше. */
    public static function tx(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function hasColumn(PDO $pdo, string $table, string $column): bool
    {
        $rows = $pdo->query('PRAGMA table_info("' . str_replace('"', '', $table) . '")')->fetchAll();
        foreach ($rows as $r) {
            if (($r['name'] ?? '') === $column) {
                return true;
            }
        }
        return false;
    }

    private static function addColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        if (!self::hasColumn($pdo, $table, $column)) {
            $pdo->exec('ALTER TABLE "' . $table . '" ADD COLUMN "' . $column . '" ' . $definition);
        }
    }

    /**
     * Версия схемы хранится в PRAGMA user_version. Каждый шаг идемпотентен:
     * боевая база могла получить часть таблиц от прежней версии портала.
     */
    private static function migrate(PDO $pdo): void
    {
        try {
            $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
            $steps = [
                static function (PDO $pdo): void {
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
                    self::addColumn($pdo, 'exams', 'xpReward', 'INTEGER NOT NULL DEFAULT 100');
                },
                static function (PDO $pdo): void {
                    self::addColumn($pdo, 'user', 'permissions', 'TEXT');
                    self::addColumn($pdo, 'user', 'scopeBranchId', 'INTEGER');
                    self::addColumn($pdo, 'employee_fines', 'ruleId', 'INTEGER');
                    $pdo->exec('CREATE TABLE IF NOT EXISTS audit_log (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        "actorUserId" TEXT NOT NULL,
                        "actorName" TEXT NOT NULL,
                        "actorRole" TEXT NOT NULL,
                        entity TEXT NOT NULL,
                        action TEXT NOT NULL,
                        summary TEXT NOT NULL,
                        href TEXT NOT NULL DEFAULT \'/\',
                        "createdAt" TEXT NOT NULL
                    )');
                    $pdo->exec('CREATE TABLE IF NOT EXISTS login_attempts (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        ip TEXT NOT NULL,
                        login TEXT NOT NULL,
                        "createdAt" INTEGER NOT NULL
                    )');
                },
                static function (PDO $pdo): void {
                    $pdo->exec('CREATE INDEX IF NOT EXISTS audit_created_idx ON audit_log("createdAt")');
                    $pdo->exec('CREATE INDEX IF NOT EXISTS audit_entity_idx ON audit_log(entity, "createdAt")');
                    $pdo->exec('CREATE INDEX IF NOT EXISTS login_attempts_ip_idx ON login_attempts(ip, "createdAt")');
                    $pdo->exec('CREATE INDEX IF NOT EXISTS login_attempts_login_idx ON login_attempts(login, "createdAt")');
                    $pdo->exec('CREATE INDEX IF NOT EXISTS shifts_date_idx ON shifts(date)');
                    $pdo->exec('CREATE INDEX IF NOT EXISTS shifts_emp_date_idx ON shifts("employeeId", date)');
                    $pdo->exec('CREATE INDEX IF NOT EXISTS shifts_branch_date_idx ON shifts("branchId", date)');
                    $pdo->exec('CREATE INDEX IF NOT EXISTS staff_tasks_emp_date_idx ON staff_tasks("employeeId", date)');
                    $pdo->exec('CREATE INDEX IF NOT EXISTS review_bonuses_phone_idx ON review_bonuses("branchId", "guestPhone")');
                    $pdo->exec('CREATE INDEX IF NOT EXISTS announcement_reads_user_idx ON announcement_reads("userId", "announcementId")');
                    $pdo->exec('CREATE INDEX IF NOT EXISTS keyboard_cleanings_week_idx ON keyboard_cleanings("branchId", "weekStart")');
                },
            ];
            foreach ($steps as $i => $step) {
                $n = $i + 1;
                if ($n <= $version) {
                    continue;
                }
                $step($pdo);
                $pdo->exec('PRAGMA user_version = ' . $n);
            }
        } catch (PDOException $e) {
            error_log('[gamerpro] migration failed: ' . $e->getMessage());
        }
    }
}

function app_base(): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    // php -S кладёт в SCRIPT_NAME путь запроса (/sign-in), а не index.php —
    // из него нельзя выводить префикс, иначе cookie сессии уйдёт на чужой путь.
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
    $query = array_filter($query, static fn ($v) => $v !== null && $v !== '');
    $q = $query ? ('?' . http_build_query($query)) : '';
    return app_base() . $path . $q;
}

function asset(string $path): string
{
    $rel = ltrim($path, '/');
    $file = dirname(__DIR__) . '/assets/' . $rel;
    $v = is_file($file) ? '?v=' . filemtime($file) : '';
    return app_base() . '/assets/' . $rel . $v;
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
    header('Location: ' . url_to($path, $query), true, 303);
    exit;
}

function redirect_back(string $fallback = '/'): never
{
    $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($ref !== '' && $host !== '') {
        $refHost = parse_url($ref, PHP_URL_HOST);
        $refPort = parse_url($ref, PHP_URL_PORT);
        $refAuthority = $refHost . ($refPort ? ':' . $refPort : '');
        if ($refAuthority === $host || $refHost === $host) {
            header('Location: ' . $ref, true, 303);
            exit;
        }
    }
    redirect_to($fallback);
}

function now_tyumen(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone(APP_TZ));
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
    return is_array($f) ? $f : null;
}

function form_stash_set(string $key, array $data): void
{
    unset($data['_csrf'], $data['password']);
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
    flash_set('error', 'Страница устарела — действие не выполнено. Повторите ещё раз.');
    redirect_back('/');
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function start_session(): void
{
    $lifetime = 60 * 60 * 24 * 30;
    // Общий /tmp хостинга чистится чужим GC через ~24 минуты — из-за этого выкидывало из портала.
    $dir = dirname(__DIR__) . '/storage/sessions';
    if ((is_dir($dir) || @mkdir($dir, 0700, true)) && is_writable($dir)) {
        session_save_path($dir);
    }
    ini_set('session.gc_maxlifetime', (string) $lifetime);
    session_name('gamerpro');
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => app_base() === '' ? '/' : app_base() . '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Robots-Tag: noindex, nofollow');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    if (is_https()) {
        header('Strict-Transport-Security: max-age=63072000');
    }
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 64);
}
