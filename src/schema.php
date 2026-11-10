<?php
declare(strict_types=1);

/**
 * Единое описание таблиц портала. Из него собираются:
 *  - таблицы SQLite (локальная разработка, запасной вариант без config.php);
 *  - таблицы MySQL/MariaDB (хостинг reg.ru);
 *  - дамп database/localhost.sql для импорта через phpMyAdmin.
 *
 * Типы колонок: pk — автоинкрементный id; key — строковый первичный ключ;
 * int, bigint, bool; str:N — строка до N символов; text, long — длинный текст;
 * uid — id пользователя; date — дата YYYY-MM-DD; ts — метка времени ISO.
 * «=значение» в конце — NOT NULL DEFAULT значение, без него колонка допускает NULL.
 *
 * Индексы: имя => 'колонка,колонка'; «!» в начале — уникальный.
 * Все строковые колонки в MySQL — utf8mb4_bin: сравнение и сортировка как в SQLite.
 */
require_once __DIR__ . '/branch-upgrade.php';

const SCHEMA_VERSION = 9;

const SCHEMA = [
    'app_meta' => [
        'columns' => ['k' => 'key', 'v' => 'text'],
    ],
    'user' => [
        'columns' => [
            'id' => 'key', 'name' => 'str:191', 'email' => 'str:191', 'emailVerified' => 'bool=0', 'image' => 'text',
            'role' => 'str:20=admin', 'permissions' => 'text', 'scopeBranchId' => 'int', 'createdAt' => 'ts', 'updatedAt' => 'ts',
        ],
        'indexes' => ['email' => '!email'],
    ],
    'account' => [
        'columns' => [
            'id' => 'key', 'accountId' => 'str:191', 'providerId' => 'str:64', 'userId' => 'uid', 'accessToken' => 'text',
            'refreshToken' => 'text', 'idToken' => 'text', 'accessTokenExpiresAt' => 'ts', 'refreshTokenExpiresAt' => 'ts',
            'scope' => 'text', 'password' => 'text', 'password_php' => 'str:255', 'createdAt' => 'ts', 'updatedAt' => 'ts',
        ],
        'indexes' => ['user' => 'userId'],
    ],
    'branches' => [
        'columns' => ['id' => 'pk', 'name' => 'str:191', 'slug' => 'str:64', 'pcCount' => 'int=40', 'sortOrder' => 'int=0', 'createdAt' => 'ts'],
        'indexes' => ['slug' => '!slug'],
    ],
    'employees' => [
        'columns' => [
            'id' => 'pk', 'name' => 'str:191', 'homeBranchId' => 'int', 'userId' => 'uid', 'isActive' => 'bool=1',
            'gradeId' => 'int', 'sortOrder' => 'int=0', 'createdAt' => 'ts',
        ],
        'indexes' => ['user' => 'userId', 'branch' => 'homeBranchId,sortOrder'],
    ],
    'employee_grades' => [
        'columns' => ['id' => 'pk', 'title' => 'str:191', 'shiftRate' => 'int=0', 'sortOrder' => 'int=0', 'createdAt' => 'ts'],
    ],
    'employee_grade_history' => [
        'columns' => [
            'id' => 'pk', 'employeeId' => 'int', 'gradeTitle' => 'str:191', 'shiftRate' => 'int=0',
            'changedByUserId' => 'uid', 'changedByName' => 'str:191', 'changedAt' => 'ts',
        ],
        'indexes' => ['employee' => 'employeeId,changedAt'],
    ],
    'shifts' => [
        'columns' => [
            'id' => 'pk', 'date' => 'date', 'branchId' => 'int', 'employeeId' => 'int', 'shiftType' => 'str:16=day',
            'note' => 'text', 'createdAt' => 'ts', 'updatedAt' => 'ts',
        ],
        'indexes' => ['date' => 'date', 'emp_date' => 'employeeId,date', 'branch_date' => 'branchId,date'],
    ],
    'staff_tasks' => [
        'columns' => [
            'id' => 'pk', 'employeeId' => 'int', 'date' => 'date', 'title' => 'str:255', 'description' => 'text',
            'createdByUserId' => 'uid', 'createdByName' => 'str:191', 'createdAt' => 'ts',
        ],
        'indexes' => ['emp_date' => 'employeeId,date', 'date' => 'date'],
    ],
    'staff_task_done' => [
        'columns' => ['id' => 'pk', 'taskId' => 'int', 'userId' => 'uid', 'userName' => 'str:191', 'completedAt' => 'ts'],
        'indexes' => ['task_user' => '!taskId,userId'],
    ],
    'daily_tasks' => [
        'columns' => [
            'id' => 'pk', 'weekday' => 'int=0', 'branchId' => 'int', 'title' => 'str:255', 'description' => 'text',
            'isActive' => 'bool=1', 'sortOrder' => 'int=0', 'createdAt' => 'ts',
        ],
    ],
    'daily_task_completions' => [
        'columns' => [
            'id' => 'pk', 'taskId' => 'int', 'userId' => 'uid', 'userName' => 'str:191', 'branchId' => 'int',
            'date' => 'date', 'completedAt' => 'ts',
        ],
        'indexes' => ['task_date' => 'taskId,date'],
    ],
    'cleaning_seat_assignments' => [
        'columns' => ['id' => 'pk', 'branchId' => 'int', 'weekStart' => 'date', 'employeeId' => 'int', 'pcNumber' => 'int', 'createdAt' => 'ts'],
        'indexes' => ['seat' => '!branchId,weekStart,pcNumber', 'week_emp' => 'weekStart,employeeId'],
    ],
    'keyboard_cleanings' => [
        'columns' => [
            'id' => 'pk', 'branchId' => 'int', 'pcNumber' => 'int', 'weekStart' => 'date',
            'doneByUserId' => 'uid', 'doneByName' => 'str:191', 'doneAt' => 'ts',
        ],
        'indexes' => ['week' => 'branchId,weekStart'],
    ],
    'incidents' => [
        'columns' => [
            'id' => 'pk', 'branchId' => 'int', 'kind' => 'str:32=incident', 'pcNumber' => 'int', 'title' => 'str:255',
            'details' => 'text', 'status' => 'str:32=new', 'createdByUserId' => 'uid', 'createdByName' => 'str:191',
            'createdAt' => 'ts', 'resolvedByUserId' => 'uid', 'resolvedByName' => 'str:191', 'resolvedAt' => 'ts',
            'resolutionNote' => 'text',
        ],
        'indexes' => ['branch_status' => 'branchId,status', 'status' => 'status'],
    ],
    'announcements' => [
        'columns' => [
            'id' => 'pk', 'title' => 'str:255', 'body' => 'text', 'isPinned' => 'bool=0', 'isDraft' => 'bool=0',
            'createdByUserId' => 'uid', 'createdByName' => 'str:191', 'createdAt' => 'ts', 'updatedAt' => 'ts',
        ],
    ],
    'announcement_reads' => [
        'columns' => ['id' => 'pk', 'announcementId' => 'int', 'userId' => 'uid', 'userName' => 'str:191', 'readAt' => 'ts'],
        'indexes' => ['ann_user' => '!announcementId,userId', 'user' => 'userId,announcementId'],
    ],
    'review_bonuses' => [
        'columns' => [
            'id' => 'pk', 'branchId' => 'int', 'guestPhone' => 'str:20', 'reviewKind' => 'str:32=text', 'bonusAmount' => 'int=0',
            'status' => 'str:32=pending', 'note' => 'text', 'createdByUserId' => 'uid', 'createdByName' => 'str:191',
            'createdAt' => 'ts', 'decidedByUserId' => 'uid', 'decidedByName' => 'str:191', 'decidedAt' => 'ts',
        ],
        'indexes' => ['phone' => 'branchId,guestPhone', 'status' => 'status'],
    ],
    'compensations' => [
        'columns' => [
            'id' => 'pk', 'branchId' => 'int', 'guestPhone' => 'str:20', 'reason' => 'text', 'actionsTaken' => 'text',
            'bonusAmount' => 'int=0', 'status' => 'str:32=pending', 'createdByUserId' => 'uid', 'createdByName' => 'str:191',
            'createdAt' => 'ts', 'decidedByUserId' => 'uid', 'decidedByName' => 'str:191', 'decidedAt' => 'ts',
        ],
        'indexes' => ['status' => 'status'],
    ],
    'loyalty_tiers' => [
        'columns' => [
            'id' => 'pk', 'title' => 'str:191', 'minHours' => 'int=0', 'maxHours' => 'int', 'cashbackPercent' => 'int=0',
            'birthdayBonus' => 'int=0', 'sortOrder' => 'int=0',
        ],
    ],
    'portal_content' => [
        'columns' => ['key' => 'key', 'valueJson' => 'long', 'updatedAt' => 'ts'],
    ],
    'knowledge_categories' => [
        'columns' => ['id' => 'pk', 'title' => 'str:191', 'slug' => 'str:64', 'sortOrder' => 'int=0', 'createdAt' => 'ts'],
        'indexes' => ['slug' => 'slug'],
    ],
    'knowledge_articles' => [
        'columns' => [
            'id' => 'pk', 'categoryId' => 'int', 'title' => 'str:255', 'slug' => 'str:128', 'contentHtml' => 'long',
            'kind' => 'str:32=article', 'isDraft' => 'bool=0', 'sortOrder' => 'int=0', 'updatedByUserId' => 'uid',
            'createdAt' => 'ts', 'updatedAt' => 'ts',
        ],
        'indexes' => ['slug' => '!slug', 'category' => 'categoryId,sortOrder'],
    ],
    'fine_rules' => [
        'columns' => [
            'id' => 'pk', 'violation' => 'text', 'primaryAmount' => 'int', 'primaryDismissal' => 'bool=0',
            'repeatAmount' => 'int', 'repeatDismissal' => 'bool=0', 'sortOrder' => 'int=0', 'isActive' => 'bool=1',
            'createdAt' => 'ts', 'updatedAt' => 'ts',
        ],
    ],
    'employee_fines' => [
        'columns' => [
            'id' => 'pk', 'employeeId' => 'int', 'employeeName' => 'str:191', 'ruleId' => 'int', 'reason' => 'text',
            'amount' => 'int=0', 'createdByUserId' => 'uid', 'createdByName' => 'str:191', 'createdAt' => 'ts',
        ],
        'indexes' => ['employee' => 'employeeId,createdAt'],
    ],
    'exams' => [
        'columns' => [
            'id' => 'pk', 'title' => 'str:255', 'slug' => 'str:128', 'intro' => 'text', 'passPercent' => 'int=80',
            'shuffle' => 'bool=1', 'isDraft' => 'bool=1', 'sortOrder' => 'int=0', 'xpReward' => 'int=100',
            'updatedByUserId' => 'uid', 'createdAt' => 'ts', 'updatedAt' => 'ts',
        ],
        'indexes' => ['slug' => '!slug'],
    ],
    'exam_questions' => [
        'columns' => [
            'id' => 'pk', 'examId' => 'int', 'prompt' => 'text', 'kind' => 'str:32=single', 'explanation' => 'text', 'sortOrder' => 'int=0',
        ],
        'indexes' => ['exam' => 'examId,sortOrder'],
    ],
    'exam_options' => [
        'columns' => ['id' => 'pk', 'questionId' => 'int', 'label' => 'text', 'isCorrect' => 'bool=0', 'sortOrder' => 'int=0'],
        'indexes' => ['question' => 'questionId,sortOrder'],
    ],
    'exam_attempts' => [
        'columns' => [
            'id' => 'pk', 'examId' => 'int', 'userId' => 'uid', 'status' => 'str:32=in_progress', 'scorePercent' => 'int',
            'earnedPoints' => 'int=0', 'maxPoints' => 'int=0', 'startedAt' => 'ts', 'submittedAt' => 'ts',
            'reviewedByUserId' => 'uid', 'reviewedAt' => 'ts', 'reviewNote' => 'text',
        ],
        'indexes' => ['user_exam' => 'userId,examId', 'exam' => 'examId'],
    ],
    'exam_answers' => [
        'columns' => [
            'id' => 'pk', 'attemptId' => 'int', 'questionId' => 'int', 'selectedOptionIds' => 'text', 'textAnswer' => 'text', 'isCorrect' => 'bool',
        ],
        'indexes' => ['attempt' => 'attemptId'],
    ],
    'shift_checklist_items' => [
        'columns' => ['id' => 'pk', 'title' => 'str:255', 'phase' => 'str:16', 'sortOrder' => 'int=0', 'isActive' => 'bool=1'],
    ],
    'shift_reports' => [
        'columns' => [
            'id' => 'pk', 'branchId' => 'int', 'date' => 'date', 'shiftType' => 'str:16', 'phase' => 'str:16', 'userId' => 'uid',
            'userName' => 'str:191', 'cashExpected' => 'int', 'cashActual' => 'int', 'noteForNext' => 'text', 'createdAt' => 'ts',
        ],
        'indexes' => ['shift' => '!branchId,date,shiftType,phase', 'date' => 'date'],
    ],
    'shift_report_checks' => [
        'columns' => ['id' => 'pk', 'reportId' => 'int', 'itemId' => 'int', 'done' => 'bool=0', 'comment' => 'text'],
        'indexes' => ['report' => 'reportId'],
    ],
    'audit_log' => [
        'columns' => [
            'id' => 'pk', 'actorUserId' => 'uid', 'actorName' => 'str:191', 'actorRole' => 'str:20', 'entity' => 'str:32',
            'action' => 'str:40', 'summary' => 'text', 'href' => 'str:255=/', 'createdAt' => 'ts',
        ],
        'indexes' => ['created' => 'createdAt', 'entity' => 'entity,createdAt', 'actor' => 'actorUserId,createdAt'],
    ],
    'login_attempts' => [
        'columns' => ['id' => 'pk', 'ip' => 'str:64', 'login' => 'str:191', 'createdAt' => 'bigint'],
        'indexes' => ['ip' => 'ip,createdAt', 'login' => 'login,createdAt'],
    ],
    'langame_settings' => [
        'columns' => [
            'id' => 'pk', 'branchId' => 'int', 'baseUrl' => 'str:255', 'tokenEnc' => 'text', 'tokenHint' => 'str:16',
            'barPercentX10' => 'int=0', 'isEnabled' => 'bool=0', 'lastSyncAt' => 'ts', 'lastSyncOk' => 'bool=0',
            'lastError' => 'text', 'syncedFrom' => 'date', 'updatedAt' => 'ts', 'liveJson' => 'text', 'liveAt' => 'bigint',
        ],
        'indexes' => ['branch' => '!branchId'],
    ],
    'langame_staff' => [
        'columns' => [
            'id' => 'pk', 'branchId' => 'int', 'langameUserId' => 'int', 'langameName' => 'str:191', 'langameLogin' => 'str:191',
            'employeeId' => 'int', 'percentX10' => 'int', 'updatedAt' => 'ts',
            'isActive' => 'bool=1', 'adminStatus' => 'str:64', 'lastLoginAt' => 'bigint',
        ],
        'indexes' => ['branch_user' => '!branchId,langameUserId', 'employee' => 'employeeId'],
    ],
    'langame_shifts' => [
        'columns' => ['id' => 'pk', 'branchId' => 'int', 'extId' => 'int', 'langameUserId' => 'int', 'dateStart' => 'str:32', 'dateStop' => 'str:32'],
        'indexes' => ['branch_ext' => '!branchId,extId', 'branch_user' => 'branchId,langameUserId'],
    ],
    'langame_products' => [
        'columns' => ['id' => 'pk', 'branchId' => 'int', 'extId' => 'int', 'name' => 'str:191'],
        'indexes' => ['branch_ext' => '!branchId,extId'],
    ],
    'bar_sales' => [
        'columns' => [
            'id' => 'pk', 'branchId' => 'int', 'extId' => 'bigint', 'saleAt' => 'str:32', 'goodsId' => 'int', 'shiftExtId' => 'int',
            'unitPriceX100' => 'int=0', 'qty' => 'int=1', 'amountX100' => 'int=0', 'isCancelled' => 'bool=0', 'isCashless' => 'bool=0',
        ],
        'indexes' => ['branch_ext' => '!branchId,extId', 'branch_date' => 'branchId,saleAt', 'branch_shift' => 'branchId,shiftExtId'],
    ],
];

