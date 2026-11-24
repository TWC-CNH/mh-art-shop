<?php
// Erzeugt den Hash für ATELIER_PASSWORD_HASH (Argon2id). Das Passwort selbst steht nirgends.
// Aufruf: php bin/atelier-password.php            (fragt nach dem Passwort)
//         php bin/atelier-password.php --generate (erzeugt ein zufälliges Passwort)
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }

if (in_array('--generate', $argv, true)) {
    $pw = rtrim(strtr(base64_encode(random_bytes(15)), '+/', '-_'), '=');
    echo "Passwort (einmalig angezeigt, sicher aufbewahren): $pw\n";
} else {
    echo 'Neues Atelier-Passwort (mind. 16 Zeichen): ';
    $pw = trim((string) fgets(STDIN));
    if (mb_strlen($pw) < 16) {
        fwrite(STDERR, "Zu kurz. Bitte mindestens 16 Zeichen.\n");
        exit(1);
    }
}
$algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
echo 'ATELIER_PASSWORD_HASH=' . password_hash($pw, $algo) . "\n";
echo "Diese Zeile in die .env auf dem Server eintragen. Wichtig: den Wert in einfache Anführungszeichen setzen.\n";
