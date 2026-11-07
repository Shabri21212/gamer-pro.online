<?php
declare(strict_types=1);

trait ActionsClub
{
    private static function doSaveIncident(array $user): void
    {
        $branch = self::branchById(self::postInt('branchId'));
        $kind = self::postStr('kind', 16);
        $title = self::postStr('title', 200);
        if (!$branch) {
            self::fail('Выберите филиал', '/issues');
        }
        if (!isset(INCIDENT_KIND[$kind])) {
            self::fail('Выберите, что случилось: поломка или происшествие', '/issues');
        }
        if (mb_strlen($title) < 3) {
            self::fail('Опишите проблему хотя бы парой слов', '/issues');
        }
        $pcRaw = preg_replace('/\D+/', '', self::postStr('pcNumber', 8)) ?? '';
        $pc = $pcRaw !== '' ? (int) $pcRaw : null;
        if ($pc !== null && ($pc < 1 || $pc > max(1, (int) $branch['pcCount']))) {
            self::fail('В филиале ' . $branch['name'] . ' нет ПК №' . $pc, '/issues');
        }
        Db::insert('incidents', [
            'branchId' => $branch['id'],
            'kind' => $kind,
            'pcNumber' => $pc,
            'title' => $title,
            'details' => self::postStr('details', 2000),
            'status' => 'new',
            'createdByUserId' => $user['id'],
            'createdByName' => self::displayName($user),
            'createdAt' => now_iso(),
            'resolutionNote' => '',
        ]);
        audit($user, 'issues', 'create', INCIDENT_KIND[$kind] . ': ' . $title . ' · ' . $branch['name'] . ($pc ? ' · ПК ' . $pc : ''), url_to('/issues'));
        flash_set('ok', $kind === 'tech' ? 'Заявка отправлена — ответственный увидит её в разделе «Поломки»' : 'Происшествие записано');
        redirect_to('/issues');
    }

    private static function doIncidentStatus(array $user): void
    {
        $row = Db::one('SELECT id, "branchId", title, status, "createdByUserId" FROM incidents WHERE id = ?', [self::postInt('id')]);
        if (!$row) {
            self::fail('Заявка не найдена', '/issues');
        }
        $status = self::postStr('status', 16);
        if (!isset(INCIDENT_STATUS[$status])) {
            self::fail('Неизвестный статус', '/issues');
        }
        $branchId = (int) $row['branchId'];
        $isAuthor = $row['createdByUserId'] === $user['id'];
        if (!can($user, 'issues.resolve', $branchId) && !($isAuthor && $status === 'done')) {
            self::permOrFail($user, 'issues.resolve', $branchId, '/issues');
        }
        $name = self::displayName($user);
        if ($status === 'done') {
            Db::exec(
                'UPDATE incidents SET status = ?, "resolvedByUserId" = ?, "resolvedByName" = ?, "resolvedAt" = ?, "resolutionNote" = ? WHERE id = ?',
                ['done', $user['id'], $name, now_iso(), self::postStr('note', 1000), (int) $row['id']],
            );
            $label = 'закрыта';
        } elseif ($status === 'in_progress') {
            Db::exec(
                'UPDATE incidents SET status = ?, "resolvedByUserId" = ?, "resolvedByName" = ?, "resolvedAt" = NULL WHERE id = ?',
                ['in_progress', $user['id'], $name, (int) $row['id']],
            );
            $label = 'взята в работу';
        } else {
            Db::exec(
                'UPDATE incidents SET status = ?, "resolvedByUserId" = NULL, "resolvedByName" = NULL, "resolvedAt" = NULL, "resolutionNote" = ? WHERE id = ?',
                ['new', '', (int) $row['id']],
            );
            $label = 'открыта снова';
        }
        audit($user, 'issues', $status, 'Заявка ' . $label . ': ' . $row['title'] . ' · ' . self::branchName($branchId), url_to('/issues'));
        flash_set('ok', 'Заявка ' . $label);
        redirect_back('/issues');
    }

    private static function doSaveNews(array $user): void
    {
        self::permOrFail($user, 'news.publish', null, '/news');
        $title = self::postStr('title', 200);
        if (mb_strlen($title) < 3) {
            self::fail('Нужен заголовок хотя бы из трёх букв', '/news');
        }
        $id = Db::insert('announcements', [
            'title' => $title,
            'body' => self::postStr('body', 5000),
            'isPinned' => isset($_POST['isPinned']) ? 1 : 0,
            'isDraft' => 0,
            'createdByUserId' => $user['id'],
            'createdByName' => self::displayName($user),
            'createdAt' => now_iso(),
            'updatedAt' => now_iso(),
        ]);
        Db::insertIgnore('announcement_reads', ['announcementId' => $id, 'userId' => $user['id'], 'userName' => self::displayName($user), 'readAt' => now_iso()]);
        audit($user, 'news', 'create', 'Опубликовано: ' . $title, url_to('/news', ['open' => $id]));
        flash_set('ok', 'Объявление опубликовано');
        redirect_to('/news');
    }

    private static function doReadNews(array $user): void
    {
        $row = Db::one('SELECT id, "isDraft" FROM announcements WHERE id = ?', [self::postInt('id')]);
        if ($row && !boolish($row['isDraft'])) {
            Db::insertIgnore('announcement_reads', [
                'announcementId' => (int) $row['id'],
                'userId' => $user['id'],
                'userName' => self::displayName($user),
                'readAt' => now_iso(),
            ]);
        }
        redirect_back('/news');
    }

    private static function doPinNews(array $user): void
    {
        self::permOrFail($user, 'news.publish', null, '/news');
        $row = Db::one('SELECT id, title, "isPinned" FROM announcements WHERE id = ?', [self::postInt('id')]);
        if (!$row) {
            self::fail('Объявление не найдено', '/news');
        }
        $pin = boolish($row['isPinned']) ? 0 : 1;
        Db::exec('UPDATE announcements SET "isPinned" = ?, "updatedAt" = ? WHERE id = ?', [$pin, now_iso(), (int) $row['id']]);
        audit($user, 'news', $pin ? 'pin' : 'unpin', ($pin ? 'Закреплено: ' : 'Откреплено: ') . $row['title'], url_to('/news'));
        flash_set('ok', $pin ? 'Объявление закреплено наверху' : 'Объявление откреплено');
        redirect_back('/news');
    }

    private static function doDeleteNews(array $user): void
    {
        self::permOrFail($user, 'news.publish', null, '/news');
        $row = Db::one('SELECT id, title FROM announcements WHERE id = ?', [self::postInt('id')]);
        if (!$row) {
            self::fail('Объявление уже удалено', '/news');
        }
        Db::tx(static function () use ($row): void {
            Db::exec('DELETE FROM announcement_reads WHERE "announcementId" = ?', [(int) $row['id']]);
            Db::exec('DELETE FROM announcements WHERE id = ?', [(int) $row['id']]);
        });
        audit($user, 'news', 'delete', 'Удалено объявление: ' . $row['title'], url_to('/news'));
        flash_set('ok', 'Объявление удалено');
        redirect_to('/news');
    }
}
