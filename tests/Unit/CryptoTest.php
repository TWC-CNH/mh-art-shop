<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Config;
use App\Crypto;
use PHPUnit\Framework\TestCase;

final class CryptoTest extends TestCase
{
    private function keys(string $cur, string $old = ''): void
    {
        putenv("DATA_ENCRYPTION_KEY=$cur");
        putenv("DATA_ENCRYPTION_KEY_OLD=$old");
        Config::reset();
    }

    protected function tearDown(): void
    {
        putenv('DATA_ENCRYPTION_KEY');
        putenv('DATA_ENCRYPTION_KEY_OLD');
        Config::reset();
    }

    public function testVerUndEntschluesseln(): void
    {
        $this->keys(str_repeat('a', 64));
        $box = Crypto::encrypt(['name' => 'Erika Müller', 'email' => 'e@x.de'], 'widerrufe/W-1');
        $this->assertStringStartsWith('v1.', $box);
        $this->assertStringNotContainsString('Erika', $box);
        $this->assertNotSame($box, Crypto::encrypt(['name' => 'Erika Müller', 'email' => 'e@x.de'], 'widerrufe/W-1'), 'zufällige Nonce');
        $this->assertSame(['name' => 'Erika Müller', 'email' => 'e@x.de'], Crypto::decrypt($box, 'widerrufe/W-1'));
    }

    public function testManipulationUndFalscherKontextWerdenErkannt(): void
    {
        $this->keys(str_repeat('a', 64));
        $box = Crypto::encrypt(['x' => 1], 'widerrufe/W-1');
        $this->expectExceptionMessage('manipuliert');
        Crypto::decrypt($box, 'widerrufe/W-2');
    }

    public function testVeraendertesChiffratWirdErkannt(): void
    {
        $this->keys(str_repeat('a', 64));
        $parts = explode('.', Crypto::encrypt(['x' => 1], 'k'));
        $parts[3][5] = $parts[3][5] === 'A' ? 'B' : 'A';
        $this->expectException(\RuntimeException::class);
        Crypto::decrypt(implode('.', $parts), 'k');
    }

    public function testSchluesselwechsel(): void
    {
        $this->keys(base64_encode(str_repeat("\x01", 32)));
        $box = Crypto::encrypt(['alt' => true], 'k');
        $this->keys(str_repeat('b', 64), base64_encode(str_repeat("\x01", 32)));
        $this->assertSame(['alt' => true], Crypto::decrypt($box, 'k'));
        $this->keys(str_repeat('b', 64));
        $this->expectExceptionMessage('Kein passender Schlüssel');
        Crypto::decrypt($box, 'k');
    }

    public function testOhneSchluesselKeinKlartext(): void
    {
        $this->keys('');
        $this->assertFalse(Crypto::ready());
        $this->expectExceptionMessage('DATA_ENCRYPTION_KEY fehlt');
        Crypto::encrypt(['x' => 1], 'k');
    }

    public function testUngueltigerSchluessel(): void
    {
        $this->keys('zu-kurz');
        $this->assertFalse(Crypto::ready());
    }

    /** Zwanzigstes Review: Werte mit dem alten Schlüssel bleiben nach einem Wechsel nachprüfbar (z. B. Beleg-Links). */
    public function testPseudonymMitAltemSchluessel(): void
    {
        $this->keys(str_repeat('a', 64));
        $withA = Crypto::pseudonym('W-20261124-ABCDEF', 'widerruf-beleg');
        $this->assertNull(Crypto::pseudonymOld('W-20261124-ABCDEF', 'widerruf-beleg'), 'ohne alten Schlüssel');
        $this->keys(str_repeat('b', 64), str_repeat('a', 64));
        $this->assertNotSame($withA, Crypto::pseudonym('W-20261124-ABCDEF', 'widerruf-beleg'));
        $this->assertSame($withA, Crypto::pseudonymOld('W-20261124-ABCDEF', 'widerruf-beleg'));
    }

    public function testPseudonymIstGeheimUndStabil(): void
    {
        $this->keys(str_repeat('a', 64));
        $a = Crypto::pseudonym('Erika@Example.de', 'email');
        $this->assertSame($a, Crypto::pseudonym('erika@example.de', 'email'));
        $this->assertNotSame($a, hash('sha256', 'email|erika@example.de'));
        $this->assertNotSame($a, Crypto::pseudonym('erika@example.de', 'ip'));
        $this->keys(str_repeat('b', 64));
        $this->assertNotSame($a, Crypto::pseudonym('erika@example.de', 'email'));
    }
}