final class Schema
{
    /** @return array{base: string, len: ?int, default: ?string, nullable: bool} */
    public static function parse(string $spec): array
    {
        $parts = explode('=', $spec, 2);
        $type = explode(':', $parts[0], 2);
        return [
            'base' => $type[0],
            'len' => isset($type[1]) ? (int) $type[1] : null,
            'default' => $parts[1] ?? null,
            'nullable' => !isset($parts[1]) && !in_array($type[0], ['pk', 'key'], true),
        ];
    }

    private static function isNumeric(string $base): bool
    {
        return in_array($base, ['pk', 'int', 'bigint', 'bool'], true);
    }

    private static function mysqlType(array $c): string
    {
        return match ($c['base']) {
            'pk' => 'int unsigned NOT NULL AUTO_INCREMENT',
            'key' => 'varchar(64) NOT NULL',
            'int' => 'int',
            'bigint' => 'bigint',
            'bool' => 'tinyint(1)',
            'str' => 'varchar(' . ($c['len'] ?? 191) . ')',
            'long' => 'mediumtext',
            'uid' => 'varchar(64)',
            'date' => 'varchar(32)',
            'ts' => 'varchar(40)',
            default => 'text',
        };
    }

    private static function defaultSql(array $c): string
    {
        if ($c['default'] === null) {
            return '';
        }
        if (self::isNumeric($c['base'])) {
            return ' NOT NULL DEFAULT ' . (int) $c['default'];
        }
        return " NOT NULL DEFAULT '" . str_replace("'", "''", $c['default']) . "'";
    }

