// Elektronische Widerrufsfunktion nach § 356a BGB („Vertrag widerrufen“ → „Widerruf bestätigen“ → Eingangsbestätigung per E-Mail)
import { randomUUID } from 'node:crypto';
import { getConfig } from '../../lib/config.mjs';
import { layout, esc } from '../../lib/render.mjs';
import { sendMail } from '../../lib/mailer.mjs';
import { putRecord } from '../../lib/record-store.mjs';
import { sameOrigin } from '../../lib/atelier-auth.mjs';
import { clientIp, hit } from '../../lib/rate-limit.mjs';

const NOSTORE = { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' };
const EMAIL_RE = /^[^\s@<>"]+@[^\s@<>"]+\.[^\s@<>"]{2,}$/;

const stamp = (d) =>
  d.toLocaleString('de-DE', { timeZone: 'Europe/Berlin', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' }) + ' Uhr';

function formPage({ values = {}, error = '' } = {}) {
  const v = (k) => esc(values[k] || '');
  return layout({
    title: 'Vertrag widerrufen',
    canonical: '/vertrag-widerrufen',
    noindex: true,
    bodyClass: 'page-legal',
    body: `<section class="legal withdraw">
  <h1>Vertrag widerrufen</h1>
  <p>Sie haben ein Werk gekauft und möchten den Kauf innerhalb der Widerrufsfrist widerrufen? Füllen Sie die drei Felder aus und klicken Sie auf „Widerruf bestätigen“. Einen Grund müssen Sie nicht angeben. Sie erhalten sofort eine Eingangsbestätigung per E-Mail.</p>
  ${error ? `<p class="form-error" role="alert">${esc(error)}</p>` : ''}
  <form method="post" action="/vertrag-widerrufen" class="withdraw-form" novalidate>
    <p class="hp" aria-hidden="true"><label>Bitte leer lassen <input name="website" tabindex="-1" autocomplete="off"></label></p>
    <label class="field"><span>Ihr Name *</span><input name="name" required maxlength="120" autocomplete="name" value="${v('name')}"></label>
    <label class="field"><span>Welchen Kauf möchten Sie widerrufen? *</span><input name="vertrag" required maxlength="300" value="${v('vertrag')}" placeholder="z. B. Werk „NACHT“, gekauft am 20.11.2026"><small>Titel des Werks, Kaufdatum oder Bestellnummer aus der Kaufbestätigung</small></label>
    <label class="field"><span>Ihre E-Mail-Adresse für die Eingangsbestätigung *</span><input name="email" type="email" required maxlength="200" autocomplete="email" value="${v('email')}"></label>
    <button class="btn btn--wide" type="submit">Widerruf bestätigen</button>
    <p class="muted small">Mit dem Klick senden Sie Ihre Widerrufserklärung ab. Fragen vorab? <a href="mailto:${esc(getConfig().contactEmail)}">${esc(getConfig().contactEmail)}</a>. Infos zum Ablauf in der <a href="/widerruf">Widerrufsbelehrung</a>.</p>
  </form>
</section>`,
  });
}

function donePage(rec, mailed) {
  const cfg = getConfig();
  return layout({
    title: 'Widerruf eingegangen',
    canonical: '/vertrag-widerrufen',
    noindex: true,
    bodyClass: 'page-legal',
    body: `<section class="legal withdraw">
  <h1>Ihr Widerruf ist eingegangen</h1>
  <dl class="withdraw-receipt">
    <dt>Eingang</dt><dd>${esc(rec.receivedLabel)}</dd>
    <dt>Name</dt><dd>${esc(rec.name)}</dd>
    <dt>Widerrufener Kauf</dt><dd>${esc(rec.contract)}</dd>
    <dt>E-Mail</dt><dd>${esc(rec.email)}</dd>
    <dt>Vorgangsnummer</dt><dd>${esc(rec.ref)}</dd>
  </dl>
  <p>${mailed ? `Die Eingangsbestätigung mit diesen Angaben ist an <strong>${esc(rec.email)}</strong> unterwegs.` : `Die Bestätigungs-E-Mail konnte gerade nicht versendet werden. Bitte speichern oder drucken Sie diese Seite als Nachweis. Ihr Widerruf ist trotzdem eingegangen und gespeichert.`}</p>
  <p>Ich melde mich in Kürze bei Ihnen, um die Rückgabe des Werks abzustimmen. Fragen: <a href="mailto:${esc(cfg.contactEmail)}">${esc(cfg.contactEmail)}</a></p>
  <p><button type="button" class="btn btn--ghost" onclick="window.print()">Seite drucken</button></p>
</section>`,
  });
}

function mailText(rec, cfg) {
  return `Guten Tag ${rec.name},

hiermit bestätige ich den Eingang Ihrer Widerrufserklärung.

Inhalt Ihrer Erklärung:
„Hiermit widerrufe ich den folgenden Vertrag: ${rec.contract}“

Name: ${rec.name}
E-Mail: ${rec.email}
Eingang: ${rec.receivedLabel}
Vorgangsnummer: ${rec.ref}

Ich melde mich in Kürze, um die Rückgabe des Werks mit Ihnen abzustimmen.

Viele Grüße
${cfg.artistName}
${cfg.address.street}, ${cfg.address.zip} ${cfg.address.city}
${cfg.contactEmail}
${cfg.siteUrl}`;
}

export default async function handler(req, context) {
  const cfg = getConfig();
  if (req.method === 'GET') return new Response(formPage(), { headers: NOSTORE });
  if (req.method !== 'POST') return new Response('Method not allowed', { status: 405 });
  if (!sameOrigin(req)) return new Response('Forbidden', { status: 403 });

  const form = await req.formData().catch(() => new FormData());
  const g = (k, n) => String(form.get(k) || '').replace(/\s+/g, ' ').trim().slice(0, n);
  const values = { name: g('name', 120), vertrag: g('vertrag', 300), email: g('email', 200).toLowerCase() };
  if (g('website', 100)) return new Response(formPage(), { headers: NOSTORE }); // Spam-Falle
  if (!values.name || !values.vertrag || !EMAIL_RE.test(values.email)) {
    return new Response(formPage({ values, error: 'Bitte Name, den betroffenen Kauf und eine gültige E-Mail-Adresse angeben.' }), { status: 422, headers: NOSTORE });
  }

  // Missbrauchsschutz: Das Formular verschickt Mails über das Shop-Postfach an frei eingegebene Adressen.
  // Je IP nur wenige Widerrufe pro Stunde; der Widerruf per E-Mail bleibt immer möglich.
  const perIp = await hit('widerruf-ip', clientIp(req, context), { limit: cfg.limits.withdrawPerIp, windowSec: 3600 });
  if (!perIp.ok) {
    return new Response(formPage({ values, error: `Von Ihrem Anschluss wurden gerade sehr viele Widerrufe abgeschickt. Bitte versuchen Sie es später noch einmal oder senden Sie Ihren Widerruf per E-Mail an ${cfg.contactEmail}.` }), {
      status: 429,
      headers: { ...NOSTORE, 'Retry-After': String(perIp.retryAfter) },
    });
  }

  const now = new Date();
  const rec = {
    ref: 'W-' + now.toISOString().slice(0, 10).replace(/-/g, '') + '-' + randomUUID().slice(0, 6).toUpperCase(),
    receivedAt: now.toISOString(),
    receivedLabel: stamp(now),
    name: values.name,
    contract: values.vertrag,
    email: values.email,
  };

  const key = now.toISOString().replace(/[:.]/g, '-') + '_' + rec.ref;
  try { await putRecord('widerrufe', key, rec); } catch (e) { console.error('Widerruf speichern fehlgeschlagen', e); }

  const subject = `Eingangsbestätigung Ihres Widerrufs (${rec.ref})`;
  // Je Empfänger nur wenige Bestätigungen pro Tag, damit niemand fremde Postfächer damit zuschüttet.
  // Der Widerruf ist trotzdem gespeichert, die Seite dient dann als Nachweis.
  const perRecipient = await hit('widerruf-mail', rec.email, { limit: cfg.limits.withdrawMailsPerRecipient, windowSec: 86400 });
  const mail = perRecipient.ok
    ? await sendMail({ to: rec.email, replyTo: cfg.contactEmail, subject, text: mailText(rec, cfg) })
    : { sent: false, reason: 'Limit je Empfänger erreicht' };
  // Nachricht an Matthias
  await sendMail({
    to: cfg.contactEmail,
    replyTo: rec.email,
    subject: `Widerruf eingegangen: ${rec.contract}`,
    text: `Über den Shop ist ein Widerruf eingegangen.\n\nName: ${rec.name}\nKauf: ${rec.contract}\nE-Mail: ${rec.email}\nEingang: ${rec.receivedLabel}\nVorgang: ${rec.ref}\n\nEingangsbestätigung an den Käufer: ${mail.sent ? 'versendet' : 'NICHT versendet (' + (mail.reason || '') + ')'}\n\nNächste Schritte: Rückgabe abstimmen, nach Erhalt in Stripe erstatten und das Werk im Atelier wieder auf „Verfügbar“ stellen.`,
  });
  console.log('Widerruf eingegangen', rec.ref, 'Mail:', mail.sent);
  return new Response(donePage(rec, mail.sent), { headers: NOSTORE });
}

export const config = { path: '/vertrag-widerrufen' };
