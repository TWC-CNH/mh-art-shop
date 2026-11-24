<?php
// Benutzer für das Atelier verwalten (per SSH auf dem Server). Jeder Benutzer braucht Passwort UND Authenticator-App.
//
//   php bin/atelier-user.php anlegen <benutzer> [--generate]   neuer Benutzer (oder Passwort neu setzen)
//   php bin/atelier-user.php passwort <benutzer> [--generate]  Passwort neu setzen (meldet alle Geräte ab)
//   php bin/atelier-user.php 2fa <benutzer>                    neue Authenticator-Einrichtung (z. B. neues Handy)
//   php bin/atelier-user.php notfallcodes <benutzer>           10 neue Notfall-Codes (alte werden ungültig)
//   php bin/atelier-user.php sperren <benutzer>                Zugang sperren (sofort abgemeldet)
//   php bin/atelier-user.php entsperren <benutzer>
//   php bin/atelier-user.php liste
//
// Optionen: --hell (QR-Code für helle Terminals), --qr-datei=<pfad.svg> (QR-Code zusätzlich als Bild speichern)
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use App\AtelierAuth;
use App\Db;
use App\Totp;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

$args = array_values(array_filter(array_slice($argv, 1), fn ($a) => !str_starts_with($a, '--')));
[$cmd, $name] = [$args[0] ?? '', $args[1] ?? ''];
$generate = in_array('--generate', $argv, true);
$light = in_array('--hell', $argv, true);
$qrFile = '';
foreach ($argv as $a) {
    if (str_starts_with($a, '--qr-datei=')) {
        $qrFile = substr($a, 11);
    }
}

$askPassword = function () use ($generate): string {
    if ($generate) {
        $pw = rtrim(strtr(base64_encode(random_bytes(15)), '+/', '-_'), '=');
        echo "Passwort (wird nur jetzt angezeigt, sicher weitergeben): $pw\n";
        return $pw;
    }
    echo 'Neues Passwort (mind. 12 Zeichen): ';
    if (DIRECTORY_SEPARATOR === '/') {
        system('stty -echo');
    }
    $pw = rtrim((string) fgets(STDIN), "\r\n"); // nur das Zeilenende entfernen – Leerzeichen gehören zum Passwort
    if (DIRECTORY_SEPARATOR === '/') {
        system('stty echo');
        echo "\n";
    }
    return $pw;
};

$show2fa = function (string $name, string $secret) use ($light, $qrFile): void {
    $uri = Totp::uri($secret, $name);
    echo "\nZwei-Faktor-Anmeldung einrichten – Authenticator-App öffnen (Google/Microsoft Authenticator, Aegis, 1Password …),\n";
    echo "„Konto hinzufügen“ und diesen QR-Code scannen:\n\n";
    // Im Terminal mit Halbblöcken (zwei Modulzeilen je Textzeile). Auf dunklem Hintergrund werden die hellen
    // Felder gezeichnet, auf hellem (--hell) die dunklen – so sieht der Code für die Kamera immer richtig aus.
    $matrix = (new QRCode(new QROptions(['quietzoneSize' => 2])))->addByteSegment($uri)->getQRMatrix()->getMatrix(true);
    $paint = fn (int $y, int $x): bool => isset($matrix[$y][$x]) ? ($light ? (bool) $matrix[$y][$x] : !$matrix[$y][$x]) : !$light;
    for ($y = 0; $y < count($matrix); $y += 2) {
        $line = '  ';
        foreach (array_keys($matrix[$y]) as $x) {
            [$top, $bottom] = [$paint($y, $x), $paint($y + 1, $x)];
            $line .= $top && $bottom ? '█' : ($top ? '▀' : ($bottom ? '▄' : ' '));
        }
        echo $line . "\n";
    }
    if ($qrFile !== '') {
        file_put_contents($qrFile, (new QRCode(new QROptions(['outputType' => QROutputInterface::MARKUP_SVG, 'outputBase64' => false])))->render($uri));
        @chmod($qrFile, 0600);
        echo "QR-Code zusätzlich gespeichert: $qrFile (enthält den Schlüssel – nach dem Einrichten löschen!)\n";
    }
    echo "\nOder in der App „Schlüssel eingeben“ wählen:\n";
    echo "  Konto:     MH ART Atelier ($name)\n";
    echo '  Schlüssel: ' . implode(' ', str_split($secret, 4)) . "\n";
    echo "  Typ:       zeitbasiert (30 Sekunden, 6 Ziffern)\n";
    echo "\nZur Kontrolle zeigt die App jetzt diesen Code: " . Totp::code($secret, Totp::currentStep()) . "\n";
};

$showRecovery = function (string $name): void {
    $codes = AtelierAuth::newRecoveryCodes($name);
    echo "\nNotfall-Codes für $name (je einmal verwendbar, falls das Handy fehlt) – ausdrucken und sicher aufbewahren:\n\n";
    foreach (array_chunk($codes, 2) as $pair) {
        echo '  ' . implode('      ', $pair) . "\n";
    }
    echo "\nAlle früheren Notfall-Codes dieses Zugangs sind damit ungültig.\n";
};

try {
    switch ($cmd) {
        case 'anlegen':
        case 'passwort':
            if ($name === '') {
                throw new InvalidArgumentException('Benutzername fehlt.');
            }
            if ($cmd === 'passwort' && !Db::one('SELECT 1 FROM atelier_benutzer WHERE benutzer = ?', [mb_strtolower($name)])) {
                throw new InvalidArgumentException("Benutzer $name gibt es nicht.");
            }
            $secret = AtelierAuth::saveUser($name, $askPassword());
            echo "Gespeichert: $name. Alle bisherigen Anmeldungen dieses Benutzers sind abgemeldet.\n";
            if ($secret) {
                $show2fa(mb_strtolower($name), $secret);
                $showRecovery($name);
            }
            break;
        case '2fa':
            $show2fa(mb_strtolower($name), AtelierAuth::reset2fa($name));
            echo "\nDie bisherige Einrichtung in der App gilt nicht mehr und kann dort gelöscht werden.\n";
            $showRecovery($name);
            break;
        case 'notfallcodes':
            $showRecovery($name);
            break;
        case 'sperren':
        case 'entsperren':
            echo AtelierAuth::setActive($name, $cmd === 'entsperren') ? "Erledigt.\n" : "Benutzer $name nicht gefunden.\n";
            break;
        case 'liste':
            foreach (Db::all('SELECT b.benutzer, b.aktiv, b.totp_enc IS NOT NULL AS zfa, b.letzter_login, (SELECT COUNT(*) FROM atelier_notfallcodes n WHERE n.benutzer_id = b.id AND n.benutzt IS NULL) AS notfall FROM atelier_benutzer b ORDER BY b.benutzer') as $u) {
                printf("%-20s %-9s 2FA: %-4s Notfall-Codes: %2d   letzter Login: %s\n", $u['benutzer'], $u['aktiv'] ? 'aktiv' : 'gesperrt', $u['zfa'] ? 'ja' : 'nein', $u['notfall'], $u['letzter_login'] ?? '–');
            }
            break;
        default:
            echo implode("\n", array_slice(file(__FILE__, FILE_IGNORE_NEW_LINES), 1, 11)) . "\n";
            exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Fehler: ' . $e->getMessage() . "\n");
    exit(1);
}
