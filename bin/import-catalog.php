<?php
// Übernimmt die Werke aus data/catalog.json in die Datenbank (einmalig beim Einrichten).
// Bereits vorhandene Werke (gleicher slug) bleiben unverändert, außer mit --update (Texte, Preise, Fotos;
// der Verkaufsstatus wird dabei nie verändert).
// Aufruf: php bin/import-catalog.php [--update]
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use App\Db;
use App\Works;

$update = in_array('--update', $argv, true);
$data = json_decode((string) file_get_contents(dirname(__DIR__) . '/data/catalog.json'), true, 512, JSON_THROW_ON_ERROR);
$new = $upd = $skip = 0;
foreach ($data as $d) {
    $w = Works::fromCatalogJson($d);
    $row = [
        'titel' => $w['title'],
        'jahr' => $w['year'],
        'serie' => (string) $w['series'],
        'technik' => $w['technique'],
        'masse' => $w['size'],
        'format' => $d['format'] ?? null,
        'beschreibung' => $w['description'],
        'preis_cent' => $w['priceCents'],
        'versand_cent' => $w['shippingEur'] !== null ? (int) round($w['shippingEur'] * 100) : null,
        'status' => $w['status'],
        'position' => $w['sort'],
        'bild_breite' => $w['w'],
        'bild_hoehe' => $w['h'],
        'thumb' => $w['thumb'] !== $w['image'] ? $w['thumb'] : null,
        'auszeichnungen' => json_encode($w['awards'], JSON_UNESCAPED_UNICODE),
        'ausstellungen' => json_encode($w['exhibitions'], JSON_UNESCAPED_UNICODE),
    ];
    Db::transaction(function () use ($w, $row, $update, &$new, &$upd, &$skip) {
        $prev = Db::one('SELECT id, (reserviert_bis > UTC_TIMESTAMP()) AS reserviert FROM werke WHERE slug = ?', [$w['slug']]);
        if ($prev && !$update) {
            $skip++;
            return;
        }
        if ($prev) {
            $id = (int) $prev['id'];
            // Verkaufsstatus gehört dem Shop (Webhook, Atelier), nicht der Katalogdatei: nie überschreiben
            $fields = $row;
            unset($fields['status']);
            if ((int) $prev['reserviert']) {
                // Läuft gerade ein Bezahlvorgang, Preis und Versand nicht ändern (er liefe sonst zum alten Preis weiter)
                unset($fields['preis_cent'], $fields['versand_cent']);
                echo "  Hinweis: {$w['slug']} ist gerade reserviert – Preis/Versand nicht geändert, später erneut ausführen.\n";
            }
            Db::run('UPDATE werke SET ' . implode(', ', array_map(fn ($c) => "$c = ?", array_keys($fields))) . ' WHERE id = ?', [...array_values($fields), $id]);
            Db::run('DELETE FROM werk_bilder WHERE werk_id = ?', [$id]);
            $upd++;
        } else {
            $row['slug'] = $w['slug'];
            Db::run('INSERT INTO werke (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')', array_values($row));
            $id = Db::lastId();
            $new++;
        }
        $i = 0;
        $imgs = array_merge(
            [['pfad' => $w['image'], 'rolle' => 'haupt']],
            $w['uvImage'] !== '' ? [['pfad' => $w['uvImage'], 'rolle' => 'uv']] : [],
            array_map(fn ($g) => ['pfad' => $g['src'], 'rolle' => $g['kind']], $w['gallery'])
        );
        foreach ($imgs as $b) {
            Db::run('INSERT INTO werk_bilder (werk_id, pfad, rolle, reihenfolge) VALUES (?, ?, ?, ?)', [$id, $b['pfad'], $b['rolle'], $i++]);
        }
    });
}
echo "Neu: $new, aktualisiert: $upd, übersprungen: $skip\n";
