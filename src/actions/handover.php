<?php
declare(strict_types=1);

trait ActionsHandover
{
    /** Сумма в рублях из поля формы: null — поле пустое, false — не похоже на сумму. */
    private static function cashInput(string $key): int|false|null
    {
        $raw = preg_replace('/[\s\x{00A0}\x{202F}₽]+/u', '', self::postStr($key, 32)) ?? '';
        if ($raw === '') {
            return null;
        }
        if (!preg_match('/^\d{1,8}$/', $raw) || (int) $raw > 10_000_000) {
            return false;
        }
        return (int) $raw;
    }

    private static function doSaveHandover(array $user): void
    {
        $phase = self::postStr('phase', 8);
        if (!isset(HANDOVER_PHASES[$phase])) {
            self::fail('Форма устарела — обновите страницу и попробуйте ещё раз.', '/shift');
        }
        $shift = self::handoverShift($user);
        $branchId = self::postInt('branchId');
        $date = self::postStr('date', 10);
        $type = self::postStr('shiftType', 16);
        if (!$shift || (int) $shift['branchId'] !== $branchId || $shift['date'] !== $date || $shift['shiftType'] !== $type) {
            self::fail('Чек-лист этой смены уже закрыт: его заполняют во время своей смены и в течение трёх часов после неё.', '/shift');
        }

        $expected = $actual = null;
        $note = '';
        if ($phase === 'close') {
            $expected = self::cashInput('cashExpected');
            $actual = self::cashInput('cashActual');
            if ($expected === false || $actual === false) {
                form_stash_set('handover_close', $_POST);
                self::fail('Суммы по кассе — целые рубли без копеек, например 12 300.', '/shift');
            }
            $note = self::postStr('noteForNext', 2000);
        }

        $items = self::checklistItems()[$phase];
        $checked = array_map('intval', array_filter((array) ($_POST['check'] ?? []), 'is_scalar'));
        $doneCount = count(array_filter($items, static fn ($it) => in_array($it['id'], $checked, true)));
        $name = self::displayName($user);
        $key = [$branchId, $date, $type, $phase];

        $isNew = Db::tx(static function () use ($key, $user, $name, $expected, $actual, $note, $items, $checked): bool {
            $find = 'SELECT id FROM shift_reports WHERE "branchId" = ? AND date = ? AND "shiftType" = ? AND phase = ?';
            $id = Db::value($find, $key);
            $isNew = $id === null;
            if ($isNew) {
                // Уникальный индекс по смене и фазе: если напарник сохранил одновременно, строка уже есть — обновим её.
                Db::insertIgnore('shift_reports', [
                    'branchId' => $key[0], 'date' => $key[1], 'shiftType' => $key[2], 'phase' => $key[3],
                    'userId' => $user['id'], 'userName' => $name, 'cashExpected' => $expected, 'cashActual' => $actual,
                    'noteForNext' => $note, 'createdAt' => now_iso(),
                ]);
                $id = Db::value($find, $key);
            }
            $id = (int) $id;
            // createdAt хранит время последнего сохранения: сменщик и управляющий видят актуальные отметки.
            Db::exec(
                'UPDATE shift_reports SET "userId" = ?, "userName" = ?, "cashExpected" = ?, "cashActual" = ?, "noteForNext" = ?, "createdAt" = ? WHERE id = ?',
                [$user['id'], $name, $expected, $actual, $note, now_iso(), $id],
            );
            Db::exec('DELETE FROM shift_report_checks WHERE "reportId" = ?', [$id]);
            foreach ($items as $it) {
                Db::insert('shift_report_checks', [
                    'reportId' => $id,
                    'itemId' => $it['id'],
                    'done' => in_array($it['id'], $checked, true) ? 1 : 0,
                    'comment' => '',
                ]);
            }
            return $isNew;
        });

        if (random_int(1, 20) === 1) {
            $cut = add_days(date_key(now_tyumen()), -70);
            Db::exec('DELETE FROM shift_report_checks WHERE "reportId" IN (SELECT id FROM shift_reports WHERE date < ?)', [$cut]);
            Db::exec('DELETE FROM shift_reports WHERE date < ?', [$cut]);
        }

        $summary = ($phase === 'open' ? 'Смена принята: ' : 'Смена сдана: ') . $shift['branchName'] . ' · ' . (SHIFT_LABELS[$type] ?? $type) . ' · ' . format_day_month($date);
        if ($items) {
            $summary .= ' · чек-лист ' . $doneCount . '/' . count($items);
        }
        if ($phase === 'close' && $actual !== null) {
            [$diffText, $diffTone] = cash_diff($expected, $actual);
            $summary .= ' · касса ' . money_rub($actual) . ($diffTone !== '' ? ' (' . $diffText . ')' : '');
        }
        audit($user, 'handover', $isNew ? 'create' : 'update', ($isNew ? '' : 'Исправлено. ') . $summary, url_to('/shift', ['branch' => $shift['branchSlug']]));

        $missing = count($items) - $doneCount;
        $msg = $isNew ? ($phase === 'open' ? 'Смена принята' : 'Смена сдана') : 'Отметки обновлены';
        if ($missing > 0) {
            $msg .= ' · не отмечено пунктов: ' . $missing;
        }
        flash_set('ok', $msg);
        redirect_to('/shift');
    }
}
