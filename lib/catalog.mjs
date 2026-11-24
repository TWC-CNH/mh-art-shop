// Werkkatalog: liest die Werke aus Stripe (Produkte) oder im Demo-Modus aus data/catalog.json
// und bringt beide in dieselbe Form.
import Stripe from 'stripe';
import demoData from '../data/catalog.json' with { type: 'json' };
import { getConfig } from './config.mjs';

let _stripe;
export function stripe() {
  const { stripeSecretKey } = getConfig();
  if (!stripeSecretKey) return null;
  if (!_stripe) {
    const opts = { maxNetworkRetries: 2 };
    // Nur für automatisierte Tests gegen einen lokalen Stripe-Ersatz.
    if (process.env.STRIPE_API_BASE) {
      const u = new URL(process.env.STRIPE_API_BASE);
      Object.assign(opts, { host: u.hostname, port: u.port, protocol: u.protocol.replace(':', ''), maxNetworkRetries: 0 });
    }
    _stripe = new Stripe(stripeSecretKey, opts);
  }
  return _stripe;
}

export const slugify = (s) =>
  String(s || '')
    .toLowerCase()
    .replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
    .normalize('NFKD').replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'werk';

export function formatFromSize(size) {
  const nums = String(size || '').match(/\d+(?:[.,]\d+)?/g)?.slice(0, 2).map((n) => parseFloat(n.replace(',', '.'))) || [];
  const longest = nums.length ? Math.max(...nums) : 0;
  if (!longest) return 'mittel';
  return longest < 60 ? 'klein' : longest < 100 ? 'mittel' : 'gross';
}

const nowSec = () => Math.floor(Date.now() / 1000);

function statusFrom(meta = {}) {
  const s = String(meta.status || '').toLowerCase();
  if (['sold', 'verkauft'].includes(s)) return 'sold';
  if (Number(meta.reserved_until || 0) > nowSec()) return 'reserved';
  return 'available';
}

const splitList = (v) => String(v || '').split('|').map((x) => x.trim()).filter(Boolean);

// Bilder in Stripe: 1. Bild = Hauptfoto. metadata.uv = Position des UV-Fotos (z. B. "2").
// metadata.kinds = Art der übrigen Bilder in Reihenfolge, z. B. "raum,detail" (sonst "ansicht").
function imagesFromStripe(p) {
  const imgs = p.images || [];
  const m = p.metadata || {};
  const uvPos = Number(m.uv || 0);
  const kinds = String(m.kinds || '').split(',').map((k) => k.trim());
  const uvImage = uvPos > 1 && imgs[uvPos - 1] ? imgs[uvPos - 1] : '';
  const gallery = [];
  let k = 0;
  imgs.forEach((src, i) => {
    if (i === 0 || i === uvPos - 1) return;
    gallery.push({ src, kind: kinds[k++] || 'ansicht' });
  });
  return { uvImage, gallery };
}

export function fromStripeProduct(p) {
  const m = p.metadata || {};
  const price = p.default_price && typeof p.default_price === 'object' ? p.default_price : null;
  const onRequest = ['1', 'true', 'ja'].includes(String(m.price_on_request || '').toLowerCase()) || !price?.unit_amount;
  const image = p.images?.[0] || '';
  const { uvImage, gallery } = imagesFromStripe(p);
  return {
    id: p.id,
    slug: m.slug || `${slugify(p.name)}-${p.id.slice(-5).toLowerCase()}`,
    title: p.name,
    year: m.year ? Number(m.year) : null,
    technique: m.technique || '',
    size: m.size || '',
    format: m.format || formatFromSize(m.size),
    series: m.series || '',
    description: p.description || '',
    priceCents: onRequest ? null : price.unit_amount,
    currency: (price?.currency || 'eur').toUpperCase(),
    priceId: onRequest ? null : price.id,
    status: statusFrom(m),
    image,
    thumb: m.thumb || image,
    uvImage,
    gallery,
    awards: splitList(m.awards),
    exhibitions: splitList(m.exhibitions),
    w: Number(m.w || 0) || null,
    h: Number(m.h || 0) || null,
    sort: Number(m.sort || 9999),
    shippingEur: m.shipping_eur !== undefined && m.shipping_eur !== '' ? Number(m.shipping_eur) : null,
    created: p.created,
  };
}

function fromDemo(d) {
  return {
    id: `demo_${d.slug}`,
    slug: d.slug,
    title: d.title,
    year: d.year,
    technique: d.technique,
    size: d.size,
    format: d.format || formatFromSize(d.size),
    series: d.series || '',
    description: d.description,
    priceCents: d.price_eur ? Math.round(d.price_eur * 100) : null,
    currency: 'EUR',
    priceId: null,
    status: d.status === 'sold' ? 'sold' : 'available',
    image: d.image,
    thumb: d.thumb || d.image,
    uvImage: d.uv_image || '',
    gallery: (d.gallery || []).map((g) => ({ src: g.src, kind: g.kind })),
    awards: d.awards || [],
    exhibitions: d.exhibitions || [],
    w: d.w, h: d.h,
    sort: d.sort ?? 9999,
    shippingEur: d.shipping_eur ?? null,
    created: 0,
  };
}

// Kleiner In-Memory-Cache je Function-Instanz; die eigentliche Auslieferung cached das Netlify-CDN.
let cache = { at: 0, works: null };
const TTL_MS = 15_000;

export function invalidateCatalog() {
  cache = { at: 0, works: null };
}

function sortWorks(list) {
  const rank = { available: 0, reserved: 1, sold: 2 };
  return list.sort((a, b) => rank[a.status] - rank[b.status] || a.sort - b.sort || (b.created || 0) - (a.created || 0));
}

export async function listWorks({ fresh = false } = {}) {
  const cfg = getConfig();
  if (!fresh && cache.works && Date.now() - cache.at < TTL_MS) return cache.works;
  let works;
  const s = stripe();
  if (!s) {
    works = demoData.map(fromDemo);
  } else {
    works = [];
    for await (const p of s.products.list({ active: true, limit: 100, expand: ['data.default_price'] })) {
      const m = p.metadata || {};
      if (['1', 'true', 'ja'].includes(String(m.hidden || '').toLowerCase())) continue;
      works.push(fromStripeProduct(p));
    }
  }
  if (!cfg.showSold) works = works.filter((w) => w.status !== 'sold');
  sortWorks(works);
  cache = { at: Date.now(), works };
  return works;
}

export async function getWork(slug) {
  const works = await listWorks();
  return works.find((w) => w.slug === slug) || null;
}

export function shippingFor(work, cfg = getConfig()) {
  if (work.shippingEur !== null && work.shippingEur !== undefined && !Number.isNaN(work.shippingEur)) return work.shippingEur;
  return cfg.shipping[work.format] ?? cfg.shipping.mittel;
}

export const euro = (cents) =>
  new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR', maximumFractionDigits: cents % 100 ? 2 : 0 }).format(cents / 100);
