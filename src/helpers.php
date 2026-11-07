<?php
declare(strict_types=1);

const MONTH_NAMES = ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
const MONTH_NAMES_GENITIVE = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
const WEEKDAY_SHORT = ['Пн','Вт','Ср','Чт','Пт','Сб','Вс'];
const WEEKDAY_LONG = ['понедельник','вторник','среда','четверг','пятница','суббота','воскресенье'];
const SHIFT_LABELS = ['day' => 'День', 'night' => 'Ночь', 'full' => 'Сутки'];
const SHIFT_HINTS = ['day' => '08:00–20:00', 'night' => '20:00–08:00', 'full' => '24 часа'];
const INCIDENT_KIND = ['incident' => 'Происшествие', 'tech' => 'Поломка'];
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

const NAV_GROUPS = [
    ['id' => 'shifts', 'label' => 'Смены', 'ownerOnly' => false, 'items' => [
        ['href' => '/', 'label' => 'Мой день', 'hint' => 'смена и мои задачи', 'icon' => 'sun'],
        ['href' => '/schedule', 'label' => 'График смен', 'hint' => 'кто и когда работает', 'icon' => 'calendar'],
        ['href' => '/cleaning', 'label' => 'Чистка клавиатур', 'hint' => 'разнарядка на неделю', 'icon' => 'spray'],
    ]],
    ['id' => 'club', 'label' => 'Клуб', 'ownerOnly' => false, 'items' => [
        ['href' => '/issues', 'label' => 'Поломки', 'hint' => 'техника и происшествия', 'icon' => 'alert', 'badge' => 'issues'],
        ['href' => '/news', 'label' => 'Объявления', 'hint' => 'важное от руководства', 'icon' => 'megaphone', 'badge' => 'news'],
    ]],
    ['id' => 'guests', 'label' => 'Гости', 'ownerOnly' => false, 'items' => [
        ['href' => '/loyalty', 'label' => 'Бонусная система', 'hint' => 'уровни и кэшбек', 'icon' => 'percent'],
        ['href' => '/reviews', 'label' => 'Бонусы за отзывы', 'hint' => 'заявки на зачисление', 'icon' => 'heart', 'badge' => 'reviews'],
        ['href' => '/compensations', 'label' => 'Компенсации', 'hint' => 'возмещения гостям', 'icon' => 'coins', 'badge' => 'compensations'],
    ]],
    ['id' => 'rules', 'label' => 'Регламенты', 'ownerOnly' => false, 'items' => [
        ['href' => '/wiki', 'label' => 'База знаний', 'hint' => 'инструкции и регламенты', 'icon' => 'book'],
        ['href' => '/tariffs', 'label' => 'Тарифы', 'hint' => 'прайс Гондатти и Киевская', 'icon' => 'tags', 'description' => 'Пакеты и цены обоих филиалов'],
        ['href' => '/fines', 'label' => 'Лист штрафов', 'hint' => 'нарушения и суммы', 'icon' => 'scale'],
        ['href' => '/exams', 'label' => 'Экзамены', 'hint' => 'проверка знаний', 'icon' => 'grad'],
    ]],
    ['id' => 'personal', 'label' => 'Личное', 'ownerOnly' => false, 'items' => [
        ['href' => '/account', 'label' => 'Мой профиль', 'hint' => 'смены, ставка, уровень', 'icon' => 'user'],
    ]],
    ['id' => 'manage', 'label' => 'Управление', 'ownerOnly' => true, 'items' => [
        ['href' => '/manage/journal', 'label' => 'Журнал', 'hint' => 'касса, заявки, задания', 'icon' => 'history', 'ownerOnly' => true],
        ['href' => '/manage/employees', 'label' => 'Сотрудники', 'hint' => 'карточки и филиалы', 'icon' => 'users', 'ownerOnly' => true],
        ['href' => '/manage/grades', 'label' => 'Уровни и ставки', 'hint' => 'сколько стоит смена', 'icon' => 'wallet', 'ownerOnly' => true],
        ['href' => '/manage/tasks', 'label' => 'Задания дня', 'hint' => 'каждому на день или ночь', 'icon' => 'clipboard-list', 'ownerOnly' => true],
        ['href' => '/reviews/edit', 'label' => 'Акция за отзывы', 'hint' => 'суммы и текст условий', 'icon' => 'heart', 'ownerOnly' => true],
        ['href' => '/tariffs/edit', 'label' => 'Тарифы', 'hint' => 'прайс и тексты блоков', 'icon' => 'tags', 'ownerOnly' => true],
        ['href' => '/manage/accounts', 'label' => 'Доступы', 'hint' => 'логины и пароли', 'icon' => 'key', 'ownerOnly' => true],
        ['href' => '/manage/exams', 'label' => 'Проверка экзаменов', 'hint' => 'результаты сдач', 'icon' => 'clipboard-check', 'ownerOnly' => true],
    ]],
];

