<?php
// Benutzer für das Atelier verwalten (per SSH auf dem Server). Jeder Benutzer braucht Passwort UND Authenticator-App.
//
//   php bin/atelier-user.php anlegen <benutzer> [--generate]   neuer Benutzer (oder Passwort neu setzen)
//   php bin/atelier-user.php passwort <benutzer> [--generate]  Passwort neu setzen (meldet alle Geräte ab)
//   php bin/atelier-user.php 2fa <benutzer>                    neue Authenticator-Einrichtung (z. B. neues Handy)
//   php bin/atelier-user.php sperren <benutzer>                Zugang sperren (sofort abgemeldet)
//   php bin/atelier-user.php entsperren <benutzer>
//   php bin/atelier-user.php liste
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use App\AtelierAuth;
use App\Db;
use App\Totp;

[$cmd, $name] = [$argv[1] ?? '', $argv[2] ?? ''];
$generate = in_array('--generate', $argv, true);

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
    $pw = trim((string) fgets(STDIN));
    if (DIRECTORY_SEPARATOR === '/') {
        system('stty echo');
        echo "\n";
    }
    return $pw;
};

$show2fa = function (string $name, string $secret): void {
    echo "\nZwei-Faktor-Anmeldung einrichten – in der Authenticator-App (Google/Microsoft Authenticator, Aegis, 1Password …):\n";
    echo "  „Konto hinzufügen“ → „Schlüssel eingeben“ / „Einrichtungsschlüssel“\n";
    echo "  Konto:     MH ART Atelier ($name)\n";
    echo '  Schlüssel: ' . implode(' ', str_split($secret, 4)) . "\n";
    echo "  Typ:       zeitbasiert (30 Sekunden, 6 Ziffern)\n";
    echo "\nOder diesen Link als QR-Code/Link öffnen (nur auf eigenem Gerät):\n  " . Totp::uri($secret, $name) . "\n";
    echo "\nZur Kontrolle zeigt die App jetzt diesen Code: " . Totp::code($secret, Totp::currentStep()) . "\n";
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
            }
            break;
        case '2fa':
            $show2fa(mb_strtolower($name), AtelierAuth::reset2fa($name));
            echo "\nDie bisherige Einrichtung in der App gilt nicht mehr und kann dort gelöscht werden.\n";
            break;
        case 'sperren':
        case 'entsperren':
            echo AtelierAuth::setActive($name, $cmd === 'entsperren') ? "Erledigt.\n" : "Benutzer $name nicht gefunden.\n";
            break;
        case 'liste':
            foreach (Db::all('SELECT benutzer, aktiv, totp_enc IS NOT NULL AS zfa, letzter_login FROM atelier_benutzer ORDER BY benutzer') as $u) {
                printf("%-20s %-9s 2FA: %-4s letzter Login: %s\n", $u['benutzer'], $u['aktiv'] ? 'aktiv' : 'gesperrt', $u['zfa'] ? 'ja' : 'nein', $u['letzter_login'] ?? '–');
            }
            break;
        default:
            echo file_get_contents(__FILE__, false, null, 0, 900);
            exit(1);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Fehler: ' . $e->getMessage() . "\n");
    exit(1);
}
