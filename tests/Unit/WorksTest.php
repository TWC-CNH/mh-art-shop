<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Works;
use PHPUnit\Framework\TestCase;

final class WorksTest extends TestCase
{
    public function testEuro(): void
    {
        $this->assertSame("3.400\u{00A0}€", Works::euro(340000));
        $this->assertSame("2.450,50\u{00A0}€", Works::euro(245050));
        $this->assertSame("0,99\u{00A0}€", Works::euro(99));
    }

    public function testSlugify(): void
    {
        $this->assertSame('oberflaeche', Works::slugify('OBERFLÄCHE'));
        $this->assertSame('strasse-cafe', Works::slugify('Straße Café'));
        $this->assertSame('werk', Works::slugify('!!!'));
    }

    public function testFormat(): void
    {
        $this->assertSame('klein', Works::formatFromSize('50 x 40 cm'));
        $this->assertSame('mittel', Works::formatFromSize('60 x 40 x 2 cm'));
        $this->assertSame('gross', Works::formatFromSize('100 x 70 x 2 cm'));
        $this->assertSame('mittel', Works::formatFromSize(''));
    }
}