function now_tyumen(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone('Asia/Yekaterinburg'));
}

function date_key(DateTimeImmutable $d): string
{
    return $d->format('Y-m-d');
}

function parse_date_key(string $key): ?array
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $key, $m)) {
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
    return (int) ((new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month + 1, $day)))->format('N')) - 1;
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

function format_long_date(int $year, int $month, int $day): string
{
    $wd = WEEKDAY_LONG[monday_first_weekday($year, $month, $day)];
    return $day . ' ' . MONTH_NAMES_GENITIVE[$month] . ', ' . $wd;
}

function format_date_human(string $key): string
{
    $p = parse_date_key($key);
    if (!$p) {
        return $key;
    }
    $wd = WEEKDAY_LONG[monday_first_weekday($p['year'], $p['month'], $p['day'])];
    return $wd . ', ' . $p['day'] . ' ' . MONTH_NAMES_GENITIVE[$p['month']];
}

function dates_between(string $from, string $to, int $max = 42): array
{
    $tz = new DateTimeZone('Asia/Yekaterinburg');
    $a = DateTimeImmutable::createFromFormat('!Y-m-d', $from, $tz);
    $b = DateTimeImmutable::createFromFormat('!Y-m-d', $to, $tz);
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

function plural_word(int $n, string $one, string $few, string $many): string
{
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

function news_preview(string $body, int $len = 120): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $body) ?? '');
    if (mb_strlen($text) <= $len) {
        return $text;
    }
    return rtrim(mb_substr($text, 0, $len)) . '…';
}

function emp_filter_class(): string
{
    return 'rounded-lg border border-border bg-card px-3 py-2 text-sm font-medium text-muted-foreground shadow-sm transition-all hover:border-foreground/25 hover:text-foreground hover:shadow-md';
}

function td_th(string $label, string $inner, string $class = 'px-3 py-2'): string
{
    return '<td class="' . $class . '" data-th="' . h($label) . '">' . $inner . '</td>';
}

function th_th(string $label, string $inner, string $class = 'px-3 py-2 text-left font-medium'): string
{
    return '<th scope="row" class="' . $class . '" data-th="' . h($label) . '">' . $inner . '</th>';
}

function short_branch(string $name): string
{
    return mb_strlen($name) <= 7 ? $name : mb_substr($name, 0, 5) . '.';
}

