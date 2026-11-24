// Reservierung und Verkaufsstatus eines Werks, gespeichert direkt in den Stripe-Produkt-Metadaten.
import { stripe, invalidateCatalog } from './catalog.mjs';

export async function purgeCatalogCache() {
  invalidateCatalog();
  try {
    const { purgeCache } = await import('@netlify/functions');
    await purgeCache({ tags: ['catalog'] });
  } catch (e) {
    // Lokal (ohne Netlify) gibt es keinen CDN-Cache – nicht schlimm.
    if (process.env.NETLIFY) console.warn('Cache konnte nicht geleert werden:', e.message);
  }
}

export async function reserve(productId, sessionId, untilSec) {
  await stripe().products.update(productId, {
    metadata: { reserved_until: String(untilSec), reserved_session: sessionId },
  });
  await purgeCatalogCache();
}

export async function release(productId, sessionId) {
  const s = stripe();
  const p = await s.products.retrieve(productId);
  const m = p.metadata || {};
  if (sessionId && m.reserved_session && m.reserved_session !== sessionId) return false; // gehört zu einem anderen Kauf
  if (String(m.status || '').toLowerCase() === 'sold') return false;
  await s.products.update(productId, { metadata: { reserved_until: '', reserved_session: '' } });
  await purgeCatalogCache();
  return true;
}

export async function markSold(productId, session) {
  const s = stripe();
  const p = await s.products.retrieve(productId);
  const m = p.metadata || {};
  const alreadySold = String(m.status || '').toLowerCase() === 'sold' && m.sold_session && m.sold_session !== session.id;
  await s.products.update(productId, {
    metadata: {
      status: 'sold',
      sold_at: new Date().toISOString().slice(0, 10),
      sold_session: alreadySold ? m.sold_session : session.id,
      // Falls ein Werk (sehr unwahrscheinlich) zweimal bezahlt wurde: Hinweis für die Erstattung.
      double_sale_session: alreadySold ? session.id : m.double_sale_session || '',
      reserved_until: '',
      reserved_session: '',
    },
  });
  await purgeCatalogCache();
  return { alreadySold };
}

export async function markAvailable(productId, sessionId) {
  const s = stripe();
  const p = await s.products.retrieve(productId);
  const m = p.metadata || {};
  if (m.sold_session && m.sold_session !== sessionId) return false;
  await s.products.update(productId, {
    metadata: { status: 'available', sold_at: '', sold_session: '', reserved_until: '', reserved_session: '' },
  });
  await purgeCatalogCache();
  return true;
}
