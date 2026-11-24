<?php
declare(strict_types=1);

namespace App\Controller;

use App\AtelierAuth;
use App\Catalog;
use App\Config;
use App\Crypto;
use App\Db;
use App\Http;
use App\Log;
use App\RateLimit;
use App\Works;

/**
 * Atelier unter /atelier (passwortgeschützt): Werke anlegen und bearbeiten, Fotos hochladen,
 * Preis, Status, Sichtbarkeit; Widerrufe und Anfragen ansehen. Alles liegt in der Datenbank.
 */
final class AtelierController
{
    private const ROLE_LABEL = ['haupt' => 'Hauptfoto', 'uv' => 'Schwarzlicht', 'detail' => 'Detail', 'raum' => 'Im Raum', 'ansicht' => 'Ansicht'];
    private const MAX_IMAGES = 8;

    private static function page(string $title, string $body, int $status = 200): never
    {
        $csrf = e(AtelierAuth::csrfToken());
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
        echo '<!doctype html>
<html lang="de" data-theme="day"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow"><meta name="csrf" content="' . $csrf . '"><title>' . e($title) . ' · Atelier</title>
<link rel="icon" href="/favicon.png"><link rel="stylesheet" href="/assets/shop.css?v=a5"><link rel="stylesheet" href="/assets/atelier.css?v=a6"></head>
<body class="atelier-body">
<a class="skip" href="#inhalt">Zum Inhalt</a>
<header class="at-head"><a href="/atelier" class="at-brand"><img src="/assets/logo-mhart.png" alt="MH ART" height="34"> <span>Atelier</span></a>
<nav>' . (AtelierAuth::userName() !== '' ? '<span class="muted">' . e(AtelierAuth::userName()) . '</span> · ' : '') . '<a href="/" target="_blank" rel="noopener">Shop ansehen ↗</a></nav></header>
<main class="at-main" id="inhalt">' . $body . '</main>
<script src="/assets/atelier.js?v=a6" defer></script></body></html>';
        exit;
    }

    /**
     * Erfolgsmeldung zu ?ok=<schlüssel>. Nur feste Texte, damit niemand über einen präparierten Link
     * eigene Botschaften als offizielle Bestätigung im Atelier anzeigen lassen kann.
     */
    private static function okMessage(): string
    {
        $n = max(0, (int) Http::query('n'));
        return match (Http::query('ok')) {
            'angelegt' => 'Werk angelegt. Es erscheint sofort im Shop.',
            'gespeichert' => 'Gespeichert. Der Shop zeigt die Änderung sofort.',
            'inzwischen-verkauft' => 'Gespeichert. Achtung: Das Werk wurde inzwischen verkauft und bleibt „Verkauft“.',
            'entfernt' => 'Werk aus dem Shop entfernt.',
            'entfernen-bezahlvorgang' => 'Nicht entfernt: Für dieses Werk läuft gerade ein Bezahlvorgang, der sich nicht beenden ließ. Bitte in einer halben Stunde erneut versuchen.',
            'verkauft-zahlung-laeuft' => 'Gespeichert als „Verkauft“. Achtung: Ein laufender Bezahlvorgang ließ sich nicht beenden. Zahlt der Käufer noch, erscheint hier ein Hinweis zur Erstattung.',
            'bezahlvorgang-laeuft' => 'Gespeichert. Ein laufender Bezahlvorgang ließ sich gerade nicht beenden; das Werk bleibt bis zu dessen Ablauf reserviert. Bitte später prüfen.',
            'bezahlvorgang-beendet' => 'Gespeichert. Ein laufender Bezahlvorgang für dieses Werk wurde beendet, weil sich Preis, Versand oder Sichtbarkeit geändert haben.',
            'nicht-mehr-da' => 'Das Werk wurde inzwischen aus dem Shop entfernt. Die Änderungen wurden nicht gespeichert.',
            'notfall' => "Mit Notfall-Code angemeldet. Noch $n Notfall-Code(s) übrig. Bitte die Authenticator-App neu einrichten lassen (bin/atelier-user.php 2fa).",
            default => '',
        };
    }

    private static function csrfField(): string
    {
        return '<input type="hidden" name="csrf" value="' . e(AtelierAuth::csrfToken()) . '">';
    }

