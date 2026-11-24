// Einfache Begrenzung von Versuchen je Schlüssel (z. B. IP-Adresse oder E-Mail) in einem Zeitfenster.
// Auf Netlify: Netlify Blobs (gilt für alle Instanzen, übersteht Kaltstarts). Lokal/Test: im Speicher.
// Gespeichert werden nur Zeitstempel unter einem geheimen Hash (HMAC) des Schlüssels, keine IP-Adressen oder E-Mails im Klartext.
// Fällt der Speicher aus, wird nicht blockiert (Kaufen und Widerrufen sollen dann trotzdem funktionieren).
import { pseudonym } from './data-crypto.mjs';

const STORE = 'ratelimit';
const onNetlify = () => !!(process.env.NETLIFY || process.env.NETLIFY_BLOBS_CONTEXT || globalThis.Netlify);
const mem = new Map();

const blob = async () => (await import('@netlify/blobs')).getStore({ name: STORE, consistency: 'strong' });
const keyOf = (bucket, id) => `${bucket}-${pseudonym(id, `rl|${bucket}`).slice(0, 40)}`;

async function load(k) {
  if (!onNetlify()) return mem.get(k) || [];
  const v = await (await blob()).get(k, { type: 'json' });
  return Array.isArray(v) ? v : [];
}
async function store(k, list) {
  if (!onNetlify()) return list.length ? mem.set(k, list) : mem.delete(k);
  const s = await blob();
  return list.length ? s.setJSON(k, list) : s.delete(k);
}

/** IP-Adresse des Besuchers. Netlify liefert sie über context.ip bzw. einen eigenen Header. */
export function clientIp(req, context) {
  return (
    context?.ip ||
    req.headers.get('x-nf-client-connection-ip') ||
    (req.headers.get('x-forwarded-for') || '').split(',')[0].trim() ||
    'unbekannt'
  );
}

/** Anzahl der Versuche im aktuellen Fenster, ohne einen neuen zu zählen. */
export async function attempts(bucket, id, { windowSec }) {
  try {
    const since = Date.now() - windowSec * 1000;
    return (await load(keyOf(bucket, id))).filter((t) => t > since).length;
  } catch (e) {
    console.warn('Rate-Limit nicht lesbar:', e.message);
    return 0;
  }
}

/**
 * Zählt einen Versuch. Ist das Limit im Fenster schon erreicht, wird nicht gezählt und ok=false geliefert.
 * retryAfter: Sekunden, bis wieder ein Versuch frei ist.
 */
export async function hit(bucket, id, { limit, windowSec }) {
  try {
    const k = keyOf(bucket, id);
    const now = Date.now();
    const list = (await load(k)).filter((t) => t > now - windowSec * 1000);
    if (list.length >= limit) return { ok: false, retryAfter: Math.max(1, Math.ceil((list[0] + windowSec * 1000 - now) / 1000)) };
    list.push(now);
    await store(k, list);
    return { ok: true };
  } catch (e) {
    console.warn('Rate-Limit nicht speicherbar:', e.message);
    return { ok: true };
  }
}

/** Zähler zurücksetzen (z. B. nach erfolgreicher Anmeldung). */
export async function resetHits(bucket, id) {
  try {
    await store(keyOf(bucket, id), []);
  } catch (e) {
    console.warn('Rate-Limit nicht zurücksetzbar:', e.message);
  }
}
