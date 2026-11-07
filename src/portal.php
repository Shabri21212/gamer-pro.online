<?php
declare(strict_types=1);

foreach (['shifts', 'club', 'guests', 'rules', 'exams', 'manage'] as $part) {
    require __DIR__ . '/pages/' . $part . '.php';
    require __DIR__ . '/actions/' . $part . '.php';
}

final class Portal
{
    use PagesShifts, PagesClub, PagesGuests, PagesRules, PagesExams, PagesManage;
    use ActionsShifts, ActionsClub, ActionsGuests, ActionsRules, ActionsExams, ActionsManage;

    /** path => [метод, право] */
    private const ROUTES = [
        '/' => ['pageHome', null],
        '/schedule' => ['pageSchedule', null],
        '/cleaning' => ['pageCleaning', null],
        '/issues' => ['pageIssues', null],
        '/news' => ['pageNews', null],
        '/loyalty' => ['pageLoyalty', null],
        '/loyalty/edit' => ['pageLoyaltyEdit', 'content.edit'],
        '/reviews' => ['pageReviews', null],
        '/reviews/edit' => ['pageReviewsEdit', 'content.edit'],
        '/compensations' => ['pageCompensations', null],
        '/tariffs' => ['pageTariffs', null],
        '/tariffs/edit' => ['pageTariffsEdit', 'content.edit'],
        '/fines' => ['pageFines', null],
        '/fines/assign' => ['pageFinesAssign', 'fines.manage'],
        '/fines/edit' => ['pageFinesEdit', 'fines.manage'],
        '/wiki/new' => ['pageWikiNew', 'wiki.edit'],
        '/exams' => ['pageExams', null],
        '/account' => ['pageAccount', null],
        '/manage/journal' => ['pageJournal', 'journal.view'],
        '/manage/employees' => ['pageEmployees', 'staff.manage'],
        '/manage/grades' => ['pageGrades', 'staff.manage'],
        '/manage/tasks' => ['pageTasks', 'tasks.assign'],
        '/manage/accounts' => ['pageAccounts', 'owner'],
        '/manage/exams' => ['pageManageExams', 'exams.manage'],
        '/manage/database' => ['pageDatabase', 'owner'],
    ];

    private const ACTIONS = [
        'complete_task' => 'doCompleteTask',
        'uncomplete_task' => 'doUncompleteTask',
        'save_shift' => 'doSaveShift',
        'delete_shift' => 'doDeleteShift',
        'copy_week' => 'doCopyWeek',
        'toggle_clean' => 'doToggleClean',
        'assign_seats' => 'doAssignSeats',
        'assign_staff_task' => 'doAssignStaffTask',
        'delete_staff_task' => 'doDeleteStaffTask',
        'save_incident' => 'doSaveIncident',
        'incident_status' => 'doIncidentStatus',
        'save_news' => 'doSaveNews',
        'read_news' => 'doReadNews',
        'pin_news' => 'doPinNews',
        'delete_news' => 'doDeleteNews',
        'save_review' => 'doSaveReview',
        'decide_review' => 'doDecideReview',
        'save_comp' => 'doSaveComp',
        'decide_comp' => 'doDecideComp',
        'save_loyalty' => 'doSaveLoyalty',
        'save_promo' => 'doSavePromo',
        'save_wiki' => 'doSaveWiki',
        'create_wiki' => 'doCreateWiki',
        'delete_wiki' => 'doDeleteWiki',
        'save_tariffs' => 'doSaveTariffs',
        'save_fine_rule' => 'doSaveFineRule',
        'delete_fine_rule' => 'doDeleteFineRule',
        'assign_fine' => 'doAssignFine',
        'cancel_fine' => 'doCancelFine',
        'create_exam' => 'doCreateExam',
        'save_exam' => 'doSaveExam',
        'delete_exam' => 'doDeleteExam',
        'start_attempt' => 'doStartAttempt',
        'submit_attempt' => 'doSubmitAttempt',
        'save_employee' => 'doSaveEmployee',
        'update_employee' => 'doUpdateEmployee',
        'toggle_employee' => 'doToggleEmployee',
        'delete_employee' => 'doDeleteEmployee',
        'assign_grade' => 'doAssignGrade',
        'save_grade' => 'doSaveGrade',
        'delete_grade' => 'doDeleteGrade',
        'save_account' => 'doSaveAccount',
        'update_access' => 'doUpdateAccess',
        'reset_password' => 'doResetPassword',
        'delete_account' => 'doDeleteAccount',
        'change_password' => 'doChangePassword',
        'download_dump' => 'doDownloadDump',
    ];

    private static ?array $countsCache = null;
    private static ?array $branchesCache = null;

