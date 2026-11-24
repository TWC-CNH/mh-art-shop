<?php
declare(strict_types=1);

namespace App;

/**
 * Liest die .env-Datei (einfaches KEY=VALUE-Format, # für Kommentare – auch hinter dem Wert) ohne Abhängigkeit.
 * Werte aus der echten Umgebung (getenv) haben Vorrang, damit Tests und Server sie überschreiben können.
 * Es wird bewusst nichts per putenv() gesetzt: Geheimnisse sollen nicht an Kindprozesse vererbt werden.
 */
final class Env
{
    /** @var array<string,string> */
    private static array $file = [];
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        self::$loaded = true;
        self::$file = [];
        if (!is_file($path)) {
            return;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (!preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*?)\s*$/', $line, $m)) {
                continue;
            }
            $v = $m[2];
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
                $v = substr($v, 1, -1);
            } elseif (preg_match('/^(["\'])(.*?)\1\s+#/', $v, $q)) {
                $v = $q[2]; // in Anführungszeichen, dahinter ein Kommentar
            } else {
                // Kommentar hinter dem Wert („KEY=wert   # Erklärung“) gehört nicht dazu.
                // Ein # direkt im Wert (ohne Leerzeichen davor) bleibt erhalten; sonst Wert in Anführungszeichen setzen.
                $v = rtrim((string) preg_replace('/\s+#.*$/', '', $v));
            }
            self::$file[$m[1]] = $v;
        }
    }

    public static function get(string $key, string $default = ''): string
    {
        if (!self::$loaded) {
            self::load(dirname(__DIR__) . '/.env');
        }
        $real = getenv($key);
        $v = $real !== false ? $real : (self::$file[$key] ?? null);
        return $v === null || $v === '' ? $default : (string) $v;
    }
}
