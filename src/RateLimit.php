<?php
declare(strict_types=1);

namespace App;

/**
 * Begrenzung von Versuchen je Zweck und Schlüssel (IP-Adresse, E-Mail) in einem Zeitfenster.
 * Gespeichert werden nur Zeitpunkte unter einem geheimen Hash, nie IP oder E-Mail im Klartext.
 * Fällt die Datenbank aus, wird nicht blockiert: Kaufen und Widerrufen sollen dann trotzdem gehen.
 */
final class RateLimit
{
    /**
     * IP-Adresse des Besuchers: REMOTE_ADDR. Nur wenn die Anfrage von einem eingetragenen Proxy kommt
     * (TRUSTED_PROXIES, kommagetrennt), wird der erste Eintrag aus X-Forwarded-For genommen.
     * Ohne diese Prüfung könnte jeder Besucher seine IP im Header frei wählen und die Begrenzung umgehen.
     */
    public static function clientIp(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unbekannt');
        $trusted = array_filter(array_map('trim', explode(',', Env::get('TRUSTED_PROXIES'))));
        if ($trusted && in_array($remote, $trusted, true)) {
            // Von rechts lesen: Jeder Proxy hängt die Adresse seines Absenders hinten an, die linken Einträge
            // kann der Besucher selbst setzen. Die erste nicht vertrauenswürdige Adresse von rechts ist der Besucher.
            $chain = array_reverse(array_map('trim', explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))));
            foreach ($chain as $addr) {
                if (!filter_var($addr, FILTER_VALIDATE_IP)) {
                    break;
                }
                if (!in_array($addr, $trusted, true)) {
                    return $addr;
                }
            }
        }
        return $remote;
    }

    private static function key(string $bucket, string $id): string
    {
        return Crypto::pseudonym($id, 'rl|' . $bucket);
    }

    private static function since(int $windowSec): string
    {
        return gmdate('Y-m-d H:i:s', time() - $windowSec);
    }

    /** Anzahl der Versuche im Fenster, ohne einen neuen zu zählen. */
    public static function attempts(string $bucket, string $id, int $windowSec): int
    {
        try {
            $r = Db::one('SELECT COUNT(*) AS n FROM rate_limits WHERE bucket = ? AND schluessel = ? AND zeitpunkt > ?', [$bucket, self::key($bucket, $id), self::since($windowSec)]);
            return (int) ($r['n'] ?? 0);
        } catch (\Throwable $e) {
            Log::warn('Rate-Limit nicht lesbar: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Zählt einen Versuch. Ist das Limit im Fenster erreicht, wird nicht gezählt.
     * @return array{ok: bool, retryAfter: int}
     */
    public static function hit(string $bucket, string $id, int $limit, int $windowSec): array
    {
        try {
            // Atomar auch bei vielen gleichzeitigen Anfragen: erst eintragen, dann zählen, wie viele Einträge
            // im Fenster bis einschließlich des eigenen (nach ID) existieren. Liegt der eigene Eintrag jenseits
            // des Limits, wird er wieder entfernt und abgelehnt. So kommen höchstens $limit Anfragen durch.
            $k = self::key($bucket, $id);
            $since = self::since($windowSec);
            Db::run('INSERT INTO rate_limits (bucket, schluessel, zeitpunkt) VALUES (?, ?, UTC_TIMESTAMP(3))', [$bucket, $k]);
            $mine = Db::lastId();
            $pos = (int) Db::one('SELECT COUNT(*) AS n FROM rate_limits WHERE bucket = ? AND schluessel = ? AND zeitpunkt > ? AND id <= ?', [$bucket, $k, $since, $mine])['n'];
            if ($pos > $limit) {
                Db::run('DELETE FROM rate_limits WHERE id = ?', [$mine]);
                $first = Db::one('SELECT MIN(zeitpunkt) AS t FROM rate_limits WHERE bucket = ? AND schluessel = ? AND zeitpunkt > ?', [$bucket, $k, $since]);
                $t = $first['t'] ? strtotime($first['t'] . ' UTC') : time();
                return ['ok' => false, 'retryAfter' => max(1, $t + $windowSec - time())];
            }
            return ['ok' => true, 'retryAfter' => 0, 'id' => $mine];
        } catch (\Throwable $e) {
            Log::warn('Rate-Limit nicht speicherbar: ' . $e->getMessage());
            return ['ok' => true, 'retryAfter' => 0];
        }
    }

    /** Einen einzelnen gezählten Versuch zurücknehmen (z. B. wenn die Aktion doch nicht stattfand). */
    public static function undo(?int $entryId): void
    {
        if ($entryId) {
            try {
                Db::run('DELETE FROM rate_limits WHERE id = ?', [$entryId]);
            } catch (\Throwable $e) {
                Log::warn('Rate-Limit nicht zurücknehmbar: ' . $e->getMessage());
            }
        }
    }

    public static function reset(string $bucket, string $id): void
    {
        try {
            Db::run('DELETE FROM rate_limits WHERE bucket = ? AND schluessel = ?', [$bucket, self::key($bucket, $id)]);
        } catch (\Throwable $e) {
            Log::warn('Rate-Limit nicht zurücksetzbar: ' . $e->getMessage());
        }
    }

    /** Alte Einträge löschen (Cron). Längstes Fenster ist ein Tag. */
    public static function purge(): int
    {
        return Db::run('DELETE FROM rate_limits WHERE zeitpunkt < ?', [self::since(2 * 86400)]);
    }
}
