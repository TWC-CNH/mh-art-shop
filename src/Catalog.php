<?php
declare(strict_types=1);

namespace App;

/**
 * Werke aus der Datenbank, Reservierung und Verkaufsstatus.
 * Reservieren geschieht mit einem einzigen bedingten UPDATE: Nur wer die Zeile tatsächlich ändert,
 * darf zur Kasse. Zwei gleichzeitige Käufer können so nie dasselbe Werk reservieren.
 */
final class Catalog
{
    private const COLS = 'w.*, (w.reserviert_bis IS NOT NULL AND w.reserviert_bis > UTC_TIMESTAMP()) AS ist_reserviert';

    /** Alle Werke für den Shop (aktiv, sichtbar), sortiert. */
    public static function list(): array
    {
        $rows = Db::all('SELECT ' . self::COLS . ' FROM werke w WHERE w.aktiv = 1 AND w.sichtbar = 1');
        $works = self::withImages($rows);
        if (!Config::get()['showSold']) {
            $works = array_values(array_filter($works, fn ($w) => $w['status'] !== 'sold'));
        }
        return Works::sort($works);
    }

    /** slug => available|reserved|sold für alle sichtbaren Werke, unabhängig von SHOW_SOLD (für /api/status). */
    public static function statusMap(): array
    {
        $out = [];
        foreach (Db::all('SELECT w.slug, w.status, ' . self::COLS . ' FROM werke w WHERE w.aktiv = 1 AND w.sichtbar = 1') as $r) {
            $out[$r['slug']] = $r['status'] === 'sold' ? 'sold' : ((int) $r['ist_reserviert'] ? 'reserved' : 'available');
        }
        return $out;
    }

    public static function bySlug(string $slug): ?array
    {
        $row = Db::one('SELECT ' . self::COLS . ' FROM werke w WHERE w.slug = ? AND w.aktiv = 1 AND w.sichtbar = 1', [$slug]);
        if (!$row) {
            return null;
        }
        $w = self::withImages([$row])[0];
        return (!Config::get()['showSold'] && $w['status'] === 'sold') ? null : $w;
    }

    /** Wie bySlug, aber auch verkaufte Werke bei SHOW_SOLD=false (z. B. für die Danke-Seite). */
    public static function bySlugIncludingSold(string $slug): ?array
    {
        $row = Db::one('SELECT ' . self::COLS . ' FROM werke w WHERE w.slug = ? AND w.aktiv = 1', [$slug]);
        return $row ? self::withImages([$row])[0] : null;
    }

    /** Für das Atelier: auch ausgeblendete Werke; liefert die Werk-Struktur plus die Rohzeile unter "_row". */
    public static function byIdForAtelier(int $id): ?array
    {
        $row = Db::one('SELECT ' . self::COLS . ' FROM werke w WHERE w.id = ? AND w.aktiv = 1', [$id]);
        return $row ? self::withImages([$row])[0] + ['_row' => $row] : null;
    }

    /** Für das Atelier: alle aktiven Werke inkl. ausgeblendeter, nach Position. */
    public static function allForAtelier(): array
    {
        $rows = Db::all('SELECT ' . self::COLS . ' FROM werke w WHERE w.aktiv = 1 ORDER BY w.position, w.id DESC');
        $works = self::withImages($rows);
        foreach ($works as $i => $w) {
            $works[$i]['_row'] = $rows[$i];
        }
        return $works;
    }

    /** @param list<array> $rows */
    private static function withImages(array $rows): array
    {
        if (!$rows) {
            return [];
        }
        $ids = array_map(fn ($r) => (int) $r['id'], $rows);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $imgs = [];
        foreach (Db::all("SELECT werk_id, pfad, rolle FROM werk_bilder WHERE werk_id IN ($in) ORDER BY werk_id, reihenfolge, id", $ids) as $b) {
            $imgs[(int) $b['werk_id']][] = $b;
        }
        return array_map(fn ($r) => self::toWork($r, $imgs[(int) $r['id']] ?? []), $rows);
    }