    public static function run(): void
    {
        send_security_headers();
        start_session();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = request_path();
        if ($method === 'POST') {
            $action = (string) ($_POST['_action'] ?? '');
            if ($path === '/sign-in' && $action === 'login') {
                self::doLogin();
                return;
            }
            csrf_check();
            if ($action === 'logout') {
                self::logout();
            }
            $user = self::user();
            if (!$user) {
                redirect_to('/sign-in');
            }
            $handler = self::ACTIONS[$action] ?? null;
            if ($handler === null) {
                redirect_to($path);
            }
            self::$handler($user);
            return;
        }
        self::get($path);
    }

    private static function get(string $path): void
    {
        if ($path === '/sign-in') {
            self::pageSignIn();
            return;
        }
        if ($path === '/sign-out') {
            self::logout();
        }
        $user = self::user();
        if (!$user) {
            redirect_to('/sign-in');
        }
        if (isset(self::ROUTES[$path])) {
            [$page, $perm] = self::ROUTES[$path];
            if ($perm !== null) {
                self::requirePerm($user, $perm);
            }
            self::$page($user);
            return;
        }
        if ($path === '/wiki') {
            $slug = self::firstArticleSlug($user);
            if ($slug) {
                redirect_to('/wiki/' . rawurlencode($slug));
            }
            self::pageWikiEmpty($user);
            return;
        }
        if (preg_match('#^/wiki/([^/]+)/edit$#', $path, $m)) {
            self::requirePerm($user, 'wiki.edit');
            self::pageWikiEdit($user, rawurldecode($m[1]));
            return;
        }
        if (preg_match('#^/wiki/([^/]+)$#', $path, $m)) {
            $slug = rawurldecode($m[1]);
            if ($slug === 'fines') {
                redirect_to('/fines');
            }
            self::pageWiki($user, $slug);
            return;
        }
        if (preg_match('#^/exams/([a-z0-9-]+)/edit$#', $path, $m)) {
            self::requirePerm($user, 'exams.manage');
            self::pageExamEdit($user, $m[1]);
            return;
        }
        if (preg_match('#^/exams/([a-z0-9-]+)/attempt/(\d+)$#', $path, $m)) {
            self::pageExamAttempt($user, $m[1], (int) $m[2]);
            return;
        }
        if (preg_match('#^/exams/([a-z0-9-]+)/result/(\d+)$#', $path, $m)) {
            self::pageExamResult($user, $m[1], (int) $m[2]);
            return;
        }
        if (preg_match('#^/exams/([a-z0-9-]+)$#', $path, $m)) {
            self::pageExamStart($user, $m[1]);
            return;
        }
        self::notFound($user);
    }

    private static function notFound(array $user, string $text = 'Такой страницы нет — возможно, ссылка устарела.'): void
    {
        http_response_code(404);
        self::layout($user, '<div class="py-10">' . empty_state('search', 'Страница не найдена', $text, '<a class="' . btn_class('outline') . '" href="' . h(url_to('/')) . '">' . icon('home') . 'На главную</a>') . '</div>', ['title' => 'Не найдено', 'width' => 'narrow']);
    }

