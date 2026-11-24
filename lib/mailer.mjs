// E-Mail-Versand über das Postfach des Shops (SMTP, z. B. das Postfach von anfragen@…).
// Ohne SMTP-Zugangsdaten wird nichts versendet (sent: false) – der Aufrufer entscheidet, was dann passiert.
import { getConfig } from './config.mjs';

export async function sendMail({ to, subject, text, html, replyTo, bcc }) {
  const cfg = getConfig();
  if (!cfg.smtp.host || !cfg.smtp.user || !cfg.smtp.pass) return { sent: false, reason: 'not-configured' };
  const { default: nodemailer } = await import('nodemailer');
  const transport = nodemailer.createTransport({
    host: cfg.smtp.host,
    port: cfg.smtp.port,
    secure: cfg.smtp.port === 465,
    auth: { user: cfg.smtp.user, pass: cfg.smtp.pass },
  });
  try {
    await transport.sendMail({ from: `"${cfg.artistName}" <${cfg.smtp.from}>`, to, bcc, replyTo, subject, text, html });
    return { sent: true };
  } catch (e) {
    console.error('E-Mail-Versand fehlgeschlagen', e.message);
    return { sent: false, reason: e.message };
  }
}
