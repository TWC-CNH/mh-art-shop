<?php
declare(strict_types=1);

namespace App;

/**
 * Einmalcodes für die Zwei-Faktor-Anmeldung (TOTP nach RFC 6238: SHA-1, 30 Sekunden, 6 Ziffern),
 * kompatibel mit Google Authenticator, Microsoft Authenticator, Aegis, 1Password usw.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    public const PERIOD = 30;
    public const DIGITS = 6;

    /** Neues Geheimnis (160 Bit), Base32 wie in Authenticator-Apps üblich. */
    public static function newSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function base32Encode(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[\s=-]/', '', $b32) ?? '');
        $bits = '';
        foreach (str_split($b32) as $c) {
            $i = strpos(self::ALPHABET, $c);
            if ($i === false) {
                throw new \InvalidArgumentException('Ungültiges Base32-Zeichen');
            }
            $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }

    public static function code(string $secretB32, int $step, int $digits = self::DIGITS): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::base32Decode($secretB32), true);
        $o = ord($hash[19]) & 0x0f;
        $num = ((ord($hash[$o]) & 0x7f) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);
        return str_pad((string) ($num % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public static function currentStep(?int $time = null): int
    {
        return intdiv($time ?? time(), self::PERIOD);
    }

    /**
     * Prüft einen Code (±1 Zeitfenster für Uhrabweichung). Liefert den verwendeten Zeitschritt
     * oder null. Schritte bis einschließlich $lastStep werden abgelehnt (kein zweites Benutzen desselben Codes).
     */
    public static function verify(string $secretB32, string $code, ?int $lastStep = null, ?int $time = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return null;
        }
        $now = self::currentStep($time);
        foreach ([0, -1, 1] as $d) {
            $step = $now + $d;
            if ($lastStep !== null && $step <= $lastStep) {
                continue;
            }
            if (hash_equals(self::code($secretB32, $step), $code)) {
                return $step;
            }
        }
        return null;
    }

    /** Link zum Einrichten in Authenticator-Apps (otpauth://). */
    public static function uri(string $secretB32, string $account, string $issuer = 'MH ART Atelier'): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secretB32 . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }
}
