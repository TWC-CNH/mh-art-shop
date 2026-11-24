<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\AtelierAuth;
use App\Config;
use App\Crypto;
use App\Db;
use App\Env;
use App\Mailer;
use PHPUnit\Framework\TestCase;
use Tests\Support\Stack;

/** Regressionstests zum dritten Review von PR #1. */
final class ReviewFixes3Test extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        Stack::resetDatabase();
    }

    protected function tearDown(): void
    {
        Stack::applyToProcess();
    }

    public function testEnvIgnoriertKommentareHinterDemWert(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'env');
        file_put_contents($f, implode("\n", [
            'T_SHOW_SOLD=true                   # verkaufte Werke weiter zeigen',
            "T_TAG_ID=AW-123456789\t# Google Ads Tag",
            'PASS_MIT_RAUTE=geheim#123',
            'PASS_IN_QUOTES="ein # im Wert"',
            "PASS_QUOTES_KOMMENTAR='a b' # Kommentar",
            '# KOMMENTIERT=1',
            'T_LEER_MIT_KOMMENTAR=     # nur falls nötig',
            'T_PASS_RAUTE_ANFANG=#Xk9!pq',
            'LEER=',
        ]));
        try {
            Env::load($f);
            $this->assertSame('true', Env::get('T_SHOW_SOLD'));
            $this->assertSame('AW-123456789', Env::get('T_TAG_ID'));
            $this->assertSame('geheim#123', Env::get('PASS_MIT_RAUTE'));
            $this->assertSame('ein # im Wert', Env::get('PASS_IN_QUOTES'));
            $this->assertSame('a b', Env::get('PASS_QUOTES_KOMMENTAR'));
            $this->assertSame('', Env::get('KOMMENTIERT'));
            $this->assertSame('', Env::get('T_LEER_MIT_KOMMENTAR'), 'leerer Wert mit Kommentar');
            $this->assertSame('#Xk9!pq', Env::get('T_PASS_RAUTE_ANFANG'), '# direkt nach = gehört zum Wert');
            $this->assertSame('x', Env::get('LEER', 'x'));
        } finally {
            unlink($f);
            Env::load(Stack::root() . '/.env');
        }
    }

    /** Viertes Review: feste Vergleichs-Hashes haben dieselben Parameter wie echte Passwort-Hashes (gleiche Rechenzeit). */
    public function testVergleichsHashPasstZuEchtenHashes(): void
    {
        $real = password_get_info(AtelierAuth::hashPassword('irgendein-passwort'));
        $const = defined('PASSWORD_ARGON2ID') ? 'DUMMY_ARGON2ID' : 'DUMMY_BCRYPT';
        $dummy = password_get_info((new \ReflectionClassConstant(AtelierAuth::class, $const))->getValue());
        $this->assertSame($real['algo'], $dummy['algo']);
        $this->assertEquals($real['options'], $dummy['options']);
        $this->assertNull(AtelierAuth::attempt('gibt-es-nicht', 'egal', '123456'));
    }

    /** Viertes Review: Reservierungsdauer bleibt unter der 24-Stunden-Grenze von Stripe. */
    public function testReservierungsdauerHatObergrenze(): void
    {
        putenv('RESERVE_MINUTES=5000');
        Config::reset();
        $this->assertLessThan(1440, Config::get()['reserveMinutes']);
        putenv('RESERVE_MINUTES=5');
        Config::reset();
        $this->assertSame(30, Config::get()['reserveMinutes']);
        putenv('RESERVE_MINUTES');
    }

    public function testNurVersendbareAdressenWerdenAngenommen(): void
    {
        $this->assertTrue(Mailer::validAddress('joerg@example.de'));
        $this->assertTrue(Mailer::validAddress('a.b+shop@sub.example.co.uk'));
        $this->assertFalse(Mailer::validAddress('jörg@example.de'));
        $this->assertFalse(Mailer::validAddress('a,b@c.de'));
        $this->assertFalse(Mailer::validAddress("a@b.de\r\nBcc: x@y.de"));
    }

    public function testPasswortNeuSetzenEntsperrtNicht(): void
    {
        [$name] = Stack::createUser();
        $this->assertTrue(AtelierAuth::setActive($name, false));
        AtelierAuth::saveUser($name, 'ein-neues-langes-passwort');
        $this->assertSame(0, (int) Db::one('SELECT aktiv FROM atelier_benutzer WHERE benutzer = ?', [$name])['aktiv']);
        $this->assertTrue(AtelierAuth::setActive($name, true));
        $this->assertTrue(AtelierAuth::setActive($name, true), 'schon aktiv: trotzdem „erledigt“');
        $this->assertFalse(AtelierAuth::setActive('gibt-es-nicht', true));
    }

    public function testFehlerhafterAlterSchluesselWirdIgnoriert(): void
    {
        $box = Crypto::encrypt(['x' => 1], 'k');
        putenv('DATA_ENCRYPTION_KEY_OLD=kaputt');
        Config::reset();
        $this->assertTrue(Crypto::ready());
        $this->assertSame(['x' => 1], Crypto::decrypt($box, 'k'));
        putenv('DATA_ENCRYPTION_KEY_OLD');
    }

    public function testSchluesselwechselVerschluesseltAllesNeu(): void
    {
        $keyA = str_repeat('a', 64);
        $keyB = str_repeat('b', 64);
        // Daten mit Schlüssel A
        [$name, $secret] = Stack::createUser();
        Db::run("INSERT INTO widerrufe (ref, eingang, daten_enc, email_hmac) VALUES ('W-ROT', UTC_TIMESTAMP(), ?, ?)", [Crypto::encrypt(['email' => 'rot@example.test', 'name' => 'R'], 'widerrufe/W-ROT'), Crypto::pseudonym('rot@example.test', 'email')]);

        $out = Stack::php('bin/rotate-key.php', [], ['DATA_ENCRYPTION_KEY' => $keyB, 'DATA_ENCRYPTION_KEY_OLD' => $keyA]);
        $this->assertStringContainsString('Fertig, nichts mehr mit altem Schlüssel', $out);

        // Nur noch Schlüssel B: alles lesbar, Suchwert neu berechnet, Anmeldung mit 2FA klappt
        putenv("DATA_ENCRYPTION_KEY=$keyB");
        putenv('DATA_ENCRYPTION_KEY_OLD');
        Config::reset();
        $row = Db::one("SELECT * FROM widerrufe WHERE ref = 'W-ROT'");
        $this->assertTrue(Crypto::usesCurrentKey($row['daten_enc']));
        $this->assertSame('rot@example.test', Crypto::decrypt($row['daten_enc'], 'widerrufe/W-ROT')['email']);
        $this->assertSame(Crypto::pseudonym('rot@example.test', 'email'), $row['email_hmac']);
        $this->assertNotNull(AtelierAuth::attempt($name, Stack::PASSWORD, \App\Totp::code($secret, \App\Totp::currentStep())));

        // zweiter Lauf: nichts mehr zu tun
        $this->assertStringContainsString('neu verschlüsselt: 0', Stack::php('bin/rotate-key.php', [], ['DATA_ENCRYPTION_KEY' => $keyB, 'DATA_ENCRYPTION_KEY_OLD' => '']));
    }
}
