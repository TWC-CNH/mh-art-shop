<?php
declare(strict_types=1);

namespace App\View;

use App\Config;
use App\Works;

/**
 * Serverseitiges Rendering aller Seiten (kein Framework, reines HTML).
 * Ursprünglich 1:1 aus der Node-Fassung (Branch main) übernommen; die Ausgabe war bytegleich.
 */
final class Render
{
    public const ASSET_V = '17';

    public const KIND_LABEL = ['detail' => 'Detail', 'raum' => 'Im Raum', 'ansicht' => 'Ansicht'];

    // ---------------------------------------------------------------- JS-Nachbildungen

    /** Wahrheitswert wie in JavaScript ('0' ist wahr, leere Arrays/Objekte ebenfalls). */
    private static function t(mixed $v): bool
    {
        if (is_string($v)) {
            return $v !== '';
        }
        if (is_float($v)) {
            return $v != 0.0 && !is_nan($v);
        }
        if (is_array($v)) {
            return true;
        }
        return (bool) $v;
    }

    /** Wert so in Text umwandeln wie ein JS-Template-Literal (`${x}`). */
    public static function str(mixed $v): string
    {
        return match (true) {
            $v === null => 'null',
            is_bool($v) => $v ? 'true' : 'false',
            is_int($v) => (string) $v,
            is_float($v) => self::num($v),
            default => (string) $v,
        };
    }

    /** Number.prototype.toString() für Gleitkommazahlen. */
    private static function num(float $n): string
    {
        if (is_nan($n)) {
            return 'NaN';
        }
        if (is_infinite($n)) {
            return $n > 0 ? 'Infinity' : '-Infinity';
        }
        if ($n == 0.0) {
            return '0';
        }
        // Kürzeste eindeutige Darstellung (serialize_precision = -1), danach JS-Regeln für Exponent/Dezimalpunkt
        $r = json_encode($n);
        if (!preg_match('/^(-?)(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/D', (string) $r, $m)) {
            return (string) $r;
        }
        $sign = $m[1];
        $int = $m[2];
        $frac = $m[3] ?? '';
        $exp = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 0;
        $digits = $int . $frac;
        $point = strlen($int) + $exp;
        $lead = strlen($digits) - strlen(ltrim($digits, '0'));
        $digits = substr($digits, $lead);
        $point -= $lead;
        $digits = rtrim($digits, '0');
        $k = strlen($digits);
        $nn = $point;
        if ($k <= $nn && $nn <= 21) {
            $s = $digits . str_repeat('0', $nn - $k);
        } elseif (0 < $nn && $nn <= 21) {
            $s = substr($digits, 0, $nn) . '.' . substr($digits, $nn);
        } elseif (-6 < $nn && $nn <= 0) {
            $s = '0.' . str_repeat('0', -$nn) . $digits;
        } else {
            $e = $nn - 1;
            $s = $digits[0] . ($k > 1 ? '.' . substr($digits, 1) : '') . 'e' . ($e >= 0 ? '+' : '-') . abs($e);
        }
        return $sign . $s;
    }

    /** Number.prototype.toFixed(): rundet den exakten Binärwert, bei Gleichstand nach oben (Betrag). */
    private static function toFixed(int|float $x, int $f): string
    {
        $x = (float) $x;
        if (is_nan($x)) {
            return 'NaN';
        }
        if (abs($x) >= 1e21) {
            return self::num($x);
        }
        $neg = $x < 0;
        $s = sprintf('%.50f', abs($x));
        [$ip, $fp] = explode('.', $s);
        $keep = $ip . substr($fp, 0, $f);
        $up = ($fp[$f] ?? '0') >= '5';
        if ($up) {
            $arr = str_split($keep);
            for ($i = count($arr) - 1; $i >= 0; $i--) {
                if ($arr[$i] === '9') {
                    $arr[$i] = '0';
                    continue;
                }
                $arr[$i] = (string) ((int) $arr[$i] + 1);
                break;
            }
            $keep = ($i < 0 ? '1' : '') . implode('', $arr);
        }
        $intLen = strlen($keep) - $f;
        $out = $f > 0 ? substr($keep, 0, $intLen) . '.' . substr($keep, $intLen) : $keep;
        $isZero = trim(str_replace('.', '', $out), '0') === '';
        return ($neg && !$isZero ? '-' : '') . $out;
    }

    /** encodeURIComponent() – rawurlencode lässt nur -_.~ stehen, JS zusätzlich !'()* */
    private static function enc(string $s): string
    {
        return strtr(rawurlencode($s), ['%21' => '!', '%27' => "'", '%28' => '(', '%29' => ')', '%2A' => '*']);
    }

