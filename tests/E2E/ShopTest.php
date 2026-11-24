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
        $blickBuyer = new Client('198.51.100.77');
        self::buy('blick', $blickBuyer);
        $other = self::werk('blick')['reserviert_session'];
        // Abbruch-Link ohne das Cookie des Käufers (z. B. Link bekannt geworden): nichts wird freigegeben
        $r = (new Client())->get("/api/cancel?session_id=$other&slug=blick");
        $this->assertSame(303, $r->status);
        $this->assertSame($other, self::werk('blick')['reserviert_session']);
        $this->assertSame('open', Stack::stripeSessions()[$other]['status']);
        // Käufer von „blick“ bricht ab, aber mit slug=nacht: nur seine eigene Reservierung wird frei
        $blickBuyer->get("/api/cancel?session_id=$other&slug=nacht");
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
        $payer = new Client('198.51.100.88');
        $this->assertSame(200, self::buy('cool', $payer)->status);
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
        $thanks = $payer->get("/danke?session_id=$sid");
        $this->assertSame(200, $thanks->status);
        $this->assertStringContainsString('Erika', $thanks->body);
        $this->assertStringContainsString('erika@example.test', $thanks->body);
        $this->assertSame('no-store', $thanks->header('cache-control'));
        // geteilter Link ohne das Cookie des Käufers: keine Daten, keine Stripe-Abfrage, Weiterleitung zur Startseite
        // Anderer Browser (z. B. Rückkehr aus der Bank-App) bzw. geteilter Link: Bestätigung ja, persönliche Daten nein
        $shared = (new Client('198.51.100.97'))->get("/danke?session_id=$sid");
        $this->assertSame(200, $shared->status);
        $this->assertStringContainsString('cool', strtolower($shared->body));
        $this->assertStringNotContainsString('Erika', $shared->body);
        $this->assertStringNotContainsString('erika@example.test', $shared->body);
        $this->assertStringNotContainsString('mh-conversion', $shared->body);
        // Zweiter Tab: Cookie gehört zu einem anderen Kauf – ebenfalls Bestätigung ohne Daten
        $other = new Client('198.51.100.98');
        $other->request('GET', '/', null, ['Cookie: mh_co=cs_test_anderer_kauf']);
        $tab = $other->request('GET', "/danke?session_id=$sid", null, ['Cookie: mh_co=cs_test_anderer_kauf']);
        $this->assertSame(200, $tab->status);
        $this->assertStringNotContainsString('Erika', $tab->body);
        // Erfundene IDs ohne Cookie: nach 20 Versuchen je IP keine Stripe-Abfragen mehr
        $prober = new Client('198.51.100.99');
        for ($i = 0; $i < 20; $i++) {
            $prober->get('/danke?session_id=cs_test_erfunden' . $i);
        }
        $this->assertSame('/', $prober->get("/danke?session_id=$sid")->header('location'));
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

        // Notfall-Code statt App: einmal gültig, mit Hinweis auf die verbleibende Zahl
        $codes = \App\AtelierAuth::newRecoveryCodes($user[0]);
        $this->assertCount(10, $codes);
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{5}-[A-Z2-9]{5}$/', $codes[0]);
        $this->assertSame(10, (int) Db::one('SELECT COUNT(*) AS n FROM atelier_notfallcodes n JOIN atelier_benutzer b ON b.id = n.benutzer_id WHERE b.benutzer = ?', [$user[0]])['n']);
        $this->assertStringNotContainsString($codes[0], json_encode(Db::all('SELECT code_hash FROM atelier_notfallcodes')), 'nur Hash gespeichert');
        $rec = ['benutzer' => $user[0], 'passwort' => Stack::PASSWORD, 'code' => '', 'notfallcode' => strtolower(str_replace('-', '', $codes[0]))];
        $viaRecovery = new Client('192.0.2.17');
        $r = $viaRecovery->post('/atelier/login', $rec);
        $this->assertSame(303, $r->status);
        $this->assertStringEndsWith('/atelier?ok=notfall&n=9', (string) $r->header('location'));
        $this->assertStringContainsString('Noch 9 Notfall-Code(s) übrig', $viaRecovery->get('/atelier?ok=notfall&n=9')->body);
        $this->assertSame(401, (new Client('192.0.2.18'))->post('/atelier/login', $rec)->status, 'Notfall-Code ein zweites Mal');
        $this->assertSame(401, (new Client('192.0.2.18'))->post('/atelier/login', ['passwort' => 'falsch-falsch'] + ['notfallcode' => $codes[1]] + $rec)->status, 'Notfall-Code ohne richtiges Passwort');
        // Notfall-Code versehentlich ins normale Code-Feld getippt (11 Zeichen): wird trotzdem erkannt
        $this->assertSame(303, (new Client('192.0.2.21'))->post('/atelier/login', ['benutzer' => $user[0], 'passwort' => Stack::PASSWORD, 'code' => $codes[3]])->status);
        \App\AtelierAuth::newRecoveryCodes($user[0]);
        $this->assertSame(401, (new Client('192.0.2.19'))->post('/atelier/login', ['notfallcode' => $codes[2]] + $rec)->status, 'alte Codes nach Neuerzeugung ungültig');

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
        $this->assertStringContainsString('Preis ist ungültig', $c->post('/atelier/speichern', ['preis' => '1.500.000'] + $edit)->body, 'über der Stripe-Grenze');
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
        $this->assertStringEndsWith('?ok=inzwischen-verkauft', $save->header('location'));
        $this->assertStringContainsString('inzwischen verkauft', $c->get(parse_url($save->header('location'), PHP_URL_PATH) . '?ok=inzwischen-verkauft')->body);
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

    /** Drittes Review von PR #1 */
    public function testDrittesReview(): void
    {
        // Danke-Seite nur für abgeschlossene Bezahlvorgänge
        $this->assertSame('/', (new Client())->get('/danke')->header('location'));
        $free = array_column(Db::all("SELECT slug FROM werke WHERE status = 'available' AND preis_cent IS NOT NULL AND reserviert_session IS NULL AND aktiv = 1 AND sichtbar = 1 ORDER BY id LIMIT 3"), 'slug');
        $opener = new Client('198.51.100.95');
        $this->assertSame(200, self::buy($free[0], $opener)->status);
        $sid = self::werk($free[0])['reserviert_session'];
        $open = $opener->get("/danke?session_id=$sid");
        $this->assertSame(303, $open->status);
        $this->assertSame('/werk/' . $free[0], $open->header('location'));

        // Werk entfernen, während jemand bezahlt: Stripe-Sitzung wird geschlossen
        [$c, $csrf] = self::login('192.0.2.40');
        $c->post('/atelier/archivieren', ['csrf' => $csrf, 'id' => self::werk($free[0])['id']]);
        $this->assertSame('expired', Stack::stripeSessions()[$sid]['status']);
        $this->assertNull(self::werk($free[0])['reserviert_session']);
        $this->assertSame('abgelaufen', Db::one('SELECT status FROM bestellungen WHERE stripe_session_id = ?', [$sid])['status']);

        // „Vergeben“-Klicks zählen nicht gegen die Checkout-Begrenzung
        $this->assertSame(200, self::buy($free[1])->status);
        $nat = new Client('203.0.113.60');
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame(409, self::buy($free[1], $nat)->status);
        }
        $this->assertSame(200, self::buy($free[2], $nat)->status);

        // Umlaut-Adresse wird mit klarer Meldung abgelehnt statt still nicht versendet
        $w = (new Client('203.0.113.61'))->post('/vertrag-widerrufen', ['name' => 'Jörg', 'vertrag' => 'Werk X', 'email' => 'jörg@example.de']);
        $this->assertSame(422, $w->status);
        $this->assertStringContainsString('ohne Umlaute', $w->body);

        // Viele Fehlversuche für einen Benutzernamen (von vielen IPs) sperren den echten Zugang nicht aus
        $user = Stack::createUser();
        for ($i = 0; $i < 7; $i++) {
            (new Client('198.51.100.' . (150 + $i)))->post('/atelier/login', ['benutzer' => $user[0], 'passwort' => 'falsch-' . $i, 'code' => '000000']);
        }
        $this->assertSame(303, (new Client('198.51.100.170'))->post('/atelier/login', Stack::credentials($user))->status);
    }

    /** Viertes Review von PR #1 */
    public function testViertesReview(): void
    {
        // Verkauf per Webhook schließt die offene Sitzung eines zweiten Käufers
        $slug = Db::one("SELECT slug FROM werke WHERE status = 'available' AND preis_cent IS NOT NULL AND reserviert_session IS NULL AND aktiv = 1 AND sichtbar = 1 ORDER BY id LIMIT 1")['slug'];
        $this->assertSame(200, self::buy($slug)->status);
        $w = self::werk($slug);
        $sidA = $w['reserviert_session'];
        Db::run('UPDATE werke SET reserviert_bis = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 5), $w['id']]); // A ist über der Zeit
        $this->assertSame(200, self::buy($slug)->status);
        $sidB = self::werk($slug)['reserviert_session'];
        $this->assertNotSame($sidA, $sidB);
        Stack::webhook('checkout.session.completed', ['id' => $sidA, 'status' => 'complete', 'payment_status' => 'paid', 'metadata' => ['werk_id' => (string) $w['id']]]);
        $this->assertSame('sold', self::werk($slug)['status']);
        $this->assertSame('expired', Stack::stripeSessions()[$sidB]['status'], 'Sitzung von B geschlossen');
        $this->assertSame('abgelaufen', Db::one('SELECT status FROM bestellungen WHERE stripe_session_id = ?', [$sidB])['status']);

        // Anfrage wird dem Werk zugeordnet, auch mit „Titel (slug)“ aus dem Formular
        (new Client('192.0.2.90'))->post('/anfrage', ['anliegen' => 'preis', 'werk' => 'NACHT (nacht)', 'name' => 'Ina', 'email' => 'ina@example.test', 'nachricht' => 'Preis?', 'datenschutz' => 'ja'], ['Accept: application/json']);
        $this->assertSame('nacht', Db::one('SELECT werk_slug FROM anfragen ORDER BY id DESC LIMIT 1')['werk_slug']);
        (new Client('192.0.2.91'))->post('/anfrage', ['anliegen' => 'preis', 'werk' => 'Unbekannt (gibt-es-nicht)', 'name' => 'Ina', 'email' => 'ina@example.test', 'nachricht' => 'Preis?', 'datenschutz' => 'ja'], ['Accept: application/json']);
        $this->assertNull(Db::one('SELECT werk_slug FROM anfragen ORDER BY id DESC LIMIT 1')['werk_slug']);

        [$c, $csrf] = self::login('192.0.2.92');
        // Riesige Abmessungen werden vor dem Entpacken abgelehnt
        $huge = Stack::$tmp . '/riesig.jpg';
        imagejpeg(imagecreatetruecolor(4500, 8), $huge, 10);
        $r = $c->post('/atelier/upload', ['full' => new \CURLFile($huge), 'sm' => new \CURLFile($huge), 'xs' => new \CURLFile($huge), 'csrf' => $csrf]);
        $this->assertSame(415, $r->status);
        $this->assertStringContainsString('2400 px', $r->body);

        // Neues Werk direkt als „Verkauft“ bekommt ein Verkaufsdatum
        $c->post('/atelier/speichern', ['csrf' => $csrf, 'id' => '', 'titel' => 'Schon verkauft', 'masse' => '50 x 40 cm', 'preis' => '900', 'status' => 'sold', 'sichtbar' => 'ja', 'bilder' => '[]']);
        $this->assertSame(gmdate('Y-m-d'), self::werk('schon-verkauft')['verkauft_am']);

        // Erfolgsmeldung nur aus festen Texten
        $page = $c->get('/atelier?ok=Bitte%20Passwort%20an%20boese%40example.de%20senden')->body;
        $this->assertStringNotContainsString('boese@example.de', $page);
        $this->assertStringNotContainsString('class="at-ok"', $page);
        $this->assertStringContainsString('Werk aus dem Shop entfernt.', $c->get('/atelier?ok=entfernt')->body);
    }

    /** Fünftes Review von PR #1 */
    public function testFuenftesReview(): void
    {
        // POST ohne Origin und Referer wird abgelehnt
        $noOrigin = (new Client('192.0.2.95'))->request('POST', '/anfrage', ['name' => 'A', 'email' => 'a@example.test', 'nachricht' => 'x', 'datenschutz' => 'ja']);
        $this->assertSame(403, $noOrigin->status);

        // Webhook ohne werk_id (z. B. ältere Sitzung) wird über den slug zugeordnet
        $slug = Db::one("SELECT slug FROM werke WHERE status = 'available' AND preis_cent IS NOT NULL AND reserviert_session IS NULL AND aktiv = 1 AND sichtbar = 1 ORDER BY id LIMIT 1")['slug'];
        Stack::webhook('checkout.session.completed', ['id' => 'cs_test_alt_ohne_werkid', 'status' => 'complete', 'payment_status' => 'paid', 'metadata' => ['product_id' => 'prod_ALT', 'slug' => $slug]]);
        $this->assertSame('sold', self::werk($slug)['status']);
        $this->assertSame('cs_test_alt_ohne_werkid', self::werk($slug)['verkauft_session']);

        [$c, $csrf] = self::login('192.0.2.96');
        // Versandpreis: Tausenderpunkt und Komma wie beim Preis, 0 erlaubt, zu groß sauber abgelehnt
        $w = self::werk('blick');
        $base = ['csrf' => $csrf, 'id' => $w['id'], 'titel' => $w['titel'], 'masse' => $w['masse'], 'preis' => '390', 'status' => 'available', 'status_geladen' => 'available', 'sichtbar' => 'ja', 'bilder' => '[]'];
        $this->assertSame(303, $c->post('/atelier/speichern', ['versand' => '1.234,50'] + $base)->status);
        $this->assertSame(123450, (int) self::werk('blick')['versand_cent']);
        $this->assertSame(303, $c->post('/atelier/speichern', ['versand' => '0'] + $base)->status);
        $this->assertSame(0, (int) self::werk('blick')['versand_cent']);
        $big = $c->post('/atelier/speichern', ['versand' => '99999999'] + $base);
        $this->assertSame(422, $big->status);
        $this->assertStringContainsString('Versandpreis ist ungültig', $big->body);

        // Werk inzwischen entfernt: kein Entwurf als „Neues Werk“, kein Duplikat
        $before = (int) Db::one('SELECT COUNT(*) AS n FROM werke')['n'];
        Db::run('UPDATE werke SET aktiv = 0 WHERE id = ?', [$w['id']]);
        $gone = $c->post('/atelier/speichern', ['titel' => 'Blick geändert'] + $base);
        $this->assertSame(303, $gone->status);
        $this->assertStringEndsWith('/atelier?ok=nicht-mehr-da', $gone->header('location'));
        $this->assertSame($before, (int) Db::one('SELECT COUNT(*) AS n FROM werke')['n']);
        Db::run('UPDATE werke SET aktiv = 1 WHERE id = ?', [$w['id']]);
    }

    /** Sechstes Review: Werk wird entfernt, während die Stripe-Sitzung gerade entsteht. */
    public function testWerkEntferntWaehrendSitzungEntsteht(): void
    {
        $w = Db::one("SELECT * FROM werke WHERE status = 'available' AND preis_cent IS NOT NULL AND reserviert_session IS NULL AND aktiv = 1 AND sichtbar = 1 ORDER BY id DESC LIMIT 1");
        $stripe = 'http://127.0.0.1:' . Stack::STRIPE_PORT;
        $cfg = fn (array $c) => file_get_contents("$stripe/__test/config", false, stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: application/json', 'content' => json_encode($c)]]));
        $cfg(['delay_create' => 1.5]);
        try {
            // Kauf startet (läuft im Hintergrund), Stripe braucht 1,5 s für die Sitzung …
            $mh = curl_multi_init();
            $ch = curl_init(Stack::base() . '/api/checkout');
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['slug' => $w['slug']]), CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Origin: ' . Stack::base(), 'X-Forwarded-For: 198.51.100.210']]);
            curl_multi_add_handle($mh, $ch);
            $deadline = microtime(true) + 0.6;
            do {
                curl_multi_exec($mh, $running);
                curl_multi_select($mh, 0.05);
            } while (microtime(true) < $deadline);
            $this->assertStringStartsWith('pending_', (string) self::werk($w['slug'])['reserviert_session'], 'Reservierung steht, Sitzung entsteht noch');

            // … und genau jetzt entfernt Matthias das Werk (wie /atelier/archivieren)
            Db::run('UPDATE werke SET aktiv = 0, reserviert_bis = NULL, reserviert_session = NULL WHERE id = ?', [$w['id']]);

            do {
                curl_multi_exec($mh, $running);
                curl_multi_select($mh, 0.1);
            } while ($running);
            $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $body = json_decode((string) curl_multi_getcontent($ch), true);
            curl_multi_remove_handle($mh, $ch);
            curl_multi_close($mh);
        } finally {
            $cfg(['delay_create' => 0]);
        }
        $this->assertSame(409, $status);
        $this->assertSame('vergeben', $body['error'] ?? null);
        $sessions = Stack::stripeSessions();
        $last = end($sessions);
        $this->assertSame($w['slug'], $last['metadata']['slug']);
        $this->assertSame('expired', $last['status'], 'neue Sitzung sofort geschlossen');
        $this->assertSame('abgelaufen', Db::one('SELECT status FROM bestellungen WHERE stripe_session_id = ?', [$last['id']])['status']);
        $this->assertNull(self::werk($w['slug'])['reserviert_session']);
        Db::run('UPDATE werke SET aktiv = 1 WHERE id = ?', [$w['id']]);
    }

    /** Siebtes Review von PR #1 */
    public function testSiebtesReview(): void
    {
        // Import mit --update setzt verkaufte Werke nicht wieder auf verfügbar
        Db::run("UPDATE werke SET status = 'sold', verkauft_session = 'cs_test_import' WHERE slug = 'unsere-zeit'");
        Stack::php('bin/import-catalog.php', ['--update']);
        $w = self::werk('unsere-zeit');
        $this->assertSame('sold', $w['status']);
        $this->assertSame('cs_test_import', $w['verkauft_session']);

        // Benutzer unter Beschuss: jede IP nur noch 3 Versuche für ihn, richtige Anmeldung von neuer IP klappt
        $user = Stack::createUser();
        for ($i = 0; $i < 6; $i++) {
            (new Client('198.51.100.' . (180 + $i)))->post('/atelier/login', ['benutzer' => $user[0], 'passwort' => 'falsch', 'code' => '000000']);
        }
        $ip = new Client('198.51.100.190');
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(401, $ip->post('/atelier/login', ['benutzer' => $user[0], 'passwort' => 'falsch', 'code' => '000000'])->status);
        }
        $this->assertSame(429, $ip->post('/atelier/login', ['benutzer' => $user[0], 'passwort' => 'falsch', 'code' => '000000'])->status);
        $this->assertSame(303, (new Client('198.51.100.191'))->post('/atelier/login', Stack::credentials($user))->status);
    }

    /** Achtes Review von PR #1 */
    public function testAchtesReview(): void
    {
        $free = fn (int $n) => array_column(Db::all("SELECT slug FROM werke WHERE status = 'available' AND preis_cent IS NOT NULL AND reserviert_session IS NULL AND aktiv = 1 AND sichtbar = 1 ORDER BY id LIMIT $n"), 'slug');
        $stripeCfg = fn (array $c) => file_get_contents('http://127.0.0.1:' . Stack::STRIPE_PORT . '/__test/config', false, stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: application/json', 'content' => json_encode($c)]]));
        [$c, $csrf] = self::login('192.0.2.120');
        [$a, $b] = $free(2);

        // Ausblenden während eines Bezahlvorgangs beendet ihn
        self::buy($a);
        $wa = self::werk($a);
        $r = $c->post('/atelier/speichern', ['csrf' => $csrf, 'id' => $wa['id'], 'titel' => $wa['titel'], 'masse' => $wa['masse'], 'preis' => (string) ($wa['preis_cent'] / 100), 'status' => 'available', 'status_geladen' => 'reserved', 'sichtbar' => 'nein', 'bilder' => '[]']);
        $this->assertStringEndsWith('?ok=bezahlvorgang-beendet', $r->header('location'));
        $this->assertSame('expired', Stack::stripeSessions()[$wa['reserviert_session']]['status']);
        $this->assertNull(self::werk($a)['reserviert_session']);

        // Preisänderung während eines Bezahlvorgangs beendet ihn
        self::buy($b);
        $wb = self::werk($b);
        $c->post('/atelier/speichern', ['csrf' => $csrf, 'id' => $wb['id'], 'titel' => $wb['titel'], 'masse' => $wb['masse'], 'preis' => (string) ($wb['preis_cent'] / 100 + 100), 'status' => 'available', 'status_geladen' => 'reserved', 'sichtbar' => 'ja', 'bilder' => '[]']);
        $this->assertSame('expired', Stack::stripeSessions()[$wb['reserviert_session']]['status']);
        // nur Text geändert: Bezahlvorgang läuft weiter
        self::buy($b);
        $wb2 = self::werk($b);
        $c->post('/atelier/speichern', ['csrf' => $csrf, 'id' => $wb2['id'], 'titel' => $wb2['titel'] . ' ', 'masse' => $wb2['masse'], 'preis' => (string) ($wb2['preis_cent'] / 100), 'status' => 'available', 'status_geladen' => 'reserved', 'sichtbar' => 'ja', 'bilder' => '[]']);
        $this->assertSame('open', Stack::stripeSessions()[$wb2['reserviert_session']]['status']);

        // Stripe-Ausfall verbraucht keine Checkout-Plätze
        [$d] = $free(1);
        $ip = new Client('203.0.113.140');
        $stripeCfg(['fail_create' => true]);
        try {
            for ($i = 0; $i < 4; $i++) {
                $this->assertSame(500, self::buy($d, $ip)->status);
            }
        } finally {
            $stripeCfg(['fail_create' => false]);
        }
        $ok = self::buy($d, $ip);
        $this->assertSame(200, $ok->status, 'nach dem Ausfall nicht „zuviele“');

        // Gutschein über den vollen Betrag: Bestellung gilt als bezahlt
        $sid = self::werk($d)['reserviert_session'];
        Stack::webhook('checkout.session.completed', ['id' => $sid, 'status' => 'complete', 'payment_status' => 'no_payment_required', 'amount_total' => 0, 'metadata' => ['werk_id' => (string) self::werk($d)['id']]]);
        $this->assertSame('bezahlt', Db::one('SELECT status FROM bestellungen WHERE stripe_session_id = ?', [$sid])['status']);

        // Zu große Fotos werden vor dem Entpacken abgelehnt
        $big = Stack::$tmp . '/gross.jpg';
        imagejpeg(imagecreatetruecolor(2500, 10), $big, 10);
        $up = $c->post('/atelier/upload', ['full' => new \CURLFile($big), 'sm' => new \CURLFile($big), 'xs' => new \CURLFile($big), 'csrf' => $csrf]);
        $this->assertSame(415, $up->status);

        // Cron ohne nutzbares RUN_DIR bricht mit Fehler ab statt still
        $notADir = Stack::$tmp . '/kein-ordner';
        file_put_contents($notADir, 'x');
        try {
            Stack::php('bin/cron.php', [], ['RUN_DIR' => $notADir]);
            $this->fail('Cron hätte mit Fehler enden müssen');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Sperrdatei', $e->getMessage());
        }
    }

    /** Neuntes Review von PR #1 */
    public function testNeuntesReview(): void
    {
        $free = fn (int $n) => array_column(Db::all("SELECT slug FROM werke WHERE status = 'available' AND preis_cent IS NOT NULL AND reserviert_session IS NULL AND aktiv = 1 AND sichtbar = 1 ORDER BY id DESC LIMIT $n"), 'slug');
        $stripeCfg = fn (array $c) => file_get_contents('http://127.0.0.1:' . Stack::STRIPE_PORT . '/__test/config', false, stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: application/json', 'content' => json_encode($c)]]));
        [$a, $b, $d] = $free(3);

        // Preis ändert sich, während die Stripe-Sitzung entsteht: Sitzung wird geschlossen, „vergeben“
        $wa = self::werk($a);
        $stripeCfg(['delay_create' => 1.5]);
        try {
            $mh = curl_multi_init();
            $ch = curl_init(Stack::base() . '/api/checkout');
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['slug' => $a]), CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Origin: ' . Stack::base(), 'X-Forwarded-For: 198.51.100.220']]);
            curl_multi_add_handle($mh, $ch);
            $until = microtime(true) + 0.6;
            do {
                curl_multi_exec($mh, $running);
                curl_multi_select($mh, 0.05);
            } while (microtime(true) < $until);
            Db::run('UPDATE werke SET preis_cent = preis_cent + 50000 WHERE id = ?', [$wa['id']]);
            do {
                curl_multi_exec($mh, $running);
                curl_multi_select($mh, 0.1);
            } while ($running);
            $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_multi_close($mh);
        } finally {
            $stripeCfg(['delay_create' => 0]);
        }
        $this->assertSame(409, $status);
        $sessions = Stack::stripeSessions();
        $this->assertSame('expired', end($sessions)['status']);
        $this->assertNull(self::werk($a)['reserviert_session'], 'vorläufige Reservierung wieder frei (12. Review)');

        // Atelier speichert neuen Preis bei einer noch entstehenden Sitzung: Reservierung wird aufgehoben
        [$c, $csrf] = self::login('192.0.2.130');
        $wb = self::werk($b);
        Db::run("UPDATE werke SET reserviert_session = 'pending_test', reserviert_bis = ? WHERE id = ?", [gmdate('Y-m-d H:i:s', time() + 600), $wb['id']]);
        $c->post('/atelier/speichern', ['csrf' => $csrf, 'id' => $wb['id'], 'titel' => $wb['titel'], 'masse' => $wb['masse'], 'preis' => (string) ($wb['preis_cent'] / 100 + 10), 'status' => 'available', 'status_geladen' => 'reserved', 'sichtbar' => 'ja', 'bilder' => '[]']);
        $this->assertNull(self::werk($b)['reserviert_session']);

        // Cookie des Bezahlvorgangs hält so lange wie die Reservierung
        $r = self::buy($d);
        $this->assertMatchesRegularExpression('/mh_co=cs_[^;]+;.*Max-Age=2700/i', implode("\n", $r->headers['set-cookie'] ?? []));

        // import --update ändert den Preis eines gerade reservierten Werks nicht
        Db::run('UPDATE werke SET preis_cent = 777700 WHERE slug = ?', [$d]);
        $out = Stack::php('bin/import-catalog.php', ['--update']);
        $this->assertStringContainsString("$d ist gerade reserviert", $out);
        $this->assertSame(777700, (int) self::werk($d)['preis_cent']);

        // Erfolgreiche Anmeldung hebt die Bremse je Benutzer und IP auf
        $user = Stack::createUser();
        for ($i = 0; $i < 6; $i++) {
            (new Client('198.51.100.' . (230 + $i)))->post('/atelier/login', ['benutzer' => $user[0], 'passwort' => 'falsch', 'code' => '000000']);
        }
        $me = new Client('198.51.100.240');
        $bad = ['benutzer' => $user[0], 'passwort' => 'falsch', 'code' => '000000'];
        $me->post('/atelier/login', $bad);
        $me->post('/atelier/login', $bad);
        $this->assertSame(303, $me->post('/atelier/login', Stack::credentials($user))->status);
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(401, $me->post('/atelier/login', $bad)->status, 'nach Erfolg wieder 3 Versuche');
        }
    }

    /** Zehntes Review von PR #1 */
    public function testZehntesReview(): void
    {
        $free = fn (int $n) => array_column(Db::all("SELECT slug FROM werke WHERE status = 'available' AND preis_cent IS NOT NULL AND reserviert_session IS NULL AND aktiv = 1 AND sichtbar = 1 ORDER BY id LIMIT $n"), 'slug');
        $stripeCfg = fn (array $c) => file_get_contents('http://127.0.0.1:' . Stack::STRIPE_PORT . '/__test/config', false, stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: application/json', 'content' => json_encode($c)]]));
        [$a, $b, $d] = $free(3);

        // Reservierung hält 5 Minuten länger als die Stripe-Sitzung
        self::buy($a);
        $wa = self::werk($a);
        $expiresAt = Stack::stripeSessions()[$wa['reserviert_session']]['expires_at'];
        $this->assertSame(gmdate('Y-m-d H:i:s', $expiresAt + 300), $wa['reserviert_bis']);

        // Webhook-Lauf, der hart abgebrochen ist (fertig = 0, alt): spätere Zustellung übernimmt
        Db::run("INSERT INTO webhook_events (event_id, typ, fertig, verarbeitet) VALUES ('evt_haengt', 'checkout.session.completed', 0, UTC_TIMESTAMP() - INTERVAL 5 MINUTE)");
        $r = Stack::webhook('checkout.session.completed', ['id' => $wa['reserviert_session'], 'status' => 'complete', 'payment_status' => 'paid', 'metadata' => ['werk_id' => (string) $wa['id']]], eventId: 'evt_haengt');
        $this->assertSame(200, $r->status);
        $this->assertSame('sold', self::werk($a)['status']);
        $this->assertSame(1, (int) Db::one("SELECT fertig FROM webhook_events WHERE event_id = 'evt_haengt'")['fertig']);
        // gerade in Arbeit (frisch): andere Zustellung wartet
        Db::run("INSERT INTO webhook_events (event_id, typ, fertig) VALUES ('evt_laeuft', 'checkout.session.completed', 0)");
        $this->assertSame(409, Stack::webhook('checkout.session.expired', ['id' => 'cs_x', 'metadata' => []], eventId: 'evt_laeuft')->status);
        // fertiges Ereignis: Duplikat
        $this->assertTrue(Stack::webhook('checkout.session.completed', ['id' => 'cs_x', 'metadata' => []], eventId: 'evt_haengt')->json()['duplicate'] ?? false);

        // Schließen bei Stripe scheitert und die Sitzung ist noch offen: Reservierung bleibt (17. Review), kein „wieder frei“-Hinweis
        $buyer = new Client('198.51.100.250');
        self::buy($b, $buyer);
        $sid = self::werk($b)['reserviert_session'];
        $stripeCfg(['fail_expire' => true]);
        try {
            $loc = (string) $buyer->get("/api/cancel?session_id=$sid&slug=$b")->header('location');
        } finally {
            $stripeCfg(['fail_expire' => false]);
        }
        $this->assertSame($sid, self::werk($b)['reserviert_session']);
        $this->assertStringEndsWith("/werk/$b", $loc);
        \App\Catalog::release((int) self::werk($b)['id'], $sid); // für die folgenden Tests freigeben

        // Position 0 bleibt ganz vorn
        [$c, $csrf] = self::login('192.0.2.140');
        $wd = self::werk($d);
        $c->post('/atelier/speichern', ['csrf' => $csrf, 'id' => $wd['id'], 'titel' => $wd['titel'], 'masse' => $wd['masse'], 'preis' => (string) ($wd['preis_cent'] / 100), 'status' => 'available', 'status_geladen' => 'available', 'sichtbar' => 'ja', 'position' => '0', 'bilder' => '[]']);
        $this->assertSame(0, (int) self::werk($d)['position']);

        // Datenschutzerklärung nennt die tatsächliche Cookie-Dauer
        $this->assertStringContainsString('höchstens 45 Minuten', (new Client())->get('/datenschutz')->body);
    }

    /** Elftes Review von PR #1 */
    public function testElftesReview(): void
    {
        [$c, $csrf] = self::login('192.0.2.150');

        // Geänderte Maße (andere Versandklasse) beenden einen laufenden Bezahlvorgang
        $slug = Db::one("SELECT slug FROM werke WHERE status = 'available' AND preis_cent IS NOT NULL AND reserviert_session IS NULL AND aktiv = 1 AND sichtbar = 1 AND versand_cent IS NULL AND masse LIKE '%x%' ORDER BY id LIMIT 1")['slug'];
        self::buy($slug);
        $w = self::werk($slug);
        $r = $c->post('/atelier/speichern', ['csrf' => $csrf, 'id' => $w['id'], 'titel' => $w['titel'], 'masse' => '150 x 120 cm', 'preis' => (string) ($w['preis_cent'] / 100), 'status' => 'available', 'status_geladen' => 'reserved', 'sichtbar' => 'ja', 'bilder' => '[]']);
        $this->assertStringEndsWith('?ok=bezahlvorgang-beendet', (string) $r->header('location'));
        $this->assertSame('expired', Stack::stripeSessions()[$w['reserviert_session']]['status']);

        // Zu großer Upload: verständliche Meldung statt „Forbidden“
        $big = Stack::$tmp . '/zu-gross.bin';
        file_put_contents($big, "\xFF\xD8" . random_bytes(3 * 1024 * 1024));
        $up = $c->post('/atelier/upload', ['full' => new \CURLFile($big), 'sm' => new \CURLFile($big), 'xs' => new \CURLFile($big), 'csrf' => $csrf]);
        $this->assertSame(413, $up->status);
        $this->assertStringContainsString('Foto zu groß', $up->json()['error'] ?? '');
    }

    /** Zwölftes Review von PR #1 */
    public function testZwoelftesReview(): void
    {
        // slug als Liste statt Text: sauber abgelehnt, keine PHP-Warnung
        $r = (new Client('198.51.100.96'))->postJson('/api/checkout', ['slug' => [1]]);
        $this->assertSame(400, $r->status);

        // Erstattungshinweis bleibt, auch wenn das Werk wieder auf „Verfügbar“ gestellt wird
        [$c, $csrf] = self::login('192.0.2.160');
        $w = self::werk('frei');
        Db::run("UPDATE werke SET status = 'sold', verkauft_session = 'cs_a', doppelverkauf_session = 'cs_test_zweitzahlung' WHERE id = ?", [$w['id']]);
        $c->post('/atelier/speichern', ['csrf' => $csrf, 'id' => $w['id'], 'titel' => $w['titel'], 'masse' => $w['masse'], 'status' => 'available', 'status_geladen' => 'sold', 'sichtbar' => 'ja', 'bilder' => '[]']);
        $after = self::werk('frei');
        $this->assertSame('available', $after['status']);
        $this->assertSame('cs_test_zweitzahlung', $after['doppelverkauf_session']);
        $this->assertStringContainsString('zweite Zahlung eingegangen', $c->get('/atelier/werk/' . $w['id'])->body);
    }

    /** Vierzehntes Review von PR #1 */
    public function testVierzehntesReview(): void
    {
        $slug = Db::one("SELECT slug FROM werke WHERE status = 'available' AND preis_cent IS NOT NULL AND reserviert_session IS NULL AND aktiv = 1 AND sichtbar = 1 ORDER BY id LIMIT 1")['slug'];

        // Abbruch ohne passendes Cookie: kein „wieder frei“-Hinweis, denn es wurde nichts freigegeben
        self::buy($slug);
        $sid = self::werk($slug)['reserviert_session'];
        $loc = (string) (new Client('198.51.100.120'))->get("/api/cancel?session_id=$sid&slug=$slug")->header('location');
        $this->assertStringEndsWith("/werk/$slug", $loc);
        $this->assertNotNull(self::werk($slug)['reserviert_session']);

        // Danke-Seite im fremden Browser: Zahlungsstatus stimmt („bezahlt“), keine Conversion (auch nicht mit Google-Ads-Konfiguration)
        Stack::updateStripeSession($sid, ['status' => 'complete', 'payment_status' => 'paid']);
        $html = \App\View\Render::thanks(['status' => 'complete', 'payment_status' => 'paid', 'metadata' => []], null, false);
        $this->assertStringNotContainsString('noch verarbeitet', $html);
        putenv('GOOGLE_ADS_CONVERSION=AW-1/abc');
        \App\Config::reset();
        try {
            $this->assertStringNotContainsString('mh-conversion', \App\View\Render::thanks(['id' => $sid, 'status' => 'complete', 'payment_status' => 'paid', 'amount_total' => 100, 'metadata' => []], null, false));
            $this->assertStringContainsString('mh-conversion', \App\View\Render::thanks(['id' => $sid, 'status' => 'complete', 'payment_status' => 'paid', 'amount_total' => 100, 'metadata' => []], null, true));
        } finally {
            putenv('GOOGLE_ADS_CONVERSION=');
            \App\Config::reset();
        }

        // Widerruf ohne Herkunftsangabe: Formular mit E-Mail-Weg statt nackter „Forbidden“-Seite
        $w = (new Client('198.51.100.121'))->request('POST', '/vertrag-widerrufen', ['name' => 'A', 'vertrag' => 'B', 'email' => 'a@example.test']);
        $this->assertSame(403, $w->status);
        $this->assertStringContainsString('per E-Mail an', $w->body);

        // Verwaiste Fotos (älter als ein Tag, keinem Werk zugeordnet) räumt der Cron weg, verwendete bleiben
        $dir = Stack::env()['UPLOAD_DIR'];
        $orphan = 'aaaaaaaa-0000-0000-0000-' . bin2hex(random_bytes(6));
        $kept = 'bbbbbbbb-0000-0000-0000-' . bin2hex(random_bytes(6));
        foreach (["$orphan.jpg", "$orphan-sm.webp", "$kept.jpg"] as $f) {
            touch("$dir/$f", time() - 8 * 86400);
        }
        Db::run("INSERT INTO werk_bilder (werk_id, pfad, rolle) VALUES (?, '/bilder/$kept.jpg', 'detail')", [self::werk($slug)['id']]);
        Db::run("DELETE FROM merker WHERE name = 'cron_taeglich'");
        Stack::php('bin/cron.php');
        $this->assertFileDoesNotExist("$dir/$orphan.jpg");
        $this->assertFileDoesNotExist("$dir/$orphan-sm.webp");
        $this->assertFileExists("$dir/$kept.jpg");
        $this->assertFileExists("$dir/.htaccess");
    }

    /** Fünfzehntes Review von PR #1 */
    public function testFuenfzehntesReview(): void
    {
        $free = array_column(Db::all("SELECT slug FROM werke WHERE status = 'available' AND preis_cent IS NOT NULL AND reserviert_session IS NULL AND aktiv = 1 AND sichtbar = 1 ORDER BY id DESC LIMIT 3"), 'slug');
        [$a, $b, $d] = $free;
        $buyer = new Client('198.51.100.130');

        // Zwei Käufe in zwei Tabs: beide gehören diesem Browser
        $urlA = self::buy($a, $buyer)->json()['url'];
        self::buy($b, $buyer);
        $status = $buyer->get('/api/status')->json();
        $this->assertContains($a, $status['mine']);
        $this->assertContains($b, $status['mine']);
        $this->assertNotContains($a, (new Client('198.51.100.131'))->get('/api/status')->json()['mine'], 'fremder Browser');
        // Werkseite hat den Knopf „Zurück zur begonnenen Zahlung“ (wird per JS eingeblendet)
        $this->assertStringContainsString('data-resume', (new Client())->get("/werk/$a")->body);
        // Zurück zur Zahlung von A, obwohl danach B begonnen wurde
        $this->assertSame($urlA, self::buy($a, $buyer)->json()['url']);
        // Abbruch von A gibt A frei, B bleibt reserviert und dem Browser zugeordnet
        $sidA = self::werk($a)['reserviert_session'];
        $buyer->get("/api/cancel?session_id=$sidA&slug=$a");
        $this->assertNull(self::werk($a)['reserviert_session']);
        $this->assertNotNull(self::werk($b)['reserviert_session']);
        $this->assertSame([$b], $buyer->get('/api/status')->json()['mine']);

        // Abbruch, während die Zahlung gerade abgeschlossen wurde: nicht freigeben
        $stripeCfg = fn (array $c) => file_get_contents('http://127.0.0.1:' . Stack::STRIPE_PORT . '/__test/config', false, stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: application/json', 'content' => json_encode($c)]]));
        $payer = new Client('198.51.100.132');
        self::buy($d, $payer);
        $sidD = self::werk($d)['reserviert_session'];
        // Stripe meldet beim ersten Lesen „open“; beim Schließen ist gerade bezahlt worden (Schließen scheitert)
        $stripeCfg(['fail_expire' => 'complete']);
        try {
            $payer->get("/api/cancel?session_id=$sidD&slug=$d");
        } finally {
            $stripeCfg(['fail_expire' => false]);
        }
        $this->assertSame($sidD, self::werk($d)['reserviert_session'], 'abgeschlossene Zahlung: Reservierung bleibt bis zum Webhook');
    }

    /** Sechzehntes Review von PR #1 */
    public function testSechzehntesReview(): void
    {
        $free = array_column(Db::all("SELECT slug FROM werke WHERE status = 'available' AND preis_cent IS NOT NULL AND reserviert_session IS NULL AND aktiv = 1 AND sichtbar = 1 ORDER BY id LIMIT 2"), 'slug');
        [$a, $b] = $free;

        // „completed“ kommt erst nach „Zahlung gescheitert“ an: Werk wird nicht als verkauft markiert
        self::buy($a);
        $w = self::werk($a);
        $obj = ['id' => $w['reserviert_session'], 'status' => 'complete', 'payment_status' => 'unpaid', 'metadata' => ['werk_id' => (string) $w['id']]];
        Stack::webhook('checkout.session.async_payment_failed', $obj);
        $this->assertSame('fehlgeschlagen', Db::one('SELECT status FROM bestellungen WHERE stripe_session_id = ?', [$obj['id']])['status']);
        Stack::webhook('checkout.session.completed', $obj);
        $this->assertSame('available', self::werk($a)['status']);

        // Eigene, bei Stripe schon abgelaufene Sitzung: neuer Kauf klappt sofort
        $buyer = new Client('198.51.100.140');
        self::buy($b, $buyer);
        $old = self::werk($b)['reserviert_session'];
        Stack::updateStripeSession($old, ['status' => 'expired']);
        $again = self::buy($b, $buyer);
        $this->assertSame(200, $again->status);
        $this->assertNotSame($old, self::werk($b)['reserviert_session']);

        // Anfrage zu einem Werk mit langem slug wird trotzdem zugeordnet
        $long = str_repeat('langer-titel-', 8) . 'ende';
        Db::run("INSERT INTO werke (slug, titel, masse) VALUES (?, 'Langer Titel', '')", [$long]);
        (new Client('192.0.2.170'))->post('/anfrage', ['anliegen' => 'frage', 'werk' => str_repeat('LANGER TITEL ', 9) . "($long)", 'name' => 'Ina', 'email' => 'ina@example.test', 'nachricht' => 'Frage', 'datenschutz' => 'ja'], ['Accept: application/json']);
        $this->assertSame($long, Db::one('SELECT werk_slug FROM anfragen ORDER BY id DESC LIMIT 1')['werk_slug']);

        // Gutschein über den vollen Betrag: Danke-Seite meldet „bestätigt“
        $this->assertStringContainsString('Die Zahlung ist bestätigt.', \App\View\Render::thanks(['status' => 'complete', 'payment_status' => 'no_payment_required', 'metadata' => []], null, false));
    }

    /** Siebzehntes Review von PR #1 */
    public function testSiebzehntesReview(): void
    {
        $free = array_column(Db::all("SELECT slug FROM werke WHERE status = 'available' AND preis_cent IS NOT NULL AND reserviert_session IS NULL AND aktiv = 1 AND sichtbar = 1 ORDER BY id LIMIT 2"), 'slug');
        [$a, $b] = $free;
        $stripeCfg = fn (array $c) => file_get_contents('http://127.0.0.1:' . Stack::STRIPE_PORT . '/__test/config', false, stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: application/json', 'content' => json_encode($c)]]));

        // Eigener Kauf schon bezahlt (Webhook noch unterwegs), erneuter Klick: Danke-Seite statt „vergeben“
        $buyer = new Client('198.51.100.150');
        self::buy($a, $buyer);
        $sid = self::werk($a)['reserviert_session'];
        Stack::updateStripeSession($sid, ['status' => 'complete', 'payment_status' => 'paid']);
        $again = self::buy($a, $buyer);
        $this->assertSame(200, $again->status);
        $this->assertStringEndsWith('/danke?session_id=' . $sid, $again->json()['url'] ?? '');

        // Atelier: Preis ändern, Schließen bei Stripe scheitert → Reservierung bleibt, ehrlicher Hinweis
        [$c, $csrf] = self::login('192.0.2.180');
        self::buy($b);
        $w = self::werk($b);
        $stripeCfg(['fail_expire' => true]);
        try {
            $r = $c->post('/atelier/speichern', ['csrf' => $csrf, 'id' => $w['id'], 'titel' => $w['titel'], 'masse' => $w['masse'], 'preis' => (string) ($w['preis_cent'] / 100 + 5), 'status' => 'available', 'status_geladen' => 'reserved', 'sichtbar' => 'ja', 'bilder' => '[]']);
        } finally {
            $stripeCfg(['fail_expire' => false]);
        }
        $this->assertStringEndsWith('?ok=bezahlvorgang-laeuft', (string) $r->header('location'));
        $this->assertSame($w['reserviert_session'], self::werk($b)['reserviert_session']);

        // Foto, das nicht (mehr) existiert, wird beim Speichern gemeldet statt kaputt verlinkt
        $missing = $c->post('/atelier/speichern', ['csrf' => $csrf, 'id' => '', 'titel' => 'Ohne Datei', 'masse' => '30 x 30 cm', 'status' => 'available', 'sichtbar' => 'ja', 'bilder' => json_encode([['url' => '/bilder/cccccccc-0000-0000-0000-000000000000.jpg', 'role' => 'haupt']])]);
        $this->assertSame(422, $missing->status);
        $this->assertStringContainsString('nicht mehr vorhanden', $missing->body);
    }

    /** Achtzehntes Review von PR #1 */
    public function testAchtzehntesReview(): void
    {
        $free = array_column(Db::all("SELECT slug FROM werke WHERE status = 'available' AND preis_cent IS NOT NULL AND reserviert_session IS NULL AND aktiv = 1 AND sichtbar = 1 ORDER BY id LIMIT 3"), 'slug');
        [$a, $b, $d] = $free;
        $stripeCfg = fn (array $c) => file_get_contents('http://127.0.0.1:' . Stack::STRIPE_PORT . '/__test/config', false, stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: application/json', 'content' => json_encode($c)]]));
        [$c, $csrf] = self::login('192.0.2.190');

        // Entfernen, während ein Bezahlvorgang läuft und Stripe nicht schließen kann: Werk bleibt im Shop
        self::buy($a);
        $wa = self::werk($a);
        $stripeCfg(['fail_expire' => true]);
        try {
            $r = $c->post('/atelier/archivieren', ['csrf' => $csrf, 'id' => $wa['id']]);
        } finally {
            $stripeCfg(['fail_expire' => false]);
        }
        $this->assertStringEndsWith("/atelier/werk/{$wa['id']}?ok=entfernen-bezahlvorgang", (string) $r->header('location'));
        $this->assertSame(1, (int) self::werk($a)['aktiv']);

        // Von Hand „Verkauft“, Stripe kann nicht schließen: Warnung statt „Gespeichert“
        self::buy($b);
        $wb = self::werk($b);
        $stripeCfg(['fail_expire' => true]);
        try {
            $r = $c->post('/atelier/speichern', ['csrf' => $csrf, 'id' => $wb['id'], 'titel' => $wb['titel'], 'masse' => $wb['masse'], 'preis' => (string) ($wb['preis_cent'] / 100), 'status' => 'sold', 'status_geladen' => 'reserved', 'sichtbar' => 'ja', 'bilder' => '[]']);
        } finally {
            $stripeCfg(['fail_expire' => false]);
        }
        $this->assertStringEndsWith('?ok=verkauft-zahlung-laeuft', (string) $r->header('location'));

        // Eigene Reservierung, Stripe kurz nicht erreichbar: „fehler“ (erneut versuchen) statt „vergeben“
        $buyer = new Client('198.51.100.160');
        self::buy($d, $buyer);
        $stripeCfg(['fail_retrieve' => true]);
        try {
            $again = self::buy($d, $buyer);
        } finally {
            $stripeCfg(['fail_retrieve' => false]);
        }
        $this->assertSame(503, $again->status);
        $this->assertSame('fehler', $again->json()['error'] ?? null);

        // Datei über upload_max_filesize: „zu groß“ statt „Datei fehlt“
        $f = Stack::$tmp . '/ueber-limit.jpg';
        file_put_contents($f, "\xFF\xD8" . random_bytes(1200 * 1024));
        $small = Stack::$tmp . '/klein.jpg';
        imagejpeg(imagecreatetruecolor(20, 20), $small);
        $up = $c->post('/atelier/upload', ['full' => new \CURLFile($f), 'sm' => new \CURLFile($small), 'xs' => new \CURLFile($small), 'csrf' => $csrf]);
        $this->assertSame(413, $up->status);
        $this->assertStringContainsString('zu groß', $up->json()['error'] ?? '');

        // Aufräumen: die benutzten Werke wieder frei, damit späteren Tests genug freie Werke bleiben
        Db::run("UPDATE werke SET status = 'available', verkauft_am = NULL, verkauft_session = NULL, doppelverkauf_session = NULL, reserviert_bis = NULL, reserviert_session = NULL WHERE slug IN (?, ?, ?)", [$a, $b, $d]);
    }

    /** Neunzehntes Review von PR #1 */
    public function testNeunzehntesReview(): void
    {
        [$c, $csrf] = self::login('192.0.2.200');
        $slugs = array_column(Db::all("SELECT slug FROM werke WHERE status = 'available' AND preis_cent IS NOT NULL AND reserviert_session IS NULL AND aktiv = 1 AND sichtbar = 1 ORDER BY id DESC LIMIT 2"), 'slug');

        // Entfernen bei entstehender Sitzung (pending_): wird entfernt, der Checkout merkt es selbst
        $w = self::werk($slugs[0]);
        Db::run("UPDATE werke SET reserviert_session = 'pending_test19', reserviert_bis = ? WHERE id = ?", [gmdate('Y-m-d H:i:s', time() + 600), $w['id']]);
        $this->assertStringEndsWith('/atelier?ok=entfernt', (string) $c->post('/atelier/archivieren', ['csrf' => $csrf, 'id' => $w['id']])->header('location'));
        $this->assertSame(0, (int) self::werk($slugs[0])['aktiv']);
        Db::run('UPDATE werke SET aktiv = 1, reserviert_session = NULL, reserviert_bis = NULL WHERE id = ?', [$w['id']]);

        // Speichern mit Eingabefehler, Werk inzwischen entfernt: kein Entwurf als „Neues Werk“
        $w2 = self::werk($slugs[1]);
        Db::run('UPDATE werke SET aktiv = 0 WHERE id = ?', [$w2['id']]);
        $r = $c->post('/atelier/speichern', ['csrf' => $csrf, 'id' => $w2['id'], 'titel' => $w2['titel'], 'masse' => $w2['masse'], 'preis' => 'abc', 'status' => 'available', 'status_geladen' => 'available', 'sichtbar' => 'ja', 'bilder' => '[]']);
        $this->assertStringEndsWith('/atelier?ok=nicht-mehr-da', (string) $r->header('location'));
        Db::run('UPDATE werke SET aktiv = 1 WHERE id = ?', [$w2['id']]);
    }

    public function testWiderrufWirdVerschluesseltGespeichert(): void
    {
        $c = new Client('192.0.2.20');
        $f = $c->get('/vertrag-widerrufen');
        $this->assertStringContainsString('>Widerruf bestätigen<', $f->body);
        $this->assertStringContainsString('vertrag-widerrufen', $c->get('/widerruf')->body);
        $this->assertSame(422, $c->post('/vertrag-widerrufen', ['name' => 'Erika Muster', 'vertrag' => 'NACHT', 'email' => 'kaputt'])->status);
        $this->assertSame(403, $c->post('/vertrag-widerrufen', ['name' => 'x'], ['Origin: https://boese.example'])->status);

        $post = $c->post('/vertrag-widerrufen', ['name' => 'Erika Muster', 'vertrag' => 'Werk „NACHT“, gekauft am 20.11.2026', 'email' => 'Erika@Example.test']);
        // Weiterleitung auf die Bestätigung (Neuladen schickt nichts doppelt)
        $this->assertSame(303, $post->status);
        $receipt = (string) $post->header('location');
        $this->assertMatchesRegularExpression('#^/vertrag-widerrufen\?ref=W-\d{8}-[0-9A-F]{6}&t=[0-9a-f]{32}$#', $receipt);
        $r = $c->get($receipt);
        $this->assertSame(200, $r->status);
        $count = (int) Db::one('SELECT COUNT(*) AS n FROM widerrufe')['n'];
        $this->assertSame(200, $c->get($receipt)->status, 'Neuladen');
        $this->assertSame($count, (int) Db::one('SELECT COUNT(*) AS n FROM widerrufe')['n'], 'kein zweiter Eintrag');
        $this->assertStringNotContainsString('Erika', (new Client())->get(preg_replace('/t=[0-9a-f]+/', 't=' . str_repeat('0', 32), $receipt))->body, 'falscher Prüfwert zeigt nichts');
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
        $before = (int) Db::one('SELECT COUNT(*) AS n FROM anfragen')['n'];
        $mailsBefore = count(array_filter(Stack::mails(), fn ($m) => str_starts_with($m['subject'], 'Preisanfrage')));
        $c = new Client('192.0.2.30');
        $r = $c->post('/anfrage', ['anliegen' => 'preis', 'werk' => 'nacht', 'seite' => Stack::base() . '/werk/nacht', 'name' => 'Max Test', 'email' => 'max@example.test', 'telefon' => '0123 456', 'nachricht' => 'Was kostet NACHT?', 'datenschutz' => 'ja'], ['Accept: application/json']);
        $this->assertSame(['ok' => true], $r->json());
        $this->assertSame(422, $c->post('/anfrage', ['name' => 'Max', 'email' => 'max@example.test', 'nachricht' => 'x'], ['Accept: application/json'])->status); // ohne Einwilligung
        $this->assertSame(['ok' => true], $c->post('/anfrage', ['bot-field' => 'spam', 'name' => 'Bot'], ['Accept: application/json'])->json());

        $row = Db::one('SELECT * FROM anfragen ORDER BY id DESC LIMIT 1');
        $this->assertSame('preis', $row['anliegen']);
        $this->assertSame('nacht', $row['werk_slug']);
        $this->assertStringNotContainsString('Max', $row['daten_enc']);
        $this->assertSame($before + 1, (int) Db::one('SELECT COUNT(*) AS n FROM anfragen')['n']); // Bot nicht gespeichert
        $m = array_values(array_filter(Stack::mails(), fn ($m) => str_starts_with($m['subject'], 'Preisanfrage')));
        $this->assertCount($mailsBefore + 1, $m, 'genau eine neue Meldung, keine für den Bot');
        $mine = array_values(array_filter($m, fn ($x) => str_contains($x['text'], 'Was kostet NACHT?')));
        $this->assertCount(1, $mine);
        $this->assertSame('max@example.test', $mine[0]['replyTo']);

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
            $this->assertSame(303, $w->post('/vertrag-widerrufen', ['name' => 'Max Test', 'vertrag' => 'Werk NACHT', 'email' => "opfer$i@example.test"])->status);
        }
        // 6. Widerruf von derselben IP: wird trotzdem gespeichert und gemeldet, nur keine Bestätigungsmail an die Adresse
        $count = (int) Db::one('SELECT COUNT(*) AS n FROM widerrufe')['n'];
        $blocked = $w->post('/vertrag-widerrufen', ['name' => 'Max Test', 'vertrag' => 'Werk NACHT', 'email' => 'opfer9@example.test']);
        $this->assertSame(303, $blocked->status);
        $this->assertSame($count + 1, (int) Db::one('SELECT COUNT(*) AS n FROM widerrufe')['n']);
        $this->assertCount(0, array_filter(Stack::mails(), fn ($m) => $m['to'] === 'opfer9@example.test'), 'keine Mail an die Adresse');
        $this->assertEmpty(array_filter(Stack::mails(), fn ($m) => $m['replyTo'] === 'opfer9@example.test'), 'über der Grenze auch keine Meldung (kein Fluten), steht im Atelier');
        // Weit über der Grenze (10-fach) wird abgelehnt, mit Hinweis auf E-Mail
        for ($i = 0; $i < 44; $i++) {
            $w->post('/vertrag-widerrufen', ['name' => 'Flut', 'vertrag' => 'X', 'email' => "flut$i@example.test"]);
        }
        $flood = $w->post('/vertrag-widerrufen', ['name' => 'Flut', 'vertrag' => 'X', 'email' => 'flut-ende@example.test']);
        $this->assertSame(429, $flood->status);
        $this->assertStringContainsString('per E-Mail an', $flood->body);

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

    public function testBenutzerSkriptZeigtQrCodeSchluesselUndNotfallcodes(): void
    {
        $out = Stack::php('bin/atelier-user.php', ['anlegen', 'cli-test', '--generate']);
        $this->assertStringContainsString('diesen QR-Code scannen', $out);
        $this->assertMatchesRegularExpression('/[█▀▄]{10,}/u', $out);
        $this->assertMatchesRegularExpression('/Schlüssel: ([A-Z2-7]{4} ){7}[A-Z2-7]{4}/u', $out);
        $this->assertSame(10, preg_match_all('/\b[A-Z2-9]{5}-[A-Z2-9]{5}\b/', $out));
        $this->assertStringContainsString('cli-test', Stack::php('bin/atelier-user.php', ['liste']));
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
