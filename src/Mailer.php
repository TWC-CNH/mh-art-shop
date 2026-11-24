<?php
declare(strict_types=1);

namespace App;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * E-Mail-Versand per SMTP (Hetzner: mail.your-server.de, Port 587, STARTTLS). Kein mail().
 * Ohne SMTP-Zugangsdaten wird nichts versendet (sent = false), der Aufrufer entscheidet.
 * MAIL_CAPTURE_DIR (nur Tests/lokal): Mails werden als Datei abgelegt statt versendet.
 */
final class Mailer
{
    /** Adresse, die auch der Versand annimmt (Eingaben vorher damit prüfen). */
    public static function validAddress(string $email): bool
    {
        return mb_strlen($email) <= 200 && PHPMailer::validateAddress($email) && !preg_match('/[\r\n<>"]/', $email);
    }

    /** @return array{sent: bool, reason: string} */
    public static function send(string $to, string $subject, string $text, string $replyTo = ''): array
    {
        $cfg = Config::get();
        // Nur in Test/Entwicklung: Mails als Datei ablegen statt senden (im Betrieb wirkungslos)
        $capture = in_array($cfg['env'], ['test', 'local'], true) ? Env::get('MAIL_CAPTURE_DIR') : '';
        if ($capture !== '') {
            @mkdir($capture, 0750, true);
            file_put_contents($capture . '/' . microtime(true) . '-' . bin2hex(random_bytes(3)) . '.json', json_encode(compact('to', 'subject', 'text', 'replyTo'), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return ['sent' => true, 'reason' => 'captured'];
        }
        $s = $cfg['smtp'];
        if ($s['host'] === '' || $s['user'] === '' || $s['pass'] === '') {
            return ['sent' => false, 'reason' => 'not-configured'];
        }
        $m = new PHPMailer(true);
        try {
            $m->isSMTP();
            $m->Host = $s['host'];
            $m->Port = $s['port'];
            $m->SMTPAuth = true;
            $m->Username = $s['user'];
            $m->Password = $s['pass'];
            $m->SMTPSecure = $s['port'] === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $m->CharSet = PHPMailer::CHARSET_UTF8;
            $m->Timeout = 15;
            $m->setFrom($s['from'], $cfg['artistName']);
            $m->addAddress($to);
            if ($replyTo !== '') {
                try {
                    $m->addReplyTo($replyTo);
                } catch (\Throwable) {
                    Log::warn('Antwortadresse ungültig, Mail geht ohne Reply-To raus');
                }
            }
            $m->Subject = $subject;
            $m->Body = $text;
            $m->send();
            return ['sent' => true, 'reason' => ''];
        } catch (\Throwable $e) {
            // ErrorInfo kann Empfängeradressen enthalten: vor dem Protokollieren entfernen (keine personenbezogenen Daten ins Log)
            Log::error('E-Mail-Versand fehlgeschlagen: ' . preg_replace('/[^\s<>"@]+@[^\s<>",;]+/', '[Adresse]', $m->ErrorInfo));
            return ['sent' => false, 'reason' => 'Versand fehlgeschlagen'];
        }
    }
}
