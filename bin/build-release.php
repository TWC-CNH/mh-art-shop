<?php
// Stellt den Upload-Ordner für das FTP-Deployment zusammen: build/release/
// Enthält nur, was auf den Server gehört (mit vendor/ ohne Entwicklungspakete), keine .env, Tests oder Git-Daten.
// Aufruf: php bin/build-release.php   → danach build/release/ nach /public_html/matthiashuebner.art hochladen
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }

$root = dirname(__DIR__);
$out = "$root/build/release";
$include = ['bin', 'data', 'migrations', 'public', 'src', 'composer.json', 'composer.lock', '.env.example', 'README.md'];
$skip = ['#^public/bilder/(?!\.htaccess$)#', '#^bin/build-release\.php$#'];

function rrmdir(string $d): void
{
    if (!is_dir($d)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($d);
}

rrmdir($out);
mkdir($out, 0755, true);
$n = 0;
foreach ($include as $item) {
    $src = "$root/$item";
    $files = is_dir($src)
        ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS))
        : [new SplFileInfo($src)];
    foreach ($files as $f) {
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        foreach ($skip as $re) {
            if (preg_match($re, $rel)) {
                continue 2;
            }
        }
        @mkdir(dirname("$out/$rel"), 0755, true);
        copy($f->getPathname(), "$out/$rel");
        $n++;
    }
}
@mkdir("$out/public/bilder", 0755, true);

echo "$n Dateien kopiert. Installiere vendor/ ohne Entwicklungspakete …\n";
$composer = getenv('COMPOSER_BIN') ?: 'composer';
passthru(escapeshellcmd($composer) . ' install --no-dev --optimize-autoloader --no-interaction --no-progress --working-dir=' . escapeshellarg($out), $code);
if ($code !== 0) {
    fwrite(STDERR, "composer install fehlgeschlagen.\n");
    exit(1);
}
foreach (['vendor/phpunit', 'vendor/sebastian'] as $dev) {
    if (is_dir("$out/$dev")) {
        fwrite(STDERR, "Fehler: Entwicklungspaket $dev im Release.\n");
        exit(1);
    }
}
echo "\nFertig: build/release/\n"
    . "Hochladen nach /public_html/matthiashuebner.art (die .env auf dem Server bleibt unberührt),\n"
    . "dann per SSH: php bin/migrate.php && php bin/smoke.php\n";
