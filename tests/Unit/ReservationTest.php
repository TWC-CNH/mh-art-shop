<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Catalog;
use App\Db;
use PHPUnit\Framework\TestCase;
use Tests\Support\Stack;

/** Reservierung direkt gegen die Testdatenbank: nie zwei Käufer gleichzeitig. */
final class ReservationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        Stack::resetDatabase();
    }

    public function testNurEinKaeuferBekommtDasWerk(): void
    {
        $id = (int) Db::one("SELECT id FROM werke WHERE slug = 'cool'")['id'];
        $a = Catalog::claim($id, 1800);
        $this->assertNotNull($a);
        $this->assertNull(Catalog::claim($id, 1800), 'zweiter Käufer');
        $this->assertFalse(Catalog::release($id, 'fremdes-token'));
        $this->assertTrue(Catalog::release($id, $a));
        $this->assertNotNull(Catalog::claim($id, 1800), 'nach Freigabe wieder frei');
    }

    public function testAbgelaufeneReservierungZaehltNicht(): void
    {
        $id = (int) Db::one("SELECT id FROM werke WHERE slug = 'blick'")['id'];
        Catalog::claim($id, 1800);
        Db::run('UPDATE werke SET reserviert_bis = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 1), $id]);
        $this->assertSame('available', Catalog::bySlug('blick')['status']);
        $this->assertNotNull(Catalog::claim($id, 1800));
    }

    public function testVerkauftOderOhnePreisNichtReservierbar(): void
    {
        $frei = (int) Db::one("SELECT id FROM werke WHERE slug = 'frei'")['id'];
        $this->assertNull(Catalog::claim($frei, 1800));
        $ohnePreis = Db::one('SELECT id FROM werke WHERE preis_cent IS NULL LIMIT 1');
        if ($ohnePreis) {
            $this->assertNull(Catalog::claim((int) $ohnePreis['id'], 1800));
        }
    }
}
