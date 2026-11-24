<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Totp;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    /** Testvektoren aus RFC 6238 (SHA-1, Geheimnis "12345678901234567890"), auf 6 Ziffern gekürzt. */
    public function testRfc6238Vektoren(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);
        foreach ([59 => '287082', 1111111109 => '081804', 1111111111 => '050471', 1234567890 => '005924', 2000000000 => '279037'] as $t => $code) {
            $this->assertSame($code, Totp::code($secret, intdiv($t, 30)), "t=$t");
        }
        $this->assertSame('94287082', Totp::code($secret, 1, 8));
    }

    public function testVerifyMitToleranzUndOhneWiederholung(): void
    {
        $s = Totp::newSecret();
        $t = 1_800_000_000;
        $now = Totp::currentStep($t);
        $this->assertSame($now, Totp::verify($s, Totp::code($s, $now), null, $t));
        $this->assertSame($now - 1, Totp::verify($s, Totp::code($s, $now - 1), null, $t), 'Uhr 30 s nach');
        $this->assertNull(Totp::verify($s, Totp::code($s, $now - 2), null, $t), 'zu alt');
        $this->assertNull(Totp::verify($s, Totp::code($s, $now), $now, $t), 'schon benutzt');
        $this->assertSame($now, Totp::verify($s, substr_replace(Totp::code($s, $now), ' ', 3, 0), null, $t), 'Leerzeichen erlaubt');
        $this->assertNull(Totp::verify($s, 'abcdef', null, $t));
        $this->assertSame(Totp::base32Decode($s), Totp::base32Decode(strtolower($s)));
        $this->assertStringStartsWith('otpauth://totp/MH%20ART%20Atelier%3Amatthias?secret=' . $s, Totp::uri($s, 'matthias'));
    }
}