function money_rub(int|float $n): string
{
    return number_format((float) $n, 0, ',', ' ') . ' ₽';
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

function format_phone(string $digits): string
{
    if (strlen($digits) !== 10) {
        return $digits;
    }
    return '+7 ' . substr($digits, 0, 3) . ' ' . substr($digits, 3, 3) . '-' . substr($digits, 6, 2) . '-' . substr($digits, 8, 2);
}

function boolish(mixed $v): bool
{
    return $v === true || $v === 1 || $v === '1';
}

function icon(string $name, string $class = 'size-4'): string
{
    $paths = [
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>',
        'moon' => '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
        'moon-star' => '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/><path d="M19 3v4M21 5h-4"/>',
        'sun-moon' => '<path d="M12 8a2.83 2.83 0 0 0 4 4 4 4 0 1 1-4-4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M6.3 17.7l-1.4 1.4M19.1 4.9l-1.4 1.4"/>',
        'calendar' => '<path d="M8 2v4M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
        'clipboard-check' => '<rect width="8" height="4" x="8" y="2" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/>',
        'clipboard-list' => '<rect width="8" height="4" x="8" y="2" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4M12 16h4M8 11h.01M8 16h.01"/>',
        'spray' => '<path d="M3 3h.01M7 5h.01M11 7h.01M3 7h.01M7 9h.01M3 11h.01"/><rect width="4" height="4" x="15" y="5"/><path d="m19 9 2 2v10c0 .6-.4 1-1 1h-6c-.6 0-1-.4-1-1V11l2-2"/><path d="m13 14 8-2"/><path d="m13 19 8-2"/>',
        'alert' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4M12 17h.01"/>',
        'megaphone' => '<path d="m3 11 18-5v12L3 14v-3Z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
        'percent' => '<path d="M19 5 5 19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
        'heart' => '<path d="M2.5 12.5c0-4 3-7 6.5-7 2 0 3.5 1 4.5 2.5C14.5 6.5 16 5.5 18 5.5c3.5 0 6.5 3 6.5 7 0 6-8.5 10.5-11 10.5S2.5 18.5 2.5 12.5Z"/>',
        'coins' => '<circle cx="8" cy="8" r="6"/><path d="M18.09 10.37A6 6 0 1 1 10.34 18"/><path d="M7 6h1v4M16.71 13.88.02.02"/>',
        'book' => '<path d="M12 7v14"/><path d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"/>',
        'tags' => '<path d="m15 5 6.3 6.3a2.4 2.4 0 0 1 0 3.4L13 23l-8.7-8.7a2.4 2.4 0 0 1 0-3.4L13 2Z"/><path d="M9.5 9.5v.01"/>',
        'scale' => '<path d="m16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="m2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="M7 21h10"/><path d="M12 3v18"/><path d="M3 7h2c2 0 5-1 7-2 2 1 5 2 7 2h2"/>',
        'grad' => '<path d="M21.42 10.922a1 1 0 0 0-.019-1.838L12.83 5.18a2 2 0 0 0-1.66 0L2.6 9.08a1 1 0 0 0 0 1.832l8.57 3.908a2 2 0 0 0 1.66 0z"/><path d="M22 10v6"/><path d="M6 12.5V16a6 3 0 0 0 12 0v-3.5"/>',
        'user' => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'history' => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>',
        'wallet' => '<path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2.3"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>',
        'key' => '<path d="m15.5 7.5 2.3 2.3a1 1 0 0 0 1.4 0l2.1-2.1a1 1 0 0 0 0-1.4L19 4"/><path d="m21 2-9.6 9.6"/><circle cx="7.5" cy="15.5" r="5.5"/>',
        'menu' => '<path d="M4 5h16M4 12h16M4 19h16"/>',
        'x' => '<path d="M18 6 6 18M6 6l12 12"/>',
        'chevrondown' => '<path d="m6 9 6 6 6-6"/>',
        'chevronup' => '<path d="m18 15-6-6-6 6"/>',
        'trash' => '<path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M10 11v6M14 11v6"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>',
        'arrow' => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
        'arrow-up-right' => '<path d="M7 7h10v10"/><path d="M7 17 17 7"/>',
        'eye' => '<path d="M2.06 12.35a1 1 0 0 1 0-.7C3.87 7.5 7.6 4.5 12 4.5s8.13 3 9.94 7.15a1 1 0 0 1 0 .7C20.13 16.5 16.4 19.5 12 19.5S3.87 16.5 2.06 12.35Z"/><circle cx="12" cy="12" r="3"/>',
        'eye-off' => '<path d="M10.7 5.1A10.9 10.9 0 0 1 12 5c7 0 10 7 10 7a13.4 13.4 0 0 1-1.7 2.5"/><path d="M6.6 6.6C3.5 8.7 2 12 2 12s3 7 10 7a9.8 9.8 0 0 0 5.4-1.6"/><path d="M14.1 14.1a3 3 0 1 1-4.2-4.2"/><path d="m2 2 20 20"/>',
        'pencil' => '<path d="M21.2 6.4a2.4 2.4 0 0 0-3.4-3.4L5 15.8 3 21l5.2-2Z"/><path d="m15 5 4 4"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'plus' => '<path d="M5 12h14M12 5v14"/>',
        'pin' => '<path d="M20 10c0 4.4-8 12-8 12S4 14.4 4 10a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
        'gift' => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M12 8v13"/><path d="M19 12v7a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-7"/><path d="M7.5 8a2.5 2.5 0 0 1 0-5A4.8 8 0 0 1 12 8a4.8 8 0 0 1 4.5-5 2.5 2.5 0 0 1 0 5"/>',
        'cake' => '<path d="M20 21v-8a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8"/><path d="M4 16s.5-1 2-1 2.5 2 4 2 2.5-2 4-2 2.5 2 4 2 2-1 2-1"/><path d="M2 21h20"/><path d="M7 8v3M12 8v3M17 8v3"/><path d="M7 4h.01M12 4h.01M17 4h.01"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'list' => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'chevronleft' => '<path d="m15 18-6-6 6-6"/>',
        'chevronright' => '<path d="m9 18 6-6-6-6"/>',
        'check-circle' => '<path d="M21.8 10A10 10 0 1 1 17 3.3"/><path d="m9 11 3 3L22 4"/>',
    ];
    $d = $paths[$name] ?? $paths['sun'];
    return '<svg class="' . h($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
}

function btn_class(string $variant = 'default', string $size = 'default'): string
{
    $base = 'group/button inline-flex shrink-0 items-center justify-center rounded-xl border border-transparent bg-clip-padding text-sm font-semibold whitespace-nowrap transition-all outline-none select-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-50 gap-1.5';
    $v = [
        'default' => 'bg-primary text-primary-foreground [a]:hover:bg-primary/80',
        'outline' => 'border-border bg-background hover:bg-muted hover:text-foreground',
        'secondary' => 'bg-secondary text-secondary-foreground hover:bg-secondary/80',
        'ghost' => 'hover:bg-muted hover:text-foreground',
        'destructive' => 'bg-destructive/10 text-destructive hover:bg-destructive/20',
        'link' => 'text-primary underline-offset-4 hover:underline',
    ][$variant] ?? 'bg-primary text-primary-foreground';
    $s = [
        'default' => 'h-8 px-2.5',
        'sm' => 'h-7 px-2.5 text-[0.8rem]',
        'lg' => 'h-9 px-2.5',
        'xl' => 'h-11 px-6 text-base',
        'icon' => 'size-8 p-0',
    ][$size] ?? 'h-8 px-2.5';
    return "$base $v $s";
}

function input_class(string $extra = ''): string
{
    return trim('h-8 w-full min-w-0 rounded-lg border border-input bg-transparent px-2.5 py-1 text-base md:text-sm transition-colors outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 ' . $extra);
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
                $best = $item;
            }
        }
    }
    return $best;
}

