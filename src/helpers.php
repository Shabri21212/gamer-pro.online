<?php
declare(strict_types=1);

const MONTH_NAMES = ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
const MONTH_NAMES_GENITIVE = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
const MONTH_SHORT = ['янв','фев','мар','апр','мая','июн','июл','авг','сен','окт','ноя','дек'];
const WEEKDAY_SHORT = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];
const WEEKDAY_LONG = ['понедельник','вторник','среда','четверг','пятница','суббота','воскресенье'];
const SHIFT_TYPES = ['day', 'night', 'full'];
const SHIFT_LABELS = ['day' => 'День', 'night' => 'Ночь', 'full' => 'Сутки'];
const SHIFT_HINTS = ['day' => '08:00–20:00', 'night' => '20:00–08:00', 'full' => '08:00–08:00'];
const SHIFT_ICONS = ['day' => 'sun', 'night' => 'moon', 'full' => 'sun-moon'];
const INCIDENT_KIND = ['tech' => 'Поломка', 'incident' => 'Происшествие'];
const INCIDENT_STATUS = ['new' => 'Новая', 'in_progress' => 'В работе', 'done' => 'Закрыта'];
const REVIEW_STATUS = ['pending' => 'Ждёт зачисления', 'credited' => 'Зачислено', 'rejected' => 'Отказ'];
const REVIEW_KIND = ['text' => 'Текст', 'photo' => 'Фото', 'text_photo' => 'Текст + фото'];
const EXAM_KIND_LABELS = [
    'single' => 'Один верный вариант',
    'multiple' => 'Несколько верных',
    'boolean' => 'Да / Нет',
];
const ATTEMPT_STATUS_LABELS = [
    'in_progress' => 'Не завершён',
    'pending_review' => 'Ждёт проверки',
    'passed' => 'Сдан',
    'failed' => 'Не сдан',
];

const DEFAULT_TARIFFS = [
    'intro' => 'Администратор обязан знать прайс наизусть. Неверная цена гостю — штраф 200 ₽ (повторно 600 ₽). Пакет выгоднее поминутного тарифа от трёх часов.',
    'footer' => 'Железо зон, поминутный тариф и регламенты — в карточках филиалов базы знаний. Калькулятор времени и кэшбек — в бонусной системе.',
    'branches' => [
        [
            'branchName' => 'Гондатти',
            'address' => 'ул. Николая Гондатти, 5/2',
            'zones' => ['Standart', 'VIP', 'Duo / Trio'],
            'packages' => [
                ['label' => '1 час', 'prices' => [120, 170, 200]],
                ['label' => '3 часа', 'prices' => [300, 450, 500]],
                ['label' => '5 часов', 'prices' => [480, 750, 800]],
                ['label' => 'Ночь 12 часов', 'hint' => '22:00–10:00', 'prices' => [600, 850, 1200]],
            ],
        ],
        [
            'branchName' => 'Киевская',
            'address' => 'ул. Киевская, 62',
            'zones' => ['Standart', 'Comfort', 'VIP'],
            'packages' => [
                ['label' => '1 час', 'prices' => [120, 150, 160]],
                ['label' => '3 часа', 'prices' => [300, 400, 430]],
                ['label' => '5 часов', 'prices' => [480, 580, 640]],
                ['label' => 'Ночь 12 часов', 'hint' => '22:00–10:00', 'prices' => [600, 750, 800]],
            ],
        ],
    ],
];

const DEFAULT_REVIEW_PROMO = [
    'intro' => 'За отзыв на 2ГИС или Яндекс (от 200 символов) начисляем бонусы на баланс гостя. На каждом филиале — один раз.',
    'textBonus' => 100,
    'photoBonus' => 0,
];

/**
 * perm: право, без которого пункт скрыт; 'owner' — только владелец.
 * badge: ключ счётчика из Portal::counts().
 */
