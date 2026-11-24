<?php
// Minimaler Stripe-Ersatz für die Ende-zu-Ende-Tests (nur die Checkout-Endpunkte, die der Shop nutzt).
// Start: php -S 127.0.0.1:PORT tests/FakeStripe/router.php   (Zustand in FAKE_STRIPE_STATE, JSON-Datei)
// Zusätzlich: GET /__test/sessions liefert alle Sitzungen samt übergebener Parameter.
declare(strict_types=1);

$stateFile = getenv('FAKE_STRIPE_STATE') ?: sys_get_temp_dir() . '/fake-stripe.json';
$fh = fopen($stateFile, 'c+');
flock($fh, LOCK_EX);
$state = json_decode((string) stream_get_contents($fh), true) ?: ['n' => 0, 'sessions' => []];

$send = function (int $code, array $obj) use (&$state, $fh): never {
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($state));
    flock($fh, LOCK_UN);
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($obj);
    exit;
};
$public = fn (array $s) => array_diff_key($s, ['params' => 1]);

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$seg = array_values(array_filter(explode('/', $path)));

if ($path === '/__test/sessions') {
    $send(200, $state['sessions']);
}
// Test kann eine Sitzung verändern (z. B. als bezahlt markieren): POST /__test/sessions/<id> mit JSON
if (preg_match('#^/__test/sessions/([A-Za-z0-9_]+)$#', $path, $m) && $method === 'POST') {
    $state['sessions'][$m[1]] = array_merge($state['sessions'][$m[1]] ?? [], json_decode((string) file_get_contents('php://input'), true) ?: []);
    $send(200, $state['sessions'][$m[1]]);
}
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer sk_test_fake') {
    $send(401, ['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid API Key']]);
}

if (($seg[1] ?? '') === 'checkout' && ($seg[2] ?? '') === 'sessions') {
    $id = $seg[3] ?? null;
    if ($method === 'POST' && $id === null) {
        $p = $_POST;
        $li = $p['line_items'][0] ?? [];
        $unit = (int) ($li['price_data']['unit_amount'] ?? 0);
        if ($unit <= 0) {
            $send(400, ['error' => ['type' => 'invalid_request_error', 'message' => 'Missing price_data']]);
        }
        $n = ++$state['n'];
        $sid = sprintf('cs_test_%06dTEST', $n);
        $s = [
            'id' => $sid, 'object' => 'checkout.session', 'status' => 'open', 'payment_status' => 'unpaid',
            'url' => "https://checkout.stripe.test/pay/$n", 'metadata' => $p['metadata'] ?? [],
            'expires_at' => (int) ($p['expires_at'] ?? time() + 1800),
            'amount_total' => $unit + (int) ($p['shipping_options'][0]['shipping_rate_data']['fixed_amount']['amount'] ?? 0),
            'customer_details' => null, 'params' => $p,
        ];
        $state['sessions'][$sid] = $s;
        $send(200, $public($s));
    }
    if ($id !== null && !isset($state['sessions'][$id])) {
        $send(404, ['error' => ['type' => 'invalid_request_error', 'message' => 'No such checkout.session']]);
    }
    if ($method === 'POST' && ($seg[4] ?? '') === 'expire') {
        if ($state['sessions'][$id]['status'] !== 'open') {
            $send(400, ['error' => ['type' => 'invalid_request_error', 'message' => 'Session is not open']]);
        }
        $state['sessions'][$id]['status'] = 'expired';
        $send(200, $public($state['sessions'][$id]));
    }
    if ($method === 'GET' && $id !== null) {
        $send(200, $public($state['sessions'][$id]));
    }
}
$send(404, ['error' => ['type' => 'invalid_request_error', 'message' => "Unbekannter Endpunkt $method $path"]]);
