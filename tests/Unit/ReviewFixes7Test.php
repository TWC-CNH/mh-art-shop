<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Catalog;
use App\Db;
use App\RateLimit;
use PHPUnit\Framework\TestCase;
use Tests\Support\Stack;

/** Regressionstests zum siebten Review von PR #1. */
final class ReviewFixes7Test extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        Stack::resetDatabase();
    }

    public function testGescheiterteZweitzahlungEntferntErstattungshinweis(): void
    {
        $id = (int) Db::one("SELECT id FROM werke WHERE slug = 'cool'")['id'];
        Catalog::markSold($id, 'cs_a');
        Catalog::markSold($id, 'cs_b'); // SEPA, noch unbezahlt
        $this->assertSame('cs_b', Db::one('SELECT doppelverkauf_session FROM werke WHERE id = ?', [$id])['doppelverkauf_session']);
        $this->assertFalse(Catalog::markAvailable($id, 'cs_b'));
        $w = Db::one('SELECT status, verkauft_session, doppelverkauf_session FROM werke WHERE id = ?', [$id]);
        $this->assertSame(['status' => 'sold', 'verkauft_session' => 'cs_a', 'doppelverkauf_session' => null], $w);
    }

    public function testKontingentPlatzLaesstSichZuruecknehmen(): void
    {
        $a = RateLimit::hit('test-undo', 'x@example.test', 2, 600);
        $b = RateLimit::hit('test-undo', 'x@example.test', 2, 600);
        $this->assertFalse(RateLimit::hit('test-undo', 'x@example.test', 2, 600)['ok']);
        RateLimit::undo($b['id']);
        $this->assertTrue(RateLimit::hit('test-undo', 'x@example.test', 2, 600)['ok'], 'zurückgegebener Platz ist wieder frei');
        $this->assertTrue($a['ok']);
    }

    public function testMigrationNachAbbruchErneutAusfuehrbar(): void
    {
        // Simuliert: 003 lief durch, wurde aber nicht als erledigt vermerkt
        Db::run("DELETE FROM schema_migrations WHERE version = '003_notfallcodes'");
        $out = Stack::php('bin/migrate.php');
        $this->assertStringContainsString('ausgeführt: 003_notfallcodes', $out);
        $this->assertNotNull(Db::one("SELECT 1 FROM schema_migrations WHERE version = '003_notfallcodes'"));

        Db::run("DELETE FROM schema_migrations WHERE version = '002_atelier_benutzer'");
        $out = Stack::php('bin/migrate.php');
        $this->assertStringContainsString('übersprungen (schon vorhanden): Spalte atelier_sessions.benutzer_id', $out);
        $this->assertStringContainsString('übersprungen (schon vorhanden): Schlüssel fk_sessions_benutzer', $out);
        $this->assertStringContainsString('ausgeführt: 002_atelier_benutzer', $out);
    }
}
