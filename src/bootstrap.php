<?php

declare(strict_types=1);

define('API_DEBUG', getenv('API_DEBUG') === '1');

ini_set('display_errors', '0');
error_reporting(E_ALL);
date_default_timezone_set('UTC');

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Api\\')) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

/** Fecha/hora actual en ISO 8601 UTC (ej. 2026-11-23T21:55:00Z). */
function now_iso(int $offsetSeconds = 0): string
{
    return gmdate('Y-m-d\TH:i:s\Z', time() + $offsetSeconds);
}
