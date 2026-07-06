<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

ensureSessionStarted();
requirePostRequest();
validateCsrfToken();

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

    setFlashMessage('Reservation status was updated successfully.', 'success');
    redirectBack();
} catch (Throwable $error) {
    setFlashMessage('Status update failed: ' . $error->getMessage(), 'error');
    redirectBack();
}