const NAV_GROUPS = [
    ['id' => 'shifts', 'label' => 'Смены', 'items' => [
        ['href' => '/', 'label' => 'Мой день', 'icon' => 'home'],
        ['href' => '/schedule', 'label' => 'График смен', 'icon' => 'calendar'],
        ['href' => '/cleaning', 'label' => 'Чистка клавиатур', 'icon' => 'keyboard'],
        ['href' => '/manage/tasks', 'label' => 'Задания на смены', 'icon' => 'clipboard-list', 'perm' => 'tasks.assign'],
    ]],
    ['id' => 'club', 'label' => 'Клуб', 'items' => [
        ['href' => '/issues', 'label' => 'Поломки', 'icon' => 'wrench', 'badge' => 'issues'],
        ['href' => '/news', 'label' => 'Объявления', 'icon' => 'megaphone', 'badge' => 'news'],
    ]],
    ['id' => 'guests', 'label' => 'Гости', 'items' => [
        ['href' => '/reviews', 'label' => 'Бонусы за отзывы', 'icon' => 'star', 'badge' => 'reviews'],
        ['href' => '/compensations', 'label' => 'Компенсации', 'icon' => 'coins', 'badge' => 'compensations'],
        ['href' => '/loyalty', 'label' => 'Бонусная система', 'icon' => 'percent'],
    ]],
    ['id' => 'rules', 'label' => 'Регламенты', 'items' => [
        ['href' => '/wiki', 'label' => 'База знаний', 'icon' => 'book'],
        ['href' => '/tariffs', 'label' => 'Тарифы', 'icon' => 'tags'],
        ['href' => '/fines', 'label' => 'Штрафы', 'icon' => 'scale'],
        ['href' => '/exams', 'label' => 'Экзамены', 'icon' => 'grad'],
    ]],
    ['id' => 'manage', 'label' => 'Управление', 'items' => [
        ['href' => '/manage/journal', 'label' => 'Журнал действий', 'icon' => 'history', 'perm' => 'journal.view'],
        ['href' => '/manage/employees', 'label' => 'Сотрудники', 'icon' => 'users', 'perm' => 'staff.manage'],
        ['href' => '/manage/grades', 'label' => 'Уровни и ставки', 'icon' => 'wallet', 'perm' => 'staff.manage'],
        ['href' => '/manage/exams', 'label' => 'Результаты экзаменов', 'icon' => 'clipboard-check', 'perm' => 'exams.manage'],
        ['href' => '/manage/accounts', 'label' => 'Доступы и права', 'icon' => 'key', 'perm' => 'owner'],
    ]],
];

const MOBILE_TABS = [
    ['href' => '/', 'label' => 'Мой день', 'icon' => 'home'],
    ['href' => '/schedule', 'label' => 'График', 'icon' => 'calendar'],
    ['href' => '/issues', 'label' => 'Поломки', 'icon' => 'wrench', 'badge' => 'issues'],
    ['href' => '/reviews', 'label' => 'Отзывы', 'icon' => 'star', 'badge' => 'reviews'],
];

function date_key(DateTimeImmutable $d): string
{
    return $d->format('Y-m-d');
}

function is_date_key(string $key): bool
{
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $key) && date_from_key($key) !== null;
}

function date_from_key(string $key): ?DateTimeImmutable
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', substr($key, 0, 10), new DateTimeZone(APP_TZ));
    return $d ?: null;
}

function parse_date_key(string $key): ?array
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $key, $m)) {
        return null;
    }
    return ['year' => (int) $m[1], 'month' => (int) $m[2] - 1, 'day' => (int) $m[3]];
}

function to_date_key(int $year, int $month, int $day): string
{
    return sprintf('%04d-%02d-%02d', $year, $month + 1, $day);
}

function monday_first_weekday(int $year, int $month, int $day): int
{
    return (int) (new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month + 1, $day)))->format('N') - 1;
}

function weekday_from_date_key(string $key): int
{
    $p = parse_date_key($key);
    return $p ? monday_first_weekday($p['year'], $p['month'], $p['day']) : 0;
}

function days_in_month(int $year, int $month): int
{
    return (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month + 1)))->format('t');
}

function monday_key(DateTimeImmutable $d): string
{
    $n = (int) $d->format('N');
    return $d->modify('-' . ($n - 1) . ' days')->format('Y-m-d');
}

function add_days(string $key, int $days): string
{
    $d = date_from_key($key) ?? now_tyumen();
    return $d->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
}

function format_long_date(int $year, int $month, int $day): string
{
    $wd = WEEKDAY_LONG[monday_first_weekday($year, $month, $day)];
    return $day . ' ' . MONTH_NAMES_GENITIVE[$month] . ', ' . $wd;
}

/** «пятница, 14 ноября» */
function format_date_human(string $key): string
{
    $p = parse_date_key($key);
    if (!$p) {
        return $key;
    }
    $wd = WEEKDAY_LONG[monday_first_weekday($p['year'], $p['month'], $p['day'])];
    return $wd . ', ' . $p['day'] . ' ' . MONTH_NAMES_GENITIVE[$p['month']];
}

/** «пт, 14 ноя» */
function format_day_short(string $key): string
{
    $p = parse_date_key($key);
    if (!$p) {
        return $key;
    }
    $wd = mb_strtolower(WEEKDAY_SHORT[monday_first_weekday($p['year'], $p['month'], $p['day'])]);
    return $wd . ', ' . $p['day'] . ' ' . MONTH_SHORT[$p['month']];
}

