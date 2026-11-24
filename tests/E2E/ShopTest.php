<?php
declare(strict_types=1);

namespace Tests\E2E;

use App\Db;
use PHPUnit\Framework\TestCase;
use Tests\Support\Client;
use Tests\Support\Stack;

/**
 * Ende-zu-Ende-Tests gegen den echten PHP-Server, eine Testdatenbank und einen Stripe-Ersatz.
 * Die Tests bauen in dieser Reihenfolge aufeinander auf (Kauf → Verkauf → …).
 */
final class ShopTest extends TestCase
{
    private static string $firstUrl = '';
    private static Client $buyer;

    public static function setUpBeforeClass(): void
    {
        Stack::start();
        Stack::applyToProcess();
        self::$buyer = new Client('198.51.100.1');
    }

    private static function werk(string $slug): array
    {
        return Db::one('SELECT * FROM werke WHERE slug = ?', [$slug]);
    }

    private static function buy(string $slug, ?Client $c = null): \Tests\Support\Response
    {
        static $n = 10;
        return ($c ?? new Client('198.51.100.' . ++$n))->postJson('/api/checkout', ['slug' => $slug]);
    }

    public function testStartseiteZeigtWerkeMitPreisen(): void
    {
        $r = (new Client())->get('/');
        $this->assertSame(200, $r->status);
        $this->assertMatchesRegularExpression('/3\.400\x{00A0}€/u', $r->body);
        $this->assertSame(33, substr_count($r->body, '<article class="card'));
        $this->assertStringContainsString('badge--sold', $r->body); // FREI ist verkauft
        $this->assertStringContainsString('action="/anfrage"', $r->body);
    }

    public function testContentSecurityPolicyPasstZumHtml(): void
    {
        $c = new Client();
        foreach (['/', '/werk/nacht', '/impressum', '/vertrag-widerrufen', '/atelier', '/danke'] as $path) {
            $r = $c->get($path);
            $csp = (string) $r->header('content-security-policy');
            $this->assertStringContainsString("script-src 'self' 'sha256-", $csp, $path);
            $this->assertStringNotContainsString("'unsafe-inline' https", $csp);
            $this->assertMatchesRegularExpression("/script-src 'self' 'sha256-[^']+'(;|$)/", $csp, 'ohne Google-Tag keine fremden Skriptquellen');
            preg_match_all('#<script(?![^>]*\bsrc=)(?![^>]*type="application/(?:ld\+)?json")[^>]*>(.*?)</script>#s', $r->body, $m);
            foreach ($m[1] as $inline) {
                $hash = "'sha256-" . base64_encode(hash('sha256', $inline, true)) . "'";
                $this->assertStringContainsString($hash, $csp, "Inline-Skript ohne passenden Hash auf $path: " . substr($inline, 0, 60));
            }
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+="/i', $r->body, "Inline-Ereignis auf $path");
        }
    }

    public function testCheckoutReserviertDasWerk(): void
    {
        $r = self::buy('cool', self::$buyer);
        $this->assertSame(200, $r->status, $r->body);
        self::$firstUrl = $r->json()['url'];
        $this->assertStringStartsWith('https://checkout.stripe.test', self::$firstUrl);
        $this->assertNotNull(self::$buyer->cookie('mh_co'));

        $w = self::werk('cool');
        $this->assertGreaterThan(gmdate('Y-m-d H:i:s', time() + 29 * 60), $w['reserviert_bis']);
        $s = Stack::stripeSessions()[$w['reserviert_session']];
        $p = $s['params'];
        $this->assertSame('1', $p['line_items'][0]['quantity']);
        $this->assertSame('340000', $p['line_items'][0]['price_data']['unit_amount']); // Preis aus der Datenbank
        $this->assertSame('DE', $p['shipping_address_collection']['allowed_countries'][0]);
        $this->assertSame('8900', $p['shipping_options'][0]['shipping_rate_data']['fixed_amount']['amount']); // großes Format
        $this->assertSame('0', $p['shipping_options'][1]['shipping_rate_data']['fixed_amount']['amount']); // Abholung
        $this->assertStringContainsString('§ 19 UStG', $p['custom_text']['submit']['message']);
        $this->assertSame((string) $w['id'], $p['metadata']['werk_id']);
        $this->assertSame('offen', Db::one('SELECT status FROM bestellungen WHERE stripe_session_id = ?', [$w['reserviert_session']])['status']);
    }

