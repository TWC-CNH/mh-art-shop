<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\AtelierAuth;
use App\Catalog;
use App\Config;
use App\Db;
use App\RateLimit;
use PHPUnit\Framework\TestCase;
use Tests\Support\Stack;

/** Regressionstests zu den Befunden aus dem Review von PR #1. */
final class ReviewFixesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        Stack::resetDatabase();
    }

    private static function id(string $slug): int
    {
        return (int) Db::one('SELECT id FROM werke WHERE slug = ?', [$slug])['id'];
    }

    public function testFehlgeschlageneZahlungLaesstHandVerkaufUnberuehrt(): void
    {
        $id = self::id('cool');
        Db::run("UPDATE werke SET status = 'sold', verkauft_session = NULL WHERE id = ?", [$id]); // im Atelier von Hand verkauft
        $this->assertFalse(Catalog::markAvailable($id, 'cs_test_fremd'));
        $this->assertSame('sold', Db::one('SELECT status FROM werke WHERE id = ?', [$id])['status']);
    }

    public function testFehlgeschlageneZahlungLaesstFremdeReservierungUnberuehrt(): void
    {
        $id = self::id('blick');
        $token = Catalog::claim($id, 1800);
        $this->assertFalse(Catalog::markAvailable($id, 'cs_test_alt'));
        $this->assertSame($token, Db::one('SELECT reserviert_session FROM werke WHERE id = ?', [$id])['reserviert_session']);
    }

    public function testFehlgeschlageneErstzahlungUebergibtAnZweitzahlung(): void
    {
        $id = self::id('nacht');
        Catalog::markSold($id, 'cs_a');
        Catalog::markSold($id, 'cs_b'); // zweite Zahlung
        $this->assertFalse(Catalog::markAvailable($id, 'cs_a'));
        $w = Db::one('SELECT status, verkauft_session, doppelverkauf_session FROM werke WHERE id = ?', [$id]);
        $this->assertSame(['status' => 'sold', 'verkauft_session' => 'cs_b', 'doppelverkauf_session' => null], $w);
        $this->assertTrue(Catalog::markAvailable($id, 'cs_b'));
        $this->assertSame('available', Db::one('SELECT status FROM werke WHERE id = ?', [$id])['status']);
    }

    public function testBegrenzungZaehltNurBisZumLimit(): void
    {
        // Simuliert gleichzeitige Anfragen: Einträge sind schon da, bevor der nächste Versuch zählt.
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue(RateLimit::hit('test', '198.51.100.99', 5, 600)['ok']);
        }
        $r = RateLimit::hit('test', '198.51.100.99', 5, 600);
        $this->assertFalse($r['ok']);
        $this->assertGreaterThan(0, $r['retryAfter']);
        $this->assertSame(5, RateLimit::attempts('test', '198.51.100.99', 600), 'abgelehnter Versuch wird wieder entfernt');
        $this->assertTrue(RateLimit::hit('test', '198.51.100.100', 5, 600)['ok'], 'andere IP unabhängig');
    }

    public function testNeuesPasswortMeldetAlleGeraeteAb(): void
    {
        [$name] = Stack::createUser();
        $u = Db::one('SELECT id, passwort_hash FROM atelier_benutzer WHERE benutzer = ?', [$name]);
        $token = bin2hex(random_bytes(32));
        $hash = (new \ReflectionMethod(AtelierAuth::class, 'tokenHash'))->invoke(null, $token, $u['passwort_hash']);
        Db::run('INSERT INTO atelier_sessions (token_hash, benutzer_id, laeuft_ab) VALUES (?, ?, ?)', [$hash, $u['id'], Db::now(3600)]);
        $_COOKIE[AtelierAuth::COOKIE] = $u['id'] . '.' . $token;
        $this->assertTrue(AtelierAuth::isLoggedIn());

        AtelierAuth::saveUser($name, 'ein-ganz-neues-passwort');
        $this->assertFalse(AtelierAuth::isLoggedIn());
        unset($_COOKIE[AtelierAuth::COOKIE]);
    }
}
