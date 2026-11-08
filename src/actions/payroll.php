<?php
declare(strict_types=1);

trait ActionsPayroll
{
    private static function doSavePayroll(array $user): void
    {
        self::requirePerm($user, 'owner');
        $id = self::postInt('employeeId');
        $month = self::postStr('month', 7);
        if (!preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/D', $month) || !Db::one('SELECT id FROM employees WHERE id = ?', [$id])) {
            self::fail('Некорректный сотрудник или месяц', '/manage/payroll');
        }
        try {
            $percent = Payroll::decimal(self::postStr('percent', 20), 10000);
            $sales = Payroll::decimal(self::postStr('sales', 20), 99999999900);
            $note = self::postStr('note', 500);
            if ($sales !== null && mb_strlen($note) < 3) {
                throw new InvalidArgumentException('Для ручной суммы продаж укажите источник или причину корректировки.');
            }
            $key = Payroll::key($month, $id);
            $value = json_encode(['percentX100' => $percent, 'salesX100' => $sales, 'note' => $note, 'updatedBy' => self::displayName($user), 'updatedAt' => now_iso()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $current = Db::one('SELECT "valueJson" FROM portal_content WHERE "key" = ?', [$key]);
            $revision = $current ? hash('sha256', (string) $current['valueJson']) : '';
            if (!hash_equals($revision, self::postStr('revision', 64))) {
                throw new InvalidArgumentException('Начисления уже изменены в другой вкладке. Обновите страницу и повторите.');
            }
            if ($current && $current['valueJson'] !== $value) {
                $statement = Db::pdo()->prepare('UPDATE portal_content SET "valueJson" = ?, "updatedAt" = ? WHERE "key" = ? AND "valueJson" = ?');
                $statement->execute([$value, now_iso(), $key, $current['valueJson']]);
                if ($statement->rowCount() !== 1) throw new InvalidArgumentException('Данные изменились. Обновите страницу.');
            } elseif (!$current) {
                try {
                    Db::insert('portal_content', ['key' => $key, 'valueJson' => $value, 'updatedAt' => now_iso()]);
                } catch (PDOException $e) {
                    if (Db::one('SELECT "key" FROM portal_content WHERE "key" = ?', [$key])) {
                        throw new InvalidArgumentException('Данные изменились. Обновите страницу.');
                    }
                    throw $e;
                }
            }
            audit($user, 'staff', 'payroll', 'Настройки начислений: сотрудник #' . $id . ', ' . $month . '; процент ' . ($percent === null ? 'из Langame' : $percent / 100 . '%') . '; продажи ' . ($sales === null ? 'из Langame' : Payroll::money($sales)), url_to('/manage/payroll', ['m' => $month]));
            flash_set('ok', 'Настройки сохранены для выбранного месяца');
        } catch (InvalidArgumentException $e) {
            flash_set('error', $e->getMessage());
        }
        redirect_to('/manage/payroll', ['m' => $month]);
    }
}
