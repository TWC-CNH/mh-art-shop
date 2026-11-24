<?php
// Gemeinsamer Start für Kommandozeilen-Skripte.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require dirname(__DIR__) . '/vendor/autoload.php';
date_default_timezone_set('Europe/Berlin');
