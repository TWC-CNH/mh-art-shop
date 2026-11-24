// Startet den Stripe-Checkout für genau ein Werk und reserviert es für die Dauer des Bezahlvorgangs.
// Funktioniert auch ohne JavaScript (Formular-POST -> 303-Weiterleitung zu Stripe).
import { stripe, fromStripeProduct, listWorks, shippingFor } from '../../lib/catalog.mjs';
import { reserve } from '../../lib/reserve.mjs';
import { getConfig } from '../../lib/config.mjs';
import { clientIp, hit } from '../../lib/rate-limit.mjs';

const wantsJson = (req) => (req.headers.get('accept') || '').includes('application/json');

function reply(req, { url, error, status = 303, sessionId = '' }) {
  const headers = new Headers({ 'Cache-Control': 'no-store' });
  // Merkt sich die eigene Checkout-Sitzung, damit man nach "Zurück" im Browser weiterbezahlen kann.
  if (sessionId) headers.append('Set-Cookie', `mh_co=${sessionId}; Path=/api; Max-Age=3600; HttpOnly; Secure; SameSite=Lax`);
  if (wantsJson(req)) {
    headers.set('Content-Type', 'application/json');
    return new Response(JSON.stringify(error ? { error } : { url }), { status: error ? (status === 303 ? 400 : status) : 200, headers });
  }
  headers.set('Location', url);
  return new Response(null, { status: 303, headers });
}

const cookie = (req, name) => (req.headers.get('cookie') || '').split(/;\s*/).map((c) => c.split('=')).find(([k]) => k === name)?.[1] || '';

export default async function handler(req, context) {
  if (req.method !== 'POST') return new Response('Method Not Allowed', { status: 405, headers: { Allow: 'POST' } });
  const cfg = getConfig();

  let slug = '';
  const ct = req.headers.get('content-type') || '';
  if (ct.includes('application/json')) slug = (await req.json().catch(() => ({}))).slug || '';
  else slug = (await req.formData().catch(() => new FormData())).get('slug') || '';
  slug = String(slug).slice(0, 120);
  const back = (flag) => `${cfg.siteUrl}/werk/${encodeURIComponent(slug)}?${flag}=1`;

  if (!slug) return reply(req, { url: `${cfg.siteUrl}/`, error: 'Kein Werk angegeben', status: 400 });

  if (cfg.demo) return reply(req, { url: back('demo'), error: 'demo', status: 409 });

  try {
    const s = stripe();
    // Frisch aus Stripe lesen (nicht aus dem Cache), damit der Status stimmt.
    const works = await listWorks({ fresh: true });
    const cached = works.find((w) => w.slug === slug);
    if (!cached) return reply(req, { url: `${cfg.siteUrl}/`, error: 'Werk nicht gefunden', status: 404 });

    const product = await s.products.retrieve(cached.id, { expand: ['default_price'] });
    const work = fromStripeProduct(product);
    // Eigene, noch offene Sitzung? Dann einfach dorthin zurück.
    const mine = cookie(req, 'mh_co');
    if (work.status === 'reserved' && mine && product.metadata?.reserved_session === mine) {
      const open = await s.checkout.sessions.retrieve(mine).catch(() => null);
      if (open?.status === 'open' && open.url) return reply(req, { url: open.url });
    }
    if (!product.active || work.status !== 'available' || !work.priceId) {
      return reply(req, { url: back('vergeben'), error: 'vergeben', status: 409 });
    }

    // Missbrauchsschutz: Jeder neue Bezahlvorgang reserviert ein Werk. Je IP nur wenige pro Reservierungsdauer,
    // sonst könnte ein Skript alle Werke dauerhaft blockieren.
    const limit = await hit('checkout', clientIp(req, context), { limit: cfg.limits.checkoutPerIp, windowSec: cfg.reserveMinutes * 60 });
    if (!limit.ok) return reply(req, { url: back('zuviele'), error: 'zuviele', status: 429 });

    const expiresAt = Math.floor(Date.now() / 1000) + cfg.reserveMinutes * 60 + 30;
    const shipEur = shippingFor(work, cfg);
    const shipping_options = [
      {
        shipping_rate_data: {
          display_name: 'Versicherter Versand',
          type: 'fixed_amount',
          fixed_amount: { amount: Math.round(shipEur * 100), currency: 'eur' },
          delivery_estimate: {
            minimum: { unit: 'business_day', value: Number(cfg.deliveryDays.split('-')[0]) || 3 },
            maximum: { unit: 'business_day', value: Number(cfg.deliveryDays.split('-')[1]) || 7 },
          },
          tax_behavior: 'inclusive',
        },
      },
    ];
    if (cfg.pickupEnabled) {
      shipping_options.push({
        shipping_rate_data: {
          display_name: cfg.pickupLabel.slice(0, 100),
          type: 'fixed_amount',
          fixed_amount: { amount: 0, currency: 'eur' },
          tax_behavior: 'inclusive',
        },
      });
    }

    const params = {
      mode: 'payment',
      submit_type: 'pay',
      line_items: [{ price: work.priceId, quantity: 1 }],
      success_url: `${cfg.siteUrl}/danke?session_id={CHECKOUT_SESSION_ID}`,
      cancel_url: `${cfg.siteUrl}/api/cancel?session_id={CHECKOUT_SESSION_ID}&slug=${encodeURIComponent(work.slug)}`,
      expires_at: expiresAt,
      locale: 'de',
      billing_address_collection: 'required',
      shipping_address_collection: { allowed_countries: cfg.shippingCountries },
      shipping_options,
      phone_number_collection: { enabled: true },
      customer_creation: 'if_required',
      metadata: { product_id: work.id, slug: work.slug, title: work.title.slice(0, 450) },
      payment_intent_data: {
        description: `Original: ${work.title}${work.year ? ` (${work.year})` : ''}`.slice(0, 1000),
        metadata: { product_id: work.id, slug: work.slug },
      },
      custom_text: {
        submit: {
          message: `Unikat, signiert, mit Echtheitszertifikat.${cfg.kleinunternehmer ? ' Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.' : ''} Es gelten die AGB und die Widerrufsbelehrung: ${cfg.siteUrl}/agb · ${cfg.siteUrl}/widerruf`.slice(0, 1200),
        },
        shipping_address: { message: 'Bitte eine Adresse angeben, an der das Paket persönlich angenommen werden kann. Bei Abholung im Atelier hier einfach Ihre Rechnungsadresse eintragen.' },
      },
    };
    if (cfg.requireTermsConsent) params.consent_collection = { terms_of_service: 'required' };
    if (cfg.invoiceCreation) params.invoice_creation = { enabled: true };
    if (cfg.allowPromotionCodes) params.allow_promotion_codes = true;

    const session = await s.checkout.sessions.create(params);
    await reserve(work.id, session.id, session.expires_at);
    return reply(req, { url: session.url, sessionId: session.id });
  } catch (err) {
    console.error('Checkout-Fehler', err);
    return reply(req, { url: back('fehler'), error: 'fehler', status: 500 });
  }
}

export const config = { path: '/api/checkout' };
