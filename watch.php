<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

$config = appConfig();
$clientHash = trim((string)($_GET['hash'] ?? ''));

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $response = fetchReservationsApiResponse((array)$config['api']);
    decodeReservationsApiResponse($response);

    $serverHash = hash('sha256', $response);
    $changed = $clientHash === ''
        || !hash_equals($clientHash, $serverHash);

    sendJsonResponse([
        'ok' => true,
        'changed' => $changed,
        'hash' => $serverHash,
    ]);
} catch (Throwable $error) {
    sendJsonResponse([
        'ok' => false,
        'changed' => false,
        'hash' => $clientHash,
        'message' => $error->getMessage(),
    ], 502);
}

/** Send a JSON response and stop the request. */
function sendJsonResponse(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}