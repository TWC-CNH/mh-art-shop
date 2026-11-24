// Atelier: Verwaltung für Matthias unter /atelier (passwortgeschützt).
// Werke anlegen und bearbeiten, Fotos hochladen (Hauptfoto, Schwarzlicht, Detail, Raum), Preis, Status, Sichtbarkeit.
// Gespeichert wird alles in Stripe (Produkte) – die Fotos liegen in Netlify Blobs.
import { randomUUID } from 'node:crypto';
import { stripe, fromStripeProduct, slugify, euro } from '../../lib/catalog.mjs';
import { purgeCatalogCache } from '../../lib/reserve.mjs';
import { putImage } from '../../lib/image-store.mjs';
import { listRecords } from '../../lib/record-store.mjs';
import { checkPassword, loginCookie, logoutCookie, isLoggedIn, sameOrigin } from '../../lib/atelier-auth.mjs';
import { getConfig } from '../../lib/config.mjs';
import { esc } from '../../lib/render.mjs';
import { clientIp, attempts, hit, resetHits } from '../../lib/rate-limit.mjs';

const ROLE_LABEL = { haupt: 'Hauptfoto', uv: 'Schwarzlicht', detail: 'Detail', raum: 'Im Raum', ansicht: 'Ansicht' };
const MAX_IMAGES = 8;
const NOSTORE = { 'Cache-Control': 'no-store', 'X-Robots-Tag': 'noindex, nofollow' };

const page = (title, body, { status = 200, headers = {} } = {}) =>
  new Response(
    `<!doctype html>
<html lang="de" data-theme="day"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow"><title>${esc(title)} · Atelier</title>
<link rel="icon" href="/favicon.png"><link rel="stylesheet" href="/assets/shop.css?v=a3"><link rel="stylesheet" href="/assets/atelier.css?v=a4"></head>
<body class="atelier-body">
<header class="at-head"><a href="/atelier" class="at-brand"><img src="/assets/logo-mhart.png" alt="MH ART" height="34"> <span>Atelier</span></a>
<nav><a href="/" target="_blank" rel="noopener">Shop ansehen ↗</a></nav></header>
<main class="at-main">${body}</main>
<script src="/assets/atelier.js?v=a5" defer></script></body></html>`,
    { status, headers: { 'Content-Type': 'text/html; charset=utf-8', ...NOSTORE, ...headers } },
  );

const redirect = (to, headers = {}) => new Response(null, { status: 303, headers: { Location: to, ...NOSTORE, ...headers } });

function loginPage(error = '', cfg = getConfig(), status = 0) {
  if (!cfg.atelierPassword) {
    return page('Atelier', `<section class="at-login"><h1 class="display">Atelier</h1><p>Das Atelier ist noch nicht eingerichtet. Bitte in Netlify die Umgebungsvariable <code>ATELIER_PASSWORD</code> setzen und neu veröffentlichen.</p></section>`);
  }
  return page('Anmelden', `<section class="at-login">
    <p class="eyebrow">MH ART</p><h1 class="display">Atelier</h1>
    <p class="muted">Hier verwalten Sie Ihre Werke, Fotos und Preise.</p>
    ${error ? `<p class="at-error">${esc(error)}</p>` : ''}
    <form method="post" action="/atelier/login" class="at-form">
      <label class="field"><span>Passwort</span><input type="password" name="passwort" required autocomplete="current-password" autofocus></label>
      <button class="btn btn--wide" type="submit">Anmelden</button>
    </form></section>`, { status: status || (error ? 401 : 200) });
}

// ---------- Daten ----------
async function allProducts() {
  const out = [];
  for await (const p of stripe().products.list({ active: true, limit: 100, expand: ['data.default_price'] })) out.push(p);
  return out.sort((a, b) => Number(a.metadata?.sort || 9999) - Number(b.metadata?.sort || 9999) || b.created - a.created);
}

// Fotos eines Produkts als Liste mit Rollen (Reihenfolge = Reihenfolge im Shop)
function imagesWithRoles(p) {
  const imgs = p.images || [];
  const uvPos = Number(p.metadata?.uv || 0);
  const kinds = String(p.metadata?.kinds || '').split(',').map((k) => k.trim());
  let k = 0;
  return imgs.map((url, i) => {
    if (i === 0) return { url, role: 'haupt' };
    if (i === uvPos - 1) return { url, role: 'uv' };
    return { url, role: ['detail', 'raum', 'ansicht'].includes(kinds[k]) ? kinds[k++] : (k++, 'ansicht') };
  });
}