    private static function toWork(array $r, array $images): array
    {
        $main = '';
        $uv = '';
        $gallery = [];
        foreach ($images as $b) {
            if ($b['rolle'] === 'haupt' && $main === '') {
                $main = $b['pfad'];
            } elseif ($b['rolle'] === 'uv' && $uv === '') {
                $uv = $b['pfad'];
            } else {
                $gallery[] = ['src' => $b['pfad'], 'kind' => in_array($b['rolle'], ['detail', 'raum', 'ansicht'], true) ? $b['rolle'] : 'ansicht'];
            }
        }
        $status = $r['status'] === 'sold' ? 'sold' : ((int) $r['ist_reserviert'] ? 'reserved' : 'available');
        return [
            'id' => (int) $r['id'],
            'slug' => $r['slug'],
            'title' => $r['titel'],
            'year' => $r['jahr'] !== null ? (int) $r['jahr'] : null,
            'technique' => $r['technik'],
            'size' => $r['masse'],
            'format' => $r['format'] ?: Works::formatFromSize($r['masse']),
            'series' => $r['serie'],
            'description' => $r['beschreibung'] ?? '',
            'priceCents' => $r['preis_cent'] !== null ? (int) $r['preis_cent'] : null,
            'currency' => 'EUR',
            'status' => $status,
            'image' => $main,
            'thumb' => $r['thumb'] ?: $main,
            'uvImage' => $uv,
            'gallery' => $gallery,
            'awards' => json_decode($r['auszeichnungen'] ?? '[]', true) ?: [],
            'exhibitions' => json_decode($r['ausstellungen'] ?? '[]', true) ?: [],
            'w' => $r['bild_breite'] !== null ? (int) $r['bild_breite'] : null,
            'h' => $r['bild_hoehe'] !== null ? (int) $r['bild_hoehe'] : null,
            'sort' => (int) $r['position'],
            'shippingEur' => $r['versand_cent'] !== null ? ((int) $r['versand_cent']) / 100 : null,
            'created' => (int) strtotime($r['erstellt'] . ' UTC'),
        ];
    }

    // ---------- Reservierung und Verkauf ----------

    /**
     * Reserviert ein Werk atomar für $seconds Sekunden mit einem vorläufigen Token.
     * Liefert das Token oder null, wenn das Werk nicht (mehr) frei ist.
     */
    public static function claim(int $id, int $seconds): ?string
    {
        $token = 'pending_' . bin2hex(random_bytes(12));
        $n = Db::run(
            'UPDATE werke SET reserviert_bis = ?, reserviert_session = ?
             WHERE id = ? AND aktiv = 1 AND sichtbar = 1 AND status = \'available\' AND preis_cent IS NOT NULL
               AND (reserviert_bis IS NULL OR reserviert_bis <= UTC_TIMESTAMP())',
            [Db::now($seconds), $token, $id]
        );
        return $n === 1 ? $token : null;
    }

    /** Ersetzt das vorläufige Token durch die Stripe-Sitzung und setzt das endgültige Ablaufdatum. */
    /** Liefert false, wenn die Reservierung inzwischen aufgehoben wurde oder sich Sichtbarkeit/Preis geändert haben. */
    public static function attachSession(int $id, string $token, string $sessionId, int $expiresAt, int $priceCents): bool
    {
        // Zusätzlich: noch sichtbar und der Preis ist derselbe, mit dem die Stripe-Sitzung angelegt wurde
        return Db::run(
            'UPDATE werke SET reserviert_session = ?, reserviert_bis = ? WHERE id = ? AND reserviert_session = ? AND aktiv = 1 AND sichtbar = 1 AND status = \'available\' AND preis_cent = ?',
            // 5 Minuten Puffer: eine Zahlung, die Sekunden vor Ablauf begonnen wurde, kann noch durchlaufen.
            // Läuft die Sitzung regulär ab, gibt das Ereignis checkout.session.expired das Werk sofort frei.
            [$sessionId, gmdate('Y-m-d H:i:s', $expiresAt + 300), $id, $token, $priceCents]
        ) === 1;
    }

