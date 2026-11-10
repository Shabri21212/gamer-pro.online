<?php
declare(strict_types=1);

trait ActionsBar
{
    private static function langameBranchFromPost(string $back): array
    {
        $b = self::branchById(self::postInt('branchId'));
        if (!$b) {
            flash_set('error', 'Филиал не найден');
            redirect_to($back);
        }
        return $b;
    }

    private static function doSaveLangame(array $user): void
    {
        $back = '/manage/langame';
        self::permOrFail($user, 'owner', null, $back);
        $b = self::langameBranchFromPost($back);
        $base = Langame::normalizeBase(self::postStr('baseUrl', 255));
        if ($base !== '' && !preg_match('#^https://[a-z0-9.-]+\.[a-z]{2,}(/[A-Za-z0-9._/-]*)?$#i', $base)) {
            flash_set('error', 'Адрес должен быть вида ' . Langame::BASE_EXAMPLE);
            redirect_to($back);
        }
        $pcCount = filter_var($_POST['pcCount'] ?? $b['pcCount'], FILTER_VALIDATE_INT);
        if ($pcCount === false || $pcCount < 0 || $pcCount > 500) {
            flash_set('error', 'Количество компьютеров — целое число от 0 до 500');
            redirect_to($back);
        }
        $pctRaw = str_replace(',', '.', self::postStr('barPercent', 8));
        $pct = $pctRaw === '' ? 0 : (int) round(((float) $pctRaw) * 10);
        if ($pct < 0 || $pct > 1000) {
            flash_set('error', 'Процент — от 0 до 100');
            redirect_to($back);
        }
        $token = preg_replace('/\s+/', '', (string) ($_POST['token'] ?? '')) ?? '';
        if (strlen($token) > 512) {
            flash_set('error', 'Токен слишком длинный');
            redirect_to($back);
        }
        $enabled = !empty($_POST['isEnabled']) ? 1 : 0;
        $s = Langame::settings($b['id']);
        try {
            $enc = $token !== '' ? Langame::encrypt($token) : ($s['tokenEnc'] ?? null);
        } catch (LangameError $e) {
            flash_set('error', $e->getMessage());
            redirect_to($back);
        }
        $hint = $token !== '' ? '…' . substr($token, -4) : ($s['tokenHint'] ?? null);
        if ($enabled && ($enc === null || $base === '')) {
            flash_set('error', 'Чтобы включить подключение, укажите адрес клуба и токен');
            redirect_to($back);
        }
        $row = ['baseUrl' => $base, 'tokenEnc' => $enc, 'tokenHint' => $hint, 'barPercentX10' => $pct, 'isEnabled' => $enabled, 'updatedAt' => now_iso()];
        if ($s) {
            Db::exec('UPDATE langame_settings SET "baseUrl" = ?, "tokenEnc" = ?, "tokenHint" = ?, "barPercentX10" = ?, "isEnabled" = ?, "updatedAt" = ? WHERE id = ?', [...array_values($row), $s['id']]);
        } else {
            Db::insert('langame_settings', ['branchId' => $b['id']] + $row);
        }
        Db::exec('UPDATE branches SET "pcCount" = ? WHERE id = ?', [$pcCount, $b['id']]);
        audit($user, 'content', 'langame', 'Настройки Langame: ' . $b['name'] . ($token !== '' ? ' (новый токен)' : ''), url_to($back));
        if ($enabled && $token !== '') {
            try {
                $r = Langame::sync($b['id']);
                flash_set('success', 'Подключено. Загружено продаж: ' . $r['sales'] . ', администраторов: ' . $r['users'] . '. Теперь привяжите их к сотрудникам ниже.');
            } catch (LangameError $e) {
                flash_set('error', 'Сохранено, но синхронизация не прошла: ' . $e->getMessage());
            }
        } else {
            flash_set('success', 'Настройки ' . $b['name'] . ' сохранены');
        }
        redirect_to($back);
    }