    public static function mysqlColumn(string $name, string $spec): string
    {
        $c = self::parse($spec);
        $sql = '`' . $name . '` ' . self::mysqlType($c);
        if ($c['base'] === 'pk' || $c['base'] === 'key') {
            return $sql;
        }
        return $sql . ($c['default'] !== null ? self::defaultSql($c) : ' DEFAULT NULL');
    }

    public static function sqliteColumn(string $name, string $spec): string
    {
        $c = self::parse($spec);
        $sql = '"' . $name . '" ';
        if ($c['base'] === 'pk') {
            return $sql . 'INTEGER PRIMARY KEY AUTOINCREMENT';
        }
        if ($c['base'] === 'key') {
            return $sql . 'TEXT PRIMARY KEY';
        }
        return $sql . (self::isNumeric($c['base']) ? 'INTEGER' : 'TEXT') . self::defaultSql($c);
    }

    /** @return array<string, array{unique: bool, cols: string[]}> */
    public static function indexes(string $table): array
    {
        $out = [];
        foreach (SCHEMA[$table]['indexes'] ?? [] as $name => $def) {
            $unique = str_starts_with($def, '!');
            $out[$table . '_' . $name . ($unique ? '_uq' : '_idx')] = [
                'unique' => $unique,
                'cols' => explode(',', ltrim($def, '!')),
            ];
        }
        return $out;
    }

