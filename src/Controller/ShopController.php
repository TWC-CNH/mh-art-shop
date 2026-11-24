<?php
declare(strict_types=1);

namespace App\Controller;

use App\Catalog;
use App\Config;
use App\Http;
use App\Log;
use App\RateLimit;
use App\View\Legal;
use App\View\Render;
use App\Works;

/** Öffentliche Seiten: Start, Werk, Danke, Rechtstexte, Sitemap, Feed, robots.txt, Live-Status. */
final class ShopController
{
    public static function home(): never
    {
        Http::html(Render::home(Catalog::list()), 200, true);
    }

    public static function work(string $slug): never
    {
        $work = Catalog::bySlug($slug);
        if (!$work) {
            self::notFound();
        }
        Http::html(Render::work($work, Catalog::list()), 200, true);
    }

    public static function thanks(): never
    {
        $id = Http::query('session_id');
        $session = null;
        $work = null;
        $s = CheckoutController::stripe();
        // Eigener Browser (Cookie passt): volle Bestätigung. Sonst – z. B. Rückkehr aus der Bank-App in einem anderen
        // Browser oder Kauf in einem zweiten Tab – nur eine Bestätigung ohne persönliche Daten und ohne Conversion.
        // Abfragen bei Stripe ohne passendes Cookie sind je IP begrenzt (keine Stripe-Last durch erfundene IDs).
        $own = CheckoutController::isMine($id);
        $limit = !$own && preg_match('/^cs_[A-Za-z0-9_]+$/', $id) ? RateLimit::hit('danke-ip', RateLimit::clientIp(), 20, 3600) : ['ok' => true, 'id' => null];
        if (!preg_match('/^cs_[A-Za-z0-9_]+$/', $id) || !$limit['ok']) {
            Http::redirect('/');
        }
        if ($s) {
            try {
                $session = $s->checkout->sessions->retrieve($id)->toArray();
                if (!empty($session['metadata']['slug'])) {
                    // ohne Filter „verkaufte ausblenden“: das Werk ist ja gerade verkauft worden
                    $work = Catalog::bySlugIncludingSold((string) $session['metadata']['slug']);
                }
            } catch (\Throwable $e) {
                Log::warn('Session nicht gefunden: ' . $e->getMessage());
                if ($own) {
                    // Eigener Bezahlvorgang, Stripe gerade nicht erreichbar: nicht kommentarlos zur Startseite schicken
                    Http::html(Render::processing(), 503);
                }
            }
        }
        // Nur ein wirklich abgeschlossener Bezahlvorgang bekommt die Bestätigung (und die Conversion).
        // Abgebrochene oder abgelaufene Sitzungen, fremde IDs und Aufrufe ohne ID landen beim Werk bzw. auf der Startseite.
        if (($session['status'] ?? '') !== 'complete') {
            $slug = $session['metadata']['slug'] ?? '';
            Http::redirect(is_string($slug) && $slug !== '' && $work ? '/werk/' . rawurlencode($slug) : '/');
        }
        RateLimit::undo($limit['id'] ?? null); // abgeschlossener Kauf: zählt nicht gegen die Grenze (z. B. mehrere Käufer hinter einer IP)
        if (!$own) {
            // Bestätigung ja (das Werk gehört Ihnen), aber Name, E-Mail, Betrag und Conversion nur im eigenen Browser
            // Zahlungsstatus ist keine persönliche Angabe: bleibt, damit „bezahlt“ bzw. „wird verarbeitet“ stimmt
            $session = ['status' => 'complete', 'payment_status' => $session['payment_status'] ?? null, 'shipping_cost' => ['amount_total' => $session['shipping_cost']['amount_total'] ?? null], 'metadata' => $session['metadata'] ?? []];
        }
        Http::html(Render::thanks($session, $work, $own));
    }

    public static function legal(string $key): never
    {
        $html = Legal::render($key);
        if ($html === null) {
            self::notFound();
        }
        Http::html($html, 200, true);
    }

    public static function notFound(): never
    {
        Http::html(Render::notFound(), 404);
    }

    /** Aktueller Status aller Werke; das Frontend aktualisiert damit Badges und Kauf-Button. */
    public static function status(): never
    {
        // Auch verkaufte Werke (bei SHOW_SOLD=false sonst nicht enthalten): gecachte Seiten sperren sonst den Kauf-Knopf nicht
        $status = Catalog::statusMap();
        // Eigene laufende Bezahlvorgänge dieses Browsers: dort darf der Knopf zurück zur Zahlung führen
        $mine = [];
        $sessions = CheckoutController::mySessions();
        if ($sessions) {
            $in = implode(',', array_fill(0, count($sessions), '?'));
            $mine = array_column(\App\Db::all("SELECT slug FROM werke WHERE reserviert_session IN ($in) AND reserviert_bis > UTC_TIMESTAMP()", $sessions), 'slug');
        }
        Http::jsonOut(['status' => (object) $status, 'mine' => $mine, 'at' => gmdate('Y-m-d\TH:i:s\Z')]);
    }

