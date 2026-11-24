// Liefert die im Atelier hochgeladenen Fotos aus (dauerhaft gecacht, Dateinamen sind eindeutig).
import { getImage } from '../../lib/image-store.mjs';

export default async function handler(req) {
  const key = decodeURIComponent(new URL(req.url).pathname.replace(/^\/bilder\//, ''));
  const img = await getImage(key);
  if (!img) return new Response('Nicht gefunden', { status: 404 });
  return new Response(img.bytes, {
    headers: {
      'Content-Type': img.contentType,
      'Cache-Control': 'public, max-age=31536000, immutable',
      'Netlify-CDN-Cache-Control': 'public, durable, max-age=31536000, immutable',
    },
  });
}

export const config = { path: '/bilder/*' };
