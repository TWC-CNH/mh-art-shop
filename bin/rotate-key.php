<?php
// Schlüsselwechsel abschließen: verschlüsselt alle Einträge mit dem aktuellen DATA_ENCRYPTION_KEY neu.
//
// Ablauf:
//   1. php bin/generate-key.php → neuen Schlüssel als DATA_ENCRYPTION_KEY eintragen,
//      den bisherigen als DATA_ENCRYPTION_KEY_OLD
//   2. php bin/rotate-key.php
//   3. Meldet das Skript „fertig, nichts mehr mit altem Schlüssel“: DATA_ENCRYPTION_KEY_OLD aus der .env entfernen
//
// Neu verschlüsselt werden Widerrufe, Anfragen und die Zwei-Faktor-Geheimnisse; die geheimen Suchwerte
// (email_hmac) werden mit dem neuen Schlüssel neu berechnet. Läuft in einer Transaktion je Tabelle.
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use App\Crypto;
use App\Db;

if (!Crypto::ready()) {
    fwrite(STDERR, "Kein gültiger DATA_ENCRYPTION_KEY eingerichtet.\n");
    exit(1);
}

$tables = [
    // Tabelle => [Spalte, Kontext-Funktion, E-Mail-HMAC neu berechnen?]
    'widerrufe' => ['daten_enc', fn ($r) => 'widerrufe/' . $r['ref'], true],
    'anfragen' => ['daten_enc', fn ($r) => 'anfragen/' . $r['ref'], true],
    'atelier_benutzer' => ['totp_enc', fn ($r) => 'atelier_benutzer/totp/' . $r['id'], false],
];

$failed = 0;
foreach ($tables as $table => [$col, $ctx, $rehash]) {
    $done = 0;
    $skipped = 0;
    Db::transaction(function () use ($table, $col, $ctx, $rehash, &$done, &$skipped, &$failed) {
        foreach (Db::all("SELECT * FROM $table WHERE $col IS NOT NULL FOR UPDATE") as $r) {
            if (Crypto::usesCurrentKey($r[$col])) {
                $skipped++;
                continue;
            }
            try {
                $data = Crypto::decrypt($r[$col], $ctx($r));
            } catch (Throwable $e) {
                fwrite(STDERR, "  $table #{$r['id']}: nicht entschlüsselbar ({$e->getMessage()})\n");
                $failed++;
                continue;
            }
            $set = ["$col = ?"];
            $params = [Crypto::encrypt($data, $ctx($r))];
            if ($rehash && isset($data['email'])) {
                $set[] = 'email_hmac = ?';
                $params[] = Crypto::pseudonym((string) $data['email'], 'email');
            }
            Db::run("UPDATE $table SET " . implode(', ', $set) . ' WHERE id = ?', [...$params, $r['id']]);
            $done++;
        }
    });
    echo str_pad($table, 18) . "neu verschlüsselt: $done, schon aktuell: $skipped\n";
}
if ($failed) {
    echo "\n$failed Eintrag/Einträge konnten nicht entschlüsselt werden. DATA_ENCRYPTION_KEY_OLD NICHT entfernen, erst klären.\n";
    exit(1);
}
echo "\nFertig, nichts mehr mit altem Schlüssel. DATA_ENCRYPTION_KEY_OLD kann aus der .env entfernt werden.\n";
echo "Hinweis: Zähler der Missbrauchsbegrenzung beginnen mit dem neuen Schlüssel von vorn (unkritisch).\n";
