<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Env;

/**
 * Startet für die Tests einen echten PHP-Server mit dem Shop und einen Stripe-Ersatz,
 * beide mit eigener Testdatenbank (DB_NAME aus TEST_DB_NAME, Standard mhart_shop_test) und temporären Ordnern.
 */
final class Stack
{
    public const SHOP_PORT = 18080;
    public const STRIPE_PORT = 18081;
    public const PASSWORD = 'Pinsel-2026-Atelier-Test';
    public const WEBHOOK_SECRET = 'whsec_test_secret';

    private static array $procs = [];
    private static ?array $env = null;
    public static string $tmp = '';

    public static function base(): string
    {
        return 'http://127.0.0.1:' . self::SHOP_PORT;
    }

    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** Umgebung für Shop, Skripte und den Testprozess selbst. */
    public static function env(): array
    {
        if (self::$env !== null) {
            return self::$env;
        }
        Env::load(self::root() . '/.env'); // nur für die lokalen DB-Zugangsdaten
        self::$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mhart-test-' . getmypid();
        @mkdir(self::$tmp . '/bilder', 0777, true);
        copy(self::root() . '/public/bilder/.htaccess', self::$tmp . '/bilder/.htaccess');
        return self::$env = [
            'APP_ENV' => 'test',
            'SITE_URL' => self::base(),
            'DB_HOST' => Env::get('TEST_DB_HOST', Env::get('DB_HOST', '127.0.0.1')),
            'DB_PORT' => Env::get('TEST_DB_PORT', Env::get('DB_PORT', '3306')),
            'DB_NAME' => Env::get('TEST_DB_NAME', 'mhart_shop_test'),
            'DB_USER' => Env::get('TEST_DB_USER', Env::get('DB_USER')),
            'DB_PASS' => Env::get('TEST_DB_PASS', Env::get('DB_PASS')),
            'STRIPE_SECRET_KEY' => 'sk_test_fake',
            'STRIPE_API_BASE' => 'http://127.0.0.1:' . self::STRIPE_PORT,
            'STRIPE_WEBHOOK_SECRET' => self::WEBHOOK_SECRET,
            'DATA_ENCRYPTION_KEY' => str_repeat('a', 64),
            'DATA_ENCRYPTION_KEY_OLD' => '',
            'TRUSTED_PROXIES' => '127.0.0.1', // Tests simulieren verschiedene Besucher-IPs über X-Forwarded-For
            'MAIL_CAPTURE_DIR' => self::$tmp . '/mails',
            'UPLOAD_DIR' => self::$tmp . '/bilder',
            'LOG_DIR' => self::$tmp . '/logs',
            'RUN_DIR' => self::$tmp . '/run',
            'FAKE_STRIPE_STATE' => self::$tmp . '/stripe.json',
            'LIMIT_LOGIN_FAILS_PER_IP' => '3',
            'SMTP_HOST' => '', 'SMTP_USER' => '', 'SMTP_PASS' => '',
            'GOOGLE_TAG_ID' => '', 'GOOGLE_ADS_CONVERSION' => '',
            'ALLOW_PROMOTION_CODES' => '', 'SHOW_SOLD' => 'true', 'KLEINUNTERNEHMER' => 'true',
        ];
    }

    /** Die Testumgebung auch im Testprozess setzen (für direkte Aufrufe von Klassen und Skripten). */
    public static function applyToProcess(): void
    {
        foreach (self::env() as $k => $v) {
            putenv("$k=$v");
        }
        \App\Config::reset();
        \App\Db::reset();
    }

