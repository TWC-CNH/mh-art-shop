<?php
// Router für den Test-Server: Uploads aus UPLOAD_DIR unter /bilder/ ausliefern, sonst wie public/index.php.
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/bilder/([a-z0-9-]+\.jpg)$#', $path, $m)) {
    $f = getenv('UPLOAD_DIR') . '/' . $m[1];
    if (is_file($f)) {
        header('Content-Type: image/jpeg');
        readfile($f);
        return true;
    }
    http_response_code(404);
    return true;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
return require dirname(__DIR__, 2) . '/public/index.php';
