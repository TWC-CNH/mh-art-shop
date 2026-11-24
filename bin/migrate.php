<?php
// Datenbankschema anlegen bzw. nachziehen: führt alle noch nicht ausgeführten migrations/*.sql aus.
// Aufruf: php bin/migrate.php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use App\Db;

$pdo = Db::pdo();
$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) NOT NULL PRIMARY KEY, ausgefuehrt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$done = array_column(Db::all('SELECT version FROM schema_migrations'), 'version');
$files = glob(dirname(__DIR__) . '/migrations/*.sql') ?: [];
sort($files);
$n = 0;
foreach ($files as $f) {
    $v = basename($f, '.sql');
    if (in_array($v, $done, true)) {
        continue;
    }
    $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($f));
    // Anweisungen einzeln (DDL schreibt in MySQL/MariaDB ohnehin sofort fest). Wurde eine Migration mittendrin
    // abgebrochen, überspringt ein erneuter Lauf, was schon da ist, statt dauerhaft festzuhängen.
    // Hinweis für neue Migrationen: kein „;“ innerhalb von Texten verwenden (es wird an „;“ getrennt).
    $already = [1050, 1060, 1061, 1068, 1091, 1826]; // Tabelle/Spalte/Index/Primärschlüssel/Fremdschlüssel schon vorhanden bzw. schon entfernt
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        // Unabhängig vom Fehlercode der Datenbank: schon vorhandene Spalten und Fremdschlüssel überspringen
        if (preg_match('/^ALTER\s+TABLE\s+`?(\w+)`?\s+ADD\s+COLUMN\s+`?(\w+)`?/i', $stmt, $m)
            && Db::one('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$m[1], $m[2]])) {
            echo "  übersprungen (schon vorhanden): Spalte {$m[1]}.{$m[2]}\n";
            continue;
        }
        if (preg_match('/^ALTER\s+TABLE\s+`?(\w+)`?\s+ADD\s+CONSTRAINT\s+`?(\w+)`?/i', $stmt, $m)
            && Db::one('SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?', [$m[1], $m[2]])) {
            echo "  übersprungen (schon vorhanden): Schlüssel {$m[2]}\n";
            continue;
        }
        try {
            $pdo->exec($stmt);
        } catch (PDOException $e) {
            if (!in_array((int) ($e->errorInfo[1] ?? 0), $already, true)) {
                throw $e;
            }
            echo "  übersprungen (schon vorhanden): " . substr(preg_replace('/\s+/', ' ', $stmt), 0, 70) . "…\n";
        }
    }
    Db::run('INSERT INTO schema_migrations (version) VALUES (?)', [$v]);
    echo "ausgeführt: $v\n";
    $n++;
}
echo $n ? "$n Migration(en) ausgeführt.\n" : "Schema ist aktuell.\n";
