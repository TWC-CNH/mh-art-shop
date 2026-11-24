// Kleine Ablage für Nachweise (z. B. eingegangene Widerrufe). Netlify Blobs, lokal Ordner .records/
import { mkdir, readFile, writeFile, readdir } from 'node:fs/promises';
import { join } from 'node:path';

const onNetlify = () => !!(process.env.NETLIFY || process.env.NETLIFY_BLOBS_CONTEXT || globalThis.Netlify);
const localDir = (store) => join(process.env.RECORDS_LOCAL_DIR || join(process.cwd(), '.records'), store);
const blob = async (store) => (await import('@netlify/blobs')).getStore({ name: store, consistency: 'strong' });

export async function putRecord(store, key, data) {
  if (onNetlify()) return (await blob(store)).setJSON(key, data);
  await mkdir(localDir(store), { recursive: true });
  await writeFile(join(localDir(store), key + '.json'), JSON.stringify(data, null, 2));
}

export async function listRecords(store, limit = 200) {
  const out = [];
  if (onNetlify()) {
    const s = await blob(store);
    const { blobs } = await s.list();
    for (const b of blobs.sort((a, b) => (a.key < b.key ? 1 : -1)).slice(0, limit)) out.push(await s.get(b.key, { type: 'json' }));
    return out.filter(Boolean);
  }
  try {
    const files = (await readdir(localDir(store))).filter((f) => f.endsWith('.json')).sort().reverse().slice(0, limit);
    for (const f of files) out.push(JSON.parse(await readFile(join(localDir(store), f), 'utf8')));
  } catch {}
  return out;
}
