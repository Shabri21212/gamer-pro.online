<?php
/*
 * Настройки портала для хостинга.
 *
 * 1. Скопируйте этот файл рядом под именем config.php.
 * 2. Впишите доступы к базе MySQL из панели reg.ru:
 *    «Хостинг» → «Базы данных MySQL» (там же видно имя базы и пользователя).
 * 3. Залейте в эту базу файл database/localhost.sql через phpMyAdmin → «Импорт».
 *
 * Пока config.php нет, портал работает на файле storage/gamerpro.sqlite — как раньше.
 */
return [
    'db' => [
        'driver' => 'mysql',
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'u3622481_default',
        'user' => 'u3622481_default',
        'pass' => 'ПАРОЛЬ_ОТ_БАЗЫ',
    ],
];
