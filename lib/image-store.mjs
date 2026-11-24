// Speicher für Fotos, die Matthias im Atelier hochlädt.
// Auf Netlify: Netlify Blobs (kostenlos, gehört zur Site). Lokal/Test: Ordner .atelier-uploads/
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { join } from 'node:path';

const STORE = 'werkbilder';
const onNetlify = () => !!(process.env.NETLIFY || process.env.NETLIFY_BLOBS_CONTEXT || globalThis.Netlify);
const localDir = () => process.env.ATELIER_LOCAL_DIR || join(process.cwd(), '.atelier-uploads');

async function blobStore() {
  const { getStore } = await import('@netlify/blobs');
  return getStore({ name: STORE, consistency: 'strong' });
}

export const validKey = (k) => /^[a-z0-9-]{8,80}\.(jpg|webp)$/.test(k);

export async function putImage(key, bytes, contentType = 'image/jpeg') {
  if (!validKey(key)) throw new Error('Ungültiger Dateiname');
  if (onNetlify()) {
    const s = await blobStore();
    await s.set(key, bytes, { metadata: { contentType } });
    return;
  }
  await mkdir(localDir(), { recursive: true });
  await writeFile(join(localDir(), key), Buffer.from(bytes));
}

export async function getImage(key) {
  if (!validKey(key)) return null;
  if (onNetlify()) {
    const s = await blobStore();
    const r = await s.getWithMetadata(key, { type: 'arrayBuffer' });
    return r ? { bytes: r.data, contentType: r.metadata?.contentType || 'image/jpeg' } : null;
  }
  try {
    const bytes = await readFile(join(localDir(), key));
    return { bytes, contentType: key.endsWith('.webp') ? 'image/webp' : 'image/jpeg' };
  } catch {
    return null;
  }
}