    public function testZweiterKaeuferBekommtVergeben(): void
    {
        $this->assertStringContainsString('Gerade reserviert', (new Client())->get('/werk/cool')->body);
        $r = self::buy('cool');
        $this->assertSame(409, $r->status);
        $this->assertSame('vergeben', $r->json()['error']);
    }

    public function testDerselbeKaeuferKommtZurueckInSeineSitzung(): void
    {
        $this->assertSame(self::$firstUrl, self::buy('cool', self::$buyer)->json()['url']);
    }

    public function testAbbruchGibtDasWerkFrei(): void
    {
        $sid = self::werk('cool')['reserviert_session'];
        $r = self::$buyer->get("/api/cancel?session_id=$sid&slug=cool");
        $this->assertSame(303, $r->status);
        $this->assertStringEndsWith('/werk/cool?abgebrochen=1', $r->header('location'));
        $this->assertSame('expired', Stack::stripeSessions()[$sid]['status']);
        $this->assertNull(self::werk('cool')['reserviert_bis']);
        $this->assertNull(self::$buyer->cookie('mh_co'));
    }

    public function testFremderAbbruchLinkGibtNichtFrei(): void
    {
        self::buy('nacht');
        $own = self::werk('nacht')['reserviert_session'];
        self::buy('blick');
        $other = self::werk('blick')['reserviert_session'];
        // Abbruch-Link der Sitzung von "blick", aber mit slug=nacht: "nacht" bleibt reserviert
        (new Client())->get("/api/cancel?session_id=$other&slug=nacht");
        $this->assertSame($own, self::werk('nacht')['reserviert_session']);
        $this->assertNull(self::werk('blick')['reserviert_session']);
    }

    public function testWebhookMitFalscherSignaturWirdAbgelehnt(): void
    {
        $r = Stack::webhook('checkout.session.completed', ['id' => 'cs_x', 'metadata' => ['werk_id' => (string) self::werk('cool')['id']]], 'whsec_falsch');
        $this->assertSame(400, $r->status);
        $this->assertNotSame('sold', self::werk('cool')['status']);
    }

    public function testBezahlungMarkiertVerkauftUndIstIdempotent(): void
    {
        $this->assertSame(200, self::buy('cool')->status);
        $sid = self::werk('cool')['reserviert_session'];
        $session = Stack::stripeSessions()[$sid];
        unset($session['params']);
        $obj = ['status' => 'complete', 'payment_status' => 'paid', 'amount_total' => 348900] + $session;

        $r = Stack::webhook('checkout.session.completed', $obj, eventId: 'evt_bezahlt_1');
        $this->assertSame(200, $r->status, $r->body);
        $w = self::werk('cool');
        $this->assertSame('sold', $w['status']);
        $this->assertSame($sid, $w['verkauft_session']);
        $this->assertNull($w['reserviert_bis']);
        $b = Db::one('SELECT * FROM bestellungen WHERE stripe_session_id = ?', [$sid]);
        $this->assertSame('bezahlt', $b['status']);
        $this->assertSame(348900, (int) $b['betrag_cent']);

        // gleiches Ereignis noch einmal: wird erkannt, nichts ändert sich
        $this->assertTrue(Stack::webhook('checkout.session.completed', $obj, eventId: 'evt_bezahlt_1')->json()['duplicate'] ?? false);

        $page = (new Client())->get('/werk/cool');
        $this->assertStringContainsString('Verkauft', $page->body);
        $this->assertStringNotContainsString('data-checkout', $page->body);
        $this->assertSame(409, self::buy('cool')->status);

        // Danke-Seite liest die Sitzung bei Stripe
        Stack::updateStripeSession($sid, ['status' => 'complete', 'customer_details' => ['name' => 'Erika Muster', 'email' => 'erika@example.test']]);
        $thanks = (new Client())->get("/danke?session_id=$sid");
        $this->assertSame(200, $thanks->status);
        $this->assertStringContainsString('Erika', $thanks->body);
        $this->assertSame('no-store', $thanks->header('cache-control'));
    }

    public function testAblaufUndGescheiterteZahlungGebenFrei(): void
    {
        self::buy('krieg');
        $sid = self::werk('krieg')['reserviert_session'];
        Stack::webhook('checkout.session.expired', ['id' => $sid, 'status' => 'expired', 'metadata' => ['werk_id' => (string) self::werk('krieg')['id']]]);
        $this->assertNull(self::werk('krieg')['reserviert_session']);

        self::buy('krieg');
        $sid = self::werk('krieg')['reserviert_session'];
        $obj = ['id' => $sid, 'status' => 'complete', 'payment_status' => 'unpaid', 'metadata' => ['werk_id' => (string) self::werk('krieg')['id']]];
        Stack::webhook('checkout.session.completed', $obj); // SEPA: sofort als verkauft markiert
        $this->assertSame('sold', self::werk('krieg')['status']);
        Stack::webhook('checkout.session.async_payment_failed', $obj);
        $this->assertSame('available', self::werk('krieg')['status']);
        $this->assertSame('fehlgeschlagen', Db::one('SELECT status FROM bestellungen WHERE stripe_session_id = ?', [$sid])['status']);
    }