    private static function primaryKey(string $table): ?string
    {
        foreach (SCHEMA[$table]['columns'] as $name => $spec) {
            $base = self::parse($spec)['base'];
            if ($base === 'pk' || $base === 'key') {
                return $name;
            }
        }
        return null;
    }

    public static function mysqlCreate(string $table, bool $ifNotExists = false): string
    {
        $lines = [];
        foreach (SCHEMA[$table]['columns'] as $name => $spec) {
            $lines[] = '  ' . self::mysqlColumn($name, $spec);
        }
        if ($pk = self::primaryKey($table)) {
            $lines[] = '  PRIMARY KEY (`' . $pk . '`)';
        }
        foreach (self::indexes($table) as $name => $idx) {
            $lines[] = '  ' . ($idx['unique'] ? 'UNIQUE KEY' : 'KEY') . ' `' . $name . '` (`' . implode('`,`', $idx['cols']) . '`)';
        }
        return 'CREATE TABLE ' . ($ifNotExists ? 'IF NOT EXISTS ' : '') . '`' . $table . "` (\n" . implode(",\n", $lines)
            . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin";
    }

    public static function sqliteCreate(string $table): string
    {
        $cols = [];
        foreach (SCHEMA[$table]['columns'] as $name => $spec) {
            $cols[] = self::sqliteColumn($name, $spec);
        }
        return 'CREATE TABLE IF NOT EXISTS "' . $table . '" (' . implode(', ', $cols) . ')';
    }

