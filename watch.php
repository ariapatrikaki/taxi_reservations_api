<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

$config = appConfig();
$jsonFile = (string)$config['json_file'];
$timeoutSeconds = (int)$config['watch']['timeout_seconds'];
$checkInterval = (int)$config['watch']['check_interval_microseconds'];
$clientHash = trim((string)($_GET['hash'] ?? ''));

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Accel-Buffering: no');

session_write_close();
ignore_user_abort(true);
@set_time_limit($timeoutSeconds + 10);

$deadline = microtime(true) + $timeoutSeconds;

while (microtime(true) < $deadline) {
    clearstatcache(true, $jsonFile);

    if (!is_file($jsonFile)) {
        sendJsonResponse([
            'ok' => false,
            'changed' => false,
            'hash' => null,
            'message' => 'reservations.json was not found.',
        ]);
    }

    $jsonText = file_get_contents($jsonFile);

    if ($jsonText !== false) {
        $decoded = json_decode($jsonText, true);
        $isValid = is_array($decoded)
            && isset($decoded['reservations'])
            && is_array($decoded['reservations']);

        if ($isValid) {
            $serverHash = hash('sha256', $jsonText);

            if ($clientHash === '' || !hash_equals($clientHash, $serverHash)) {
                sendJsonResponse([
                    'ok' => true,
                    'changed' => true,
                    'hash' => $serverHash,
                ]);
            }
        }
    }

    usleep($checkInterval);
}

sendJsonResponse([
    'ok' => true,
    'changed' => false,
    'hash' => $clientHash,
]);

/** Send a JSON response and stop the request. */
function sendJsonResponse(array $payload): never
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
