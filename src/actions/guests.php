<?php
declare(strict_types=1);

trait ActionsGuests
{
    private static function doSaveReview(array $user): void
    {
        $phone = normalize_phone(self::postStr('guestPhone', 32));
        if (!$phone) {
            self::fail('Номер телефона указан неверно — нужно 10 цифр после +7', '/reviews');
        }
        $branch = self::branchById(self::postInt('branchId'));
        if (!$branch) {
            self::fail('Выберите филиал', '/reviews');
        }
        $promo = self::portal_json('review_promo', DEFAULT_REVIEW_PROMO);
        $amount = (int) $promo['textBonus'];
        if ($amount <= 0) {
            self::fail('В условиях акции не указана сумма бонуса — попросите руководителя заполнить её', '/reviews');
        }
        $existing = Db::one('SELECT status, "createdAt" FROM review_bonuses WHERE "branchId" = ? AND "guestPhone" = ? AND status != ?', [$branch['id'], $phone, 'rejected']);
        if ($existing) {
            self::fail(format_phone($phone) . ' уже получал бонус за отзыв в «' . $branch['name'] . '» (' . format_dt((string) $existing['createdAt'], false) . '). Повторно на этом филиале нельзя.', '/reviews');
        }
        Db::insert('review_bonuses', [
            'branchId' => $branch['id'],
            'guestPhone' => $phone,
            'reviewKind' => 'text',
            'bonusAmount' => $amount,
            'status' => 'pending',
            'note' => '',
            'createdByUserId' => $user['id'],
            'createdByName' => self::displayName($user),
            'createdAt' => now_iso(),
        ]);
        audit($user, 'guests', 'review', 'Заявка на бонус за отзыв: ' . format_phone($phone) . ' · ' . $amount . ' · ' . $branch['name'], url_to('/reviews', ['status' => 'pending']));
        flash_set('ok', 'Заявка отправлена: ' . $amount . ' бонусов для ' . format_phone($phone));
        redirect_back('/reviews');
    }

    private static function decideGuest(array $user, string $table, string $back, string $what): void
    {
        $row = Db::one('SELECT id, "branchId", "guestPhone", "bonusAmount", status FROM ' . $table . ' WHERE id = ?', [self::postInt('id')]);
        if (!$row) {
            self::fail('Заявка не найдена', $back);
        }
        self::permOrFail($user, 'guests.decide', (int) $row['branchId'], $back);
        $decision = self::postStr('decision', 16);
        if (!in_array($decision, ['credited', 'rejected'], true)) {
            self::fail('Неизвестное решение', $back);
        }
        if ($row['status'] !== 'pending') {
            self::fail('По этой заявке уже принято решение', $back);
        }
        Db::exec(
            'UPDATE ' . $table . ' SET status = ?, "decidedByUserId" = ?, "decidedByName" = ?, "decidedAt" = ? WHERE id = ?',
            [$decision, $user['id'], self::displayName($user), now_iso(), (int) $row['id']],
        );
        $phone = format_phone((string) $row['guestPhone']);
        audit($user, 'guests', $decision, $what . ': ' . $phone . ' · ' . (int) $row['bonusAmount'] . ' — ' . ($decision === 'credited' ? 'зачислено' : 'отказ'), url_to($back));
        flash_set('ok', $decision === 'credited' ? 'Отмечено: ' . (int) $row['bonusAmount'] . ' бонусов зачислено на ' . $phone : 'Заявка отклонена');
        redirect_back($back);
    }

    private static function doDecideReview(array $user): void
    {
        self::decideGuest($user, 'review_bonuses', '/reviews', 'Бонус за отзыв');
    }