    public function testDoppelteZahlungWirdVermerkt(): void
    {
        $id = (string) self::werk('cool')['id'];
        Stack::webhook('checkout.session.completed', ['id' => 'cs_test_zweite', 'status' => 'complete', 'payment_status' => 'paid', 'metadata' => ['werk_id' => $id]]);
        $w = self::werk('cool');
        $this->assertSame('cs_test_zweite', $w['doppelverkauf_session']);
        $this->assertNotSame('cs_test_zweite', $w['verkauft_session']);
    }

    public function testStatusSitemapFeedRechtstexte(): void
    {
        $c = new Client();
        $st = $c->get('/api/status')->json()['status'];
        $this->assertSame('sold', $st['cool']);
        $this->assertSame('reserved', $st['nacht']);
        $this->assertSame('available', $st['unsere-zeit']);

        $sm = $c->get('/sitemap.xml');
        $this->assertStringContainsString('/werk/cool</loc>', $sm->body);
        $this->assertStringContainsString('<image:loc>', $sm->body);
        $feed = $c->get('/feed.xml')->body;
        $this->assertStringContainsString('<g:id>unsere-zeit</g:id>', $feed);
        $this->assertStringNotContainsString('<g:id>cool</g:id>', $feed); // verkauft
        $this->assertStringContainsString('Disallow: /atelier', $c->get('/robots.txt')->body);
        foreach (['/impressum', '/agb', '/widerruf', '/versand', '/datenschutz'] as $p) {
            $this->assertSame(200, $c->get($p)->status, $p);
        }
        $this->assertSame(404, $c->get('/werk/gibt-es-nicht')->status);
        $this->assertSame(404, $c->get('/irgendwas')->status);
    }

    public function testAtelierLoginSchuetzt(): void
    {
        $c = new Client('192.0.2.10');
        $this->assertStringContainsString('noch nicht eingerichtet', $c->get('/atelier')->body, 'ohne Benutzer kein Login');
        Stack::createUser();
        $page = $c->get('/atelier')->body;
        $this->assertStringContainsString('name="benutzer"', $page);
        $this->assertStringContainsString('name="passwort"', $page);
        $this->assertStringContainsString('autocomplete="one-time-code"', $page);
        $r = $c->post('/atelier/login', ['benutzer' => 'niemand', 'passwort' => 'falsch', 'code' => '123456']);
        $this->assertSame(401, $r->status);
        $this->assertStringContainsString('Anmeldung fehlgeschlagen', $r->body);
        $this->assertNull($c->cookie('mh_atelier'));
        $this->assertSame(403, $c->post('/atelier/login', Stack::credentials(), ['Origin: https://boese.example'])->status);

        // Zwei-Faktor: richtiges Passwort ohne gültigen Code reicht nicht, ein Code gilt nur einmal
        $user = Stack::createUser();
        $ok = Stack::credentials($user);
        $wrong = ['code' => $ok['code'] === '000000' ? '111111' : '000000'] + $ok;
        $this->assertSame(401, (new Client('192.0.2.14'))->post('/atelier/login', $wrong)->status);
        $this->assertSame(401, (new Client('192.0.2.14'))->post('/atelier/login', ['passwort' => 'falsch-falsch'] + $ok)->status);
        $first = new Client('192.0.2.15');
        $this->assertSame(303, $first->post('/atelier/login', $ok)->status);
        $this->assertStringContainsString(e($user[0]), $first->get('/atelier')->body);
        $this->assertSame(401, (new Client('192.0.2.16'))->post('/atelier/login', $ok)->status, 'derselbe Code ein zweites Mal');

        // gesperrter Benutzer ist sofort abgemeldet
        Stack::applyToProcess();
        \App\AtelierAuth::setActive($user[0], false);
        $this->assertStringContainsString('name="passwort"', $first->get('/atelier')->body);
        $this->assertSame(401, $c->post('/atelier/upload', ['csrf' => 'x'])->status);
        $this->assertSame(401, $c->post('/atelier/speichern', ['titel' => 'X'])->status);
    }

