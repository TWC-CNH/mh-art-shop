<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Catalog;
use App\Config;
use App\Db;
use App\RateLimit;
use App\View\Legal;
use PHPUnit\Framework\TestCase;
use Tests\Support\Stack;

/** Regressionstests zum zweiten Review von PR #1. */
final class ReviewFixes2Test extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        Stack::resetDatabase();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR']);
        foreach (['TRUSTED_PROXIES', 'RETENTION_INQUIRY_MONTHS', 'RETENTION_WITHDRAWAL_YEARS'] as $k) {
            putenv($k);
        }
        Stack::applyToProcess();
    }

    private static function werk(string $slug): array
    {
        return Db::one('SELECT * FROM werke WHERE slug = ?', [$slug]);
    }

    public function testZahlungNachVerkaufVonHandWirdAlsDoppelverkaufErkannt(): void
    {
        $id = (int) self::werk('cool')['id'];
        Db::run("UPDATE werke SET status = 'sold', verkauft_session = NULL, verkauft_am = '2026-11-01' WHERE id = ?", [$id]);
        $r = Catalog::markSold($id, 'cs_nach_handverkauf');
        $this->assertTrue($r['alreadySold']);
        $w = self::werk('cool');
        $this->assertNull($w['verkauft_session'], 'Verkauf von Hand bleibt der Verkauf');
        $this->assertSame('cs_nach_handverkauf', $w['doppelverkauf_session']);
        $this->assertSame('2026-11-01', $w['verkauft_am']);
    }

    public function testDritteZahlungUeberschreibtDenHinweisNicht(): void
    {
        $id = (int) self::werk('nacht')['id'];
        Catalog::markSold($id, 'cs_a');
        Catalog::markSold($id, 'cs_b');
        $this->assertTrue(Catalog::markSold($id, 'cs_c')['alreadySold']);
        $w = self::werk('nacht');
        $this->assertSame('cs_a', $w['verkauft_session']);
        $this->assertSame('cs_b', $w['doppelverkauf_session']);
        $this->assertFalse(Catalog::markSold($id, 'cs_a')['alreadySold'], 'gleiches Ereignis erneut: kein Doppelverkauf');
    }

    public function testProxyKetteWirdVonRechtsGelesen(): void
    {
        putenv('TRUSTED_PROXIES=10.0.0.1,10.0.0.2');
        Config::reset();
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4, 203.0.113.9'; // links vom Besucher gefälscht
        $this->assertSame('203.0.113.9', RateLimit::clientIp());
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.1.1.1, 198.51.100.7, 10.0.0.2'; // zwei Proxys
        $this->assertSame('198.51.100.7', RateLimit::clientIp());
        $_SERVER['HTTP_X_FORWARDED_FOR'] = 'kaputt, 198.51.100.8';
        $this->assertSame('198.51.100.8', RateLimit::clientIp());
        $_SERVER['REMOTE_ADDR'] = '192.0.2.50'; // kein vertrauenswürdiger Proxy: Header zählt nicht
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
        $this->assertSame('192.0.2.50', RateLimit::clientIp());
    }

    public function testDatenschutzNenntKonfigurierteFristen(): void
    {
        putenv('RETENTION_INQUIRY_MONTHS=6');
        putenv('RETENTION_WITHDRAWAL_YEARS=4');
        Config::reset();
        $html = Legal::render('datenschutz');
        $this->assertStringContainsString('nach 6 Monaten automatisch gelöscht', $html);
        $this->assertStringContainsString('nach 4 Jahren automatisch gelöscht.', $html);
        putenv('RETENTION_INQUIRY_MONTHS');
        putenv('RETENTION_WITHDRAWAL_YEARS');
        Config::reset();
        $html = Legal::render('datenschutz');
        $this->assertStringContainsString('nach 12 Monaten', $html);
        $this->assertStringContainsString('nach 3 Jahren automatisch gelöscht (Ende der regelmäßigen Verjährungsfrist).', $html);
    }
}
