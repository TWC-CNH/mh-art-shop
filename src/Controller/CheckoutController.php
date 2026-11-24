<?php
declare(strict_types=1);

namespace App\Controller;

use App\Catalog;
use App\Config;
use App\Db;
use App\Http;
use App\Log;
use App\RateLimit;
use App\Works;
use Stripe\StripeClient;

/**
 * Kauf eines Werks über Stripe Checkout. Ablauf:
 * 1. Werk in der Datenbank atomar reservieren (nur ein Käufer gleichzeitig)
 * 2. Stripe-Sitzung mit Preis aus der Datenbank anlegen (keine Stripe-Produkte nötig)
 * 3. Nach Zahlung markiert der Webhook das Werk als verkauft; Abbruch oder Ablauf gibt es wieder frei
 */
final class CheckoutController
{
    public const SESSION_COOKIE = 'mh_co';

    public static function stripe(): ?StripeClient
    {
        $c = Config::get();
        if ($c['stripeSecretKey'] === '') {
            return null;
        }
        $opts = ['api_key' => $c['stripeSecretKey']];
        if ($c['stripeApiBase'] !== '') {
            $opts['api_base'] = $c['stripeApiBase']; // nur für Tests gegen einen lokalen Stripe-Ersatz
        }
        return new StripeClient($opts);
    }

    /** Eigene Bezahlvorgänge dieses Browsers (Cookie, höchstens 5, durch Punkte getrennt). */
    public static function mySessions(): array
    {
        // höchstens die letzten 5 – das Cookie kommt vom Browser und könnte beliebig lang sein
        return array_slice(array_values(array_filter(explode('.', Http::cookie(self::SESSION_COOKIE)), fn ($s) => (bool) preg_match('/^cs_[A-Za-z0-9_]+$/', $s))), -5);
    }

    public static function isMine(string $sessionId): bool
    {
        foreach (self::mySessions() as $s) {
            if (hash_equals($s, $sessionId)) {
                return true;
            }
        }
        return false;
    }

    private static function rememberSessions(array $ids): void
    {
        $ids = array_slice(array_values(array_unique($ids)), -5);
        Http::setCookie(self::SESSION_COOKIE, '', -1, '/api', 'Lax'); // Überbleibsel mit Pfad /api (frühere Fassung) entfernen
        Http::setCookie(self::SESSION_COOKIE, implode('.', $ids), $ids ? Config::get()['reserveMinutes'] * 60 + 900 : -1, '/', 'Lax');
    }

    /** Antwort für fetch (JSON) oder klassisches Formular (Weiterleitung). */
    private static function reply(string $url, string $error = '', int $status = 303, string $sessionId = ''): never
    {
        if ($sessionId !== '') {
            // Merkt sich die eigene Sitzung, damit man nach "Zurück" im Browser weiterbezahlen kann.
            // So lange gültig wie der Bezahlvorgang (Reservierungsdauer plus Puffer); weitere Käufe in anderen Tabs bleiben erhalten
            self::rememberSessions([...self::mySessions(), $sessionId]);
        }
        if (Http::wantsJson()) {
            Http::jsonOut($error !== '' ? ['error' => $error] : ['url' => $url], $error !== '' ? ($status === 303 ? 400 : $status) : 200);
        }
        Http::redirect($url);
    }