    private static function login(string $ip): array
    {
        $c = new Client($ip);
        $r = $c->post('/atelier/login', Stack::credentials());
        if ($r->status !== 303) {
            throw new \RuntimeException('Login fehlgeschlagen: ' . $r->status);
        }
        preg_match('/name="csrf" content="([a-f0-9]{64})"/', $c->get('/atelier')->body, $m);
        return [$c, $m[1]];
    }

    private static function jpeg(int $w = 40, int $h = 30): \CURLFile
    {
        $f = Stack::$tmp . '/upload-' . uniqid() . '.jpg';
        imagejpeg(imagecreatetruecolor($w, $h), $f);
        return new \CURLFile($f, 'image/jpeg', 'foto.jpg');
    }

    public function testAtelierWerkAnlegenAendernVerkaufenAusblendenEntfernen(): void
    {
        [$c, $csrf] = self::login('192.0.2.11');
        $this->assertSame(33, substr_count($c->get('/atelier')->body, '<li class="at-row'));

        // Upload: drei Größen, ohne CSRF-Token abgelehnt, PNG abgelehnt
        $this->assertSame(403, $c->post('/atelier/upload', ['full' => self::jpeg(), 'sm' => self::jpeg(), 'xs' => self::jpeg()])->status);
        $u = $c->post('/atelier/upload', ['full' => self::jpeg(), 'sm' => self::jpeg(), 'xs' => self::jpeg(), 'csrf' => $csrf])->json();
        $this->assertMatchesRegularExpression('#^/bilder/[0-9a-f-]+\.jpg$#', $u['url'] ?? '');
        $u2 = $c->post('/atelier/upload', ['full' => self::jpeg(), 'sm' => self::jpeg(), 'xs' => self::jpeg(), 'csrf' => $csrf])->json();
        $png = Stack::$tmp . '/x.png';
        imagepng(imagecreatetruecolor(5, 5), $png);
        $this->assertSame(415, $c->post('/atelier/upload', ['full' => new \CURLFile($png), 'sm' => self::jpeg(), 'xs' => self::jpeg(), 'csrf' => $csrf])->status);
        $img = $c->get(str_replace('.jpg', '-sm.jpg', $u['url']));
        $this->assertSame(200, $img->status);
        $this->assertStringStartsWith("\xFF\xD8", $img->body);

        // Neues Werk (fremde Bild-URL wird ignoriert)
        $form = ['csrf' => $csrf, 'id' => '', 'titel' => 'Morgenrot', 'jahr' => '2026', 'serie' => 'Neu', 'technik' => 'Acryl und UV-Farbe auf Leinwand', 'masse' => '80 x 100 cm', 'beschreibung' => 'Ein neues Bild.', 'preis' => '2.450 €', 'versand' => '', 'status' => 'available', 'sichtbar' => 'ja', 'position' => '1', 'auszeichnungen' => "Preis A\nPreis B", 'ausstellungen' => '', 'w' => '1200', 'h' => '1500',
            'bilder' => json_encode([['url' => $u['url'], 'role' => 'haupt'], ['url' => $u2['url'], 'role' => 'uv'], ['url' => 'https://boese.example/x.jpg', 'role' => 'detail']])];
        $sv = $c->post('/atelier/speichern', $form);
        $this->assertSame(303, $sv->status, $sv->body);
        $w = self::werk('morgenrot');
        $this->assertSame(245000, (int) $w['preis_cent']);
        $this->assertSame('["Preis A","Preis B"]', $w['auszeichnungen']);
        $bilder = Db::all('SELECT pfad, rolle FROM werk_bilder WHERE werk_id = ? ORDER BY reihenfolge', [$w['id']]);
        $this->assertSame([['pfad' => $u['url'], 'rolle' => 'haupt'], ['pfad' => $u2['url'], 'rolle' => 'uv']], $bilder);
        $shop = (new Client())->get('/werk/morgenrot');
        $this->assertSame(200, $shop->status);
        $this->assertMatchesRegularExpression('/2\.450\x{00A0}€/u', $shop->body);
        $this->assertStringContainsString('-sm.jpg 720w', $shop->body);
        // Upload erzeugt zusätzlich WebP in allen drei Größen, der Shop bietet sie an
        $stem = substr($u['url'], 0, -4);
        $this->assertStringContainsString('<source type="image/webp" srcset="' . $stem . '-xs.webp 420w', $shop->body);
        foreach (['', '-sm', '-xs'] as $suffix) {
            $webp = $c->get($stem . $suffix . '.webp');
            $this->assertSame(200, $webp->status, $suffix);
            $this->assertSame('RIFF', substr($webp->body, 0, 4));
        }

        // gleicher Titel → eigene Adresse
        $c->post('/atelier/speichern', ['bilder' => '[]', 'preis' => ''] + $form);
        $this->assertNull(self::werk('morgenrot-2')['preis_cent']);

        // Preis ändern + verkauft
        $edit = ['csrf' => $csrf, 'id' => $w['id'], 'titel' => 'Morgenrot', 'preis' => '2900', 'status' => 'sold', 'sichtbar' => 'ja', 'bilder' => json_encode([['url' => $u['url'], 'role' => 'haupt']])];
        $this->assertSame(303, $c->post('/atelier/speichern', $edit)->status);
        $w = self::werk('morgenrot');
        $this->assertSame(290000, (int) $w['preis_cent']);
        $this->assertSame('sold', $w['status']);
        $this->assertStringContainsString('Verkauft', (new Client())->get('/werk/morgenrot')->body);

        // ungültiger Preis: Eingaben bleiben erhalten
        $err = $c->post('/atelier/speichern', ['preis' => 'abc'] + $edit);
        $this->assertSame(422, $err->status);
        $this->assertStringContainsString('Preis ist ungültig', $err->body);
        $this->assertStringContainsString('name="preis" inputmode="decimal" value="abc"', $err->body);

        // fehlendes oder falsches CSRF-Token, fremde Herkunft
        $this->assertSame(403, $c->post('/atelier/speichern', ['csrf' => str_repeat('0', 64)] + $edit)->status);
        $this->assertSame(403, $c->post('/atelier/speichern', $edit, ['Origin: https://boese.example'])->status);

        // ausblenden
        $c->post('/atelier/speichern', ['sichtbar' => 'nein'] + $edit);
        $this->assertSame(404, (new Client())->get('/werk/morgenrot')->status);
        $this->assertStringContainsString('Ausgeblendet', $c->get('/atelier')->body);

        // entfernen (archivieren)
        $c->post('/atelier/archivieren', ['csrf' => $csrf, 'id' => self::werk('morgenrot-2')['id']]);
        $this->assertSame(0, (int) self::werk('morgenrot-2')['aktiv']);
        $this->assertSame(404, (new Client())->get('/werk/morgenrot-2')->status);

        // importiertes Werk: nur den Preis ändern – Formatklasse (Versand) und Vorschaubild bleiben
        $nacht = self::werk('nacht');
        $bild = Db::one("SELECT pfad FROM werk_bilder WHERE werk_id = ? AND rolle = 'haupt'", [$nacht['id']])['pfad'];
        Db::run("UPDATE werke SET format = 'klein' WHERE id = ?", [$nacht['id']]); // bewusst abweichend von den Maßen
        $imgs = array_map(fn ($b) => ['url' => $b['pfad'], 'role' => $b['rolle']], Db::all('SELECT pfad, rolle FROM werk_bilder WHERE werk_id = ? ORDER BY reihenfolge', [$nacht['id']]));
        $r = $c->post('/atelier/speichern', ['csrf' => $csrf, 'id' => $nacht['id'], 'titel' => $nacht['titel'], 'masse' => $nacht['masse'], 'technik' => $nacht['technik'], 'preis' => '1234', 'status' => 'available', 'sichtbar' => 'ja', 'bilder' => json_encode($imgs)]);
        $this->assertSame(303, $r->status);
        $after = self::werk('nacht');
        $this->assertSame(123400, (int) $after['preis_cent']);
        $this->assertSame('klein', $after['format']);
        $this->assertSame($nacht['thumb'], $after['thumb']);
        $this->assertNotNull($after['thumb']);
        $this->assertStringStartsWith('/img/works/', $bild);

        // abmelden: Sitzung ist serverseitig ungültig
        $token = $c->cookie('mh_atelier');
        $c->post('/atelier/logout', ['csrf' => $csrf]);
        $stolen = new Client('192.0.2.12');
        $stolen->request('GET', '/atelier', null, ["Cookie: mh_atelier=$token"]);
        $this->assertStringContainsString('name="passwort"', $stolen->request('GET', '/atelier', null, ["Cookie: mh_atelier=$token"])->body);
    }

