<?php
declare(strict_types=1);

trait ActionsRules
{
    /* ---------- база знаний ---------- */

    /** Раздел из формы: существующий id или новый по названию. */
    private static function wikiCategoryFromPost(string $back): int
    {
        $raw = self::postStr('categoryId', 16);
        if ($raw === 'new') {
            $title = self::postStr('newCategory', 80);
            if (mb_strlen($title) < 2) {
                self::fail('Напишите название нового раздела', $back);
            }
            $existing = Db::one('SELECT id FROM knowledge_categories WHERE title = ?', [$title]);
            if ($existing) {
                return (int) $existing['id'];
            }
            return Db::insert('knowledge_categories', [
                'title' => $title,
                'slug' => slugify($title, 'razdel'),
                'sortOrder' => (int) Db::value('SELECT COALESCE(MAX("sortOrder"), 0) FROM knowledge_categories') + 1,
                'createdAt' => now_iso(),
            ]);
        }
        $id = (int) $raw;
        if (!Db::one('SELECT id FROM knowledge_categories WHERE id = ?', [$id])) {
            self::fail('Выберите раздел базы знаний', $back);
        }
        return $id;
    }

    private static function wikiHtmlFromPost(string $back): string
    {
        $html = (string) ($_POST['contentHtml'] ?? '');
        if (strlen($html) > 300000) {
            self::fail('Текст слишком большой — разбейте статью на несколько', $back);
        }
        return sanitize_html($html);
    }

    private static function doCreateWiki(array $user): void
    {
        self::permOrFail($user, 'wiki.edit', null, '/wiki');
        $title = self::postStr('title', 120);
        if (mb_strlen($title) < 2) {
            self::fail('Нужен заголовок статьи', '/wiki/new');
        }
        $cat = self::wikiCategoryFromPost('/wiki/new');
        $slug = unique_slug('knowledge_articles', slugify($title, 'statya'));
        Db::insert('knowledge_articles', [
            'categoryId' => $cat,
            'title' => $title,
            'slug' => $slug,
            'contentHtml' => self::wikiHtmlFromPost('/wiki/new'),
            'kind' => 'article',
            'isDraft' => isset($_POST['isDraft']) ? 1 : 0,
            'sortOrder' => (int) Db::value('SELECT COALESCE(MAX("sortOrder"), 0) FROM knowledge_articles WHERE "categoryId" = ?', [$cat]) + 1,
            'updatedByUserId' => $user['id'],
            'createdAt' => now_iso(),
            'updatedAt' => now_iso(),
        ]);
        audit($user, 'wiki', 'create', 'Новая статья: ' . $title, url_to('/wiki/' . rawurlencode($slug)));
        flash_set('ok', 'Статья «' . $title . '» создана');
        redirect_to('/wiki/' . rawurlencode($slug));
    }

    private static function doSaveWiki(array $user): void
    {
        self::permOrFail($user, 'wiki.edit', null, '/wiki');
        $a = Db::one('SELECT id, slug, title FROM knowledge_articles WHERE id = ?', [self::postInt('id')]);
        if (!$a) {
            self::fail('Статья не найдена', '/wiki');
        }
        $back = '/wiki/' . rawurlencode((string) $a['slug']) . '/edit';
        $title = self::postStr('title', 120);
        if (mb_strlen($title) < 2) {
            self::fail('Нужен заголовок статьи', $back);
        }
        Db::exec(
            'UPDATE knowledge_articles SET title = ?, "categoryId" = ?, "contentHtml" = ?, "isDraft" = ?, "updatedByUserId" = ?, "updatedAt" = ? WHERE id = ?',
            [$title, self::wikiCategoryFromPost($back), self::wikiHtmlFromPost($back), isset($_POST['isDraft']) ? 1 : 0, $user['id'], now_iso(), (int) $a['id']],
        );
        audit($user, 'wiki', 'update', 'Статья изменена: ' . $title, url_to('/wiki/' . rawurlencode((string) $a['slug'])));
        flash_set('ok', 'Статья сохранена');
        redirect_to('/wiki/' . rawurlencode((string) $a['slug']));
    }

