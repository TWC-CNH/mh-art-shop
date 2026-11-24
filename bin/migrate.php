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
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        $pdo->exec($stmt);
    }
    Db::run('INSERT INTO schema_migrations (version) VALUES (?)', [$v]);
    echo "ausgeführt: $v\n";
    $n++;
}
echo $n ? "$n Migration(en) ausgeführt.\n" : "Schema ist aktuell.\n";