    /** Zweites Review von PR #1: Verkäufe dürfen durch das Atelier nicht verloren gehen, Checkout nur von der eigenen Seite. */
    public function testVerkaufGehtImAtelierNichtVerlorenUndCheckoutNurVonDerEigenenSeite(): void
    {
        [$c, $csrf] = self::login('192.0.2.13');
        $free = array_column(Db::all("SELECT slug FROM werke WHERE status = 'available' AND preis_cent IS NOT NULL AND reserviert_session IS NULL AND aktiv = 1 AND sichtbar = 1 ORDER BY id DESC LIMIT 3"), 'slug');

        // fremde Seite darf keinen Bezahlvorgang auslösen
        $foreign = (new Client('198.51.100.200'))->post('/api/checkout', ['slug' => $free[0]], ['Origin: https://boese.example']);
        $this->assertSame(403, $foreign->status);
        $this->assertNull(self::werk($free[0])['reserviert_session']);

        // Formular geöffnet, dann kommt ein Verkauf über den Shop herein, dann speichert Matthias einen Tippfehler
        $w = self::werk($free[0]);
        $form = $c->get('/atelier/werk/' . $w['id'])->body;
        $this->assertStringContainsString('name="status_geladen" value="available"', $form);
        $this->assertSame(200, self::buy($free[0])->status);
        $sid = self::werk($free[0])['reserviert_session'];
        Stack::webhook('checkout.session.completed', ['id' => $sid, 'status' => 'complete', 'payment_status' => 'paid', 'amount_total' => 100, 'metadata' => ['werk_id' => (string) $w['id']]]);
        $save = $c->post('/atelier/speichern', ['csrf' => $csrf, 'id' => $w['id'], 'titel' => $w['titel'] . ' ', 'masse' => $w['masse'], 'preis' => (string) ($w['preis_cent'] / 100), 'status' => 'available', 'status_geladen' => 'available', 'sichtbar' => 'ja', 'bilder' => '[]']);
        $this->assertSame(303, $save->status);
        $this->assertStringContainsString('inzwischen%20verkauft', $save->header('location'));
        $after = self::werk($free[0]);
        $this->assertSame('sold', $after['status']);
        $this->assertSame($sid, $after['verkauft_session']);

        // bewusst von „Verkauft“ auf „Verfügbar“ umgestellt: das gilt
        $c->post('/atelier/speichern', ['csrf' => $csrf, 'id' => $w['id'], 'titel' => $w['titel'], 'masse' => $w['masse'], 'status' => 'available', 'status_geladen' => 'sold', 'sichtbar' => 'ja', 'bilder' => '[]']);
        $this->assertSame('available', self::werk($free[0])['status']);

        // Bestellstatus fällt bei vertauschter Reihenfolge der Ereignisse nicht von „bezahlt“ zurück
        $this->assertSame(200, self::buy($free[1])->status);
        $w1 = self::werk($free[1]);
        $obj = ['id' => $w1['reserviert_session'], 'status' => 'complete', 'metadata' => ['werk_id' => (string) $w1['id']]];
        Stack::webhook('checkout.session.async_payment_succeeded', ['payment_status' => 'paid'] + $obj);
        Stack::webhook('checkout.session.completed', ['payment_status' => 'unpaid'] + $obj);
        $this->assertSame('bezahlt', Db::one('SELECT status FROM bestellungen WHERE stripe_session_id = ?', [$obj['id']])['status']);

        // Werk wird im Atelier verkauft, während jemand bezahlt: die Stripe-Sitzung wird geschlossen
        $this->assertSame(200, self::buy($free[2])->status);
        $w2 = self::werk($free[2]);
        $c->post('/atelier/speichern', ['csrf' => $csrf, 'id' => $w2['id'], 'titel' => $w2['titel'], 'masse' => $w2['masse'], 'status' => 'sold', 'status_geladen' => 'reserved', 'sichtbar' => 'ja', 'bilder' => '[]']);
        $this->assertSame('sold', self::werk($free[2])['status']);
        $this->assertSame('expired', Stack::stripeSessions()[$w2['reserviert_session']]['status']);

        // Atelier-Liste: Vorschaubilder von Uploads zeigen auf die -xs-Datei, die es gibt
        $list = $c->get('/atelier')->body;
        $this->assertStringNotContainsString('-sm-xs.jpg', $list);
        $this->assertMatchesRegularExpression('#/bilder/[0-9a-f-]+-xs\.jpg#', $list);
        preg_match('#/bilder/[0-9a-f-]+-xs\.jpg#', $list, $m);
        $this->assertSame(200, $c->get($m[0])->status);
    }

