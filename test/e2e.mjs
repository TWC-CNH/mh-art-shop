// End-to-End-Test gegen einen lokalen Stripe-Ersatz:  node test/e2e.mjs
import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { fileURLToPath } from 'node:url';
const run = promisify(execFile);
import Stripe from 'stripe';
import { startFakeStripe } from './fake-stripe.mjs';

const PORT = 12111;
Object.assign(process.env, {
  ATELIER_PASSWORD: 'Pinsel-2026',
  RECORDS_LOCAL_DIR: fileURLToPath(new URL('../.test-records/', import.meta.url)),
  ATELIER_LOCAL_DIR: fileURLToPath(new URL('../.test-uploads/', import.meta.url)),
  STRIPE_SECRET_KEY: 'sk_test_fake',
  STRIPE_API_BASE: `http://localhost:${PORT}`,
  STRIPE_WEBHOOK_SECRET: 'whsec_test_secret',
  SITE_URL: 'https://shop.example.test',
  DATA_ENCRYPTION_KEY: 'a'.repeat(64),
});

const fake = await startFakeStripe(PORT);
const root = new URL('..', import.meta.url);
let ok = 0;
const step = async (name, fn) => { await fn(); ok++; console.log('✓', name); };

try {
  await step('Seed legt alle Werke in Stripe an', async () => {
    await run('node', ['scripts/seed-stripe.mjs'], { cwd: root, env: process.env });
    assert.equal(fake.products.size, 33);
    const cool = [...fake.products.values()].find((p) => p.metadata.slug === 'cool');
    assert.equal(fake.prices.get(cool.default_price).unit_amount, 340000);
    assert.equal(cool.images[0], 'https://shop.example.test/img/works/cool.jpg');
    const nacht = [...fake.products.values()].find((p) => p.metadata.slug === 'nacht');
    assert.equal(nacht.metadata.uv, '2');
    assert.match(nacht.images[1], /nacht-uv\.jpg$/);
    // zweiter Lauf: keine Dubletten
    await run('node', ['scripts/seed-stripe.mjs'], { cwd: root, env: process.env });
    assert.equal(fake.products.size, 33);
  });

  const site = (await import('../netlify/functions/site.mjs')).default;
  const checkout = (await import('../netlify/functions/checkout.mjs')).default;
  const cancel = (await import('../netlify/functions/cancel.mjs')).default;
  const webhook = (await import('../netlify/functions/stripe-webhook.mjs')).default;
  const { invalidateCatalog } = await import('../lib/catalog.mjs');
  const get = async (path) => { const r = await site(new Request('https://shop.example.test' + path)); return { status: r.status, text: await r.text(), headers: r.headers }; };
  // Jeder Kauf kommt von einer eigenen IP (sonst greift die Begrenzung je IP); feste IP für den Limit-Test.
  let ipN = 0;
  const buy = (slug, cookie = '', ip = `198.51.100.${++ipN}`) => checkout(new Request('https://shop.example.test/api/checkout', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'x-nf-client-connection-ip': ip, ...(cookie ? { cookie } : {}) }, body: JSON.stringify({ slug }) }));
  const prod = (slug) => [...fake.products.values()].find((p) => p.metadata.slug === slug);
  const signer = new Stripe('sk_test_fake');
  const hook = async (type, object, secret = 'whsec_test_secret') => {
    const payload = JSON.stringify({ id: 'evt_' + Math.random().toString(36).slice(2), object: 'event', type, data: { object } });
    const header = signer.webhooks.generateTestHeaderString({ payload, secret });
    return webhook(new Request('https://shop.example.test/api/stripe-webhook', { method: 'POST', headers: { 'stripe-signature': header }, body: payload }));
  };

  await step('Startseite zeigt Werke aus Stripe mit Preisen', async () => {
    const r = await get('/');
    assert.equal(r.status, 200);
    assert.match(r.text, /3\.400\s€/);
    assert.equal((r.text.match(/<article class="card/g) || []).length, 33);
    assert.match(r.text, /badge--sold/); // FREI ist verkauft
    assert.equal(r.headers.get('netlify-cache-tag'), 'catalog');
  });

  let sessionCookie = '';
  let firstUrl = '';
  await step('Checkout startet und reserviert das Werk', async () => {
    const r = await buy('cool');
    assert.equal(r.status, 200);
    firstUrl = (await r.json()).url;
    assert.match(firstUrl, /^https:\/\/checkout\.stripe\.test/);
    sessionCookie = r.headers.get('set-cookie').split(';')[0];
    const p = prod('cool');
    assert.ok(Number(p.metadata.reserved_until) > Date.now() / 1000 + 29 * 60);
    const s = fake.sessions.get(p.metadata.reserved_session);
    assert.equal(s.params.line_items[0].quantity, '1');
    assert.equal(s.params.shipping_address_collection.allowed_countries[0], 'DE');
    assert.equal(s.params.shipping_options[0].shipping_rate_data.fixed_amount.amount, '8900'); // großes Format
    assert.equal(s.params.shipping_options[1].shipping_rate_data.fixed_amount.amount, '0'); // Abholung
    assert.match(s.params.custom_text.submit.message, /§ 19 UStG/);
  });

  await step('Werkseite zeigt „reserviert“, zweiter Käufer bekommt „vergeben“', async () => {
    const page = await get('/werk/cool');
    assert.match(page.text, /Gerade reserviert/);
    const r = await buy('cool');
    assert.equal(r.status, 409);
    assert.equal((await r.json()).error, 'vergeben');
  });

  await step('Derselbe Käufer kommt per Cookie zurück in seine offene Sitzung', async () => {
    const r = await buy('cool', sessionCookie);
    assert.equal((await r.json()).url, firstUrl);
  });

  await step('Abbruch gibt das Werk sofort wieder frei', async () => {
    const sid = prod('cool').metadata.reserved_session;
    const r = await cancel(new Request(`https://shop.example.test/api/cancel?session_id=${sid}&slug=cool`));
    assert.equal(r.status, 303);
    assert.match(r.headers.get('location'), /\/werk\/cool\?abgebrochen=1$/);
    assert.equal(fake.sessions.get(sid).status, 'expired');
    assert.equal(prod('cool').metadata.reserved_until, undefined);
  });

  await step('Webhook mit falscher Signatur wird abgelehnt', async () => {
    const r = await hook('checkout.session.completed', { id: 'cs_x', metadata: { product_id: prod('cool').id } }, 'whsec_falsch');
    assert.equal(r.status, 400);
    assert.notEqual(prod('cool').metadata.status, 'sold');
  });

  let paidSession;
  await step('Bezahlung -> Werk ist verkauft und nicht mehr kaufbar', async () => {
    const r = await buy('cool');
    const sid = prod('cool').metadata.reserved_session;
    paidSession = { ...fake.sessions.get(sid) };
    assert.ok((await r.json()).url);
    const w = await hook('checkout.session.completed', { ...paidSession, status: 'complete', payment_status: 'paid' });
    assert.equal(w.status, 200);
    assert.equal(prod('cool').metadata.status, 'sold');
    assert.equal(prod('cool').metadata.sold_session, sid);
    invalidateCatalog();
    const page = await get('/werk/cool');
    assert.match(page.text, /Privatsammlung/);
    assert.doesNotMatch(page.text, /data-checkout/);
    const again = await buy('cool');
    assert.equal((await again.json()).error, 'vergeben');
  });

  await step('Abgelaufene Sitzung (Webhook) gibt Reservierung frei', async () => {
    await buy('nacht');
    const sid = prod('nacht').metadata.reserved_session;
    await hook('checkout.session.expired', { ...fake.sessions.get(sid), status: 'expired' });
    assert.equal(prod('nacht').metadata.reserved_session, undefined);
  });

  await step('Gescheiterte SEPA-Zahlung macht Werk wieder verfügbar', async () => {
    await buy('blick');
    const sid = prod('blick').metadata.reserved_session;
    const s = fake.sessions.get(sid);
    await hook('checkout.session.completed', { ...s, status: 'complete', payment_status: 'unpaid' });
    assert.equal(prod('blick').metadata.status, 'sold');
    await hook('checkout.session.async_payment_failed', { ...s });
    assert.equal(prod('blick').metadata.status, 'available');
  });

  await step('Werk „Preis auf Anfrage“ hat keinen Kauf-Button und lässt sich nicht kaufen', async () => {
    const page = await get('/werk/show');
    assert.match(page.text, /Preis anfragen/);
    assert.doesNotMatch(page.text, /data-checkout/);
    const r = await buy('show');
    assert.equal((await r.json()).error, 'vergeben');
  });

  await step('Danke-Seite zeigt das gekaufte Werk', async () => {
    fake.sessions.get(paidSession.id).customer_details = { name: 'Erika Mustermann', email: 'erika@example.test' };
    fake.sessions.get(paidSession.id).payment_status = 'paid';
    const r = await get(`/danke?session_id=${paidSession.id}`);
    assert.match(r.text, /Danke, Erika/);
    assert.match(r.text, /„COOL“ gehört jetzt Ihnen/);
    assert.equal(r.headers.get('cache-control'), 'no-store');
  });

  await step('Werkseite: Größenvergleich, WebP, strukturierte Daten', async () => {
    const t = (await get('/werk/nacht')).text;
    assert.match(t, /class="sizecmp"/);
    assert.match(t, /data-view="uv"/); // Schwarzlicht-Aufnahme aus Stripe (Bild 2, metadata.uv)
    assert.match(t, /nacht-uv/);
    assert.match(t, /Im Raum/);
    assert.match(t, /nacht-sm\.webp 720w/);
    assert.match(t, /"BreadcrumbList"/);
    assert.match(t, /"availability":"https:\/\/schema\.org\/(InStock|LimitedAvailability)"/);
  });

  await step('Live-Status-API meldet verkauft/reserviert', async () => {
    const statusFn = (await import('../netlify/functions/status.mjs')).default;
    invalidateCatalog();
    const r = await statusFn(new Request('https://shop.example.test/api/status'));
    const j = await r.json();
    assert.equal(j.status.cool, 'sold');
    assert.equal(j.status.frei, 'sold');
    assert.equal(j.status.nacht, 'available');
  });

  await step('Google-Shopping-Feed und Bild-Sitemap', async () => {
    const feed = (await get('/feed.xml')).text;
    assert.match(feed, /<g:id>nacht<\/g:id>/);
    assert.match(feed, /<g:price>3000\.00 EUR<\/g:price>/);
    assert.doesNotMatch(feed, /<g:id>cool<\/g:id>/); // verkauft
    assert.doesNotMatch(feed, /<g:id>show<\/g:id>/); // ohne Preis
    const sm = (await get('/sitemap.xml')).text;
    assert.match(sm, /<image:loc>https:\/\/shop\.example\.test\/img\/works\/nacht-uv\.jpg<\/image:loc>/);
  });

  await step('Gutscheincodes lassen sich zuschalten', async () => {
    process.env.ALLOW_PROMOTION_CODES = 'true';
    await buy('krieg');
    const s = fake.sessions.get(prod('krieg').metadata.reserved_session);
    assert.equal(s.params.allow_promotion_codes, 'true');
    delete process.env.ALLOW_PROMOTION_CODES;
  });

  await step('Anfrage-Formular und Merkliste sind eingebaut', async () => {
    const t = (await get('/werk/show')).text;
    assert.match(t, /data-inquiry="preis"/);
    assert.match(t, /name="anfrage"/);
    assert.match(t, /data-fav="show"/);
    assert.match(t, /data-share/);
  });

  await step('Rechtstexte, Sitemap, robots, 404', async () => {
    for (const p of ['/impressum', '/agb', '/widerruf', '/versand', '/datenschutz']) assert.equal((await get(p)).status, 200);
    assert.match((await get('/sitemap.xml')).text, /https:\/\/shop\.example\.test\/werk\/cool/);
    assert.match((await get('/robots.txt')).text, /Sitemap: https:\/\/shop\.example\.test\/sitemap\.xml/);
    assert.equal((await get('/werk/gibtsnicht')).status, 404);
    assert.match((await get('/impressum')).text, /Richard-Wagner-Straße 21/);
    assert.match((await get('/')).text, /<a href="https:\/\/www\.tobiaswolfrum\.de" target="_blank" rel="noopener">Tobias Wolfrum Consulting<\/a>/);
  });


  await step('Atelier: Login schützt die Verwaltung', async () => {
    const atelier = (await import('../netlify/functions/atelier.mjs')).default;
    const O = 'https://shop.example.test';
    const r0 = await atelier(new Request(O + '/atelier'));
    assert.match(await r0.text(), /name="passwort"/);
    const fdBad = new FormData(); fdBad.set('passwort', 'falsch');
    const r1 = await atelier(new Request(O + '/atelier/login', { method: 'POST', headers: { origin: O }, body: fdBad }));
    assert.match(await r1.text(), /Passwort stimmt nicht/);
    assert.equal(r1.headers.get('set-cookie'), null);
    const fdX = new FormData(); fdX.set('passwort', 'Pinsel-2026');
    assert.equal((await atelier(new Request(O + '/atelier/login', { method: 'POST', headers: { origin: 'https://boese.example' }, body: fdX }))).status, 403);
    const up0 = new FormData();
    assert.equal((await atelier(new Request(O + '/atelier/upload', { method: 'POST', headers: { origin: O }, body: up0 }))).status, 401);
  });

  await step('Atelier: Werk mit Fotos anlegen, ändern, verkaufen, ausblenden, entfernen', async () => {
    const atelier = (await import('../netlify/functions/atelier.mjs')).default;
    const bilder = (await import('../netlify/functions/bilder.mjs')).default;
    const O = 'https://shop.example.test';
    const fd = new FormData(); fd.set('passwort', 'Pinsel-2026');
    const login = await atelier(new Request(O + '/atelier/login', { method: 'POST', headers: { origin: O }, body: fd }));
    assert.equal(login.status, 303);
    const cookie = login.headers.get('set-cookie').split(';')[0];
    const H = { cookie, origin: O };
    const req = (path, body) => atelier(new Request(O + path, body ? { method: 'POST', headers: H, body } : { headers: { cookie } }));

    const list = await (await req('/atelier')).text();
    assert.match(list, /NACHT/);
    assert.equal((list.match(/<li class="at-row/g) || []).length, 33);

    // Foto-Upload (3 Größen als JPG)
    const jpg = new Blob([new Uint8Array([0xff, 0xd8, 0xff, 0xe0, 1, 2, 3, 0xff, 0xd9])], { type: 'image/jpeg' });
    const up = new FormData(); up.set('full', jpg, 'a.jpg'); up.set('sm', jpg, 'b.jpg'); up.set('xs', jpg, 'c.jpg');
    const u = await (await req('/atelier/upload', up)).json();
    assert.match(u.url, /^\/bilder\/[0-9a-f-]+\.jpg$/);
    const up2 = new FormData(); up2.set('full', jpg); up2.set('sm', jpg); up2.set('xs', jpg);
    const u2 = await (await req('/atelier/upload', up2)).json();
    const png = new FormData(); png.set('full', new Blob([new Uint8Array([0x89, 0x50])])); png.set('sm', jpg); png.set('xs', jpg);
    assert.equal((await req('/atelier/upload', png)).status, 415);
    const img = await bilder(new Request(O + u.url.replace('.jpg', '-sm.jpg')));
    assert.equal(img.status, 200);
    assert.equal(img.headers.get('content-type'), 'image/jpeg');
    assert.equal((await bilder(new Request(O + '/bilder/../../etc/passwd'))).status, 404);

    // Neues Werk
    const f = new FormData();
    Object.entries({ id: '', titel: 'Morgenrot', jahr: '2026', serie: 'Neu', technik: 'Acryl und UV-Farbe auf Leinwand', masse: '80 x 100 cm', beschreibung: 'Ein neues Bild.', preis: '2.450 €', versand: '', status: 'available', sichtbar: 'ja', position: '1', auszeichnungen: 'Preis A\nPreis B', ausstellungen: '', w: '1200', h: '1500' }).forEach(([k, v]) => f.set(k, v));
    f.set('bilder', JSON.stringify([{ url: u.url, role: 'haupt' }, { url: u2.url, role: 'uv' }]));
    const sv = await req('/atelier/speichern', f);
    assert.equal(sv.status, 303);
    const p = prod('morgenrot');
    assert.ok(p, 'Werk angelegt');
    assert.equal(fake.prices.get(p.default_price).unit_amount, 245000);
    assert.equal(p.images[0], O + u.url);
    assert.equal(p.metadata.uv, '2');
    assert.equal(p.metadata.awards, 'Preis A | Preis B');
    invalidateCatalog();
    const shop = await get('/werk/morgenrot');
    assert.equal(shop.status, 200);
    assert.match(shop.text, /2\.450\s€/);
    assert.match(shop.text, /-sm\.jpg 720w/);
    assert.match(shop.text, /data-view="uv"|art-uv|Schwarzlicht/);

    // gleicher Titel → eigene Adresse
    f.set('bilder', '[]'); f.set('preis', '');
    await req('/atelier/speichern', f);
    assert.ok(prod('morgenrot-2'));
    assert.equal(prod('morgenrot-2').default_price, null);

    // Preis ändern + verkauft
    const e = new FormData();
    Object.entries({ id: p.id, titel: 'Morgenrot', preis: '2900', status: 'sold', sichtbar: 'ja', bilder: JSON.stringify([{ url: u.url, role: 'haupt' }]) }).forEach(([k, v]) => e.set(k, v));
    const oldPrice = p.default_price;
    assert.equal((await req('/atelier/speichern', e)).status, 303);
    assert.equal(fake.prices.get(p.default_price).unit_amount, 290000);
    assert.equal(fake.prices.get(oldPrice).active, false);
    assert.equal(p.metadata.status, 'sold');
    assert.equal(p.metadata.uv, undefined);
    invalidateCatalog();
    assert.match((await get('/werk/morgenrot')).text, /Verkauft/);

    // ungültiger Preis
    e.set('preis', 'abc');
    const errPage = await (await req('/atelier/speichern', e)).text();
    assert.match(errPage, /Preis ist ungültig/);
    assert.match(errPage, /name="preis" inputmode="decimal" value="abc"/); // Eingaben bleiben erhalten
    assert.match(errPage, /value="Morgenrot"/);

    // ausblenden
    e.set('preis', '2900'); e.set('sichtbar', 'nein');
    await req('/atelier/speichern', e);
    invalidateCatalog();
    assert.equal((await get('/werk/morgenrot')).status, 404);

    // fremde Herkunft wird abgewiesen
    assert.equal((await atelier(new Request(O + '/atelier/speichern', { method: 'POST', headers: { cookie, origin: 'https://boese.example' }, body: e }))).status, 403);

    // entfernen (archivieren)
    const a = new FormData(); a.set('id', prod('morgenrot-2').id);
    await req('/atelier/archivieren', a);
    assert.equal(prod('morgenrot-2').active, false);
    invalidateCatalog();
    assert.equal((await get('/werk/morgenrot-2')).status, 404);
  });

  await step('Widerrufsbutton (§ 356a BGB): Formular, Bestätigung, Nachweis', async () => {
    const widerruf = (await import('../netlify/functions/widerruf.mjs')).default;
    const O = 'https://shop.example.test';
    const home = (await get('/')).text;
    assert.match(home, /<a class="btn-withdraw" href="\/vertrag-widerrufen">Vertrag widerrufen<\/a>/);
    const f = await (await widerruf(new Request(O + '/vertrag-widerrufen'))).text();
    assert.match(f, />Widerruf bestätigen</);
    assert.doesNotMatch(f, /name="grund"/);
    assert.match((await get('/widerruf')).text, /vertrag-widerrufen/);
    const bad = new FormData(); bad.set('name', 'Erika Muster'); bad.set('vertrag', 'NACHT'); bad.set('email', 'kaputt');
    assert.equal((await widerruf(new Request(O + '/vertrag-widerrufen', { method: 'POST', headers: { origin: O }, body: bad }))).status, 422);
    const ok = new FormData(); ok.set('name', 'Erika Muster'); ok.set('vertrag', 'Werk „NACHT“, gekauft am 20.11.2026'); ok.set('email', 'erika@example.test');
    const r = await widerruf(new Request(O + '/vertrag-widerrufen', { method: 'POST', headers: { origin: O }, body: ok }));
    const t = await r.text();
    assert.equal(r.status, 200);
    assert.match(t, /Ihr Widerruf ist eingegangen/);
    assert.match(t, /\d{2}\.\d{2}\.\d{4}, \d{2}:\d{2}:\d{2} Uhr/);
    assert.match(t, /W-\d{8}-[0-9A-F]{6}/);
    const atelier = (await import('../netlify/functions/atelier.mjs')).default;
    const fd = new FormData(); fd.set('passwort', 'Pinsel-2026');
    const cookie = (await atelier(new Request(O + '/atelier/login', { method: 'POST', headers: { origin: O }, body: fd }))).headers.get('set-cookie').split(';')[0];
    const list = await (await atelier(new Request(O + '/atelier/widerrufe', { headers: { cookie } }))).text();
    assert.match(list, /Erika Muster/);
  });

  await step('Widerrufe liegen verschlüsselt im Speicher (AES-256-GCM)', async () => {
    const { readdir, readFile, writeFile } = await import('node:fs/promises');
    const { join } = await import('node:path');
    const dir = join(process.env.RECORDS_LOCAL_DIR, 'widerrufe');
    const files = await readdir(dir);
    assert.ok(files.length >= 1);
    const raw = await readFile(join(dir, files[0]), 'utf8');
    assert.doesNotMatch(raw, /Erika|Muster|example\.test|NACHT/); // kein Klartext auf der Platte
    const box = JSON.parse(raw);
    assert.equal(box.enc, 'v1');
    assert.ok(box.iv && box.tag && box.ct && box.kid);

    const O = 'https://shop.example.test';
    const atelier = (await import('../netlify/functions/atelier.mjs')).default;
    const fd = new FormData(); fd.set('passwort', 'Pinsel-2026');
    const cookie = (await atelier(new Request(O + '/atelier/login', { method: 'POST', headers: { origin: O, 'x-nf-client-connection-ip': '192.0.2.50' }, body: fd }))).headers.get('set-cookie').split(';')[0];
    const view = async () => (await atelier(new Request(O + '/atelier/widerrufe', { headers: { cookie } }))).text();

    // Manipulierter Eintrag wird erkannt und nicht angezeigt
    const tampered = { ...box, ct: Buffer.from('x' + Buffer.from(box.ct, 'base64').toString('latin1').slice(1), 'latin1').toString('base64') };
    await writeFile(join(dir, files[0]), JSON.stringify(tampered));
    assert.match(await view(), /lassen sich mit dem aktuellen Schlüssel nicht entschlüsseln/);
    await writeFile(join(dir, files[0]), raw);

    // Schlüsselwechsel: alter Schlüssel als _OLD hält alte Einträge lesbar
    process.env.DATA_ENCRYPTION_KEY = 'b'.repeat(64);
    process.env.DATA_ENCRYPTION_KEY_OLD = 'a'.repeat(64);
    const fd2 = new FormData(); fd2.set('passwort', 'Pinsel-2026');
    const cookie2 = (await atelier(new Request(O + '/atelier/login', { method: 'POST', headers: { origin: O, 'x-nf-client-connection-ip': '192.0.2.51' }, body: fd2 }))).headers.get('set-cookie').split(';')[0];
    assert.match(await (await atelier(new Request(O + '/atelier/widerrufe', { headers: { cookie: cookie2 } }))).text(), /Erika Muster/);
    delete process.env.DATA_ENCRYPTION_KEY_OLD;
    process.env.DATA_ENCRYPTION_KEY = 'a'.repeat(64);

    // Ohne Schlüssel: nichts im Klartext speichern, Atelier warnt
    delete process.env.DATA_ENCRYPTION_KEY;
    try {
      const widerruf = (await import('../netlify/functions/widerruf.mjs')).default;
      const before = (await readdir(dir)).length;
      const f = new FormData(); f.set('name', 'Ohne Schluessel'); f.set('vertrag', 'Werk X'); f.set('email', 'ohne@example.test');
      const r = await widerruf(new Request(O + '/vertrag-widerrufen', { method: 'POST', headers: { origin: O, 'x-nf-client-connection-ip': '192.0.2.60' }, body: f }));
      assert.equal(r.status, 200); // Käufer bekommt trotzdem seine Bestätigungsseite
      assert.equal((await readdir(dir)).length, before);
      assert.match(await view(), /kein Schlüssel für die Verschlüsselung eingerichtet/);
    } finally {
      process.env.DATA_ENCRYPTION_KEY = 'a'.repeat(64);
    }
  });

  await step('Missbrauchsschutz: Reservierungen, Atelier-Login und Widerrufe je IP begrenzt', async () => {
    const O = 'https://shop.example.test';
    // Checkout: höchstens 3 neue Bezahlvorgänge je IP, danach "zuviele"
    invalidateCatalog();
    const free = [...fake.products.values()].filter((p) => p.active && p.default_price && p.metadata.status !== 'sold' && !p.metadata.reserved_session && p.metadata.hidden !== 'true').map((p) => p.metadata.slug);
    assert.ok(free.length >= 5, 'genug freie Werke');
    const ip = '203.0.113.7';
    for (const slug of free.slice(0, 3)) assert.equal((await buy(slug, '', ip)).status, 200, slug);
    const r4 = await buy(free[3], '', ip);
    assert.equal(r4.status, 429);
    assert.equal((await r4.json()).error, 'zuviele');
    assert.ok(!prod(free[3]).metadata.reserved_session, 'kein viertes Werk reserviert');
    assert.equal((await buy(free[3], '', '203.0.113.8')).status, 200); // andere IP darf
    assert.match((await get('/werk/' + free[4])).text, /data-notice="zuviele"/);

    // Atelier: nach 3 Fehlversuchen gesperrt, auch für das richtige Passwort; andere IP nicht betroffen
    process.env.LIMIT_LOGIN_FAILS_PER_IP = '3';
    try {
      const atelier = (await import('../netlify/functions/atelier.mjs')).default;
      const login = (pw, ipAddr) => { const fd = new FormData(); fd.set('passwort', pw); return atelier(new Request(O + '/atelier/login', { method: 'POST', headers: { origin: O, 'x-nf-client-connection-ip': ipAddr }, body: fd })); };
      for (let i = 0; i < 3; i++) assert.equal((await login('falsch', '203.0.113.20')).status, 401);
      const locked = await login('Pinsel-2026', '203.0.113.20');
      assert.equal(locked.status, 429);
      assert.match(await locked.text(), /Zu viele Fehlversuche/);
      assert.equal(locked.headers.get('set-cookie'), null);
      assert.equal((await login('Pinsel-2026', '203.0.113.21')).status, 303);
      // erfolgreiche Anmeldung setzt den Zähler zurück
      await login('falsch', '203.0.113.22'); await login('falsch', '203.0.113.22');
      assert.equal((await login('Pinsel-2026', '203.0.113.22')).status, 303);
      assert.equal((await login('falsch', '203.0.113.22')).status, 401);
    } finally {
      delete process.env.LIMIT_LOGIN_FAILS_PER_IP;
    }

    // Widerruf: höchstens 5 je IP und Stunde, danach 429 mit Hinweis auf den Widerruf per E-Mail
    const widerruf = (await import('../netlify/functions/widerruf.mjs')).default;
    const send = (email) => { const f = new FormData(); f.set('name', 'Max Test'); f.set('vertrag', 'Werk NACHT'); f.set('email', email); return widerruf(new Request(O + '/vertrag-widerrufen', { method: 'POST', headers: { origin: O, 'x-nf-client-connection-ip': '203.0.113.30' }, body: f })); };
    for (let i = 0; i < 5; i++) assert.equal((await send(`opfer${i}@example.test`)).status, 200);
    const blocked = await send('opfer9@example.test');
    assert.equal(blocked.status, 429);
    assert.match(await blocked.text(), /per E-Mail an/);

    // Je Empfänger höchstens 2 Bestätigungsmails pro Tag (Zähler direkt geprüft, SMTP ist im Test nicht eingerichtet)
    const { hit } = await import('../lib/rate-limit.mjs');
    const lim = { limit: 2, windowSec: 86400 };
    assert.equal((await hit('widerruf-mail', 'opfer0@example.test', lim)).ok, true); // 2. Mail an opfer0
    assert.equal((await hit('widerruf-mail', 'opfer0@example.test', lim)).ok, false); // 3. wäre gesperrt
  });

  console.log(`\nAlle ${ok} Prüfungen bestanden.`);
} catch (e) {
  console.error('\n✗ Fehlgeschlagen nach', ok, 'Prüfungen:', e);
  process.exitCode = 1;
} finally {
  fake.close();
}