const relUrl = (u, cfg = getConfig()) => (u && u.startsWith(cfg.siteUrl) ? u.slice(cfg.siteUrl.length) : u);
const thumbOf = (u) => (/\/bilder\/[a-z0-9-]+\.jpg$/.test(u) ? u.replace(/\.jpg$/, '-xs.jpg') : u);

// ---------- Ansichten ----------
function listPage(products, { msg = '' } = {}) {
  const cfg = getConfig();
  const rows = products.map((p) => {
    const w = fromStripeProduct(p);
    const hidden = ['true', '1', 'ja'].includes(String(p.metadata?.hidden || '').toLowerCase());
    const st = hidden ? 'Ausgeblendet' : w.status === 'sold' ? 'Verkauft' : w.status === 'reserved' ? 'Reserviert' : 'Im Shop';
    return `<li class="at-row${hidden ? ' is-hidden' : ''}">
      <a class="at-row-link" href="/atelier/werk/${esc(p.id)}">
        <span class="at-thumb">${w.image ? `<img src="${esc(relUrl(thumbOf(w.thumb || w.image)))}" alt="" loading="lazy">` : '<span class="at-noimg">Kein Foto</span>'}</span>
        <span class="at-info"><strong>${esc(w.title)}</strong><span class="muted">${esc([w.year, w.size].filter(Boolean).join(' · '))}</span></span>
        <span class="at-price">${w.priceCents ? esc(euro(w.priceCents)) : '<span class="muted">auf Anfrage</span>'}</span>
        <span class="at-status at-status--${hidden ? 'hidden' : w.status}">${st}</span>
      </a>
    </li>`;
  });
  return page('Werke', `
    <div class="at-top"><div><p class="eyebrow">Atelier</p><h1 class="display">Ihre Werke <span class="at-count">${products.length}</span></h1></div>
    <div class="at-top-actions"><a class="btn" href="/atelier/neu">+ Neues Werk</a><a class="btn btn--ghost" href="/atelier/widerrufe">Widerrufe</a>
    <form method="post" action="/atelier/logout"><button class="btn btn--ghost" type="submit">Abmelden</button></form></div></div>
    ${msg ? `<p class="at-ok">${esc(msg)}</p>` : ''}
    <input class="at-search" type="search" placeholder="Werk suchen …" data-at-search aria-label="Werk suchen">
    <ul class="at-list" data-at-list>${rows.join('')}</ul>
    <p class="muted at-hint">Tipp: Reihenfolge im Shop über das Feld „Position“ im Werk. Verkäufe über den Shop werden automatisch als „Verkauft“ markiert.${cfg.demo ? ' <strong>Hinweis:</strong> Stripe ist noch nicht verbunden.' : ''}</p>`);
}

