<?php

$configured = getenv('IGDL_TEST_BASE_PATH');
$basePath = is_string($configured) && $configured !== '' ? $configured : dirname(__DIR__, 4);

if (! is_file($basePath.'/vendor/autoload.php')) {
    $fallback = dirname(__DIR__, 3).'/snapigdownloader';

    if (is_file($fallback.'/vendor/autoload.php')) {
        $basePath = $fallback;
    }
}

require $basePath.'/vendor/autoload.php';

spl_autoload_register(function (string $class): void {
    $prefix = 'Rastographer\\IgDownloader\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = dirname(__DIR__).'/src/'.str_replace('\\', '/', $relative).'.php';

    if (is_file($path)) {
        require_once $path;
    }
}, true, true);

require __DIR__.'/TestCase.php';
