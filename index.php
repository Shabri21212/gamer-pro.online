<?php
declare(strict_types=1);

if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $file = realpath(__DIR__ . rawurldecode($path));
    $assets = realpath(__DIR__ . '/assets');
    if ($file && $assets && str_starts_with($file, $assets . DIRECTORY_SEPARATOR) && is_file($file)
        && in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['css', 'js', 'woff2', 'svg', 'png', 'jpg', 'webp', 'ico'], true)) {
        return false;
    }
    if ($file && $file !== __FILE__ && is_file($file)) {
        http_response_code(404);
        exit;
    }
}

require __DIR__ . '/src/bootstrap.php';
Portal::run();