    private static function logout(): never
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        redirect_to('/sign-in');
    }

    private static function user(): ?array
    {
        static $cached = false;
        if ($cached !== false) {
            return $cached;
        }
        $id = $_SESSION['uid'] ?? null;
        if (!$id) {
            return $cached = null;
        }
        try {
            $u = Db::one('SELECT id, name, email, role, permissions, "scopeBranchId" FROM "user" WHERE id = ?', [$id]);
        } catch (PDOException) {
            $u = Db::one('SELECT id, name, email, role FROM "user" WHERE id = ?', [$id]);
        }
        if (!$u) {
            return $cached = null;
        }
        $emp = Db::one('SELECT id, name, "homeBranchId", "gradeId" FROM employees WHERE "userId" = ?', [$u['id']]);
        $role = normalize_role($u['role'] ?? 'admin');
        $email = (string) $u['email'];
        $login = str_ends_with($email, '@' . LOGIN_DOMAIN) ? substr($email, 0, -strlen('@' . LOGIN_DOMAIN)) : $email;
        $scope = isset($u['scopeBranchId']) && $u['scopeBranchId'] !== null && $u['scopeBranchId'] !== '' ? (int) $u['scopeBranchId'] : null;
        return $cached = [
            'id' => (string) $u['id'],
            'name' => (string) $u['name'],
            'login' => $login,
            'role' => $role,
            'roleLabel' => role_label($role),
            'isOwner' => $role === 'owner',
            'perms' => perms_from_row($u),
            'scopeBranchId' => $role === 'owner' ? null : $scope,
            'employeeId' => $emp ? (int) $emp['id'] : null,
            'employeeName' => $emp['name'] ?? null,
            'homeBranchId' => $emp ? (int) $emp['homeBranchId'] : null,
            'gradeId' => $emp && $emp['gradeId'] !== null ? (int) $emp['gradeId'] : null,
        ];
    }

    private static function displayName(array $user): string
    {
        return (string) ($user['employeeName'] ?? $user['name']);
    }

    private static function requirePerm(array $user, string $perm, ?int $branchId = null): void
    {
        if (can($user, $perm, $branchId)) {
            return;
        }
        http_response_code(403);
        $text = $perm === 'owner'
            ? 'Раздел доступен только владельцу.'
            : 'Нужно право «' . (PERMISSIONS[$perm]['label'] ?? $perm) . '». Попросите владельца выдать его в разделе «Доступы и права».';
        self::layout($user, '<div class="py-10">' . empty_state('lock', 'Нет доступа', $text, '<a class="' . btn_class('outline') . '" href="' . h(url_to('/')) . '">' . icon('home') . 'На главную</a>') . '</div>', ['title' => 'Нет доступа', 'width' => 'narrow']);
        exit;
    }

    /** Для POST: без права — сообщение и возврат на страницу. */
    private static function permOrFail(array $user, string $perm, ?int $branchId = null, string $back = '/'): void
    {
        if (can($user, $perm, $branchId)) {
            return;
        }
        $label = PERMISSIONS[$perm]['label'] ?? 'это действие';
        $scope = perm_scope($user, $perm);
        $msg = $scope !== null && $branchId !== null && $branchId !== $scope
            ? 'Ваше право «' . $label . '» действует только для филиала ' . self::branchName($scope)
            : 'Недостаточно прав: «' . $label . '»';
        flash_set('error', $msg);
        redirect_back($back);
    }

    private static function postInt(string $key): int
    {
        return (int) ($_POST[$key] ?? 0);
    }

    private static function postStr(string $key, int $max = 2000): string
    {
        $v = $_POST[$key] ?? '';
        return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
    }

    private static function getStr(string $key, int $max = 120): string
    {
        $v = $_GET[$key] ?? '';
        return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
    }

    private static function doLogin(): void
    {
        $login = strtolower(trim((string) ($_POST['login'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $_SESSION['last_login'] = mb_substr($login, 0, 64);
        if ($login === '' || $password === '') {
            flash_set('error', 'Введите логин и пароль');
            redirect_to('/sign-in');
        }
        $ip = client_ip();
        $now = time();
        try {
            if (random_int(1, 20) === 1) {
                Db::exec('DELETE FROM login_attempts WHERE "createdAt" < ?', [$now - 86400]);
            }
            $byIp = (int) Db::value('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND "createdAt" > ?', [$ip, $now - 900]);
            $byLogin = (int) Db::value('SELECT COUNT(*) FROM login_attempts WHERE login = ? AND "createdAt" > ?', [$login, $now - 900]);
        } catch (PDOException) {
            $byIp = $byLogin = 0;
        }
        if ($byIp >= 20 || $byLogin >= 8) {
            flash_set('error', 'Слишком много неудачных попыток. Подождите 15 минут или попросите владельца сбросить пароль.');
            redirect_to('/sign-in');
        }
        $u = Db::one('SELECT id, role FROM "user" WHERE email = ?', [$login . '@' . LOGIN_DOMAIN]);
        $acc = $u ? Db::one('SELECT id, password, password_php FROM account WHERE "userId" = ? AND "providerId" = ?', [$u['id'], 'credential']) : null;
        $ok = false;
        if ($acc) {
            if (!empty($acc['password_php']) && password_verify($password, (string) $acc['password_php'])) {
                $ok = true;
                if (password_needs_rehash((string) $acc['password_php'], PASSWORD_DEFAULT)) {
                    Db::exec('UPDATE account SET password_php = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $acc['id']]);
                }
            } elseif (!empty($acc['password'])) {
                @ini_set('memory_limit', '256M');
                $ok = Scrypt::verifyBetterAuth((string) $acc['password'], $password);
                if ($ok) {
                    Db::exec('UPDATE account SET password_php = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $acc['id']]);
                }
            }
        }
        if (!$ok) {
            try {
                Db::exec('INSERT INTO login_attempts (ip, login, "createdAt") VALUES (?,?,?)', [$ip, $login, $now]);
            } catch (PDOException) {
            }
            flash_set('error', 'Неверный логин или пароль');
            redirect_to('/sign-in');
        }
        try {
            Db::exec('DELETE FROM login_attempts WHERE login = ?', [$login]);
        } catch (PDOException) {
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = $u['id'];
        unset($_SESSION['last_login']);
        redirect_to('/');
    }

    /* ---------- общие данные ---------- */

    private static function counts(array $user): array
    {
        if (self::$countsCache !== null) {
            return self::$countsCache;
        }
        $news = (int) Db::value(
            'SELECT COUNT(*) FROM announcements a
             WHERE a."isDraft" = 0 AND NOT EXISTS (
               SELECT 1 FROM announcement_reads r WHERE r."announcementId" = a.id AND r."userId" = ?
             )',
            [$user['id']],
        );
        $issues = (int) Db::value('SELECT COUNT(*) FROM incidents WHERE status != ?', ['done']);
        $reviews = 0;
        $comps = 0;
        if (can($user, 'guests.decide')) {
            $scope = perm_scope($user, 'guests.decide');
            $where = $scope !== null ? ' AND "branchId" = ' . (int) $scope : '';
            $reviews = (int) Db::value('SELECT COUNT(*) FROM review_bonuses WHERE status = ?' . $where, ['pending']);
            $comps = (int) Db::value('SELECT COUNT(*) FROM compensations WHERE status = ?' . $where, ['pending']);
        }
        return self::$countsCache = ['news' => $news, 'issues' => $issues, 'reviews' => $reviews, 'compensations' => $comps];
    }

    private static function branches(): array
    {
        return self::$branchesCache ??= array_map(static function ($b) {
            $b['id'] = (int) $b['id'];
            $b['pcCount'] = (int) $b['pcCount'];
            return $b;
        }, Db::all('SELECT id, name, slug, "pcCount" FROM branches ORDER BY "sortOrder", id'));
    }

    private static function branchName(?int $id): string
    {
        foreach (self::branches() as $b) {
            if ($b['id'] === $id) {
                return (string) $b['name'];
            }
        }
        return '—';
    }

    private static function branchById(int $id): ?array
    {
        foreach (self::branches() as $b) {
            if ($b['id'] === $id) {
                return $b;
            }
        }
        return null;
    }

    /** Филиал из ?branch=slug, иначе домашний филиал пользователя, иначе первый. */
    private static function pickBranch(array $user, ?int $forceId = null): ?array
    {
        $branches = self::branches();
        if (!$branches) {
            return null;
        }
        if ($forceId !== null && ($b = self::branchById($forceId))) {
            return $b;
        }
        $slug = self::getStr('branch', 64);
        foreach ($branches as $b) {
            if ($slug !== '' && $b['slug'] === $slug) {
                return $b;
            }
        }
        foreach ($branches as $b) {
            if ($b['id'] === (int) ($user['homeBranchId'] ?? 0)) {
                return $b;
            }
        }
        return $branches[0];
    }

    private static function branchSwitch(string $path, array $current, array $query = []): string
    {
        $items = [];
        foreach (self::branches() as $b) {
            $items[] = [
                'href' => url_to($path, array_merge($query, ['branch' => $b['slug']])),
                'label' => $b['name'],
                'active' => $b['id'] === $current['id'],
            ];
        }
        return segmented($items, 'Филиал');
    }

    private static function employees(bool $activeOnly = false): array
    {
        $rows = Db::all('SELECT id, name, "homeBranchId", "userId", "isActive", "gradeId", "sortOrder" FROM employees ORDER BY "homeBranchId", "sortOrder", id');
        if ($activeOnly) {
            $rows = array_values(array_filter($rows, static fn ($e) => boolish($e['isActive'])));
        }
        return $rows;
    }

    /** Сотрудники, сгруппированные по филиалам — для <select> с optgroup. */
    private static function employeeOptions(?int $onlyBranch = null, ?int $firstBranch = null): array
    {
        $groups = [];
        $branches = self::branches();
        if ($firstBranch !== null) {
            usort($branches, static fn ($a, $b) => ($b['id'] === $firstBranch) <=> ($a['id'] === $firstBranch));
        }
        foreach ($branches as $b) {
            if ($onlyBranch !== null && $b['id'] !== $onlyBranch) {
                continue;
            }
            $list = [];
            foreach (self::employees(true) as $e) {
                if ((int) $e['homeBranchId'] === $b['id']) {
                    $list[(int) $e['id']] = (string) $e['name'];
                }
            }
            if ($list) {
                $groups[$b['name']] = $list;
            }
        }
        return $groups;
    }

    private static function portal_json(string $key, array $default): array
    {
        $row = Db::one('SELECT "valueJson" FROM portal_content WHERE "key" = ?', [$key]);
        if (!$row) {
            return $default;
        }
        $decoded = json_decode((string) $row['valueJson'], true);
        if (!is_array($decoded)) {
            return $default;
        }
        return $key === 'tariffs' ? $decoded + $default : array_replace_recursive($default, $decoded);
    }

    private static function save_portal_json(string $key, array $value): void
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        if (Db::one('SELECT "key" FROM portal_content WHERE "key" = ?', [$key])) {
            Db::exec('UPDATE portal_content SET "valueJson" = ?, "updatedAt" = ? WHERE "key" = ?', [$json, now_iso(), $key]);
        } else {
            Db::exec('INSERT INTO portal_content ("key", "valueJson", "updatedAt") VALUES (?,?,?)', [$key, $json, now_iso()]);
        }
    }

    /** Смены сотрудников за вчера и сегодня, которые идут прямо сейчас. */
    private static function liveShifts(?int $employeeId = null): array
    {
        $now = now_tyumen();
        $today = date_key($now);
        $yesterday = $now->modify('-1 day')->format('Y-m-d');
        $params = [$yesterday, $today];
        $extra = '';
        if ($employeeId !== null) {
            $extra = ' AND s."employeeId" = ?';
            $params[] = $employeeId;
        }
        $rows = Db::all(
            'SELECT s.id, s.date, s."branchId", b.name AS "branchName", s."shiftType", s."employeeId", e.name AS "employeeName", e."homeBranchId"
             FROM shifts s
             JOIN employees e ON e.id = s."employeeId"
             JOIN branches b ON b.id = s."branchId"
             WHERE s.date IN (?, ?)' . $extra . '
             ORDER BY b."sortOrder", e."sortOrder", s.date',
            $params,
        );
        $out = [];
        foreach ($rows as $r) {
            $r['date'] = substr((string) $r['date'], 0, 10);
            $st = shift_state($r['date'], (string) $r['shiftType'], $now);
            if ($st['state'] === 'live') {
                $r['state'] = $st;
                $out[] = $r;
            }
        }
        return $out;
    }

    private static function currentShiftFor(?int $employeeId): ?array
    {
        if (!$employeeId) {
            return null;
        }
        return self::liveShifts($employeeId)[0] ?? null;
    }

    /** Ближайшие смены сотрудника, которые ещё не закончились. */
    private static function upcomingShiftsFor(int $employeeId, int $days = 14): array
    {
        $now = now_tyumen();
        $from = $now->modify('-1 day')->format('Y-m-d');
        $to = $now->modify('+' . $days . ' days')->format('Y-m-d');
        $rows = Db::all(
            'SELECT s.id, s.date, s."branchId", b.name AS "branchName", s."shiftType", s."employeeId"
             FROM shifts s JOIN branches b ON b.id = s."branchId"
             WHERE s."employeeId" = ? AND s.date >= ? AND s.date <= ?
             ORDER BY s.date',
            [$employeeId, $from, $to],
        );
        $out = [];
        foreach ($rows as $r) {
            $r['date'] = substr((string) $r['date'], 0, 10);
            $st = shift_state($r['date'], (string) $r['shiftType'], $now);
            if ($st['state'] !== 'done') {
                $r['state'] = $st;
                $out[] = $r;
            }
        }
        return $out;
    }

    private static function staffTasksFor(int $employeeId, string $date, ?string $userId = null): array
    {
        if ($userId) {
            return Db::all(
                'SELECT t.*, (SELECT d."completedAt" FROM staff_task_done d WHERE d."taskId" = t.id AND d."userId" = ? LIMIT 1) AS done_at
                 FROM staff_tasks t WHERE t."employeeId" = ? AND t.date = ? ORDER BY t.id',
                [$userId, $employeeId, $date],
            );
        }
        return Db::all(
            'SELECT t.*, (SELECT d."completedAt" FROM staff_task_done d WHERE d."taskId" = t.id LIMIT 1) AS done_at
             FROM staff_tasks t WHERE t."employeeId" = ? AND t.date = ? ORDER BY t.id',
            [$employeeId, $date],
        );
    }

    /* ---------- разметка ---------- */

    private static function layout(array $user, string $inner, array $opts = []): void
    {
        self::html($user, $inner, $opts);
    }

    private static function html(?array $user, string $inner, array $opts = []): void
    {
        $path = request_path();
        $current = find_nav_item($path);
        $title = (string) ($opts['title'] ?? ($current['label'] ?? 'Портал'));
        $flash = flash_take();
        $base = app_base();
        header('Content-Type: text/html; charset=utf-8');
        $out = '<!DOCTYPE html><html lang="ru" class="bg-background"><head>';
        $out .= '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
        $out .= '<meta name="color-scheme" content="light dark"><meta name="theme-color" content="#f3f4f6"><meta name="robots" content="noindex, nofollow">';
        $out .= '<title>' . h($title . ' · ' . APP_NAME) . '</title>';
        $out .= '<link rel="icon" href="' . h(asset('img/icon-light-32x32.png')) . '" sizes="32x32">';
        $out .= '<link rel="apple-touch-icon" href="' . h(asset('img/apple-icon.png')) . '">';
        $out .= '<link rel="manifest" href="' . h(asset('manifest.webmanifest')) . '">';
        $out .= '<link rel="preload" href="' . h($base . '/assets/fonts/geist-cyrillic.woff2') . '" as="font" type="font/woff2" crossorigin>';
        $out .= '<link rel="preload" href="' . h($base . '/assets/fonts/unbounded-cyrillic.woff2') . '" as="font" type="font/woff2" crossorigin>';
        $out .= '<link rel="stylesheet" href="' . h(asset('css/app.css')) . '">';
        $out .= '<script>' . THEME_BOOT . '(function(){var m=document.querySelector(\'meta[name="theme-color"]\');if(m&&document.documentElement.classList.contains("dark"))m.content="#0d0f12"})();</script>';
        $out .= '</head><body class="min-h-dvh bg-background font-sans text-foreground antialiased">';
        $out .= '<a href="#main" class="skip-link">Перейти к содержимому</a>';
        if ($user) {
            $out .= self::shell($user, $path, $title, $inner, (string) ($opts['width'] ?? 'default'));
        } else {
            $out .= $inner;
        }
        if ($flash) {
            $isError = ($flash['type'] ?? '') === 'error';
            $out .= '<div class="pointer-events-none fixed inset-x-0 top-0 z-[70] flex flex-col items-center gap-2 p-3 lg:items-end lg:p-5">';
            $out .= '<div class="toast pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-lg border bg-popover px-4 py-3 text-sm text-popover-foreground shadow-lg ' . ($isError ? 'border-primary/40' : 'border-border') . '" role="' . ($isError ? 'alert' : 'status') . '" data-toast>';
            $out .= '<span class="mt-0.5 ' . ($isError ? 'text-primary-ink' : 'text-foreground') . '">' . icon($isError ? 'alert' : 'check-circle', 'size-4') . '</span>';
            $out .= '<p class="min-w-0 flex-1 leading-relaxed">' . h($flash['msg']) . '</p>';
            $out .= '<button type="button" class="-mt-1 -mr-2 ' . btn_class('quiet', 'icon-sm') . '" data-toast-close aria-label="Закрыть уведомление">' . icon('x', 'size-4') . '</button></div></div>';
        }
        $out .= '<script>window.GP=' . json_encode(['csrf' => csrf_token(), 'base' => $base], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';</script>';
        $out .= '<script src="' . h(asset('js/app.js')) . '" defer></script>';
        $out .= '</body></html>';
        echo $out;
    }

    private static function shell(array $user, string $path, string $title, string $inner, string $width): string
    {
        $counts = self::counts($user);
        $widthClass = [
            'narrow' => 'max-w-3xl',
            'default' => 'max-w-6xl',
            'wide' => 'max-w-[88rem]',
        ][$width] ?? 'max-w-6xl';
        $out = '<div class="min-h-dvh lg:pl-68">';
        $out .= '<aside class="fixed inset-y-0 left-0 z-40 hidden w-68 flex-col border-r border-sidebar-border bg-sidebar text-sidebar-foreground lg:flex">' . self::sidebar($user, $counts, $path, false) . '</aside>';

        $out .= '<header class="sticky top-0 z-30 flex h-14 items-center gap-3 border-b border-border bg-background/85 px-4 pt-[env(safe-area-inset-top)] backdrop-blur-md lg:hidden">';
        $out .= '<a href="' . h(url_to('/')) . '" class="flex shrink-0 items-center text-foreground" aria-label="GAMER PRO — на главную">' . brand_logo('h-3 w-auto') . '</a>';
        $out .= '<span class="h-4 w-px shrink-0 bg-border" aria-hidden="true"></span>';
        $out .= '<p class="min-w-0 flex-1 truncate text-sm font-semibold">' . h($title) . '</p>';
        $out .= '<button type="button" class="' . btn_class('quiet', 'icon-sm') . '" data-theme-toggle aria-label="Сменить тему">' . icon('moon', 'size-4') . '</button>';
        $out .= '<a href="' . h(url_to('/account')) . '" class="shrink-0 rounded-md" aria-label="Мой профиль">' . avatar(self::displayName($user), 'size-8 text-[11px]') . '</a>';
        $out .= '</header>';

        $out .= '<main id="main" tabindex="-1" class="mx-auto flex w-full ' . $widthClass . ' flex-col gap-6 px-4 pt-5 pb-28 outline-none sm:px-6 lg:gap-8 lg:px-10 lg:pt-9 lg:pb-14">' . $inner . '</main>';

        $out .= '<nav class="fixed inset-x-0 bottom-0 z-30 border-t border-border bg-card/95 pb-[env(safe-area-inset-bottom)] backdrop-blur-md lg:hidden" aria-label="Быстрые разделы"><ul class="grid grid-cols-5">';
        $active = find_nav_item($path);
        foreach (MOBILE_TABS as $tab) {
            $on = $active && $active['href'] === $tab['href'];
            $badge = !empty($tab['badge']) ? (int) ($counts[$tab['badge']] ?? 0) : 0;
            $out .= '<li><a href="' . h(url_to($tab['href'])) . '" class="relative flex h-16 flex-col items-center justify-center gap-1 text-[11px] font-medium ' . ($on ? 'text-foreground' : 'text-muted-foreground') . '"' . ($on ? ' aria-current="page"' : '') . '>';
            if ($on) {
                $out .= '<span class="absolute top-0 left-1/2 h-0.5 w-8 -translate-x-1/2 bg-primary" aria-hidden="true"></span>';
            }
            $out .= '<span class="relative">' . icon($tab['icon'], 'size-5');
            if ($badge > 0) {
                $out .= '<span class="absolute -top-1.5 -right-2.5 min-w-4 rounded-full bg-primary px-1 text-center text-[10px] leading-4 font-bold text-primary-foreground tabular-nums">' . ($badge > 99 ? '99+' : $badge) . '</span>';
            }
            $out .= '</span>' . h($tab['label']) . '</a></li>';
        }
        $out .= '<li><button type="button" class="flex h-16 w-full flex-col items-center justify-center gap-1 text-[11px] font-medium text-muted-foreground" data-open-nav aria-expanded="false" aria-controls="nav-drawer">' . icon('menu', 'size-5') . 'Меню</button></li>';
        $out .= '</ul></nav>';

        $out .= '<div id="nav-drawer" class="fixed inset-0 z-50 hidden lg:hidden" data-nav-drawer role="dialog" aria-modal="true" aria-label="Меню портала">';
        $out .= '<button type="button" class="absolute inset-0 size-full bg-black/55 backdrop-blur-sm" data-close-nav aria-label="Закрыть меню" tabindex="-1"></button>';
        $out .= '<div class="drawer-panel absolute inset-y-0 left-0 flex w-80 max-w-[86vw] flex-col bg-sidebar pt-[env(safe-area-inset-top)] text-sidebar-foreground shadow-2xl">' . self::sidebar($user, $counts, $path, true) . '</div></div>';
        return $out . '</div>';
    }

    private static function sidebar(array $user, array $counts, string $path, bool $drawer): string
    {
        $html = '<div class="flex h-16 shrink-0 items-center gap-3 px-5">';
        $html .= '<a href="' . h(url_to('/')) . '" class="flex items-center text-sidebar-accent-foreground transition-opacity hover:opacity-80" aria-label="GAMER PRO — на главную">' . brand_logo('h-3.5 w-auto') . '</a>';
        $html .= '<span class="rounded-[4px] border border-sidebar-border px-1.5 py-px text-[10px] font-semibold tracking-[0.2em] text-sidebar-muted">CRM</span>';
        if ($drawer) {
            $html .= '<button type="button" class="ml-auto inline-flex size-9 items-center justify-center rounded-md text-sidebar-muted transition-colors hover:bg-sidebar-accent hover:text-sidebar-accent-foreground" data-close-nav aria-label="Закрыть меню">' . icon('x', 'size-5') . '</button>';
        }
        $html .= '</div><nav class="flex-1 overflow-y-auto px-3 pb-4 scrollbar-thin" aria-label="Разделы портала">';
        $activeItem = find_nav_item($path);
        foreach (NAV_GROUPS as $group) {
            $items = array_filter($group['items'], static fn ($it) => empty($it['perm']) || can($user, $it['perm']));
            if (!$items) {
                continue;
            }
            $html .= '<div class="mt-5 first:mt-1"><p class="px-3 pb-1.5 text-[10px] font-semibold tracking-[0.18em] text-sidebar-muted uppercase">' . h($group['label']) . '</p><ul class="flex flex-col gap-px">';
            foreach ($items as $item) {
                $active = $activeItem && $activeItem['href'] === $item['href'];
                $count = !empty($item['badge']) ? (int) ($counts[$item['badge']] ?? 0) : 0;
                $cls = $active
                    ? 'bg-sidebar-accent text-sidebar-accent-foreground'
                    : 'text-sidebar-foreground hover:bg-sidebar-accent/70 hover:text-sidebar-accent-foreground';
                $html .= '<li><a href="' . h(url_to($item['href'])) . '" class="relative flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors ' . $cls . '"' . ($active ? ' aria-current="page"' : '') . '>';
                if ($active) {
                    $html .= '<span class="nav-mark" aria-hidden="true"></span>';
                }
                $html .= icon($item['icon'], 'size-4 shrink-0' . ($active ? ' text-brand' : ' text-sidebar-muted'));
                $html .= '<span class="min-w-0 flex-1 truncate">' . h($item['label']) . '</span>';
                if ($count > 0) {
                    $html .= '<span class="min-w-5 rounded-full bg-primary px-1.5 text-center text-[11px] leading-5 font-semibold text-primary-foreground tabular-nums">' . ($count > 99 ? '99+' : $count) . '<span class="sr-only"> новых</span></span>';
                }
                $html .= '</a></li>';
            }
            $html .= '</ul></div>';
        }
        $html .= '</nav>';
        $name = self::displayName($user);
        $sub = $user['roleLabel'] . ($user['homeBranchId'] ? ' · ' . self::branchName($user['homeBranchId']) : '');
        $html .= '<div class="border-t border-sidebar-border p-3"><div class="flex items-center gap-2.5">';
        $html .= '<a href="' . h(url_to('/account')) . '" class="flex min-w-0 flex-1 items-center gap-3 rounded-md p-1.5 transition-colors hover:bg-sidebar-accent">';
        $html .= '<span class="cut inline-flex size-9 shrink-0 items-center justify-center bg-sidebar-accent-foreground font-display text-xs font-semibold text-sidebar" style="--cut:7px" aria-hidden="true">' . h(initials($name)) . '</span>';
        $html .= '<span class="flex min-w-0 flex-col"><span class="truncate text-sm font-semibold text-sidebar-accent-foreground">' . h(short_name($name)) . '</span><span class="truncate text-xs text-sidebar-muted">' . h($sub) . '</span></span></a>';
        $html .= '<button type="button" class="inline-flex size-9 shrink-0 items-center justify-center rounded-md text-sidebar-muted transition-colors hover:bg-sidebar-accent hover:text-sidebar-accent-foreground" data-theme-toggle aria-label="Сменить тему">' . icon('moon', 'size-4') . '</button>';
        $html .= form_open('logout', 'action="' . h(url_to('/sign-out')) . '" class="shrink-0"') . '<button class="inline-flex size-9 items-center justify-center rounded-md text-sidebar-muted transition-colors hover:bg-sidebar-accent hover:text-brand" aria-label="Выйти" title="Выйти">' . icon('logout', 'size-4') . '</button></form>';
        $html .= '</div></div>';
        return $html;
    }

    private static function pageSignIn(): void
    {
        if (self::user()) {
            redirect_to('/');
        }
        $flash = flash_take();
        $err = ($flash['type'] ?? '') === 'error' ? (string) $flash['msg'] : '';
        $last = (string) ($_SESSION['last_login'] ?? '');
        $branches = implode(' · ', array_map(static fn ($b) => $b['name'], self::branches()));
        $html = '<main id="main" class="grid min-h-dvh lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]">';
        $html .= '<div class="relative hidden overflow-hidden bg-sidebar text-sidebar-accent-foreground lg:block">';
        $html .= '<img src="' . h(asset('img/club-hall.webp')) . '" alt="" class="absolute inset-0 size-full object-cover opacity-75" width="768" height="1376" fetchpriority="high">';
        $html .= '<div class="absolute inset-0 bg-linear-to-t from-sidebar via-sidebar/40 to-sidebar/10" aria-hidden="true"></div>';
        $html .= '<div class="relative flex h-full flex-col justify-between p-10 xl:p-14">';
        $html .= '<span class="text-sidebar-accent-foreground">' . brand_logo('h-9 w-auto', true) . '</span>';
        $html .= '<div class="flex max-w-md flex-col gap-4">' . '<p class="flex items-center gap-2 text-[11px] font-semibold tracking-[0.18em] text-sidebar-foreground uppercase"><span class="cut-mark" aria-hidden="true"></span>Портал сотрудников</p>';
        $html .= '<p class="font-display text-3xl leading-tight font-semibold text-balance xl:text-4xl">Смены, регламенты и гости — в одном окне</p>';
        if ($branches !== '') {
            $html .= '<p class="text-sm text-sidebar-foreground">' . h($branches) . '</p>';
        }
        $html .= '</div></div></div>';
        $html .= '<div class="flex flex-col justify-center px-5 py-12 sm:px-10">';
        $html .= '<div class="mx-auto flex w-full max-w-sm flex-col gap-8">';
        $html .= '<span class="text-foreground lg:hidden">' . brand_logo('h-8 w-auto', true) . '</span>';
        $html .= '<div class="flex flex-col gap-2"><h1 class="font-display text-2xl font-semibold tracking-tight">Вход в CRM</h1><p class="text-sm leading-relaxed text-muted-foreground">Логин и пароль выдаёт владелец клуба. Если не получается войти — напишите ему.</p></div>';
        $html .= '<form method="post" class="flex flex-col gap-5" data-submit-once>' . csrf_field() . hidden('_action', 'login');
        $html .= ui_field('Логин', '<input name="login" class="' . field_class('h-11') . '" required autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" value="' . h($last) . '"' . ($last === '' ? ' autofocus' : '') . '>');
        $html .= '<label class="flex flex-col gap-1.5"><span class="text-sm font-medium">Пароль</span><span class="relative block"><input name="password" type="password" class="' . field_class('h-11 pr-11') . '" required autocomplete="current-password"' . ($last !== '' ? ' autofocus' : '') . '>';
        $html .= '<button type="button" class="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-muted-foreground hover:text-foreground" data-toggle-pass aria-label="Показать пароль" aria-pressed="false">' . icon('eye') . '</button></span></label>';
        if ($err !== '') {
            $html .= '<p role="alert" class="flex items-start gap-2 rounded-md border border-primary/30 bg-primary/5 px-3 py-2.5 text-sm text-primary-ink">' . icon('alert', 'mt-0.5 size-4 shrink-0') . h($err) . '</p>';
        }
        $html .= '<button class="' . btn_class('primary', 'lg') . ' w-full">Войти ' . icon('arrow', 'size-4') . '</button></form>';
        $html .= '</div></div></main>';
        self::html(null, $html, ['title' => 'Вход']);
    }
}
