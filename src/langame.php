<?php
declare(strict_types=1);

final class LangameError extends RuntimeException
{
}

/**
 * Клиент открытого API Langame и синхронизация продаж бара.
 * Токен хранится в базе только в зашифрованном виде (AES-256-GCM);
 * ключ шифрования лежит в storage/, который закрыт от браузера.
 */
final class Langame
{
    public const BASE_EXAMPLE = 'https://1433.langame.ru/public_api';
    private const SALES_PAGE = 500;
    private const MAX_PAGES = 40;
    public const AUTO_SYNC_SECONDS = 180;

    /* ---------- шифрование токена ---------- */

    private static function key(): string
    {
        static $key = null;
        if ($key !== null) {
            return $key;
        }
        $dir = dirname(__DIR__) . '/storage';
        $file = $dir . '/langame.key';
        if (is_file($file)) {
            $raw = base64_decode(trim((string) file_get_contents($file)), true);
            if ($raw !== false && strlen($raw) === 32) {
                return $key = $raw;
            }
        }
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            throw new LangameError('Не удалось создать папку storage для ключа шифрования');
        }
        $raw = random_bytes(32);
        if (@file_put_contents($file, base64_encode($raw), LOCK_EX) === false) {
            throw new LangameError('Не удалось сохранить ключ шифрования в storage/');
        }
        @chmod($file, 0600);
        return $key = $raw;
    }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new LangameError('Не удалось зашифровать токен');
        }
        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(?string $enc): ?string
    {
        if ($enc === null || !str_starts_with($enc, 'v1:')) {
            return null;
        }
        $raw = base64_decode(substr($enc, 3), true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? null : $plain;
    }

    /* ---------- настройки ---------- */

    public static function settings(int $branchId): ?array
    {
        try {
            $row = Db::one('SELECT * FROM langame_settings WHERE "branchId" = ?', [$branchId]);
        } catch (PDOException) {
            return null;
        }
        return $row ?: null;
    }

    /** Филиалы, где подключение включено и токен сохранён. @return int[] */
    public static function enabledBranchIds(): array
    {
        try {
            $rows = Db::all('SELECT "branchId" FROM langame_settings WHERE "isEnabled" = 1 AND "tokenEnc" IS NOT NULL');
        } catch (PDOException) {
            return [];
        }
        return array_map(static fn ($r) => (int) $r['branchId'], $rows);
    }

    public static function normalizeBase(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        if ($url !== '' && !preg_match('#/public_api$#', $url)) {
            $url .= '/public_api';
        }
        return $url;
    }

    /* ---------- HTTP ---------- */

    private static function get(array $s, string $path, array $query = []): array
    {
        $token = self::decrypt($s['tokenEnc'] ?? null);
        $base = (string) ($s['baseUrl'] ?? '');
        if ($token === null || $base === '') {
            throw new LangameError('Не указан адрес клуба или токен');
        }
        return self::request($base, $token, $path, $query);
    }

    public static function request(string $base, string $token, string $path, array $query = []): array
    {
        if (!function_exists('curl_init')) {
            throw new LangameError('На сервере не включено расширение PHP cURL');
        }
        $url = $base . $path . ($query ? '?' . http_build_query($query) : '');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['X-Request-Token: ' . $token, 'Accept: application/json'],
            CURLOPT_USERAGENT => 'GamerPro-Portal/1.0',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new LangameError('Langame не отвечает: ' . ($err !== '' ? $err : 'нет соединения'));
        }
        $json = json_decode((string) $body, true);
        if ($code === 401 || $code === 403) {
            throw new LangameError('Langame отклонил токен (код ' . $code . '). Проверьте, что ключ серверный и выдан для этого клуба.');
        }
        if ($code === 404) {
            throw new LangameError('Адрес не найден (404). Проверьте адрес клуба — он выглядит как ' . self::BASE_EXAMPLE);
        }
        if ($code >= 400 || !is_array($json)) {
            $detail = is_array($json) ? (string) ($json['detail'] ?? $json['title'] ?? '') : '';
            throw new LangameError('Ошибка Langame (код ' . $code . ')' . ($detail !== '' ? ': ' . $detail : ''));
        }
        return $json;
    }

    /** @return array{0: array, 1: int} данные страницы и число страниц */
    private static function page(array $s, string $path, array $query): array
    {
        $r = self::get($s, $path, $query);
        $data = isset($r['data']) && is_array($r['data']) ? $r['data'] : (array_is_list($r) ? $r : []);
        return [$data, max(1, (int) ($r['total_pages'] ?? 1))];
    }

    /** Проверка подключения: возвращает число администраторов в Langame. */
    public static function test(string $base, string $token): int
    {
        $r = self::request($base, $token, '/users/list', ['page' => 1, 'page_limit' => 1]);
        return isset($r['data']) && is_array($r['data']) ? count($r['data']) : 0;
    }

    /* ---------- синхронизация ---------- */

    /** Тихая фоновая синхронизация, если данные старше AUTO_SYNC_SECONDS. */
    public static function maybeSync(int $branchId): void
    {
        $s = self::settings($branchId);
        if (!$s || !boolish($s['isEnabled']) || empty($s['tokenEnc'])) {
            return;
        }
        $last = $s['lastSyncAt'] ? strtotime((string) $s['lastSyncAt']) : 0;
        if ($last && time() - $last < self::AUTO_SYNC_SECONDS) {
            return;
        }
        Db::exec('UPDATE langame_settings SET "lastSyncAt" = ? WHERE id = ?', [now_iso(), $s['id']]);
        try {
            self::sync($branchId);
        } catch (Throwable $e) {
            error_log('[gamerpro] langame auto-sync: ' . $e->getMessage());
        }
    }

    /** @return array{sales: int, shifts: int, users: int} */
    public static function sync(int $branchId): array
    {
        $s = self::settings($branchId);
        if (!$s) {
            throw new LangameError('Подключение не настроено');
        }
        try {
            $users = self::syncUsers($s);
            self::syncProducts($s);
            [$sales, $shiftIds, $from] = self::syncSales($s);
            $shifts = self::syncShifts($s, $shiftIds, $from);
            Db::exec('UPDATE langame_settings SET "lastSyncAt" = ?, "lastSyncOk" = 1, "lastError" = NULL, "syncedFrom" = COALESCE("syncedFrom", ?) WHERE id = ?', [now_iso(), $from, $s['id']]);
            return ['sales' => $sales, 'shifts' => $shifts, 'users' => $users];
        } catch (Throwable $e) {
            $msg = $e instanceof LangameError ? $e->getMessage() : 'Внутренняя ошибка синхронизации';
            if (!$e instanceof LangameError) {
                error_log('[gamerpro] langame sync: ' . $e->getMessage());
            }
            Db::exec('UPDATE langame_settings SET "lastSyncAt" = ?, "lastSyncOk" = 0, "lastError" = ? WHERE id = ?', [now_iso(), mb_substr($msg, 0, 500), $s['id']]);
            throw new LangameError($msg);
        }
    }

    private static function syncUsers(array $s): int
    {
        $bid = (int) $s['branchId'];
        $count = 0;
        for ($p = 1, $total = 1; $p <= $total && $p <= 10; $p++) {
            [$rows, $total] = self::page($s, '/users/list', ['page' => $p, 'page_limit' => 200]);
            foreach ($rows as $u) {
                $uid = (int) ($u['id'] ?? 0);
                if ($uid <= 0) {
                    continue;
                }
                $count++;
                $name = mb_substr(trim((string) ($u['username'] ?? '')), 0, 191);
                $login = mb_substr(trim((string) ($u['email'] ?? '')), 0, 191);
                $exists = Db::value('SELECT id FROM langame_staff WHERE "branchId" = ? AND "langameUserId" = ?', [$bid, $uid]);
                if ($exists) {
                    Db::exec('UPDATE langame_staff SET "langameName" = ?, "langameLogin" = ? WHERE id = ?', [$name, $login, $exists]);
                } else {
                    Db::insertIgnore('langame_staff', ['branchId' => $bid, 'langameUserId' => $uid, 'langameName' => $name, 'langameLogin' => $login, 'updatedAt' => now_iso()]);
                }
            }
        }
        return $count;
    }

    private static function syncProducts(array $s): void
    {
        $bid = (int) $s['branchId'];
        $known = [];
        foreach (Db::all('SELECT "extId", name FROM langame_products WHERE "branchId" = ?', [$bid]) as $r) {
            $known[(int) $r['extId']] = (string) $r['name'];
        }
        for ($p = 1, $total = 1; $p <= $total && $p <= 20; $p++) {
            [$rows, $total] = self::page($s, '/products/list', ['page' => $p, 'page_limit' => 500]);
            foreach ($rows as $g) {
                $id = (int) ($g['id'] ?? 0);
                $name = mb_substr(trim((string) ($g['name'] ?? '')), 0, 191);
                if ($id <= 0 || $name === '') {
                    continue;
                }
                if (!isset($known[$id])) {
                    Db::insertIgnore('langame_products', ['branchId' => $bid, 'extId' => $id, 'name' => $name]);
                } elseif ($known[$id] !== $name) {
                    Db::exec('UPDATE langame_products SET name = ? WHERE "branchId" = ? AND "extId" = ?', [$name, $bid, $id]);
                }
            }
        }
    }

    /** @return array{0: int, 1: int[], 2: string} число строк, id смен, дата начала окна */
    private static function syncSales(array $s): array
    {
        $bid = (int) $s['branchId'];
        $now = now_tyumen();
        $lastSale = Db::value('SELECT MAX("saleAt") FROM bar_sales WHERE "branchId" = ?', [$bid]);
        $from = $lastSale
            ? (new DateTimeImmutable(substr((string) $lastSale, 0, 10), new DateTimeZone(APP_TZ)))->modify('-2 days')->format('Y-m-d')
            : $now->modify('first day of last month')->format('Y-m-d');
        $to = $now->modify('+1 day')->format('Y-m-d');
        $shiftIds = [];
        $n = 0;
        for ($p = 1, $total = 1; $p <= $total && $p <= self::MAX_PAGES; $p++) {
            [$rows, $total] = self::page($s, '/products/expense', ['date_from' => $from, 'date_to' => $to, 'page' => $p, 'page_limit' => self::SALES_PAGE]);
            foreach ($rows as $r) {
                $ext = (int) ($r['id'] ?? 0);
                if ($ext <= 0) {
                    continue;
                }
                $qty = max(1, (int) ($r['count'] ?? 1));
                $unit = (int) round(((float) ($r['price_sale'] ?? 0)) * 100);
                $shift = (int) ($r['working_shift_id'] ?? 0);
                $row = [
                    'saleAt' => mb_substr((string) ($r['date'] ?? ''), 0, 32),
                    'goodsId' => (int) ($r['list_goods_id'] ?? 0),
                    'shiftExtId' => $shift,
                    'unitPriceX100' => $unit,
                    'qty' => $qty,
                    'amountX100' => $unit * $qty,
                    'isCancelled' => (int) ($r['cancel'] ?? 0) ? 1 : 0,
                    'isCashless' => (int) ($r['beznal'] ?? 0) ? 1 : 0,
                ];
                $id = Db::value('SELECT id FROM bar_sales WHERE "branchId" = ? AND "extId" = ?', [$bid, $ext]);
                if ($id) {
                    Db::exec('UPDATE bar_sales SET "saleAt" = ?, "goodsId" = ?, "shiftExtId" = ?, "unitPriceX100" = ?, qty = ?, "amountX100" = ?, "isCancelled" = ?, "isCashless" = ? WHERE id = ?', [...array_values($row), $id]);
                } else {
                    Db::insertIgnore('bar_sales', ['branchId' => $bid, 'extId' => $ext] + $row);
                }
                if ($shift > 0) {
                    $shiftIds[$shift] = true;
                }
                $n++;
            }
        }
        return [$n, array_keys($shiftIds), $from];
    }

    /** Подтягивает смены, чтобы понять, кто из администраторов продал позицию. */
    private static function syncShifts(array $s, array $needIds, string $from): int
    {
        $bid = (int) $s['branchId'];
        if ($needIds) {
            $place = implode(',', array_fill(0, count($needIds), '?'));
            $have = array_map('intval', array_column(Db::all('SELECT "extId" FROM langame_shifts WHERE "branchId" = ? AND "extId" IN (' . $place . ') AND "dateStop" IS NOT NULL', [$bid, ...$needIds]), 'extId'));
            $needIds = array_values(array_diff($needIds, $have));
        }
        $need = array_fill_keys($needIds, true);
        $cutoff = (new DateTimeImmutable($from, new DateTimeZone(APP_TZ)))->modify('-2 days')->format('Y-m-d');
        [$first, $total] = self::page($s, '/working_shifts/list', ['page' => 1, 'page_limit' => 200]);
        $asc = count($first) > 1 && strcmp((string) ($first[0]['date_start'] ?? ''), (string) ($first[count($first) - 1]['date_start'] ?? '')) < 0;
        $order = $asc ? range($total, 1) : range(1, $total);
        $saved = 0;
        foreach (array_slice($order, 0, 25) as $i => $p) {
            $rows = $p === 1 ? $first : self::page($s, '/working_shifts/list', ['page' => $p, 'page_limit' => 200])[0];
            $oldest = null;
            foreach ($rows as $w) {
                $ext = (int) ($w['id'] ?? 0);
                if ($ext <= 0) {
                    continue;
                }
                $start = mb_substr((string) ($w['date_start'] ?? ''), 0, 32);
                $stop = isset($w['date_stop']) && $w['date_stop'] !== null && $w['date_stop'] !== '' ? mb_substr((string) $w['date_stop'], 0, 32) : null;
                $uid = (int) ($w['user_id'] ?? 0);
                $oldest = $oldest === null || strcmp($start, $oldest) < 0 ? $start : $oldest;
                $id = Db::value('SELECT id FROM langame_shifts WHERE "branchId" = ? AND "extId" = ?', [$bid, $ext]);
                if ($id) {
                    Db::exec('UPDATE langame_shifts SET "langameUserId" = ?, "dateStart" = ?, "dateStop" = ? WHERE id = ?', [$uid, $start, $stop, $id]);
                } else {
                    Db::insertIgnore('langame_shifts', ['branchId' => $bid, 'extId' => $ext, 'langameUserId' => $uid, 'dateStart' => $start, 'dateStop' => $stop]);
                }
                unset($need[$ext]);
                $saved++;
            }
            if (!$need && ($oldest === null || strcmp(substr($oldest, 0, 10), $cutoff) < 0)) {
                break;
            }
        }
        return $saved;
    }

    /* ---------- отчёты ---------- */

    /**
     * Продажи бара по сотрудникам портала за период [from, to] (даты Y-m-d включительно).
     * @return array<int, array{revenue: int, qty: int, lines: int, percentX10: int, earn: int}> ключ — employeeId; суммы в копейках
     */
    public static function totalsByEmployee(string $from, string $to, ?int $branchId = null): array
    {
        $params = [$from, $to . ' 23:59:59'];
        $where = '';
        if ($branchId !== null) {
            $where = ' AND s."branchId" = ?';
            $params[] = $branchId;
        }
        try {
            $rows = Db::all(
                'SELECT l."employeeId" AS eid, l."percentX10" AS pct, st."barPercentX10" AS def,
                        SUM(s."amountX100") AS revenue, SUM(s.qty) AS qty, COUNT(*) AS cnt
                 FROM bar_sales s
                 JOIN langame_shifts w ON w."branchId" = s."branchId" AND w."extId" = s."shiftExtId"
                 JOIN langame_staff l ON l."branchId" = s."branchId" AND l."langameUserId" = w."langameUserId"
                 JOIN langame_settings st ON st."branchId" = s."branchId"
                 WHERE s."isCancelled" = 0 AND l."employeeId" IS NOT NULL AND s."saleAt" >= ? AND s."saleAt" <= ?' . $where . '
                 GROUP BY l."employeeId", l."percentX10", st."barPercentX10"',
                $params,
            );
        } catch (PDOException) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $eid = (int) $r['eid'];
            $pct = $r['pct'] !== null ? (int) $r['pct'] : (int) $r['def'];
            $rev = (int) $r['revenue'];
            $cur = $out[$eid] ?? ['revenue' => 0, 'qty' => 0, 'lines' => 0, 'percentX10' => $pct, 'earn' => 0];
            $cur['revenue'] += $rev;
            $cur['qty'] += (int) $r['qty'];
            $cur['lines'] += (int) $r['cnt'];
            $cur['earn'] += (int) round($rev * $pct / 1000);
            $cur['percentX10'] = $pct;
            $out[$eid] = $cur;
        }
        return $out;
    }

    /** Последние продажи сотрудника. */
    public static function recentSales(int $employeeId, string $from, string $to, int $limit = 20): array
    {
        try {
            return Db::all(
                'SELECT s."saleAt", s.qty, s."unitPriceX100", s."amountX100", s."isCancelled", s."isCashless", s."branchId", p.name AS "goodsName"
                 FROM bar_sales s
                 JOIN langame_shifts w ON w."branchId" = s."branchId" AND w."extId" = s."shiftExtId"
                 JOIN langame_staff l ON l."branchId" = s."branchId" AND l."langameUserId" = w."langameUserId"
                 LEFT JOIN langame_products p ON p."branchId" = s."branchId" AND p."extId" = s."goodsId"
                 WHERE l."employeeId" = ? AND s."saleAt" >= ? AND s."saleAt" <= ?
                 ORDER BY s."saleAt" DESC, s.id DESC LIMIT ' . max(1, min(100, $limit)),
                [$employeeId, $from, $to . ' 23:59:59'],
            );
        } catch (PDOException) {
            return [];
        }
    }

    /** Топ позиций сотрудника за период. */
    public static function topGoods(int $employeeId, string $from, string $to, int $limit = 5): array
    {
        try {
            return Db::all(
                'SELECT COALESCE(p.name, \'Товар\') AS name, SUM(s.qty) AS qty, SUM(s."amountX100") AS amount
                 FROM bar_sales s
                 JOIN langame_shifts w ON w."branchId" = s."branchId" AND w."extId" = s."shiftExtId"
                 JOIN langame_staff l ON l."branchId" = s."branchId" AND l."langameUserId" = w."langameUserId"
                 LEFT JOIN langame_products p ON p."branchId" = s."branchId" AND p."extId" = s."goodsId"
                 WHERE l."employeeId" = ? AND s."isCancelled" = 0 AND s."saleAt" >= ? AND s."saleAt" <= ?
                 GROUP BY p.name ORDER BY amount DESC LIMIT ' . max(1, min(20, $limit)),
                [$employeeId, $from, $to . ' 23:59:59'],
            );
        } catch (PDOException) {
            return [];
        }
    }

    /** Сумма продаж, которые не удалось привязать к сотруднику портала (копейки). */
    public static function unlinkedRevenue(int $branchId, string $from, string $to): int
    {
        try {
            return (int) Db::value(
                'SELECT COALESCE(SUM(s."amountX100"), 0) FROM bar_sales s
                 LEFT JOIN langame_shifts w ON w."branchId" = s."branchId" AND w."extId" = s."shiftExtId"
                 LEFT JOIN langame_staff l ON l."branchId" = s."branchId" AND l."langameUserId" = w."langameUserId"
                 WHERE s."branchId" = ? AND s."isCancelled" = 0 AND s."saleAt" >= ? AND s."saleAt" <= ? AND l."employeeId" IS NULL',
                [$branchId, $from, $to . ' 23:59:59'],
            );
        } catch (PDOException) {
            return 0;
        }
    }
}

function kop_rub(int $kop): string
{
    $rub = $kop / 100;
    return abs($rub - round($rub)) < 0.005 ? money_rub((int) round($rub)) : number_format($rub, 2, ',', "\u{202F}") . "\u{00A0}₽";
}

function pct_label(int $x10): string
{
    return rtrim(rtrim(number_format($x10 / 10, 1, ',', ''), '0'), ',') . '%';
}
