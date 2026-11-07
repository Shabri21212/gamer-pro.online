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
require $src . '/schema.php';
require $src . '/helpers.php';
require $src . '/access.php';
require $src . '/ui.php';
require $src . '/langame.php';
require $src . '/portal.php';

/** Настройки из config.php в корне сайта (на хостинге). Нет файла — пустой массив. */
function app_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $file = dirname(__DIR__) . '/config.php';
        $loaded = is_file($file) ? require $file : [];
        $cfg = is_array($loaded) ? $loaded : [];
    }
    return $cfg;
}

/**
 * Доступ к базе. Драйвер выбирается так:
 *  - config.php с 'db' => ['driver' => 'mysql', ...] — MySQL на хостинге;
 *  - переменная окружения GAMERPRO_DB_DRIVER=mysql — MySQL для проверки локально;
 *  - иначе SQLite: storage/gamerpro.sqlite (или путь из GAMERPRO_DB).
 * SQL портала пишется с идентификаторами в двойных кавычках — в MySQL для этого
 * включается ANSI_QUOTES, поэтому один и тот же запрос работает в обеих базах.
 */
final class Db
{
    private static ?PDO $pdo = null;
    private static string $driver = 'sqlite';

    public static function path(): string
    {
        $env = getenv('GAMERPRO_DB');
        return is_string($env) && $env !== '' ? $env : dirname(__DIR__) . '/storage/gamerpro.sqlite';
    }

    /** @return array{driver: string, host?: string, port?: int, name?: string, user?: string, pass?: string, socket?: string} */
    public static function config(): array
    {
        if (getenv('GAMERPRO_DB_DRIVER') === 'mysql') {
            return [
                'driver' => 'mysql',
                'host' => (string) (getenv('GAMERPRO_DB_HOST') ?: '127.0.0.1'),
                'port' => (int) (getenv('GAMERPRO_DB_PORT') ?: 3306),
                'name' => (string) getenv('GAMERPRO_DB_NAME'),
                'user' => (string) getenv('GAMERPRO_DB_USER'),
                'pass' => (string) getenv('GAMERPRO_DB_PASS'),
                'socket' => (string) (getenv('GAMERPRO_DB_SOCKET') ?: ''),
            ];
        }
        $db = app_config()['db'] ?? [];
        if (is_array($db) && ($db['driver'] ?? '') === 'mysql') {
            return $db + ['host' => 'localhost', 'port' => 3306, 'name' => '', 'user' => '', 'pass' => '', 'socket' => ''];
        }
        return ['driver' => 'sqlite'];
    }

    public static function driver(): string
    {
        self::pdo();
        return self::$driver;
    }

    public static function pdo(): PDO
    {
        if (self::$pdo) {
            return self::$pdo;
        }
        $cfg = self::config();
        if ($cfg['driver'] === 'mysql') {
            self::$pdo = self::connectMysql($cfg);
            self::$driver = 'mysql';
        } else {
            self::$pdo = self::connectSqlite();
            self::$driver = 'sqlite';
        }
        Schema::migrate(self::$pdo, self::$driver);
        return self::$pdo;
    }

    private static function connectSqlite(): PDO
    {
        $path = self::path();
        if (!is_file($path)) {
            self::fail('Не найдена база данных storage/gamerpro.sqlite. Загрузите папку storage на хостинг или укажите MySQL в config.php.');
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        return $pdo;
    }

    private static function connectMysql(array $cfg): PDO
    {
        if (!extension_loaded('pdo_mysql')) {
            self::fail('На хостинге не включено расширение PHP pdo_mysql. Включите его в панели reg.ru (Настройки PHP → Расширения).');
        }
        $dsn = !empty($cfg['socket'])
            ? 'mysql:unix_socket=' . $cfg['socket'] . ';dbname=' . $cfg['name'] . ';charset=utf8mb4'
            : 'mysql:host=' . $cfg['host'] . ';port=' . (int) $cfg['port'] . ';dbname=' . $cfg['name'] . ';charset=utf8mb4';
        try {
            $pdo = new PDO($dsn, (string) $cfg['user'], (string) $cfg['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
        } catch (PDOException $e) {
            error_log('[gamerpro] mysql connect: ' . $e->getMessage());
            self::fail('Не удалось подключиться к базе MySQL «' . $cfg['name'] . '». Проверьте имя базы, пользователя и пароль в config.php.');
        }
        // Нестрогий режим, как у SQLite: слишком длинная строка обрезается, а не роняет страницу.
        $pdo->exec("SET SESSION sql_mode = 'ANSI_QUOTES,PIPES_AS_CONCAT,NO_ENGINE_SUBSTITUTION'");
        $pdo->exec("SET time_zone = '+05:00'");
        return $pdo;
    }

    private static function fail(string $message): never
    {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Нет связи с базой · ' . APP_NAME . '</title></head>';
        echo '<body style="font-family:system-ui,sans-serif;max-width:36rem;margin:12vh auto;padding:0 1.25rem;line-height:1.55;color:#16181d">';
        echo '<h1 style="font-size:1.35rem">Портал временно недоступен</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
        exit;
    }

    private static function quoteIdent(string $name): string
    {
        return '"' . str_replace('"', '', $name) . '"';
    }

    /** INSERT строки, возвращает id новой записи. */
    public static function insert(string $table, array $row): int
    {
        $cols = array_map([self::class, 'quoteIdent'], array_keys($row));
        self::exec(
            'INSERT INTO ' . self::quoteIdent($table) . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')',
            array_values($row),
        );
        return (int) self::lastId();
    }

    /** INSERT, который молча пропускает строку, если она нарушает уникальный индекс. */
    public static function insertIgnore(string $table, array $row): void
    {
        $verb = self::driver() === 'mysql' ? 'INSERT IGNORE INTO ' : 'INSERT OR IGNORE INTO ';
        $cols = array_map([self::class, 'quoteIdent'], array_keys($row));
        self::exec(
            $verb . self::quoteIdent($table) . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')',
            array_values($row),
        );
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