    private static function doSaveComp(array $user): void
    {
        $phone = normalize_phone(self::postStr('guestPhone', 32));
        if (!$phone) {
            self::fail('Номер телефона указан неверно — нужно 10 цифр после +7', '/compensations');
        }
        $reason = self::postStr('reason', 1000);
        if (mb_strlen($reason) < 5) {
            self::fail('Опишите, что случилось у гостя', '/compensations');
        }
        $amount = (int) preg_replace('/\D+/', '', self::postStr('bonusAmount', 16));
        if ($amount < 1 || $amount > 5000) {
            self::fail('Сумма бонусов — целое число от 1 до 5000', '/compensations');
        }
        $branch = self::branchById(self::postInt('branchId'));
        if (!$branch) {
            self::fail('Выберите филиал', '/compensations');
        }
        Db::insert('compensations', [
            'branchId' => $branch['id'],
            'guestPhone' => $phone,
            'reason' => $reason,
            'actionsTaken' => self::postStr('actionsTaken', 1000),
            'bonusAmount' => $amount,
            'status' => 'pending',
            'createdByUserId' => $user['id'],
            'createdByName' => self::displayName($user),
            'createdAt' => now_iso(),
        ]);
        audit($user, 'guests', 'compensation', 'Компенсация ' . format_phone($phone) . ' · ' . $amount . ' · ' . $branch['name'] . ': ' . mb_substr($reason, 0, 120), url_to('/compensations', ['status' => 'pending']));
        flash_set('ok', 'Компенсация записана: ' . $amount . ' бонусов для ' . format_phone($phone));
        redirect_back('/compensations');
    }

    private static function doDecideComp(array $user): void
    {
        self::decideGuest($user, 'compensations', '/compensations', 'Компенсация');
    }

    private static function doSaveLoyalty(array $user): void
    {
        self::permOrFail($user, 'content.edit', null, '/loyalty/edit');
        $id = self::postInt('id');
        $title = self::postStr('title', 80);
        if ($title === '') {
            self::fail('Укажите название уровня', '/loyalty/edit');
        }
        $maxRaw = self::postStr('maxHours', 8);
        $row = [
            'title' => $title,
            'minHours' => max(0, self::postInt('minHours')),
            'maxHours' => $maxRaw === '' ? null : max(0, (int) $maxRaw),
            'cashbackPercent' => min(100, max(0, self::postInt('cashbackPercent'))),
            'birthdayBonus' => max(0, self::postInt('birthdayBonus')),
        ];
        if ($row['maxHours'] !== null && $row['maxHours'] < $row['minHours']) {
            self::fail('«До» не может быть меньше «от»', '/loyalty/edit');
        }
        if ($id > 0) {
            if (!Db::one('SELECT id FROM loyalty_tiers WHERE id = ?', [$id])) {
                self::fail('Уровень не найден', '/loyalty/edit');
            }
            Db::exec(
                'UPDATE loyalty_tiers SET title = ?, "minHours" = ?, "maxHours" = ?, "cashbackPercent" = ?, "birthdayBonus" = ? WHERE id = ?',
                [$row['title'], $row['minHours'], $row['maxHours'], $row['cashbackPercent'], $row['birthdayBonus'], $id],
            );
        } else {
            $row['sortOrder'] = (int) Db::value('SELECT COALESCE(MAX("sortOrder"), 0) FROM loyalty_tiers') + 1;
            Db::insert('loyalty_tiers', $row);
        }
        audit($user, 'content', 'loyalty', 'Уровень «' . $title . '»: ' . $row['cashbackPercent'] . '% кэшбека', url_to('/loyalty'));
        flash_set('ok', 'Уровень «' . $title . '» сохранён');
        redirect_to('/loyalty/edit');
    }

    private static function doSavePromo(array $user): void
    {
        self::permOrFail($user, 'content.edit', null, '/reviews/edit');
        $bonus = max(0, self::postInt('textBonus'));
        if ($bonus === 0) {
            self::fail('Укажите сумму бонуса за отзыв', '/reviews/edit');
        }
        self::save_portal_json('review_promo', [
            'intro' => self::postStr('intro', 2000) ?: DEFAULT_REVIEW_PROMO['intro'],
            'textBonus' => $bonus,
            'photoBonus' => 0,
        ]);
        audit($user, 'content', 'promo', 'Условия акции за отзывы: ' . $bonus . ' бонусов', url_to('/reviews'));
        flash_set('ok', 'Условия акции сохранены');
        redirect_to('/reviews');
    }
}