    public function testWiderrufWirdVerschluesseltGespeichert(): void
    {
        $c = new Client('192.0.2.20');
        $f = $c->get('/vertrag-widerrufen');
        $this->assertStringContainsString('>Widerruf bestätigen<', $f->body);
        $this->assertStringContainsString('vertrag-widerrufen', $c->get('/widerruf')->body);
        $this->assertSame(422, $c->post('/vertrag-widerrufen', ['name' => 'Erika Muster', 'vertrag' => 'NACHT', 'email' => 'kaputt'])->status);
        $this->assertSame(403, $c->post('/vertrag-widerrufen', ['name' => 'x'], ['Origin: https://boese.example'])->status);

        $r = $c->post('/vertrag-widerrufen', ['name' => 'Erika Muster', 'vertrag' => 'Werk „NACHT“, gekauft am 20.11.2026', 'email' => 'Erika@Example.test']);
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Ihr Widerruf ist eingegangen', $r->body);
        $this->assertMatchesRegularExpression('/\d{2}\.\d{2}\.\d{4}, \d{2}:\d{2}:\d{2} Uhr/', $r->body);
        $this->assertMatchesRegularExpression('/W-\d{8}-[0-9A-F]{6}/', $r->body);

        $row = Db::one('SELECT * FROM widerrufe ORDER BY id DESC LIMIT 1');
        $this->assertStringStartsWith('v1.', $row['daten_enc']);
        $this->assertStringNotContainsString('Erika', implode('|', $row));
        $this->assertStringNotContainsString('example', implode('|', $row));
        $this->assertSame(1, (int) $row['mail_versendet']);

        $mails = Stack::mails();
        $this->assertContains('erika@example.test', array_column($mails, 'to'));
        $this->assertNotEmpty(array_filter($mails, fn ($m) => str_starts_with($m['subject'], 'Widerruf eingegangen')));

        [$a] = self::login('192.0.2.21');
        $this->assertStringContainsString('Erika Muster', $a->get('/atelier/widerrufe')->body);
    }