    /** @return array<string, true> имена колонок в нижнем регистре; пусто — таблицы нет */
    private static function existingColumns(PDO $pdo, string $driver, string $table): array
    {
        $out = [];
        if ($driver === 'mysql') {
            $st = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $st->execute([$table]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $c) {
                $out[strtolower((string) $c)] = true;
            }
            return $out;
        }
        foreach ($pdo->query('PRAGMA table_info("' . $table . '")')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[strtolower((string) $r['name'])] = true;
        }
        return $out;
    }

    /** @return array<string, true> */
    private static function existingIndexes(PDO $pdo, string $driver, string $table): array
    {
        $out = [];
        if ($driver === 'mysql') {
            $st = $pdo->prepare('SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $st->execute([$table]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $n) {
                $out[(string) $n] = true;
            }
            return $out;
        }
        foreach ($pdo->query('PRAGMA index_list("' . $table . '")')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['name']] = true;
        }
        return $out;
    }

    public static function storedVersion(PDO $pdo): int
    {
        try {
            $st = $pdo->query('SELECT v FROM app_meta WHERE k = \'schema\'');
            $v = $st ? $st->fetchColumn() : false;
            return $v === false ? 0 : (int) $v;
        } catch (PDOException) {
            return 0;
        }
    }

    /**
     * Доводит базу до SCHEMA: создаёт недостающие таблицы, колонки и индексы.
     * Существующие данные не трогает. Каждый шаг отдельно — ошибка одного не мешает остальным.
     */
    public static function migrate(PDO $pdo, string $driver): void
    {
        if (self::storedVersion($pdo) >= SCHEMA_VERSION) {
            return;
        }
        $failed = 0;
        foreach (array_keys(SCHEMA) as $table) {
            try {
                $cols = self::existingColumns($pdo, $driver, $table);
                if (!$cols) {
                    if ($driver === 'mysql') {
                        $pdo->exec(self::mysqlCreate($table, true));
                        continue;
                    }
                    $pdo->exec(self::sqliteCreate($table));
                } else {
                    foreach (SCHEMA[$table]['columns'] as $name => $spec) {
                        if (isset($cols[strtolower($name)])) {
                            continue;
                        }
                        $pdo->exec($driver === 'mysql'
                            ? 'ALTER TABLE `' . $table . '` ADD COLUMN ' . self::mysqlColumn($name, $spec)
                            : 'ALTER TABLE "' . $table . '" ADD COLUMN ' . self::sqliteColumn($name, $spec));
                    }
                }
                $have = self::existingIndexes($pdo, $driver, $table);
                foreach (self::indexes($table) as $name => $idx) {
                    if (isset($have[$name])) {
                        continue;
                    }
                    try {
                        $pdo->exec($driver === 'mysql'
                            ? 'ALTER TABLE `' . $table . '` ADD ' . ($idx['unique'] ? 'UNIQUE ' : '') . 'INDEX `' . $name . '` (`' . implode('`,`', $idx['cols']) . '`)'
                            : 'CREATE ' . ($idx['unique'] ? 'UNIQUE ' : '') . 'INDEX IF NOT EXISTS "' . $name . '" ON "' . $table . '" ("' . implode('","', $idx['cols']) . '")');
                    } catch (PDOException $e) {
                        error_log('[gamerpro] index ' . $name . ': ' . $e->getMessage());
                    }
                }
            } catch (PDOException $e) {
                $failed++;
                error_log('[gamerpro] migrate ' . $table . ': ' . $e->getMessage());
            }
        }
        if ($failed > 0) {
            return;
        }
        try {
            BranchUpgrade::run($pdo);
            $pdo->prepare('DELETE FROM app_meta WHERE k = ?')->execute(['schema']);
            $pdo->prepare('INSERT INTO app_meta (k, v) VALUES (?, ?)')->execute(['schema', (string) SCHEMA_VERSION]);
        } catch (PDOException $e) {
            error_log('[gamerpro] schema version: ' . $e->getMessage());
        }
    }

    /** Значение из исходной базы, приведённое к типу колонки MySQL. */
    private static function coerce(mixed $v, array $c): mixed
    {
        if ($v === null || ($v === '' && self::isNumeric($c['base']))) {
            if ($c['default'] === null) {
                return null;
            }
            return self::isNumeric($c['base']) ? (int) $c['default'] : $c['default'];
        }
        if ($c['base'] === 'bool') {
            return ($v === true || $v === 1 || $v === '1' || $v === 't' || $v === 'true') ? 1 : 0;
        }
        if (self::isNumeric($c['base'])) {
            return is_numeric($v) ? (int) $v : ($c['default'] !== null ? (int) $c['default'] : null);
        }
        $s = (string) $v;
        if ($c['base'] === 'str' && $c['len'] !== null && mb_strlen($s) > $c['len']) {
            $s = mb_substr($s, 0, $c['len']);
        }
        return $s;
    }

    private static function mysqlLiteral(mixed $v): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (is_int($v)) {
            return (string) $v;
        }
        return "'" . strtr((string) $v, ["\\" => "\\\\", "\0" => "\\0", "\n" => "\\n", "\r" => "\\r", "'" => "\\'", "\x1a" => "\\Z"]) . "'";
    }

    /** Таблицы, которые есть в исходной базе. */
    private static function sourceTables(PDO $pdo, string $driver): array
    {
        $rows = $driver === 'mysql'
            ? $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_COLUMN)
            : $pdo->query('SELECT name FROM sqlite_master WHERE type = \'table\'')->fetchAll(PDO::FETCH_COLUMN);
        return array_fill_keys(array_map('strval', $rows), true);
    }

    /**
     * Полный дамп для MySQL/MariaDB из любой базы портала (SQLite или MySQL).
     * $write получает куски SQL по мере чтения — память не растёт на больших таблицах.
     * @return array<string, int> сколько строк выгружено по таблицам
     */
    public static function dumpMysql(PDO $source, string $driver, callable $write, string $sourceLabel = ''): array
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Yekaterinburg'));
        $write("-- GAMER PRO CRM · база данных для MySQL 8 / MariaDB\n");
        $write('-- Создано: ' . $now->format('d.m.Y H:i') . ' (Екатеринбург)' . ($sourceLabel !== '' ? ' · источник: ' . $sourceLabel : '') . "\n");
        $write("--\n-- Как залить на reg.ru: phpMyAdmin → слева выбрать базу (например u3622481_default) → вкладка «Импорт»\n");
        $write("-- → выбрать этот файл → «Вперёд». Затем в config.php на хостинге указать доступы к этой базе.\n");
        $write("-- Таблицы портала создаются заново: если они уже были в базе, их содержимое заменится данными из файла.\n\n");
        $write("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\nSET time_zone = '+00:00';\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");

        $have = self::sourceTables($source, $driver);
        $counts = [];
        foreach (SCHEMA as $table => $def) {
            $write("-- --------------------------------------------------------\n-- Таблица `" . $table . "`\n\n");
            $write('DROP TABLE IF EXISTS `' . $table . "`;\n" . self::mysqlCreate($table) . ";\n\n");
            $counts[$table] = 0;
            $specs = array_map([self::class, 'parse'], $def['columns']);
            $names = array_keys($def['columns']);
            if ($table === 'app_meta') {
                $write("INSERT INTO `app_meta` (`k`, `v`) VALUES ('schema', '" . SCHEMA_VERSION . "');\n\n");
                $counts[$table] = 1;
                continue;
            }
            if (!isset($have[$table])) {
                continue;
            }
            $pk = self::primaryKey($table);
            $order = $pk !== null ? ' ORDER BY "' . $pk . '"' : '';
            $st = $source->query('SELECT * FROM "' . $table . '"' . $order);
            $head = 'INSERT INTO `' . $table . '` (`' . implode('`, `', $names) . "`) VALUES\n";
            $batch = [];
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $lower = array_change_key_case($row, CASE_LOWER);
                $vals = [];
                foreach ($names as $i => $col) {
                    $raw = array_key_exists($col, $row) ? $row[$col] : ($lower[strtolower($col)] ?? null);
                    $vals[] = self::mysqlLiteral(self::coerce($raw, $specs[$col]));
                }
                $batch[] = '(' . implode(', ', $vals) . ')';
                $counts[$table]++;
                if (count($batch) >= 100) {
                    $write($head . implode(",\n", $batch) . ";\n");
                    $batch = [];
                }
            }
            if ($batch) {
                $write($head . implode(",\n", $batch) . ";\n");
            }
            $write("\n");
        }
        $write("SET FOREIGN_KEY_CHECKS = 1;\n");
        return $counts;
    }
}
