// Legt alle Werke aus data/catalog.json als Produkte in Stripe an (oder aktualisiert sie).
// Einmalig nach dem ersten Deploy ausführen, damit die Bild-URLs schon öffentlich erreichbar sind:
//
//   STRIPE_SECRET_KEY=sk_test_... SITE_URL=https://shop.example.de npm run seed
//
// Optional: --dry (nur anzeigen), --only=cool,nacht (nur bestimmte Werke)
import Stripe from 'stripe';
import { readFileSync } from 'node:fs';

const key = process.env.STRIPE_SECRET_KEY;
const site = (process.env.SITE_URL || '').replace(/\/$/, '');
const dry = process.argv.includes('--dry');
const only = (process.argv.find((a) => a.startsWith('--only=')) || '').slice(7).split(',').filter(Boolean);

if (!key) { console.error('Bitte STRIPE_SECRET_KEY setzen.'); process.exit(1); }
if (!/^https:\/\/|^http:\/\/localhost[:/]/.test(site)) { console.error('Bitte SITE_URL mit https:// setzen (die Bild-URLs müssen öffentlich erreichbar sein).'); process.exit(1); }

const opts = {};
if (process.env.STRIPE_API_BASE) { const u = new URL(process.env.STRIPE_API_BASE); Object.assign(opts, { host: u.hostname, port: u.port, protocol: u.protocol.replace(':', '') }); }
const stripe = new Stripe(key, opts);
const catalog = JSON.parse(readFileSync(new URL('../data/catalog.json', import.meta.url), 'utf8'));

const existing = new Map();
for await (const p of stripe.products.list({ limit: 100, expand: ['data.default_price'] })) {
  if (p.metadata?.slug) existing.set(p.metadata.slug, p);
}

let created = 0, updated = 0;
for (const w of catalog) {
  if (only.length && !only.includes(w.slug)) continue;
  const metadata = {
    slug: w.slug,
    year: w.year ? String(w.year) : '',
    technique: w.technique || '',
    size: w.size || '',
    format: w.format || '',
    series: w.series || '',
    sort: String(w.sort ?? ''),
    w: String(w.w || ''),
    h: String(w.h || ''),
    thumb: w.thumb ? site + w.thumb : '',
    price_on_request: w.price_eur ? '' : 'true',
    awards: (w.awards || []).join(' | ').slice(0, 500),
    exhibitions: (w.exhibitions || []).join(' | ').slice(0, 500),
  };
  // Bilder: Hauptfoto, dann UV-Foto, dann weitere Ansichten (Stripe erlaubt max. 8)
  const images = [site + w.image];
  if (w.uv_image) { images.push(site + w.uv_image); metadata.uv = '2'; } else metadata.uv = '';
  const extra = (w.gallery || []).slice(0, 8 - images.length);
  extra.forEach((g) => images.push(site + g.src));
  metadata.kinds = extra.map((g) => g.kind).join(',');
  const base = { name: w.title, description: w.description || undefined, images, metadata };
  const prev = existing.get(w.slug);
  const cents = w.price_eur ? Math.round(w.price_eur * 100) : null;

  if (dry) { console.log(prev ? 'UPDATE' : 'CREATE', w.slug, cents ? cents / 100 + ' €' : 'auf Anfrage'); continue; }

  if (!prev) {
    // Verkaufsstatus nur beim ersten Anlegen setzen – danach führt Stripe ihn.
    metadata.status = w.status === 'sold' ? 'sold' : 'available';
    await stripe.products.create({ ...base, metadata, ...(cents ? { default_price_data: { currency: 'eur', unit_amount: cents } } : {}) });
    created++;
    console.log('angelegt:', w.slug);
  } else {
    const update = { ...base };
    const prevCents = prev.default_price?.unit_amount ?? null;
    if (cents && prevCents !== cents) {
      const price = await stripe.prices.create({ product: prev.id, currency: 'eur', unit_amount: cents });
      update.default_price = price.id;
    }
    await stripe.products.update(prev.id, update);
    updated++;
    console.log('aktualisiert:', w.slug);
  }
}
console.log(`Fertig: ${created} neu, ${updated} aktualisiert.`);
