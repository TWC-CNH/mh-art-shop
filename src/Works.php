<?php
declare(strict_types=1);

namespace App;

/**
 * Hilfsfunktionen rund um ein Werk und die einheitliche Werk-Struktur, mit der alle Templates arbeiten.
 *
 * Werk-Array (Schlüssel wie früher in Node):
 *   id (int|string), slug, title, year (?int), technique, size, format (klein|mittel|gross), series,
 *   description, priceCents (?int, null = Preis auf Anfrage), currency ('EUR'),
 *   status (available|reserved|sold), image, thumb, uvImage ('' wenn keins),
 *   gallery (list<array{src:string,kind:string}>), awards (list<string>), exhibitions (list<string>),
 *   w (?int), h (?int), sort (int), shippingEur (?float), created (int, Unix-Zeit)
 */
final class Works
{
    public static function slugify(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        if (class_exists(\Normalizer::class)) {
            $s = \Normalizer::normalize($s, \Normalizer::FORM_KD) ?: $s;
        } else {
            // Ohne intl-Erweiterung: gängige Akzente von Hand auf den Grundbuchstaben bringen
            $s = strtr($s, [
                'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'ā' => 'a', 'ç' => 'c', 'č' => 'c', 'ć' => 'c',
                'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ě' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
                'ñ' => 'n', 'ń' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u',
                'ý' => 'y', 'ÿ' => 'y', 'š' => 's', 'ś' => 's', 'ž' => 'z', 'ź' => 'z', 'ż' => 'z', 'ł' => 'l', 'ř' => 'r', 'æ' => 'ae', 'œ' => 'oe',
            ]);
        }
        $s = preg_replace('/\p{Mn}+/u', '', $s) ?? $s;
        $s = trim(preg_replace('/[^a-z0-9]+/', '-', $s) ?? '', '-');
        // Umlaute werden zu zwei Buchstaben: auf 110 Zeichen kürzen (Spalte und Adresse erlauben 140, Rest für „-2“ usw.)
        $s = rtrim(substr($s, 0, 110), '-');
        return $s !== '' ? $s : 'werk';
    }

    public static function formatFromSize(string $size): string
    {
        preg_match_all('/\d+(?:[.,]\d+)?/', $size, $m);
        $nums = array_map(fn ($n) => (float) str_replace(',', '.', $n), array_slice($m[0], 0, 2));
        $longest = $nums ? max($nums) : 0;
        if (!$longest) {
            return 'mittel';
        }
        return $longest < 60 ? 'klein' : ($longest < 100 ? 'mittel' : 'gross');
    }

    /** Preis wie Intl.NumberFormat('de-DE', EUR): "3.400 €" bzw. "2.450,50 €" (geschütztes Leerzeichen vor €). */
    public static function euro(int $cents): string
    {
        $dec = $cents % 100 !== 0 ? 2 : 0;
        return number_format($cents / 100, $dec, ',', '.') . "\u{00A0}€";
    }

    public static function shippingFor(array $work, ?array $cfg = null): float
    {
        $cfg ??= Config::get();
        if (isset($work['shippingEur']) && $work['shippingEur'] !== null && is_numeric($work['shippingEur'])) {
            return (float) $work['shippingEur'];
        }
        return (float) ($cfg['shipping'][$work['format']] ?? $cfg['shipping']['mittel']);
    }

    /** Sortierung wie bisher: verfügbar vor reserviert vor verkauft, dann Position, dann neueste zuerst. */
    public static function sort(array $list): array
    {
        $rank = ['available' => 0, 'reserved' => 1, 'sold' => 2];
        usort($list, fn ($a, $b) => [$rank[$a['status']], $a['sort'], -($a['created'] ?? 0)] <=> [$rank[$b['status']], $b['sort'], -($b['created'] ?? 0)]);
        return $list;
    }

    /** Ein Eintrag aus data/catalog.json in die Werk-Struktur (für Import und Vergleichstests). */
    public static function fromCatalogJson(array $d): array
    {
        return [
            'id' => 'demo_' . $d['slug'],
            'slug' => $d['slug'],
            'title' => $d['title'],
            'year' => $d['year'] ?? null,
            'technique' => $d['technique'] ?? '',
            'size' => $d['size'] ?? '',
            'format' => $d['format'] ?? self::formatFromSize($d['size'] ?? ''),
            'series' => $d['series'] ?? '',
            'description' => $d['description'] ?? '',
            'priceCents' => !empty($d['price_eur']) ? (int) round($d['price_eur'] * 100) : null,
            'currency' => 'EUR',
            'status' => ($d['status'] ?? '') === 'sold' ? 'sold' : 'available',
            'image' => $d['image'],
            'thumb' => $d['thumb'] ?? $d['image'],
            'uvImage' => $d['uv_image'] ?? '',
            'gallery' => array_map(fn ($g) => ['src' => $g['src'], 'kind' => $g['kind']], $d['gallery'] ?? []),
            'awards' => $d['awards'] ?? [],
            'exhibitions' => $d['exhibitions'] ?? [],
            'w' => $d['w'] ?? null,
            'h' => $d['h'] ?? null,
            'sort' => $d['sort'] ?? 9999,
            'shippingEur' => $d['shipping_eur'] ?? null,
            'created' => 0,
        ];
    }
}
