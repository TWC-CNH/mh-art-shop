<?php
// Ein einziger Cronjob für alles (in konsoleH alle 5 Minuten, mit absolutem PHP-Pfad):
//   /usr/bin/php8.x /home/BENUTZER/public_html/matthiashuebner.art/bin/cron.php
// - abgelaufene Reservierungen freigeben, alte Zähler und Atelier-Sitzungen löschen (jedes Mal)
// - Aufbewahrungsfristen durchsetzen (einmal am Tag, Merker in der Datenbank)
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use App\AtelierAuth;
use App\Catalog;
use App\Config;
use App\Db;
use App\Env;
use App\Log;
use App\RateLimit;

$runDir = Env::get('RUN_DIR', dirname(__DIR__) . '/storage/run');
@mkdir($runDir, 0750, true);
$lock = fopen("$runDir/cron.lock", 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0); // läuft noch
}

try {
    $released = Catalog::releaseExpired();
    $rl = RateLimit::purge();
    $sess = AtelierAuth::purge();
    if ($released) {
        Log::info("Cron: $released abgelaufene Reservierung(en) freigegeben");
    }

    $last = Db::one('SELECT wert FROM merker WHERE name = ?', ['cron_taeglich']);
    if (!$last || $last['wert'] !== gmdate('Y-m-d')) {
        $r = Config::get()['retention'];
        $a = Db::run('DELETE FROM anfragen WHERE eingang < (UTC_TIMESTAMP() - INTERVAL ? MONTH)', [$r['inquiryMonths']]);
        $w = Db::run('DELETE FROM widerrufe WHERE eingang < (UTC_TIMESTAMP() - INTERVAL ? YEAR)', [$r['withdrawalYears']]);
        $e = Db::run('DELETE FROM webhook_events WHERE verarbeitet < (UTC_TIMESTAMP() - INTERVAL 90 DAY)');
        Db::run('INSERT INTO merker (name, wert) VALUES (?, ?) ON DUPLICATE KEY UPDATE wert = VALUES(wert)', ['cron_taeglich', gmdate('Y-m-d')]);
        Log::info("Cron täglich: $a Anfrage(n), $w Widerruf(e), $e Webhook-Einträge nach Ablauf der Frist gelöscht");
    }
    Db::run('INSERT INTO merker (name, wert) VALUES (?, ?) ON DUPLICATE KEY UPDATE wert = VALUES(wert)', ['cron_letzter_lauf', gmdate('Y-m-d H:i:s')]);
} catch (\Throwable $e) {
    Log::error('Cron-Fehler: ' . $e->getMessage());
    $exitCode = 1;
} finally {
    flock($lock, LOCK_UN);
}
exit($exitCode ?? 0);
