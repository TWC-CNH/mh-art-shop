<?php
declare(strict_types=1);

namespace App;

/**
 * Protokoll nach LOG_DIR (Standard: ~/logs auf dem Server, lokal storage/logs).
 * Zeitstempel mit Zeitzonen-Offset, damit Log und Datenbank (UTC) zusammenpassen.
 * Keine personenbezogenen Daten ins Log schreiben.
 */
final class Log
{
    private static function dir(): string
    {
        $d = Env::get('LOG_DIR', dirname(__DIR__) . '/storage/logs');
        if (!is_dir($d)) {
            @mkdir($d, 0750, true);
        }
        return $d;
    }

    public static function write(string $level, string $msg): void
    {
        $line = sprintf("[%s] %s %s\n", date('Y-m-d H:i:s P'), strtoupper($level), str_replace(["\r", "\n"], ' ', $msg));
        if (@file_put_contents(self::dir() . '/shop-' . date('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX) === false) {
            error_log(trim($line));
        }
    }

    public static function info(string $m): void { self::write('info', $m); }
    public static function warn(string $m): void { self::write('warn', $m); }
    public static function error(string $m): void { self::write('error', $m); }
}
