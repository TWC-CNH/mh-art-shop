// Käufer bricht bei Stripe ab: Sitzung schließen, Reservierung sofort freigeben, zurück zum Werk.
import { stripe } from '../../lib/catalog.mjs';
import { release } from '../../lib/reserve.mjs';
import { getConfig } from '../../lib/config.mjs';

export default async function handler(req) {
  const cfg = getConfig();
  const url = new URL(req.url);
  const id = url.searchParams.get('session_id') || '';
  const slug = (url.searchParams.get('slug') || '').slice(0, 120);
  const target = slug ? `${cfg.siteUrl}/werk/${encodeURIComponent(slug)}?abgebrochen=1` : `${cfg.siteUrl}/`;
  const s = stripe();
  if (s && /^cs_/.test(id)) {
    try {
      const session = await s.checkout.sessions.retrieve(id);
      if (session.status === 'open') await s.checkout.sessions.expire(id);
      if (session.status !== 'complete' && session.metadata?.product_id) await release(session.metadata.product_id, id);
    } catch (e) {
      console.warn('Abbruch konnte nicht verarbeitet werden:', e.message);
    }
  }
  return new Response(null, {
    status: 303,
    headers: { Location: target, 'Cache-Control': 'no-store', 'Set-Cookie': 'mh_co=; Path=/api; Max-Age=0; HttpOnly; Secure; SameSite=Lax' },
  });
}

export const config = { path: '/api/cancel' };
