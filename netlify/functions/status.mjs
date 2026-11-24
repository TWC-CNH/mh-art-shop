// Aktueller Verkaufsstatus aller Werke (verfügbar / reserviert / verkauft).
// Die Seiten selbst sind im CDN gecacht; das Frontend fragt hier nach und aktualisiert Badges und Kauf-Button live.
import { listWorks } from '../../lib/catalog.mjs';

export default async function handler() {
  try {
    const works = await listWorks();
    const status = Object.fromEntries(works.map((w) => [w.slug, w.status]));
    return new Response(JSON.stringify({ status, at: new Date().toISOString() }), {
      headers: {
        'Content-Type': 'application/json; charset=utf-8',
        'Cache-Control': 'public, max-age=0, must-revalidate',
        'Netlify-CDN-Cache-Control': 'public, durable, s-maxage=20, stale-while-revalidate=60',
        'Netlify-Cache-Tag': 'catalog',
      },
    });
  } catch (e) {
    return Response.json({ error: 'nicht verfügbar' }, { status: 503 });
  }
}

export const config = { path: '/api/status' };
