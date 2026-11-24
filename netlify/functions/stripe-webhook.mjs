// Stripe-Webhook: markiert Werke als verkauft, gibt abgelaufene Reservierungen frei
// und leert den Seiten-Cache, wenn Matthias im Stripe-Dashboard etwas ändert.
import { stripe } from '../../lib/catalog.mjs';
import { markSold, markAvailable, release, purgeCatalogCache } from '../../lib/reserve.mjs';
import { getConfig } from '../../lib/config.mjs';

export default async function handler(req) {
  if (req.method !== 'POST') return new Response('Method Not Allowed', { status: 405 });
  const cfg = getConfig();
  const s = stripe();
  if (!s || !cfg.stripeWebhookSecret) return new Response('Webhook nicht konfiguriert', { status: 500 });

  const raw = await req.text();
  let event;
  try {
    event = await s.webhooks.constructEventAsync(raw, req.headers.get('stripe-signature') || '', cfg.stripeWebhookSecret);
  } catch (err) {
    console.warn('Ungültige Webhook-Signatur:', err.message);
    return new Response('Ungültige Signatur', { status: 400 });
  }

  const obj = event.data.object;
  const productId = obj?.metadata?.product_id;
  try {
    switch (event.type) {
      // Bestellung abgeschlossen. Auch bei verzögerten Zahlarten (z. B. SEPA) wird das Werk sofort
      // als verkauft markiert, damit es niemand zweites kauft. Scheitert die Zahlung später, wird es wieder frei.
      case 'checkout.session.completed':
      case 'checkout.session.async_payment_succeeded': {
        if (productId) {
          const { alreadySold } = await markSold(productId, obj);
          if (alreadySold) console.error(`ACHTUNG: Werk ${productId} wurde doppelt bezahlt (Sitzung ${obj.id}). Bitte im Stripe-Dashboard erstatten.`);
        }
        break;
      }
      case 'checkout.session.async_payment_failed': {
        if (productId) await markAvailable(productId, obj.id);
        break;
      }
      case 'checkout.session.expired': {
        if (productId) await release(productId, obj.id);
        break;
      }
      case 'charge.refunded': {
        // Erstattung: Werk bleibt bewusst "verkauft", bis Matthias es im Dashboard wieder auf available setzt
        // (z. B. erst wenn das Bild nach einem Widerruf wieder im Atelier ist).
        break;
      }
      default: {
        if (event.type.startsWith('product.') || event.type.startsWith('price.')) await purgeCatalogCache();
      }
    }
  } catch (err) {
    console.error(`Fehler bei ${event.type}:`, err);
    return new Response('Fehler', { status: 500 }); // Stripe versucht es dann erneut
  }
  return Response.json({ received: true });
}

export const config = { path: '/api/stripe-webhook' };
