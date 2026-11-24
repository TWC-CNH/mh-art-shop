<?php
declare(strict_types=1);

namespace App;

/**
 * Zentrale Konfiguration. Schlüssel entsprechen der früheren Node-Fassung (getConfig()),
 * damit Templates 1:1 übernommen werden konnten. Werte kommen aus .env bzw. der Umgebung.
 */
final class Config
{
    public const FORMAT_LABELS = ['klein' => 'Klein (bis 60 cm)', 'mittel' => 'Mittel (60–99 cm)', 'gross' => 'Groß (ab 100 cm)'];

    private static ?array $cfg = null;

    public static function reset(): void
    {
        self::$cfg = null;
    }

    private static function bool(string $v): bool
    {
        return in_array(strtolower($v), ['1', 'true', 'yes', 'ja'], true);
    }

    private static function num(string $key, string $default, int $min = 0): int
    {
        return max($min, (int) Env::get($key, $default));
    }

    public static function get(): array
    {
        if (self::$cfg !== null) {
            return self::$cfg;
        }
        $e = [Env::class, 'get'];
        $siteUrl = rtrim($e('SITE_URL', 'http://localhost:8888'), '/');

        return self::$cfg = [
            'siteUrl' => $siteUrl,
            'env' => $e('APP_ENV', 'production'), // "local" schaltet Fehlerausgabe ein
            'stripeSecretKey' => $e('STRIPE_SECRET_KEY'),
            'stripeWebhookSecret' => $e('STRIPE_WEBHOOK_SECRET'),
            'stripeApiBase' => $e('STRIPE_API_BASE'), // nur für Tests gegen einen lokalen Stripe-Ersatz
            // Ohne Stripe-Schlüssel: Shop zeigt Werke, Kaufen ist gesperrt (Hinweis "Demo-Modus").
            'demo' => $e('STRIPE_SECRET_KEY') === '',

            'db' => [
                'host' => $e('DB_HOST', 'localhost'),
                'port' => (int) $e('DB_PORT', '3306'),
                'name' => $e('DB_NAME'),
                'user' => $e('DB_USER'),
                'pass' => $e('DB_PASS'),
            ],

            'shopName' => $e('SHOP_NAME', 'MH ART'),
            'artistName' => $e('ARTIST_NAME', 'Matthias Hübner'),
            'tagline' => $e('SHOP_TAGLINE', 'Modern Surrealistic Realism'),
            'contactEmail' => $e('CONTACT_EMAIL', 'anfragen@matthiashuebner.art'),
            'contactPhone' => $e('CONTACT_PHONE', '+49 177 6936000'),
            'instagram' => $e('INSTAGRAM_URL', 'https://www.instagram.com/matthiashubner.art/'),
            'facebook' => $e('FACEBOOK_URL', 'https://www.facebook.com/matthiashuebnerdraws/'),
            'mainSite' => $e('MAIN_SITE_URL', 'https://www.novaspes.de'),

            'address' => [
                'name' => $e('LEGAL_NAME', 'Matthias Hübner'),
                'street' => $e('LEGAL_STREET', 'Richard-Wagner-Straße 21'),
                'zip' => $e('LEGAL_ZIP', '06258'),
                'city' => $e('LEGAL_CITY', 'Schkopau'),
                'country' => 'Deutschland',
            ],
            'vatId' => $e('VAT_ID', 'DE454915656'),
            'kleinunternehmer' => self::bool($e('KLEINUNTERNEHMER', 'true')),

            'shipping' => [
                'klein' => (float) $e('SHIPPING_SMALL_EUR', '29'),
                'mittel' => (float) $e('SHIPPING_MEDIUM_EUR', '49'),
                'gross' => (float) $e('SHIPPING_LARGE_EUR', '89'),
            ],
            'shippingCountries' => array_values(array_filter(array_map(fn ($s) => strtoupper(trim($s)), explode(',', $e('SHIPPING_COUNTRIES', 'DE'))))),
            'pickupEnabled' => self::bool($e('PICKUP_ENABLED', 'true')),
            'pickupLabel' => $e('PICKUP_LABEL', 'Persönliche Abholung im Atelier (nach Absprache)'),
            'deliveryDays' => $e('DELIVERY_DAYS', '3-7'),

            // Stripe verlangt für Checkout-Sitzungen mindestens 30 Minuten Laufzeit.
            'reserveMinutes' => self::num('RESERVE_MINUTES', '30', 30),
            'requireTermsConsent' => self::bool($e('STRIPE_TERMS_CONSENT', 'false')),
            'invoiceCreation' => self::bool($e('STRIPE_INVOICE_CREATION', 'false')),
            'allowPromotionCodes' => self::bool($e('ALLOW_PROMOTION_CODES', 'false')),
            'showSold' => self::bool($e('SHOW_SOLD', 'true')),

            'smtp' => [
                'host' => $e('SMTP_HOST'),
                'port' => (int) $e('SMTP_PORT', '587'),
                'user' => $e('SMTP_USER'),
                'pass' => $e('SMTP_PASS'),
                'from' => $e('SMTP_FROM') ?: ($e('SMTP_USER') ?: $e('CONTACT_EMAIL', 'anfragen@matthiashuebner.art')),
            ],

            // Atelier: nur der Hash des Passworts (bin/atelier-password.php erzeugt ihn)
            'atelierPasswordHash' => $e('ATELIER_PASSWORD_HASH'),

            // Personenbezogene Daten (Widerrufe, Anfragen): 32 Byte, Base64 oder Hex
            'dataKey' => $e('DATA_ENCRYPTION_KEY'),
            'dataKeyOld' => $e('DATA_ENCRYPTION_KEY_OLD'),

            // Missbrauchsschutz (je IP-Adresse bzw. Empfänger)
            'limits' => [
                'checkoutPerIp' => self::num('LIMIT_CHECKOUT_PER_IP', '3', 1),
                'loginFailsPerIp' => self::num('LIMIT_LOGIN_FAILS_PER_IP', '10', 1),
                'loginWindowMin' => self::num('LIMIT_LOGIN_WINDOW_MIN', '15', 1),
                'withdrawPerIp' => self::num('LIMIT_WITHDRAW_PER_IP', '5', 1),
                'withdrawMailsPerRecipient' => self::num('LIMIT_WITHDRAW_MAILS_PER_RECIPIENT', '2', 1),
                'inquiryPerIp' => self::num('LIMIT_INQUIRY_PER_IP', '10', 1),
            ],

            // Aufbewahrung personenbezogener Daten (Löschung per Cron)
            'retention' => [
                'inquiryMonths' => self::num('RETENTION_INQUIRY_MONTHS', '12', 1),
                'withdrawalYears' => self::num('RETENTION_WITHDRAWAL_YEARS', '3', 1),
            ],

            'creditLabel' => $e('CREDIT_LABEL', 'Tobias Wolfrum Consulting'),
            'creditUrl' => $e('CREDIT_URL', 'https://www.tobiaswolfrum.de'),

            'googleTagId' => $e('GOOGLE_TAG_ID'),
            'googleAdsConversion' => $e('GOOGLE_ADS_CONVERSION'),

            'uploadDir' => $e('UPLOAD_DIR', dirname(__DIR__) . '/public/bilder'),
        ];
    }
}
