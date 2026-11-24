<?php
declare(strict_types=1);

namespace App;

/**
 * Anmeldung im Atelier: Benutzername, Passwort (Argon2id) und Zwei-Faktor-Code (TOTP) – alle drei nötig.
 * Benutzer werden per SSH mit bin/atelier-user.php angelegt; das TOTP-Geheimnis steht nur verschlüsselt in der Datenbank.
 *
 * Nach der Anmeldung bekommt das Gerät ein zufälliges Token als Cookie ("<benutzer-id>.<token>"). In der Datenbank
 * steht nur ein HMAC des Tokens, gebunden an den Passwort-Hash des Benutzers: Ein neues Passwort meldet alle Geräte
 * dieses Benutzers ab, ein deaktivierter Benutzer ist sofort draußen. Formulare tragen ein CSRF-Token.
 */
final class AtelierAuth
{
    public const COOKIE = 'mh_atelier';
    private const DAYS = 30;
    private static ?string $token = null;
    private static ?array $user = null;
    /** Feste Hashes eines zufälligen, nirgends gespeicherten Passworts (gleiche Parameter wie hashPassword) */
    private const DUMMY_ARGON2ID = '$argon2id$v=19$m=65536,t=4,p=1$bHRzcHJ0WC9URzhoU2JYdA$yWxN7L2dVb+LRt0QfiZOXm6bcvOp0n76/dH5Lz8plSQ';
    private const DUMMY_BCRYPT = '$2y$12$3F3haVSgASlKLRjSyKEM6uLr4RqhAlqm6OlBQbDRMxD6YDQ4bib1.';

    /** Gibt es mindestens einen aktiven Benutzer mit eingerichteter Zwei-Faktor-Anmeldung? */
    public static function configured(): bool
    {
        return (bool) Db::one('SELECT 1 FROM atelier_benutzer WHERE aktiv = 1 AND totp_enc IS NOT NULL LIMIT 1');
    }

    private static function tokenHash(string $token, string $passwordHash): string
    {
        return hash_hmac('sha256', $token, $passwordHash);
    }

    /**
     * Prüft Benutzer, Passwort und Code. Liefert den Benutzer oder null.
     * Rechnet auch bei unbekanntem Benutzer ein Passwort-Hashing, damit die Antwortzeit nichts verrät.
     */
    public static function attempt(string $name, string $password, string $code): ?array
    {
        $u = Db::one('SELECT * FROM atelier_benutzer WHERE benutzer = ? AND aktiv = 1', [mb_strtolower(trim($name))]);
        // Bei unbekanntem Benutzer gegen einen festen Hash prüfen: gleich viel Rechenzeit wie bei einem echten
        $pwOk = password_verify($password, $u['passwort_hash'] ?? (defined('PASSWORD_ARGON2ID') ? self::DUMMY_ARGON2ID : self::DUMMY_BCRYPT));
        if (!$u || !$pwOk || $u['totp_enc'] === null) {
            return null;
        }
        // Notfall-Code (Format XXXXX-XXXXX) statt App-Code: einmalig gültig
        if (preg_match('/^[A-Za-z0-9]{5}[\s-]?[A-Za-z0-9]{5}$/', trim($code))) {
            return self::useRecoveryCode($u, $code);
        }
        try {
            $secret = Crypto::decrypt($u['totp_enc'], 'atelier_benutzer/totp/' . $u['id'])['secret'];
        } catch (\Throwable $e) {
            Log::error('2FA-Geheimnis nicht lesbar für Benutzer ' . $u['id'] . ': ' . $e->getMessage());
            return null;
        }
        // Zuletzt benutzte Zeitschritte: jeder Code gilt genau einmal. (Nicht „alles bis zum letzten Schritt“ sperren –
        // sonst würde eine leicht vorgehende Handy-Uhr den nächsten korrekten Code eines zweiten Geräts aussperren.)
        $before = $u['totp_genutzt'];
        for ($try = 0; $try < 3; $try++) {
            $usedSteps = array_map('intval', array_filter(explode(',', (string) $before), 'strlen'));
            $step = Totp::verify($secret, $code, null, null, $usedSteps);
            if ($step === null) {
                return null;
            }
            $now = Totp::currentStep();
            $keep = array_values(array_filter([...$usedSteps, $step], fn ($s) => $s >= $now - 2));
            // Atomar verbrauchen: nur wenn niemand sonst seit dem Lesen einen Code benutzt hat
            if (Db::run(
                'UPDATE atelier_benutzer SET totp_genutzt = ?, totp_letzter_schritt = ?, letzter_login = UTC_TIMESTAMP() WHERE id = ? AND totp_genutzt <=> ?',
                [implode(',', $keep), $step, $u['id'], $before]
            ) === 1) {
                return $u;
            }
            // Gleichzeitige Anmeldung hat zuerst einen (anderen) Code verbraucht: neu lesen und noch einmal prüfen
            $before = Db::one('SELECT totp_genutzt FROM atelier_benutzer WHERE id = ?', [$u['id']])['totp_genutzt'] ?? null;
        }
        return null;
    }

