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
use App\View\Render;

/** Elektronische Widerrufsfunktion nach § 356a BGB („Vertrag widerrufen“ → „Widerruf bestätigen“ → Eingangsbestätigung). */
final class WithdrawalController
{

    public static function stamp(\DateTimeImmutable $d): string
    {
        return $d->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y, H:i:s') . ' Uhr';
    }

    private static function formPage(array $values = [], string $error = ''): string
    {
        $cfg = Config::get();
        $v = fn (string $k) => e($values[$k] ?? '');
        $err = $error !== '' ? '<p class="form-error" role="alert">' . e($error) . '</p>' : '';
        $mail = e($cfg['contactEmail']);
        return Render::layout([
            'title' => 'Vertrag widerrufen',
            'canonical' => '/vertrag-widerrufen',
            'noindex' => true,
            'bodyClass' => 'page-legal',
            'body' => <<<HTML
<section class="legal withdraw">
  <h1>Vertrag widerrufen</h1>
  <p>Sie haben ein Werk gekauft und möchten den Kauf innerhalb der Widerrufsfrist widerrufen? Füllen Sie die drei Felder aus und klicken Sie auf „Widerruf bestätigen“. Einen Grund müssen Sie nicht angeben. Sie erhalten sofort eine Eingangsbestätigung per E-Mail.</p>
  $err
  <form method="post" action="/vertrag-widerrufen" class="withdraw-form" novalidate>
    <p class="hp" aria-hidden="true"><label>Bitte leer lassen <input name="hp_w7q" tabindex="-1" autocomplete="off"></label></p>
    <label class="field"><span>Ihr Name *</span><input name="name" required maxlength="120" autocomplete="name" value="{$v('name')}"></label>
    <label class="field"><span>Welchen Kauf möchten Sie widerrufen? *</span><input name="vertrag" required maxlength="300" value="{$v('vertrag')}" placeholder="z. B. Werk „NACHT“, gekauft am 20.11.2026"><small>Titel des Werks, Kaufdatum oder Bestellnummer aus der Kaufbestätigung</small></label>
    <label class="field"><span>Ihre E-Mail-Adresse für die Eingangsbestätigung *</span><input name="email" type="email" required maxlength="200" autocomplete="email" value="{$v('email')}"></label>
    <button class="btn btn--wide" type="submit">Widerruf bestätigen</button>
    <p class="muted small">Mit dem Klick senden Sie Ihre Widerrufserklärung ab. Fragen vorab? <a href="mailto:$mail">$mail</a>. Infos zum Ablauf in der <a href="/widerruf">Widerrufsbelehrung</a>.</p>
  </form>
</section>
HTML,
        ]);
    }

    private static function donePage(array $rec, bool $mailed, bool $direct = false): string
    {
        $cfg = Config::get();
        $mail = e($cfg['contactEmail']);
        $note = $mailed
            ? 'Die Eingangsbestätigung mit diesen Angaben ist an <strong>' . e($rec['email']) . '</strong> unterwegs.'
            : 'Die Bestätigungs-E-Mail konnte gerade nicht versendet werden. Bitte speichern oder drucken Sie diese Seite als Nachweis. Ihr Widerruf ist trotzdem eingegangen.';
        if ($direct) {
            $note .= ' Bitte laden Sie diese Seite nicht neu – sonst wird der Widerruf ein zweites Mal abgeschickt.';
        }
        return Render::layout([
            'title' => 'Widerruf eingegangen',
            'canonical' => '/vertrag-widerrufen',
            'noindex' => true,
            'bodyClass' => 'page-legal',
            'body' => '<section class="legal withdraw">
  <h1>Ihr Widerruf ist eingegangen</h1>
  <dl class="withdraw-receipt">
    <dt>Eingang</dt><dd>' . e($rec['receivedLabel']) . '</dd>
    <dt>Name</dt><dd>' . e($rec['name']) . '</dd>
    <dt>Widerrufener Kauf</dt><dd>' . e($rec['contract']) . '</dd>
    <dt>E-Mail</dt><dd>' . e($rec['email']) . '</dd>
    <dt>Vorgangsnummer</dt><dd>' . e($rec['ref']) . '</dd>
  </dl>
  <p>' . $note . '</p>
  <p>Ich melde mich in Kürze bei Ihnen, um die Rückgabe des Werks abzustimmen. Fragen: <a href="mailto:' . $mail . '">' . $mail . '</a></p>
  <p><button type="button" class="btn btn--ghost" data-print>Seite drucken</button></p>
</section>',
        ]);
    }