    private static function doDeleteWiki(array $user): void
    {
        self::permOrFail($user, 'wiki.edit', null, '/wiki');
        $a = Db::one('SELECT id, title, kind FROM knowledge_articles WHERE id = ?', [self::postInt('id')]);
        if (!$a || $a['kind'] === 'fines') {
            self::fail('Статья не найдена', '/wiki');
        }
        Db::exec('DELETE FROM knowledge_articles WHERE id = ?', [(int) $a['id']]);
        audit($user, 'wiki', 'delete', 'Удалена статья: ' . $a['title'], url_to('/wiki'));
        flash_set('ok', 'Статья «' . $a['title'] . '» удалена');
        redirect_to('/wiki');
    }

    /* ---------- тарифы ---------- */

    private static function doSaveTariffs(array $user): void
    {
        self::permOrFail($user, 'content.edit', null, '/tariffs');
        $str = static fn ($v, int $max): string => is_string($v) ? mb_substr(trim($v), 0, $max) : '';
        $branches = [];
        foreach ((array) ($_POST['branches'] ?? []) as $b) {
            if (!is_array($b) || $str($b['branchName'] ?? '', 60) === '') {
                continue;
            }
            $zones = array_map(static fn ($z) => $str($z, 30), array_values((array) ($b['zones'] ?? [])));
            while ($zones && end($zones) === '') {
                array_pop($zones);
            }
            $packages = [];
            foreach ((array) ($b['packages'] ?? []) as $p) {
                $label = is_array($p) ? $str($p['label'] ?? '', 60) : '';
                if ($label === '') {
                    continue;
                }
                $prices = [];
                foreach (array_keys($zones) as $zi) {
                    $v = trim((string) ($p['prices'][$zi] ?? ''));
                    $prices[] = $v === '' ? null : max(0, (int) $v);
                }
                $row = ['label' => $label, 'prices' => $prices];
                $hint = $str($p['hint'] ?? '', 60);
                if ($hint !== '') {
                    $row['hint'] = $hint;
                }
                $packages[] = $row;
            }
            $branches[] = ['branchName' => $str($b['branchName'], 60), 'address' => $str($b['address'] ?? '', 120), 'zones' => $zones, 'packages' => $packages];
        }
        if (!$branches) {
            self::fail('Нужен хотя бы один филиал с названием', '/tariffs/edit');
        }
        self::save_portal_json('tariffs', [
            'intro' => self::postStr('intro', 1000),
            'footer' => self::postStr('footer', 1000),
            'branches' => $branches,
        ]);
        audit($user, 'content', 'tariffs', 'Обновлены тарифы: ' . implode(', ', array_column($branches, 'branchName')), url_to('/tariffs'));
        flash_set('ok', 'Тарифы сохранены');
        redirect_to('/tariffs');
    }

    /* ---------- штрафы ---------- */

    private static function amountOrNull(string $key): ?int
    {
        $raw = trim((string) ($_POST[$key] ?? ''));
        return $raw === '' ? null : max(0, min(1000000, (int) $raw));
    }

    private static function doSaveFineRule(array $user): void
    {
        self::permOrFail($user, 'fines.manage', null, '/fines');
        $violation = self::postStr('violation', 300);
        if (mb_strlen($violation) < 3) {
            self::fail('Напишите, за что штраф', '/fines/edit');
        }
        $pd = isset($_POST['primaryDismissal']) ? 1 : 0;
        $rd = isset($_POST['repeatDismissal']) ? 1 : 0;
        $data = [
            'violation' => $violation,
            'primaryAmount' => $pd ? null : self::amountOrNull('primaryAmount'),
            'primaryDismissal' => $pd,
            'repeatAmount' => $rd ? null : self::amountOrNull('repeatAmount'),
            'repeatDismissal' => $rd,
            'isActive' => isset($_POST['isActive']) ? 1 : 0,
            'updatedAt' => now_iso(),
        ];
        $id = self::postInt('id');
        if ($id > 0) {
            if (!Db::one('SELECT id FROM fine_rules WHERE id = ?', [$id])) {
                self::fail('Пункт не найден', '/fines/edit');
            }
            Db::exec(
                'UPDATE fine_rules SET violation = ?, "primaryAmount" = ?, "primaryDismissal" = ?, "repeatAmount" = ?, "repeatDismissal" = ?, "isActive" = ?, "updatedAt" = ? WHERE id = ?',
                [...array_values($data), $id],
            );
            audit($user, 'fines', 'rule', 'Изменён пункт листа: ' . mb_substr($violation, 0, 120), url_to('/fines'));
            flash_set('ok', 'Пункт сохранён');
        } else {
            $data['sortOrder'] = (int) Db::value('SELECT COALESCE(MAX("sortOrder"), 0) FROM fine_rules') + 1;
            $data['createdAt'] = now_iso();
            Db::insert('fine_rules', $data);
            audit($user, 'fines', 'rule', 'Новый пункт листа: ' . mb_substr($violation, 0, 120), url_to('/fines'));
            flash_set('ok', 'Пункт добавлен в лист штрафов');
        }
        redirect_back('/fines/edit');
    }

