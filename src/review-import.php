<?php
declare(strict_types=1);

/**
 * Перенос в CRM бонусов, которые гости получили до начала учёта в портале.
 * Каждая строка попадает в «Бонусы за отзывы» как уже зачисленная: форма новой заявки сразу видит,
 * что номер бонус получал, а запись появляется в истории своего филиала.
 *
 * Повторный запуск безопасен: строка с тем же филиалом, номером и промокодом второй раз не добавляется.
 */
final class ReviewImport
{
    /** Значение review_bonuses.reviewKind у перенесённых строк: по нему журнал отличает их от заявок сотрудников. */
    public const KIND = 'import';
    /** Подпись в колонках «Добавил(а)» и «Статус» вместо имени сотрудника. */
    public const AUTHOR = 'Импорт';

    private const SEED = '/database/seeds/review-bonuses-gazovikov.php';

    /**
     * Обновление базы до версии 11: номера филиала «Газовиков» из таблицы промокодов.
     * Файла с номерами может уже не быть — его удаляют из репозитория после переноса; тогда шаг ничего не делает.
     * Испорченный файл тоже не должен ронять портал, поэтому наружу выходит только ошибка самой базы:
     * по ней Schema::migrate() оставит версию прежней и повторит шаг при следующем запросе.
     *
     * @return array{added: int, skipped: int}
     */
    public static function migrateV11(PDO $pdo, ?string $seedFile = null): array
    {
        $nothing = ['added' => 0, 'skipped' => 0];
        $seed = self::readSeed($seedFile ?? dirname(__DIR__) . self::SEED);
        if ($seed === null) {
            return $nothing;
        }
        $find = $pdo->prepare('SELECT id FROM branches WHERE slug = ?');
        $find->execute([$seed['branch']]);
        $branchId = $find->fetchColumn();
        if ($branchId === false) {
            error_log('[gamerpro] review import: нет филиала «' . $seed['branch'] . '»');
            return $nothing;
        }
        return self::import($pdo, (int) $branchId, $seed['rows']);
    }

    /**
     * Записывает строки как зачисленные бонусы филиала.
     *
     * @param array<int, mixed> $rows строки вида [телефон, дата YYYY-MM-DD, бонусов, промокод]
     * @return array{added: int, skipped: int} skipped — уже перенесённые и не прошедшие проверку строки
     */
    public static function import(PDO $pdo, int $branchId, array $rows): array
    {
        $exists = $pdo->prepare('SELECT 1 FROM review_bonuses WHERE "branchId" = ? AND "guestPhone" = ? AND note = ? LIMIT 1');
        $insert = $pdo->prepare(
            'INSERT INTO review_bonuses ("branchId", "guestPhone", "reviewKind", "bonusAmount", status, note, "createdByName", "createdAt", "decidedByName", "decidedAt")
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $added = 0;
        $skipped = 0;
        // Перенос идёт одной транзакцией: либо все строки, либо ни одной. Чужую транзакцию не трогаем.
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }
        try {
            foreach ($rows as $row) {
                $entry = self::normalizeRow($row);
                if ($entry === null) {
                    $skipped++;
                    continue;
                }
                $exists->execute([$branchId, $entry['phone'], $entry['note']]);
                $known = $exists->fetchColumn() !== false;
                $exists->closeCursor();
                if ($known) {
                    $skipped++;
                    continue;
                }
                $insert->execute([$branchId, $entry['phone'], self::KIND, $entry['amount'], 'credited', $entry['note'], self::AUTHOR, $entry['at'], self::AUTHOR, $entry['at']]);
                $added++;
            }
            if ($ownTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return ['added' => $added, 'skipped' => $skipped];
    }

    /** @return array{branch: string, rows: array<int, mixed>}|null */
    private static function readSeed(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        try {
            $seed = require $file;
        } catch (Throwable $e) {
            error_log('[gamerpro] review import: ' . basename($file) . ' — ' . $e->getMessage());
            return null;
        }
        if (!is_array($seed) || !is_string($seed['branch'] ?? null) || !is_array($seed['rows'] ?? null)) {
            error_log('[gamerpro] review import: неверный формат файла ' . basename($file));
            return null;
        }
        return ['branch' => $seed['branch'], 'rows' => $seed['rows']];
    }

    /** @return array{phone: string, at: string, amount: int, note: string}|null null — строку нельзя перенести */
    private static function normalizeRow(mixed $row): ?array
    {
        if (!is_array($row) || count($row) < 4) {
            return null;
        }
        [$rawPhone, $date, $amount, $promo] = array_values($row);
        if (!is_scalar($rawPhone) || !is_string($date) || !is_numeric($amount) || !is_scalar($promo)) {
            return null;
        }
        $phone = normalize_phone((string) $rawPhone);
        $promo = mb_substr(mb_strtoupper(trim((string) $promo)), 0, 64);
        if ($phone === null || (int) $amount <= 0 || $promo === '') {
            return null;
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        // В таблице есть только дата активации; полдень по времени клуба не сдвигает её ни в одном часовом поясе.
        $at = (new DateTimeImmutable($date . ' 12:00:00', new DateTimeZone(APP_TZ)))->format('Y-m-d\TH:i:s.000P');
        return ['phone' => $phone, 'at' => $at, 'amount' => (int) $amount, 'note' => 'Промокод ' . $promo];
    }
}
