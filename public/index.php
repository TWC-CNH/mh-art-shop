<?php
// Einziger Einstiegspunkt. Apache leitet alles, was keine vorhandene Datei ist, hierher (.htaccess).
declare(strict_types=1);

use App\Controller\AtelierController;
use App\Controller\CheckoutController;
use App\Controller\InquiryController;
use App\Controller\ShopController;
use App\Controller\WithdrawalController;
use App\Config;
use App\Http;
use App\Log;
use App\View\Legal;

// Eingebauter PHP-Server (lokal): vorhandene Dateien direkt ausliefern
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($file !== __DIR__ . '/' && is_file($file) && !str_ends_with($file, '.php')) {
        return false;
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';

date_default_timezone_set('Europe/Berlin');
$local = Config::get()['env'] === 'local';
ini_set('display_errors', $local ? '1' : '0');
error_reporting(E_ALL);
set_exception_handler(function (\Throwable $e) use ($local) {
    Log::error(get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo '<!doctype html><meta charset="utf-8"><title>Kurz nicht erreichbar</title><p style="font-family:sans-serif;padding:2rem">Der Shop ist gerade kurz nicht erreichbar. Bitte in einer Minute neu laden.</p>';
    if ($local) {
        echo '<pre>' . htmlspecialchars((string) $e) . '</pre>';
    }
});

// Sicherheits-Header (zusätzlich zu .htaccess, damit sie auch lokal gelten)
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header('X-Frame-Options: SAMEORIGIN');

$path = Http::path();

match (true) {
    $path === '/' => ShopController::home(),
    (bool) preg_match('#^/werk/([^/]{1,140})$#', $path, $m) => ShopController::work(rawurldecode($m[1])),
    $path === '/danke' => ShopController::thanks(),
    in_array(substr($path, 1), Legal::KEYS, true) => ShopController::legal(substr($path, 1)),
    $path === '/sitemap.xml' => ShopController::sitemap(),
    $path === '/feed.xml' => ShopController::feed(),
    $path === '/robots.txt' => ShopController::robots(),
    $path === '/api/status' => ShopController::status(),
    $path === '/api/checkout' => CheckoutController::checkout(),
    $path === '/api/cancel' => CheckoutController::cancel(),
    $path === '/api/stripe-webhook' => CheckoutController::webhook(),
    $path === '/vertrag-widerrufen' => WithdrawalController::handle(),
    $path === '/anfrage' => InquiryController::handle(),
    $path === '/atelier' || str_starts_with($path, '/atelier/') => AtelierController::handle($path),
    default => ShopController::notFound(),
};