    public function testAnfrageWirdVerschluesseltGespeichertUndGemeldet(): void
    {
        $c = new Client('192.0.2.30');
        $r = $c->post('/anfrage', ['anliegen' => 'preis', 'werk' => 'nacht', 'seite' => Stack::base() . '/werk/nacht', 'name' => 'Max Test', 'email' => 'max@example.test', 'telefon' => '0123 456', 'nachricht' => 'Was kostet NACHT?', 'datenschutz' => 'ja'], ['Accept: application/json']);
        $this->assertSame(['ok' => true], $r->json());
        $this->assertSame(422, $c->post('/anfrage', ['name' => 'Max', 'email' => 'max@example.test', 'nachricht' => 'x'], ['Accept: application/json'])->status); // ohne Einwilligung
        $this->assertSame(['ok' => true], $c->post('/anfrage', ['bot-field' => 'spam', 'name' => 'Bot'], ['Accept: application/json'])->json());

        $row = Db::one('SELECT * FROM anfragen ORDER BY id DESC LIMIT 1');
        $this->assertSame('preis', $row['anliegen']);
        $this->assertSame('nacht', $row['werk_slug']);
        $this->assertStringNotContainsString('Max', $row['daten_enc']);
        $this->assertSame(1, (int) Db::one('SELECT COUNT(*) AS n FROM anfragen')['n']); // Bot nicht gespeichert
        $m = array_values(array_filter(Stack::mails(), fn ($m) => str_starts_with($m['subject'], 'Preisanfrage')));
        $this->assertCount(1, $m);
        $this->assertSame('max@example.test', $m[0]['replyTo']);

        [$a] = self::login('192.0.2.31');
        $this->assertStringContainsString('Was kostet NACHT?', $a->get('/atelier/anfragen')->body);
    }

    public function testMissbrauchsschutz(): void
    {
        // Checkout: höchstens 3 neue Bezahlvorgänge je IP
        $free = array_column(Db::all("SELECT slug FROM werke WHERE status = 'available' AND preis_cent IS NOT NULL AND reserviert_session IS NULL AND aktiv = 1 AND sichtbar = 1 ORDER BY id LIMIT 5"), 'slug');
        $ip = new Client('203.0.113.7');
        foreach (array_slice($free, 0, 3) as $s) {
            $this->assertSame(200, self::buy($s, $ip)->status, $s);
        }
        $r4 = self::buy($free[3], $ip);
        $this->assertSame(429, $r4->status);
        $this->assertSame('zuviele', $r4->json()['error']);
        $this->assertNull(self::werk($free[3])['reserviert_session']);
        $this->assertSame(200, self::buy($free[3], new Client('203.0.113.8'))->status);
        $this->assertStringContainsString('data-notice="zuviele"', (new Client())->get('/werk/' . $free[4])->body);

        // Atelier: nach 3 Fehlversuchen gesperrt, auch für das richtige Passwort; andere IP nicht betroffen
        $bad = new Client('203.0.113.20');
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(401, $bad->post('/atelier/login', ['benutzer' => 'x', 'passwort' => 'falsch', 'code' => '000000'])->status);
        }
        $locked = $bad->post('/atelier/login', Stack::credentials());
        $this->assertSame(429, $locked->status);
        $this->assertStringContainsString('Zu viele Fehlversuche', $locked->body);
        $this->assertNull($bad->cookie('mh_atelier'));
        $this->assertSame(303, (new Client('203.0.113.21'))->post('/atelier/login', Stack::credentials())->status);

