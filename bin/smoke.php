<?php
// Selbsttest nach jedem Deployment: php bin/smoke.php
// Prüft PHP-Erweiterungen, vendor/, .env, Datenbank, Schema, Schlüssel und Schreibrechte. Keine Web-Anfragen.
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use App\Config;
use App\Crypto;
use App\Db;

$fail = 0;
$check = function (string $name, bool $ok, string $hint = '') use (&$fail) {
    echo ($ok ? '  ok   ' : '  FEHLT ') . $name . ($ok || $hint === '' ? '' : "  → $hint") . "\n";
    if (!$ok) {
        $fail++;
    }
};
$cfg = Config::get();

echo "PHP " . PHP_VERSION . "\n";
$check('PHP 8.3 oder neuer', version_compare(PHP_VERSION, '8.3.0', '>='));
foreach (['pdo_mysql', 'sodium', 'gd', 'mbstring', 'curl', 'json'] as $ext) {
    $check("Erweiterung $ext", extension_loaded($ext), 'andere PHP-Version wählen oder Hetzner-Support');
}
$check('GD mit WebP (für kleinere Fotos)', function_exists('imagewebp') && !empty(gd_info()['WebP Support']), 'ohne WebP liefert der Shop nur JPG aus – funktioniert, ist aber größer');
$check('vendor/ vorhanden (Stripe, PHPMailer)', class_exists(\Stripe\StripeClient::class) && class_exists(\PHPMailer\PHPMailer\PHPMailer::class), 'lokal composer install --no-dev und vendor/ hochladen');
$check('.env vorhanden', is_file(dirname(__DIR__) . '/.env'));
$check('SITE_URL mit https', str_starts_with($cfg['siteUrl'], 'https://'), 'SITE_URL=https://matthiashuebner.art');

try {
    $v = Db::one('SELECT VERSION() AS v')['v'];
    $check("Datenbank erreichbar ($v)", true);
    $missing = array_diff(['werke', 'werk_bilder', 'bestellungen', 'webhook_events', 'widerrufe', 'anfragen', 'rate_limits', 'atelier_sessions', 'atelier_benutzer', 'atelier_notfallcodes', 'merker'], array_column(Db::all('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()'), 't'));
    $check('Schema vollständig', !$missing, 'php bin/migrate.php (fehlt: ' . implode(', ', $missing) . ')');
    $n = (int) Db::one('SELECT COUNT(*) AS n FROM werke WHERE aktiv = 1')['n'];
    $check("Werke vorhanden ($n)", $n > 0, 'php bin/import-catalog.php');
    $cron = Db::one('SELECT wert FROM merker WHERE name = ?', ['cron_letzter_lauf']);
    echo '  info  letzter Cron-Lauf: ' . ($cron['wert'] ?? 'noch nie') . " (UTC)\n";
} catch (\Throwable $e) {
    $check('Datenbank erreichbar', false, $e->getMessage());
}

$check('DATA_ENCRYPTION_KEY gültig', Crypto::ready(), 'php bin/generate-key.php');
if (Crypto::ready()) {
    $ok = Crypto::decrypt(Crypto::encrypt(['t' => 'ä'], 'smoke'), 'smoke') === ['t' => 'ä'];
    $check('Verschlüsselung funktioniert', $ok);
}
try {
    $check('Atelier-Benutzer mit 2FA vorhanden', \App\AtelierAuth::configured(), 'php bin/atelier-user.php anlegen <name>');
} catch (\Throwable $e) {
    $check('Atelier-Benutzer mit 2FA vorhanden', false, 'php bin/migrate.php');
}
$check('Upload-Ordner beschreibbar', is_dir($cfg['uploadDir']) && is_writable($cfg['uploadDir']), $cfg['uploadDir']);
$check('Schutz im Upload-Ordner (.htaccess)', is_file($cfg['uploadDir'] . '/.htaccess'));
$check('STRIPE_SECRET_KEY gesetzt', $cfg['stripeSecretKey'] !== '', 'ohne Schlüssel ist Kaufen gesperrt');
if ($cfg['stripeSecretKey'] !== '') {
    echo '  info  Stripe-Modus: ' . (str_starts_with($cfg['stripeSecretKey'], 'sk_live_') ? 'LIVE' : 'Test') . "\n";
    $check('STRIPE_WEBHOOK_SECRET gesetzt', $cfg['stripeWebhookSecret'] !== '', 'Webhook im Stripe-Dashboard anlegen');
}
$check('SMTP eingerichtet', $cfg['smtp']['host'] !== '' && $cfg['smtp']['user'] !== '' && $cfg['smtp']['pass'] !== '', 'Pflicht für Widerrufsbestätigungen');

echo $fail ? "\n$fail Punkt(e) offen.\n" : "\nAlles in Ordnung.\n";
exit($fail ? 1 : 0);