function editPage(p, { error = '', msg = '' } = {}) {
  const isNew = !p?.id;
  const m = p?.metadata || {};
  const w = p ? fromStripeProduct(p) : null;
  const images = p?._images ?? (p ? imagesWithRoles(p).map((x) => ({ ...x, url: relUrl(x.url) })) : []);
  const hidden = ['true', '1', 'ja'].includes(String(m.hidden || '').toLowerCase());
  const priceEur = p?._price ?? (w?.priceCents ? String(w.priceCents / 100).replace('.', ',') : '');
  const fmtTime = (sec) => new Date(sec * 1000).toLocaleString('de-DE', { timeZone: 'Europe/Berlin', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  const reservedUntil = Number(m.reserved_until || 0);
  const statusNote = m.status === 'sold' && m.sold_session
    ? `<p class="at-note">Über den Shop verkauft${/^\d{4}-\d{2}-\d{2}$/.test(m.sold_at || '') ? ` am ${m.sold_at.split('-').reverse().join('.')}` : ''}. Käuferdaten stehen in Stripe unter „Zahlungen“.</p>`
    : reservedUntil > Date.now() / 1000
      ? `<p class="at-note">Gerade reserviert: Jemand ist im Bezahlvorgang (bis ${fmtTime(reservedUntil).slice(-5)} Uhr). Danach wird es automatisch verkauft oder wieder frei.</p>`
      : '';
  const v = (x) => esc(x ?? '');
  return page(isNew ? 'Neues Werk' : w.title, `
    <p class="at-back"><a href="/atelier">← Alle Werke</a></p>
    <div class="at-top"><div><p class="eyebrow">${isNew ? 'Neues Werk' : 'Werk bearbeiten'}</p><h1 class="display">${isNew ? 'Neues Werk anlegen' : v(w.title)}</h1></div>
    ${!isNew && w.slug ? `<a class="btn btn--ghost" href="/werk/${v(w.slug)}" target="_blank" rel="noopener">Im Shop ansehen ↗</a>` : ''}</div>
    ${error ? `<p class="at-error">${esc(error)}</p>` : ''}${msg ? `<p class="at-ok">${esc(msg)}</p>` : ''}
    <form method="post" action="/atelier/speichern" class="at-form at-edit" data-at-edit>
      <input type="hidden" name="id" value="${v(p?.id)}">
      <input type="hidden" name="bilder" value="${v(JSON.stringify(images))}" data-at-images-json>
      <input type="hidden" name="w" value="${v(m.w)}" data-at-w><input type="hidden" name="h" value="${v(m.h)}" data-at-h>

      <section class="at-card">
        <h2>Fotos</h2>
        <p class="muted">Das erste Foto ist das Hauptfoto im Shop. Für jedes weitere Foto wählen Sie, was es zeigt. Bis zu ${MAX_IMAGES} Fotos, eines davon als Schwarzlicht-Aufnahme. Große Fotos werden beim Hochladen automatisch verkleinert.</p>
        <ul class="at-photos" data-at-photos></ul>
        <label class="at-upload">
          <input type="file" accept="image/*" multiple data-at-file>
          <span class="btn btn--ghost">+ Fotos hinzufügen</span>
          <span class="muted" data-at-upload-status></span>
        </label>
      </section>

      <section class="at-card">
        <h2>Angaben</h2>
        <div class="at-grid">
          <label class="field field--wide"><span>Titel *</span><input name="titel" required maxlength="120" value="${v(w?.title)}" placeholder="z. B. NACHT"></label>
          <label class="field"><span>Jahr</span><input name="jahr" inputmode="numeric" maxlength="4" value="${v(m.year)}" placeholder="2026"></label>
          <label class="field"><span>Serie <em>(optional)</em></span><input name="serie" maxlength="60" value="${v(m.series)}" placeholder="z. B. Schirm"></label>
          <label class="field field--wide"><span>Technik</span><input name="technik" maxlength="200" value="${v(m.technique)}" placeholder="Acryl auf Leinwand, UV reaktiv"></label>
          <label class="field field--wide"><span>Maße <em>(Höhe x Breite x Tiefe)</em></span><input name="masse" maxlength="80" value="${v(m.size)}" placeholder="100 x 70 x 2 cm"></label>
          <label class="field field--wide"><span>Beschreibung</span><textarea name="beschreibung" rows="6" maxlength="4000">${v(p?.description)}</textarea></label>
        </div>
      </section>

      <section class="at-card">
        <h2>Preis &amp; Verkauf</h2>
        <div class="at-grid">
          <label class="field"><span>Preis in Euro</span><input name="preis" inputmode="decimal" value="${v(priceEur)}" placeholder="leer = Preis auf Anfrage"></label>
          <label class="field"><span>Eigener Versandpreis <em>(optional)</em></span><input name="versand" inputmode="decimal" value="${v(m.shipping_eur)}" placeholder="sonst nach Größe"></label>
          <fieldset class="field field--wide at-radios"><legend>Status</legend>${statusNote}
            <label><input type="radio" name="status" value="available" ${m.status !== 'sold' ? 'checked' : ''}> Verfügbar</label>
            <label><input type="radio" name="status" value="sold" ${m.status === 'sold' ? 'checked' : ''}> Verkauft</label>
          </fieldset>
          <fieldset class="field field--wide at-radios"><legend>Sichtbarkeit</legend>
            <label><input type="radio" name="sichtbar" value="ja" ${!hidden ? 'checked' : ''}> Im Shop zeigen</label>
            <label><input type="radio" name="sichtbar" value="nein" ${hidden ? 'checked' : ''}> Ausblenden</label>
          </fieldset>
          <label class="field"><span>Position im Shop <em>(1 = ganz vorn)</em></span><input name="position" inputmode="numeric" value="${v(m.sort)}" placeholder="z. B. 5"></label>
        </div>
        <p class="muted">Ohne Preis erscheint „Preis auf Anfrage“ mit Anfrage-Formular. Mit Preis lässt sich das Werk direkt kaufen.</p>
      </section>

      <section class="at-card">
        <h2>Auszeichnungen &amp; Ausstellungen <em class="muted">(optional)</em></h2>
        <div class="at-grid">
          <label class="field field--wide"><span>Auszeichnungen <em>(eine pro Zeile)</em></span><textarea name="auszeichnungen" rows="3">${v(String(m.awards || '').split('|').map((x) => x.trim()).filter(Boolean).join('\n'))}</textarea></label>
          <label class="field field--wide"><span>Ausstellungen <em>(eine pro Zeile)</em></span><textarea name="ausstellungen" rows="3">${v(String(m.exhibitions || '').split('|').map((x) => x.trim()).filter(Boolean).join('\n'))}</textarea></label>
        </div>
      </section>

      <div class="at-save"><button class="btn btn--wide" type="submit" data-at-submit>${isNew ? 'Werk anlegen' : 'Änderungen speichern'}</button></div>
    </form>
    ${!isNew ? `<form method="post" action="/atelier/archivieren" class="at-danger" data-confirm="„${v(w.title)}“ wirklich aus dem Shop entfernen? (Das Werk wird in Stripe archiviert und kann dort wiederhergestellt werden.)">
      <input type="hidden" name="id" value="${v(p.id)}"><button class="btn btn--ghost" type="submit">Werk aus dem Shop entfernen</button></form>` : ''}
    <template data-at-roles>${Object.entries(ROLE_LABEL).filter(([k]) => k !== 'haupt').map(([k, l]) => `<option value="${k}">${l}</option>`).join('')}</template>`);
}

// ---------- Speichern ----------
const parseEuro = (s) => {
  const t = String(s || '').trim().replace(/\s|€/g, '').replace(/\.(?=\d{3}(\D|$))/g, '').replace(',', '.');
  if (!t) return null;
  const n = Number(t);
  return Number.isFinite(n) && n > 0 && n < 1e7 ? Math.round(n * 100) : NaN;
};
// Eingaben nach einem Fehler erhalten (nichts geht verloren)
function draftFrom(form, prev) {
  let imgs = [];
  try { imgs = JSON.parse(String(form.get('bilder') || '[]')); } catch {}
  const g = (k) => String(form.get(k) ?? '');
  return {
    ...(prev || {}),
    id: prev?.id || '',
    name: g('titel'),
    description: g('beschreibung'),
    metadata: { ...(prev?.metadata || {}), year: g('jahr'), series: g('serie'), technique: g('technik'), size: g('masse'), shipping_eur: g('versand'), status: g('status'), hidden: g('sichtbar') === 'nein' ? 'true' : '', sort: g('position'), awards: lines(g('auszeichnungen')), exhibitions: lines(g('ausstellungen')), w: g('w'), h: g('h') },
    _images: Array.isArray(imgs) ? imgs.filter((x) => x && typeof x.url === 'string') : [],
    _price: g('preis'),
  };
}
const lines = (s) => String(s || '').split(/\r?\n/).map((x) => x.trim()).filter(Boolean).join(' | ').slice(0, 500);

async function save(form) {
  const cfg = getConfig();
  const s = stripe();
  const id = String(form.get('id') || '');
  const title = String(form.get('titel') || '').trim().slice(0, 120);
  if (!title) return { error: 'Bitte einen Titel eingeben.' };
  const cents = parseEuro(form.get('preis'));
  if (Number.isNaN(cents)) return { error: 'Der Preis ist ungültig. Bitte nur eine Zahl eingeben, z. B. 2400.' };
  const ship = String(form.get('versand') || '').trim().replace(',', '.');
  if (ship && !(Number(ship) >= 0)) return { error: 'Der Versandpreis ist ungültig.' };

  let images = [];
  try { images = JSON.parse(String(form.get('bilder') || '[]')); } catch { images = []; }
  images = images.filter((x) => x && typeof x.url === 'string' && x.url.length < 600).slice(0, MAX_IMAGES);
  // Reihenfolge für Stripe: Hauptfoto, Schwarzlicht, weitere
  const main = images[0];
  const uv = images.slice(1).find((x) => x.role === 'uv');
  const rest = images.slice(1).filter((x) => x !== uv);
  const abs = (u) => (u.startsWith('/') ? cfg.siteUrl + u : u);
  const ordered = [main, uv, ...rest].filter(Boolean);
  const stripeImages = ordered.map((x) => abs(x.url));

  const metadata = {
    year: String(form.get('jahr') || '').trim().slice(0, 4),
    series: String(form.get('serie') || '').trim().slice(0, 60),
    technique: String(form.get('technik') || '').trim().slice(0, 200),
    size: String(form.get('masse') || '').trim().slice(0, 80),
    shipping_eur: ship,
    status: form.get('status') === 'sold' ? 'sold' : 'available',
    hidden: form.get('sichtbar') === 'nein' ? 'true' : '',
    sort: String(form.get('position') || '').replace(/\D/g, '').slice(0, 5),
    awards: lines(form.get('auszeichnungen')),
    exhibitions: lines(form.get('ausstellungen')),
    price_on_request: cents ? '' : 'true',
    uv: uv ? '2' : '',
    kinds: rest.map((x) => (['detail', 'raum', 'ansicht'].includes(x.role) ? x.role : 'ansicht')).join(','),
    thumb: main && /\/bilder\/[a-z0-9-]+\.jpg$/.test(main.url) ? abs(main.url.replace(/\.jpg$/, '-sm.jpg')) : '',
    w: String(form.get('w') || '').replace(/\D/g, '').slice(0, 5),
    h: String(form.get('h') || '').replace(/\D/g, '').slice(0, 5),
  };
  if (metadata.status === 'available') Object.assign(metadata, { sold_at: '', sold_session: '' });
  const description = String(form.get('beschreibung') || '').trim().slice(0, 4000);

  if (!id) {
    // Eindeutige Adresse im Shop
    const taken = new Set((await allProducts()).map((p) => p.metadata?.slug).filter(Boolean));
    let slug = slugify(title);
    for (let i = 2; taken.has(slug); i++) slug = `${slugify(title)}-${i}`;
    metadata.slug = slug;
    const created = await s.products.create({
      name: title,
      ...(description ? { description } : {}),
      images: stripeImages,
      metadata,
      ...(cents ? { default_price_data: { currency: 'eur', unit_amount: cents } } : {}),
    });
    await purgeCatalogCache();
    return { id: created.id, msg: 'Werk angelegt. Es erscheint in wenigen Minuten im Shop.' };
  }

  const prev = await s.products.retrieve(id, { expand: ['default_price'] });
  const update = { name: title, description: description || '', images: stripeImages.length ? stripeImages : '', metadata };
  const prevCents = prev.default_price?.unit_amount ?? null;
  if (cents && cents !== prevCents) {
    const price = await s.prices.create({ product: id, currency: 'eur', unit_amount: cents });
    update.default_price = price.id;
  }
  await s.products.update(id, update);
  if (cents && prev.default_price?.id && cents !== prevCents) {
    await s.prices.update(prev.default_price.id, { active: false }).catch(() => {});
  }
  await purgeCatalogCache();
  return { id, msg: 'Gespeichert. Der Shop zeigt die Änderung in wenigen Minuten.' };
}

// ---------- Foto-Upload (vom Browser bereits verkleinert) ----------
async function upload(req) {
  const form = await req.formData();
  const id = randomUUID();
  const sizes = [['', 'full'], ['-sm', 'sm'], ['-xs', 'xs']];
  for (const [suffix, field] of sizes) {
    const f = form.get(field);
    if (!f || typeof f === 'string') return Response.json({ error: 'Datei fehlt' }, { status: 400, headers: NOSTORE });
    if (f.size > 4 * 1024 * 1024) return Response.json({ error: 'Foto zu groß' }, { status: 413, headers: NOSTORE });
    const bytes = new Uint8Array(await f.arrayBuffer());
    // JPEG-Kennung prüfen
    if (!(bytes[0] === 0xff && bytes[1] === 0xd8)) return Response.json({ error: 'Nur JPG erlaubt' }, { status: 415, headers: NOSTORE });
    await putImage(`${id}${suffix}.jpg`, bytes, 'image/jpeg');
  }
  return Response.json({ url: `/bilder/${id}.jpg` }, { headers: NOSTORE });
}

// ---------- Router ----------
export default async function handler(req, context) {
  const cfg = getConfig();
  const url = new URL(req.url);
  const path = url.pathname.replace(/\/+$/, '') || '/atelier';

  if (path === '/atelier/login' && req.method === 'POST') {
    if (!sameOrigin(req)) return new Response('Forbidden', { status: 403 });
    // Sperre nach zu vielen Fehlversuchen je IP (gilt auch für das richtige Passwort, bis das Fenster abgelaufen ist)
    const ip = clientIp(req, context);
    const lim = { limit: cfg.limits.loginFailsPerIp, windowSec: cfg.limits.loginWindowMin * 60 };
    if ((await attempts('login', ip, lim)) >= lim.limit) {
      return loginPage(`Zu viele Fehlversuche. Bitte in ${cfg.limits.loginWindowMin} Minuten erneut versuchen.`, cfg, 429);
    }
    const form = await req.formData().catch(() => new FormData());
    if (checkPassword(form.get('passwort') || '')) {
      await resetHits('login', ip);
      return redirect('/atelier', { 'Set-Cookie': loginCookie() });
    }
    await hit('login', ip, lim);
    await new Promise((r) => setTimeout(r, 800)); // bremst Rateversuche zusätzlich
    return loginPage('Das Passwort stimmt nicht.');
  }
  if (path === '/atelier/logout' && req.method === 'POST') return redirect('/atelier', { 'Set-Cookie': logoutCookie() });

  if (!isLoggedIn(req)) {
    if (req.method !== 'GET') return new Response('Bitte anmelden', { status: 401, headers: NOSTORE });
    return loginPage();
  }
  if (req.method === 'POST' && !sameOrigin(req)) return new Response('Forbidden', { status: 403 });
  if (!stripe()) return page('Atelier', `<section class="at-login"><h1 class="display">Atelier</h1><p>Stripe ist noch nicht verbunden. Sobald <code>STRIPE_SECRET_KEY</code> gesetzt ist, können hier Werke gepflegt werden.</p></section>`);

  try {
    if (path === '/atelier' && req.method === 'GET') return listPage(await allProducts(), { msg: url.searchParams.get('ok') || '' });
    if (path === '/atelier/neu' && req.method === 'GET') return editPage(null);
    if (path === '/atelier/widerrufe' && req.method === 'GET') {
      const recs = await listRecords('widerrufe');
      return page('Widerrufe', `<p class="at-back"><a href="/atelier">← Alle Werke</a></p>
        <div class="at-top"><div><p class="eyebrow">Nachweise</p><h1 class="display">Widerrufe</h1></div></div>
        <p class="muted">Hier landen alle Widerrufe, die Käufer über „Vertrag widerrufen“ abgeschickt haben. Sie bekommen zusätzlich jedes Mal eine E-Mail. Nach Rückerhalt des Werks: Zahlung in Stripe erstatten und das Werk wieder auf „Verfügbar“ stellen.</p>
        ${recs.length ? `<ul class="at-list">${recs.map((r) => `<li class="at-card at-withdraw"><strong>${esc(r.contract)}</strong><span>${esc(r.name)} · <a href="mailto:${esc(r.email)}">${esc(r.email)}</a></span><span class="muted">Eingang ${esc(r.receivedLabel)} · ${esc(r.ref)}</span></li>`).join('')}</ul>` : '<p class="at-card">Bisher keine Widerrufe.</p>'}`);
    }
    if (path.startsWith('/atelier/werk/') && req.method === 'GET') {
      const id = path.slice('/atelier/werk/'.length);
      if (!/^prod_[A-Za-z0-9]+$/.test(id)) return redirect('/atelier');
      const p = await stripe().products.retrieve(id, { expand: ['default_price'] }).catch(() => null);
      if (!p || !p.active) return redirect('/atelier');
      return editPage(p, { msg: url.searchParams.get('ok') || '' });
    }
    if (path === '/atelier/upload' && req.method === 'POST') return await upload(req);
    if (path === '/atelier/speichern' && req.method === 'POST') {
      const form = await req.formData();
      const r = await save(form);
      if (r.error) {
        const id = String(form.get('id') || '');
        const prev = id ? await stripe().products.retrieve(id, { expand: ['default_price'] }).catch(() => null) : null;
        return editPage(draftFrom(form, prev), { error: r.error });
      }
      return redirect(`/atelier/werk/${r.id}?ok=${encodeURIComponent(r.msg)}`);
    }
    if (path === '/atelier/archivieren' && req.method === 'POST') {
      const form = await req.formData();
      const id = String(form.get('id') || '');
      if (/^prod_[A-Za-z0-9]+$/.test(id)) {
        await stripe().products.update(id, { active: false });
        await purgeCatalogCache();
      }
      return redirect(`/atelier?ok=${encodeURIComponent('Werk aus dem Shop entfernt.')}`);
    }
    return redirect('/atelier');
  } catch (e) {
    console.error('Atelier-Fehler', e);
    return page('Fehler', `<section class="at-login"><h1 class="display">Das hat nicht geklappt</h1><p>${esc(e.message || 'Unbekannter Fehler')}</p><p><a class="btn" href="/atelier">Zurück</a></p></section>`, { status: 500 });
  }
}

export const config = { path: ['/atelier', '/atelier/*'] };
