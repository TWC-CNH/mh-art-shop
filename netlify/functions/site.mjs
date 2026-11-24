// Rendert alle Shop-Seiten serverseitig. Netlify cached die Antworten im CDN (Tag "catalog");
// der Stripe-Webhook leert diesen Cache, sobald sich ein Werk ändert oder verkauft wird.
import { listWorks, getWork, stripe, shippingFor } from '../../lib/catalog.mjs';
import { renderHome, renderWork, renderThanks, renderNotFound } from '../../lib/render.mjs';
import { renderLegal, LEGAL_KEYS } from '../../lib/legal.mjs';
import { getConfig } from '../../lib/config.mjs';

const CACHED = {
  'Content-Type': 'text/html; charset=utf-8',
  'Cache-Control': 'public, max-age=0, must-revalidate',
  'Netlify-CDN-Cache-Control': 'public, durable, s-maxage=300, stale-while-revalidate=86400',
  'Netlify-Cache-Tag': 'catalog',
};
const NOSTORE = { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' };
const html = (body, status = 200, headers = CACHED) => new Response(body, { status, headers });

export default async function handler(req) {
  const url = new URL(req.url);
  const path = url.pathname.replace(/\/+$/, '') || '/';

  try {
    if (path === '/') return html(renderHome(await listWorks()));

    if (path.startsWith('/werk/')) {
      const slug = decodeURIComponent(path.slice(6));
      const work = await getWork(slug);
      if (!work) return html(renderNotFound(), 404);
      return html(renderWork(work, await listWorks()));
    }

    if (path === '/danke') {
      const id = url.searchParams.get('session_id');
      let session = null;
      let work = null;
      const s = stripe();
      if (s && id && /^cs_/.test(id)) {
        try {
          session = await s.checkout.sessions.retrieve(id);
          if (session?.metadata?.slug) work = await getWork(session.metadata.slug);
        } catch (e) {
          console.warn('Session nicht gefunden', e.message);
        }
      }
      return html(renderThanks({ session, work }), 200, NOSTORE);
    }

    const key = path.slice(1);
    if (LEGAL_KEYS.includes(key)) return html(renderLegal(key));

    if (path === '/sitemap.xml') {
      const cfg = getConfig();
      const works = await listWorks();
      const abs = (u) => (/^https?:/.test(u) ? u : cfg.siteUrl + u);
      const x = (t) => String(t).replace(/&/g, '&amp;').replace(/</g, '&lt;');
      const entries = [
        `  <url><loc>${cfg.siteUrl}/</loc></url>`,
        ...works.map((w) => `  <url><loc>${cfg.siteUrl}/werk/${w.slug}</loc>${[w.image, w.uvImage].filter(Boolean).map((img, i) => `<image:image><image:loc>${x(abs(img))}</image:loc><image:title>${x(`${w.title}${i ? ' (unter Schwarzlicht)' : ''} – ${cfg.artistName}`)}</image:title></image:image>`).join('')}</url>`),
        ...LEGAL_KEYS.map((k) => `  <url><loc>${cfg.siteUrl}/${k}</loc></url>`),
      ];
      const xml = `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">\n${entries.join('\n')}\n</urlset>`;
      return new Response(xml, { headers: { ...CACHED, 'Content-Type': 'application/xml; charset=utf-8' } });
    }

    // Produkt-Feed für Google Merchant Center (kostenlose Einträge + Shopping-Anzeigen)
    if (path === '/feed.xml') {
      const cfg = getConfig();
      const works = (await listWorks()).filter((w) => w.priceCents && w.status !== 'sold');
      const abs = (u) => (/^https?:/.test(u) ? u : cfg.siteUrl + u);
      const x = (t) => String(t ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
      const ship = (w) => shippingFor(w, cfg);
      const items = works.map((w) => `  <item>
    <g:id>${x(w.slug)}</g:id>
    <title>${x(`${w.title} – Originalgemälde von ${cfg.artistName}${w.year ? `, ${w.year}` : ''}`)}</title>
    <description>${x(`${w.description || ''} ${w.technique}, ${w.size}. Signiertes Unikat mit Echtheitszertifikat.`.trim())}</description>
    <link>${cfg.siteUrl}/werk/${x(w.slug)}</link>
    <g:image_link>${x(abs(w.image))}</g:image_link>${w.uvImage ? `\n    <g:additional_image_link>${x(abs(w.uvImage))}</g:additional_image_link>` : ''}
    <g:availability>${w.status === 'available' ? 'in_stock' : 'out_of_stock'}</g:availability>
    <g:price>${(w.priceCents / 100).toFixed(2)} EUR</g:price>
    <g:condition>new</g:condition>
    <g:brand>${x(cfg.artistName)}</g:brand>
    <g:identifier_exists>no</g:identifier_exists>
    <g:google_product_category>Home &amp; Garden &gt; Decor &gt; Artwork</g:google_product_category>
    <g:product_type>Kunst &gt; Malerei &gt; Original</g:product_type>
    <g:shipping><g:country>DE</g:country><g:price>${Number(ship(w)).toFixed(2)} EUR</g:price></g:shipping>
  </item>`);
      const xml = `<?xml version="1.0" encoding="UTF-8"?>\n<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">\n<channel>\n  <title>${x(cfg.shopName)} – ${x(cfg.artistName)}</title>\n  <link>${cfg.siteUrl}</link>\n  <description>Originalgemälde von ${x(cfg.artistName)}</description>\n${items.join('\n')}\n</channel>\n</rss>`;
      return new Response(xml, { headers: { ...CACHED, 'Content-Type': 'application/xml; charset=utf-8' } });
    }

    if (path === '/robots.txt') {
      const cfg = getConfig();
      return new Response(`User-agent: *\nAllow: /\nDisallow: /api/\nDisallow: /danke\nDisallow: /atelier\n\nSitemap: ${cfg.siteUrl}/sitemap.xml\n`, { headers: { ...CACHED, 'Content-Type': 'text/plain; charset=utf-8' } });
    }

    return html(renderNotFound(), 404);
  } catch (err) {
    console.error(err);
    return html('<!doctype html><meta charset="utf-8"><title>Kurz nicht erreichbar</title><p style="font-family:sans-serif;padding:2rem">Der Shop ist gerade kurz nicht erreichbar. Bitte in einer Minute neu laden.</p>', 503, NOSTORE);
  }
}

export const config = {
  // Muss eine feste Liste sein (Netlify liest sie statisch aus).
  path: ['/', '/werk/*', '/danke', '/sitemap.xml', '/feed.xml', '/robots.txt', '/impressum', '/agb', '/widerruf', '/versand', '/datenschutz'],
  preferStatic: true,
};