    private static function doDeleteFineRule(array $user): void
    {
        self::permOrFail($user, 'fines.manage', null, '/fines');
        $r = Db::one('SELECT id, violation FROM fine_rules WHERE id = ?', [self::postInt('id')]);
        if (!$r) {
            self::fail('Пункт уже удалён', '/fines/edit');
        }
        Db::exec('DELETE FROM fine_rules WHERE id = ?', [(int) $r['id']]);
        audit($user, 'fines', 'rule_delete', 'Удалён пункт листа: ' . mb_substr((string) $r['violation'], 0, 120), url_to('/fines'));
        flash_set('ok', 'Пункт удалён');
        redirect_back('/fines/edit');
    }

    private static function doAssignFine(array $user): void
    {
        self::permOrFail($user, 'fines.manage', null, '/fines');
        $stashFail = static function (string $msg): never {
            form_stash_set('fine-assign', $_POST);
            flash_set('error', $msg);
            redirect_to('/fines/assign');
        };
        $emp = Db::one('SELECT id, name, "isActive" FROM employees WHERE id = ?', [self::postInt('employeeId')]);
        if (!$emp || !boolish($emp['isActive'])) {
            $stashFail('Выберите сотрудника');
        }
        $reason = self::postStr('reason', 500);
        if (mb_strlen($reason) < 3) {
            $stashFail('Напишите причину штрафа');
        }
        $amount = (int) preg_replace('/\D+/', '', self::postStr('amount', 16));
        if ($amount < 1 || $amount > 50000) {
            $stashFail('Сумма — целое число от 1 до 50 000 ₽');
        }
        $ruleId = self::postInt('ruleId');
        if ($ruleId > 0 && !Db::one('SELECT id FROM fine_rules WHERE id = ?', [$ruleId])) {
            $ruleId = 0;
        }
        Db::insert('employee_fines', [
            'employeeId' => (int) $emp['id'],
            'employeeName' => (string) $emp['name'],
            'ruleId' => $ruleId > 0 ? $ruleId : null,
            'reason' => $reason,
            'amount' => $amount,
            'createdByUserId' => $user['id'],
            'createdByName' => self::displayName($user),
            'createdAt' => now_iso(),
        ]);
        audit($user, 'fines', 'assign', 'Штраф ' . money_rub($amount) . ' — ' . $emp['name'] . ': ' . mb_substr($reason, 0, 120), url_to('/fines'));
        flash_set('ok', 'Штраф назначен: ' . money_rub($amount) . ' — ' . $emp['name']);
        redirect_to('/fines');
    }

    private static function doCancelFine(array $user): void
    {
        self::permOrFail($user, 'fines.manage', null, '/fines');
        $f = Db::one('SELECT id, "employeeName", amount, reason FROM employee_fines WHERE id = ?', [self::postInt('id')]);
        if (!$f) {
            self::fail('Штраф уже отменён', '/fines');
        }
        Db::exec('DELETE FROM employee_fines WHERE id = ?', [(int) $f['id']]);
        audit($user, 'fines', 'cancel', 'Отменён штраф ' . money_rub((int) $f['amount']) . ' — ' . $f['employeeName'] . ': ' . mb_substr((string) $f['reason'], 0, 100), url_to('/fines'));
        flash_set('ok', 'Штраф отменён');
        redirect_back('/fines');
    }
}