/** «14 ноября» */
function format_day_month(string $key): string
{
    $p = parse_date_key($key);
    return $p ? $p['day'] . ' ' . MONTH_NAMES_GENITIVE[$p['month']] : $key;
}

/** Метка времени из базы → «сегодня, 14:32», «вчера, 09:10», «3 ноя, 18:00», «3 ноя 2025». */
function format_dt(?string $iso, bool $time = true): string
{
    $iso = trim((string) $iso);
    if ($iso === '') {
        return '—';
    }
    try {
        $d = (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone(APP_TZ));
    } catch (Exception) {
        return $iso;
    }
    $now = now_tyumen();
    $clock = $time ? ', ' . $d->format('H:i') : '';
    $key = $d->format('Y-m-d');
    if ($key === $now->format('Y-m-d')) {
        return 'сегодня' . $clock;
    }
    if ($key === $now->modify('-1 day')->format('Y-m-d')) {
        return 'вчера' . $clock;
    }
    $base = (int) $d->format('j') . ' ' . MONTH_SHORT[(int) $d->format('n') - 1];
    if ($d->format('Y') !== $now->format('Y')) {
        return $base . ' ' . $d->format('Y');
    }
    return $base . $clock;
}

/** Неделя с понедельника $monday: «3–9 ноября» или «27 октября – 2 ноября». */
function week_label(string $monday): string
{
    $a = date_from_key($monday);
    if (!$a) {
        return $monday;
    }
    $b = $a->modify('+6 days');
    $am = (int) $a->format('n') - 1;
    $bm = (int) $b->format('n') - 1;
    if ($am === $bm) {
        return $a->format('j') . '–' . $b->format('j') . ' ' . MONTH_NAMES_GENITIVE[$bm];
    }
    return $a->format('j') . ' ' . MONTH_NAMES_GENITIVE[$am] . ' – ' . $b->format('j') . ' ' . MONTH_NAMES_GENITIVE[$bm];
}

function dates_between(string $from, string $to, int $max = 42): array
{
    $a = date_from_key($from);
    $b = date_from_key($to);
    if (!$a || !$b || $b < $a) {
        return [];
    }
    $out = [];
    for ($d = $a; $d <= $b; $d = $d->modify('+1 day')) {
        $out[] = $d->format('Y-m-d');
        if (count($out) >= $max) {
            break;
        }
    }
    return $out;
}

function format_month_title(int $year, int $month): string
{
    return MONTH_NAMES[$month] . ' ' . $year;
}

/**
 * Окно смены по времени клуба: день 08–20, ночь 20–08 следующего дня, сутки 08–08.
 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
 */
function shift_window(string $dateKey, string $type): array
{
    $d = date_from_key($dateKey) ?? now_tyumen()->setTime(0, 0);
    return match ($type) {
        'night' => [$d->setTime(20, 0), $d->modify('+1 day')->setTime(8, 0)],
        'full' => [$d->setTime(8, 0), $d->modify('+1 day')->setTime(8, 0)],
        default => [$d->setTime(8, 0), $d->setTime(20, 0)],
    };
}

/** @return array{state: string, start: DateTimeImmutable, end: DateTimeImmutable, progress: int, minutesLeft: int, minutesToStart: int} */
function shift_state(string $dateKey, string $type, ?DateTimeImmutable $now = null): array
{
    $now ??= now_tyumen();
    [$start, $end] = shift_window($dateKey, $type);
    $ts = $now->getTimestamp();
    $state = $ts < $start->getTimestamp() ? 'upcoming' : ($ts >= $end->getTimestamp() ? 'done' : 'live');
    $span = max(1, $end->getTimestamp() - $start->getTimestamp());
    $progress = $state === 'live' ? (int) round(($ts - $start->getTimestamp()) / $span * 100) : ($state === 'done' ? 100 : 0);
    return [
        'state' => $state,
        'start' => $start,
        'end' => $end,
        'progress' => max(0, min(100, $progress)),
        'minutesLeft' => max(0, (int) floor(($end->getTimestamp() - $ts) / 60)),
        'minutesToStart' => max(0, (int) floor(($start->getTimestamp() - $ts) / 60)),
    ];
}

function duration_label(int $minutes): string
{
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    if ($h >= 48) {
        return plural(intdiv($h, 24), 'день', 'дня', 'дней');
    }
    if ($h === 0) {
        return $m . ' мин';
    }
    return $h . ' ч' . ($m > 0 ? ' ' . $m . ' мин' : '');
}

