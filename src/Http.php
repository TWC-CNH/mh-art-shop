<?php
declare(strict_types=1);

namespace App;

/** Kleine Hilfen für Anfrage und Antwort. */
final class Http
{
    public static function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public static function path(): string
    {
        $p = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $p = rtrim($p, '/');
        return $p === '' ? '/' : $p;
    }

    public static function query(string $k): string
    {
        $v = $_GET[$k] ?? '';
        return is_string($v) ? $v : '';
    }

    public static function post(string $k, int $max = 10000): string
    {
        $v = $_POST[$k] ?? '';
        return is_string($v) ? mb_substr($v, 0, $max) : '';
    }

    public static function cookie(string $k): string
    {
        $v = $_COOKIE[$k] ?? '';
        return is_string($v) ? $v : '';
    }

    public static function wantsJson(): bool
    {
        return str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    /** JSON-Body (fetch mit Content-Type: application/json) */
    public static function json(): array
    {
        if (!str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
            return [];
        }
        $d = json_decode((string) file_get_contents('php://input'), true);
        return is_array($d) ? $d : [];
    }

    /**
     * Schutz gegen fremde Formulare (CSRF): POSTs müssen von der eigenen Seite kommen.
     * Fehlt der Origin-Header (sehr alte Browser), wird der Referer geprüft.
     */
    public static function sameOrigin(): bool
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $src = (string) ($_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));
        if ($src === '') {
            // Alle aktuellen Browser schicken bei POST einen Origin-Header. Fehlt Origin und Referer, lehnen wir ab.
            return false;
        }
        $h = parse_url($src, PHP_URL_HOST);
        $p = parse_url($src, PHP_URL_PORT);
        return $h !== null && ($p ? "$h:$p" : $h) === $host;
    }

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || str_starts_with(Config::get()['siteUrl'], 'https://');
    }

    public static function header(string $name, string $value): void
    {
        header("$name: " . str_replace(["\r", "\n"], '', $value));
    }

    public static function html(string $body, int $status = 200, bool $cache = false): never
    {
        http_response_code($status);
        self::header('Content-Type', 'text/html; charset=utf-8');
        self::header('Cache-Control', $cache ? 'public, max-age=60' : 'no-store');
        echo $body;
        exit;
    }

    public static function text(string $body, string $type, int $status = 200, string $cache = 'public, max-age=300'): never
    {
        http_response_code($status);
        self::header('Content-Type', $type);
        self::header('Cache-Control', $cache);
        echo $body;
        exit;
    }

    public static function jsonOut(array $data, int $status = 200, array $headers = []): never
    {
        http_response_code($status);
        self::header('Content-Type', 'application/json; charset=utf-8');
        self::header('Cache-Control', 'no-store');
        foreach ($headers as $k => $v) {
            self::header($k, $v);
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function redirect(string $to, int $status = 303): never
    {
        http_response_code($status);
        self::header('Location', $to);
        self::header('Cache-Control', 'no-store');
        exit;
    }

    /** Cookie setzen; Secure nur unter HTTPS (lokal läuft http://localhost). */
    public static function setCookie(string $name, string $value, int $maxAge, string $path = '/', string $sameSite = 'Strict'): void
    {
        setcookie($name, $value, [
            'expires' => $maxAge > 0 ? time() + $maxAge : time() - 3600,
            'path' => $path,
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => $sameSite,
        ]);
    }
}