    // ---------- Notfall-Codes ----------

    private const RECOVERY_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // ohne leicht verwechselbare Zeichen (0/O, 1/I)
    public const RECOVERY_COUNT = 10;

    private static function normalizeRecovery(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    /** Prüft einen Notfall-Code und verbraucht ihn. Liefert den Benutzer (mit '_notfall_rest') oder null. */
    private static function useRecoveryCode(array $u, string $code): ?array
    {
        $norm = self::normalizeRecovery($code);
        // Nur Codes mit passender Kennung (erste zwei Zeichen) prüfen – meist genau einer statt bis zu zehn Argon2id-Prüfungen
        foreach (Db::all('SELECT id, code_hash FROM atelier_notfallcodes WHERE benutzer_id = ? AND benutzt IS NULL AND (kennung = ? OR kennung IS NULL)', [$u['id'], substr($norm, 0, 2)]) as $row) {
            if (password_verify($norm, $row['code_hash'])) {
                if (Db::run('UPDATE atelier_notfallcodes SET benutzt = UTC_TIMESTAMP() WHERE id = ? AND benutzt IS NULL', [$row['id']]) !== 1) {
                    return null; // gleichzeitig schon verbraucht
                }
                Db::run('UPDATE atelier_benutzer SET letzter_login = UTC_TIMESTAMP() WHERE id = ?', [$u['id']]);
                Log::warn('Atelier: Notfall-Code verwendet für Benutzer ' . $u['id']);
                $u['_notfall_rest'] = (int) Db::one('SELECT COUNT(*) AS n FROM atelier_notfallcodes WHERE benutzer_id = ? AND benutzt IS NULL', [$u['id']])['n'];
                return $u;
            }
        }
        return null;
    }

    /** Erzeugt neue Notfall-Codes (alte werden ungültig). Liefert die Codes im Klartext – nur einmal anzeigen. */
    public static function newRecoveryCodes(string $name): array
    {
        $u = Db::one('SELECT id FROM atelier_benutzer WHERE benutzer = ?', [mb_strtolower(trim($name))]);
        if (!$u) {
            throw new \InvalidArgumentException("Benutzer $name gibt es nicht.");
        }
        $codes = [];
        Db::transaction(function () use ($u, &$codes) {
            Db::run('DELETE FROM atelier_notfallcodes WHERE benutzer_id = ?', [$u['id']]);
            for ($i = 0; $i < self::RECOVERY_COUNT; $i++) {
                $c = '';
                for ($j = 0; $j < 10; $j++) {
                    $c .= self::RECOVERY_ALPHABET[random_int(0, strlen(self::RECOVERY_ALPHABET) - 1)];
                }
                $codes[] = substr($c, 0, 5) . '-' . substr($c, 5);
                Db::run('INSERT INTO atelier_notfallcodes (benutzer_id, code_hash, kennung) VALUES (?, ?, ?)', [$u['id'], self::hashPassword($c), substr($c, 0, 2)]);
            }
        });
        return $codes;
    }

    public static function login(array $user): void
    {
        $token = bin2hex(random_bytes(32));
        Db::run('INSERT INTO atelier_sessions (token_hash, benutzer_id, laeuft_ab) VALUES (?, ?, ?)', [self::tokenHash($token, $user['passwort_hash']), $user['id'], Db::now(self::DAYS * 86400)]);
        Http::setCookie(self::COOKIE, $user['id'] . '.' . $token, self::DAYS * 86400, '/atelier', 'Strict');
        self::$token = $token;
        self::$user = $user;
    }

    /** @return array{0:int,1:string}|null Benutzer-ID und Token aus dem Cookie */
    private static function parseCookie(): ?array
    {
        if (!preg_match('/^(\d{1,10})\.([0-9a-f]{64})$/', Http::cookie(self::COOKIE), $m)) {
            return null;
        }
        return [(int) $m[1], $m[2]];
    }

    public static function logout(): void
    {
        if ($c = self::parseCookie()) {
            $u = Db::one('SELECT passwort_hash FROM atelier_benutzer WHERE id = ?', [$c[0]]);
            if ($u) {
                Db::run('DELETE FROM atelier_sessions WHERE token_hash = ?', [self::tokenHash($c[1], $u['passwort_hash'])]);
            }
        }
        Http::setCookie(self::COOKIE, '', -1, '/atelier', 'Strict');
    }

    public static function isLoggedIn(): bool
    {
        $c = self::parseCookie();
        if (!$c) {
            return false;
        }
        $u = Db::one('SELECT * FROM atelier_benutzer WHERE id = ? AND aktiv = 1', [$c[0]]);
        if (!$u) {
            return false;
        }
        $ok = (bool) Db::one('SELECT 1 FROM atelier_sessions WHERE token_hash = ? AND benutzer_id = ? AND laeuft_ab > UTC_TIMESTAMP()', [self::tokenHash($c[1], $u['passwort_hash']), $u['id']]);
        if ($ok) {
            self::$token = $c[1];
            self::$user = $u;
        }
        return $ok;
    }

    public static function userName(): string
    {
        return (string) (self::$user['benutzer'] ?? '');
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

    // ---------- Benutzerverwaltung (bin/atelier-user.php) ----------

    public static function hashPassword(string $pw): string
    {
        return password_hash($pw, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
    }

    /** Legt einen Benutzer an oder setzt sein Passwort neu (meldet alle Geräte ab). Neue Benutzer bekommen 2FA. */
    public static function saveUser(string $name, string $password): ?string
    {
        $name = mb_strtolower(trim($name));
        if (!preg_match('/^[a-z0-9._-]{3,60}$/', $name)) {
            throw new \InvalidArgumentException('Benutzername: 3–60 Zeichen, nur a–z, 0–9, Punkt, Bindestrich, Unterstrich.');
        }
        if (mb_strlen($password) < 12 || mb_strlen($password) > 500) {
            throw new \InvalidArgumentException('Passwort: 12 bis 500 Zeichen (das Anmeldeformular nimmt höchstens 500 an).');
        }
        $hash = self::hashPassword($password);
        $existing = Db::one('SELECT id, totp_enc FROM atelier_benutzer WHERE benutzer = ?', [$name]);
        if ($existing) {
            Db::run('UPDATE atelier_benutzer SET passwort_hash = ? WHERE id = ?', [$hash, $existing['id']]); // Sperre bleibt bestehen
            Db::run('DELETE FROM atelier_sessions WHERE benutzer_id = ?', [$existing['id']]);
            return $existing['totp_enc'] === null ? self::reset2fa($name) : null;
        }
        Db::run('INSERT INTO atelier_benutzer (benutzer, passwort_hash) VALUES (?, ?)', [$name, $hash]);
        return self::reset2fa($name);
    }

    /** Neues TOTP-Geheimnis (z. B. bei neuem Handy). Meldet alle Geräte des Benutzers ab. */
    public static function reset2fa(string $name): string
    {
        $u = Db::one('SELECT id FROM atelier_benutzer WHERE benutzer = ?', [mb_strtolower(trim($name))]);
        if (!$u) {
            throw new \InvalidArgumentException("Benutzer $name gibt es nicht.");
        }
        $secret = Totp::newSecret();
        Db::run('UPDATE atelier_benutzer SET totp_enc = ?, totp_letzter_schritt = NULL, totp_genutzt = NULL WHERE id = ?', [Crypto::encrypt(['secret' => $secret], 'atelier_benutzer/totp/' . $u['id']), $u['id']]);
        Db::run('DELETE FROM atelier_sessions WHERE benutzer_id = ?', [$u['id']]);
        return $secret;
    }

    public static function setActive(string $name, bool $active): bool
    {
        if (!Db::one('SELECT 1 FROM atelier_benutzer WHERE benutzer = ?', [mb_strtolower(trim($name))])) {
            return false;
        }
        Db::run('UPDATE atelier_benutzer SET aktiv = ? WHERE benutzer = ?', [$active ? 1 : 0, mb_strtolower(trim($name))]);
        if (!$active) {
            Db::run('DELETE s FROM atelier_sessions s JOIN atelier_benutzer b ON b.id = s.benutzer_id WHERE b.benutzer = ?', [mb_strtolower(trim($name))]);
        }
        return true;
    }
}
