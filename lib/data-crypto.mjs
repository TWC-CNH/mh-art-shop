// Verschlüsselung personenbezogener Daten, die der Shop selbst speichert (z. B. Widerrufe mit Name und E-Mail).
// Verfahren: AES-256-GCM (vertraulich und manipulationssicher), Schlüssel aus DATA_ENCRYPTION_KEY (32 Byte, Base64 oder Hex).
// Schlüsselwechsel: neuen Schlüssel als DATA_ENCRYPTION_KEY, den bisherigen als DATA_ENCRYPTION_KEY_OLD setzen.
// Alte Einträge bleiben dann lesbar, neue werden mit dem neuen Schlüssel geschrieben.
// Ohne Schlüssel wird nichts im Klartext gespeichert (encrypt wirft einen Fehler).
import { createCipheriv, createDecipheriv, createHash, createHmac, hkdfSync, randomBytes } from 'node:crypto';
import { getConfig } from './config.mjs';

const ALG = 'aes-256-gcm';

function parseKey(raw, name) {
  if (!raw) return null;
  const s = String(raw).trim();
  const buf = /^[0-9a-fA-F]{64}$/.test(s) ? Buffer.from(s, 'hex') : Buffer.from(s, 'base64');
  if (buf.length !== 32) throw new Error(`${name} muss 32 Byte lang sein (64 Hex-Zeichen oder 44 Zeichen Base64).`);
  return buf;
}

const keyId = (key) => createHash('sha256').update(key).digest('hex').slice(0, 8);

function keys(cfg = getConfig()) {
  const current = parseKey(cfg.dataKey, 'DATA_ENCRYPTION_KEY');
  const old = parseKey(cfg.dataKeyOld, 'DATA_ENCRYPTION_KEY_OLD');
  return { current, all: [current, old].filter(Boolean) };
}

/** Ist ein Schlüssel eingerichtet? */
export function encryptionReady() {
  try {
    return !!keys().current;
  } catch {
    return false;
  }
}

/**
 * Verschlüsselt ein Objekt. `context` (z. B. "widerrufe/<schlüssel>") wird als Zusatzdaten gebunden:
 * Ein Eintrag lässt sich nicht unbemerkt unter einen anderen Namen kopieren.
 */
export function encryptJSON(data, context = '') {
  const { current } = keys();
  if (!current) throw new Error('DATA_ENCRYPTION_KEY fehlt: personenbezogene Daten werden nicht unverschlüsselt gespeichert.');
  const iv = randomBytes(12);
  const c = createCipheriv(ALG, current, iv);
  c.setAAD(Buffer.from(String(context)));
  const ct = Buffer.concat([c.update(JSON.stringify(data), 'utf8'), c.final()]);
  return { enc: 'v1', kid: keyId(current), iv: iv.toString('base64'), tag: c.getAuthTag().toString('base64'), ct: ct.toString('base64') };
}

/** Gegenstück zu encryptJSON. Unverschlüsselte Alt-Einträge (ohne "enc") werden unverändert zurückgegeben. */
export function decryptJSON(box, context = '') {
  if (!box || typeof box !== 'object' || box.enc !== 'v1') return box;
  const key = keys().all.find((k) => keyId(k) === box.kid);
  if (!key) throw new Error(`Kein passender Schlüssel (kid ${box.kid}).`);
  const d = createDecipheriv(ALG, key, Buffer.from(box.iv, 'base64'));
  d.setAAD(Buffer.from(String(context)));
  d.setAuthTag(Buffer.from(box.tag, 'base64'));
  return JSON.parse(Buffer.concat([d.update(Buffer.from(box.ct, 'base64')), d.final()]).toString('utf8'));
}

/**
 * Geheimer Hash (HMAC) für Zähler-Schlüssel wie IP-Adressen oder E-Mails, damit sie sich nicht durch
 * Ausprobieren zurückrechnen lassen. Eigener, aus dem Datenschlüssel abgeleiteter Teilschlüssel.
 */
export function pseudonym(value, purpose = 'id') {
  const cfg = getConfig();
  let base = null;
  try { base = keys(cfg).current; } catch {}
  const secret = base
    ? Buffer.from(hkdfSync('sha256', base, Buffer.alloc(0), Buffer.from('mh-pseudonym'), 32))
    : Buffer.from(String(cfg.atelierSecret || cfg.atelierPassword || cfg.stripeSecretKey || 'mh-local-dev'));
  return createHmac('sha256', secret).update(`${purpose}|${String(value).toLowerCase()}`).digest('hex');
}