function exam_slugify(string $value): string
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
    $out = trim($out, '-');
    $out = substr($out, 0, 60);
    return $out !== '' ? $out : ('ekzamen-' . time());
}

function exam_unique_slug(string $base): string
{
    if (!Db::one('SELECT id FROM exams WHERE slug = ?', [$base])) {
        return $base;
    }
    for ($i = 2; $i < 100; $i++) {
        $try = $base . '-' . $i;
        if (!Db::one('SELECT id FROM exams WHERE slug = ?', [$try])) {
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

function brand_logo(string $class = 'h-4 w-auto', bool $full = false): string
{
    $view = $full ? '0 0 870 119' : '0 0 870 67';
    $gamer = '<path d="M30.8997 66.0082L23.4753 58.5863V49.2359H72.7278V41.7656H38.1802V24.9921H89.4988V53.3057L76.7937 66.0082H30.8997Z M0 66.0082V28.3149L27.5642 0.750732H89.4988V10.0985L82.0756 17.523H33.5904L16.771 34.3424V58.5863L9.34781 66.0082H0Z M180.007 66.0082L156.371 17.523H141.441L128.908 43.1727H161.796V52.5256L154.371 59.9488H120.75L117.801 66.0082H98.9423L131.007 0.750732H166.81L198.684 66.0082H180.007Z M327.19 66.0082V0.750732H416.689V10.0985L409.266 17.523H343.735V24.992H410.629V41.7656H343.735V49.2359H409.267L416.689 56.6591V66.0082H327.19Z M299.712 66.0082V27.1041L265.644 44.1899H258.829L224.894 27.1041L224.754 66.0082H208.137V0L262.24 27.1423L316.338 0V66.0082H299.712Z M508.613 66.0541L492.477 41.7656H452.994V24.992H492.506L497.469 17.523H444.313V66.0082H427.539V0.750732H527.239L505.558 33.3814L527.234 66.0541H508.613Z" fill="currentColor"/>';
    $pro = '<path d="M801.514 66.0082L780.499 44.9929V0.750732H848.986L870 21.7673V66.0069L801.514 66.0082Z M853.227 49.2359V30.5276L840.222 17.523H797.274V36.2313L810.278 49.2372H853.227V49.2359Z M753.035 66.0541L736.898 41.7656H697.416V24.992H736.927L741.89 17.523H688.734V66.0082H671.961V0.750732H771.661L749.98 33.3814L771.657 66.0541H753.035Z M563.425 66.0082V0.750732H663.122L640.589 34.6661L635.53 41.7656H588.879V24.992H628.391L633.354 17.523H580.196V66.0082H563.425Z" fill="var(--brand)"/>';
    return '<svg viewBox="' . $view . '" class="' . h($class) . '" role="img" aria-label="GAMER PRO">' . $gamer . $pro . '</svg>';
}

function shift_badge(array $shift, bool $compact = true, ?int $myId = null): string
{
    $type = $shift['shiftType'] ?? 'day';
    $away = !empty($shift['awayAtBranchName']);
    $styles = [
        'day' => 'bg-shift-day text-shift-day-foreground',
        'night' => 'bg-shift-night text-shift-night-foreground',
        'full' => 'bg-shift-full text-shift-full-foreground',
    ];
    $icons = ['day' => 'sun', 'night' => 'moon', 'full' => 'sun-moon'];
    $cls = $away
        ? 'bg-muted text-muted-foreground border-border border border-dashed'
        : ($styles[$type] ?? $styles['day']);
    if ($myId && (int) ($shift['employeeId'] ?? 0) === $myId && !$away) {
        $cls .= ' ring-foreground/25 ring-2';
    }
    $pad = $compact ? 'gp-shift items-start gap-1 px-2 py-1 leading-tight sm:items-center' : 'gp-shift items-center gap-1.5 px-2.5 py-2 text-sm';
    $iconSize = $compact ? 'size-3.5 shrink-0 mt-0.5 sm:mt-0' : 'size-4 shrink-0';
    $icon = $away ? icon('arrow-up-right', $iconSize) : icon($icons[$type] ?? 'sun', $iconSize);
    $extra = '';
    if (!empty($shift['guestFromBranchName'])) {
        $extra = '<span class="shrink-0 rounded bg-current/15 px-1 text-xs font-semibold uppercase">' . h(short_branch($shift['guestFromBranchName'])) . '</span>';
    }
    if ($away) {
        $extra = '<span class="gp-shift-away shrink-0 text-xs font-semibold">→ ' . h($shift['awayAtBranchName']) . '</span>';
    }
    $fullName = (string) ($shift['employeeName'] ?? '');
    $shown = short_name($fullName);
    $typeLabel = SHIFT_LABELS[$type] ?? $type;
    $typeHint = SHIFT_HINTS[$type] ?? '';
    $kindText = $away
        ? ('в другом клубе' . (!empty($shift['awayAtBranchName']) ? ' · ' . $shift['awayAtBranchName'] : ''))
        : ($typeLabel . ($typeHint !== '' ? ' · ' . $typeHint : ''));
    $nameHtml = '<span class="min-w-0 flex-1">'
        . '<span class="gp-shift-short truncate text-[11px] sm:text-xs" title="' . h($fullName) . '">' . h($shown) . '</span>'
        . '<span class="gp-shift-full">' . h($fullName) . '</span>'
        . '<span class="gp-shift-kind">' . h($kindText) . '</span>'
        . '</span>';
    return '<span class="flex min-w-0 rounded-md text-left font-semibold ' . $pad . ' ' . $cls . '">'
        . $icon
        . $nameHtml
        . $extra
        . '</span>';
}