    private static function mailText(array $rec, array $cfg): string
    {
        $a = $cfg['address'];
        return "Guten Tag {$rec['name']},\n\nhiermit bestätige ich den Eingang Ihrer Widerrufserklärung.\n\nInhalt Ihrer Erklärung:\n„Hiermit widerrufe ich den folgenden Vertrag: {$rec['contract']}“\n\n"
            . "Name: {$rec['name']}\nE-Mail: {$rec['email']}\nEingang: {$rec['receivedLabel']}\nVorgangsnummer: {$rec['ref']}\n\n"
            . "Ich melde mich in Kürze, um die Rückgabe des Werks mit Ihnen abzustimmen.\n\nViele Grüße\n{$cfg['artistName']}\n{$a['street']}, {$a['zip']} {$a['city']}\n{$cfg['contactEmail']}\n{$cfg['siteUrl']}";
    }

    public static function handle(): never
    {
        $cfg = Config::get();
        if (Http::method() === 'GET') {
            if (Http::query('ref') !== '') {
                self::showReceipt(Http::query('ref'), Http::query('t'));
            }
            Http::html(self::formPage());
        }
        if (Http::method() !== 'POST') {
            http_response_code(405);
            exit('Method not allowed');
        }
        if (!Http::sameOrigin()) {
            Log::warn('Widerruf ohne gültige Herkunftsangabe abgelehnt (Origin/Referer fehlt oder fremd)');
            Http::html(self::formPage([], "Ihr Browser hat das Formular ohne Herkunftsangabe gesendet, deshalb konnten wir es nicht annehmen. Bitte versuchen Sie es in einem anderen Browser erneut oder senden Sie Ihren Widerruf per E-Mail an {$cfg['contactEmail']}."), 403);
        }
        $g = fn (string $k, int $n) => mb_substr(trim(preg_replace('/\s+/u', ' ', Http::post($k, $n * 2)) ?? ''), 0, $n);
        $values = ['name' => $g('name', 120), 'vertrag' => $g('vertrag', 300), 'email' => mb_strtolower($g('email', 200))];
        if ($g('hp_w7q', 100) !== '') {
            // Spam-Falle: nicht still verwerfen (ein gesetzlich geschuldeter Widerruf darf nicht verloren gehen),
            // sondern sichtbar ablehnen, mit dem Weg per E-Mail
            Log::warn('Widerruf: Spam-Falle ausgefüllt, abgelehnt');
            Http::html(self::formPage($values, "Ihr Widerruf konnte nicht angenommen werden, weil ein verstecktes Feld ausgefüllt war (z. B. vom Passwortmanager). Bitte senden Sie das Formular erneut oder Ihren Widerruf per E-Mail an {$cfg['contactEmail']}."), 422);
        }
        if ($values['name'] === '' || $values['vertrag'] === '' || !\App\Mailer::validAddress($values['email'])) {
            Http::html(self::formPage($values, 'Bitte Name, den betroffenen Kauf und eine gültige E-Mail-Adresse angeben (ohne Umlaute, z. B. joerg@… statt jörg@…).'), 422);
        }

        // Missbrauchsschutz je IP und Stunde (gespeichert wird immer, ein echter Widerruf geht nicht verloren):
        // - Bestätigung an den Käufer bis LIMIT_WITHDRAW_CONFIRM_PER_IP (Standard 10), zusätzlich höchstens
        //   LIMIT_WITHDRAW_MAILS_PER_RECIPIENT (Standard 5) je Adresse und Tag.
        // - Meldung an Matthias bis LIMIT_WITHDRAW_PER_IP (Standard 5); darüber steht der Widerruf nur im Atelier –
        //   sonst ließe sich das Shop-Postfach fluten und von Hetzner sperren.
        // - Weit darüber (10-fache Grenze): ablehnen, mit Hinweis auf den Widerruf per E-Mail.
        $ip = RateLimit::clientIp();
        if (!RateLimit::hit('widerruf-speichern', $ip, $cfg['limits']['withdrawPerIp'] * 10, 3600)['ok']) {
            Log::warn('Widerruf: sehr viele Einsendungen von einer IP, abgelehnt');
            Http::html(self::formPage($values, "Von Ihrem Anschluss kamen gerade sehr viele Widerrufe. Bitte senden Sie Ihren Widerruf per E-Mail an {$cfg['contactEmail']}."), 429);
        }
        // Eingangsbestätigung an den Käufer ist gesetzlich versprochen: eigene, großzügigere Grenze je IP.
        // Wird sie trotzdem zurückgehalten, steht das in der Meldung an Matthias, der sie dann selbst schickt.
        $perIp = RateLimit::hit('widerruf-ip', $ip, $cfg['limits']['withdrawConfirmPerIp'], 3600);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $rec = [
            'ref' => 'W-' . $now->format('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3))),
            'receivedAt' => $now->format('c'),
            'receivedLabel' => self::stamp($now),
            'name' => $values['name'],
            'contract' => $values['vertrag'],
            'email' => $values['email'],
        ];

        $stored = true;
        try {
            Db::run('INSERT INTO widerrufe (ref, eingang, daten_enc, email_hmac) VALUES (?, ?, ?, ?)', [
                $rec['ref'], $now->format('Y-m-d H:i:s'), Crypto::encrypt($rec, 'widerrufe/' . $rec['ref']), Crypto::pseudonym($rec['email'], 'email'),
            ]);
        } catch (\Throwable $e) {
            $stored = false;
            Log::error('Widerruf speichern fehlgeschlagen: ' . $e->getMessage());
        }

        // Je Empfänger nur wenige Bestätigungen pro Tag, damit niemand fremde Postfächer damit zuschüttet.
        // Platz im Kontingent atomar reservieren (auch bei gleichzeitigen Anfragen höchstens N Mails) und
        // bei gescheitertem Versand zurückgeben, damit ein erneuter Versuch nach SMTP-Ausfall möglich bleibt.
        $slot = $perIp['ok'] ? RateLimit::hit('widerruf-mail', $rec['email'], $cfg['limits']['withdrawMailsPerRecipient'], 86400) : ['ok' => false];
        $mail = $slot['ok']
            ? Mailer::send($rec['email'], "Eingangsbestätigung Ihres Widerrufs ({$rec['ref']})", self::mailText($rec, $cfg), $cfg['contactEmail'])
            : ['sent' => false, 'reason' => $perIp['ok'] ? 'Limit je Empfänger erreicht' : 'Limit je IP erreicht'];
        if ($perIp['ok'] && !$slot['ok']) {
            RateLimit::undo($perIp['id'] ?? null); // keine Mail wegen Empfänger-Limit: zählt nicht je IP
        }
        if ($slot['ok'] && !$mail['sent']) {
            RateLimit::undo($slot['id'] ?? null);
            RateLimit::undo($perIp['id'] ?? null); // Mailausfall: auch den Platz je IP zurückgeben
        }
        if ($stored) {
            try {
                Db::run('UPDATE widerrufe SET mail_versendet = ? WHERE ref = ?', [$mail['sent'] ? 1 : 0, $rec['ref']]);
            } catch (\Throwable $e) {
                Log::error('Widerruf: Mail-Vermerk nicht gespeichert: ' . $e->getMessage());
            }
        }

        // Eigener Zähler für die Meldung an Matthias: so kann niemand sein Postfach fluten, auch nicht mit immer derselben Adresse
        $ownerOk = RateLimit::hit('widerruf-meldung', $ip, $cfg['limits']['withdrawPerIp'], 3600)['ok'];
        $owner = !$ownerOk && $stored ? ['sent' => false, 'reason' => 'Limit je IP (steht im Atelier)'] : Mailer::send(
            $cfg['contactEmail'],
            "Widerruf eingegangen: {$rec['contract']}",
            "Über den Shop ist ein Widerruf eingegangen.\n\nName: {$rec['name']}\nKauf: {$rec['contract']}\nE-Mail: {$rec['email']}\nEingang: {$rec['receivedLabel']}\nVorgang: {$rec['ref']}\n\n"
            . 'Eingangsbestätigung an den Käufer: ' . ($mail['sent'] ? 'versendet' : "NICHT versendet ({$mail['reason']}) – bitte dem Käufer den Eingang selbst per E-Mail bestätigen") . "\n"
            . ($stored ? '' : "ACHTUNG: Der Widerruf konnte im Atelier NICHT gespeichert werden (Datenbank / DATA_ENCRYPTION_KEY prüfen). Bitte diese E-Mail als Nachweis aufbewahren.\n")
            . "\nNächste Schritte: Rückgabe abstimmen, nach Erhalt in Stripe erstatten und das Werk im Atelier wieder auf „Verfügbar“ stellen.",
            $rec['email']
        );
        Log::info("Widerruf {$rec['ref']}: gespeichert " . ($stored ? 'ja' : 'nein') . ', Bestätigung ' . ($mail['sent'] ? 'ja' : 'nein') . ', Meldung ' . ($owner['sent'] ? 'ja' : 'nein'));

        // Nirgends angekommen (weder gespeichert noch per Mail): dem Käufer ehrlich sagen und den Weg per E-Mail nennen,
        // statt eine Eingangsbestätigung anzuzeigen, für die es keinen Nachweis gibt.
        if (!$stored && !$mail['sent'] && !$owner['sent']) {
            Http::html(self::formPage($values, "Ihr Widerruf konnte gerade technisch nicht entgegengenommen werden. Bitte senden Sie ihn per E-Mail an {$cfg['contactEmail']} (Name, betroffener Kauf) oder versuchen Sie es in einigen Minuten erneut."), 503);
        }
        if ($stored) {
            // Nach dem Absenden weiterleiten: Neuladen oder „Zurück/Vor“ schicken den Widerruf nicht erneut ab
            Http::redirect('/vertrag-widerrufen?ref=' . rawurlencode($rec['ref']) . '&t=' . self::receiptToken($rec['ref']));
        }
        Http::html(self::donePage($rec, $mail['sent'], true)); // nicht gespeichert: direkt anzeigen, mit Hinweis
    }

    /** Geheimer Prüfwert für den Link zur Eingangsbestätigung (nur wer abgeschickt hat, kennt ihn). */
    private static function receiptToken(string $ref): string
    {
        return substr(Crypto::pseudonym($ref, 'widerruf-beleg'), 0, 32);
    }

    /** Eingangsbestätigung anzeigen (bis 24 Stunden nach Eingang, nur mit gültigem Prüfwert). */
    private static function showReceipt(string $ref, string $token): never
    {
        $old = Crypto::pseudonymOld($ref, 'widerruf-beleg');
        $valid = hash_equals(self::receiptToken($ref), $token) || ($old !== null && hash_equals(substr($old, 0, 32), $token));
        $row = preg_match('/^W-\d{8}-[0-9A-F]{6}$/', $ref) && $valid
            ? Db::one('SELECT * FROM widerrufe WHERE ref = ? AND eingang > UTC_TIMESTAMP() - INTERVAL 1 DAY', [$ref])
            : null;
        if (!$row) {
            Http::redirect('/vertrag-widerrufen');
        }
        try {
            $rec = Crypto::decrypt($row['daten_enc'], 'widerrufe/' . $ref);
        } catch (\Throwable $e) {
            Log::error('Widerrufsbeleg nicht lesbar: ' . $e->getMessage());
            Http::redirect('/vertrag-widerrufen');
        }
        header('X-Robots-Tag: noindex');
        Http::html(self::donePage($rec, (bool) (int) $row['mail_versendet']));
    }
}