    private static function loginPage(string $error = '', int $status = 0): never
    {
        if (!AtelierAuth::configured()) {
            self::page('Atelier', '<section class="at-login"><h1 class="display">Atelier</h1><p>Das Atelier ist noch nicht eingerichtet. Bitte auf dem Server einen Benutzer anlegen: <code>php bin/atelier-user.php anlegen &lt;name&gt;</code></p></section>');
        }
        self::page('Anmelden', '<section class="at-login">
    <p class="eyebrow">MH ART</p><h1 class="display">Atelier</h1>
    <p class="muted">Hier verwalten Sie Ihre Werke, Fotos und Preise.</p>
    ' . ($error !== '' ? '<p class="at-error">' . e($error) . '</p>' : '') . '
    <form method="post" action="/atelier/login" class="at-form">
      <label class="field"><span>Benutzername</span><input name="benutzer" required autocomplete="username" autocapitalize="none" spellcheck="false" autofocus></label>
      <label class="field"><span>Passwort</span><input type="password" name="passwort" required autocomplete="current-password"></label>
      <label class="field"><span>Code aus der Authenticator-App</span><input name="code" inputmode="numeric" pattern="[0-9 ]{6,7}|[A-Za-z0-9]{5}[ \-]?[A-Za-z0-9]{5}" maxlength="11" autocomplete="one-time-code" aria-describedby="code-hint"><small id="code-hint">6 Ziffern, wechselt alle 30 Sekunden</small></label>
      <details class="at-recovery"><summary>Handy nicht zur Hand? Notfall-Code verwenden</summary>
        <label class="field"><span>Notfall-Code</span><input name="notfallcode" maxlength="11" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="XXXXX-XXXXX" aria-describedby="recovery-hint"><small id="recovery-hint">Jeder Notfall-Code gilt nur einmal.</small></label>
      </details>
      <button class="btn btn--wide" type="submit">Anmelden</button>
    </form></section>', $status ?: ($error !== '' ? 401 : 200));
    }

    private static function thumbOf(string $u): string
    {
        return preg_match('#^/bilder/[a-z0-9-]+\.jpg$#', $u) ? preg_replace('/(-sm|-xs)?\.jpg$/', '-xs.jpg', $u) : $u;
    }

    // ---------- Ansichten ----------

    private static function listPage(string $msg = ''): never
    {
        $works = Catalog::allForAtelier();
        $rows = '';
        foreach ($works as $w) {
            $hidden = !(int) $w['_row']['sichtbar'];
            $st = $hidden ? 'Ausgeblendet' : ($w['status'] === 'sold' ? 'Verkauft' : ($w['status'] === 'reserved' ? 'Reserviert' : 'Im Shop'));
            $thumb = $w['image'] !== '' ? '<img src="' . e(self::thumbOf($w['thumb'] ?: $w['image'])) . '" alt="" loading="lazy">' : '<span class="at-noimg">Kein Foto</span>';
            $rows .= '<li class="at-row' . ($hidden ? ' is-hidden' : '') . '">
      <a class="at-row-link" href="/atelier/werk/' . $w['id'] . '">
        <span class="at-thumb">' . $thumb . '</span>
        <span class="at-info"><strong>' . e($w['title']) . '</strong><span class="muted">' . e(implode(' · ', array_filter([(string) ($w['year'] ?? ''), $w['size']]))) . '</span></span>
        <span class="at-price">' . ($w['priceCents'] ? e(Works::euro($w['priceCents'])) : '<span class="muted">auf Anfrage</span>') . '</span>
        <span class="at-status at-status--' . ($hidden ? 'hidden' : $w['status']) . '">' . $st . '</span>
      </a>
    </li>';
        }
        $demo = Config::get()['demo'] ? ' <strong>Hinweis:</strong> Stripe ist noch nicht verbunden, Kaufen ist im Shop gesperrt.' : '';
        self::page('Werke', '
    <div class="at-top"><div><p class="eyebrow">Atelier</p><h1 class="display">Ihre Werke <span class="at-count">' . count($works) . '</span></h1></div>
    <div class="at-top-actions"><a class="btn" href="/atelier/neu">+ Neues Werk</a><a class="btn btn--ghost" href="/atelier/anfragen">Anfragen</a><a class="btn btn--ghost" href="/atelier/widerrufe">Widerrufe</a>
    <form method="post" action="/atelier/logout">' . self::csrfField() . '<button class="btn btn--ghost" type="submit">Abmelden</button></form></div></div>
    ' . ($msg !== '' ? '<p class="at-ok">' . e($msg) . '</p>' : '') . '
    <input class="at-search" type="search" placeholder="Werk suchen …" data-at-search aria-label="Werk suchen">
    <ul class="at-list" data-at-list>' . $rows . '</ul>
    <p class="muted at-hint">Tipp: Reihenfolge im Shop über das Feld „Position“ im Werk. Verkäufe über den Shop werden automatisch als „Verkauft“ markiert.' . $demo . '</p>');
    }

    /** @param array|null $w Werk aus Catalog::byIdForAtelier oder ein Entwurf nach Fehler */
    private static function editPage(?array $w, string $error = '', string $msg = ''): never
    {
        $isNew = !$w || empty($w['id']);
        $r = $w['_row'] ?? [];
        $images = $w['_images'] ?? ($w ? self::imagesOf($w) : []);
        $hidden = $w ? !(int) ($r['sichtbar'] ?? 1) : false;
        if (isset($w['_hidden'])) {
            $hidden = $w['_hidden'];
        }
        $priceEur = $w['_price'] ?? ($w && $w['priceCents'] ? str_replace('.', ',', (string) ($w['priceCents'] / 100)) : '');
        $shipEur = $w['_ship'] ?? ($w && $w['shippingEur'] !== null ? str_replace('.', ',', (string) $w['shippingEur']) : '');
        $status = $w['_status'] ?? ($w['status'] ?? 'available');
        $v = fn ($x) => e($x ?? '');

        $statusNote = '';
        if (!$isNew && $status === 'sold' && !empty($r['verkauft_session'])) {
            $date = !empty($r['verkauft_am']) ? ' am ' . date('d.m.Y', strtotime($r['verkauft_am'])) : '';
            $statusNote = '<p class="at-note">Über den Shop verkauft' . $date . '. Käuferdaten stehen in Stripe unter „Zahlungen“.</p>';
        } elseif (!$isNew && ($w['status'] ?? '') === 'reserved') {
            $until = (new \DateTimeImmutable($r['reserviert_bis'] . ' UTC'))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('H:i');
            $statusNote = '<p class="at-note">Gerade reserviert: Jemand ist im Bezahlvorgang (bis ' . $until . ' Uhr). Danach wird es automatisch verkauft oder wieder frei.</p>';
        }
        if (!$isNew && !empty($r['doppelverkauf_session'])) {
            $statusNote .= '<p class="at-error">Achtung: Für dieses Werk ist eine zweite Zahlung eingegangen (Stripe-Sitzung ' . e($r['doppelverkauf_session']) . '). Bitte in Stripe erstatten.</p>';
        }
        $lines = fn ($list) => e(implode("\n", $list ?? []));
        $roleOpts = '';
        foreach (self::ROLE_LABEL as $k => $l) {
            if ($k !== 'haupt') {
                $roleOpts .= "<option value=\"$k\">$l</option>";
            }
        }

        $title = $isNew ? 'Neues Werk' : $w['title'];
        self::page($title, '
    <p class="at-back"><a href="/atelier">← Alle Werke</a></p>
    <div class="at-top"><div><p class="eyebrow">' . ($isNew ? 'Neues Werk' : 'Werk bearbeiten') . '</p><h1 class="display">' . ($isNew ? 'Neues Werk anlegen' : $v($w['title'])) . '</h1></div>
    ' . (!$isNew && !empty($w['slug']) ? '<a class="btn btn--ghost" href="/werk/' . $v($w['slug']) . '" target="_blank" rel="noopener">Im Shop ansehen ↗</a>' : '') . '</div>
    ' . ($error !== '' ? '<p class="at-error">' . e($error) . '</p>' : '') . ($msg !== '' ? '<p class="at-ok">' . e($msg) . '</p>' : '') . '
    <form method="post" action="/atelier/speichern" class="at-form at-edit" data-at-edit>
      ' . self::csrfField() . '
      <input type="hidden" name="id" value="' . $v($isNew ? '' : $w['id']) . '">
      <input type="hidden" name="bilder" value="' . $v(json_encode($images, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '" data-at-images-json>
      <input type="hidden" name="w" value="' . $v($w['w'] ?? '') . '" data-at-w><input type="hidden" name="h" value="' . $v($w['h'] ?? '') . '" data-at-h>

      <section class="at-card">
        <h2>Fotos</h2>
        <p class="muted">Das erste Foto ist das Hauptfoto im Shop. Für jedes weitere Foto wählen Sie, was es zeigt. Bis zu ' . self::MAX_IMAGES . ' Fotos, eines davon als Schwarzlicht-Aufnahme. Große Fotos werden beim Hochladen automatisch verkleinert.</p>
        <ul class="at-photos" data-at-photos></ul>
        <label class="at-upload">
          <input type="file" accept="image/*" multiple data-at-file>
          <span class="btn btn--ghost">+ Fotos hinzufügen</span>
          <span class="muted" data-at-upload-status></span>
        </label>
      </section>

      <section class="at-card">
        <h2>Angaben</h2>
        <div class="at-grid">
          <label class="field field--wide"><span>Titel *</span><input name="titel" required maxlength="120" value="' . $v($w['title'] ?? '') . '" placeholder="z. B. NACHT"></label>
          <label class="field"><span>Jahr</span><input name="jahr" inputmode="numeric" maxlength="4" value="' . $v($w['year'] ?? '') . '" placeholder="2026"></label>
          <label class="field"><span>Serie <em>(optional)</em></span><input name="serie" maxlength="60" value="' . $v($w['series'] ?? '') . '" placeholder="z. B. Schirm"></label>
          <label class="field field--wide"><span>Technik</span><input name="technik" maxlength="200" value="' . $v($w['technique'] ?? '') . '" placeholder="Acryl auf Leinwand, UV reaktiv"></label>
          <label class="field field--wide"><span>Maße <em>(Höhe x Breite x Tiefe)</em></span><input name="masse" maxlength="80" value="' . $v($w['size'] ?? '') . '" placeholder="100 x 70 x 2 cm"></label>
          <label class="field field--wide"><span>Beschreibung</span><textarea name="beschreibung" rows="6" maxlength="4000">' . $v($w['description'] ?? '') . '</textarea></label>
        </div>
      </section>

      <section class="at-card">
        <h2>Preis &amp; Verkauf</h2>
        <div class="at-grid">
          <label class="field"><span>Preis in Euro</span><input name="preis" inputmode="decimal" value="' . $v($priceEur) . '" placeholder="leer = Preis auf Anfrage"></label>
          <label class="field"><span>Eigener Versandpreis <em>(optional)</em></span><input name="versand" inputmode="decimal" value="' . $v($shipEur) . '" placeholder="sonst nach Größe"></label>
          <fieldset class="field field--wide at-radios"><legend>Status</legend>' . $statusNote . '
            <input type="hidden" name="status_geladen" value="' . $v($w['_status_geladen'] ?? ($w['status'] ?? 'available')) . '">
            <label><input type="radio" name="status" value="available" ' . ($status !== 'sold' ? 'checked' : '') . '> Verfügbar</label>
            <label><input type="radio" name="status" value="sold" ' . ($status === 'sold' ? 'checked' : '') . '> Verkauft</label>
          </fieldset>
          <fieldset class="field field--wide at-radios"><legend>Sichtbarkeit</legend>
            <label><input type="radio" name="sichtbar" value="ja" ' . (!$hidden ? 'checked' : '') . '> Im Shop zeigen</label>
            <label><input type="radio" name="sichtbar" value="nein" ' . ($hidden ? 'checked' : '') . '> Ausblenden</label>
          </fieldset>
          <label class="field"><span>Position im Shop <em>(1 = ganz vorn)</em></span><input name="position" inputmode="numeric" value="' . $v(isset($w['sort']) && $w['sort'] !== 9999 ? $w['sort'] : '') . '" placeholder="z. B. 5"></label>
        </div>
        <p class="muted">Ohne Preis erscheint „Preis auf Anfrage“ mit Anfrage-Formular. Mit Preis lässt sich das Werk direkt kaufen.</p>
      </section>

      <section class="at-card">
        <h2>Auszeichnungen &amp; Ausstellungen <em class="muted">(optional)</em></h2>
        <div class="at-grid">
          <label class="field field--wide"><span>Auszeichnungen <em>(eine pro Zeile)</em></span><textarea name="auszeichnungen" rows="3">' . $lines($w['awards'] ?? []) . '</textarea></label>
          <label class="field field--wide"><span>Ausstellungen <em>(eine pro Zeile)</em></span><textarea name="ausstellungen" rows="3">' . $lines($w['exhibitions'] ?? []) . '</textarea></label>
        </div>
      </section>

      <div class="at-save"><button class="btn btn--wide" type="submit" data-at-submit>' . ($isNew ? 'Werk anlegen' : 'Änderungen speichern') . '</button></div>
    </form>
    ' . (!$isNew ? '<form method="post" action="/atelier/archivieren" class="at-danger" data-confirm="„' . $v($w['title']) . '“ wirklich aus dem Shop entfernen? (Das Werk wird archiviert und taucht nicht mehr auf.)">
      ' . self::csrfField() . '<input type="hidden" name="id" value="' . $v($w['id']) . '"><button class="btn btn--ghost" type="submit">Werk aus dem Shop entfernen</button></form>' : '') . '
    <template data-at-roles>' . $roleOpts . '</template>', $error !== '' ? 422 : 200);
    }

    /** Fotos eines Werks als Liste [{url, role}] in Shop-Reihenfolge (Hauptfoto, Schwarzlicht, weitere). */
    private static function imagesOf(array $w): array
    {
        $out = [];
        if ($w['image'] !== '') {
            $out[] = ['url' => $w['image'], 'role' => 'haupt'];
        }
        if ($w['uvImage'] !== '') {
            $out[] = ['url' => $w['uvImage'], 'role' => 'uv'];
        }
        foreach ($w['gallery'] as $g) {
            $out[] = ['url' => $g['src'], 'role' => $g['kind']];
        }
        return $out;
    }

    // ---------- Speichern ----------

    private static function parseEuro(string $s): ?int
    {
        $t = preg_replace('/\s|€/u', '', trim($s)) ?? '';
        $t = preg_replace('/\.(?=\d{3}(\D|$))/', '', $t) ?? '';
        $t = str_replace(',', '.', $t);
        if ($t === '') {
            return null;
        }
        // Stripe Checkout erlaubt höchstens 999.999,99 € je Position
        if (!is_numeric($t) || (float) $t <= 0 || (float) $t >= 1e6) {
            return -1;
        }
        return (int) round((float) $t * 100);
    }

    /** PHP-Größenangabe wie „8M“ in Bytes. */
    private static function iniBytes(string $v): int
    {
        $n = (int) $v;
        return match (strtoupper(substr(trim($v), -1))) {
            'G' => $n * 1024 ** 3,
            'M' => $n * 1024 ** 2,
            'K' => $n * 1024,
            default => $n,
        } ?: PHP_INT_MAX;
    }

    /** Position aus dem Formular; leer = ans Ende (9999), 0 ist eine gültige Position ganz vorn. */
    private static function position(): int
    {
        $p = (string) preg_replace('/\D/', '', Http::post('position', 5));
        return $p === '' ? 9999 : (int) $p;
    }

    private static function lines(string $s): array
    {
        return array_values(array_filter(array_map(fn ($x) => mb_substr(trim($x), 0, 200), preg_split('/\r?\n/', $s) ?: []), fn ($x) => $x !== ''));
    }

    /** Erlaubte Bildpfade: eigene Uploads oder vorhandene Werkfotos. Keine fremden Adressen. */
    private static function validImagePath(string $u): bool
    {
        return (bool) preg_match('#^/(bilder|img/works)/[A-Za-z0-9._-]+\.(jpg|jpeg|webp|png)$#', $u);
    }

    private static function draft(?array $prev): array
    {
        $imgs = json_decode(Http::post('bilder', 20000), true);
        $imgs = is_array($imgs) ? array_values(array_filter($imgs, fn ($x) => is_array($x) && is_string($x['url'] ?? null) && self::validImagePath($x['url']))) : [];
        return array_merge($prev ?? [], [
            'id' => $prev['id'] ?? '',
            'title' => Http::post('titel', 120),
            'year' => Http::post('jahr', 4),
            'series' => Http::post('serie', 60),
            'technique' => Http::post('technik', 200),
            'size' => Http::post('masse', 80),
            'description' => Http::post('beschreibung', 4000),
            'awards' => self::lines(Http::post('auszeichnungen')),
            'exhibitions' => self::lines(Http::post('ausstellungen')),
            'w' => Http::post('w', 5), 'h' => Http::post('h', 5),
            'sort' => self::position(),
            '_images' => $imgs,
            '_price' => Http::post('preis', 20),
            '_ship' => Http::post('versand', 20),
            '_status' => Http::post('status') === 'sold' ? 'sold' : 'available',
            '_status_geladen' => Http::post('status_geladen', 20),
            '_hidden' => Http::post('sichtbar') === 'nein',
        ]);
    }

    /** @return array{error?: string, id?: int, msg?: string} */
    private static function save(): array
    {
        $id = (int) Http::post('id', 12);
        $title = trim(Http::post('titel', 120));
        if ($title === '') {
            return ['error' => 'Bitte einen Titel eingeben.'];
        }
        $cents = self::parseEuro(Http::post('preis', 20));
        if ($cents === -1) {
            return ['error' => 'Der Preis ist ungültig. Bitte nur eine Zahl bis 999.999 eingeben, z. B. 2400.'];
        }
        $shipIn = trim(Http::post('versand', 20));
        $shipCents = $shipIn === '' ? null : (preg_match('/^0+([.,]0*)?\s*€?$/u', $shipIn) ? 0 : self::parseEuro($shipIn));
        if ($shipCents === -1 || ($shipCents !== null && $shipCents > 1000000)) {
            return ['error' => 'Der Versandpreis ist ungültig. Bitte eine Zahl bis 10.000 eingeben, z. B. 49 oder 49,50.'];
        }
        $year = preg_replace('/\D/', '', Http::post('jahr', 4));

        $images = json_decode(Http::post('bilder', 20000), true);
        $images = is_array($images) ? array_values(array_filter($images, fn ($x) => is_array($x) && is_string($x['url'] ?? null) && self::validImagePath($x['url']))) : [];
        $images = array_slice($images, 0, self::MAX_IMAGES);
        foreach ($images as $x) {
            $file = str_starts_with($x['url'], '/bilder/')
                ? Config::get()['uploadDir'] . '/' . basename($x['url'])
                : dirname(__DIR__, 2) . '/public' . $x['url'];
            if (!is_file($file)) {
                return ['error' => 'Ein Foto ist nicht mehr vorhanden (vermutlich zu lange nicht gespeichert). Bitte entfernen und neu hochladen.'];
            }
        }
        $main = $images[0] ?? null;
        $uv = null;
        foreach (array_slice($images, 1) as $x) {
            if (($x['role'] ?? '') === 'uv') {
                $uv = $x;
                break;
            }
        }
        $rest = array_values(array_filter(array_slice($images, 1), fn ($x) => $x !== $uv));
        $status = Http::post('status') === 'sold' ? 'sold' : 'available';
        // Status nur ändern, wenn Matthias ihn im Formular wirklich umgestellt hat. Sonst könnte ein Speichern
        // einen Verkauf überschreiben, der nach dem Öffnen des Formulars über den Shop hereinkam.
        $loaded = Http::post('status_geladen', 20);
        $statusChanged = !in_array($loaded, ['available', 'reserved', 'sold'], true) || ($status === 'sold') !== ($loaded === 'sold');
        $size = trim(Http::post('masse', 80));

        $data = [
            'titel' => $title,
            'jahr' => $year !== '' ? (int) $year : null,
            'serie' => trim(Http::post('serie', 60)),
            'technik' => trim(Http::post('technik', 200)),
            'masse' => $size,
            'format' => Works::formatFromSize($size),
            'beschreibung' => trim(Http::post('beschreibung', 4000)),
            'preis_cent' => $cents,
            'versand_cent' => $shipCents,
            'sichtbar' => Http::post('sichtbar') === 'nein' ? 0 : 1,
            'position' => self::position(),
            'bild_breite' => ($bw = (int) preg_replace('/\D/', '', Http::post('w', 5))) ?: null,
            'bild_hoehe' => ($bh = (int) preg_replace('/\D/', '', Http::post('h', 5))) ?: null,
            'thumb' => $main ? self::thumbFor($main['url']) : null,
            'auszeichnungen' => json_encode(self::lines(Http::post('auszeichnungen')), JSON_UNESCAPED_UNICODE),
            'ausstellungen' => json_encode(self::lines(Http::post('ausstellungen')), JSON_UNESCAPED_UNICODE),
        ];

        $expire = null;
        $releaseAfterExpire = false;
        $r = Db::transaction(function () use ($id, $data, $status, $statusChanged, $main, $uv, $rest, &$expire, &$releaseAfterExpire) {
            if ($id === 0) {
                $data['slug'] = Catalog::uniqueSlug($data['titel']);
                $data['status'] = $status;
                if ($status === 'sold') {
                    $data['verkauft_am'] = gmdate('Y-m-d');
                }
                $cols = array_keys($data);
                // Legt jemand gleichzeitig ein Werk mit gleichem Titel an, nächste freie Adresse nehmen
                for ($try = 0; ; $try++) {
                    try {
                        Db::run('INSERT INTO werke (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($data));
                        break;
                    } catch (\PDOException $e) {
                        if ((int) ($e->errorInfo[1] ?? 0) !== 1062 || $try >= 5) {
                            throw $e;
                        }
                        // uniqueSlug() sieht innerhalb der Transaktion (REPEATABLE READ) nur den alten Stand: zufällige Endung anhängen
                        $data['slug'] = substr(Works::slugify($data['titel']), 0, 120) . '-' . bin2hex(random_bytes(2));
                    }
                }
                $id = Db::lastId();
                $msg = 'angelegt';
            } else {
                $prev = Db::one('SELECT status, masse, format, thumb, sichtbar, preis_cent, versand_cent, reserviert_session, (reserviert_bis > UTC_TIMESTAMP()) AS reserviert FROM werke WHERE id = ? AND aktiv = 1 FOR UPDATE', [$id]);
                if (!$prev) {
                    return ['error' => 'Das Werk gibt es nicht mehr.', 'gone' => true];
                }
                // Werte, die das Formular nicht zeigt, nur ändern, wenn sich ihre Grundlage ändert:
                // Formatklasse (Versandpreis) nur bei geänderten Maßen, Vorschaubild nur bei neuem Hauptfoto.
                if ($data['masse'] === $prev['masse'] && $prev['format'] !== null) {
                    $data['format'] = $prev['format'];
                }
                $prevMain = Db::one('SELECT pfad FROM werk_bilder WHERE werk_id = ? AND rolle = \'haupt\' ORDER BY reihenfolge LIMIT 1', [$id]);
                if ($main && $prevMain && $prevMain['pfad'] === $main['url'] && $prev['thumb'] !== null) {
                    $data['thumb'] = $prev['thumb'];
                }
                $set = implode(', ', array_map(fn ($c) => "$c = ?", array_keys($data)));
                Db::run("UPDATE werke SET $set WHERE id = ?", [...array_values($data), $id]);
                $msg = 'gespeichert';
                if ($statusChanged && $status === 'sold' && $prev['status'] !== 'sold') {
                    Db::run('UPDATE werke SET status = \'sold\', verkauft_am = UTC_DATE(), reserviert_bis = NULL, reserviert_session = NULL WHERE id = ?', [$id]);
                    // Läuft gerade ein Bezahlvorgang, die Stripe-Sitzung schließen, damit niemand mehr bezahlen kann
                    if ((int) $prev['reserviert'] && str_starts_with((string) $prev['reserviert_session'], 'cs_')) {
                        $expire = $prev['reserviert_session'];
                    }
                } elseif ($statusChanged && $status === 'available' && $prev['status'] === 'sold') {
                    // doppelverkauf_session bleibt: die zweite Zahlung muss trotzdem erstattet werden
                    Db::run('UPDATE werke SET status = \'available\', verkauft_am = NULL, verkauft_session = NULL WHERE id = ?', [$id]);
                } elseif (!$statusChanged && $prev['status'] === 'sold' && $status !== 'sold') {
                    $msg = 'inzwischen-verkauft';
                }
                // Läuft gerade ein Bezahlvorgang und wird das Werk ausgeblendet oder ändern sich Preis/Versand,
                // diesen Bezahlvorgang beenden: sonst würde ein ausgeblendetes Werk oder zum alten Preis verkauft.
                $int = fn ($v) => $v === null ? null : (int) $v;
                // Auch eine gerade entstehende Sitzung (pending_…) zählt: ihre Reservierung wird aufgehoben,
                // dann merkt der Checkout das und schließt die neue Stripe-Sitzung selbst.
                $runningSession = (int) $prev['reserviert'] && preg_match('/^(cs_|pending_)/', (string) $prev['reserviert_session']) ? $prev['reserviert_session'] : null;
                // tatsächlicher Versandpreis: eigener Preis oder aus der Formatklasse (die an den Maßen hängt)
                $shipping = fn ($cents, $format) => $cents !== null ? (int) $cents : (int) round(Works::shippingFor(['shippingEur' => null, 'format' => $format ?: 'mittel']) * 100);
                $changedTerms = ((int) $prev['sichtbar'] === 1 && $data['sichtbar'] === 0)
                    || $int($prev['preis_cent']) !== $int($data['preis_cent'])
                    || $shipping($int($prev['versand_cent']), $prev['format'] ?: Works::formatFromSize((string) $prev['masse'])) !== $shipping($int($data['versand_cent']), $data['format']);
                if ($expire === null && $runningSession !== null && $changedTerms) {
                    if (str_starts_with($runningSession, 'cs_')) {
                        // fertige Stripe-Sitzung: erst bei Stripe schließen, dann freigeben (nach der Transaktion)
                        $expire = $runningSession;
                        $releaseAfterExpire = true;
                    } else {
                        // entstehende Sitzung (pending_…): Reservierung aufheben, der Checkout schließt seine Sitzung selbst
                        Db::run('UPDATE werke SET reserviert_bis = NULL, reserviert_session = NULL WHERE id = ? AND reserviert_session = ?', [$id, $runningSession]);
                    }
                    $msg = 'bezahlvorgang-beendet';
                }
            }
            Db::run('DELETE FROM werk_bilder WHERE werk_id = ?', [$id]);
            $i = 0;
            foreach (array_filter([$main ? ['url' => $main['url'], 'role' => 'haupt'] : null, $uv ? ['url' => $uv['url'], 'role' => 'uv'] : null]) as $x) {
                Db::run('INSERT INTO werk_bilder (werk_id, pfad, rolle, reihenfolge) VALUES (?, ?, ?, ?)', [$id, $x['url'], $x['role'], $i++]);
            }
            foreach ($rest as $x) {
                $role = in_array($x['role'] ?? '', ['detail', 'raum', 'ansicht'], true) ? $x['role'] : 'ansicht';
                Db::run('INSERT INTO werk_bilder (werk_id, pfad, rolle, reihenfolge) VALUES (?, ?, ?, ?)', [$id, $x['url'], $role, $i++]);
            }
            return ['id' => $id, 'msg' => $msg];
        });
        if ($expire !== null) {
            try {
                CheckoutController::expireSession($expire);
                Db::run('UPDATE bestellungen SET status = \'abgelaufen\' WHERE stripe_session_id = ? AND status = \'offen\'', [$expire]);
                if ($releaseAfterExpire) {
                    Catalog::release((int) $r['id'], $expire);
                }
            } catch (\Throwable $e) {
                Log::warn('Offene Stripe-Sitzung nicht geschlossen: ' . $e->getMessage());
                if (isset($r['msg'])) {
                    // Reservierung bleibt bis zum Ablauf bzw. Verkauf von Hand mit laufender Zahlung: ehrlich melden
                    $r['msg'] = $releaseAfterExpire ? 'bezahlvorgang-laeuft' : 'verkauft-zahlung-laeuft';
                }
            }
        }
        return $r;
    }

    /** Vorschaubild zum Hauptfoto: die mittlere Größe (-sm), sofern es sie gibt; sonst das Hauptfoto selbst (NULL). */
    private static function thumbFor(string $url): ?string
    {
        if (preg_match('#^/bilder/[a-z0-9-]+\.jpg$#', $url)) {
            return preg_replace('/\.jpg$/', '-sm.jpg', $url);
        }
        if (preg_match('#^/img/works/([A-Za-z0-9._-]+)\.jpg$#', $url, $m) && is_file(dirname(__DIR__, 2) . "/public/img/works/{$m[1]}-sm.jpg")) {
            return "/img/works/{$m[1]}-sm.jpg";
        }
        return null;
    }

    // ---------- Foto-Upload (vom Browser bereits verkleinert) ----------

    private static function upload(): never
    {
        $dir = Config::get()['uploadDir'];
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            Http::jsonOut(['error' => 'Upload-Ordner fehlt'], 500);
        }
        $id = bin2hex(random_bytes(16));
        // Bricht PHP mittendrin ab (z. B. Speichergrenze), halb geschriebene Dateien trotzdem entfernen
        $done = false;
        register_shutdown_function(function () use (&$done, $dir, &$id) {
            if (!$done) {
                foreach (glob("$dir/$id*") ?: [] as $f) {
                    @unlink($f);
                }
            }
        });
        $id = substr($id, 0, 8) . '-' . substr($id, 8, 4) . '-' . substr($id, 12, 4) . '-' . substr($id, 16, 4) . '-' . substr($id, 20);
        $written = [];
        foreach (['' => 'full', '-sm' => 'sm', '-xs' => 'xs'] as $suffix => $field) {
            $f = $_FILES[$field] ?? null;
            if ($f && in_array($f['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                self::cleanup($written);
                Http::jsonOut(['error' => 'Foto zu groß für den Server (Grenze ' . ini_get('upload_max_filesize') . ')'], 413);
            }
            if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
                self::cleanup($written);
                Http::jsonOut(['error' => 'Datei fehlt'], 400);
            }
            if ($f['size'] > 4 * 1024 * 1024) {
                self::cleanup($written);
                Http::jsonOut(['error' => 'Foto zu groß'], 413);
            }
            $bytes = (string) file_get_contents($f['tmp_name']);
            if (!str_starts_with($bytes, "\xFF\xD8")) {
                self::cleanup($written);
                Http::jsonOut(['error' => 'Nur JPG erlaubt'], 415);
            }
            // Abmessungen aus dem Dateikopf prüfen, bevor das Bild im Speicher entpackt wird
            $dim = @getimagesizefromstring($bytes);
            // Der Browser verkleinert auf höchstens 1600 px; 2400 px lassen Spielraum und bleiben speicherschonend (~23 MB)
            if (!$dim || $dim[0] < 1 || $dim[1] < 1 || $dim[0] > 2400 || $dim[1] > 2400) {
                self::cleanup($written);
                Http::jsonOut(['error' => 'Foto nicht lesbar oder größer als 2400 px'], 415);
            }
            // Neu speichern: entfernt Metadaten (z. B. GPS) und alles, was kein Bild ist
            $img = @imagecreatefromstring($bytes);
            if (!$img) {
                self::cleanup($written);
                Http::jsonOut(['error' => 'Foto nicht lesbar'], 415);
            }
            $path = "$dir/$id$suffix.jpg";
            if (!@imagejpeg($img, $path, 86) || !is_file($path) || filesize($path) === 0) {
                self::cleanup([...$written, $path]);
                Log::error('Foto konnte nicht gespeichert werden: ' . $path);
                Http::jsonOut(['error' => 'Foto konnte nicht gespeichert werden'], 500);
            }
            $written[] = $path;
            // Zusätzlich WebP (deutlich kleiner); der Shop liefert es nur aus, wenn alle drei Größen da sind
            $webp = "$dir/$id$suffix.webp";
            if (function_exists('imagewebp') && @imagewebp($img, $webp, 80) && is_file($webp) && filesize($webp) > 0) {
                $webps[] = $webp;
            } else {
                $webpFailed = true;
                @unlink($webp);
            }
        }
        $done = true;
        if (!empty($webpFailed)) {
            self::cleanup($webps ?? []); // WebP alle oder keine – sonst bliebe bei manchen Breiten das Bild leer
        }
        Http::jsonOut(['url' => "/bilder/$id.jpg"]);
    }

    private static function cleanup(array $paths): void
    {
        foreach ($paths as $p) {
            @unlink($p);
        }
    }

    // ---------- Widerrufe und Anfragen ----------

    private static function decryptRows(array $rows, string $table): array
    {
        $ok = [];
        $bad = 0;
        foreach ($rows as $r) {
            try {
                $ok[] = Crypto::decrypt($r['daten_enc'], "$table/{$r['ref']}") + ['_row' => $r];
            } catch (\Throwable $e) {
                $bad++;
                Log::error("Eintrag $table/{$r['ref']} nicht entschlüsselbar: " . $e->getMessage());
            }
        }
        return [$ok, $bad];
    }

    private static function cryptoWarning(int $bad): string
    {
        if (!Crypto::ready()) {
            return '<p class="at-error">Es ist kein Schlüssel für die Verschlüsselung eingerichtet (<code>DATA_ENCRYPTION_KEY</code>). Neue Einträge werden deshalb nicht gespeichert, sie kommen nur per E-Mail an.</p>';
        }
        return $bad ? '<p class="at-error">' . $bad . ' Eintrag/Einträge lassen sich mit dem aktuellen Schlüssel nicht entschlüsseln. Wurde der Schlüssel gewechselt, den bisherigen als <code>DATA_ENCRYPTION_KEY_OLD</code> eintragen.</p>' : '';
    }

    private static function withdrawalsPage(): never
    {
        [$recs, $bad] = self::decryptRows(Db::all('SELECT * FROM widerrufe ORDER BY eingang DESC LIMIT 200'), 'widerrufe');
        $list = $recs ? '<ul class="at-list">' . implode('', array_map(fn ($r) => '<li class="at-card at-withdraw"><strong>' . e($r['contract']) . '</strong><span>' . e($r['name']) . ' · <a href="mailto:' . e($r['email']) . '">' . e($r['email']) . '</a></span><span class="muted">Eingang ' . e($r['receivedLabel']) . ' · ' . e($r['ref']) . ((int) $r['_row']['mail_versendet'] ? '' : ' · Bestätigung nicht versendet') . '</span></li>', $recs)) . '</ul>' : '<p class="at-card">Bisher keine Widerrufe.</p>';
        $years = Config::get()['retention']['withdrawalYears'];
        self::page('Widerrufe', '<p class="at-back"><a href="/atelier">← Alle Werke</a></p>
        <div class="at-top"><div><p class="eyebrow">Nachweise</p><h1 class="display">Widerrufe</h1></div></div>
        <p class="muted">Hier landen alle Widerrufe, die Käufer über „Vertrag widerrufen“ abgeschickt haben. Sie bekommen zusätzlich jedes Mal eine E-Mail. Nach Rückerhalt des Werks: Zahlung in Stripe erstatten und das Werk wieder auf „Verfügbar“ stellen. Die Angaben sind verschlüsselt gespeichert und werden nach ' . $years . ' Jahren automatisch gelöscht.</p>
        ' . self::cryptoWarning($bad) . $list);
    }

    private static function inquiriesPage(): never
    {
        [$recs, $bad] = self::decryptRows(Db::all('SELECT * FROM anfragen ORDER BY eingang DESC LIMIT 300'), 'anfragen');
        $labels = ['allgemein' => 'Allgemein', 'preis' => 'Preisanfrage', 'frage' => 'Frage zum Werk', 'warteliste' => 'Warteliste', 'aehnlich' => 'Ähnliches Werk'];
        $list = $recs ? '<ul class="at-list">' . implode('', array_map(function ($r) use ($labels) {
            $when = (new \DateTimeImmutable($r['_row']['eingang'] . ' UTC'))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y, H:i');
            return '<li class="at-card at-withdraw"><strong>' . e($labels[$r['anliegen']] ?? 'Anfrage') . ($r['werk'] !== '' ? ' · ' . e($r['werk']) : '') . '</strong>'
                . '<span>' . e($r['name']) . ' · <a href="mailto:' . e($r['email']) . '">' . e($r['email']) . '</a>' . ($r['telefon'] !== '' ? ' · ' . e($r['telefon']) : '') . '</span>'
                . '<span class="at-msg">' . nl2br(e($r['nachricht'])) . '</span>'
                . '<span class="muted">Eingang ' . $when . ' Uhr · ' . e($r['_row']['ref']) . '</span></li>';
        }, $recs)) . '</ul>' : '<p class="at-card">Bisher keine Anfragen.</p>';
        $months = Config::get()['retention']['inquiryMonths'];
        self::page('Anfragen', '<p class="at-back"><a href="/atelier">← Alle Werke</a></p>
        <div class="at-top"><div><p class="eyebrow">Postfach</p><h1 class="display">Anfragen</h1></div></div>
        <p class="muted">Anfragen aus dem Shop. Jede kommt zusätzlich per E-Mail. Die Angaben sind verschlüsselt gespeichert und werden nach ' . $months . ' Monaten automatisch gelöscht.</p>
        ' . self::cryptoWarning($bad) . $list);
    }

    // ---------- Router ----------

    public static function handle(string $path): never
    {
        $cfg = Config::get();
        $method = Http::method();

        if ($path === '/atelier/login' && $method === 'POST') {
            if (!Http::sameOrigin()) {
                http_response_code(403);
                exit('Forbidden');
            }
            // Sperre nach zu vielen Fehlversuchen je IP (gilt auch für das richtige Passwort)
            $ip = RateLimit::clientIp();
            $win = $cfg['limits']['loginWindowMin'] * 60;
            // Jeder Versuch belegt vor der Passwortprüfung einen Platz (atomar), damit auch viele
            // gleichzeitige Anfragen nicht mehr als das Limit an Versuchen bekommen. Erfolg setzt zurück.
            if (!RateLimit::hit('login', $ip, $cfg['limits']['loginFailsPerIp'], $win)['ok']) {
                self::loginPage("Zu viele Fehlversuche. Bitte in {$cfg['limits']['loginWindowMin']} Minuten erneut versuchen.", 429);
            }
            $name = mb_strtolower(trim(Http::post('benutzer', 60)));
            // Viele Fehlversuche für diesen Benutzernamen (verteilt über viele IPs): bremsen, aber nicht sperren –
            // sonst könnte jeder den echten Zugang aussperren. Mit richtigem Passwort UND Code klappt es immer.
            // Steht ein Benutzername unter Beschuss (viele Fehlversuche von vielen IPs), darf jede IP für diesen
            // Benutzer nur noch 3 Versuche je Zeitfenster machen. Wer beim ersten Mal Passwort und Code richtig hat,
            // kommt weiter rein (kein Aussperren); ein verteilter Angriff bekommt aber deutlich weniger Versuche.
            // Den Rest schützt der Zwei-Faktor-Code.
            if (RateLimit::attempts('login-user', $name, $win) >= $cfg['limits']['loginFailsPerIp'] * 2) {
                Log::warn('Atelier: viele Fehlversuche für einen Benutzernamen');
                if (!RateLimit::hit('login-user-ip', $name . '|' . $ip, 3, $win)['ok']) {
                    self::loginPage("Zu viele Fehlversuche. Bitte in {$cfg['limits']['loginWindowMin']} Minuten erneut versuchen.", 429);
                }
            }
            // Beide Felder lang genug lesen: ein Notfall-Code (XXXXX-XXXXX) im Code-Feld wird so auch erkannt
            $code = trim(Http::post('code', 20)) !== '' ? Http::post('code', 20) : Http::post('notfallcode', 20);
            $user = AtelierAuth::attempt($name, Http::post('passwort', 500), $code);
            if ($user) {
                RateLimit::reset('login', $ip);
                RateLimit::reset('login-user', $name);
                RateLimit::reset('login-user-ip', $name . '|' . $ip);
                AtelierAuth::login($user);
                if (isset($user['_notfall_rest'])) {
                    $rest = $user['_notfall_rest'];
                    Http::redirect('/atelier?ok=notfall&n=' . (int) $rest);
                }
                Http::redirect('/atelier');
            }
            RateLimit::hit('login-user', $name, PHP_INT_MAX, $win); // Fehlversuch je Benutzer zählen
            // bewusst ohne Hinweis, welcher der drei Werte falsch war
            self::loginPage('Anmeldung fehlgeschlagen. Bitte Benutzername, Passwort und Code prüfen.');
        }

        if (!AtelierAuth::isLoggedIn()) {
            if ($method !== 'GET') {
                http_response_code(401);
                header('Cache-Control: no-store');
                exit('Bitte anmelden');
            }
            self::loginPage();
        }
        // Übersteigt eine Anfrage post_max_size, verwirft PHP den ganzen Inhalt (auch das CSRF-Token): klar melden
        if ($path === '/atelier/upload' && $method === 'POST' && !$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > self::iniBytes((string) ini_get('post_max_size'))) {
            Http::jsonOut(['error' => 'Foto zu groß für den Server (Grenze ' . ini_get('post_max_size') . ')'], 413);
        }
        if ($method === 'POST' && (!Http::sameOrigin() || !AtelierAuth::checkCsrf(Http::post('csrf', 100)))) {
            http_response_code(403);
            exit('Forbidden');
        }

        try {
            if ($path === '/atelier/logout' && $method === 'POST') {
                AtelierAuth::logout();
                Http::redirect('/atelier');
            }
            if ($path === '/atelier' && $method === 'GET') {
                self::listPage(self::okMessage());
            }
            if ($path === '/atelier/neu' && $method === 'GET') {
                self::editPage(null);
            }
            if ($path === '/atelier/widerrufe' && $method === 'GET') {
                self::withdrawalsPage();
            }
            if ($path === '/atelier/anfragen' && $method === 'GET') {
                self::inquiriesPage();
            }
            if (preg_match('#^/atelier/werk/(\d{1,10})$#', $path, $m) && $method === 'GET') {
                $w = Catalog::byIdForAtelier((int) $m[1]);
                if (!$w) {
                    Http::redirect('/atelier');
                }
                self::editPage($w, '', self::okMessage());
            }
            if ($path === '/atelier/upload' && $method === 'POST') {
                self::upload();
            }
            if ($path === '/atelier/speichern' && $method === 'POST') {
                $r = self::save();
                if (isset($r['gone'])) {
                    Http::redirect('/atelier?ok=nicht-mehr-da'); // sonst würde der Entwurf als neues Werk erscheinen
                }
                if (isset($r['error'])) {
                    $id = (int) Http::post('id', 12);
                    $prev = $id ? Catalog::byIdForAtelier($id) : null;
                    if ($id && !$prev) {
                        Http::redirect('/atelier?ok=nicht-mehr-da'); // sonst würde der Entwurf als neues Werk erscheinen
                    }
                    self::editPage(self::draft($prev), $r['error']);
                }
                Http::redirect('/atelier/werk/' . $r['id'] . '?ok=' . $r['msg']);
            }
            if ($path === '/atelier/archivieren' && $method === 'POST') {
                $id = (int) Http::post('id', 12);
                if ($id) {
                    // Läuft gerade ein Bezahlvorgang, zuerst die Stripe-Sitzung schließen. Gelingt das nicht, wird das
                    // Werk NICHT entfernt – sonst könnte es unbemerkt gekauft werden, obwohl es nicht mehr im Atelier erscheint.
                    $r = Db::one('SELECT reserviert_session, (reserviert_bis > UTC_TIMESTAMP()) AS reserviert FROM werke WHERE id = ?', [$id]);
                    $open = $r && (int) $r['reserviert'] && str_starts_with((string) $r['reserviert_session'], 'cs_') ? $r['reserviert_session'] : null;
                    if ($open !== null) {
                        try {
                            CheckoutController::expireSession($open);
                            Db::run('UPDATE bestellungen SET status = \'abgelaufen\' WHERE stripe_session_id = ? AND status = \'offen\'', [$open]);
                        } catch (\Throwable $e) {
                            Log::warn('Entfernen abgebrochen, Stripe-Sitzung nicht geschlossen: ' . $e->getMessage());
                            Http::redirect('/atelier/werk/' . $id . '?ok=entfernen-bezahlvorgang');
                        }
                    }
                    // Nur archivieren, wenn sich die Reservierung seit dem Lesen nicht geändert hat. Ist inzwischen aus einer
                    // entstehenden (pending_) eine echte Stripe-Sitzung geworden, lieber neu versuchen lassen.
                    // Wurde die Sitzung eben geschlossen, darf der Webhook „expired“ die Reservierung schon gelöscht haben
                    $done = Db::run('UPDATE werke SET aktiv = 0, reserviert_bis = NULL, reserviert_session = NULL WHERE id = ? AND (reserviert_session <=> ? OR (? = 1 AND reserviert_session IS NULL))', [$id, $r['reserviert_session'] ?? null, $open !== null ? 1 : 0]);
                    if ($done === 0 && Db::one('SELECT 1 FROM werke WHERE id = ? AND aktiv = 1', [$id])) {
                        Http::redirect('/atelier/werk/' . $id . '?ok=entfernen-bezahlvorgang');
                    }
                    if ($done === 0 || !$r) {
                        Http::redirect('/atelier?ok=nicht-mehr-da'); // gab es nicht oder war schon entfernt
                    }
                }
                Http::redirect('/atelier?ok=entfernt');
            }
            Http::redirect('/atelier');
        } catch (\Throwable $e) {
            Log::error('Atelier-Fehler: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            self::page('Fehler', '<section class="at-login"><h1 class="display">Das hat nicht geklappt</h1><p>Bitte noch einmal versuchen. Bleibt der Fehler, steht der Grund im Protokoll des Servers.</p><p><a class="btn" href="/atelier">Zurück</a></p></section>', 500);
        }
    }
}