    public static function checkout(): never
    {
        if (Http::method() !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            exit('Method Not Allowed');
        }
        if (!Http::sameOrigin()) {
            http_response_code(403);
            exit('Forbidden'); // fremde Seiten sollen keine Reservierungen auslösen können
        }
        $cfg = Config::get();
        $raw = Http::json()['slug'] ?? Http::post('slug', 140);
        $slug = is_string($raw) ? mb_substr($raw, 0, 140) : '';
        $back = fn (string $flag) => $cfg['siteUrl'] . '/werk/' . rawurlencode($slug) . "?$flag=1";

        if ($slug === '') {
            self::reply($cfg['siteUrl'] . '/', 'Kein Werk angegeben', 400);
        }
        if ($cfg['demo']) {
            self::reply($back('demo'), 'demo', 409);
        }

        $row = Db::one('SELECT id, reserviert_session, (reserviert_bis > UTC_TIMESTAMP()) AS res FROM werke WHERE slug = ? AND aktiv = 1 AND sichtbar = 1', [$slug]);
        if (!$row) {
            self::reply($cfg['siteUrl'] . '/', 'Werk nicht gefunden', 404);
        }
        $s = self::stripe();

        // Eigene, noch offene Sitzung? Dann einfach dorthin zurück.
        if ((int) $row['res'] && self::isMine((string) $row['reserviert_session'])) {
            try {
                $open = $s->checkout->sessions->retrieve((string) $row['reserviert_session']);
                if ($open->status === 'open' && $open->url) {
                    self::reply($open->url);
                }
                if ($open->status === 'complete') {
                    self::reply($cfg['siteUrl'] . '/danke?session_id=' . rawurlencode((string) $row['reserviert_session']));
                }
                if ($open->status === 'expired') {
                    // eigene Sitzung ist bei Stripe abgelaufen (Webhook noch unterwegs): Reservierung freigeben, neu kaufen
                    Catalog::release((int) $row['id'], (string) $row['reserviert_session']);
                    Db::run('UPDATE bestellungen SET status = \'abgelaufen\' WHERE stripe_session_id = ? AND status = \'offen\'', [(string) $row['reserviert_session']]);
                }
            } catch (\Throwable $e) {
                Log::warn('Eigene Sitzung nicht abrufbar: ' . $e->getMessage());
                self::reply($back('fehler'), 'fehler', 503); // nicht „vergeben“ – es ist ja die eigene Reservierung
            }
        }

        $work = Catalog::bySlug($slug);
        if (!$work || $work['status'] !== 'available' || $work['priceCents'] === null) {
            self::reply($back('vergeben'), 'vergeben', 409);
        }

        $seconds = $cfg['reserveMinutes'] * 60 + 30;
        $token = Catalog::claim($work['id'], $seconds + 60);
        if ($token === null) {
            self::reply($back('vergeben'), 'vergeben', 409); // zählt nicht gegen die Begrenzung
        }
        // Missbrauchsschutz: Jeder neue Bezahlvorgang reserviert ein Werk. Je IP nur wenige pro Reservierungsdauer.
        $limit = RateLimit::hit('checkout', RateLimit::clientIp(), $cfg['limits']['checkoutPerIp'], $cfg['reserveMinutes'] * 60);
        if (!$limit['ok']) {
            Catalog::release($work['id'], $token);
            self::reply($back('zuviele'), 'zuviele', 429);
        }
        $slot = $limit['id'] ?? null;

        // Nach der Reservierung frisch lesen: Preis, Versand und Maße könnten sich seit dem ersten Lesen geändert haben.
        // Ab jetzt sieht das Atelier die Reservierung und beendet sie bei weiteren Änderungen selbst.
        $fresh = Catalog::bySlug($slug);
        if (!$fresh || $fresh['id'] !== $work['id'] || $fresh['priceCents'] === null) {
            Catalog::release($work['id'], $token);
            RateLimit::undo($slot);
            self::reply($back('vergeben'), 'vergeben', 409);
        }
        $work = $fresh;

        $session = null;
        try {
            $session = $s->checkout->sessions->create(self::sessionParams($work, $cfg, time() + $seconds));
            Db::run('INSERT INTO bestellungen (stripe_session_id, werk_id, betrag_cent) VALUES (?, ?, ?)', [$session->id, $work['id'], $work['priceCents']]);
            if (!Catalog::attachSession($work['id'], $token, $session->id, (int) $session->expires_at, (int) $work['priceCents'])) {
                // Während die Sitzung entstand, wurde das Werk entfernt oder verkauft: Sitzung sofort schließen
                try {
                    $s->checkout->sessions->expire($session->id);
                } catch (\Throwable $e2) {
                    Log::error('Sitzung für vergebenes Werk nicht geschlossen: ' . $e2->getMessage());
                }
                Db::run('UPDATE bestellungen SET status = \'abgelaufen\' WHERE stripe_session_id = ?', [$session->id]);
                Catalog::release($work['id'], $token); // eigene vorläufige Reservierung nicht stehen lassen
                RateLimit::undo($slot);
                self::reply($back('vergeben'), 'vergeben', 409);
            }
        } catch (\Throwable $e) {
            Log::error('Checkout-Fehler: ' . $e->getMessage());
            RateLimit::undo($slot); // kein Bezahlvorgang entstanden: zählt nicht gegen die Begrenzung
            // Aufräumen: Reservierung freigeben (Token oder schon zugeordnete Sitzung) und eine bereits
            // angelegte Stripe-Sitzung schließen, damit sie nicht ohne Bestellung bezahlt werden kann.
            Catalog::release($work['id'], $token);
            if ($session) {
                Catalog::release($work['id'], $session->id);
                try {
                    Db::run('UPDATE bestellungen SET status = \'abgelaufen\' WHERE stripe_session_id = ? AND status = \'offen\'', [$session->id]);
                } catch (\Throwable) {
                }
                try {
                    $s->checkout->sessions->expire($session->id);
                } catch (\Throwable $e2) {
                    Log::error('Stripe-Sitzung konnte nicht geschlossen werden: ' . $e2->getMessage());
                }
            }
            self::reply($back('fehler'), 'fehler', 500);
        }
        self::reply($session->url, '', 303, $session->id);
    }

