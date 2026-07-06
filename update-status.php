<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/app.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

$sessionToken = (string)($_SESSION['csrf_token'] ?? '');
$submittedToken = (string)($_POST['csrf_token'] ?? '');

if (
    $sessionToken === ''
    || $submittedToken === ''
    || !hash_equals($sessionToken, $submittedToken)
) {
    http_response_code(403);
    exit('Invalid security token.');
}

$bookingId = trim((string)($_POST['booking_id'] ?? ''));
$status = trim((string)($_POST['status'] ?? ''));

try {
    $apiStatus = normalizeApiStatusValue($status);

    if ($bookingId === '') {
        throw new RuntimeException('Missing booking ID.');
    }

    $config = appConfig();
    $database = initializeDatabase($config['database']);
    $pdo = $database['pdo'];

    $sourceId = findReservationApiSourceId($pdo, $bookingId);

    if ($sourceId === '') {
        throw new RuntimeException(
            'This reservation does not have the numeric API ID required by the status endpoint.'
        );
    }

    postReservationStatusToApi(
        (array)$config['api'],
        $sourceId,
        $apiStatus
    );

    updateLocalReservationStatus($pdo, $bookingId, $apiStatus);

    $_SESSION['flash_message'] = 'Reservation status was updated successfully.';
    $_SESSION['flash_type'] = 'success';

    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
    exit;
} catch (Throwable $error) {
    $_SESSION['flash_message'] = 'Status update failed: ' . $error->getMessage();
    $_SESSION['flash_type'] = 'error';

    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
    exit;
}