    /** JSON.stringify() für Arrays/Strings/Zahlen (keine Slash-, Unicode- oder U+2028-Escapes). */
    private static function json(mixed $v): string
    {
        return (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
    }

    /** Zahl, die in JS ganzzahlig sein kann, als int (für JSON-Ausgabe wie JSON.stringify). */
    private static function jsonNum(int|float $n): int|float
    {
        return is_float($n) && floor($n) == $n && abs($n) < 1e15 ? (int) $n : $n;
    }

    // ---------------------------------------------------------------- Bilder

    private static function isLocalImg(mixed $src, ?array $cfg = null): bool
    {
        $cfg ??= cfg();
        return self::t($src) && preg_match('/\.jpe?g$/iD', $src) === 1 && (str_starts_with($src, '/img/') || str_starts_with($src, $cfg['siteUrl'] . '/img/'));
    }

    // Im Atelier hochgeladene Fotos – liegen in 1600/720/420 px als JPG vor
    private static function isAtelierImg(mixed $src, ?array $cfg = null): bool
    {
        $cfg ??= cfg();
        return self::t($src) && preg_match('/\/bilder\/[a-z0-9-]+\.jpg$/iD', $src) === 1 && (str_starts_with($src, '/bilder/') || str_starts_with($src, $cfg['siteUrl'] . '/bilder/'));
    }

    /** <picture> mit WebP für eigene Bilder (/img/…), srcset für Atelier-Fotos, normales <img> für sonstige URLs. */
    public static function pic(mixed $src, string $attrs = '', string $sizes = ''): string
    {
        if (self::isAtelierImg($src)) {
            $stem = preg_replace('/(-sm|-xs)?\.jpg$/iD', '', $src, 1);
            $img = $sizes !== ''
                ? '<img src="' . e($src) . '" srcset="' . e($stem . '-xs.jpg 420w, ' . $stem . '-sm.jpg 720w, ' . $stem . '.jpg 1600w') . '" sizes="' . e($sizes) . '" ' . $attrs . '>'
                : '<img src="' . e($src) . '" ' . $attrs . '>';
            // WebP-Varianten nur anbieten, wenn der Upload sie erzeugt hat (sonst bliebe das Bild leer)
            $dir = \App\Config::get()['uploadDir'] . '/' . basename($stem);
            if (!is_file("$dir.webp") || ($sizes !== '' && (!is_file("$dir-sm.webp") || !is_file("$dir-xs.webp")))) {
                return $img;
            }
            $set = $sizes !== '' ? $stem . '-xs.webp 420w, ' . $stem . '-sm.webp 720w, ' . $stem . '.webp 1600w' : preg_replace('/\.jpg$/iD', '.webp', $src, 1);
            return '<picture><source type="image/webp" srcset="' . e($set) . '"' . ($sizes !== '' ? ' sizes="' . e($sizes) . '"' : '') . '>' . $img . '</picture>';
        }
        $img = '<img src="' . e($src) . '" ' . $attrs . '>';
        if (!self::isLocalImg($src)) {
            return $img;
        }
        // Varianten: -xs (420 px), -sm (720 px), Original (1600 px)
        $stem = preg_replace('/(-sm|-xs)?\.jpe?g$/iD', '', $src, 1);
        $set = $sizes !== '' ? $stem . '-xs.webp 420w, ' . $stem . '-sm.webp 720w, ' . $stem . '.webp 1600w' : preg_replace('/\.jpe?g$/iD', '.webp', $src, 1);
        return '<picture><source type="image/webp" srcset="' . e($set) . '"' . ($sizes !== '' ? ' sizes="' . e($sizes) . '"' : '') . '>' . $img . '</picture>';
    }

    /** Gleiche Bildquelle als einfache URL in passender Größe (für JS-Umschaltung, Lightbox, SVG). */
    private static function sized(mixed $src, string $size = ''): mixed
    {
        return self::isLocalImg($src) || self::isAtelierImg($src) ? preg_replace('/(-sm|-xs)?\.jpe?g$/iD', $size . '.jpg', $src, 1) : $src;
    }

    private static function absUrl(array $cfg, mixed $path): string
    {
        if (!self::t($path)) {
            return '';
        }
        return preg_match('/^https?:\/\//', $path) === 1 ? $path : $cfg['siteUrl'] . $path;
    }

    // ---------------------------------------------------------------- Seitengerüst

    public static function layout(array $opts): string
    {
        $title = $opts['title'] ?? null;
        $description = $opts['description'] ?? '';
        $body = $opts['body'] ?? null;
        $canonical = $opts['canonical'] ?? '/';
        $ogImage = $opts['ogImage'] ?? '';
        $jsonLd = $opts['jsonLd'] ?? null;
        $bodyClass = $opts['bodyClass'] ?? '';
        $noindex = $opts['noindex'] ?? false;
        $preload = $opts['preload'] ?? '';
        $preloadSizes = $opts['preloadSizes'] ?? '(max-width: 1020px) 92vw, 60vw';

        $cfg = cfg();
        $fullTitle = self::t($title) ? $title . ' | ' . $cfg['artistName'] : $cfg['artistName'] . ' · ' . $cfg['shopName'] . ' – Originale kaufen';
        $desc = self::t($description) ? $description : 'Signierte Originalgemälde von ' . $cfg['artistName'] . '. ' . $cfg['tagline'] . ': figurative Malerei in Schwarz-Weiß mit Neon- und UV-reaktiven Farben. Direkt aus dem Atelier, mit Echtheitszertifikat.';
        return '<!doctype html>
<html lang="de" data-theme="day">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' . e($fullTitle) . '</title>
<meta name="description" content="' . e($desc) . '">
<link rel="canonical" href="' . e($cfg['siteUrl'] . $canonical) . '">
' . (self::t($noindex) ? '<meta name="robots" content="noindex">' : '') . '
<meta property="og:type" content="website">
<meta property="og:title" content="' . e($fullTitle) . '">
<meta property="og:description" content="' . e($desc) . '">
<meta property="og:url" content="' . e($cfg['siteUrl'] . $canonical) . '">
' . (self::t($ogImage) ? '<meta property="og:image" content="' . e(self::absUrl($cfg, $ogImage)) . '">' : '') . '
<meta name="theme-color" content="#ffffff">
<link rel="icon" href="/favicon.png" sizes="32x32">
<link rel="icon" href="/assets/icon-192.png" sizes="192x192">
<link rel="apple-touch-icon" href="/assets/apple-touch-icon.png">
<link rel="preload" href="/fonts/archivo-latin-wdth-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/fonts/newsreader-latin-opsz-italic.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/assets/shop.css?v=' . self::ASSET_V . '">
' . (self::t($preload) ? '<link rel="preload" as="image" type="image/webp" imagesrcset="' . e($preload) . '" imagesizes="' . e($preloadSizes) . '" fetchpriority="high">' : '') . '
<script>try{if(localStorage.getItem(\'mh-theme\')===\'uv\')document.documentElement.dataset.theme=\'uv\'}catch(e){}</script>
' . (self::t($jsonLd) ? '<script type="application/ld+json">' . str_replace('<', '\\u003c', self::json($jsonLd)) . '</script>' : '') . '
</head>
<body class="' . e($bodyClass) . '">
<a class="skip" href="#main">Zum Inhalt springen</a>
<header class="site-header">
  <a class="brand" href="/" aria-label="' . e($cfg['artistName']) . ' – ' . e($cfg['shopName']) . ' Startseite">
    <img class="logo logo--day" src="/assets/logo-mhart.png" alt="" width="126" height="60">
    <img class="logo logo--uv" src="/assets/logo-mhart-light.png" alt="" width="126" height="60" aria-hidden="true">
    <span class="brand-name">' . e($cfg['artistName']) . '</span>
  </a>
  <nav class="site-nav" id="site-nav" aria-label="Hauptnavigation">
    <a href="/#werke">Werke</a>
    <a href="/#schwarzlicht">Schwarzlicht</a>
    <a href="/#kuenstler">Künstler</a>
    <a href="/#kaufen">Kaufen</a>
  </nav>
  <a class="fav-link" href="/?filter=merkliste#werke" hidden data-fav-link aria-label="Merkliste"><span class="fav-heart" aria-hidden="true">♡</span><span class="fav-count" data-fav-count aria-hidden="true">0</span></a>
  <button class="uv-toggle" type="button" aria-pressed="false" aria-describedby="uv-tip">
    <span class="uv-switch" aria-hidden="true"><span></span></span><span class="uv-label">UV-Licht</span>
  </button>
  <button class="menu-btn" type="button" aria-expanded="false" aria-controls="site-nav" data-menu><span aria-hidden="true"></span><span class="sr-only">Menü</span></button>
  <p class="uv-tip" id="uv-tip" role="status">Schwarzlicht an: Werke mit UV-Aufnahme zeigen jetzt, wie sie im Dunkeln leuchten.</p>
</header>
<main id="main">
' . self::str($body ?? 'undefined') . '
</main>
<footer class="site-footer">
  <p class="wordmark" aria-hidden="true"><span>Matthias</span> <span>Hübner<em>.</em></span></p>
  <div class="footer-grid">
    <div>
      <img class="logo logo--day" src="/assets/logo-mhart.png" alt="' . e($cfg['shopName']) . '" width="105" height="50">
      <img class="logo logo--uv" src="/assets/logo-mhart-light.png" alt="" width="105" height="50" aria-hidden="true">
      <p class="muted">' . e($cfg['artistName']) . ' · ' . e($cfg['tagline']) . '<br>Atelier im Raum Leipzig</p>
    </div>
    <div>
      <p class="eyebrow">Kontakt</p>
      <p><a href="mailto:' . e($cfg['contactEmail']) . '">' . e($cfg['contactEmail']) . '</a><br>
      ' . implode(' · ', array_filter([
          $cfg['instagram'] !== '' ? '<a href="' . e($cfg['instagram']) . '" rel="noopener" target="_blank">Instagram</a>' : '',
          $cfg['facebook'] !== '' ? '<a href="' . e($cfg['facebook']) . '" rel="noopener" target="_blank">Facebook</a>' : '',
      ])) . ($cfg['instagram'] !== '' || $cfg['facebook'] !== '' ? '<br>' : '') . '
      <a href="' . e($cfg['mainSite']) . '" rel="noopener" target="_blank">novaspes.de</a></p>
    </div>
    <div>
      <p class="eyebrow">Rechtliches</p>
      <p><a href="/impressum">Impressum</a><br><a href="/agb">AGB</a><br><a href="/widerruf">Widerrufsbelehrung</a><br><a href="/versand">Versand &amp; Zahlung</a><br><a href="/datenschutz">Datenschutz</a>' . (self::t($cfg['googleTagId']) ? '<br><button type="button" class="linklike" data-consent-open>Cookie-Einstellungen</button>' : '') . '</p>
    </div>
  </div>
  <p class="withdraw-cta"><a class="btn-withdraw" href="/vertrag-widerrufen">Vertrag widerrufen</a><span>Online gekauft? Hier können Sie innerhalb der Widerrufsfrist widerrufen.</span></p>
  <p class="fine">' . ($cfg['kleinunternehmer'] ? 'Alle Preise sind Endpreise. Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.' : 'Alle Preise inkl. gesetzlicher Umsatzsteuer.') . ' Zzgl. <a href="/versand">Versandkosten</a>. Sichere Zahlung über Stripe. Raumansichten sind Visualisierungen.</p>
  ' . (self::t($cfg['creditUrl']) ? '<p class="credit">Realisiert mit Unterstützung von <a href="' . e($cfg['creditUrl']) . '" target="_blank" rel="noopener">' . e($cfg['creditLabel']) . '</a></p>' : '') . '
</footer>
' . self::inquiryDialog($cfg) . '
<p class="toast" role="status" aria-live="polite" hidden></p>
' . self::consentBanner($cfg) . '
<script src="/assets/shop.js?v=' . self::ASSET_V . '" defer></script>
</body>
</html>';
    }

    private static function consentBanner(array $cfg): string
    {
        if (!self::t($cfg['googleTagId'])) {
            return '';
        }
        return '<div class="consent" hidden data-tag="' . e($cfg['googleTagId']) . '">
  <p>Ich nutze Google-Dienste, um zu messen, ob meine Anzeigen funktionieren. Das passiert nur mit Ihrer Einwilligung. <a href="/datenschutz">Mehr erfahren</a></p>
  <div class="consent-actions"><button type="button" class="btn btn--ghost" data-consent="deny">Ablehnen</button><button type="button" class="btn" data-consent="grant">Einverstanden</button></div>
</div>';
    }

    private static function priceLabel(array $w): string
    {
        return self::t($w['priceCents'] ?? null) ? Works::euro($w['priceCents']) : 'Preis auf Anfrage';
    }

    private static function statusBadge(array $w): string
    {
        if ($w['status'] === 'sold') {
            return '<span class="badge badge--sold">Verkauft</span>';
        }
        if ($w['status'] === 'reserved') {
            return '<span class="badge badge--reserved">Reserviert</span>';
        }
        return '';
    }

    // Bild so in die 4:5-"Wand" einpassen, dass es nie übersteht (Breite in % der Karte).
    private static function fitPct(array $w): int|float
    {
        if (!self::t($w['w'] ?? null) || !self::t($w['h'] ?? null)) {
            return 70;
        }
        $r = $w['w'] / $w['h'];
        return $r >= 0.8 ? 78 : 78 * ($r / 0.8);
    }

    // Tageslicht / Schwarzlicht als Vergleichs-Regler (Hero, Schwarzlicht-Sektion, Werkseite)
    private static function uvCompare(?array $w, array $o = []): string
    {
        $sizes = $o['sizes'] ?? '(max-width: 860px) 92vw, 46vw';
        $priority = $o['priority'] ?? false;
        $label = $o['label'] ?? true;
        if (!self::t($w['uvImage'] ?? null)) {
            return '';
        }
        return '<div class="cmp" data-cmp style="--pos:50%">
    ' . self::pic($w['image'], 'class="cmp-day" alt="' . e($w['title']) . ' bei Tageslicht" ' . (self::t($w['w'] ?? null) ? 'width="' . self::str($w['w']) . '" height="' . self::str($w['h'] ?? null) . '"' : '') . ' ' . ($priority ? 'fetchpriority="high"' : 'loading="lazy"'), $sizes) . '
    <div class="cmp-uv">' . self::pic($w['uvImage'], 'alt="' . e($w['title']) . ' unter Schwarzlicht" ' . ($priority ? 'fetchpriority="low"' : 'loading="lazy"'), $sizes) . '</div>
    <span class="cmp-line" aria-hidden="true"><span class="cmp-knob">↔</span></span>
    ' . ($label ? '<span class="cmp-tag cmp-tag--day">Tageslicht</span><span class="cmp-tag cmp-tag--uv">Schwarzlicht</span>' : '') . '
    <input class="cmp-range" type="range" min="0" max="100" value="50" aria-label="Regler: links Tageslicht, rechts Schwarzlicht">
  </div>';
    }

    private static function priceCat(array $w): string
    {
        if (!self::t($w['priceCents'] ?? null)) {
            return 'anfrage';
        }
        $eur = $w['priceCents'] / 100;
        return $eur < 1000 ? 'bis1000' : ($eur <= 2500 ? 'bis2500' : 'ab2500');
    }

    // Anfrage-Dialog (ein Formular für Preisanfrage, Frage, Warteliste, allgemeine Anfrage).
    // Ohne JavaScript greift der normale mailto-Link.
    private static function inquiryDialog(array $cfg): string
    {
        return '<dialog class="inquiry" data-inquiry-dialog aria-labelledby="inq-h">
  <form method="post" action="/anfrage" name="anfrage" data-inquiry-form>
    <input type="hidden" name="form-name" value="anfrage">
    <input type="hidden" name="anliegen" value="allgemein">
    <input type="hidden" name="werk" value="">
    <input type="hidden" name="seite" value="">
    <p class="visually-hidden"><label>Nicht ausfüllen: <input name="bot-field" tabindex="-1" autocomplete="off"></label></p>
    <button type="button" class="inq-close" data-inquiry-close aria-label="Schließen">×</button>
    <p class="eyebrow" data-inq-eyebrow>Anfrage</p>
    <h2 id="inq-h" class="display" data-inq-title>Schreiben Sie mir</h2>
    <p class="inq-sub" data-inq-sub>Ich antworte in der Regel innerhalb eines Tages.</p>
    <div class="inq-grid">
      <label class="field"><span>Name</span><input name="name" required autocomplete="name"></label>
      <label class="field"><span>E-Mail</span><input name="email" type="email" required autocomplete="email"></label>
      <label class="field field--wide"><span>Telefon <em>(optional)</em></span><input name="telefon" type="tel" autocomplete="tel"></label>
      <label class="field field--wide"><span>Nachricht</span><textarea name="nachricht" rows="5" required></textarea></label>
    </div>
    <label class="check"><input type="checkbox" name="datenschutz" value="ja" required><span>Ich bin einverstanden, dass meine Angaben zur Beantwortung der Anfrage verarbeitet werden (<a href="/datenschutz" target="_blank">Datenschutz</a>).</span></label>
    <button class="btn btn--wide" type="submit">Anfrage senden <span aria-hidden="true">→</span></button>
    <p class="inq-alt">Lieber per E-Mail? <a href="mailto:' . e($cfg['contactEmail']) . '">' . e($cfg['contactEmail']) . '</a></p>
    <p class="inq-error" hidden data-inq-error>Das hat leider nicht geklappt. Bitte versuchen Sie es noch einmal oder schreiben Sie direkt an ' . e($cfg['contactEmail']) . '.</p>
  </form>
  <div class="inq-done" hidden data-inq-done>
    <p class="eyebrow">Gesendet</p>
    <h2 class="display">Danke für Ihre <em class="accent">Nachricht.</em></h2>
    <p>Ich melde mich so bald wie möglich bei Ihnen.</p>
    <button type="button" class="btn btn--ghost" data-inquiry-close>Schließen</button>
  </div>
</dialog>';
    }

    private static function card(array $w, int $i): string
    {
        $pct = self::toFixed(self::fitPct($w), 1);
        $award = self::t($w['awards'] ?? null) && count($w['awards']) ? '<span class="tag tag--award">Ausgezeichnet</span>' : '';
        $hasUv = self::t($w['uvImage'] ?? null);
        $uvTag = $hasUv ? '<span class="tag tag--uv" title="Mit Aufnahme unter Schwarzlicht">UV</span>' : '';
        $sz = '(max-width: 560px) 44vw, (max-width: 1020px) 30vw, 250px';
        return '<article class="card' . ($hasUv ? ' has-uv' : '') . '" data-format="' . e($w['format']) . '" data-status="' . e($w['status']) . '" data-price="' . self::str($w['priceCents'] ?? '') . '" data-series="' . e($w['series']) . '" data-uv="' . ($hasUv ? 'ja' : 'nein') . '" data-slug="' . e($w['slug']) . '" data-pricecat="' . self::priceCat($w) . '">
  <a href="/werk/' . e($w['slug']) . '" class="card-link">
    <div class="card-img">
      <span class="art" style="width:' . $pct . '%">
        ' . self::pic($w['thumb'], 'class="art-day" alt="' . e($w['title']) . ' – ' . e($w['technique']) . ', ' . e($w['size']) . '" loading="' . ($i < 4 ? 'eager' : 'lazy') . '" decoding="async" ' . (self::t($w['w'] ?? null) ? 'width="' . self::str($w['w']) . '" height="' . self::str($w['h'] ?? null) . '"' : ''), $sz) . '
        ' . ($hasUv ? self::pic(self::sized($w['uvImage'], '-sm'), 'class="art-uv" alt="" aria-hidden="true" loading="lazy" decoding="async"', $sz) : '') . '
      </span>
      ' . self::statusBadge($w) . '
      <span class="tags">' . $uvTag . $award . '</span>
    </div>
    <div class="card-meta">
      <h3 class="card-title">' . e($w['title']) . (self::t($w['year'] ?? null) ? ' <span class="year">' . self::str($w['year']) . '</span>' : '') . '</h3>
      <p class="card-sub">' . e($w['size']) . '</p>
      <p class="card-price">' . ($w['status'] === 'sold' ? 'Privatsammlung' : e(self::priceLabel($w))) . '</p>
    </div>
  </a>
  <span class="card-fav"><button type="button" class="fav-btn" data-fav="' . e($w['slug']) . '" aria-pressed="false" aria-label="' . e($w['title']) . ' merken" title="Merken">♡</button></span>
</article>';
    }

    // Akzentwort: Serif kursiv mit gesetztem Neon-Farbstrich (Motiv aus seinen Bildern)
    private static function strich(string $word, string $color = 'yellow'): string
    {
        return '<em class="accent strich strich--' . $color . '">' . $word . '</em>';
    }

    private static function idx(string $n, string $label): string
    {
        return '<p class="eyebrow eyebrow--idx"><span class="idx">' . $n . '</span>' . $label . '</p>';
    }

    // ---------------------------------------------------------------- Startseite

    public static function home(array $works): string
    {
        $cfg = cfg();
        $works = array_values($works);
        $available = array_values(array_filter($works, fn ($w) => $w['status'] !== 'sold'));
        $uvWorks = array_values(array_filter($works, fn ($w) => self::t($w['uvImage'] ?? null) && $w['status'] !== 'sold'));
        $hero = null;
        foreach ($uvWorks as $w) {
            if ($w['slug'] === 'nacht') {
                $hero = $w;
                break;
            }
        }
        $hero ??= $uvWorks[0] ?? $available[0] ?? $works[0] ?? null;
        $series = [];
        foreach ($works as $w) {
            $s = $w['series'] ?? null;
            if (self::t($s) && !in_array($s, $series, true)) {
                $series[] = $s;
            }
        }
        $formats = array_values(array_filter(['klein', 'mittel', 'gross'], function ($f) use ($works) {
            foreach ($works as $w) {
                if ($w['format'] === $f) {
                    return true;
                }
            }
            return false;
        }));
        $today = gmdate('Y-m-d');
        $when = fn ($ex) => self::t($ex['end'] ?? null) ? $ex['end'] : $ex['start'];
        $upcoming = array_values(array_filter(Artist::EXHIBITIONS, fn ($ex) => strcmp($when($ex), $today) >= 0));
        $past = array_slice(array_values(array_filter(Artist::EXHIBITIONS, fn ($ex) => strcmp($when($ex), $today) < 0)), 0, 8);
        $bySlug = [];
        foreach ($works as $w) {
            $bySlug[$w['slug']] = $w;
        }
        $workLink = fn ($slug, $text) => isset($bySlug[$slug]) ? '<a href="/werk/' . e($slug) . '">' . e($text) . '</a>' : e($text);
        $featuredUv = array_slice(array_values(array_filter($uvWorks, fn ($w) => $w['slug'] !== ($hero['slug'] ?? null))), 0, 8);

        $heroArt = '';
        if (self::t($hero['uvImage'] ?? null)) {
            $heroArt = self::uvCompare($hero, ['priority' => true, 'sizes' => '(max-width: 860px) 92vw, 44vw']);
        } elseif ($hero) {
            $heroArt = self::pic($hero['image'], 'alt="' . e($hero['title']) . '" fetchpriority="high"', '(max-width: 860px) 92vw, 44vw');
        }
        $heroCap = $hero ? '<figcaption><a href="/werk/' . e($hero['slug']) . '">' . e($hero['title']) . (self::t($hero['year'] ?? null) ? ', ' . self::str($hero['year']) : '') . '</a> · ' . e($hero['technique']) . ' · ' . e($hero['size']) . (self::t($hero['uvImage'] ?? null) ? '<span class="hint-drag">Regler ziehen: Tageslicht ↔ Schwarzlicht</span>' : '') . '</figcaption>' : '';

        $tickerOnce = implode('<span class="dot">●</span>', array_map(fn ($t) => '<span>' . e($t) . '</span>', Artist::ARTIST['ticker'])) . '<span class="dot">●</span>';
        $ticker = $tickerOnce . $tickerOnce;

        $blacklight = '';
        if (count($featuredUv)) {
            $thumbs = '';
            $i = 0;
            foreach (array_values(array_filter([$hero, ...$featuredUv], fn ($w) => self::t($w))) as $w) {
                $data = self::json([
                    'slug' => $w['slug'],
                    'title' => $w['title'],
                    'year' => $w['year'] ?? null,
                    'day' => self::sized($w['image'], '-sm'),
                    'uv' => self::sized($w['uvImage'], '-sm'),
                    'dayW' => self::sized($w['image']),
                    'uvW' => self::sized($w['uvImage']),
                    'price' => $w['status'] === 'sold' ? 'Verkauft' : self::priceLabel($w),
                ]);
                $thumbs .= '<button type="button" class="bl-thumb' . ($i === 0 ? ' is-active' : '') . '" data-bl=\'' . e($data) . '\' aria-label="' . e($w['title']) . ' im Vergleich zeigen">' . self::pic(self::sized($w['uvImage'], '-xs'), 'alt="" loading="lazy"') . '</button>';
                $i++;
            }
            $blacklight = '<section class="blacklight" id="schwarzlicht" aria-labelledby="sl-h" data-blacklight>
  <div class="bl-inner">
    <div class="bl-copy">
      ' . self::idx('02', 'Schwarzlicht') . '
      <h2 id="sl-h" class="display">Erst im Dunkeln ' . self::strich('vollständig.', 'magenta') . '</h2>
      <p>' . e($cfg['artistName']) . ' malt mit Farben, die auf UV-Licht reagieren. Unter Schwarzlicht verschwindet das Tageslicht-Bild, und eine zweite Ebene tritt hervor. Wählen Sie ein Werk und ziehen Sie den Regler.</p>
      <div class="bl-thumbs">
        ' . $thumbs . '
      </div>
      <p class="bl-meta" data-bl-meta>' . ($hero ? '<a href="/werk/' . e($hero['slug']) . '">' . e($hero['title']) . '</a> · ' . e($hero['status'] === 'sold' ? 'Verkauft' : self::priceLabel($hero)) : '') . '</p>
    </div>
    <div class="bl-stage">' . ($hero ? self::uvCompare($hero, ['sizes' => '(max-width: 860px) 92vw, 50vw']) : '') . '</div>
  </div>
</section>';
        }

        $upcomingHtml = '';
        if (count($upcoming)) {
            $upcomingHtml = '<h3 class="record-h">Demnächst</h3><ul class="timeline timeline--next">' . implode('', array_map(fn ($ex) => '<li><span class="when">' . e($ex['when']) . '</span><span class="what"><strong>' . e($ex['title']) . '</strong>' . e($ex['place']) . (isset($ex['works']) ? ' · ' . implode(', ', array_map(fn ($x) => $workLink($x['slug'], $x['title']), $ex['works'])) : '') . '</span></li>', $upcoming)) . '</ul>';
        }
        $pastHtml = implode('', array_map(fn ($ex) => '<li><span class="when">' . e($ex['when']) . '</span><span class="what"><strong>' . e($ex['title']) . '</strong>' . e($ex['place']) . '</span></li>', $past));
        $awardsHtml = implode('', array_map(fn ($a) => '<li><span class="award-org">' . e($a['org']) . '</span><strong>' . e($a['title']) . '</strong>' . (!empty($a['works']) ? '<span class="award-works">' . implode(', ', array_map(fn ($x) => $workLink($x['slug'], $x['title']), $a['works'])) . '</span>' : '') . '</li>', Artist::AWARDS));

        $cards = [];
        foreach ($works as $i => $w) {
            $cards[] = self::card($w, $i);
        }

        $body = '
<section class="hero">
  <div class="hero-copy">
    <p class="eyebrow eyebrow--idx"><span class="idx">MH</span>' . e($cfg['tagline']) . '</p>
    <h1 class="display hero-title"><span class="line">Schwarz-Weiß</span> <span class="line">ist das ' . self::strich('Fundament.', 'yellow') . '</span> <span class="line">Neon ist der</span> <span class="line">' . self::strich('Kontrast.', 'magenta') . '</span></h1>
    <p class="lead">Figurative Malerei von ' . e($cfg['artistName']) . ', entstanden in der Nacht. Viele Werke tragen UV-reaktive Farben und beginnen unter Schwarzlicht ein zweites Mal zu leuchten.</p>
    <div class="hero-actions"><a class="btn" href="#werke">' . count($available) . ' Originale ansehen</a><a class="btn btn--ghost" href="#schwarzlicht">Im Schwarzlicht sehen</a></div>
    <ul class="hero-facts">
      <li><strong>Unikate</strong>signiert, mit Echtheitszertifikat</li>
      <li><strong>Ausgezeichnet</strong>u. a. New York, Luxemburg</li>
      <li><strong>Ausgestellt</strong>Paris · Rom · Madrid · Málaga</li>
    </ul>
  </div>
  <figure class="hero-art">
    ' . $heroArt . '
    ' . $heroCap . '
  </figure>
</section>

<div class="ticker" aria-hidden="true"><div class="ticker-track">' . $ticker . '</div></div>

<section class="works" id="werke" aria-labelledby="werke-h">
  <div class="section-head">
    <div>
      ' . self::idx('01', 'Shop') . '
      <h2 id="werke-h" class="display">Verfügbare ' . self::strich('Originale', 'cyan') . '</h2>
    </div>
    <div class="filters" role="group" aria-label="Werke filtern">
      <button type="button" class="chip is-active" data-filter="all">Alle</button>
      <button type="button" class="chip" data-filter="status:available">Verfügbar</button>
      <button type="button" class="chip chip--uv" data-filter="uv:ja">Mit UV-Aufnahme</button>
      ' . implode('', array_map(fn ($f) => '<button type="button" class="chip" data-filter="format:' . $f . '">' . e(explode(' (', Config::FORMAT_LABELS[$f])[0]) . '</button>', $formats)) . '
      ' . implode('', array_map(fn ($s) => '<button type="button" class="chip" data-filter="series:' . e($s) . '">Serie ' . e($s) . '</button>', $series)) . '
      <button type="button" class="chip" data-filter="pricecat:bis1000">bis 1.000 €</button>
      <button type="button" class="chip" data-filter="pricecat:bis2500">1.000–2.500 €</button>
      <button type="button" class="chip" data-filter="pricecat:ab2500">ab 2.500 €</button>
      <button type="button" class="chip chip--fav" data-filter="merkliste" hidden data-fav-chip>♡ Merkliste</button>
      <label class="sort"><span class="visually-hidden">Sortieren</span>
        <select data-sort>
          <option value="default">Empfohlen</option>
          <option value="price-asc">Preis aufsteigend</option>
          <option value="price-desc">Preis absteigend</option>
        </select>
      </label>
    </div>
  </div>
  <div class="grid" data-grid>
    ' . implode("\n", $cards) . '
  </div>
  <p class="empty" hidden data-empty>Für diese Auswahl ist gerade kein Werk da. <button type="button" class="linklike" data-filter="all">Alle Werke zeigen</button></p>
</section>

' . $blacklight . '

<section class="artist" id="kuenstler" aria-labelledby="kuenstler-h">
  <figure class="artist-img">
    ' . self::pic('/img/artist-night.jpg', 'alt="Porträt ' . e($cfg['artistName']) . ' in Schwarz-Weiß" loading="lazy" width="1400" height="1802"') . '
  </figure>
  <div class="artist-copy">
    ' . self::idx('03', 'Der Künstler') . '
    <h2 id="kuenstler-h" class="display">Matthias ' . self::strich('Hübner', 'yellow') . '</h2>
    ' . implode('', array_map(fn ($p) => '<p>' . e($p) . '</p>', Artist::ARTIST['bio'])) . '
    <ol class="principles">
      ' . implode('', array_map(fn ($p) => '<li><strong>' . e($p['title']) . '</strong><span>' . e($p['text']) . '</span></li>', Artist::ARTIST['principles'])) . '
    </ol>
    <p><a class="text-link" href="' . e($cfg['mainSite']) . '/mhbio" rel="noopener" target="_blank">Vollständige Biografie auf novaspes.de</a></p>
  </div>
</section>

<section class="record" aria-labelledby="record-h">
  <div class="record-head">
    ' . self::idx('04', 'Werdegang') . '
    <h2 id="record-h" class="display">Ausgestellt in Europa, ' . self::strich('ausgezeichnet', 'cyan') . ' in New York.</h2>
  </div>
  <div class="record-col">
    <p class="eyebrow">Ausstellungen</p>
    ' . $upcomingHtml . '
    <h3 class="record-h">Zuletzt</h3>
    <ul class="timeline">' . $pastHtml . '</ul>
  </div>
  <div class="record-col">
    <p class="eyebrow">Auszeichnungen</p>
    <ul class="awards">' . $awardsHtml . '</ul>
  </div>
</section>

<section class="buy" id="kaufen" aria-labelledby="kaufen-h">
  ' . self::idx('05', 'Kauf') . '
  <h2 id="kaufen-h" class="display">So kommt ein Original ' . self::strich('zu Ihnen.', 'cyan') . '</h2>
  <ol class="steps">
    <li><span class="num">01</span><h3>Werk wählen</h3><p>Jedes Bild gibt es genau einmal. Im Bezahlvorgang ist es ' . self::str($cfg['reserveMinutes']) . ' Minuten für Sie reserviert.</p></li>
    <li><span class="num">02</span><h3>Sicher bezahlen</h3><p>Verschlüsselt über Stripe: Karte, Apple Pay, Google Pay und weitere Verfahren.</p></li>
    <li><span class="num">03</span><h3>Persönlich verpackt</h3><p>Versichert unterwegs in ' . e($cfg['deliveryDays']) . ' Werktagen, oder Sie holen es im Atelier ab.</p></li>
    <li><span class="num">04</span><h3>Mit Zertifikat</h3><p>Signiertes Unikat mit Certificate of Authenticity. 14 Tage Widerrufsrecht.</p></li>
  </ol>
</section>

<section class="contact-cta">
  ' . self::idx('06', 'Anfragen') . '
  <h2 class="display">Ein Werk im Kopf, das hier ' . self::strich('nicht steht?', 'magenta') . '</h2>
  <p>Auftragsarbeiten, limitierte Editionen oder ein Besuch im Atelier: Schreiben Sie einfach.</p>
  <a class="btn" href="mailto:' . e($cfg['contactEmail']) . '?subject=' . self::enc('Anfrage über den Shop') . '" data-inquiry="allgemein">Anfrage schreiben <span aria-hidden="true">→</span></a>
  <p class="cta-mail">oder direkt an <a href="mailto:' . e($cfg['contactEmail']) . '">' . e($cfg['contactEmail']) . '</a></p>
</section>';

        $items = [];
        foreach (array_slice($works, 0, 40) as $i => $w) {
            $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'url' => $cfg['siteUrl'] . '/werk/' . self::str($w['slug']), 'name' => $w['title']];
        }
        $jsonLd = [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'itemListElement' => $items,
        ];
        $person = [
            '@context' => 'https://schema.org',
            '@type' => 'Person',
            'name' => $cfg['artistName'],
            'jobTitle' => 'Bildender Künstler',
            'url' => $cfg['siteUrl'],
            'sameAs' => array_values(array_filter([$cfg['mainSite'], $cfg['instagram'], $cfg['facebook']], fn ($v) => self::t($v))),
        ];
        $heroPre = '';
        if ($hero && self::isLocalImg($hero['image'])) {
            $st = preg_replace('/\.jpe?g$/iD', '', $hero['image'], 1);
            $heroPre = $st . '-xs.webp 420w, ' . $st . '-sm.webp 720w, ' . $st . '.webp 1600w';
        }
        return self::layout(['title' => '', 'body' => $body, 'canonical' => '/', 'ogImage' => $hero['image'] ?? '', 'jsonLd' => [$jsonLd, $person], 'bodyClass' => 'page-home', 'preload' => $heroPre, 'preloadSizes' => '(max-width: 860px) 92vw, 44vw']);
    }

    // ---------------------------------------------------------------- Werkseite

    /**
     * Maße "H x B (x T) cm" lesen; Ausrichtung zur Sicherheit am Foto prüfen.
     * @return array{h:float,b:float}|null
     */
    public static function dimsOf(array $work): ?array
    {
        $size = $work['size'] ?? '';
        preg_match_all('/\d+(?:[.,]\d+)?/', self::t($size) ? self::str($size) : '', $m);
        $nums = array_map(fn ($n) => (float) str_replace(',', '.', $n), array_slice($m[0], 0, 2));
        if (count($nums) < 2) {
            return null;
        }
        foreach ($nums as $n) {
            if (!$n || $n > 600) {
                return null;
            }
        }
        [$h, $b] = $nums;
        if (self::t($work['w'] ?? null) && self::t($work['h'] ?? null)) {
            $portrait = $work['h'] >= $work['w'];
            if (($portrait && $b > $h) || (!$portrait && $h > $b)) {
                [$h, $b] = [$b, $h];
            }
        }
        return ['h' => $h, 'b' => $b];
    }

    // Größenvergleich: Bild in echter Größe neben einer 1,75 m großen Person, Bildmitte auf 1,55 m.
    private static function sizeCompare(array $work): string
    {
        $d = self::dimsOf($work);
        if (!$d) {
            return '';
        }
        $s = [self::class, 'str'];
        $fmt = fn ($n) => preg_replace('/\./', ',', self::str($n), 1);
        $CENTER = 155; // Bildmitte über dem Boden (Galerie-Standard)
        $bottom = $CENTER - $d['h'] / 2;
        $sofa = $bottom >= 100; // Sofa nur, wenn das Bild darüber Platz hat
        $SOFA_W = 200;
        $span = max($d['b'], $sofa ? $SOFA_W : 0);
        $P = 12; // Person links
        $cx = 85 + $span / 2;
        $W = $cx + $span / 2 + 40;
        $floor = max(220, ceil($CENTER + $d['h'] / 2 + 22));
        $top = $floor - $CENTER - $d['h'] / 2;
        $x = $cx - $d['b'] / 2;
        $person = '<g class="sz-person" transform="translate(' . $s($P) . ',' . $s($floor - 175) . ')">
      <circle cx="17.5" cy="12" r="11"/>
      <path d="M3 31 Q3 25 9 25 H26 Q32 25 32 31 V86 Q32 91 27 91 V171 Q27 175 23 175 H19.5 V97 H15.5 V175 H12 Q8 175 8 171 V91 Q3 91 3 86 Z"/>
    </g>';
        $sofaSvg = $sofa
            ? '<g class="sz-sofa" transform="translate(' . $s($cx - $SOFA_W / 2) . ',' . $s($floor - 80) . ')">
        <rect x="8" y="0" width="' . $s($SOFA_W - 16) . '" height="48" rx="6"/>
        <rect x="0" y="30" width="' . $s($SOFA_W) . '" height="38" rx="7"/>
        <rect x="14" y="68" width="6" height="12"/><rect x="' . $s($SOFA_W - 20) . '" y="68" width="6" height="12"/>
      </g>'
            : '';
        return '<figure class="sizecmp">
    <svg viewBox="0 0 ' . $s($W) . ' ' . $s($floor + 6) . '" role="img" aria-label="Größenvergleich: ' . $fmt($d['h']) . ' × ' . $fmt($d['b']) . ' cm neben einer 1,75 m großen Person" preserveAspectRatio="xMidYMax meet">
      <line class="sz-floor" x1="0" y1="' . $s($floor) . '" x2="' . $s($W) . '" y2="' . $s($floor) . '"/>
      ' . $person . '
      ' . $sofaSvg . '
      <rect class="sz-frame" x="' . $s($x) . '" y="' . $s($top) . '" width="' . $s($d['b']) . '" height="' . $s($d['h']) . '"/>
      <image href="' . e(self::sized($work['thumb'], '-sm')) . '" x="' . $s($x) . '" y="' . $s($top) . '" width="' . $s($d['b']) . '" height="' . $s($d['h']) . '" preserveAspectRatio="xMidYMid slice"/>
      <text class="sz-label" x="' . $s($cx) . '" y="' . $s($top - 7) . '" text-anchor="middle">' . $fmt($d['b']) . ' cm</text>
      <text class="sz-label" x="' . $s($x + $d['b'] + 7) . '" y="' . $s($top + $d['h'] / 2) . '" dominant-baseline="middle">' . $fmt($d['h']) . ' cm</text>
      <text class="sz-label sz-muted" x="' . $s($P + 17.5) . '" y="' . $s($floor - 183) . '" text-anchor="middle">1,75 m</text>
    </svg>
    <figcaption>Größenvergleich: das Werk in echter Größe an der Wand' . ($sofa ? ' über einem 2 m breiten Sofa' : '') . ', daneben eine Person von 1,75 m.</figcaption>
  </figure>';
    }

    public static function work(array $work, array $works): string
    {
        $cfg = cfg();
        $works = array_values($works);
        $ship = Works::shippingFor($work, $cfg);
        $others = array_slice(array_values(array_filter($works, fn ($w) => $w['slug'] !== $work['slug'] && $w['status'] !== 'sold')), 0, 10);
        $idx = -1;
        foreach ($works as $i => $w) {
            if ($w['slug'] === $work['slug']) {
                $idx = $i;
                break;
            }
        }
        $prev = $works[$idx - 1] ?? null;
        $next = $works[$idx + 1] ?? null;
        $hasYear = self::t($work['year'] ?? null);
        $hasUv = self::t($work['uvImage'] ?? null);
        $hasAwards = !empty($work['awards']);
        $mailSubject = self::enc('Anfrage: ' . self::str($work['title']) . ($hasYear ? ' (' . self::str($work['year']) . ')' : ''));
        $inq = 'data-work="' . e($work['title']) . '" data-slug="' . e($work['slug']) . '"';
        $mailBody = self::enc("Guten Tag Herr Hübner,\n\nich interessiere mich für das Werk „" . self::str($work['title']) . '“ (' . self::str($work['size']) . ").\n" . $cfg['siteUrl'] . '/werk/' . self::str($work['slug']) . "\n\nViele Grüße\n");

        if ($work['status'] === 'sold') {
            $action = '<p class="state state--sold">Dieses Werk befindet sich in einer Privatsammlung.</p>
      <a class="btn btn--ghost btn--wide" href="mailto:' . e($cfg['contactEmail']) . '?subject=' . $mailSubject . '" data-inquiry="aehnlich" ' . $inq . '>Ähnliches Werk anfragen</a>';
        } elseif ($work['status'] === 'reserved') {
            $action = '<p class="state state--reserved">Gerade reserviert. Ein anderer Interessent ist im Bezahlvorgang. Schauen Sie in einer halben Stunde noch einmal vorbei.</p>
      <a class="btn btn--ghost btn--wide" href="mailto:' . e($cfg['contactEmail']) . '?subject=' . $mailSubject . '&body=' . $mailBody . '" data-inquiry="warteliste" ' . $inq . '>Auf die Warteliste</a>';
        } elseif (!self::t($work['priceCents'] ?? null)) {
            $action = '<a class="btn btn--wide" href="mailto:' . e($cfg['contactEmail']) . '?subject=' . $mailSubject . '&body=' . $mailBody . '" data-inquiry="preis" ' . $inq . '>Preis anfragen</a>
      <p class="hint">Antwort in der Regel innerhalb eines Tages.</p>';
        } else {
            $action = '<form class="buy-form" method="post" action="/api/checkout" data-checkout>
        <input type="hidden" name="slug" value="' . e($work['slug']) . '">
        <button class="btn btn--wide btn--buy" type="submit">Original kaufen <span aria-hidden="true">→</span></button>
      </form>
      <p class="hint">Weiter zur sicheren Bezahlung bei Stripe. Das Werk ist dort ' . self::str($cfg['reserveMinutes']) . ' Minuten für Sie reserviert.</p>
      <a class="text-link" href="mailto:' . e($cfg['contactEmail']) . '?subject=' . $mailSubject . '&body=' . $mailBody . '" data-inquiry="frage" ' . $inq . '>Lieber erst eine Frage stellen</a>';
        }

        // Ansichten: Tageslicht, Schwarzlicht, Vergleich, weitere Fotos
        $gallery = $work['gallery'] ?? [];
        $views = [['id' => 'day', 'label' => 'Tageslicht', 'thumb' => $work['thumb']]];
        if ($hasUv) {
            $views[] = ['id' => 'uv', 'label' => 'Schwarzlicht', 'thumb' => $work['uvImage']];
            $views[] = ['id' => 'cmp', 'label' => 'Vergleich', 'thumb' => $work['uvImage'], 'cmp' => true];
        }
        foreach ($gallery as $i => $g) {
            $views[] = ['id' => 'g' . $i, 'label' => self::KIND_LABEL[$g['kind']] ?? 'Ansicht', 'thumb' => $g['src'], 'src' => $g['src'], 'kind' => $g['kind']];
        }
        $sizes = '(max-width: 1020px) 92vw, 60vw';

        $galleryHtml = '';
        foreach (array_values($gallery) as $i => $g) {
            $kl = self::KIND_LABEL[$g['kind']] ?? 'Ansicht';
            $galleryHtml .= '<button type="button" class="zoom view" data-view="g' . $i . '" data-zoom="' . e(self::sized($g['src'])) . '" aria-label="' . e($kl) . ' vergrößern" hidden>' . self::pic($g['src'], 'alt="' . e($work['title']) . ' – ' . e($kl) . ($g['kind'] === 'raum' ? ' (Visualisierung)' : '') . '" loading="lazy"', $sizes) . ($g['kind'] === 'raum' ? '<span class="viz-note">Visualisierung</span>' : '') . '</button>';
        }
        $viewsHtml = '';
        if (count($views) > 1) {
            $btns = '';
            foreach ($views as $i => $v) {
                $btns .= '<button type="button" role="tab" class="view-btn' . ($i === 0 ? ' is-active' : '') . ($v['id'] === 'uv' || $v['id'] === 'cmp' ? ' view-btn--uv' : '') . '" data-show="' . $v['id'] . '" aria-selected="' . ($i === 0 ? 'true' : 'false') . '">' . self::pic(self::sized($v['thumb'], '-xs'), 'alt="" loading="lazy"') . '<span>' . e($v['label']) . '</span></button>';
            }
            $viewsHtml = '<div class="views" role="tablist" aria-label="Ansichten">' . $btns . '</div>';
        }

        $stage = '<div class="stage" data-stage>
      <button type="button" class="zoom view is-active" data-view="day" data-zoom="' . e(self::sized($work['image'])) . '" aria-label="Bild vergrößern">
        ' . self::pic($work['image'], 'alt="' . e($work['title']) . ' von ' . e($cfg['artistName']) . ' – ' . e($work['technique']) . ', ' . e($work['size']) . '" ' . (self::t($work['w'] ?? null) && self::t($work['h'] ?? null) ? 'width="' . self::str($work['w']) . '" height="' . self::str($work['h']) . '"' : '') . ' fetchpriority="high"', $sizes) . '
      </button>
      ' . ($hasUv ? '<button type="button" class="zoom view" data-view="uv" data-zoom="' . e(self::sized($work['uvImage'])) . '" aria-label="Schwarzlicht-Aufnahme vergrößern" hidden>' . self::pic($work['uvImage'], 'alt="' . e($work['title']) . ' unter Schwarzlicht" loading="lazy"', $sizes) . '</button>
      <div class="view view--cmp" data-view="cmp" hidden>' . self::uvCompare($work, ['sizes' => $sizes]) . '</div>' : '') . '
      ' . $galleryHtml . '
    </div>
    ' . $viewsHtml;

        $othersHtml = '';
        if (count($others)) {
            $othersHtml = '<section class="more"><h2 class="display">Weitere Originale</h2><div class="strip">' . implode('', array_map(fn ($w) => '<a href="/werk/' . e($w['slug']) . '" class="strip-item"><span class="strip-img">' . self::pic($w['thumb'], 'alt="' . e($w['title']) . '" loading="lazy"', '210px') . '</span><span>' . e($w['title']) . '</span><span class="muted">' . e(self::priceLabel($w)) . '</span></a>', $others)) . '</div></section>';
        }

        $priceNote = '';
        if (self::t($work['priceCents'] ?? null) && $work['status'] !== 'sold') {
            $priceNote = '<p class="price-note">' . ($cfg['kleinunternehmer'] ? 'Endpreis, keine Umsatzsteuer gemäß § 19 UStG' : 'inkl. MwSt.') . ' · zzgl. ' . (self::t($ship) ? Works::euro((int) round($ship * 100)) : 'kostenlosem') . ' versichertem Versand' . ($cfg['pickupEnabled'] ? ' oder kostenlose Abholung' : '') . '</p>';
        }

        $body = '
<nav class="crumbs" aria-label="Brotkrumen"><a href="/">Shop</a> <span aria-hidden="true">/</span> <a href="/#werke">Werke</a> <span aria-hidden="true">/</span> <span>' . e($work['title']) . '</span></nav>
<div class="notice" hidden data-notice="abgebrochen">Bezahlung abgebrochen. Die Reservierung ist aufgehoben, das Werk ist wieder frei.</div>
<div class="notice" hidden data-notice="demo">Demo-Modus: Stripe ist noch nicht verbunden, deshalb startet hier noch keine echte Bezahlung.</div>
<div class="notice notice--warn" hidden data-notice="vergeben">Dieses Werk ist gerade reserviert oder schon verkauft. Bitte versuchen Sie es später noch einmal oder schreiben Sie mir.</div>
<div class="notice notice--warn" hidden data-notice="zuviele">Von Ihrem Anschluss wurden gerade mehrere Bezahlvorgänge gestartet. Bitte versuchen Sie es in einer halben Stunde noch einmal oder schreiben Sie mir eine E-Mail.</div>
<div class="notice notice--warn" hidden data-notice="fehler">Der Bezahlvorgang konnte nicht gestartet werden. Bitte versuchen Sie es noch einmal oder schreiben Sie mir eine E-Mail.</div>
<article class="work' . ($hasUv ? ' has-uv' : '') . '" data-work-slug="' . e($work['slug']) . '" data-work-status="' . e($work['status']) . '">
  <div class="work-media">
    ' . $stage . '
    <p class="media-note">Fotografien des Originals. Farben können je nach Bildschirm leicht abweichen.' . ($hasUv ? ' Die Schwarzlicht-Aufnahme zeigt die UV-reaktiven Farben im Dunkeln.' : '') . '</p>
    ' . self::sizeCompare($work) . '
  </div>
  <div class="work-info">
    <p class="eyebrow">' . e($cfg['artistName']) . (self::t($work['series'] ?? null) ? ' · Serie ' . e($work['series']) : '') . '</p>
    <h1 class="display work-title">' . e($work['title']) . ($hasYear ? ' <span class="year">' . self::str($work['year']) . '</span>' : '') . '</h1>
    ' . ($hasUv || $hasAwards ? '<p class="work-tags">' . ($hasUv ? '<span class="tag tag--uv">UV-reaktiv</span>' : '') . ($hasAwards ? '<span class="tag tag--award">Ausgezeichnet</span>' : '') . '</p>' : '') . '
    <dl class="facts">
      ' . (self::t($work['technique'] ?? null) ? '<div><dt>Technik</dt><dd>' . e($work['technique']) . '</dd></div>' : '') . '
      ' . (self::t($work['size'] ?? null) ? '<div><dt>Maße</dt><dd>' . e($work['size']) . '</dd></div>' : '') . '
      <div><dt>Ausführung</dt><dd>Signiertes Unikat mit Echtheitszertifikat (COA)</dd></div>
    </dl>
    <div class="buybox">
    <p class="price">' . ($work['status'] === 'sold' ? 'Verkauft' : e(self::priceLabel($work))) . '</p>
    ' . $priceNote . '
    <div class="actions">' . $action . '</div>
    </div>
    <div class="tools">
      <button type="button" class="tool" data-fav="' . e($work['slug']) . '" aria-pressed="false"><span class="tool-ico" aria-hidden="true">♡</span><span data-fav-label>Merken</span></button>
      <button type="button" class="tool" data-share data-title="' . e($work['title']) . ' – ' . e($cfg['artistName']) . '"><span class="tool-ico" aria-hidden="true">↗</span>Teilen</button>
    </div>
    <ul class="assurances">
      <li>Versicherter Versand in ' . e($cfg['deliveryDays']) . ' Werktagen, persönlich verpackt</li>
      <li>14 Tage Widerrufsrecht</li>
      <li>Zahlung verschlüsselt über Stripe</li>
    </ul>
    ' . (self::t($work['description'] ?? null) ? '<div class="desc"><h2>Über das Werk</h2><p>' . e($work['description']) . '</p></div>' : '') . '
    ' . ($hasAwards ? '<div class="honors"><h2>Auszeichnungen</h2><ul>' . implode('', array_map(fn ($a) => '<li>' . e($a) . '</li>', $work['awards'])) . '</ul></div>' : '') . '
    ' . (!empty($work['exhibitions']) ? '<div class="honors"><h2>Ausgestellt</h2><ul>' . implode('', array_map(fn ($a) => '<li>' . e($a) . '</li>', $work['exhibitions'])) . '</ul></div>' : '') . '
    <nav class="pager" aria-label="Weitere Werke blättern">
      ' . ($prev ? '<a href="/werk/' . e($prev['slug']) . '">← ' . e($prev['title']) . '</a>' : '<span></span>') . '
      ' . ($next ? '<a href="/werk/' . e($next['slug']) . '">' . e($next['title']) . ' →</a>' : '<span></span>') . '
    </nav>
  </div>
</article>
' . $othersHtml . '
<div class="lightbox" hidden data-lightbox role="dialog" aria-label="Bildansicht"><button type="button" class="lb-close" aria-label="Schließen">×</button><button type="button" class="lb-nav lb-prev" aria-label="Vorheriges Bild">‹</button><img alt=""><button type="button" class="lb-nav lb-next" aria-label="Nächstes Bild">›</button></div>';

        $jsonLd = [
            '@context' => 'https://schema.org',
            '@type' => ['Product', 'VisualArtwork'],
            'name' => $work['title'],
            'image' => array_merge([self::absUrl($cfg, $work['image'])], $hasUv ? [self::absUrl($cfg, $work['uvImage'])] : []),
            'description' => self::t($work['description'] ?? null) ? $work['description'] : self::str($work['technique']) . ', ' . self::str($work['size']),
            'artform' => 'Malerei',
            'artMedium' => $work['technique'],
            'creator' => ['@type' => 'Person', 'name' => $cfg['artistName']],
        ];
        if ($hasYear) {
            $jsonLd['dateCreated'] = self::str($work['year']);
        }
        if ($hasAwards) {
            $jsonLd['award'] = array_values($work['awards']);
        }
        $jsonLd['brand'] = ['@type' => 'Brand', 'name' => $cfg['shopName']];
        if (self::t($work['priceCents'] ?? null)) {
            $jsonLd['offers'] = [
                '@type' => 'Offer',
                'url' => $cfg['siteUrl'] . '/werk/' . self::str($work['slug']),
                'priceCurrency' => 'EUR',
                'price' => self::toFixed($work['priceCents'] / 100, 2),
                'availability' => $work['status'] === 'available' ? 'https://schema.org/InStock' : ($work['status'] === 'reserved' ? 'https://schema.org/LimitedAvailability' : 'https://schema.org/SoldOut'),
                'itemCondition' => 'https://schema.org/NewCondition',
            ];
        }
        $breadcrumb = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Werke', 'item' => $cfg['siteUrl'] . '/'],
                ['@type' => 'ListItem', 'position' => 2, 'name' => $work['title'], 'item' => $cfg['siteUrl'] . '/werk/' . self::str($work['slug'])],
            ],
        ];
        $desc = self::str($work['title']) . ($hasYear ? ' (' . self::str($work['year']) . ')' : '') . ' von ' . $cfg['artistName'] . ': ' . self::str($work['technique']) . ', ' . self::str($work['size']) . '. ' . (self::t($work['priceCents'] ?? null) ? 'Original für ' . Works::euro($work['priceCents']) : 'Preis auf Anfrage') . ', signiert mit Echtheitszertifikat.';
        $pre = '';
        if (self::isLocalImg($work['image'])) {
            $st = preg_replace('/\.jpe?g$/iD', '', $work['image'], 1);
            $pre = $st . '-xs.webp 420w, ' . $st . '-sm.webp 720w, ' . $st . '.webp 1600w';
        }
        return self::layout(['title' => self::str($work['title']) . ' – Original kaufen', 'description' => $desc, 'body' => $body, 'canonical' => '/werk/' . self::str($work['slug']), 'ogImage' => $work['image'], 'preload' => $pre, 'jsonLd' => [$jsonLd, $breadcrumb], 'bodyClass' => 'page-work']);
    }

    // ---------------------------------------------------------------- Danke / 404

    /** @param array|null $session Stripe-Checkout-Session als Array (wie von der API geliefert) */
    public static function thanks(?array $session, ?array $work): string
    {
        $cfg = cfg();
        $fullName = $session['customer_details']['name'] ?? null;
        $name = is_string($fullName) ? explode(' ', $fullName)[0] : null;
        $email = $session['customer_details']['email'] ?? null;
        $total = $session['amount_total'] ?? null;
        $isPickup = ($session['shipping_cost']['amount_total'] ?? null) === 0 && $cfg['pickupEnabled'];
        $conversion = '';
        if (self::t($cfg['googleAdsConversion']) && $session) {
            $data = ['send_to' => $cfg['googleAdsConversion'], 'value' => self::jsonNum((self::t($total) ? $total : 0) / 100), 'currency' => 'EUR'];
            if (array_key_exists('id', $session) && $session['id'] !== null) {
                $data['transaction_id'] = $session['id'];
            } elseif (array_key_exists('id', $session)) {
                $data['transaction_id'] = null;
            }
            // Daten-Block statt Inline-Skript (Content-Security-Policy); shop.js liest ihn aus
            $conversion = '<script type="application/json" id="mh-conversion">' . str_replace('<', '\u003c', self::json($data)) . '</script>';
        }
        $body = '
<section class="thanks">
  ' . ($work ? self::pic($work['thumb'], 'class="thanks-img" alt="' . e($work['title']) . '"') : '') . '
  <div>
    <p class="eyebrow">Bestellung eingegangen</p>
    <h1>Danke' . (self::t($name) ? ', ' . e($name) : '') . '. ' . ($work ? '„' . e($work['title']) . '“ gehört jetzt Ihnen.' : 'Ihr Kauf ist bestätigt.') . '</h1>
    <p class="lead">' . (($session['payment_status'] ?? null) === 'paid' ? 'Die Zahlung ist bestätigt.' : 'Ihre Zahlung wird noch verarbeitet. Sobald sie bestätigt ist, bekommen Sie eine E-Mail.') . (self::t($email) ? ' Eine Bestätigung geht an <strong>' . e($email) . '</strong>.' : '') . '</p>
    <ul class="assurances">
      <li>' . ($isPickup ? 'Ich melde mich in den nächsten Tagen, um einen Abholtermin im Atelier mit Ihnen abzustimmen.' : 'Ich verpacke das Werk persönlich und versende es versichert innerhalb von ' . e($cfg['deliveryDays']) . ' Werktagen. Die Sendungsnummer bekommen Sie per E-Mail.') . '</li>
      <li>Das signierte Echtheitszertifikat liegt dem Werk bei.</li>
      <li>Fragen? <a href="mailto:' . e($cfg['contactEmail']) . '">' . e($cfg['contactEmail']) . '</a></li>
    </ul>
    ' . (self::t($total) ? '<p class="muted">Gesamtbetrag: ' . Works::euro((int) $total) . (self::t($session['id'] ?? null) ? ' · Bestellreferenz: ' . e(mb_strtoupper(mb_substr($session['id'], -10))) : '') . '</p>' : '') . '
    <p><a class="btn btn--ghost" href="/">Zurück zum Shop</a></p>
  </div>
</section>' . $conversion;
        return self::layout(['title' => 'Danke für Ihren Kauf', 'body' => $body, 'canonical' => '/danke', 'noindex' => true, 'bodyClass' => 'page-thanks']);
    }

    public static function notFound(): string
    {
        return self::layout([
            'title' => 'Seite nicht gefunden',
            'noindex' => true,
            'canonical' => '/404',
            'body' => '<section class="legal"><h1>Dieses Werk ist nicht (mehr) hier.</h1><p>Vielleicht wurde es gerade verkauft oder der Link ist veraltet.</p><p><a class="btn" href="/#werke">Alle verfügbaren Werke</a></p></section>',
        ]);
    }
}