        // Widerruf: höchstens 5 je IP und Stunde
        $w = new Client('203.0.113.30');
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, $w->post('/vertrag-widerrufen', ['name' => 'Max Test', 'vertrag' => 'Werk NACHT', 'email' => "opfer$i@example.test"])->status);
        }
        $blocked = $w->post('/vertrag-widerrufen', ['name' => 'Max Test', 'vertrag' => 'Werk NACHT', 'email' => 'opfer9@example.test']);
        $this->assertSame(429, $blocked->status);
        $this->assertStringContainsString('per E-Mail an', $blocked->body);

        // Je Empfänger höchstens 2 Bestätigungen pro Tag; der Widerruf wird trotzdem gespeichert
        foreach (['203.0.113.31', '203.0.113.32', '203.0.113.33'] as $i => $addr) {
            (new Client($addr))->post('/vertrag-widerrufen', ['name' => 'Opfer', 'vertrag' => 'X', 'email' => 'opfer-mail@example.test']);
        }
        $this->assertCount(2, array_filter(Stack::mails(), fn ($m) => $m['to'] === 'opfer-mail@example.test'));
        $rows = Db::all('SELECT mail_versendet FROM widerrufe ORDER BY id DESC LIMIT 3');
        $this->assertSame(['0', '1', '1'], array_map(fn ($r) => (string) $r['mail_versendet'], $rows));

        // In der Datenbank stehen keine IP-Adressen oder E-Mails im Klartext
        $dump = json_encode(Db::all('SELECT * FROM rate_limits'));
        $this->assertStringNotContainsString('203.0.113', $dump);
        $this->assertStringNotContainsString('example.test', $dump);
    }

    public function testCronGibtAbgelaufeneReservierungenFreiUndLoescht(): void
    {
        $id = (int) self::werk('nacht')['id'];
        Db::run('UPDATE werke SET reserviert_bis = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 60), $id]);
        Db::run("INSERT INTO anfragen (ref, eingang, anliegen, daten_enc, email_hmac) VALUES ('A-ALT', UTC_TIMESTAMP() - INTERVAL 13 MONTH, 'allgemein', 'v1.x.y.z', REPEAT('0', 64))");
        Db::run("INSERT INTO widerrufe (ref, eingang, daten_enc, email_hmac) VALUES ('W-ALT', UTC_TIMESTAMP() - INTERVAL 37 MONTH, 'v1.x.y.z', REPEAT('0', 64)), ('W-JUNG', UTC_TIMESTAMP() - INTERVAL 35 MONTH, 'v1.x.y.z', REPEAT('0', 64))");
        Db::run("DELETE FROM merker WHERE name = 'cron_taeglich'");

        Stack::php('bin/cron.php');

        $this->assertNull(self::werk('nacht')['reserviert_session']);
        $this->assertNull(Db::one("SELECT 1 FROM anfragen WHERE ref = 'A-ALT'"));
        $this->assertNull(Db::one("SELECT 1 FROM widerrufe WHERE ref = 'W-ALT'"));
        $this->assertNotNull(Db::one("SELECT 1 FROM widerrufe WHERE ref = 'W-JUNG'"));
        $this->assertNotNull(Db::one("SELECT 1 FROM merker WHERE name = 'cron_letzter_lauf'"));
    }

    public function testSmokeSkriptLaeuft(): void
    {
        $out = '';
        try {
            $out = Stack::php('bin/smoke.php');
        } catch (\RuntimeException $e) {
            $out = $e->getMessage(); // SITE_URL ohne https und fehlendes SMTP sind im Test erwartet
        }
        $this->assertStringContainsString('ok   Datenbank erreichbar', $out);
        $this->assertStringContainsString('ok   Verschlüsselung funktioniert', $out);
    }
}
