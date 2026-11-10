<?php
declare(strict_types=1);

/**
 * Права учётных записей. scoped — право можно ограничить одним филиалом
 * (поле scopeBranchId у пользователя): например, старший админ Киевской
 * составляет график только Киевской.
 */
const PERMISSIONS = [
    'schedule.view_all' => ['group' => 'Смены', 'label' => 'Видеть графики всех филиалов', 'hint' => 'Без этого права сотрудник видит график только своего филиала и свои подмены. Редактирование выдаётся отдельно.', 'scoped' => false],
    'schedule.edit' => ['group' => 'Смены', 'label' => 'Составлять график смен', 'hint' => 'Ставить, менять и убирать смены, копировать неделю', 'scoped' => true],
    'tasks.assign' => ['group' => 'Смены', 'label' => 'Назначать задания на смену', 'hint' => 'Раздел «Задания на смены»', 'scoped' => true],
    'cleaning.assign' => ['group' => 'Смены', 'label' => 'Распределять чистку клавиатур', 'hint' => 'Закреплять места и снимать чужие отметки', 'scoped' => true],
    'issues.resolve' => ['group' => 'Клуб', 'label' => 'Вести поломки', 'hint' => 'Брать в работу и закрывать заявки', 'scoped' => true],
    'news.publish' => ['group' => 'Клуб', 'label' => 'Публиковать объявления', 'hint' => 'Писать, закреплять и удалять, видеть кто прочитал', 'scoped' => false],
    'guests.decide' => ['group' => 'Гости', 'label' => 'Зачислять бонусы и компенсации', 'hint' => 'Подтверждать или отклонять заявки', 'scoped' => true],
    'content.edit' => ['group' => 'Регламенты', 'label' => 'Менять тарифы и бонусную систему', 'hint' => 'Прайс, уровни кэшбека, условия акции за отзывы', 'scoped' => false],
    'wiki.edit' => ['group' => 'Регламенты', 'label' => 'Редактировать базу знаний', 'hint' => 'Создавать, править и удалять статьи', 'scoped' => false],
    'fines.manage' => ['group' => 'Регламенты', 'label' => 'Назначать штрафы', 'hint' => 'Выписывать и отменять штрафы, править лист', 'scoped' => false],
    'exams.manage' => ['group' => 'Регламенты', 'label' => 'Управлять экзаменами', 'hint' => 'Создавать тесты и смотреть результаты сотрудников', 'scoped' => false],
    'staff.manage' => ['group' => 'Управление', 'label' => 'Сотрудники, уровни и ставки', 'hint' => 'Карточки, филиалы, уровни и ставки за смену', 'scoped' => false],
    'journal.view' => ['group' => 'Управление', 'label' => 'Журнал действий', 'hint' => 'Кто, что и когда изменил в портале', 'scoped' => false],
];

const ROLES = [
    'owner' => [
        'label' => 'Владелец',
        'hint' => 'Всё, включая доступы и права других',
        'perms' => '*',
    ],
    'manager' => [
        'label' => 'Управляющий',
        'hint' => 'Всё, кроме доступов и паролей',
        'perms' => ['schedule.view_all', 'schedule.edit', 'tasks.assign', 'cleaning.assign', 'issues.resolve', 'news.publish', 'guests.decide', 'content.edit', 'wiki.edit', 'fines.manage', 'exams.manage', 'staff.manage', 'journal.view'],
    ],
    'senior' => [
        'label' => 'Старший администратор',
        'hint' => 'График, задания, чистка, поломки и объявления',
        'perms' => ['schedule.edit', 'tasks.assign', 'cleaning.assign', 'issues.resolve', 'news.publish'],
    ],
    'admin' => [
        'label' => 'Администратор',
        'hint' => 'Работа на смене, без управления',
        'perms' => [],
    ],
];

function normalize_role(?string $role): string
{
    return isset(ROLES[(string) $role]) ? (string) $role : 'admin';
}

function role_label(?string $role): string
{
    return ROLES[normalize_role($role)]['label'];
}

function role_preset(string $role): array
{
    $perms = ROLES[normalize_role($role)]['perms'];
    return $perms === '*' ? array_keys(PERMISSIONS) : $perms;
}

/** Права из строки "user": явный список, если задан, иначе шаблон роли. */
function perms_from_row(array $row): array
{
    $role = normalize_role($row['role'] ?? 'admin');
    if ($role === 'owner') {
        return array_keys(PERMISSIONS);
    }
    $raw = $row['permissions'] ?? null;
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return array_values(array_intersect(array_keys(PERMISSIONS), $decoded));
        }
    }
    return role_preset($role);
}

/**
 * Проверка права. Если передан филиал, а право ограничено филиалом пользователя,
 * разрешено только для этого филиала.
 */
function can(array $user, string $perm, ?int $branchId = null): bool
{
    if (!empty($user['isOwner'])) {
        return true;
    }
    if ($perm === 'owner' || !in_array($perm, $user['perms'] ?? [], true)) {
        return false;
    }
    if ($perm === 'schedule.edit' && $branchId !== null && !can_view_schedule($user, $branchId)) {
        return false;
    }
    $scope = $user['scopeBranchId'] ?? null;
    if ($branchId !== null && $scope !== null && !empty(PERMISSIONS[$perm]['scoped'])) {
        return (int) $scope === $branchId;
    }
    return true;
}

function can_any(array $user, array $perms): bool
{
    foreach ($perms as $p) {
        if (can($user, $p)) {
            return true;
        }
    }
    return false;
}

/** Филиал, которым ограничены права пользователя по $perm, или null — все филиалы. */
function perm_scope(array $user, string $perm): ?int
{
    if (!empty($user['isOwner']) || empty(PERMISSIONS[$perm]['scoped'])) {
        return null;
    }
    $scope = $user['scopeBranchId'] ?? null;
    return $scope === null ? null : (int) $scope;
}

/** Просмотр не расширяет права редактирования; без привязки доступ закрыт. */
function can_view_schedule(array $user, int $branchId): bool
{
    return can($user, 'schedule.view_all')
        || ($branchId > 0 && $branchId === (int) ($user['homeBranchId'] ?? 0));
}

const AUDIT_ENTITIES = [
    'schedule' => 'График',
    'handover' => 'Приём и сдача',
    'tasks' => 'Задания',
    'cleaning' => 'Чистка',
    'issues' => 'Поломки',
    'news' => 'Объявления',
    'guests' => 'Гости',
    'content' => 'Тарифы и акции',
    'wiki' => 'База знаний',
    'fines' => 'Штрафы',
    'exams' => 'Экзамены',
    'staff' => 'Сотрудники',
    'access' => 'Доступы',
];

function audit(array $user, string $entity, string $action, string $summary, string $href = '/'): void
{
    try {
        Db::exec(
            'INSERT INTO audit_log ("actorUserId","actorName","actorRole",entity,action,summary,href,"createdAt") VALUES (?,?,?,?,?,?,?,?)',
            [
                (string) $user['id'],
                mb_substr((string) ($user['employeeName'] ?? $user['name']), 0, 120),
                (string) ($user['role'] ?? 'admin'),
                $entity,
                mb_substr($action, 0, 40),
                mb_substr($summary, 0, 500),
                mb_substr($href, 0, 255),
                now_iso(),
            ],
        );
        if (random_int(1, 200) === 1) {
            Db::exec('DELETE FROM audit_log WHERE "createdAt" < ?', [now_tyumen()->modify('-365 days')->format('Y-m-d')]);
        }
    } catch (Throwable $e) {
        error_log('[gamerpro] audit failed: ' . $e->getMessage());
    }
}
