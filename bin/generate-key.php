<?php
// Erzeugt einen Schlüssel für DATA_ENCRYPTION_KEY (32 Byte, Base64).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
echo 'DATA_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)) . "\n";
echo "Sicher aufbewahren (Passwortmanager). Geht der Schlüssel verloren, sind gespeicherte Widerrufe und Anfragen unlesbar.\n";
