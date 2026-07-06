<?php
declare(strict_types=1);

session_start();

// 1. Έλεγχος ασφαλείας CSRF Token
if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    $_SESSION['flash_message'] = 'Invalid security token.';
    $_SESSION['flash_type'] = 'error';
    header('Location: index.php');
    exit;
}

// 2. Έλεγχος αν στάλθηκε το Booking ID
$bookingId = trim((string)($_POST['booking_id'] ?? ''));
if ($bookingId === '') {
    $_SESSION['flash_message'] = 'Missing booking ID.';
    $_SESSION['flash_type'] = 'error';
    header('Location: index.php');
    exit;
}

// 3. Στοιχεία του Live API
$apiUrl = 'https://transfers.n22st.eu/wp-json/transfer-now/v1/reservations/' . urlencode($bookingId);
$apiKey = 'tfn_py7dq41m7umtxvzlwtzpksxyft7j6v0a';

// 4. Εκτέλεση του DELETE αιτήματος με cURL στο Live API
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $apiUrl);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'X-TFN-Key: ' . $apiKey
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// 5. Διαγραφή από την τοπική σου βάση (MySQL στο XAMPP)
if ($httpCode === 200 || $httpCode === 204) {
    try {
        require_once __DIR__ . '/app.php';
        $config = appConfig();
        $database = initializeDatabase($config['database']);
        $pdo = $database['pdo'];

        // Διαγράφουμε την κράτηση από τον τοπικό πίνακα
        $stmt = $pdo->prepare('DELETE FROM reservations WHERE booking_id = :booking_id');
        $stmt->execute([':booking_id' => $bookingId]);

        $_SESSION['flash_message'] = "Reservation {$bookingId} deleted successfully from both Live API and local DB!";
        $_SESSION['flash_type'] = 'success';
    } catch (Throwable $e) {
        $_SESSION['flash_message'] = 'Deleted from Live API, but failed to delete from local DB: ' . $e->getMessage();
        $_SESSION['flash_type'] = 'error';
    }
} else {
    $_SESSION['flash_message'] = "Failed to delete from Live API. (HTTP Code: {$httpCode})";
    $_SESSION['flash_type'] = 'error';
}

// 6. Επιστροφή στο Dashboard
header('Location: index.php');
exit;