    private static function sessionParams(array $work, array $cfg, int $expiresAt): array
    {
        $shipEur = Works::shippingFor($work, $cfg);
        [$dMin, $dMax] = array_pad(array_map('intval', explode('-', $cfg['deliveryDays'])), 2, 0);
        $shipping = [[
            'shipping_rate_data' => [
                'display_name' => 'Versicherter Versand',
                'type' => 'fixed_amount',
                'fixed_amount' => ['amount' => (int) round($shipEur * 100), 'currency' => 'eur'],
                'delivery_estimate' => [
                    'minimum' => ['unit' => 'business_day', 'value' => $dMin ?: 3],
                    'maximum' => ['unit' => 'business_day', 'value' => $dMax ?: 7],
                ],
                'tax_behavior' => 'inclusive',
            ],
        ]];
        if ($cfg['pickupEnabled']) {
            $shipping[] = ['shipping_rate_data' => [
                'display_name' => mb_substr($cfg['pickupLabel'], 0, 100),
                'type' => 'fixed_amount',
                'fixed_amount' => ['amount' => 0, 'currency' => 'eur'],
                'tax_behavior' => 'inclusive',
            ]];
        }
        $productData = ['name' => mb_substr($work['title'] . ($work['year'] ? " ({$work['year']})" : ''), 0, 250)];
        $desc = trim(implode(' · ', array_filter([$work['technique'], $work['size'], 'Original, signiert, mit Echtheitszertifikat'])));
        $productData['description'] = mb_substr($desc, 0, 500);
        if (str_starts_with($cfg['siteUrl'], 'https://') && $work['image'] !== '') {
            $productData['images'] = [str_starts_with($work['image'], 'http') ? $work['image'] : $cfg['siteUrl'] . $work['image']];
        }
        $meta = ['werk_id' => (string) $work['id'], 'slug' => $work['slug'], 'title' => mb_substr($work['title'], 0, 450)];

        $params = [
            'mode' => 'payment',
            'submit_type' => 'pay',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => ['currency' => 'eur', 'unit_amount' => $work['priceCents'], 'tax_behavior' => 'inclusive', 'product_data' => $productData],
            ]],
            'success_url' => $cfg['siteUrl'] . '/danke?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cfg['siteUrl'] . '/api/cancel?session_id={CHECKOUT_SESSION_ID}&slug=' . rawurlencode($work['slug']),
            'expires_at' => $expiresAt,
            'locale' => 'de',
            'billing_address_collection' => 'required',
            'shipping_address_collection' => ['allowed_countries' => $cfg['shippingCountries']],
            'shipping_options' => $shipping,
            'phone_number_collection' => ['enabled' => true],
            'customer_creation' => 'if_required',
            'metadata' => $meta,
            'payment_intent_data' => [
                'description' => mb_substr('Original: ' . $work['title'] . ($work['year'] ? " ({$work['year']})" : ''), 0, 1000),
                'metadata' => ['werk_id' => (string) $work['id'], 'slug' => $work['slug']],
            ],
            'custom_text' => [
                'submit' => ['message' => mb_substr('Unikat, signiert, mit Echtheitszertifikat.' . ($cfg['kleinunternehmer'] ? ' Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.' : '') . " Es gelten die AGB und die Widerrufsbelehrung: {$cfg['siteUrl']}/agb · {$cfg['siteUrl']}/widerruf", 0, 1200)],
                'shipping_address' => ['message' => 'Bitte eine Adresse angeben, an der das Paket persönlich angenommen werden kann. Bei Abholung im Atelier hier einfach Ihre Rechnungsadresse eintragen.'],
            ],
        ];
        if ($cfg['requireTermsConsent']) {
            $params['consent_collection'] = ['terms_of_service' => 'required'];
        }
        if ($cfg['invoiceCreation']) {
            $params['invoice_creation'] = ['enabled' => true];
        }
        if ($cfg['allowPromotionCodes']) {
            $params['allow_promotion_codes'] = true;
        }
        return $params;
    }

    /** Käufer bricht bei Stripe ab: Sitzung schließen, Reservierung sofort freigeben, zurück zum Werk. */
    public static function cancel(): never
    {
        $cfg = Config::get();
        $id = Http::query('session_id');
        $slug = mb_substr(Http::query('slug'), 0, 140);
        $released = false;
        $s = self::stripe();
        // Nur wer den Bezahlvorgang gestartet hat (Cookie mit derselben Sitzung), kann ihn abbrechen.
        // Ein bekannt gewordener Abbruch-Link allein reicht nicht; die Reservierung läuft dann regulär ab.
        if ($s && preg_match('/^cs_[A-Za-z0-9_]+$/', $id) && self::isMine($id)) {
            try {
                $session = $s->checkout->sessions->retrieve($id);
                if ($session->status === 'open') {
                    try {
                        $session = $s->checkout->sessions->expire($id);
                    } catch (\Throwable $e) {
                        Log::warn('Abbruch: Sitzung bei Stripe nicht geschlossen: ' . $e->getMessage());
                        // Status neu lesen: vielleicht wurde gerade bezahlt, oder die Sitzung ist doch schon zu
                        try {
                            $session = $s->checkout->sessions->retrieve($id);
                        } catch (\Throwable) {
                            $session = null; // Status unbekannt: nicht freigeben
                        }
                    }
                }
                // Nur freigeben, wenn die Sitzung sicher nicht mehr bezahlt werden kann. Ist sie noch offen (z. B. in einem
                // zweiten Tab) oder unbekannt, bleibt die Reservierung bis zum Ablauf bzw. bis zum Webhook „expired“.
                $werkId = (int) ($session->metadata['werk_id'] ?? 0);
                if ($session && in_array($session->status, ['expired'], true) && $werkId) {
                    $released = Catalog::release($werkId, $id);
                    Db::run('UPDATE bestellungen SET status = \'abgelaufen\' WHERE stripe_session_id = ? AND status = \'offen\'', [$id]);
                }
            } catch (\Throwable $e) {
                Log::warn('Abbruch konnte nicht verarbeitet werden: ' . $e->getMessage());
            }
        }
        self::rememberSessions(array_filter(self::mySessions(), fn ($x) => $x !== $id)); // nur diesen Vorgang vergessen
        // Den Hinweis „Reservierung aufgehoben, wieder frei“ nur zeigen, wenn das auch stimmt
        Http::redirect($slug !== '' ? $cfg['siteUrl'] . '/werk/' . rawurlencode($slug) . ($released ? '?abgebrochen=1' : '') : $cfg['siteUrl'] . '/');
    }

    /** Stripe-Webhook: verkauft / wieder frei. Signatur wird geprüft, jedes Ereignis nur einmal verarbeitet. */
    public static function webhook(): never
    {
        if (Http::method() !== 'POST') {
            http_response_code(405);
            exit('Method Not Allowed');
        }
        $cfg = Config::get();
        if ($cfg['stripeSecretKey'] === '' || $cfg['stripeWebhookSecret'] === '') {
            http_response_code(500);
            exit('Webhook nicht konfiguriert');
        }
        $payload = (string) file_get_contents('php://input');
        try {
            $event = \Stripe\Webhook::constructEvent($payload, (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''), $cfg['stripeWebhookSecret']);
        } catch (\Throwable $e) {
            Log::warn('Ungültige Webhook-Signatur: ' . $e->getMessage());
            http_response_code(400);
            exit('Ungültige Signatur');
        }
        // Ereignis beanspruchen: Kommen zwei Zustellungen gleichzeitig, verarbeitet nur eine. „fertig“ wird erst nach
        // Erfolg gesetzt – bricht ein Lauf hart ab (Zeit- oder Speichergrenze), übernimmt eine spätere Zustellung.
        if (Db::run('INSERT IGNORE INTO webhook_events (event_id, typ, fertig) VALUES (?, ?, 0)', [$event->id, $event->type]) === 0) {
            $row = Db::one('SELECT fertig FROM webhook_events WHERE event_id = ?', [$event->id]);
            if ($row && (int) $row['fertig'] === 1) {
                Http::jsonOut(['received' => true, 'duplicate' => true]);
            }
            if (!$row) {
                // Eintrag ist inzwischen weg (die andere Zustellung ist gescheitert): selbst übernehmen
                $taken = Db::run('INSERT IGNORE INTO webhook_events (event_id, typ, fertig) VALUES (?, ?, 0)', [$event->id, $event->type]);
            } else {
                // in Arbeit: nur übernehmen, wenn der andere Lauf seit über 2 Minuten hängt
                $taken = Db::run('UPDATE webhook_events SET verarbeitet = UTC_TIMESTAMP() WHERE event_id = ? AND fertig = 0 AND verarbeitet <= UTC_TIMESTAMP() - INTERVAL 2 MINUTE', [$event->id]);
            }
            if ($taken === 0) {
                Http::jsonOut(['received' => false, 'busy' => true], 409); // Stripe stellt später erneut zu
            }
        }

        $obj = $event->data->object;
        $werkId = (int) ($obj->metadata['werk_id'] ?? 0);
        if (!$werkId && str_starts_with($event->type, 'checkout.session.')) {
            // Sitzungen ohne werk_id (z. B. aus einer früheren Shop-Fassung mit product_id): über den slug zuordnen
            $slug = (string) ($obj->metadata['slug'] ?? '');
            $werkId = $slug !== '' ? (int) (Db::one('SELECT id FROM werke WHERE slug = ?', [$slug])['id'] ?? 0) : 0;
            if (!$werkId) {
                Log::error("Webhook {$event->type} ohne zuordenbares Werk (Sitzung " . ($obj->id ?? '?') . '). Bitte im Stripe-Dashboard prüfen.');
            } elseif (!empty($obj->id)) {
                // Bestellzeile nachtragen, damit Betrag und Zahlungsdatum auch für solche Käufe in der Datenbank stehen
                Db::run('INSERT IGNORE INTO bestellungen (stripe_session_id, werk_id, betrag_cent) VALUES (?, ?, ?)', [(string) $obj->id, $werkId, $obj->amount_total ?? null]);
            }
        }
        $sid = (string) ($obj->id ?? '');
        try {
            switch ($event->type) {
                // Auch bei verzögerten Zahlarten (z. B. SEPA) sofort als verkauft markieren,
                // damit es niemand zweites kauft. Scheitert die Zahlung später, wird es wieder frei.
                case 'checkout.session.completed':
                case 'checkout.session.async_payment_succeeded':
                    $failedBefore = $event->type === 'checkout.session.completed'
                        && ($obj->payment_status ?? '') !== 'paid'
                        && (Db::one('SELECT status FROM bestellungen WHERE stripe_session_id = ?', [$sid])['status'] ?? '') === 'fehlgeschlagen';
                    if ($failedBefore) {
                        // Zustellung kam nach „Zahlung gescheitert“ an (z. B. erneuter Versuch von Stripe): nicht verkaufen
                        Log::warn("Webhook: verspätetes completed für gescheiterte Zahlung $sid ignoriert");
                        break;
                    }
                    if ($werkId) {
                        $r = Catalog::markSold($werkId, $sid);
                        if ($r['alreadySold']) {
                            Log::error("ACHTUNG: Werk $werkId wurde doppelt bezahlt (Sitzung $sid). Bitte im Stripe-Dashboard erstatten.");
                        }
                        if ($r['expire'] !== null) {
                            try {
                                self::stripe()?->checkout->sessions->expire($r['expire']);
                                Db::run('UPDATE bestellungen SET status = \'abgelaufen\' WHERE stripe_session_id = ? AND status = \'offen\'', [$r['expire']]);
                            } catch (\Throwable $e) {
                                Log::warn("Offene Sitzung {$r['expire']} nach Verkauf nicht geschlossen: " . $e->getMessage());
                            }
                        }
                        // 'no_payment_required': z. B. Gutschein über den vollen Betrag – ebenfalls erledigt
                        $paid = in_array($obj->payment_status ?? '', ['paid', 'no_payment_required'], true);
                        Db::run('UPDATE bestellungen SET status = IF(status = \'bezahlt\', status, ?), betrag_cent = COALESCE(?, betrag_cent), bezahlt_am = IF(?, UTC_TIMESTAMP(), bezahlt_am) WHERE stripe_session_id = ?', [$paid ? 'bezahlt' : 'offen', $obj->amount_total ?? null, $paid ? 1 : 0, $sid]);
                    }
                    break;
                case 'checkout.session.async_payment_failed':
                    if ($werkId) {
                        Catalog::markAvailable($werkId, $sid);
                        Db::run('UPDATE bestellungen SET status = \'fehlgeschlagen\' WHERE stripe_session_id = ?', [$sid]);
                    }
                    break;
                case 'checkout.session.expired':
                    if ($werkId) {
                        Catalog::release($werkId, $sid);
                        Db::run('UPDATE bestellungen SET status = \'abgelaufen\' WHERE stripe_session_id = ? AND status = \'offen\'', [$sid]);
                    }
                    break;
                default:
                    // charge.refunded u. a.: Werk bleibt bewusst verkauft, bis Matthias es im Atelier wieder freigibt.
                    break;
            }
            Db::run('UPDATE webhook_events SET fertig = 1 WHERE event_id = ?', [$event->id]);
        } catch (\Throwable $e) {
            Log::error("Fehler bei {$event->type}: " . $e->getMessage());
            try {
                Db::run('DELETE FROM webhook_events WHERE event_id = ?', [$event->id]); // Stripe soll es erneut zustellen
            } catch (\Throwable) {
            }
            http_response_code(500); // Stripe versucht es dann erneut
            exit('Fehler');
        }
        Http::jsonOut(['received' => true]);
    }
}
