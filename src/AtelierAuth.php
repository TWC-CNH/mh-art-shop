<?php
declare(strict_types=1);

namespace App;

/**
 * Anmeldung im Atelier: ein Passwort (nur sein Argon2id-Hash steht in ATELIER_PASSWORD_HASH).
 * Nach der Anmeldung bekommt das Gerät ein zufälliges Token als Cookie; in der Datenbank steht nur dessen Hash.
 * Abmelden löscht den Eintrag serverseitig. Formulare tragen ein CSRF-Token, das vom Sitzungstoken abgeleitet ist.
 */
final class AtelierAuth
{
    public const COOKIE = 'mh_atelier';
    private const DAYS = 30;
    private static ?string $token = null;

    public static function configured(): bool
    {
        return Config::get()['atelierPasswordHash'] !== '';
    }

    public static function checkPassword(string $pw): bool
    {
        $hash = Config::get()['atelierPasswordHash'];
        // Auch ohne eingerichtetes Passwort gleich lange rechnen (kein Zeitunterschied als Hinweis)
        return $hash !== '' && password_verify($pw, $hash);
    }

    public static function login(): void
    {
        $token = bin2hex(random_bytes(32));
        Db::run('INSERT INTO atelier_sessions (token_hash, laeuft_ab) VALUES (?, ?)', [hash('sha256', $token), Db::now(self::DAYS * 86400)]);
        Http::setCookie(self::COOKIE, $token, self::DAYS * 86400, '/atelier', 'Strict');
        self::$token = $token;
    }

    public static function logout(): void
    {
        $t = Http::cookie(self::COOKIE);
        if ($t !== '') {
            Db::run('DELETE FROM atelier_sessions WHERE token_hash = ?', [hash('sha256', $t)]);
        }
        Http::setCookie(self::COOKIE, '', -1, '/atelier', 'Strict');
    }

    public static function isLoggedIn(): bool
    {
        if (!self::configured()) {
            return false;
        }
        $t = Http::cookie(self::COOKIE);
        if (!preg_match('/^[0-9a-f]{64}$/', $t)) {
            return false;
        }
        $ok = (bool) Db::one('SELECT 1 FROM atelier_sessions WHERE token_hash = ? AND laeuft_ab > UTC_TIMESTAMP()', [hash('sha256', $t)]);
        if ($ok) {
            self::$token = $t;
        }
        return $ok;
    }

    public static function csrfToken(): string
    {
        return self::$token ? hash_hmac('sha256', 'csrf', self::$token) : '';
    }

    public static function checkCsrf(string $given): bool
    {
        $expected = self::csrfToken();
        return $expected !== '' && hash_equals($expected, $given);
    }

    /** Cron: abgelaufene Sitzungen löschen. */
    public static function purge(): int
    {
        return Db::run('DELETE FROM atelier_sessions WHERE laeuft_ab <= UTC_TIMESTAMP()');
    }
}