    private static function doTestLangame(array $user): void
    {
        $back = '/manage/langame';
        self::permOrFail($user, 'owner', null, $back);
        $b = self::langameBranchFromPost($back);
        $s = Langame::settings($b['id']);
        $token = $s ? Langame::decrypt($s['tokenEnc'] ?? null) : null;
        if (!$s || $token === null || (string) $s['baseUrl'] === '') {
            flash_set('error', 'Сначала сохраните адрес клуба и токен');
            redirect_to($back);
        }
        try {
            Langame::test((string) $s['baseUrl'], $token);
            flash_set('success', 'Связь с Langame есть — токен принят');
        } catch (LangameError $e) {
            flash_set('error', $e->getMessage());
        }
        redirect_to($back);
    }

    private static function doSyncLangame(array $user): void
    {
        $back = self::postStr('back', 200);
        $back = str_starts_with($back, '/') && !str_starts_with($back, '//') ? $back : '/bar';
        $b = self::langameBranchFromPost($back);
        $s = Langame::settings($b['id']);
        if (!$s || !boolish($s['isEnabled'])) {
            flash_set('error', 'Подключение к Langame для ' . $b['name'] . ' выключено');
            redirect_to($back);
        }
        $last = $s['lastSyncAt'] ? strtotime((string) $s['lastSyncAt']) : 0;
        if (!$user['isOwner'] && $last && time() - $last < 30) {
            flash_set('success', 'Данные только что обновились');
            redirect_to($back);
        }
        try {
            $r = Langame::sync($b['id']);
            flash_set('success', 'Обновлено: ' . plural($r['sales'], 'продажа', 'продажи', 'продаж') . ' за последние дни');
        } catch (LangameError $e) {
            flash_set('error', $e->getMessage());
        }
        redirect_to($back);
    }

    private static function doLinkLangameStaff(array $user): void
    {
        $back = '/manage/langame';
        self::permOrFail($user, 'owner', null, $back);
        $b = self::langameBranchFromPost($back);
        $links = is_array($_POST['link'] ?? null) ? $_POST['link'] : [];
        $pcts = is_array($_POST['pct'] ?? null) ? $_POST['pct'] : [];
        $valid = array_fill_keys(array_map(static fn ($e) => (int) $e['id'], self::employees()), true);
        $used = [];
        $n = 0;
        Db::tx(static function () use ($b, $links, $pcts, $valid, &$used, &$n): void {
            foreach ($links as $lid => $eidRaw) {
                $lid = (int) $lid;
                $eid = (int) $eidRaw;
                $eid = isset($valid[$eid]) ? $eid : null;
                if ($eid !== null) {
                    if (isset($used[$eid])) {
                        $eid = null;
                    } else {
                        $used[$eid] = true;
                        $n++;
                    }
                }
                $p = str_replace(',', '.', trim((string) ($pcts[$lid] ?? '')));
                $pct = $p === '' ? null : max(0, min(1000, (int) round(((float) $p) * 10)));
                Db::exec('UPDATE langame_staff SET "employeeId" = ?, "percentX10" = ?, "updatedAt" = ? WHERE "branchId" = ? AND "langameUserId" = ?', [$eid, $pct, now_iso(), $b['id'], $lid]);
            }
        });
        audit($user, 'staff', 'langame_link', 'Привязка учёток Langame (' . $b['name'] . '): ' . plural($n, 'сотрудник', 'сотрудника', 'сотрудников'), url_to($back));
        flash_set('success', 'Привязки сохранены' . (count($used) < count(array_filter($links, static fn ($v) => (int) $v > 0)) ? '. Один сотрудник может быть привязан только к одной учётке — дубли сброшены.' : ''));
        redirect_to($back);
    }

    private static function doForgetLangameToken(array $user): void
    {
        $back = '/manage/langame';
        self::permOrFail($user, 'owner', null, $back);
        $b = self::langameBranchFromPost($back);
        Db::exec('UPDATE langame_settings SET "tokenEnc" = NULL, "tokenHint" = NULL, "isEnabled" = 0, "updatedAt" = ? WHERE "branchId" = ?', [now_iso(), $b['id']]);
        audit($user, 'content', 'langame', 'Токен Langame удалён: ' . $b['name'], url_to($back));
        flash_set('success', 'Токен удалён, подключение выключено');
        redirect_to($back);
    }
}