    public static function php(string $script, array $args = [], array $extraEnv = []): string
    {
        $cmd = array_merge([PHP_BINARY, self::root() . '/' . $script], $args);
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::root(), $extraEnv + getenv() + self::env());
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        $code = proc_close($p);
        if ($code !== 0) {
            throw new \RuntimeException("$script fehlgeschlagen ($code): $out");
        }
        return $out;
    }

    /** Testdatenbank leeren, Schema anlegen, Werke importieren. */
    public static function resetDatabase(): void
    {
        self::applyToProcess();
        $pdo = \App\Db::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchAll(\PDO::FETCH_COLUMN) as $t) {
            $pdo->exec("DROP TABLE `$t`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        self::php('bin/migrate.php');
        self::php('bin/import-catalog.php');
    }

    public static function start(): void
    {
        if (self::$procs) {
            return;
        }
        self::resetDatabase();
        @unlink(self::env()['FAKE_STRIPE_STATE']);
        $env = getenv() + self::env();
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        self::$procs[] = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . self::STRIPE_PORT, self::root() . '/tests/FakeStripe/router.php'], [1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $p1, self::root(), $env);
        self::$procs[] = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . self::SHOP_PORT, '-t', self::root() . '/public', self::root() . '/tests/Support/serve.php'], [1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $p2, self::root(), $env);
        foreach ([self::SHOP_PORT, self::STRIPE_PORT] as $port) {
            for ($i = 0; $i < 50; $i++) {
                $s = @fsockopen('127.0.0.1', $port, $en, $es, 0.2);
                if ($s) {
                    fclose($s);
                    continue 2;
                }
                usleep(100000);
            }
            throw new \RuntimeException("Server auf Port $port startet nicht");
        }
        register_shutdown_function([self::class, 'stop']);
    }

    public static function stop(): void
    {
        foreach (self::$procs as $p) {
            $st = proc_get_status($p);
            if ($st['running']) {
                // php -S startet unter Windows ggf. einen Kindprozess: ganzen Baum beenden
                PHP_OS_FAMILY === 'Windows' ? exec('taskkill /F /T /PID ' . $st['pid'] . ' 2>NUL') : proc_terminate($p);
            }
            proc_close($p);
        }
        self::$procs = [];
    }

    /** Legt einen Atelier-Benutzer mit Passwort und 2FA an. @return array{0:string,1:string} Name und TOTP-Geheimnis */
    public static function createUser(): array
    {
        self::applyToProcess();
        $name = 'test' . bin2hex(random_bytes(4));
        return [$name, \App\AtelierAuth::saveUser($name, self::PASSWORD)];
    }

    /** Anmeldedaten mit gültigem Code (legt ohne Angabe einen neuen Benutzer an). */
    public static function credentials(?array $user = null): array
    {
        [$name, $secret] = $user ?? self::createUser();
        return ['benutzer' => $name, 'passwort' => self::PASSWORD, 'code' => \App\Totp::code($secret, \App\Totp::currentStep())];
    }

    /** Alle bisher "versendeten" Mails (MAIL_CAPTURE_DIR). */
    public static function mails(): array
    {
        $out = [];
        foreach (glob(self::env()['MAIL_CAPTURE_DIR'] . '/*.json') ?: [] as $f) {
            $out[] = json_decode((string) file_get_contents($f), true);
        }
        return $out;
    }

    public static function stripeSessions(): array
    {
        return json_decode((string) file_get_contents('http://127.0.0.1:' . self::STRIPE_PORT . '/__test/sessions'), true);
    }

    public static function updateStripeSession(string $id, array $changes): void
    {
        file_get_contents('http://127.0.0.1:' . self::STRIPE_PORT . "/__test/sessions/$id", false, stream_context_create(['http' => ['method' => 'POST', 'header' => 'Content-Type: application/json', 'content' => json_encode($changes)]]));
    }

    /** Signierter Webhook-Aufruf wie von Stripe. */
    public static function webhook(string $type, array $object, string $secret = self::WEBHOOK_SECRET, ?string $eventId = null): Response
    {
        $payload = json_encode(['id' => $eventId ?? 'evt_' . bin2hex(random_bytes(6)), 'object' => 'event', 'type' => $type, 'api_version' => '2025-01-27.acacia', 'data' => ['object' => $object]]);
        $t = time();
        $sig = "t=$t,v1=" . hash_hmac('sha256', "$t.$payload", $secret);
        return (new Client())->request('POST', '/api/stripe-webhook', $payload, ['Content-Type: application/json', "Stripe-Signature: $sig"]);
    }
}