function plural_word(int $n, string $one, string $few, string $many): string
{
    $n = abs($n);
    $m10 = $n % 10;
    $m100 = $n % 100;
    if ($m10 === 1 && $m100 !== 11) {
        return $one;
    }
    if ($m10 >= 2 && $m10 <= 4 && ($m100 < 12 || $m100 > 14)) {
        return $few;
    }
    return $many;
}

function plural(int $n, string $one, string $few, string $many): string
{
    return $n . ' ' . plural_word($n, $one, $few, $many);
}

function shift_weight(string $type): int
{
    return $type === 'full' ? 2 : 1;
}

function shift_count_label(int $n): string
{
    return plural_word($n, 'смена', 'смены', 'смен');
}

function parse_employee_name(string $name): array
{
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $parts = array_values(array_filter($parts, static fn ($p) => $p !== ''));
    if (count($parts) < 2) {
        return ['surname' => trim($name), 'first' => ''];
    }
    return ['surname' => $parts[0], 'first' => implode(' ', array_slice($parts, 1))];
}

function short_name(string $name): string
{
    $parts = parse_employee_name($name);
    if ($parts['first'] === '') {
        return $parts['surname'];
    }
    return $parts['surname'] . ' ' . mb_strtoupper(mb_substr($parts['first'], 0, 1)) . '.';
}

function first_name(string $name): string
{
    $parts = parse_employee_name($name);
    return $parts['first'] !== '' ? explode(' ', $parts['first'])[0] : $parts['surname'];
}

function initials(string $name): string
{
    $parts = parse_employee_name($name);
    $a = mb_strtoupper(mb_substr($parts['surname'], 0, 1));
    $b = $parts['first'] !== '' ? mb_strtoupper(mb_substr($parts['first'], 0, 1)) : '';
    return ($a . $b) !== '' ? $a . $b : '?';
}

function news_preview(string $body, int $len = 140): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $body) ?? '');
    if (mb_strlen($text) <= $len) {
        return $text;
    }
    return rtrim(mb_substr($text, 0, $len)) . '…';
}

function money_rub(int|float $n): string
{
    return number_format((float) $n, 0, ',', "\u{202F}") . "\u{00A0}₽";
}

function num_fmt(int|float $n): string
{
    return number_format((float) $n, 0, ',', "\u{202F}");
}

function format_fine_amount(?int $amount, bool $dismissal): string
{
    if ($dismissal) {
        return 'Увольнение';
    }
    if ($amount === null) {
        return '—';
    }
    return money_rub($amount);
}

function normalize_phone(string $raw): ?string
{
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if (strlen($digits) === 10) {
        return $digits;
    }
    if (strlen($digits) === 11 && ($digits[0] === '7' || $digits[0] === '8')) {
        return substr($digits, 1);
    }
    if (strlen($digits) === 12 && str_starts_with($digits, '77')) {
        return substr($digits, 2);
    }
    return null;
}

function format_phone(string $raw): string
{
    $digits = normalize_phone($raw) ?? (preg_replace('/\D+/', '', $raw) ?? '');
    if (strlen($digits) !== 10) {
        return $raw;
    }
    return '+7 ' . substr($digits, 0, 3) . ' ' . substr($digits, 3, 3) . '-' . substr($digits, 6, 2) . '-' . substr($digits, 8, 2);
}

function boolish(mixed $v): bool
{
    return $v === true || $v === 1 || $v === '1';
}

function slugify(string $value, string $fallback = 'razdel'): string
{
    $map = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
        'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
        'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '',
        'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];
    $lower = mb_strtolower($value);
    $out = '';
    $len = mb_strlen($lower);
    for ($i = 0; $i < $len; $i++) {
        $ch = mb_substr($lower, $i, 1);
        $out .= $map[$ch] ?? $ch;
    }
    $out = preg_replace('/[^a-z0-9]+/', '-', $out) ?? '';
    $out = substr(trim($out, '-'), 0, 60);
    $out = trim($out, '-');
    return $out !== '' ? $out : ($fallback . '-' . time());
}

function unique_slug(string $table, string $base): string
{
    $table = in_array($table, ['exams', 'knowledge_articles'], true) ? $table : 'exams';
    if (!Db::one('SELECT id FROM ' . $table . ' WHERE slug = ?', [$base])) {
        return $base;
    }
    for ($i = 2; $i < 100; $i++) {
        $try = $base . '-' . $i;
        if (!Db::one('SELECT id FROM ' . $table . ' WHERE slug = ?', [$try])) {
            return $try;
        }
    }
    return $base . '-' . time();
}

