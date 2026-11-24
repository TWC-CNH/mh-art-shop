<?php
declare(strict_types=1);

namespace App\Controller;

use App\Config;
use App\Crypto;
use App\Db;
use App\Http;
use App\Log;
use App\Mailer;
use App\RateLimit;

/**
 * Anfrage-Formular (Preisanfrage, Frage zum Werk, Warteliste, ähnliches Werk, allgemein). Früher Netlify Forms.
 * Speichert verschlüsselt und schickt Matthias eine E-Mail (Antworten gehen direkt an den Absender).
 */
final class InquiryController
{
    private const KINDS = ['allgemein', 'preis', 'frage', 'warteliste', 'aehnlich'];

    private static function done(bool $ok, int $status = 200, string $error = ''): never
    {
        if (Http::wantsJson()) {
            Http::jsonOut($ok ? ['ok' => true] : ['error' => $error], $status);
        }
        // Ohne JavaScript: einfache Bestätigungsseite
        $cfg = Config::get();
        // Bei Eingabefehlern den konkreten Grund nennen und zurück zum (vom Browser meist noch gefüllten) Formular führen
        $reason = match (true) {
            $error === 'zuviele' => 'Von Ihrem Anschluss kamen gerade sehr viele Anfragen.',
            $error === '', $error === 'fehler' => 'Das hat leider nicht geklappt.',
            default => $error,
        };
        $msg = $ok
            ? 'Vielen Dank, Ihre Anfrage ist angekommen. Ich antworte in der Regel innerhalb eines Tages.'
            : e($reason) . ($status === 422 ? ' Gehen Sie im Browser einen Schritt zurück, Ihre Eingaben sind dort meist noch vorhanden.' : '') . ' Oder schreiben Sie direkt an ' . e($cfg['contactEmail']) . '.';
        Http::html(\App\View\Render::layout([
            'title' => 'Anfrage',
            'canonical' => '/anfrage',
            'noindex' => true,
            'bodyClass' => 'page-legal',
            'body' => '<section class="legal"><h1>Anfrage</h1><p>' . $msg . '</p><p><a class="btn" href="/">Zurück zum Shop</a></p></section>',
        ]), $status);
    }

    public static function handle(): never
    {
        if (Http::method() !== 'POST') {
            http_response_code(405);
            exit('Method not allowed');
        }
        if (!Http::sameOrigin()) {
            http_response_code(403);
            exit('Forbidden');
        }
        $cfg = Config::get();
        $g = fn (string $k, int $n) => mb_substr(trim(Http::post($k, $n * 2)), 0, $n);
        if ($g('bot-field', 100) !== '') {
            self::done(true); // Spam-Falle: so tun, als wäre alles gut
        }
        $d = [
            'anliegen' => in_array($g('anliegen', 20), self::KINDS, true) ? $g('anliegen', 20) : 'allgemein',
            'werk' => $g('werk', 140),
            'seite' => $g('seite', 300),
            'name' => preg_replace('/\s+/u', ' ', $g('name', 120)),
            'email' => mb_strtolower($g('email', 200)),
            'telefon' => preg_replace('/[^\d+()\/ -]/', '', $g('telefon', 40)),
            'nachricht' => $g('nachricht', 4000),
        ];
        if ($d['name'] === '' || $d['nachricht'] === '' || !\App\Mailer::validAddress($d['email']) || Http::post('datenschutz') !== 'ja') {
            self::done(false, 422, 'Bitte Name, eine gültige E-Mail-Adresse (ohne Umlaute), Nachricht und die Einwilligung angeben.');
        }
        $lim = RateLimit::hit('anfrage-ip', RateLimit::clientIp(), $cfg['limits']['inquiryPerIp'], 3600);
        if (!$lim['ok']) {
            self::done(false, 429, 'zuviele');
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $ref = 'A-' . $now->format('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        // Das Formular schickt „Titel (slug)“; den slug am Ende herauslösen (oder nur slug)
        $werkRaw = trim(Http::post('werk', 600)); // ungekürzt, damit auch lange slugs vollständig sind
        $slug = preg_match('/(?:^|\()([a-z0-9-]{1,140})\)?\s*$/', $werkRaw, $sm) ? $sm[1] : null;
        try {
            if ($slug !== null && !Db::one('SELECT 1 FROM werke WHERE slug = ?', [$slug])) {
                $slug = null;
            }
        } catch (\Throwable) {
            $slug = null; // Datenbank nicht erreichbar: Anfrage trotzdem per Mail weitergeben
        }
        $stored = true;
        try {
            Db::run('INSERT INTO anfragen (ref, eingang, anliegen, werk_slug, daten_enc, email_hmac) VALUES (?, ?, ?, ?, ?, ?)', [
                $ref, $now->format('Y-m-d H:i:s'), $d['anliegen'], $slug, Crypto::encrypt($d, 'anfragen/' . $ref), Crypto::pseudonym($d['email'], 'email'),
            ]);
        } catch (\Throwable $e) {
            $stored = false;
            Log::error('Anfrage speichern fehlgeschlagen: ' . $e->getMessage());
        }
        $labels = ['allgemein' => 'Allgemeine Anfrage', 'preis' => 'Preisanfrage', 'frage' => 'Frage zum Werk', 'warteliste' => 'Warteliste', 'aehnlich' => 'Ähnliches Werk'];
        $mail = Mailer::send(
            $cfg['contactEmail'],
            $labels[$d['anliegen']] . ($d['werk'] !== '' ? ": {$d['werk']}" : '') . " ({$ref})",
            "Neue Anfrage über den Shop.\n\nAnliegen: {$labels[$d['anliegen']]}\nWerk: " . ($d['werk'] ?: '–') . "\nSeite: " . ($d['seite'] ?: '–')
            . "\n\nName: {$d['name']}\nE-Mail: {$d['email']}\nTelefon: " . ($d['telefon'] ?: '–') . "\n\nNachricht:\n{$d['nachricht']}\n\nVorgang: $ref"
            . ($stored ? '' : "\n\nACHTUNG: Die Anfrage konnte nicht im Atelier gespeichert werden."),
            $d['email']
        );
        if (!$stored && !$mail['sent']) {
            RateLimit::undo($lim['id'] ?? null); // nichts angekommen: zählt nicht gegen die Begrenzung
            self::done(false, 503, 'fehler');
        }
        self::done(true);
    }
}