    private static function x(mixed $t): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], (string) ($t ?? ''));
    }

    private static function abs(string $u): string
    {
        return preg_match('#^https?:#', $u) ? $u : Config::get()['siteUrl'] . $u;
    }

    public static function sitemap(): never
    {
        $cfg = Config::get();
        $entries = ["  <url><loc>{$cfg['siteUrl']}/</loc></url>"];
        foreach (Catalog::list() as $w) {
            $imgs = '';
            foreach (array_values(array_filter([$w['image'], $w['uvImage']])) as $i => $img) {
                $imgs .= '<image:image><image:loc>' . self::x(self::abs($img)) . '</image:loc><image:title>' . self::x($w['title'] . ($i ? ' (unter Schwarzlicht)' : '') . ' – ' . $cfg['artistName']) . '</image:title></image:image>';
            }
            $entries[] = "  <url><loc>{$cfg['siteUrl']}/werk/{$w['slug']}</loc>$imgs</url>";
        }
        foreach (Legal::KEYS as $k) {
            $entries[] = "  <url><loc>{$cfg['siteUrl']}/$k</loc></url>";
        }
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\" xmlns:image=\"http://www.google.com/schemas/sitemap-image/1.1\">\n" . implode("\n", $entries) . "\n</urlset>";
        Http::text($xml, 'application/xml; charset=utf-8');
    }

    /** Produkt-Feed für das Google Merchant Center. */
    public static function feed(): never
    {
        $cfg = Config::get();
        $items = [];
        foreach (Catalog::list() as $w) {
            if (!$w['priceCents'] || $w['status'] === 'sold') {
                continue;
            }
            $items[] = "  <item>\n    <g:id>" . self::x($w['slug']) . "</g:id>\n"
                . '    <title>' . self::x("{$w['title']} – Originalgemälde von {$cfg['artistName']}" . ($w['year'] ? ", {$w['year']}" : '')) . "</title>\n"
                . '    <description>' . self::x(trim(($w['description'] ?: '') . " {$w['technique']}, {$w['size']}. Signiertes Unikat mit Echtheitszertifikat.")) . "</description>\n"
                . "    <link>{$cfg['siteUrl']}/werk/" . self::x($w['slug']) . "</link>\n"
                . '    <g:image_link>' . self::x(self::abs($w['image'])) . '</g:image_link>'
                . ($w['uvImage'] ? "\n    <g:additional_image_link>" . self::x(self::abs($w['uvImage'])) . '</g:additional_image_link>' : '') . "\n"
                . '    <g:availability>' . ($w['status'] === 'available' ? 'in_stock' : 'out_of_stock') . "</g:availability>\n"
                . '    <g:price>' . number_format($w['priceCents'] / 100, 2, '.', '') . " EUR</g:price>\n"
                . "    <g:condition>new</g:condition>\n"
                . '    <g:brand>' . self::x($cfg['artistName']) . "</g:brand>\n"
                . "    <g:identifier_exists>no</g:identifier_exists>\n"
                . "    <g:google_product_category>Home &amp; Garden &gt; Decor &gt; Artwork</g:google_product_category>\n"
                . "    <g:product_type>Kunst &gt; Malerei &gt; Original</g:product_type>\n"
                . '    <g:shipping><g:country>DE</g:country><g:price>' . number_format(Works::shippingFor($w, $cfg), 2, '.', '') . " EUR</g:price></g:shipping>\n"
                . '  </item>';
        }
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<rss version=\"2.0\" xmlns:g=\"http://base.google.com/ns/1.0\">\n<channel>\n  <title>" . self::x($cfg['shopName']) . ' – ' . self::x($cfg['artistName']) . "</title>\n  <link>{$cfg['siteUrl']}</link>\n  <description>Originalgemälde von " . self::x($cfg['artistName']) . "</description>\n" . implode("\n", $items) . "\n</channel>\n</rss>";
        Http::text($xml, 'application/xml; charset=utf-8');
    }

    public static function robots(): never
    {
        $u = Config::get()['siteUrl'];
        Http::text("User-agent: *\nAllow: /\nDisallow: /api/\nDisallow: /danke\nDisallow: /atelier\n\nSitemap: $u/sitemap.xml\n", 'text/plain; charset=utf-8');
    }
}