function exam_parse_selected(string $value): array
{
    $ids = [];
    foreach (explode(',', $value) as $part) {
        $id = (int) trim($part);
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    return $ids;
}

function exam_shuffle(array $items, int $seed): array
{
    $result = array_values($items);
    $n = count($result);
    $state = $seed * 9301 + 49297;
    for ($i = $n - 1; $i > 0; $i--) {
        $state = ($state * 9301 + 49297) % 233280;
        $j = (int) floor(($state / 233280.0) * ($i + 1));
        $tmp = $result[$i];
        $result[$i] = $result[$j];
        $result[$j] = $tmp;
    }
    return $result;
}

function find_nav_item(string $path): ?array
{
    $path = rtrim($path, '/') ?: '/';
    $best = null;
    foreach (NAV_GROUPS as $g) {
        foreach ($g['items'] as $item) {
            $href = rtrim($item['href'], '/') ?: '/';
            $match = $href === '/' ? $path === '/' : ($path === $href || str_starts_with($path, $href . '/'));
            if ($match && ($best === null || strlen($href) > strlen($best['href']))) {
                $best = $item + ['group' => $g['label']];
            }
        }
    }
    if ($best === null && $path === '/account') {
        return ['href' => '/account', 'label' => 'Мой профиль', 'icon' => 'user', 'group' => 'Личное'];
    }
    return $best;
}

/**
 * Чистит HTML базы знаний: только разметка текста, без скриптов, обработчиков и javascript:-ссылок.
 * Нужна, потому что редактировать статьи теперь могут не только владельцы.
 */
function sanitize_html(string $html): string
{
    $allowed = [
        'p' => [], 'br' => [], 'hr' => [], 'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [],
        'mark' => [], 'small' => [], 'sup' => [], 'sub' => [], 'code' => [], 'pre' => [], 'span' => [], 'div' => [],
        'h2' => [], 'h3' => [], 'h4' => [], 'blockquote' => ['data-variant'], 'ul' => ['data-type'], 'ol' => ['start'],
        'li' => ['data-checked'], 'label' => [], 'a' => ['href', 'title', 'target'], 'img' => ['src', 'alt', 'width', 'height'],
        'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => ['colspan', 'rowspan'], 'td' => ['colspan', 'rowspan'],
    ];
    $html = trim($html);
    if ($html === '') {
        return '';
    }
    if (!class_exists('DOMDocument')) {
        $tags = '<' . implode('><', array_keys($allowed)) . '>';
        $clean = strip_tags($html, $tags);
        $clean = preg_replace('/\s(on\w+|style|class|id)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean) ?? '';
        return preg_replace('/(href|src)\s*=\s*("|\')\s*(javascript|data|vbscript):[^"\']*\2/i', '$1="#"', $clean) ?? '';
    }
    $doc = new DOMDocument('1.0', 'UTF-8');
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="gp-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $root = $doc->getElementById('gp-root');
    if (!$root) {
        return h(strip_tags($html));
    }
    $safeUrl = static function (string $url): bool {
        $url = trim($url);
        if ($url === '' || $url[0] === '#' || $url[0] === '/') {
            return true;
        }
        return (bool) preg_match('#^(https?:|mailto:|tel:)#i', $url);
    };
    $walk = static function (DOMNode $node) use (&$walk, $allowed, $safeUrl): void {
        for ($child = $node->firstChild; $child !== null; $child = $next) {
            $next = $child->nextSibling;
            if ($child instanceof DOMComment || $child instanceof DOMProcessingInstruction) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'svg', 'math', 'link', 'meta', 'base'], true)) {
                $node->removeChild($child);
                continue;
            }
            if (!isset($allowed[$tag])) {
                $walk($child);
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }
            $keep = $allowed[$tag];
            for ($i = $child->attributes->length - 1; $i >= 0; $i--) {
                $attr = $child->attributes->item($i);
                if (!$attr) {
                    continue;
                }
                $name = strtolower($attr->nodeName);
                if (!in_array($name, $keep, true)) {
                    $child->removeAttribute($attr->nodeName);
                    continue;
                }
                if (in_array($name, ['href', 'src'], true) && !$safeUrl($attr->nodeValue ?? '')) {
                    $child->removeAttribute($attr->nodeName);
                }
            }
            if ($tag === 'a') {
                $child->setAttribute('rel', 'noopener noreferrer');
                if ($child->getAttribute('target') !== '' && $child->getAttribute('target') !== '_blank') {
                    $child->removeAttribute('target');
                }
            }
            $walk($child);
        }
    };
    $walk($root);
    $out = '';
    foreach ($root->childNodes as $n) {
        $out .= $doc->saveHTML($n);
    }
    return trim($out);
}
