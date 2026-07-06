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

try {
    if ($bookingId === '') {
        throw new RuntimeException('Missing booking ID.');
    }

    $config = appConfig();
    $database = initializeDatabase($config['database']);
    $pdo = $database['pdo'];

    deleteReservationFromApi((array)$config['api'], $bookingId);
    deleteLocalReservation($pdo, $bookingId);

    $_SESSION['flash_message'] = 'Reservation was deleted successfully.';
    $_SESSION['flash_type'] = 'success';

    header('Location: index.php?reservation_deleted=1');
    exit;
} catch (Throwable $error) {
    $_SESSION['flash_message'] = 'Reservation delete failed: ' . $error->getMessage();
    $_SESSION['flash_type'] = 'error';

    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
    exit;
}
