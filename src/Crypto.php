<?php
declare(strict_types=1);

namespace App;

/**
 * Verschlüsselung personenbezogener Daten (Widerrufe, Anfragen) mit libsodium:
 * XChaCha20-Poly1305 (vertraulich und manipulationssicher). Der Kontext (z. B. "widerrufe/W-2026…")
 * wird als Zusatzdaten gebunden, ein Eintrag lässt sich also nicht unbemerkt umkopieren.
 *
 * Schlüssel: DATA_ENCRYPTION_KEY (32 Byte, Base64 oder Hex). Wechsel: neuen Schlüssel setzen,
 * bisherigen als DATA_ENCRYPTION_KEY_OLD. Ohne Schlüssel wird nichts gespeichert (encrypt wirft).
 */
final class Crypto
{
    private const PREFIX = 'v1';

    private static function parseKey(string $raw, string $name): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $bin = preg_match('/^[0-9a-fA-F]{64}$/', $raw) ? hex2bin($raw) : base64_decode($raw, true);
        if ($bin === false || strlen($bin) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new \RuntimeException("$name muss 32 Byte lang sein (64 Hex-Zeichen oder 44 Zeichen Base64).");
        }
        return $bin;
    }

    private static function keyId(string $key): string
    {
        return substr(hash('sha256', $key), 0, 8);
    }

    /** @return array{current: ?string, all: list<string>} */
    private static function keys(): array
    {
        $c = Config::get();
        $cur = self::parseKey($c['dataKey'], 'DATA_ENCRYPTION_KEY');
        $old = self::parseKey($c['dataKeyOld'], 'DATA_ENCRYPTION_KEY_OLD');
        return ['current' => $cur, 'all' => array_values(array_filter([$cur, $old]))];
    }

    public static function ready(): bool
    {
        try {
            return self::keys()['current'] !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Verschlüsselt ein Array. Ergebnis: "v1.<kid>.<nonce>.<chiffrat>" (Base64url), passt in eine TEXT-Spalte. */
    public static function encrypt(array $data, string $context): string
    {
        $key = self::keys()['current'];
        if ($key === null) {
            throw new \RuntimeException('DATA_ENCRYPTION_KEY fehlt: personenbezogene Daten werden nicht unverschlüsselt gespeichert.');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ct = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $context, $nonce, $key);
        return implode('.', [self::PREFIX, self::keyId($key), self::b64($nonce), self::b64($ct)]);
    }

    public static function decrypt(string $box, string $context): array
    {
        $parts = explode('.', $box);
        if (count($parts) !== 4 || $parts[0] !== self::PREFIX) {
            throw new \RuntimeException('Unbekanntes Format.');
        }
        [, $kid, $nonce, $ct] = $parts;
        foreach (self::keys()['all'] as $key) {
            if (self::keyId($key) !== $kid) {
                continue;
            }
            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(self::unb64($ct), $context, self::unb64($nonce), $key);
            if ($plain === false) {
                throw new \RuntimeException('Eintrag manipuliert oder falscher Kontext.');
            }
            return json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
        }
        throw new \RuntimeException("Kein passender Schlüssel (kid $kid).");
    }

    /**
     * Geheimer Hash (HMAC) für IP-Adressen und E-Mails in Zählern und Suchspalten,
     * damit sie sich nicht durch Ausprobieren zurückrechnen lassen.
     */
    public static function pseudonym(string $value, string $purpose = 'id'): string
    {
        $c = Config::get();
        $base = null;
        try {
            $base = self::keys()['current'];
        } catch (\Throwable) {
        }
        $secret = $base !== null
            ? hash_hkdf('sha256', $base, 32, 'mh-pseudonym')
            : ($c['stripeSecretKey'] ?: 'mh-local-dev');
        return hash_hmac('sha256', $purpose . '|' . mb_strtolower($value, 'UTF-8'), $secret);
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function unb64(string $s): string
    {
        $r = base64_decode(strtr($s, '-_', '+/'), true);
        if ($r === false) {
            throw new \RuntimeException('Ungültige Kodierung.');
        }
        return $r;
    }
}
