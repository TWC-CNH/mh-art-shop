// Kleine Ablage für Nachweise (z. B. eingegangene Widerrufe). Netlify Blobs, lokal Ordner .records/
// Die Einträge enthalten personenbezogene Daten und werden deshalb immer verschlüsselt gespeichert (lib/data-crypto.mjs).
import { mkdir, readFile, writeFile, readdir } from 'node:fs/promises';
import { join } from 'node:path';
import { encryptJSON, decryptJSON } from './data-crypto.mjs';

const onNetlify = () => !!(process.env.NETLIFY || process.env.NETLIFY_BLOBS_CONTEXT || globalThis.Netlify);
const localDir = (store) => join(process.env.RECORDS_LOCAL_DIR || join(process.cwd(), '.records'), store);
const blob = async (store) => (await import('@netlify/blobs')).getStore({ name: store, consistency: 'strong' });
const ctx = (store, key) => `${store}/${key}`;

export async function putRecord(store, key, data) {
  const box = encryptJSON(data, ctx(store, key)); // wirft ohne Schlüssel: nie Klartext speichern
  if (onNetlify()) return (await blob(store)).setJSON(key, box);
  await mkdir(localDir(store), { recursive: true });
  await writeFile(join(localDir(store), key + '.json'), JSON.stringify(box));
}

// Liefert entschlüsselte Einträge, neueste zuerst. Nicht entschlüsselbare (falscher/fehlender Schlüssel) kommen als { _unreadable: true }.
export async function listRecords(store, limit = 200) {
  const raw = [];
  if (onNetlify()) {
    const s = await blob(store);
    const { blobs } = await s.list();
    for (const b of blobs.sort((a, b) => (a.key < b.key ? 1 : -1)).slice(0, limit)) raw.push([b.key, await s.get(b.key, { type: 'json' })]);
  } else {
    try {
      const files = (await readdir(localDir(store))).filter((f) => f.endsWith('.json')).sort().reverse().slice(0, limit);
      for (const f of files) raw.push([f.slice(0, -5), JSON.parse(await readFile(join(localDir(store), f), 'utf8'))]);
    } catch {}
  }
  return raw.filter(([, v]) => v).map(([key, v]) => {
    try {
      return decryptJSON(v, ctx(store, key));
    } catch (e) {
      console.error(`Eintrag ${store}/${key} nicht entschlüsselbar:`, e.message);
      return { _unreadable: true, key };
    }
  });
}
