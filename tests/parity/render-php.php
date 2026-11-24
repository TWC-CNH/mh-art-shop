<?php
// Vergleichs-Harness: rendert alle Seiten mit den PHP-Klassen nach tests/parity/out/php/.
// Aufruf über tests/parity/run.sh (setzt die Umgebungsvariablen und PARITY_VARIANT).
declare(strict_types=1);

use App\Config;
use App\Env;
use App\View\Legal;
use App\View\Render;
use App\Works;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// Eine lokale .env darf das Ergebnis nicht beeinflussen: nur echte Umgebungsvariablen zählen (wie in Node).
Env::load(__DIR__ . '/does-not-exist.env');
Config::reset();

$out = __DIR__ . '/out/php';
$variant = getenv('PARITY_VARIANT') ?: 'default';
if (!is_dir($out)) {
    mkdir($out, 0777, true);
}
$write = function (string $name, ?string $html) use ($out, $variant): void {
    file_put_contents("$out/$variant--$name.html", $html ?? '');
};

$fixtures = json_decode((string) file_get_contents(__DIR__ . '/fixtures.json'), true, 512, JSON_THROW_ON_ERROR);
$catalog = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/data/catalog.json'), true, 512, JSON_THROW_ON_ERROR);
$works = array_map([Works::class, 'fromCatalogJson'], $catalog);
if (!cfg()['showSold']) {
    $works = array_values(array_filter($works, fn ($w) => $w['status'] !== 'sold'));
}
$works = Works::sort($works);
$all = [...$works, ...$fixtures['edgeWorks']];

$write('home', Render::home($works));
$write('home-edge', Render::home($all));
foreach ($works as $w) {
    $write('werk-' . $w['slug'], Render::work($w, $works));
}
foreach ($fixtures['edgeWorks'] as $w) {
    $write('werk-' . $w['slug'], Render::work($w, $all));
}
foreach (Legal::KEYS as $key) {
    $write('legal-' . $key, Legal::render($key));
}
$write('notfound', Render::notFound());
$write('thanks-empty', Render::thanks(null, null));
foreach ($fixtures['sessions'] as $name => $s) {
    $work = null;
    foreach ($all as $w) {
        if ($w['slug'] === $s['workSlug']) {
            $work = $w;
            break;
        }
    }
    $write('thanks-' . $name, Render::thanks($s['session'], $work));
}
