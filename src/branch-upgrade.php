<?php
declare(strict_types=1);

final class BranchUpgrade
{
    /** Повторный запуск безопасен: существующие цены, статьи и подключения не заменяются. */
    public static function run(PDO $pdo): void
    {
        $pdo->beginTransaction();
        try {
            $find = static function (string $sql, array $params = []) use ($pdo): mixed {
                $st = $pdo->prepare($sql);
                $st->execute($params);
                return $st->fetchColumn();
            };
            $insert = static function (string $table, array $row) use ($pdo): void {
                $cols = '"' . implode('","', array_keys($row)) . '"';
                $pdo->prepare('INSERT INTO "' . $table . '" (' . $cols . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
            };
            $now = now_iso();
            $bid = $find('SELECT id FROM branches WHERE slug = ?', ['gazovikov']);
            if ($bid === false) {
                $insert('branches', ['name' => 'Газовиков', 'slug' => 'gazovikov', 'pcCount' => 0, 'sortOrder' => 2, 'createdAt' => $now]);
                $bid = (int) $pdo->lastInsertId();
            }
            if ($find('SELECT id FROM langame_settings WHERE "branchId" = ?', [$bid]) === false) {
                $insert('langame_settings', ['branchId' => $bid, 'baseUrl' => 'https://643.langame.ru/public_api', 'isEnabled' => 0, 'updatedAt' => $now]);
            }
            $raw = $find('SELECT "valueJson" FROM portal_content WHERE "key" = ?', ['tariffs']);
            if ($raw !== false) {
                $tariffs = json_decode((string) $raw, true);
                if (is_array($tariffs) && isset($tariffs['branches']) && is_array($tariffs['branches'])) {
                    $hasBranch = array_filter($tariffs['branches'], static fn ($b) => ($b['branchName'] ?? '') === 'Газовиков');
                    if (!$hasBranch) {
                        $tariffs['branches'][] = DEFAULT_TARIFFS['branches'][2];
                        $pdo->prepare('UPDATE portal_content SET "valueJson" = ?, "updatedAt" = ? WHERE "key" = ?')->execute([json_encode($tariffs, JSON_UNESCAPED_UNICODE), $now, 'tariffs']);
                    }
                }
            }
            if ($find('SELECT id FROM knowledge_articles WHERE slug = ?', ['gazovikov-zony']) === false) {
                $category = $find('SELECT id FROM knowledge_categories WHERE slug = ? ORDER BY id LIMIT 1', ['kluby-i-tarify']);
                if ($category === false) {
                    $insert('knowledge_categories', ['title' => 'Клубы и тарифы', 'slug' => 'kluby-i-tarify', 'sortOrder' => 4, 'createdAt' => $now]);
                    $category = (int) $pdo->lastInsertId();
                }
                $hardware = [
                    'Процессор' => ['Intel Core i5-12400F', 'Intel Core i5-12400F', 'Intel Core i5-12600KF'],
                    'Видеокарта' => ['RTX 4060', 'RTX 4060 Ti', 'RTX 4070'],
                    'Оперативная память' => ['16 GB', '32 GB', '32 GB'],
                    'Мышь' => ['Logitech G102', 'Logitech G403', 'Logitech G Pro X Superlight'],
                    'Клавиатура' => ['Red Square Keyrox TKL Hyperion', 'Dark Project KD87', 'Dark Project KD65'],
                    'Наушники' => ['HyperX Cloud 2', 'HyperX Cloud 2', 'Razer BlackShark V2X'],
                    'Монитор' => ['27″ AOC, Full HD, 240 Hz', '27″ ASUS, 2K, 280 Hz', '25″ ASUS ROG Strix, Full HD, 380 Hz'],
                    'Кресло' => ['Knight Outrider', 'Knight Titan', 'Knight Titan'],
                ];
                $html = '<p>Адрес: ул. Газовиков, 38. Зоны: Standart, VIP, Duo и PS5.</p>';
                foreach (['Standart', 'VIP', 'Duo'] as $i => $zone) {
                    $html .= '<h2>' . $zone . '</h2><ul>';
                    foreach ($hardware as $label => $values) {
                        $html .= '<li><strong>' . h($label) . ':</strong> ' . h($values[$i]) . '</li>';
                    }
                    $html .= '</ul>';
                }
                $html .= '<h2>PS5</h2><p>Консольная зона PlayStation 5.</p><h2>Тарифы</h2><p>Актуальные цены — в разделе «Тарифы», стоимость времени — в «Бонусной системе». Ночной пакет действует с 22:00 до 10:00. Не путать с графиком сотрудников: день 08:00–20:00, ночь 20:00–08:00.</p>';
                $insert('knowledge_articles', ['categoryId' => $category, 'title' => 'Газовиков: зоны и железо', 'slug' => 'gazovikov-zony', 'contentHtml' => $html, 'kind' => 'article', 'isDraft' => 0, 'sortOrder' => 3, 'createdAt' => $now, 'updatedAt' => $now]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