    /** Gibt eine Reservierung frei, wenn sie zu dieser Sitzung (oder diesem Token) gehört und das Werk nicht verkauft ist. */
    public static function release(int $id, string $sessionOrToken): bool
    {
        return Db::run(
            'UPDATE werke SET reserviert_bis = NULL, reserviert_session = NULL WHERE id = ? AND status <> \'sold\' AND reserviert_session = ?',
            [$id, $sessionOrToken]
        ) === 1;
    }

    /** @return array{alreadySold: bool, expire: ?string} expire = offene Stripe-Sitzung eines anderen Käufers, die zu schließen ist */
    public static function markSold(int $id, string $sessionId): array
    {
        return Db::transaction(function () use ($id, $sessionId) {
            $r = Db::one('SELECT status, verkauft_session, doppelverkauf_session, reserviert_session FROM werke WHERE id = ? FOR UPDATE', [$id]);
            if (!$r) {
                return ['alreadySold' => false, 'expire' => null];
            }
            // Hatte inzwischen ein anderer Käufer reserviert, muss dessen Stripe-Sitzung geschlossen werden,
            // sonst könnte er ein bereits verkauftes Werk bezahlen.
            $other = (string) $r['reserviert_session'];
            $expire = str_starts_with($other, 'cs_') && $other !== $sessionId ? $other : null;
            // Schon verkauft – über eine andere Sitzung oder von Hand im Atelier (verkauft_session leer)?
            $already = $r['status'] === 'sold' && $r['verkauft_session'] !== $sessionId;
            if ($already) {
                // Bisherigen Verkauf behalten; die erste weitere Zahlung bleibt als Hinweis zur Erstattung stehen
                Db::run('UPDATE werke SET doppelverkauf_session = COALESCE(doppelverkauf_session, ?), reserviert_bis = NULL, reserviert_session = NULL WHERE id = ?', [$sessionId, $id]);
            } else {
                Db::run(
                    'UPDATE werke SET status = \'sold\', verkauft_am = COALESCE(verkauft_am, UTC_DATE()), verkauft_session = ?, reserviert_bis = NULL, reserviert_session = NULL WHERE id = ?',
                    [$sessionId, $id]
                );
            }
            return ['alreadySold' => (bool) $already, 'expire' => $expire];
        });
    }

    /**
     * Zahlung gescheitert: wieder verfügbar – aber nur, wenn genau diese Sitzung das Werk verkauft hat.
     * Von Hand im Atelier gesetzte Verkäufe und fremde Reservierungen bleiben unberührt.
     */
    public static function markAvailable(int $id, string $sessionId): bool
    {
        // War es die als Doppelzahlung vermerkte Sitzung, ist der Erstattungshinweis hinfällig
        if (Db::run('UPDATE werke SET doppelverkauf_session = NULL WHERE id = ? AND doppelverkauf_session = ?', [$id, $sessionId]) === 1) {
            return false;
        }
        // Gab es eine zweite Zahlung für das Werk, bleibt es verkauft – jetzt an diese zweite Sitzung.
        if (Db::run(
            'UPDATE werke SET verkauft_session = doppelverkauf_session, doppelverkauf_session = NULL
             WHERE id = ? AND status = \'sold\' AND verkauft_session = ? AND doppelverkauf_session IS NOT NULL',
            [$id, $sessionId]
        ) === 1) {
            return false;
        }
        return Db::run(
            'UPDATE werke SET status = \'available\', verkauft_am = NULL, verkauft_session = NULL
             WHERE id = ? AND status = \'sold\' AND verkauft_session = ?',
            [$id, $sessionId]
        ) === 1;
    }

    /** Cron: abgelaufene Reservierungen aufräumen (die Anzeige ignoriert sie ohnehin). */
    public static function releaseExpired(): int
    {
        return Db::run('UPDATE werke SET reserviert_bis = NULL, reserviert_session = NULL WHERE reserviert_bis IS NOT NULL AND reserviert_bis <= UTC_TIMESTAMP()');
    }

    public static function uniqueSlug(string $title): string
    {
        $base = Works::slugify($title);
        $slug = $base;
        for ($i = 2; Db::one('SELECT 1 FROM werke WHERE slug = ?', [$slug]); $i++) {
            $slug = "$base-$i";
        }
        return $slug;
    }
}
