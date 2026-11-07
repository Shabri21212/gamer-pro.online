<?php
declare(strict_types=1);

require __DIR__ . '/pages.php';
require __DIR__ . '/actions.php';

final class Portal
{
    use PortalPages;
    use PortalActions;

    public static function run(): void
    {
        start_session();
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = request_path();
        if ($method === 'POST') {
            $action = (string) ($_POST['_action'] ?? '');
            if ($action !== 'login') {
                csrf_check();
            }
            self::post($path);
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
            session_destroy();
            redirect_to('/sign-in');
        }
        $user = self::user();
        if (!$user) {
            redirect_to('/sign-in');
        }
        if ($path === '/setup') {
            redirect_to('/');
        }

        if ($path === '/') {
            self::pageHome($user);
            return;
        }
        if ($path === '/schedule') {
            self::pageSchedule($user);
            return;
        }
        if ($path === '/cleaning') {
            self::pageCleaning($user);
            return;
        }
        if ($path === '/issues') {
            self::pageIssues($user);
            return;
        }
        if ($path === '/news') {
            self::pageNews($user);
            return;
        }
        if ($path === '/loyalty') {
            self::pageLoyalty($user);
            return;
        }
        if ($path === '/loyalty/edit') {
            self::requireOwner($user);
            self::pageLoyaltyEdit($user);
            return;
        }
        if ($path === '/reviews') {
            self::pageReviews($user);
            return;
        }
        if ($path === '/reviews/edit') {
            self::requireOwner($user);
            self::pageReviewsEdit($user);
            return;
        }
        if ($path === '/compensations') {
            self::pageCompensations($user);
            return;
        }
        if ($path === '/wiki') {
            $slug = self::firstArticleSlug($user['isOwner']);
            if ($slug) {
                redirect_to('/wiki/' . rawurlencode($slug));
            }
            self::pageWikiEmpty($user);
            return;
        }
        if (preg_match('#^/wiki/([^/]+)/edit$#', $path, $m)) {
            self::requireOwner($user);
            self::pageWikiEdit($user, urldecode($m[1]));
            return;
        }
        if (preg_match('#^/wiki/([^/]+)$#', $path, $m)) {
            $slug = urldecode($m[1]);
            if ($slug === 'fines') {
                redirect_to('/fines');
            }
            self::pageWiki($user, $slug);
            return;
        }
        if ($path === '/tariffs') {
            self::pageTariffs($user);
            return;
        }
        if ($path === '/tariffs/edit') {
            self::requireOwner($user);
            self::pageTariffsEdit($user);
            return;
        }
        if ($path === '/fines') {
            self::pageFines($user);
            return;
        }
        if ($path === '/fines/assign') {
            self::requireOwner($user);
            self::pageFinesAssign($user);
            return;
        }
        if ($path === '/fines/edit') {
            self::requireOwner($user);
            self::pageFinesEdit($user);
            return;
        }
        if (preg_match('#^/exams/([a-z0-9-]+)/edit$#', $path, $m)) {
            self::requireOwner($user);
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
        if ($path === '/exams') {
            self::pageExams($user);
            return;
        }
        if ($path === '/account') {
            self::pageAccount($user);
            return;
        }
        if ($path === '/manage/journal') {
            self::requireOwner($user);
            self::pageJournal($user);
            return;
        }
        if ($path === '/manage/employees') {
            self::requireOwner($user);
            self::pageEmployees($user);
            return;
        }
        if ($path === '/manage/grades') {
            self::requireOwner($user);
            self::pageGrades($user);
            return;
        }
        if ($path === '/manage/tasks') {
            self::requireOwner($user);
            self::pageTasks($user);
            return;
        }
        if ($path === '/manage/accounts') {
            self::requireOwner($user);
            self::pageAccounts($user);
            return;
        }
        if ($path === '/manage/exams') {
            self::requireOwner($user);
            self::pageManageExams($user);
            return;
        }
        http_response_code(404);
        self::layout($user, '<main class="mx-auto max-w-md px-4 py-16"><p class="text-muted-foreground text-sm">Страница не найдена.</p></main>');
    }

    private static function post(string $path): void
    {
        $action = (string) ($_POST['_action'] ?? '');
        if ($path === '/sign-in' && $action === 'login') {
            self::doLogin();
            return;
        }
        $user = self::user();
        if (!$user) {
            redirect_to('/sign-in');
        }
        match ($action) {
            'complete_task' => self::doCompleteTask($user),
            'uncomplete_task' => self::doUncompleteTask($user),
            'save_shift' => self::doSaveShift($user),
            'delete_shift' => self::doDeleteShift($user),
            'toggle_clean' => self::doToggleClean($user),
            'assign_seats' => self::doAssignSeats($user),
            'save_incident' => self::doSaveIncident($user),
            'resolve_incident' => self::doResolveIncident($user),
            'save_news' => self::doSaveNews($user),
            'read_news' => self::doReadNews($user),
            'delete_news' => self::doDeleteNews($user),
            'save_review' => self::doSaveReview($user),
            'decide_review' => self::doDecideReview($user),
            'save_comp' => self::doSaveComp($user),
            'decide_comp' => self::doDecideComp($user),
            'save_wiki' => self::doSaveWiki($user),
            'save_tariffs' => self::doSaveTariffs($user),
            'save_fines' => self::doSaveFines($user),
            'assign_fine' => self::doAssignFine($user),
            'save_loyalty' => self::doSaveLoyalty($user),
            'save_promo' => self::doSavePromo($user),
            'save_employee' => self::doSaveEmployee($user),
            'delete_employee' => self::doDeleteEmployee($user),
            'save_grade' => self::doSaveGrade($user),
            'assign_grade' => self::doAssignGrade($user),
            'save_task_tpl' => self::doAssignStaffTask($user),
            'assign_staff_task' => self::doAssignStaffTask($user),
            'delete_staff_task' => self::doDeleteStaffTask($user),
            'save_account' => self::doSaveAccount($user),
            'reset_password' => self::doResetPassword($user),
            'delete_account' => self::doDeleteAccount($user),
            'change_password' => self::doChangePassword($user),
            'create_exam' => self::doCreateExam($user),
            'save_exam' => self::doSaveExam($user),
            'delete_exam' => self::doDeleteExam($user),
            'start_attempt' => self::doStartAttempt($user),
            'submit_attempt' => self::doSubmitAttempt($user),
            default => redirect_to($path),
        };
    }

    private static function user(): ?array
    {
        $id = $_SESSION['uid'] ?? null;
        if (!$id) {
            return null;
        }
        $u = Db::one('SELECT id, name, email, role FROM "user" WHERE id = ?', [$id]);
        if (!$u) {
            return null;
        }
        $emp = Db::one('SELECT id, name, "homeBranchId" FROM employees WHERE "userId" = ?', [$u['id']]);
        $role = ($u['role'] ?? '') === 'owner' ? 'owner' : 'admin';
        $email = (string) $u['email'];
        $login = str_ends_with($email, '@' . LOGIN_DOMAIN) ? substr($email, 0, -strlen('@' . LOGIN_DOMAIN)) : $email;
        return [
            'id' => $u['id'],
            'name' => $u['name'],
            'login' => $login,
            'role' => $role,
            'isOwner' => $role === 'owner',
            'employeeId' => $emp ? (int) $emp['id'] : null,
            'employeeName' => $emp['name'] ?? null,
            'homeBranchId' => $emp ? (int) $emp['homeBranchId'] : null,
        ];
    }

    private static function requireOwner(array $user): void
    {
        if (!$user['isOwner']) {
            http_response_code(403);
            self::layout($user, '<main class="mx-auto max-w-md px-4 py-16"><p class="text-muted-foreground">Только для владельца.</p></main>');
            exit;
        }
    }

    private static function doLogin(): void
    {
        $login = strtolower(trim((string) ($_POST['login'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $email = $login . '@' . LOGIN_DOMAIN;
        $u = $login !== '' ? Db::one('SELECT id, role FROM "user" WHERE email = ?', [$email]) : null;
        if ($login === '' || $password === '') {
            flash_set('error', 'Введите логин и пароль');
            redirect_to('/sign-in');
        }
        $acc = $u ? Db::one('SELECT id, password, password_php FROM account WHERE "userId" = ? AND "providerId" = ?', [$u['id'], 'credential']) : null;
        $ok = false;
        if ($acc) {
            if (!empty($acc['password_php']) && password_verify($password, $acc['password_php'])) {
                $ok = true;
            } elseif (!empty($acc['password'])) {
                @ini_set('memory_limit', '256M');
                $ok = Scrypt::verifyBetterAuth((string) $acc['password'], $password);
                if ($ok) {
                    Db::exec('UPDATE account SET password_php = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $acc['id']]);
                }
            }
        }
        if (!$ok) {
            flash_set('error', 'Неверный логин или пароль');
            redirect_to('/sign-in');
        }
        session_regenerate_id(true);
        $_SESSION['uid'] = $u['id'];
        redirect_to('/');
    }

    private static function counts(array $user): array
    {
        $news = (int) (Db::one(
            'SELECT COUNT(*) c FROM announcements a
             WHERE a."isDraft" = 0 AND NOT EXISTS (
               SELECT 1 FROM announcement_reads r WHERE r."announcementId" = a.id AND r."userId" = ?
             )',
            [$user['id']],
        )['c'] ?? 0);
        $issues = (int) (Db::one('SELECT COUNT(*) c FROM incidents WHERE status != ?', ['done'])['c'] ?? 0);
        $reviews = $user['isOwner']
            ? (int) (Db::one('SELECT COUNT(*) c FROM review_bonuses WHERE status = ?', ['pending'])['c'] ?? 0)
            : 0;
        $comps = $user['isOwner']
            ? (int) (Db::one('SELECT COUNT(*) c FROM compensations WHERE status = ?', ['pending'])['c'] ?? 0)
            : 0;
        return ['news' => $news, 'issues' => $issues, 'reviews' => $reviews, 'compensations' => $comps];
    }

    private static function branches(): array
    {
        return Db::all('SELECT id, name, slug, "pcCount" FROM branches ORDER BY "sortOrder", id');
    }

    private static function employees(): array
    {
        return Db::all('SELECT id, name, "homeBranchId", "userId", "isActive", "gradeId" FROM employees ORDER BY "homeBranchId", "sortOrder", id');
    }

    private static function portal_json(string $key, array $default): array
    {
        $row = Db::one('SELECT "valueJson" FROM portal_content WHERE key = ?', [$key]);
        if (!$row) {
            return $default;
        }
        $decoded = json_decode((string) $row['valueJson'], true);
        return is_array($decoded) ? array_replace_recursive($default, $decoded) : $default;
    }

    private static function save_portal_json(string $key, array $value): void
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);
        $now = (new DateTimeImmutable())->format('c');
        $exists = Db::one('SELECT key FROM portal_content WHERE key = ?', [$key]);
        if ($exists) {
            Db::exec('UPDATE portal_content SET "valueJson" = ?, "updatedAt" = ? WHERE key = ?', [$json, $now, $key]);
        } else {
            Db::exec('INSERT INTO portal_content (key, "valueJson", "updatedAt") VALUES (?,?,?)', [$key, $json, $now]);
        }
    }

    private static function myShift(array $user): ?array
    {
        if (!$user['employeeId']) {
            return null;
        }
        $now = now_tyumen();
        $today = date_key($now);
        $yesterday = $now->modify('-1 day')->format('Y-m-d');
        $rows = Db::all(
            'SELECT s.date, s."branchId", b.name AS "branchName", s."shiftType", s."employeeId", e.name AS "employeeName"
             FROM shifts s
             JOIN employees e ON e.id = s."employeeId"
             JOIN branches b ON b.id = s."branchId"
             WHERE e."userId" = ? AND s.date IN (?, ?)
             ORDER BY s.date DESC',
            [$user['id'], $today, $yesterday],
        );
        return self::pickLiveShift($rows, $now, $today);
    }

    /** Смена, которая сейчас «живая»: ночь после 00:00 остаётся вчерашней до полудня, пока не сдали. */
    private static function pickLiveShift(array $rows, DateTimeImmutable $now, string $today): ?array
    {
        if (!$rows) {
            return null;
        }
        $onToday = null;
        $carried = null;
        foreach ($rows as $row) {
            $d = substr((string) $row['date'], 0, 10);
            $row['date'] = $d;
            if ($d === $today) {
                $onToday = $row;
            } elseif (in_array($row['shiftType'], ['night', 'full'], true)) {
                $carried = $row;
            }
        }
        $picked = $onToday ?? $carried;
        if ($carried && (int) $now->format('G') < 12) {
            $close = Db::one(
                'SELECT id FROM shift_reports WHERE "branchId" = ? AND date = ? AND "shiftType" = ? AND phase = ?',
                [$carried['branchId'], $carried['date'], $carried['shiftType'], 'close'],
            );
            if (!$close) {
                $picked = $carried;
            }
        }
        return $picked;
    }

    private static function liveShifts(): array
    {
        $now = now_tyumen();
        $today = date_key($now);
        $yesterday = $now->modify('-1 day')->format('Y-m-d');
        $rows = Db::all(
            'SELECT s.date, s."branchId", b.name AS "branchName", s."shiftType", s."employeeId", e.name AS "employeeName"
             FROM shifts s
             JOIN employees e ON e.id = s."employeeId"
             JOIN branches b ON b.id = s."branchId"
             WHERE s.date IN (?, ?) AND e."isActive"=1
             ORDER BY b."sortOrder", e."sortOrder", s.date DESC',
            [$today, $yesterday],
        );
        $byEmp = [];
        foreach ($rows as $row) {
            $byEmp[(int) $row['employeeId']][] = $row;
        }
        $out = [];
        foreach ($byEmp as $list) {
            $picked = self::pickLiveShift($list, $now, $today);
            if ($picked) {
                $out[] = $picked;
            }
        }
        return $out;
    }

    private static function staffTasksFor(int $employeeId, string $date, ?string $userId = null): array
    {
        if ($userId) {
            return Db::all(
                'SELECT t.*,
                        (SELECT d."userId" FROM staff_task_done d WHERE d."taskId"=t.id AND d."userId"=? LIMIT 1) AS done_me
                 FROM staff_tasks t
                 WHERE t."employeeId"=? AND t.date=?
                 ORDER BY t.id',
                [$userId, $employeeId, $date],
            );
        }
        return Db::all(
            'SELECT t.* FROM staff_tasks t WHERE t."employeeId"=? AND t.date=? ORDER BY t.id',
            [$employeeId, $date],
        );
    }

    private static function clockLine(DateTimeImmutable $now, ?array $shift = null): string
    {
        $html = '<p class="text-muted-foreground flex flex-wrap items-center gap-x-2 gap-y-0.5 text-sm">' . icon('clock', 'size-3.5') . ' Сейчас ' . h($now->format('H:i')) . ' · Тюмень';
        if ($shift && ($shift['date'] ?? '') !== date_key($now) && in_array($shift['shiftType'] ?? '', ['night', 'full'], true)) {
            $html .= '<span class="text-foreground">· ночная смена ещё идёт, задание за ' . h(format_date_human($shift['date'])) . '</span>';
        }
        return $html . '</p>';
    }

    private static function onShiftToday(string $dateKey): array
    {
        return Db::all(
            'SELECT s.id, s.date, s."branchId", b.name AS "branchName", s."employeeId", e.name AS "employeeName", s."shiftType"
             FROM shifts s
             JOIN branches b ON b.id = s."branchId"
             JOIN employees e ON e.id = s."employeeId"
             WHERE s.date = ?
             ORDER BY b."sortOrder", s."shiftType"',
            [$dateKey],
        );
    }

    private static function layoutGuest(string $inner): void
    {
        self::html(false, null, $inner);
    }

    private static function layout(array $user, string $inner, bool $wiki = false): void
    {
        self::html(true, $user, $inner, $wiki);
    }

    private static function html(bool $app, ?array $user, string $inner, bool $wiki = false): void
    {
        $path = request_path();
        $counts = $user ? self::counts($user) : ['news' => 0, 'issues' => 0, 'reviews' => 0, 'compensations' => 0];
        $flash = flash_take();
        $title = 'GAMERPRO — база знаний и график смен';
        $current = find_nav_item($path);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="ru" class="bg-background"><head>';
        echo '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
        echo '<meta name="color-scheme" content="dark light"><meta name="theme-color" content="#ffffff">';
        echo '<title>' . h($title) . '</title>';
        echo '<link rel="icon" href="' . h(asset('img/icon-light-32x32.png')) . '">';
        echo '<link rel="stylesheet" href="' . h(asset('css/app.css')) . '">';
        echo '<style>dialog[data-day-dialog]{position:fixed;inset:0;margin:auto;height:fit-content;max-height:90vh;width:calc(100% - 2rem);max-width:28rem;overflow:auto}dialog.gp-news-dialog{position:fixed;inset:0;margin:auto;height:fit-content;max-height:90vh;width:calc(100% - 2rem);max-width:32rem;overflow:auto}dialog.gp-news-dialog::backdrop{background:rgba(15,18,22,.48);backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px)}.gp-break{overflow-wrap:anywhere;word-break:break-word}.gp-clamp-2{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}[data-emp-filter][aria-pressed="true"]{border-color:color-mix(in oklab,var(--primary) 45%,transparent);color:var(--foreground);box-shadow:0 4px 12px rgba(15,18,22,.12)}html.dark,html.dark dialog,html.dark select{color-scheme:dark}html.dark select,html.dark select option,html.dark select optgroup{background-color:var(--card,#171717);color:var(--card-foreground,#f4f4f5)}html.dark select option:disabled,html.dark select optgroup{color:var(--muted-foreground,#a1a1aa)}select{accent-color:#e53935}select option:checked,select option:hover,select option:focus{background-color:#f2b4b0!important;background-image:linear-gradient(#f2b4b0,#f2b4b0);color:#1a1212}select option:checked{box-shadow:inset 0 0 0 999px #f2b4b0}@supports selector(::picker(select)){select,select::picker(select){appearance:base-select}select::picker-icon{color:#9a9a9a}select::picker(select){background:#1c1c1c;color:#f2f2f2;border:1px solid #2a2a2a;border-radius:.6rem;position-area:block-end span-all;position-try-fallbacks:none;inset:unset;top:anchor(bottom);left:anchor(left);right:anchor(right);margin-top:.25rem;max-height:min(16rem,45vh);overflow-y:auto}select option:is(:hover,:checked,:focus){background:#f2b4b0;background-image:none;box-shadow:none;color:#1a1212}}</style>';
        echo '<style id="gp-fines">[data-fines-list]>summary{list-style:none}[data-fines-list]>summary::-webkit-details-marker,[data-fines-list]>summary::marker{display:none}[data-fines-list][open]>summary>svg:last-child{transform:rotate(180deg)}</style>';
        echo '<style id="gp-task">.gp-day-task{border-color:color-mix(in oklab,var(--primary) 48%,var(--border));background:linear-gradient(160deg,color-mix(in oklab,var(--primary) 22%,transparent),var(--card) 58%)}.gp-day-task-body{background:color-mix(in oklab,var(--primary) 16%,transparent);border:1px solid color-mix(in oklab,var(--primary) 32%,transparent);border-radius:.7rem;padding:.75rem .9rem}.gp-day-task-title{margin:0;font-size:1.4rem;line-height:1.2;font-weight:700;letter-spacing:-.03em;color:var(--primary)}.gp-day-task-desc{margin:.4rem 0 0;font-size:1.08rem;line-height:1.45;color:var(--foreground)}.gp-day-task-done{margin:.45rem 0 0;font-size:.9rem;font-weight:600;color:var(--primary)}@media(min-width:640px){.gp-day-task-title{font-size:1.7rem}.gp-day-task-desc{font-size:1.2rem}}</style>';
        echo '<style id="gp-mob">'
            . 'html,body{max-width:100%;overflow-x:clip}'
            . '.gp-shift-full,.gp-shift-kind,.gp-cal-wd{display:none}'
            . '.gp-week-cards{display:none}'
            . '.wiki-content{overflow-wrap:anywhere;word-break:break-word;min-width:0}'
            . '.wiki-content img,.wiki-content video,.wiki-content iframe,.wiki-content pre{max-width:100%;height:auto}'
            . '.wiki-content table{display:block;max-width:100%;overflow-x:auto}'
            . '[data-existing]{min-width:0}'
            . '[data-existing] .gp-break,dialog[data-day-dialog] h3{overflow-wrap:anywhere}'
            . '@media(max-width:1023px){'
            . 'main{min-width:0;padding-bottom:max(1.25rem,env(safe-area-inset-bottom))}'
            . '.gp-topbar{padding-left:max(.75rem,env(safe-area-inset-left));padding-right:max(.75rem,env(safe-area-inset-right));padding-top:env(safe-area-inset-top);height:calc(3.5rem + env(safe-area-inset-top))}'
            . '[data-nav-drawer]>div{padding-top:env(safe-area-inset-top)}'
            . 'dialog[data-day-dialog],dialog.gp-news-dialog{width:calc(100% - 1.25rem);max-width:28rem;max-height:min(90vh,90dvh);padding-bottom:env(safe-area-inset-bottom)}'
            . '.gp-cal-weekdays,.gp-cal-pad{display:none}'
            . '.gp-cal-grid{display:flex;flex-direction:column;gap:.55rem}'
            . '.gp-cal-day{min-height:0!important;flex-direction:column;gap:.55rem;padding:.85rem 1rem;border-radius:.95rem;touch-action:manipulation}'
            . '.gp-cal-meta{align-items:center;gap:.5rem}'
            . '.gp-cal-num{font-size:1.35rem;line-height:1;min-width:1.7rem}'
            . '.gp-cal-wd{display:inline;margin-right:auto;font-size:.82rem;font-weight:650;text-transform:capitalize;color:var(--muted-foreground)}'
            . '.gp-cal-today{font-size:.68rem;white-space:nowrap}'
            . '.gp-cal-shifts{gap:.45rem}'
            . '.gp-cal-empty{display:block;padding:.15rem 0}'
            . '.gp-shift{width:100%;align-items:flex-start;flex-wrap:wrap;gap:.45rem .55rem;padding:.7rem .8rem;border-radius:.75rem}'
            . '.gp-shift-short{display:none}'
            . '.gp-shift-full{display:block;font-size:.95rem;font-weight:700;line-height:1.25;overflow:visible;white-space:normal;overflow-wrap:anywhere}'
            . '.gp-shift-kind{display:block;margin-top:.15rem;font-size:.75rem;font-weight:650;opacity:.88;white-space:normal}'
            . '.gp-shift-away{display:none}'
            . '[data-emp-filter]{max-width:100%;white-space:normal;height:auto;line-height:1.25}'
            . '.gp-week-table{display:none}'
            . '.gp-week-cards{display:flex;flex-direction:column;gap:.75rem}'
            . '.gp-week-card{border:1px solid var(--border);background:var(--card);border-radius:.9rem;padding:.85rem 1rem}'
            . '.gp-week-card h3{margin:0 0 .65rem;font-size:.95rem;font-weight:700;overflow-wrap:anywhere}'
            . '.gp-week-day{display:flex;flex-direction:column;gap:.35rem;padding:.55rem 0;border-top:1px solid var(--border)}'
            . '.gp-week-day:first-of-type{border-top:0;padding-top:0}'
            . '.gp-week-day.is-today{background:color-mix(in oklab,var(--primary) 8%,transparent);margin:0 -.35rem;padding-left:.35rem;padding-right:.35rem;border-radius:.5rem}'
            . '.gp-week-day-h{display:flex;align-items:baseline;justify-content:space-between;flex-wrap:wrap;gap:.35rem .5rem;font-size:.78rem;font-weight:700;text-transform:capitalize}'
            . '}'
            . '@media(max-width:899px){'
            . 'table.gp-cards thead{display:none}'
            . 'table.gp-cards,table.gp-cards tbody{display:block;width:100%}'
            . 'table.gp-cards tr{display:block;border:1px solid var(--border);background:var(--card);border-radius:.85rem;padding:.7rem .9rem;margin:0 0 .65rem}'
            . 'table.gp-cards td,table.gp-cards tbody th{display:flex;align-items:flex-start;justify-content:space-between;gap:.75rem;padding:.28rem 0;border:0;text-align:left;white-space:normal;overflow-wrap:anywhere;width:auto}'
            . 'table.gp-cards td::before,table.gp-cards tbody th::before{content:attr(data-th);flex:0 0 6.6rem;font-size:.68rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--muted-foreground);padding-top:.18rem}'
            . 'table.gp-cards td:not([data-th])::before,table.gp-cards td[data-th=""]::before,table.gp-cards tbody th:not([data-th])::before{content:none;flex:0}'
            . 'table.gp-cards td[data-th=""]{display:block;padding-top:.45rem}'
            . 'table{font-size:.85rem}'
            . '}'
            . '@media(max-width:639px){'
            . '.gp-exam-wrap{padding:.75rem}'
            . '.gp-exam-ch-title{white-space:normal}'
            . '.gp-exam-ch{align-items:flex-start;flex-wrap:wrap}'
            . '.gp-exam-ch-st{margin-left:2.05rem;flex-basis:100%;justify-content:flex-start}'
            . '.gp-exam-kicker{max-width:100%}'
            . '.gp-exam-hero{flex-wrap:wrap;min-height:0;align-items:flex-end}'
            . '.gp-exam-num{font-size:2.45rem}'
            . '.gp-exam-nav{flex-wrap:wrap}'
            . '.gp-exam-nav-actions{margin-left:0;width:100%;justify-content:space-between}'
            . '.gp-exam-row{flex-wrap:wrap}'
            . '.gp-exam-row-meta{margin-left:0;width:100%;justify-content:flex-start;text-transform:none}'
            . '.gp-day-task li>div{flex-direction:column}'
            . '.gp-day-task form,.gp-day-task form button{width:100%}'
            . '[data-half-box] dd{font-size:1.05rem}'
            . '}'
            . '@media(max-width:419px){[data-half-box] dl{grid-template-columns:1fr;gap:.5rem}}'
            . '</style>';
        echo '<style id="gp-skin">'
            . 'html.dark{--background:#121212;--foreground:#f2f2f2;--card:#1a1a1a;--card-foreground:#f2f2f2;--popover:#1c1c1c;--popover-foreground:#f2f2f2;--primary:#e53935;--primary-foreground:#fff;--secondary:#242424;--secondary-foreground:#ececec;--muted:#242424;--muted-foreground:#9a9a9a;--accent:#262626;--accent-foreground:#f2f2f2;--destructive:#e53935;--border:#2a2a2a;--input:#2a2a2a;--ring:#e53935;--brand:#e53935;--sidebar:#141414;--sidebar-foreground:#f0f0f0;--sidebar-primary:#e53935;--sidebar-primary-foreground:#fff;--sidebar-accent:#222;--sidebar-accent-foreground:#f2f2f2;--sidebar-border:#262626;--sidebar-ring:#e53935;--radius:.9rem}'
            . 'html.dark body{font-weight:500}'
            . 'html.dark h1,html.dark h2,html.dark h3{font-weight:700}'
            . 'html.dark .gp-skin-glow{display:block;position:fixed;top:0;left:0;z-index:1;width:38rem;height:22rem;pointer-events:none;background:radial-gradient(ellipse 85% 75% at 0% 0%,rgba(229,57,53,.42),rgba(229,57,53,.1) 46%,transparent 72%)}'
            . '.gp-skin-glow{display:none}'
            . '</style>';
        echo '<style id="gp-exam">'
            . '.gp-exam-wrap{display:flex;align-items:stretch;gap:1.1rem;width:100%;padding:1.15rem 1.35rem 2rem;box-sizing:border-box}'
            . '.gp-exam-rail{width:17.75rem;flex-shrink:0;background:#161616;color:#f0f0f0;border:1px solid #242424;border-radius:1.15rem;padding:.95rem .85rem 1rem;display:flex;flex-direction:column;gap:.7rem}'
            . '.gp-exam-xp{padding:.15rem .35rem .85rem;border-bottom:1px solid #2a2a2a}'
            . '.gp-exam-xp-top{display:flex;justify-content:space-between;align-items:flex-end;gap:.75rem}'
            . '.gp-exam-xp-lvl,.gp-exam-xp-num{margin:0;font-size:.62rem;font-weight:700;letter-spacing:.16em;text-transform:uppercase;color:#8d8d8d}'
            . '.gp-exam-xp-lvl b,.gp-exam-xp-num b{display:block;margin-top:.15rem;font-size:1.7rem;letter-spacing:-.03em;color:#fff;line-height:1}'
            . '.gp-exam-xp-num{text-align:right}'
            . '.gp-exam-xp-bar{height:.34rem;border-radius:99px;background:#2a2a2a;margin:.7rem 0 .45rem;overflow:hidden}'
            . '.gp-exam-xp-bar>span{display:block;height:100%;background:#e53935;border-radius:99px}'
            . '.gp-exam-xp-meta{margin:0;font-size:.72rem;color:#8d8d8d;line-height:1.4;font-weight:500}'
            . '.gp-exam-chapters{display:flex;flex-direction:column;gap:.4rem;flex:1}'
            . '.gp-exam-ch{display:flex;align-items:center;gap:.7rem;padding:.7rem .7rem;border-radius:.8rem;text-decoration:none;color:inherit;background:#1c1c1c;border:1px solid transparent}'
            . '.gp-exam-ch:hover{background:#222}'
            . '.gp-exam-ch.is-on{background:#202020;box-shadow:inset 3px 0 0 #e53935}'
            . '.gp-exam-ch.is-done .gp-exam-ch-st{color:#cfcfcf}'
            . '.gp-exam-ch-ico{width:1.35rem;height:1.35rem;display:flex;align-items:center;justify-content:center;color:#c5c5c5;flex-shrink:0}'
            . '.gp-exam-ch-title{display:block;font-size:.88rem;font-weight:650;line-height:1.25}'
            . '.gp-exam-ch-sub{display:block;margin-top:.12rem;font-size:.68rem;color:#8a8a8a}'
            . '.gp-exam-ch-st{margin-left:auto;display:flex;align-items:center;gap:.35rem;color:#8d8d8d;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;flex-shrink:0}'
            . '.gp-exam-ch.is-draft .gp-exam-ch-st{color:#e53935}'
            . '.gp-exam-to-all{display:flex;align-items:center;justify-content:center;margin-top:.35rem;background:#1c1c1c;color:#f0f0f0;border:1px solid #2a2a2a;border-radius:.8rem;padding:.7rem;font-weight:700;font-size:.88rem;text-decoration:none}'
            . '.gp-exam-to-all:hover{background:#242424}'
            . '.gp-exam-stage{flex:1;min-width:0}'
            . '.gp-exam{color-scheme:dark;background:#161616;color:#f0f0f0;border-radius:1.2rem;overflow:hidden;border:1px solid #242424}'
            . '.gp-exam .text-muted-foreground{color:#9a9a9a}'
            . '.gp-exam .text-primary{color:#e53935}'
            . '.gp-exam .border-border{border-color:#2c2c2c}'
            . '.gp-exam .bg-card{background:#202020}'
            . '.gp-exam .bg-background{background:#161616}'
            . '.gp-exam input:not([type=checkbox]):not([type=radio]):not([type=hidden]),.gp-exam textarea,.gp-exam select{background:#1c1c1c;border:1px solid #333;color:#f0f0f0}'
            . '.gp-exam-hero{position:relative;min-height:11.25rem;display:flex;align-items:flex-end;justify-content:space-between;gap:1rem;padding:1.7rem 1.85rem 1.3rem;background:#111;overflow:hidden}'
            . '.gp-exam-hero:before{content:"";position:absolute;inset:0;pointer-events:none;background:radial-gradient(ellipse 58% 125% at 62% -8%,rgba(229,57,53,.78),rgba(229,57,53,.22) 40%,transparent 70%)}'
            . '.gp-exam-mark{position:absolute;left:52%;top:-12%;width:12.5rem;height:12.5rem;color:rgba(255,72,68,.5);pointer-events:none;z-index:0;filter:drop-shadow(0 0 26px rgba(229,57,53,.85))}'
            . '.gp-exam-kicker{position:relative;z-index:1;margin:0;font-size:.7rem;font-weight:700;letter-spacing:.18em;text-transform:uppercase;color:#ececec;max-width:68%;line-height:1.4}'
            . '.gp-exam-num{position:relative;z-index:1;font-size:4.55rem;font-weight:700;line-height:.82;letter-spacing:-.05em;color:#fff;font-variant-numeric:tabular-nums}'
            . '.gp-exam-num:after{content:"";position:absolute;right:-.4rem;bottom:.12rem;width:1.65rem;height:1.65rem;border-right:3px solid #e53935;border-bottom:3px solid #e53935}'
            . '.gp-exam-body{padding:1.55rem 1.85rem 1.2rem;font-size:1rem;font-weight:500;line-height:1.7;color:#e8e8e8}'
            . '.gp-exam-body p{margin:0}'
            . '.gp-exam-body p+p,.gp-exam-body .gp-exam-ans-list+p,.gp-exam-body p+.gp-exam-ans-list{margin-top:1rem}'
            . '.gp-exam-mark-ok{color:#8fd4a4;font-weight:700}'
            . '.gp-exam-mark-bad{color:#f0a8a4;font-weight:700}'
            . '.gp-exam-mark-wait{color:#cbb87a;font-weight:700}'
            . '.gp-exam-ans-list{display:flex;flex-direction:column;gap:.5rem;margin:.7rem 0 0}'
            . '.gp-exam-ans{background:#1e1e1e;border:1px solid #2a2a2a;border-radius:.85rem;padding:.75rem 1rem}'
            . '.gp-exam-ans.is-ok{background:#17241c;border-color:#3d6b4c;color:#c8e6c9}'
            . '.gp-exam-ans.is-bad{background:#2a1616;border-color:#e53935;color:#ffcdd2}'
            . '.gp-exam-ans .gp-exam-ans-note{display:block;margin-top:.15rem;font-size:.8rem;opacity:.85}'
            . '.gp-exam-body strong{color:#fff;font-weight:700}'
            . '.gp-exam-nav{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.25rem 1.85rem 1.45rem}'
            . '.gp-exam-nav form{margin:0}'
            . '.gp-exam-nav-actions{display:flex;align-items:center;gap:1rem;margin-left:auto}'
            . '.gp-exam-owner{display:flex;flex-wrap:wrap;align-items:center;gap:.45rem 1.2rem;margin-top:1.15rem}'
            . '.gp-exam-del{background:none;border:0;color:#e53935;font-weight:700;font-size:.95rem;padding:.15rem 0;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:.4rem}'
            . '.gp-exam-del:hover{color:#ff8a80}'
            . '.gp-exam-back{background:none;border:0;color:#9a9a9a;font-weight:600;font-size:.95rem;padding:.55rem .15rem;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:.4rem}'
            . '.gp-exam-back:hover{color:#f0f0f0}'
            . '.gp-exam-next{display:inline-flex;align-items:center;justify-content:center;gap:.4rem;background:#e53935;color:#fff;border:0;border-radius:.9rem;font-weight:700;font-size:.95rem;padding:.72rem 1.4rem;cursor:pointer;text-decoration:none;min-width:6.6rem}'
            . '.gp-exam-next:hover{background:#d32f2f}'
            . '.gp-exam-next.hidden,.gp-exam-back.hidden{display:none}'
            . '.gp-exam-row{display:flex;align-items:center;gap:.85rem;width:100%;text-align:left;background:#1e1e1e;border:1px solid #2a2a2a;border-radius:.9rem;padding:.9rem 1rem;color:inherit;text-decoration:none}'
            . '.gp-exam-row+.gp-exam-row{margin-top:.5rem}'
            . '.gp-exam-row:hover{border-color:#3a3a3a;background:#232323}'
            . '.gp-exam-row.is-done{box-shadow:inset 3px 0 0 #e53935}'
            . '.gp-exam-row-ico{width:1.75rem;height:1.75rem;display:flex;align-items:center;justify-content:center;color:#cfcfcf;flex-shrink:0}'
            . '.gp-exam-row-meta{margin-left:auto;display:flex;align-items:center;gap:.7rem;color:#8d8d8d;font-size:.78rem;font-weight:600;flex-shrink:0;text-transform:uppercase;letter-spacing:.04em}'
            . '.gp-exam-row.is-done .gp-exam-row-meta{color:#cfcfcf}'
            . '.gp-exam-opt{display:flex;align-items:center;gap:.75rem;background:#1e1e1e;border:1px solid #2a2a2a;border-radius:.85rem;padding:.85rem 1rem;cursor:pointer}'
            . '.gp-exam-opt+.gp-exam-opt{margin-top:.5rem}'
            . '.gp-exam-step p+.gp-exam-opt{margin-top:.9rem}'
            . '.gp-exam-opt:hover{border-color:#3a3a3a}'
            . '.gp-exam-opt:has(input:checked){border-color:#e53935;background:#2a1616}'
            . '.gp-exam-step{border:0;margin:0;padding:0;min-width:0}'
            . '.gp-exam-step>p:first-of-type{font-size:1.06rem;font-weight:600;color:#f2f2f2;line-height:1.55}'
            . '.gp-exam-step>p:first-of-type+p{font-size:.92rem;font-weight:500;color:#9a9a9a}'
            . '.gp-exam[data-exam-take] .gp-exam-step{display:none}'
            . '.gp-exam[data-exam-take] .gp-exam-step.is-on{display:block}'
            . '@media(max-width:960px){.gp-exam-wrap{flex-direction:column;padding:1rem}.gp-exam-rail{width:100%}}'
            . '@media(max-width:640px){.gp-exam-num{font-size:3.05rem}.gp-exam-mark{width:9.5rem;height:9.5rem;left:46%}.gp-exam-hero,.gp-exam-body,.gp-exam-nav{padding-left:1.15rem;padding-right:1.15rem}}'
            . '</style>';
        echo '<script>' . THEME_BOOT . '</script>';
        echo '<script>window.GP=' . json_encode([
            'csrf' => csrf_token(),
            'base' => app_base(),
        ], JSON_UNESCAPED_UNICODE) . ';</script>';
        echo '</head><body class="bg-background text-foreground font-sans antialiased">';
        echo '<div class="gp-skin-glow" aria-hidden="true"></div>';
        if ($flash) {
            $cls = ($flash['type'] ?? '') === 'error'
                ? 'bg-destructive/10 text-destructive'
                : 'bg-primary/10 text-primary';
            echo '<div class="fixed top-3 left-1/2 z-50 max-w-[calc(100%-1.5rem)] -translate-x-1/2 rounded-md px-3 py-2 text-center text-sm shadow-md ' . $cls . '" role="status">' . h($flash['msg']) . '</div>';
        }
        if ($app && $user) {
            echo self::shell($user, $counts, $path, $current, $inner);
        } else {
            echo $inner;
        }
        $jsFile = dirname(__DIR__) . '/assets/js/app.js';
        $jsVer = is_file($jsFile) ? (string) filemtime($jsFile) : '1';
        echo '<script src="' . h(asset('js/app.js')) . '?v=' . h($jsVer) . '" defer></script>';
        echo '</body></html>';
    }

    private static function shell(array $user, array $counts, string $path, ?array $current, string $inner): string
    {
        $name = $user['employeeName'] ?? $user['name'];
        $displayName = short_name((string) $name);
        $initial = mb_strtoupper(mb_substr(trim((string) $name), 0, 1)) ?: '?';
        $role = $user['isOwner'] ? 'Владелец' : 'Администратор';
        $nameLen = mb_strlen(trim($displayName));
        $nameCls = $nameLen > 16 ? 'truncate text-xs font-medium leading-tight' : 'truncate text-sm font-medium';
        $sidebar = self::sidebar($user, $counts, $path, false);
        $mobile = self::sidebar($user, $counts, $path, true);
        $profile = function (string $align, string $place = 'up') use ($name, $displayName, $initial, $role, $nameCls): string {
            $side = $align === 'end' ? 'right-0' : 'left-0';
            $anchor = $place === 'down' ? 'top-full mt-1.5' : 'bottom-full mb-1.5';
            $grow = $align === 'start' ? 'min-w-0 flex-1' : 'shrink-0';
            $btnW = $align === 'start' ? ' w-full' : '';
            return '<div class="relative ' . $grow . '" data-menu>'
                . '<button type="button" class="hover:bg-muted flex min-w-0' . $btnW . ' items-center gap-2 rounded-md p-1.5 transition-colors" data-menu-btn aria-label="Профиль: ' . h($name) . '">'
                . '<span class="bg-primary text-primary-foreground flex size-7 shrink-0 items-center justify-center rounded-full text-sm font-semibold">' . h($initial) . '</span>'
                . '<span class="hidden min-w-0 flex-1 flex-col text-left sm:flex"><span class="' . $nameCls . '">' . h($displayName) . '</span><span class="text-muted-foreground text-xs">' . h($role) . '</span></span>'
                . icon('chevrondown', 'text-muted-foreground ml-auto hidden size-3.5 shrink-0 sm:block')
                . '</button>'
                . '<div class="border-border bg-popover text-popover-foreground absolute ' . $side . ' ' . $anchor . ' z-50 hidden w-56 rounded-lg border p-1 shadow-md" data-menu-panel>'
                . '<a class="bg-destructive/10 text-destructive hover:bg-destructive/20 flex items-center gap-2 rounded-md px-2 py-2 text-sm font-medium" href="' . h(url_to('/sign-out')) . '">' . icon('logout') . ' Выйти</a>'
                . '<div class="bg-border my-1 h-px"></div>'
                . '<div class="flex items-center gap-2.5 px-2 py-2"><span class="bg-primary text-primary-foreground flex size-9 shrink-0 items-center justify-center rounded-full text-sm font-semibold">' . h($initial) . '</span><span class="flex min-w-0 flex-col"><span class="truncate text-sm font-semibold">' . h($name) . '</span><span class="text-muted-foreground text-xs">' . h($role) . '</span></span></div>'
                . '<a class="hover:bg-muted flex items-center gap-2 rounded-md px-2 py-2 text-sm" href="' . h(url_to('/account')) . '">' . icon('user') . ' Мой профиль</a>'
                . '</div></div>';
        };
        $theme = '<button type="button" class="flex size-9 shrink-0 items-center justify-center rounded-full border border-border text-muted-foreground transition-colors hover:bg-muted hover:text-foreground" data-theme-toggle aria-label="Тема">' . icon('moon', 'size-4') . '</button>';
        $logoutBtn = '<a href="' . h(url_to('/sign-out')) . '" class="flex size-9 shrink-0 items-center justify-center rounded-full border border-border text-destructive transition-colors hover:bg-destructive/10" aria-label="Выйти">' . icon('logout', 'size-4') . '</a>';

        $out = '<div class="min-h-svh lg:pl-64">';
        $out .= '<aside class="border-border bg-sidebar text-sidebar-foreground fixed inset-y-0 left-0 z-40 hidden w-64 flex-col border-r lg:flex">' . $sidebar . '<div class="border-border flex shrink-0 items-center gap-1 overflow-hidden border-t p-2">' . $profile('start', 'up') . $theme . $logoutBtn . '</div></aside>';
        $out .= '<div class="gp-topbar border-border bg-background/90 sticky top-0 z-30 flex h-14 items-center gap-2 border-b px-3 backdrop-blur-md lg:hidden">';
        $out .= '<button type="button" class="' . btn_class('ghost', 'icon') . '" data-open-nav aria-label="Открыть меню">' . icon('menu', 'size-5') . '</button>';
        $out .= '<a href="' . h(url_to('/')) . '" class="flex items-center transition-opacity hover:opacity-75">' . brand_logo('h-4 w-auto') . '</a>';
        if ($current) {
            $out .= '<span class="text-muted-foreground ml-1 truncate text-sm">' . h($current['label']) . '</span>';
        }
        $out .= '<div class="ml-auto flex items-center gap-1">' . $theme . $logoutBtn . $profile('end', 'down') . '</div></div>';
        $out .= '<div class="fixed inset-0 z-50 hidden lg:hidden" data-nav-drawer><button type="button" class="bg-foreground/40 absolute inset-0 backdrop-blur-sm" data-close-nav aria-label="Закрыть меню"></button>';
        $out .= '<div class="bg-sidebar text-sidebar-foreground border-border absolute inset-y-0 left-0 flex w-72 max-w-[85vw] flex-col border-r">' . $mobile . '</div></div>';
        $out .= $inner . '</div>';
        return $out;
    }

    private static function sidebar(array $user, array $counts, string $path, bool $close): string
    {
        $html = '<div class="border-border flex h-14 shrink-0 items-center gap-2 border-b px-4">';
        $html .= '<a href="' . h(url_to('/')) . '" class="flex items-center transition-opacity hover:opacity-75">' . brand_logo('h-4 w-auto') . '</a>';
        if ($close) {
            $html .= '<button type="button" class="' . btn_class('ghost', 'icon') . ' ml-auto" data-close-nav>' . icon('x', 'size-5') . '</button>';
        }
        $html .= '</div><nav class="flex-1 overflow-y-auto px-2 py-3" aria-label="Разделы портала"><ul class="flex flex-col gap-4">';
        foreach (NAV_GROUPS as $group) {
            if (!empty($group['ownerOnly']) && !$user['isOwner']) {
                continue;
            }
            $items = array_filter($group['items'], fn ($it) => empty($it['ownerOnly']) || $user['isOwner']);
            if (!$items) {
                continue;
            }
            $html .= '<li><p class="flex items-center gap-2 px-3 pb-1.5"><span class="text-muted-foreground/80 text-[0.66rem] font-semibold uppercase tracking-[0.16em]">' . h($group['label']) . '</span><span class="bg-border h-px min-w-4 flex-1"></span></p><ul class="flex flex-col gap-0.5">';
            $activeItem = find_nav_item($path);
            foreach ($items as $item) {
                $active = $activeItem && $activeItem['href'] === $item['href'];
                $count = !empty($item['badge']) ? (int) ($counts[$item['badge']] ?? 0) : 0;
                $cls = $active
                    ? 'bg-sidebar-accent text-sidebar-accent-foreground'
                    : 'text-muted-foreground hover:bg-muted hover:text-foreground';
                $html .= '<li><a href="' . h(url_to($item['href'])) . '" class="group relative flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium transition-colors ' . $cls . '"' . ($active ? ' aria-current="page"' : '') . '>';
                if ($active) {
                    $html .= '<span class="bg-primary absolute inset-y-1.5 left-0 w-0.5 rounded-full"></span>';
                }
                $html .= icon($item['icon'], 'size-4 shrink-0' . ($active ? ' text-primary' : ''));
                $html .= '<span class="min-w-0 flex-1 truncate">' . h($item['label']) . '</span>';
                if ($count > 0) {
                    $html .= '<span class="bg-primary text-primary-foreground min-w-5 shrink-0 rounded-full px-1.5 py-0.5 text-center text-[0.7rem] font-semibold tabular-nums">' . ($count > 99 ? '99+' : $count) . '</span>';
                }
                $html .= '</a></li>';
            }
            $html .= '</ul></li>';
        }
        $html .= '</ul></nav>';
        return $html;
    }

    private static function pageSignIn(): void
    {
        if (self::user()) {
            redirect_to('/');
        }
        $flash = flash_take();
        $err = ($flash['type'] ?? '') === 'error' ? $flash['msg'] : null;
        $html = '<main class="bg-background text-foreground flex min-h-dvh flex-col items-center justify-center gap-6 px-4 py-10"><div class="flex w-full max-w-sm flex-col gap-6">';
        $html .= '<div class="flex flex-col items-center gap-5 text-center">' . brand_logo('h-11 w-auto', true) . '</div>';
        $html .= '<div class="bg-card border-border rounded-xl border p-6 shadow-sm"><form method="post" class="flex flex-col gap-5">' . csrf_field();
        $html .= '<input type="hidden" name="_action" value="login">';
        $html .= '<div class="flex flex-col gap-2"><label class="flex items-center gap-2 text-sm font-medium" for="login">Логин</label><input id="login" name="login" class="' . input_class('h-11') . '" required autocomplete="username" autocapitalize="none"></div>';
        $passReq = ' required';
        $html .= '<div class="flex flex-col gap-2"><label class="flex items-center gap-2 text-sm font-medium" for="password">Пароль</label><div class="relative"><input id="password" name="password" type="password" class="' . input_class('h-11 pr-11') . '"' . $passReq . ' autocomplete="current-password"><button type="button" class="text-muted-foreground hover:text-foreground absolute inset-y-0 right-0 flex w-11 items-center justify-center" data-toggle-pass>' . icon('eye') . '</button></div></div>';
        if ($err) {
            $html .= '<p role="alert" class="bg-destructive/10 text-destructive rounded-md px-3 py-2 text-sm">' . h($err) . '</p>';
        }
        $html .= '<button class="' . btn_class() . ' h-11 w-full text-base">Войти</button></form></div>';
        $html .= '<p class="text-muted-foreground text-center text-xs leading-relaxed">Логин и пароль выдаёт управляющий. Если не можете войти — напишите ему.</p></div></main>';
        self::layoutGuest($html);
    }

    private static function greeting(DateTimeImmutable $now): string
    {
        $h = (int) $now->format('G');
        if ($h < 5) {
            return 'Доброй ночи';
        }
        if ($h < 12) {
            return 'Доброе утро';
        }
        if ($h < 18) {
            return 'Добрый день';
        }
        return 'Добрый вечер';
    }

    private static function pageHome(array $user): void
    {
        $now = now_tyumen();
        $today = date_key($now);
        $wd = monday_first_weekday((int) $now->format('Y'), (int) $now->format('n') - 1, (int) $now->format('j'));
        $first = explode(' ', trim($user['employeeName'] ?? $user['name']))[0];
        $greet = self::greeting($now);
        $dateLabel = mb_strtoupper(mb_substr(WEEKDAY_LONG[$wd], 0, 1)) . mb_substr(WEEKDAY_LONG[$wd], 1) . ', ' . (int) $now->format('j') . ' ' . MONTH_NAMES_GENITIVE[(int) $now->format('n') - 1];
        $branches = self::branches();

        if ($user['isOwner']) {
            $incidents = Db::all(
                'SELECT i.*, b.name AS "branchName" FROM incidents i JOIN branches b ON b.id = i."branchId" WHERE i.status != ? ORDER BY i."createdAt" DESC',
                ['done'],
            );
            $news = (int) (self::counts($user)['news']);
            $ann = Db::all('SELECT * FROM announcements WHERE "isDraft" = 0 ORDER BY "isPinned" DESC, "createdAt" DESC LIMIT 8');
            $on = self::liveShifts();
            self::layout($user, self::ownerDash($greet, $first, $dateLabel, $now, $branches, $on, $incidents, $ann, $news));
            return;
        }

        $my = self::myShift($user);
        $open = (int) (Db::one('SELECT COUNT(*) c FROM incidents WHERE status != ?', ['done'])['c'] ?? 0);
        $unread = (int) (self::counts($user)['news']);
        $html = '<main class="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 py-6 sm:px-6 sm:py-8">';
        $html .= '<header class="flex flex-col gap-1"><p class="text-muted-foreground text-sm">' . h($dateLabel) . '</p>' . self::clockLine($now, $my) . '<h1 class="text-2xl font-semibold tracking-tight text-balance sm:text-3xl">' . h($greet) . ', ' . h($first) . '</h1></header>';
        $shiftBox = $my
            ? 'border-primary/25 from-primary/8 via-card to-card bg-gradient-to-br shadow-sm'
            : 'border-border bg-card';
        $html .= '<section class="relative overflow-hidden rounded-xl border p-4 sm:p-5 ' . $shiftBox . '">';
        if ($my) {
            $ic = $my['shiftType'] === 'night' ? 'moon-star' : 'sun';
            $html .= '<div class="flex flex-col gap-3"><div class="flex items-center gap-2">' . icon($ic, 'text-primary size-5') . '<h2 class="text-sm font-medium tracking-wide uppercase">Моя смена</h2></div>';
            $html .= '<div class="flex flex-wrap items-baseline gap-x-3 gap-y-1"><span class="text-2xl font-semibold tracking-tight">' . h($my['branchName']) . '</span><span class="text-muted-foreground text-base sm:text-lg">' . h(SHIFT_LABELS[$my['shiftType']] ?? $my['shiftType']) . ', ' . h(SHIFT_HINTS[$my['shiftType']] ?? '') . '</span></div>';
            if ($my['date'] !== $today) {
                $html .= '<p class="text-sm">Смена с <span class="font-medium">' . h(format_date_human($my['date'])) . '</span> — после полуночи задание не сменяется, пока идёт ночь.</p>';
            }
            $html .= '</div>';
        } else {
            $html .= '<div class="flex flex-col gap-2"><h2 class="text-muted-foreground text-sm font-medium tracking-wide uppercase">Моя смена</h2><p class="text-sm leading-relaxed">Сегодня смены за вами нет.</p><a class="text-primary inline-flex items-center gap-1 text-sm font-medium hover:underline" href="' . h(url_to('/schedule')) . '">' . icon('calendar') . ' Открыть график смен ' . icon('arrow', 'size-3.5') . '</a></div>';
        }
        $html .= '</section>';
        if ($my && $user['employeeId']) {
            $tasks = self::staffTasksFor((int) $user['employeeId'], $my['date'], $user['id']);
            $html .= self::dayTasksCard($tasks, $my['date'], (string) (SHIFT_LABELS[$my['shiftType']] ?? ''));
        }
        if ($unread > 0 || $open > 0) {
            $html .= '<section class="flex flex-col gap-2"><h2 class="text-muted-foreground text-xs font-medium tracking-wide uppercase">Требует внимания</h2>';
            if ($unread > 0) {
                $html .= '<a href="' . h(url_to('/news')) . '" class="border-border bg-card hover:bg-muted/60 flex items-center gap-3 rounded-xl border px-4 py-3 text-sm shadow-sm"><span class="bg-primary/10 text-primary flex size-9 shrink-0 items-center justify-center rounded-lg">' . icon('megaphone') . '</span><span class="font-medium">' . h(plural($unread, 'новое объявление', 'новых объявления', 'новых объявлений')) . '</span>' . icon('arrow', 'text-muted-foreground ml-auto size-4') . '</a>';
            }
            if ($open > 0) {
                $html .= '<a href="' . h(url_to('/issues')) . '" class="border-border bg-card hover:bg-muted/60 flex items-center gap-3 rounded-xl border px-4 py-3 text-sm shadow-sm"><span class="bg-primary/10 text-primary flex size-9 shrink-0 items-center justify-center rounded-lg">' . icon('alert') . '</span><span class="font-medium">' . h(plural($open, 'незакрытая поломка', 'незакрытые поломки', 'незакрытых поломок')) . '</span>' . icon('arrow', 'text-muted-foreground ml-auto size-4') . '</a>';
            }
            $html .= '</section>';
        }
        $html .= '<a href="' . h(url_to('/schedule')) . '" class="border-border bg-card hover:bg-muted/60 flex items-center gap-3 rounded-xl border px-4 py-3 text-sm shadow-sm">' . icon('calendar', 'text-primary size-4') . '<span class="font-medium">Мои смены на месяц</span>' . icon('arrow', 'text-muted-foreground ml-auto size-4') . '</a>';
        $html .= '</main>';
        self::layout($user, $html);
    }

    private static function dayTasksCard(array $tasks, string $date, string $shiftLabel): string
    {
        $sub = format_date_human($date) . ($shiftLabel !== '' ? ' · ' . $shiftLabel : '');
        if (!$tasks) {
            return '<section class="border-border bg-muted/30 rounded-xl border border-dashed p-4 sm:p-5"><div class="flex items-center gap-2 pb-2">' . icon('clipboard-list', 'text-muted-foreground size-5') . '<h2 class="text-muted-foreground text-sm font-medium tracking-wide uppercase">Задание на смену</h2></div><p class="text-muted-foreground text-xs pb-2">' . h($sub) . '</p><p class="text-muted-foreground text-sm">На эту смену задание не назначено.</p></section>';
        }
        $html = '<section class="gp-day-task rounded-xl border p-4 shadow-sm sm:p-5"><div class="flex flex-col gap-1 pb-3"><div class="flex items-center gap-2">' . icon('clipboard-list', 'text-primary size-5') . '<h2 class="text-sm font-medium tracking-wide uppercase">Задание на смену</h2></div><p class="text-muted-foreground text-xs">' . h($sub) . '</p></div><ul class="flex flex-col gap-3">';
        foreach ($tasks as $t) {
            $mine = !empty($t['done_me']);
            $html .= '<li class="flex flex-col gap-2"><div class="flex items-start justify-between gap-3"><div class="gp-day-task-body min-w-0 flex-1">';
            $html .= '<p class="gp-day-task-title">' . h($t['title']) . '</p>';
            if ($t['description']) {
                $html .= '<p class="gp-day-task-desc whitespace-pre-wrap">' . h($t['description']) . '</p>';
            }
            if ($mine) {
                $html .= '<p class="gp-day-task-done">Выполнено</p>';
            }
            $html .= '</div>';
            $html .= '<form method="post">' . csrf_field() . '<input type="hidden" name="taskId" value="' . (int) $t['id'] . '">';
            if ($mine) {
                $html .= '<input type="hidden" name="_action" value="uncomplete_task"><button class="' . btn_class('outline', 'sm') . '">Снять</button>';
            } else {
                $html .= '<input type="hidden" name="_action" value="complete_task"><button class="' . btn_class('default', 'sm') . '">' . icon('check', 'size-4') . ' Выполнил</button>';
            }
            $html .= '</form></div></li>';
        }
        return $html . '</ul></section>';
    }

    private static function todayOnShift(array $rows, int $year, int $month, int $day, array $branches): string
    {
        $order = array_map(fn ($b) => $b['name'], $branches);
        $by = [];
        foreach ($order as $n) {
            $by[$n] = [];
        }
        foreach ($rows as $r) {
            $by[$r['branchName']][] = $r;
        }
        $html = '<section class="border-border bg-card rounded-xl border p-4 shadow-sm sm:p-5"><div class="flex flex-wrap items-center gap-x-2 gap-y-1">' . icon('users', 'text-primary size-5') . '<h2 class="text-sm font-medium tracking-wide uppercase">Сегодня на смене</h2><span class="text-muted-foreground text-xs">' . h(format_long_date($year, $month, $day)) . '</span></div>';
        if (!$order) {
            return $html . '<p class="text-muted-foreground mt-3 text-sm">На сегодня смены не расставлены.</p></section>';
        }
        $html .= '<ul class="mt-4 flex flex-col gap-4">';
        foreach ($order as $name) {
            $list = $by[$name] ?? [];
            $dayP = array_filter($list, fn ($r) => in_array($r['shiftType'], ['day', 'full'], true));
            $nightP = array_filter($list, fn ($r) => in_array($r['shiftType'], ['night', 'full'], true));
            $html .= '<li class="flex flex-col gap-2"><h3 class="text-base font-semibold tracking-tight">' . h($name) . '</h3><div class="grid grid-cols-1 gap-2 sm:grid-cols-2">';
            $html .= self::shiftSlot('day', $dayP) . self::shiftSlot('night', $nightP);
            $html .= '</div></li>';
        }
        return $html . '</ul></section>';
    }

    private static function shiftSlot(string $kind, array $people): string
    {
        $ic = $kind === 'day' ? 'sun' : 'moon-star';
        $label = $kind === 'day' ? 'День' : 'Ночь';
        $hint = SHIFT_HINTS[$kind];
        $box = $kind === 'day'
            ? 'border-shift-day-foreground/20 bg-shift-day/40'
            : 'border-shift-night-foreground/20 bg-shift-night/40';
        $html = '<div class="rounded-lg border px-3 py-2.5 ' . $box . '"><div class="flex flex-wrap items-center gap-x-2 gap-y-1 pb-1">' . icon($ic, 'size-4') . '<span class="text-sm font-medium">' . $label . '</span><span class="text-muted-foreground text-xs">' . h($hint) . '</span></div>';
        if (!$people) {
            $html .= '<p class="text-muted-foreground text-sm">никого</p>';
        } else {
            $html .= '<div class="flex flex-col gap-1">';
            foreach ($people as $p) {
                $html .= '<p class="gp-break text-sm font-medium">' . h($p['employeeName']) . '</p>';
            }
            $html .= '</div>';
        }
        return $html . '</div>';
    }

    private static function ownerDash(string $greet, string $first, string $dateLabel, DateTimeImmutable $now, array $branches, array $on, array $incidents, array $ann, int $unread): string
    {
        $y = (int) $now->format('Y');
        $m = (int) $now->format('n') - 1;
        $d = (int) $now->format('j');
        $today = date_key($now);
        $html = '<div class="mx-auto flex w-full max-w-5xl flex-col gap-8 px-4 py-6 sm:px-6 sm:py-8">';
        $html .= '<header class="flex flex-col gap-1"><p class="text-muted-foreground text-sm">' . h($dateLabel) . '</p>' . self::clockLine($now) . '<h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">' . h($greet) . ', ' . h($first) . '</h1></header>';
        $html .= '<section class="grid grid-cols-1 gap-3 sm:grid-cols-2">';
        $newsCls = $unread > 0 ? 'border-primary/30 from-primary/5 bg-gradient-to-br to-card' : 'border-border bg-card';
        $html .= '<a href="' . h(url_to('/news')) . '" class="' . $newsCls . ' hover:bg-muted/50 flex min-h-[5.5rem] flex-col justify-center gap-1 rounded-xl border px-5 py-4 shadow-sm"><div class="flex items-center gap-2">' . icon('megaphone', 'text-primary size-5') . '<span class="text-muted-foreground text-xs font-medium tracking-wide uppercase">Объявления</span></div><span class="text-xl font-semibold tracking-tight">' . ($unread > 0 ? h(plural($unread, 'новое', 'новых', 'новых')) : 'Всё прочитано') . '</span><span class="text-primary text-sm font-medium">Открыть раздел →</span></a>';
        $issCls = $incidents ? 'border-destructive/25 from-destructive/5 bg-gradient-to-br to-card' : 'border-border bg-card';
        $html .= '<a href="' . h(url_to('/issues')) . '" class="' . $issCls . ' hover:bg-muted/50 flex min-h-[5.5rem] flex-col justify-center gap-1 rounded-xl border px-5 py-4 shadow-sm"><div class="flex items-center gap-2">' . icon('alert', 'text-destructive size-5') . '<span class="text-muted-foreground text-xs font-medium tracking-wide uppercase">Поломки</span></div><span class="text-xl font-semibold tracking-tight">' . ($incidents ? h(plural(count($incidents), 'открытая', 'открытые', 'открытых')) : 'Всё закрыто') . '</span><span class="text-primary text-sm font-medium">Открыть журнал →</span></a></section>';
        $html .= '<section class="border-border bg-card rounded-2xl border p-5 shadow-sm sm:p-6"><div class="flex flex-wrap items-center gap-x-3 gap-y-1 pb-5">' . icon('users', 'text-primary size-6') . '<h2 class="text-base font-semibold tracking-tight">На смене</h2><span class="text-muted-foreground text-sm">' . h(format_long_date($y, $m, $d)) . ' · время клуба, Тюмень</span></div>';
        $html .= self::todayOnShiftInner($on, $branches);
        $html .= '<a href="' . h(url_to('/schedule')) . '" class="text-primary mt-5 inline-flex items-center gap-1.5 text-sm font-medium hover:underline">График смен на месяц ' . icon('arrow', 'size-4') . '</a></section>';

        $html .= '<section class="flex flex-col gap-4"><div class="flex flex-wrap items-center justify-between gap-2"><h2 class="text-muted-foreground text-xs font-medium tracking-wide uppercase">Задания на текущие смены</h2><a class="text-primary inline-flex items-center gap-1 text-sm font-medium hover:underline" href="' . h(url_to('/manage/tasks')) . '">' . icon('pencil', 'size-3.5') . ' Назначить</a></div><div class="grid grid-cols-1 gap-3 lg:grid-cols-2">';
        if (!$on) {
            $html .= '<p class="text-muted-foreground text-sm">Никого нет в смене.</p>';
        }
        foreach ($on as $p) {
            $tasks = self::staffTasksFor((int) $p['employeeId'], $p['date']);
            $nightCarry = $p['date'] !== $today && in_array($p['shiftType'], ['night', 'full'], true);
            $html .= '<div class="border-border bg-card rounded-xl border p-4 shadow-sm"><div class="flex flex-wrap items-baseline justify-between gap-2 pb-2"><p class="font-semibold">' . h($p['employeeName']) . '</p><p class="text-muted-foreground text-xs">' . h($p['branchName']) . ' · ' . h(SHIFT_LABELS[$p['shiftType']] ?? '') . ($nightCarry ? ' · ещё с ' . h(format_date_human($p['date'])) : '') . '</p></div>';
            if (!$tasks) {
                $html .= '<p class="text-muted-foreground text-sm">Задание не назначено.</p>';
            } else {
                $html .= '<ul class="flex flex-col gap-1.5">';
                foreach ($tasks as $t) {
                    $html .= '<li><p class="font-medium text-sm">' . h($t['title']) . '</p>' . ($t['description'] ? '<p class="text-muted-foreground text-xs whitespace-pre-wrap">' . h($t['description']) . '</p>' : '') . '</li>';
                }
                $html .= '</ul>';
            }
            $html .= '</div>';
        }
        $html .= '</div></section>';

        $html .= '<section class="flex flex-col gap-4"><h2 class="text-muted-foreground text-xs font-medium tracking-wide uppercase">Проблемы и важное</h2><div class="grid grid-cols-1 gap-4 lg:grid-cols-2">';
        $html .= '<div class="border-border bg-card rounded-xl border p-4 shadow-sm"><div class="flex items-center gap-2 pb-3">' . icon('alert', 'text-destructive size-4') . '<h3 class="font-semibold text-sm">Открытые поломки</h3></div>';
        if (!$incidents) {
            $html .= '<p class="text-muted-foreground text-sm">Всё закрыто.</p>';
        } else {
            $html .= '<ul class="flex flex-col gap-2">';
            foreach (array_slice($incidents, 0, 8) as $i) {
                $html .= '<li class="text-sm"><a class="hover:underline" href="' . h(url_to('/issues')) . '">' . h($i['title']) . '</a> <span class="text-muted-foreground">· ' . h($i['branchName']) . '</span></li>';
            }
            $html .= '</ul>';
        }
        $html .= '</div><div class="border-border bg-card rounded-xl border p-4 shadow-sm"><div class="flex items-center gap-2 pb-3">' . icon('megaphone', 'text-primary size-4') . '<h3 class="font-semibold text-sm">Объявления</h3></div>';
        if (!$ann) {
            $html .= '<p class="text-muted-foreground text-sm">Пока тихо.</p>';
        } else {
            $html .= '<ul class="flex flex-col gap-2">';
            foreach (array_slice($ann, 0, 4) as $a) {
                $html .= '<li class="text-sm"><a class="hover:underline font-medium" href="' . h(url_to('/news')) . '">' . h($a['title']) . '</a></li>';
            }
            $html .= '</ul>';
        }
        $html .= '</div></div></section></div>';
        return $html;
    }

    private static function todayOnShiftInner(array $rows, array $branches): string
    {
        $order = array_map(fn ($b) => $b['name'], $branches);
        $by = [];
        foreach ($order as $n) {
            $by[$n] = [];
        }
        foreach ($rows as $r) {
            $by[$r['branchName']][] = $r;
        }
        if (!$order) {
            return '<p class="text-muted-foreground text-base">На сегодня смены не расставлены.</p>';
        }
        $html = '<ul class="flex flex-col gap-6">';
        foreach ($order as $name) {
            $list = $by[$name] ?? [];
            $dayP = array_values(array_filter($list, fn ($r) => in_array($r['shiftType'], ['day', 'full'], true)));
            $nightP = array_values(array_filter($list, fn ($r) => in_array($r['shiftType'], ['night', 'full'], true)));
            $html .= '<li class="flex flex-col gap-3"><h3 class="text-lg font-semibold tracking-tight">' . h($name) . '</h3><div class="grid grid-cols-1 gap-3 sm:grid-cols-2">' . self::ownerSlot('day', $dayP) . self::ownerSlot('night', $nightP) . '</div></li>';
        }
        return $html . '</ul>';
    }

    private static function ownerSlot(string $kind, array $people): string
    {
        $ic = $kind === 'day' ? 'sun' : 'moon-star';
        $box = $kind === 'day' ? 'border-shift-day-foreground/25 bg-shift-day/50' : 'border-shift-night-foreground/25 bg-shift-night/50';
        $html = '<div class="rounded-xl border px-4 py-3 ' . $box . '"><div class="flex flex-wrap items-center gap-x-2 gap-y-1 pb-2">' . icon($ic, 'size-5') . '<span class="font-medium">' . ($kind === 'day' ? 'День' : 'Ночь') . '</span><span class="text-muted-foreground text-sm">' . h(SHIFT_HINTS[$kind]) . '</span></div>';
        if (!$people) {
            return $html . '<p class="text-muted-foreground text-sm">никого</p></div>';
        }
        $html .= '<div class="flex flex-col gap-1">';
        foreach ($people as $p) {
            $today = date_key(now_tyumen());
            $n = $p['employeeName'];
            if (($p['date'] ?? '') !== $today && in_array($p['shiftType'] ?? '', ['night', 'full'], true)) {
                $n .= ' · ещё с ' . format_date_human($p['date']);
            }
            $html .= '<p class="gp-break text-base font-semibold">' . h($n) . '</p>';
        }
        $html .= '</div></div>';
        return $html;
    }
}
