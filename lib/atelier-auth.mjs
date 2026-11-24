// Anmeldung für das Atelier: ein Passwort (ATELIER_PASSWORD), danach ein signiertes Cookie für 30 Tage.
import { createHmac, timingSafeEqual, createHash } from 'node:crypto';
import { getConfig } from './config.mjs';

const COOKIE = 'mh_atelier';
const DAYS = 30;

function secret(cfg = getConfig()) {
  // Eigenes Geheimnis, sonst aus Passwort + Stripe-Schlüssel abgeleitet (ändert sich das Passwort, sind alle Sitzungen ungültig)
  return cfg.atelierSecret || createHash('sha256').update(`${cfg.atelierPassword}|${cfg.stripeSecretKey}|mh-atelier`).digest('hex');
}
const sign = (v) => createHmac('sha256', secret()).update(v).digest('base64url');

const safeEq = (a, b) => {
  const A = Buffer.from(String(a));
  const B = Buffer.from(String(b));
  return A.length === B.length && timingSafeEqual(A, B);
};

export function checkPassword(pw) {
  const cfg = getConfig();
  if (!cfg.atelierPassword) return false;
  return safeEq(createHash('sha256').update(String(pw)).digest('hex'), createHash('sha256').update(cfg.atelierPassword).digest('hex'));
}

export function loginCookie() {
  const exp = String(Math.floor(Date.now() / 1000) + DAYS * 86400);
  return `${COOKIE}=${exp}.${sign(exp)}; Path=/; Max-Age=${DAYS * 86400}; HttpOnly; Secure; SameSite=Strict`;
}
export const logoutCookie = () => `${COOKIE}=; Path=/; Max-Age=0; HttpOnly; Secure; SameSite=Strict`;

export function isLoggedIn(req) {
  const cfg = getConfig();
  if (!cfg.atelierPassword) return false;
  const raw = (req.headers.get('cookie') || '').split(/;\s*/).map((c) => c.split('=')).find(([k]) => k === COOKIE)?.[1] || '';
  const [exp, sig] = raw.split('.');
  if (!exp || !sig || Number(exp) < Date.now() / 1000) return false;
  return safeEq(sig, sign(exp));
}

// Schutz gegen fremde Formulare (CSRF): POSTs müssen von der eigenen Seite kommen
export function sameOrigin(req) {
  const origin = req.headers.get('origin');
  if (!origin) return true; // ältere Browser senden bei SameSite=Strict-Cookies ohnehin nichts Fremdes
  try {
    return new URL(origin).host === new URL(req.url).host;
  } catch {
    return false;
  }
}
