<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

ensureSessionStarted();
requirePostRequest();
validateCsrfToken();

$bookingId = trim((string)($_POST['booking_id'] ?? ''));

try {
    if ($bookingId === '') {
        throw new RuntimeException('Missing booking ID.');
    }

    $config = appConfig();
    $database = initializeDatabase($config['database']);
    $pdo = $database['pdo'];

    deleteReservationFromApi((array)$config['api'], $bookingId);
    deleteLocalReservation($pdo, $bookingId);

    setFlashMessage('Reservation was deleted successfully.', 'success');
    redirectTo('index.php?reservation_deleted=1');
} catch (Throwable $error) {
    setFlashMessage('Reservation delete failed: ' . $error->getMessage(), 'error');
    redirectBack();
}
