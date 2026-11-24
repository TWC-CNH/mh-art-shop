// Zentrale Einstellungen des Shops.
// Alles, was sich pro Installation ändert, kommt aus Umgebungsvariablen (Netlify > Site configuration > Environment variables).
// Die Standardwerte unten passen für Matthias Hübner / MH ART.

const env = (key, fallback = '') => {
  const fromNetlify = typeof Netlify !== 'undefined' ? Netlify.env?.get?.(key) : undefined;
  const v = fromNetlify ?? process.env[key];
  return v === undefined || v === null || v === '' ? fallback : v;
};

const bool = (v) => ['1', 'true', 'yes', 'ja'].includes(String(v).toLowerCase());

export function getConfig() {
  const siteUrl = env('SITE_URL', env('URL', 'http://localhost:8888')).replace(/\/$/, '');
  return {
    siteUrl,
    stripeSecretKey: env('STRIPE_SECRET_KEY'),
    stripeWebhookSecret: env('STRIPE_WEBHOOK_SECRET'),
    // Ohne Stripe-Schlüssel läuft der Shop im Demo-Modus mit data/catalog.json (Kauf-Button zeigt Hinweis).
    demo: !env('STRIPE_SECRET_KEY'),

    shopName: env('SHOP_NAME', 'MH ART'),
    artistName: env('ARTIST_NAME', 'Matthias Hübner'),
    tagline: env('SHOP_TAGLINE', 'Modern Surrealistic Realism'),
    contactEmail: env('CONTACT_EMAIL', 'anfragen@matthiashuebner.art'),
    contactPhone: env('CONTACT_PHONE', '+49 177 6936000'),
    instagram: env('INSTAGRAM_URL', 'https://www.instagram.com/matthiashubner.art/'),
    facebook: env('FACEBOOK_URL', 'https://www.facebook.com/matthiashuebnerdraws/'),
    mainSite: env('MAIN_SITE_URL', 'https://www.novaspes.de'),

    // Rechtliches
    address: {
      name: env('LEGAL_NAME', 'Matthias Hübner'),
      street: env('LEGAL_STREET', 'Richard-Wagner-Straße 21'),
      zip: env('LEGAL_ZIP', '06258'),
      city: env('LEGAL_CITY', 'Schkopau'),
      country: 'Deutschland',
    },
    vatId: env('VAT_ID', 'DE454915656'),
    // Kleinunternehmer nach § 19 UStG: keine Umsatzsteuer ausweisen.
    kleinunternehmer: bool(env('KLEINUNTERNEHMER', 'true')),

    // Versand: Preise in Euro je Formatklasse (längste Seite < 60 cm = klein, < 100 cm = mittel, sonst groß).
    // Pro Werk überschreibbar über Stripe-Metadaten "shipping_eur".
    shipping: {
      klein: Number(env('SHIPPING_SMALL_EUR', '29')),
      mittel: Number(env('SHIPPING_MEDIUM_EUR', '49')),
      gross: Number(env('SHIPPING_LARGE_EUR', '89')),
    },
    shippingCountries: env('SHIPPING_COUNTRIES', 'DE').split(',').map((s) => s.trim().toUpperCase()).filter(Boolean),
    pickupEnabled: bool(env('PICKUP_ENABLED', 'true')),
    pickupLabel: env('PICKUP_LABEL', 'Persönliche Abholung im Atelier (nach Absprache)'),
    deliveryDays: env('DELIVERY_DAYS', '3-7'),

    // Stripe-Checkout
    reserveMinutes: Math.max(30, Number(env('RESERVE_MINUTES', '30'))),
    requireTermsConsent: bool(env('STRIPE_TERMS_CONSENT', 'false')),
    invoiceCreation: bool(env('STRIPE_INVOICE_CREATION', 'false')),
    // Gutscheincodes im Checkout erlauben (Codes legt Matthias im Stripe-Dashboard unter Produkte > Gutscheine an)
    allowPromotionCodes: bool(env('ALLOW_PROMOTION_CODES', 'false')),
    showSold: bool(env('SHOW_SOLD', 'true')),

    // Atelier (Verwaltung unter /atelier) – Passwort für Matthias
    smtp: {
      host: env('SMTP_HOST', ''),
      port: Number(env('SMTP_PORT', '465')),
      user: env('SMTP_USER', ''),
      pass: env('SMTP_PASS', ''),
      from: env('SMTP_FROM', '') || env('SMTP_USER', '') || env('CONTACT_EMAIL', 'anfragen@matthiashuebner.art'),
    },
    atelierPassword: env('ATELIER_PASSWORD'),
    atelierSecret: env('ATELIER_SECRET'),

    // Schutz vor Missbrauch (je IP-Adresse bzw. Empfänger, Fenster in Minuten)
    limits: {
      checkoutPerIp: Math.max(1, Number(env('LIMIT_CHECKOUT_PER_IP', '3'))), // neue Bezahlvorgänge (= Reservierungen) je Reservierungsdauer
      loginFailsPerIp: Math.max(1, Number(env('LIMIT_LOGIN_FAILS_PER_IP', '10'))), // falsche Atelier-Passwörter, danach Sperre
      loginWindowMin: Math.max(1, Number(env('LIMIT_LOGIN_WINDOW_MIN', '15'))),
      withdrawPerIp: Math.max(1, Number(env('LIMIT_WITHDRAW_PER_IP', '5'))), // Widerrufe je Stunde
      withdrawMailsPerRecipient: Math.max(1, Number(env('LIMIT_WITHDRAW_MAILS_PER_RECIPIENT', '2'))), // Bestätigungsmails je Adresse und Tag
    },

    // Kleiner Hinweis im Seitenfuß (Backlink)
    creditLabel: env('CREDIT_LABEL', 'Tobias Wolfrum Consulting'),
    creditUrl: env('CREDIT_URL', 'https://www.tobiaswolfrum.de'),

    // Optional: Google Ads / Google Tag (wird erst nach Einwilligung geladen)
    googleTagId: env('GOOGLE_TAG_ID'),
    googleAdsConversion: env('GOOGLE_ADS_CONVERSION'), // z. B. "AW-123456789/AbCdEfGh"
  };
}

export const FORMAT_LABELS = { klein: 'Klein (bis 60 cm)', mittel: 'Mittel (60–99 cm)', gross: 'Groß (ab 100 cm)' };
