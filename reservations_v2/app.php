<?php
declare(strict_types=1);

/** Return the application configuration. */
function appConfig(): array
{
    return [
        'database' => [
            'host' => 'localhost',
            'name' => 'taxi_dashboard',
            'user' => 'root',
            'password' => '',
            'charset' => 'utf8mb4',
        ],
        'api' => [
            'url' => 'https://transfers.n22st.eu/wp-json/transfer-now/v1/reservations/',
            'key' => 'tfn_py7dq41m7umtxvzlwtzpksxyft7j6v0a',
            'poll_interval_milliseconds' => 3600000,
            'auto_refresh_milliseconds' => 3600000,
        ],
        'cache' => [
            'enabled' => true,
            'ttl_seconds' => 3600,
            'directory' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'taxi_dashboard_cache',
        ],
    ];
}

/** Escape a value for safe HTML output. */
function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Convert a date/time value to a MySQL DATETIME string. */
function toMysqlDateTime(?string $value): ?string
{
    $value = trim((string)$value);

    if ($value === '') {
        return null;
    }

    try {
        return (new DateTime($value))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return null;
    }
}

/** Format a date/time value for the dashboard. */
function showDateTime(?string $value): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return '-';
    }

    try {
        return (new DateTime($value))->format('d/m/Y H:i');
    } catch (Throwable) {
        return $value;
    }
}

/** Format a status-like value consistently. */
function displayLabel(?string $value): string
{
    $value = trim((string)$value);
    return $value === '' ? '-' : ucwords(str_replace(['_', '-'], ' ', strtolower($value)));
}

/** Convert the raw payment method into a readable label. */
function displayPaymentMethod(?string $value): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return '';
    }

    if (str_starts_with(strtolower($value), 'pm_')) {
        return 'Online payment';
    }

    return displayLabel($value);
}

/** Return true only when a value should be displayed to the user. */
function hasVisibleValue(mixed $value): bool
{
    if ($value === null || $value === false) {
        return false;
    }

    if (is_int($value) || is_float($value)) {
        return (float)$value !== 0.0;
    }

    if (is_string($value)) {
        return trim($value) !== '';
    }

    return !empty($value);
}

/** Convert a JSON field name into a readable label. */
function humanizeFieldName(string $key): string
{
    return ucwords(str_replace(['_', '-'], ' ', trim($key)));
}

/** Convert an additional JSON value into readable text. */
function extraValueText(mixed $value): string
{
    if (is_bool($value)) {
        return $value ? 'Yes' : 'No';
    }

    if (is_array($value)) {
        $parts = [];

        foreach ($value as $item) {
            if (hasVisibleValue($item)) {
                $parts[] = extraValueText($item);
            }
        }

        return implode(', ', $parts);
    }

    return trim((string)$value);
}

/** Build a dashboard URL while preserving unrelated filters. */
function dashboardUrl(array $changes = []): string
{
    $params = $_GET;

    // Sync parameters are one-time actions and must not leak into filter links.
    unset($params['sync'], $params['auto_refresh'], $params['_']);

    foreach ($changes as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }

    return 'index.php' . ($params ? '?' . http_build_query($params) : '');
}


/** Return true when the dashboard should check the live API on this request. */
function dashboardSyncRequested(): bool
{
    return (string)($_GET['sync'] ?? '') === '1';
}

/** Return true when the current live API check was started automatically. */
function dashboardAutomaticSyncRequested(): bool
{
    return dashboardSyncRequested()
        && (string)($_GET['auto_refresh'] ?? '') === '1';
}


/** Return a normalized cache configuration. */
function dashboardCacheConfig(array $config): array
{
    $cacheConfig = (array)($config['cache'] ?? []);

    return [
        'enabled' => (bool)($cacheConfig['enabled'] ?? true),
        'ttl_seconds' => max(1, (int)($cacheConfig['ttl_seconds'] ?? 3600)),
        'directory' => (string)($cacheConfig['directory'] ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'taxi_dashboard_cache')),
    ];
}

/** Return the cache key used for one filter result set. */
function dashboardCacheKey(array $filters, string $apiHash): string
{
    ksort($filters);

    return hash('sha256', json_encode([
        'filters' => $filters,
        'api_hash' => $apiHash,
        'version' => 1,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** Return the absolute filename for one dashboard cache entry. */
function dashboardCacheFilename(array $filters, string $apiHash, array $cacheConfig): string
{
    return rtrim((string)$cacheConfig['directory'], DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'dashboard_' . dashboardCacheKey($filters, $apiHash) . '.json';
}

/** Create the cache directory when possible. */
function ensureDashboardCacheDirectory(array $cacheConfig): bool
{
    $directory = (string)$cacheConfig['directory'];

    if (is_dir($directory)) {
        return is_writable($directory);
    }

    return mkdir($directory, 0775, true) && is_writable($directory);
}

/** Read a cached dashboard result set when it is still fresh. */
function readDashboardCache(array $filters, string $apiHash, array $cacheConfig): ?array
{
    if (!(bool)$cacheConfig['enabled'] || $apiHash === '') {
        return null;
    }

    $filename = dashboardCacheFilename($filters, $apiHash, $cacheConfig);

    if (!is_file($filename)) {
        return null;
    }

    $ttlSeconds = max(1, (int)$cacheConfig['ttl_seconds']);

    if ((time() - (int)filemtime($filename)) > $ttlSeconds) {
        @unlink($filename);
        return null;
    }

    $payload = json_decode((string)file_get_contents($filename), true);

    if (!is_array($payload)) {
        @unlink($filename);
        return null;
    }

    return $payload;
}

/** Store one dashboard result set in the file cache. */
function writeDashboardCache(array $filters, string $apiHash, array $payload, array $cacheConfig): void
{
    if (!(bool)$cacheConfig['enabled'] || $apiHash === '') {
        return;
    }

    if (!ensureDashboardCacheDirectory($cacheConfig)) {
        return;
    }

    $filename = dashboardCacheFilename($filters, $apiHash, $cacheConfig);
    $temporaryFilename = $filename . '.tmp';

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($json === false) {
        return;
    }

    file_put_contents($temporaryFilename, $json, LOCK_EX);
    @rename($temporaryFilename, $filename);
}

/** Remove all saved dashboard filter results. */
function clearDashboardCache(?array $cacheConfig = null): void
{
    $cacheConfig ??= dashboardCacheConfig(appConfig());

    $directory = (string)$cacheConfig['directory'];

    if (!is_dir($directory)) {
        return;
    }

    foreach (glob(rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'dashboard_*.json') ?: [] as $filename) {
        if (is_file($filename)) {
            @unlink($filename);
        }
    }
}

/**
 * Load the visible dashboard data from cache, or from MySQL when cache misses.
 *
 * @return array{reservations: array, trips_by_reservation: array, summary: array, from_cache: bool}
 */
function loadDashboardData(PDO $pdo, array $filters, string $apiHash, array $cacheConfig): array
{
    $cachedData = readDashboardCache($filters, $apiHash, $cacheConfig);

    if ($cachedData !== null) {
        return [
            'reservations' => (array)($cachedData['reservations'] ?? []),
            'trips_by_reservation' => (array)($cachedData['trips_by_reservation'] ?? []),
            'summary' => (array)($cachedData['summary'] ?? []),
            'from_cache' => true,
        ];
    }

    $reservations = findReservations($pdo, $filters);
    $reservationIds = array_map(
        static fn(array $reservation): int => (int)$reservation['id'],
        $reservations
    );
    $tripsByReservation = findTripsByReservationIds($pdo, $reservationIds);
    $summary = getReservationSummary($pdo);

    $payload = [
        'reservations' => $reservations,
        'trips_by_reservation' => $tripsByReservation,
        'summary' => $summary,
    ];

    writeDashboardCache($filters, $apiHash, $payload, $cacheConfig);

    return $payload + ['from_cache' => false];
}

/** Start the PHP session when it has not already been started. */
function ensureSessionStarted(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

/** Return the current CSRF token, creating it when needed. */
function ensureCsrfToken(): string
{
    ensureSessionStarted();

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['csrf_token'];
}

/** Stop the request unless it is a POST request. */
function requirePostRequest(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Method not allowed.');
    }
}

/** Validate the submitted CSRF token against the current session token. */
function validateCsrfToken(): void
{
    ensureSessionStarted();

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
}

/** Save a flash message for the next dashboard request. */
function setFlashMessage(string $message, string $type = 'info'): void
{
    ensureSessionStarted();
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
}

/** Read and remove the current flash message from the session. */
function consumeFlashMessage(): array
{
    ensureSessionStarted();

    $message = (string)($_SESSION['flash_message'] ?? '');
    $type = (string)($_SESSION['flash_type'] ?? 'info');

    unset($_SESSION['flash_message'], $_SESSION['flash_type']);

    return [
        'message' => $message,
        'type' => $type,
    ];
}

/** Redirect to a URL and stop the request. */
function redirectTo(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/** Redirect back to the previous page, or to a safe fallback. */
function redirectBack(string $fallback = 'index.php'): never
{
    redirectTo((string)($_SERVER['HTTP_REFERER'] ?? $fallback));
}

/** Return the CSS class used for a reservation status badge. */
function statusBadgeClass(?string $status): string
{
    return match (strtolower(trim((string)$status))) {
        'active' => 'badge badge-success',
        'confirmed' => 'badge badge-confirmed',
        'completed' => 'badge badge-info',
        'cancelled', 'canceled' => 'badge badge-danger',
        'pending' => 'badge badge-warning',
        default => 'badge badge-neutral',
    };
}

/** Return the CSS class used for a payment status badge. */
function paymentBadgeClass(?string $status): string
{
    return match (strtolower(trim((string)$status))) {
        'paid' => 'badge badge-success',
        'refunded' => 'badge badge-info',
        'pending' => 'badge badge-warning',
        'partially paid', 'partially_paid' => 'badge badge-purple',
        default => 'badge badge-neutral',
    };
}

/**
 * Create the database and required tables, then return an active PDO connection.
 *
 * @return array{pdo: PDO, force_resync: bool}
 */
function initializeDatabase(array $databaseConfig): array
{
    $host = (string)$databaseConfig['host'];
    $name = (string)$databaseConfig['name'];
    $user = (string)$databaseConfig['user'];
    $password = (string)$databaseConfig['password'];
    $charset = (string)$databaseConfig['charset'];

    $pdoOptions = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $serverPdo = new PDO(
        "mysql:host={$host};charset={$charset}",
        $user,
        $password,
        $pdoOptions
    );

    $safeDatabaseName = str_replace('`', '``', $name);
    $serverPdo->exec(
        "CREATE DATABASE IF NOT EXISTS `{$safeDatabaseName}`
         CHARACTER SET utf8mb4
         COLLATE utf8mb4_unicode_ci"
    );

    $pdo = new PDO(
        "mysql:host={$host};dbname={$name};charset={$charset}",
        $user,
        $password,
        $pdoOptions
    );

    createReservationsTable($pdo);
    createTripsTable($pdo);
    createMetadataTable($pdo);

    $schemaChanged = ensureReservationColumns($pdo);
    $schemaChanged = ensureTripsTimeNullable($pdo) || $schemaChanged;

    return [
        'pdo' => $pdo,
        'force_resync' => $schemaChanged,
    ];
}

/** Create the main reservations table. */
function createReservationsTable(PDO $pdo): void
{
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS reservations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            source_id VARCHAR(40) NULL,
            booking_id VARCHAR(80) NOT NULL UNIQUE,
            trip_type VARCHAR(30) NOT NULL,
            customer_name VARCHAR(150) NOT NULL,
            customer_email VARCHAR(190) NULL,
            customer_phone VARCHAR(80) NULL,
            adults INT NOT NULL DEFAULT 0,
            children INT NOT NULL DEFAULT 0,
            luggage_count INT NOT NULL DEFAULT 0,
            baby_seats INT NOT NULL DEFAULT 0,
            extra_items_json LONGTEXT NULL,
            vehicle_type VARCHAR(80) NULL,
            driver_id VARCHAR(50) NULL,
            flight_number VARCHAR(120) NULL,
            airline VARCHAR(150) NULL,
            flight_from_airport VARCHAR(255) NULL,
            flight_from_datetime DATETIME NULL,
            flight_to_airport VARCHAR(255) NULL,
            flight_to_datetime DATETIME NULL,
            flight_details_text LONGTEXT NULL,
            current_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            original_price DECIMAL(10,2) NOT NULL DEFAULT 0,
            status VARCHAR(80) NULL,
            payment_status VARCHAR(80) NULL,
            payment_method VARCHAR(120) NULL,
            customer_message TEXT NULL,
            created_at DATETIME NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_source_id (source_id),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB
          DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci
        SQL);
}

/** Create the table that stores one-way and return trip legs. */
function createTripsTable(PDO $pdo): void
{
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS trips (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            reservation_id INT UNSIGNED NOT NULL,
            label VARCHAR(30) NOT NULL,
            route_from VARCHAR(255) NOT NULL,
            route_to VARCHAR(255) NOT NULL,
            trip_date DATE NOT NULL,
            trip_time TIME NULL,
            CONSTRAINT fk_trips_reservation
                FOREIGN KEY (reservation_id)
                REFERENCES reservations(id)
                ON DELETE CASCADE,
            INDEX idx_trip_date (trip_date),
            INDEX idx_route_from (route_from),
            INDEX idx_route_to (route_to)
        ) ENGINE=InnoDB
          DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci
        SQL);
}

/** Create the table used for application metadata. */
function createMetadataTable(PDO $pdo): void
{
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS app_meta (
            meta_key VARCHAR(100) PRIMARY KEY,
            meta_value TEXT NULL
        ) ENGINE=InnoDB
          DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci
        SQL);
}

/** Add columns required by the real API response to an existing database. */
function ensureReservationColumns(PDO $pdo): bool
{
    $requiredColumns = [
        'source_id' => 'VARCHAR(40) NULL AFTER id',
        'customer_email' => 'VARCHAR(190) NULL AFTER customer_name',
        'driver_id' => 'VARCHAR(50) NULL AFTER vehicle_type',
        'flight_details_text' => 'LONGTEXT NULL AFTER flight_to_datetime',
        'payment_method' => 'VARCHAR(120) NULL AFTER payment_status',
        'extra_items_json' => 'LONGTEXT NULL AFTER baby_seats',
    ];

    $changed = false;

    foreach ($requiredColumns as $columnName => $definition) {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?'
        );
        $statement->execute(['reservations', $columnName]);

        if ((int)$statement->fetchColumn() === 0) {
            $safeColumn = str_replace('`', '``', $columnName);
            $pdo->exec("ALTER TABLE reservations ADD COLUMN `{$safeColumn}` {$definition}");
            $changed = true;
        }
    }

    return $changed;
}

/** Allow missing pickup or return times from the real reservation feed. */
function ensureTripsTimeNullable(PDO $pdo): bool
{
    $statement = $pdo->query(
        "SELECT IS_NULLABLE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'trips'
           AND COLUMN_NAME = 'trip_time'"
    );
    $isNullable = $statement->fetchColumn();

    if ($isNullable === 'YES') {
        return false;
    }

    $pdo->exec('ALTER TABLE trips MODIFY trip_time TIME NULL');
    return true;
}


/** Ensure the cURL extension is available before calling the live API. */
function requireCurlExtension(): void
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException(
            'The PHP cURL extension is not enabled. Enable extension=curl in php.ini.'
        );
    }
}

/** Return a validated reservation API base URL. */
function reservationApiBaseUrl(array $apiConfig): string
{
    $apiUrl = rtrim((string)($apiConfig['url'] ?? ''), '/');

    if ($apiUrl === '') {
        throw new RuntimeException('The reservations API URL or key is missing.');
    }

    return $apiUrl;
}

/** Return a validated reservation API key. */
function reservationApiKey(array $apiConfig): string
{
    $apiKey = trim((string)($apiConfig['key'] ?? ''));

    if ($apiKey === '') {
        throw new RuntimeException('The reservations API URL or key is missing.');
    }

    return $apiKey;
}

/** Build a reservation API endpoint from the configured base URL and path. */
function reservationApiEndpoint(array $apiConfig, string $path = ''): string
{
    $baseUrl = reservationApiBaseUrl($apiConfig);
    $path = ltrim($path, '/');

    return $path === '' ? $baseUrl . '/' : $baseUrl . '/' . $path;
}

/** Execute one request against the protected reservation API. */
function sendReservationApiRequest(
    array $apiConfig,
    string $method = 'GET',
    string $path = '',
    ?array $payload = null,
    string $errorContext = 'reservations API request'
): string {
    requireCurlExtension();

    $method = strtoupper(trim($method));
    $endpoint = reservationApiEndpoint($apiConfig, $path);
    $apiKey = reservationApiKey($apiConfig);
    $headers = [
        'X-TFN-Key: ' . $apiKey,
        'Accept: application/json',
    ];

    $curlOptions = [
        CURLOPT_URL => $endpoint,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => $method,
    ];

    if ($payload !== null) {
        $encodedPayload = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($encodedPayload === false) {
            throw new RuntimeException('Could not encode the API request payload.');
        }

        $headers[] = 'Content-Type: application/json';
        $curlOptions[CURLOPT_POSTFIELDS] = $encodedPayload;
    } elseif ($method !== 'GET') {
        $headers[] = 'Content-Type: application/json';
    }

    $curlOptions[CURLOPT_HTTPHEADER] = $headers;

    $curl = curl_init();
    curl_setopt_array($curl, $curlOptions);

    $response = curl_exec($curl);
    $curlError = curl_error($curl);
    $httpStatus = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);

    curl_close($curl);

    if ($response === false) {
        throw new RuntimeException(
            'The ' . $errorContext . ' failed: ' . ($curlError ?: 'Unknown cURL error.')
        );
    }

    if ($httpStatus < 200 || $httpStatus >= 300) {
        $message = trim((string)$response);
        throw new RuntimeException(
            'The ' . $errorContext . ' returned HTTP ' . $httpStatus
            . ($message !== '' ? ': ' . $message : '.')
        );
    }

    return (string)$response;
}

/**
 * Fetch the reservations from the protected WordPress REST API.
 *
 * The cURL request uses the exact endpoint and X-TFN-Key supplied for the
 * reservation service. It runs only on the PHP server, so the key is never
 * exposed to browser JavaScript.
 */
function fetchReservationsApiResponse(array $apiConfig): string
{
    return sendReservationApiRequest(
        $apiConfig,
        'GET',
        '',
        null,
        'reservations API request'
    );
}

/**
 * Decode and validate one reservations API response.
 *
 * @return array{reservations: array, total: int, limit: int, offset: int}
 */
function decodeReservationsApiResponse(string $response): array
{
    $data = json_decode($response, true);

    if (
        !is_array($data)
        || !isset($data['reservations'])
        || !is_array($data['reservations'])
    ) {
        throw new RuntimeException(
            'The reservations API did not return a valid reservations list.'
        );
    }

    return [
        'reservations' => $data['reservations'],
        'total' => (int)($data['total'] ?? count($data['reservations'])),
        'limit' => (int)($data['limit'] ?? count($data['reservations'])),
        'offset' => (int)($data['offset'] ?? 0),
    ];
}

/**
 * Synchronize the protected reservations API with MySQL only when its
 * response has changed.
 *
 * @return array{message: string, hash: string}
 */
function syncReservationsFromApi(
    PDO $pdo,
    array $apiConfig,
    bool $forceResync = false
): array {
    try {
        $response = fetchReservationsApiResponse($apiConfig);
        $apiHash = hash('sha256', $response);
        $savedHash = getSavedApiHash($pdo);

        if (
            !$forceResync
            && $savedHash !== false
            && hash_equals((string)$savedHash, $apiHash)
        ) {
            return [
                'message' => '',
                'hash' => $apiHash,
            ];
        }

        $data = decodeReservationsApiResponse($response);
        $count = importReservations($pdo, $data['reservations'], $apiHash);

        return [
            'message' => $count . ' reservations were synchronized from the live API.',
            'hash' => $apiHash,
        ];
    } catch (Throwable $error) {
        return [
            'message' => 'Live API synchronization failed: ' . $error->getMessage(),
            'hash' => (string)(getSavedApiHash($pdo) ?: ''),
        ];
    }
}

/** Read the hash of the last successfully synchronized API response. */
function getSavedApiHash(PDO $pdo): string|false
{
    $statement = $pdo->prepare(
        "SELECT meta_value FROM app_meta WHERE meta_key = 'reservations_api_hash'"
    );
    $statement->execute();
    return $statement->fetchColumn();
}

/** Import all API reservations inside a single database transaction. */
function importReservations(PDO $pdo, array $reservations, string $apiHash): int
{
    $reservationStatement = $pdo->prepare(reservationUpsertSql());
    $findReservationStatement = $pdo->prepare(
        'SELECT id FROM reservations WHERE booking_id = ?'
    );
    $deleteTripsStatement = $pdo->prepare(
        'DELETE FROM trips WHERE reservation_id = ?'
    );
    $insertTripStatement = $pdo->prepare(<<<SQL
        INSERT INTO trips (
            reservation_id,
            label,
            route_from,
            route_to,
            trip_date,
            trip_time
        ) VALUES (?, ?, ?, ?, ?, ?)
        SQL);

    $bookingIds = [];
    $pdo->beginTransaction();

    try {
        foreach ($reservations as $rawReservation) {
            if (!is_array($rawReservation)) {
                continue;
            }

            $reservation = normalizeReservation($rawReservation);
            $bookingId = trim((string)$reservation['booking_id']);

            if ($bookingId === '') {
                continue;
            }

            $bookingIds[] = $bookingId;
            upsertReservation($reservationStatement, $reservation);

            $findReservationStatement->execute([$bookingId]);
            $reservationId = (int)$findReservationStatement->fetchColumn();

            $deleteTripsStatement->execute([$reservationId]);
            insertReservationTrips($insertTripStatement, $reservationId, $reservation['trips']);
        }

        deleteReservationsMissingFromApi($pdo, $bookingIds);
        saveApiHash($pdo, $apiHash);
        $pdo->commit();
        clearDashboardCache();

        return count($bookingIds);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }
}

/** Normalize the reservation structure returned by the live API. */
function normalizeReservation(array $reservation): array
{
    if (array_key_exists('pickup_from', $reservation)) {
        return normalizeFlatReservation($reservation);
    }

    return normalizeNestedReservation($reservation);
}

/** Normalize the real flat API reservation structure. */
function normalizeFlatReservation(array $reservation): array
{
    $isReturn = filter_var($reservation['return_trip'] ?? false, FILTER_VALIDATE_BOOL)
        || (string)($reservation['return_trip'] ?? '') === '1';

    $pickupFrom = trim((string)($reservation['pickup_from'] ?? ''));
    $dropoffTo = trim((string)($reservation['dropoff_to'] ?? ''));
    $trips = [[
        'label' => 'Go',
        'route_from' => $pickupFrom,
        'route_to' => $dropoffTo,
        'date' => trim((string)($reservation['pickup_date'] ?? '')),
        'time' => trim((string)($reservation['pickup_time'] ?? '')),
    ]];

    if ($isReturn && hasVisibleValue($reservation['return_date'] ?? null)) {
        $trips[] = [
            'label' => 'Return',
            'route_from' => $dropoffTo,
            'route_to' => $pickupFrom,
            'date' => trim((string)$reservation['return_date']),
            'time' => trim((string)($reservation['return_time'] ?? '')),
        ];
    }

    $flightText = trim((string)($reservation['flight_details'] ?? ''));
    $flights = parseFlightDetails($flightText);
    $firstFlight = $flights[0] ?? [];
    $childSeats = trim((string)($reservation['child_seats'] ?? ''));
    $extraItems = [];

    if ($childSeats !== '') {
        $extraItems['child_seats'] = $childSeats;
    }

    return [
        'source_id' => trim((string)($reservation['id'] ?? '')),
        'booking_id' => trim((string)($reservation['booking_id'] ?? '')),
        'trip_type' => $isReturn ? 'round_trip' : 'one_way',
        'customer_name' => trim((string)($reservation['customer_name'] ?? '')),
        'customer_email' => trim((string)($reservation['customer_email'] ?? '')),
        'customer_phone' => trim((string)($reservation['customer_phone'] ?? '')),
        'adults' => (int)($reservation['adults'] ?? 0),
        'children' => (int)($reservation['children'] ?? 0),
        'luggage_count' => (int)($reservation['luggage'] ?? 0),
        'baby_seats' => 0,
        'extra_items' => $extraItems,
        'vehicle_type' => trim((string)($reservation['vehicle'] ?? '')),
        'driver_id' => trim((string)($reservation['driver_id'] ?? '')),
        'flight_number' => trim((string)($reservation['flight_number'] ?? '')),
        'airline' => trim((string)($firstFlight['airline'] ?? '')),
        'flight_from_airport' => trim((string)($firstFlight['from_airport'] ?? '')),
        'flight_from_datetime' => toMysqlDateTime($firstFlight['from_datetime'] ?? null),
        'flight_to_airport' => trim((string)($firstFlight['to_airport'] ?? '')),
        'flight_to_datetime' => toMysqlDateTime($firstFlight['to_datetime'] ?? null),
        'flight_details_text' => $flightText,
        'current_price' => (float)($reservation['final_price'] ?? 0),
        'original_price' => (float)($reservation['price'] ?? 0),
        'status' => displayLabel((string)($reservation['status'] ?? '')),
        'payment_status' => displayLabel((string)($reservation['payment_status'] ?? '')),
        'payment_method' => trim((string)($reservation['payment_method'] ?? '')),
        'customer_message' => trim((string)($reservation['customer_message'] ?? '')),
        'created_at' => toMysqlDateTime((string)($reservation['created_at'] ?? '')),
        'trips' => $trips,
    ];
}

/** Keep compatibility with the earlier nested reservation structure. */
function normalizeNestedReservation(array $reservation): array
{
    $flight = is_array($reservation['flight'] ?? null) ? $reservation['flight'] : [];
    $flightFrom = is_array($flight['from'] ?? null) ? $flight['from'] : [];
    $flightTo = is_array($flight['to'] ?? null) ? $flight['to'] : [];
    $passengers = is_array($reservation['passengers'] ?? null) ? $reservation['passengers'] : [];
    $luggage = is_array($reservation['luggage'] ?? null) ? $reservation['luggage'] : [];
    $pricing = is_array($reservation['pricing'] ?? null) ? $reservation['pricing'] : [];
    $customer = is_array($reservation['customer'] ?? null) ? $reservation['customer'] : [];
    $trips = [];

    foreach ((array)($reservation['trips'] ?? []) as $trip) {
        if (!is_array($trip)) {
            continue;
        }

        $route = is_array($trip['route'] ?? null) ? $trip['route'] : [];
        $trips[] = [
            'label' => $trip['label'] ?? 'Trip',
            'route_from' => $route['from'] ?? '',
            'route_to' => $route['to'] ?? '',
            'date' => $trip['date'] ?? '',
            'time' => $trip['time'] ?? '',
        ];
    }

    return [
        'source_id' => '',
        'booking_id' => trim((string)($reservation['booking_id'] ?? '')),
        'trip_type' => $reservation['trip_type'] ?? 'one_way',
        'customer_name' => trim((string)($customer['name'] ?? '')),
        'customer_email' => trim((string)($customer['email'] ?? '')),
        'customer_phone' => trim((string)($customer['phone'] ?? '')),
        'adults' => (int)($passengers['adults'] ?? 0),
        'children' => (int)($passengers['children'] ?? 0),
        'luggage_count' => (int)($luggage['luggage'] ?? 0),
        'baby_seats' => (int)($luggage['baby_seats'] ?? 0),
        'extra_items' => collectExtraItems($passengers, $luggage),
        'vehicle_type' => trim((string)($reservation['vehicle_type'] ?? '')),
        'driver_id' => trim((string)($reservation['driver_id'] ?? '')),
        'flight_number' => trim((string)($flight['flight_number'] ?? '')),
        'airline' => trim((string)($flight['airline'] ?? '')),
        'flight_from_airport' => trim((string)($flightFrom['airport'] ?? '')),
        'flight_from_datetime' => toMysqlDateTime($flightFrom['datetime'] ?? null),
        'flight_to_airport' => trim((string)($flightTo['airport'] ?? '')),
        'flight_to_datetime' => toMysqlDateTime($flightTo['datetime'] ?? null),
        'flight_details_text' => '',
        'current_price' => (float)($pricing['current_price'] ?? 0),
        'original_price' => (float)($pricing['original_price'] ?? 0),
        'status' => displayLabel((string)($reservation['status'] ?? '')),
        'payment_status' => displayLabel((string)($reservation['payment_status'] ?? '')),
        'payment_method' => trim((string)($reservation['payment_method'] ?? '')),
        'customer_message' => trim((string)($reservation['customer_message'] ?? '')),
        'created_at' => toMysqlDateTime($reservation['created_at'] ?? null),
        'trips' => $trips,
    ];
}

/** Insert or update one normalized reservation. */
function upsertReservation(PDOStatement $statement, array $reservation): void
{
    $extraItems = $reservation['extra_items'];

    $statement->execute([
        ':source_id' => $reservation['source_id'] ?: null,
        ':booking_id' => $reservation['booking_id'],
        ':trip_type' => $reservation['trip_type'],
        ':customer_name' => $reservation['customer_name'],
        ':customer_email' => $reservation['customer_email'] ?: null,
        ':customer_phone' => $reservation['customer_phone'] ?: null,
        ':adults' => $reservation['adults'],
        ':children' => $reservation['children'],
        ':luggage_count' => $reservation['luggage_count'],
        ':baby_seats' => $reservation['baby_seats'],
        ':extra_items_json' => $extraItems
            ? json_encode($extraItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null,
        ':vehicle_type' => $reservation['vehicle_type'] ?: null,
        ':driver_id' => $reservation['driver_id'] ?: null,
        ':flight_number' => $reservation['flight_number'] ?: null,
        ':airline' => $reservation['airline'] ?: null,
        ':flight_from_airport' => $reservation['flight_from_airport'] ?: null,
        ':flight_from_datetime' => $reservation['flight_from_datetime'],
        ':flight_to_airport' => $reservation['flight_to_airport'] ?: null,
        ':flight_to_datetime' => $reservation['flight_to_datetime'],
        ':flight_details_text' => $reservation['flight_details_text'] ?: null,
        ':current_price' => $reservation['current_price'],
        ':original_price' => $reservation['original_price'],
        ':status' => $reservation['status'] ?: null,
        ':payment_status' => $reservation['payment_status'] ?: null,
        ':payment_method' => $reservation['payment_method'] ?: null,
        ':customer_message' => $reservation['customer_message'] ?: null,
        ':created_at' => $reservation['created_at'],
    ]);
}

/** Keep unknown passenger and luggage fields from legacy records. */
function collectExtraItems(array $passengers, array $luggage): array
{
    $extraItems = [];

    foreach ($passengers as $key => $value) {
        if (!in_array((string)$key, ['adults', 'children'], true) && hasVisibleValue($value)) {
            $extraItems[(string)$key] = $value;
        }
    }

    foreach ($luggage as $key => $value) {
        if (!in_array((string)$key, ['luggage', 'baby_seats'], true) && hasVisibleValue($value)) {
            $extraItems[(string)$key] = $value;
        }
    }

    return $extraItems;
}

/** Insert all trip legs for one reservation. */
function insertReservationTrips(PDOStatement $statement, int $reservationId, array $trips): void
{
    foreach ($trips as $trip) {
        if (!is_array($trip)) {
            continue;
        }

        $date = trim((string)($trip['date'] ?? ''));

        if ($date === '') {
            continue;
        }

        $time = trim((string)($trip['time'] ?? ''));
        $mysqlTime = null;

        if ($time !== '') {
            $mysqlTime = strlen($time) === 5 ? $time . ':00' : $time;
        }

        $statement->execute([
            $reservationId,
            $trip['label'] ?? 'Trip',
            $trip['route_from'] ?? '',
            $trip['route_to'] ?? '',
            $date,
            $mysqlTime,
        ]);
    }
}

/** Delete database rows that no longer exist in the latest API response. */
function deleteReservationsMissingFromApi(PDO $pdo, array $bookingIds): void
{
    if (!$bookingIds) {
        $pdo->exec('DELETE FROM reservations');
        return;
    }

    $placeholders = implode(',', array_fill(0, count($bookingIds), '?'));
    $statement = $pdo->prepare(
        "DELETE FROM reservations WHERE booking_id NOT IN ({$placeholders})"
    );
    $statement->execute($bookingIds);
}

/** Save the API response hash only after a successful synchronization. */
function saveApiHash(PDO $pdo, string $apiHash): void
{
    $statement = $pdo->prepare(<<<SQL
        INSERT INTO app_meta (meta_key, meta_value)
        VALUES ('reservations_api_hash', ?)
        ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)
        SQL);
    $statement->execute([$apiHash]);
}

/** SQL used to insert new reservations and update existing reservations. */
function reservationUpsertSql(): string
{
    return <<<'SQL'
        INSERT INTO reservations (
            source_id,
            booking_id,
            trip_type,
            customer_name,
            customer_email,
            customer_phone,
            adults,
            children,
            luggage_count,
            baby_seats,
            extra_items_json,
            vehicle_type,
            driver_id,
            flight_number,
            airline,
            flight_from_airport,
            flight_from_datetime,
            flight_to_airport,
            flight_to_datetime,
            flight_details_text,
            current_price,
            original_price,
            status,
            payment_status,
            payment_method,
            customer_message,
            created_at
        ) VALUES (
            :source_id,
            :booking_id,
            :trip_type,
            :customer_name,
            :customer_email,
            :customer_phone,
            :adults,
            :children,
            :luggage_count,
            :baby_seats,
            :extra_items_json,
            :vehicle_type,
            :driver_id,
            :flight_number,
            :airline,
            :flight_from_airport,
            :flight_from_datetime,
            :flight_to_airport,
            :flight_to_datetime,
            :flight_details_text,
            :current_price,
            :original_price,
            :status,
            :payment_status,
            :payment_method,
            :customer_message,
            :created_at
        )
        ON DUPLICATE KEY UPDATE
            source_id = VALUES(source_id),
            trip_type = VALUES(trip_type),
            customer_name = VALUES(customer_name),
            customer_email = VALUES(customer_email),
            customer_phone = VALUES(customer_phone),
            adults = VALUES(adults),
            children = VALUES(children),
            luggage_count = VALUES(luggage_count),
            baby_seats = VALUES(baby_seats),
            extra_items_json = VALUES(extra_items_json),
            vehicle_type = VALUES(vehicle_type),
            driver_id = VALUES(driver_id),
            flight_number = VALUES(flight_number),
            airline = VALUES(airline),
            flight_from_airport = VALUES(flight_from_airport),
            flight_from_datetime = VALUES(flight_from_datetime),
            flight_to_airport = VALUES(flight_to_airport),
            flight_to_datetime = VALUES(flight_to_datetime),
            flight_details_text = VALUES(flight_details_text),
            current_price = VALUES(current_price),
            original_price = VALUES(original_price),
            status = VALUES(status),
            payment_status = VALUES(payment_status),
            payment_method = VALUES(payment_method),
            customer_message = VALUES(customer_message),
            created_at = VALUES(created_at)
        SQL;
}


/** Build the quick date filter cards displayed above the dashboard table. */
function buildQuickDateFilters(?DateTimeImmutable $today = null): array
{
    $today ??= new DateTimeImmutable('today');
    $tomorrow = $today->modify('+1 day');
    $thisWeekStart = $today->modify('monday this week');
    $thisWeekEnd = $thisWeekStart->modify('+6 days');
    $nextWeekStart = $thisWeekStart->modify('+7 days');
    $nextWeekEnd = $nextWeekStart->modify('+6 days');

    return [
        [
            'key' => '',
            'label' => 'all',
            'short' => 'all',
            'from' => '',
            'to' => '',
            'meta' => 'dates',
        ],
        [
            'key' => 'today',
            'label' => 'today',
            'short' => 'today',
            'from' => $today->format('Y-m-d'),
            'to' => $today->format('Y-m-d'),
            'meta' => $today->format('d M'),
        ],
        [
            'key' => 'tomorrow',
            'label' => 'tomorrow',
            'short' => 'tomorrow',
            'from' => $tomorrow->format('Y-m-d'),
            'to' => $tomorrow->format('Y-m-d'),
            'meta' => $tomorrow->format('d M'),
        ],
        [
            'key' => 'this_week',
            'label' => 'this week',
            'short' => 'this week',
            'from' => $thisWeekStart->format('Y-m-d'),
            'to' => $thisWeekEnd->format('Y-m-d'),
            'meta' => $thisWeekStart->format('d M') . ' - ' . $thisWeekEnd->format('d M'),
        ],
        [
            'key' => 'next_week',
            'label' => 'next week',
            'short' => 'next week',
            'from' => $nextWeekStart->format('Y-m-d'),
            'to' => $nextWeekEnd->format('Y-m-d'),
            'meta' => $nextWeekStart->format('d M') . ' - ' . $nextWeekEnd->format('d M'),
        ],
    ];
}

/** Find one quick date filter by its key. */
function findQuickDateFilter(array $quickDateFilters, string $selectedKey): ?array
{
    foreach ($quickDateFilters as $quickDateFilter) {
        if ((string)($quickDateFilter['key'] ?? '') === $selectedKey) {
            return $quickDateFilter;
        }
    }

    return null;
}

/** Return true when the active date filters match a selected quick date card. */
function quickDateFilterMatchesDashboardFilters(array $quickDateFilter, array $filters): bool
{
    return $filters['date_from'] === $quickDateFilter['from']
        && $filters['date_to'] === $quickDateFilter['to'];
}

/** Read and normalize dashboard filters. */
function getDashboardFilters(): array
{
    return [
        'q' => trim((string)($_GET['q'] ?? '')),
        'date_from' => trim((string)($_GET['date_from'] ?? '')),
        'date_to' => trim((string)($_GET['date_to'] ?? '')),
        'status' => trim((string)($_GET['status'] ?? '')),
        'payment' => trim((string)($_GET['payment'] ?? '')),
    ];
}

/** Return reservations that match the active filters. */
function findReservations(PDO $pdo, array $filters): array
{
    $sql = <<<'SQL'
        SELECT DISTINCT r.*
        FROM reservations r
        LEFT JOIN trips t ON t.reservation_id = r.id
        WHERE 1 = 1
        SQL;
    $params = [];

    if ($filters['q'] !== '') {
        $like = '%' . $filters['q'] . '%';
        $sql .= <<<'SQL'
             AND (
                r.booking_id LIKE ?
                OR r.source_id LIKE ?
                OR r.customer_name LIKE ?
                OR r.customer_email LIKE ?
                OR r.customer_phone LIKE ?
                OR r.vehicle_type LIKE ?
                OR r.driver_id LIKE ?
                OR r.flight_number LIKE ?
                OR r.flight_details_text LIKE ?
                OR r.status LIKE ?
                OR r.payment_status LIKE ?
                OR r.payment_method LIKE ?
                OR t.route_from LIKE ?
                OR t.route_to LIKE ?
            )
            SQL;
        $params = array_merge($params, array_fill(0, 14, $like));
    }

    if ($filters['date_from'] !== '') {
        $sql .= ' AND t.trip_date >= ?';
        $params[] = $filters['date_from'];
    }

    if ($filters['date_to'] !== '') {
        $sql .= ' AND t.trip_date <= ?';
        $params[] = $filters['date_to'];
    }

    if ($filters['status'] !== '') {
        $statusFilter = strtolower($filters['status']);

        if (in_array($statusFilter, ['confirmed', 'active'], true)) {
            $sql .= " AND LOWER(r.status) IN ('confirmed', 'active')";
        } elseif (in_array($statusFilter, ['cancelled', 'canceled'], true)) {
            $sql .= " AND LOWER(r.status) IN ('cancelled', 'canceled')";
        } else {
            $sql .= ' AND LOWER(r.status) = LOWER(?)';
            $params[] = $filters['status'];
        }
    }

    if ($filters['payment'] === 'open') {
        $sql .= " AND LOWER(r.payment_status) IN ('pending', 'partially paid')";
    } elseif ($filters['payment'] !== '') {
        $sql .= ' AND LOWER(r.payment_status) = LOWER(?)';
        $params[] = $filters['payment'];
    }

    $sql .= ' ORDER BY r.created_at DESC, r.id DESC';

    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}

/** Load all trip legs for the visible reservations in one query. */
function findTripsByReservationIds(PDO $pdo, array $reservationIds): array
{
    if (!$reservationIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($reservationIds), '?'));
    $statement = $pdo->prepare(
        "SELECT * FROM trips
         WHERE reservation_id IN ({$placeholders})
         ORDER BY reservation_id, id"
    );
    $statement->execute($reservationIds);

    $tripsByReservation = [];

    foreach ($statement->fetchAll() as $trip) {
        $tripsByReservation[(int)$trip['reservation_id']][] = $trip;
    }

    return $tripsByReservation;
}

/** Return the counts displayed in the dashboard summary cards. */
function getReservationSummary(PDO $pdo): array
{
    $statement = $pdo->query(<<<SQL
        SELECT
            COUNT(*) AS total_count,
            COALESCE(SUM(LOWER(status) IN ('active', 'confirmed')), 0) AS active_count,
            COALESCE(SUM(LOWER(status) IN ('cancelled', 'canceled')), 0) AS cancelled_count,
            COALESCE(SUM(LOWER(payment_status) IN ('pending', 'partially paid')), 0) AS open_payments
        FROM reservations
        SQL);

    return $statement->fetch() ?: [
        'total_count' => 0,
        'active_count' => 0,
        'cancelled_count' => 0,
        'open_payments' => 0,
    ];
}

/** Decode additional passenger and luggage fields saved in MySQL. */
function decodeExtraItems(?string $json): array
{
    if (!$json) {
        return [];
    }

    $items = json_decode($json, true);
    return is_array($items) ? $items : [];
}

/** Build compact passenger and luggage text shown in each row. */
function buildCompactReservationItems(array $reservation, array $extraItems): array
{
    $items = [];
    $adults = (int)$reservation['adults'];
    $children = (int)$reservation['children'];
    $luggage = (int)$reservation['luggage_count'];
    $babySeats = (int)$reservation['baby_seats'];

    if ($adults > 0) {
        $items[] = $adults . ' adult' . ($adults === 1 ? '' : 's');
    }

    if ($children > 0) {
        $items[] = $children . ' child' . ($children === 1 ? '' : 'ren');
    }

    if ($luggage > 0) {
        $items[] = $luggage . ' luggage';
    }

    if ($babySeats > 0) {
        $items[] = $babySeats . ' baby seat' . ($babySeats === 1 ? '' : 's');
    }

    foreach ($extraItems as $key => $value) {
        if (!hasVisibleValue($value)) {
            continue;
        }

        if ((string)$key === 'child_seats') {
            $items[] = extraValueText($value);
            continue;
        }

        $items[] = humanizeFieldName((string)$key) . ': ' . extraValueText($value);
    }

    return $items;
}

/** Parse one or more flights from the API flight_details string. */
function parseFlightDetails(?string $flightDetails): array
{
    $flightDetails = trim((string)$flightDetails);

    if ($flightDetails === '') {
        return [];
    }

    $flights = [];

    foreach (preg_split('/\s*\|\|\|\s*/', $flightDetails) ?: [] as $segment) {
        $segment = trim($segment);

        if ($segment === '') {
            continue;
        }

        $parts = preg_split('/\s*\|\s*From:\s*/', $segment, 2);
        $header = trim((string)($parts[0] ?? ''));
        $remaining = (string)($parts[1] ?? '');
        $routeParts = preg_split('/\s*\|\s*To:\s*/', $remaining, 2);
        $fromText = trim((string)($routeParts[0] ?? ''));
        $toText = trim((string)($routeParts[1] ?? ''));

        $code = $header;
        $airline = '';

        if (preg_match('/^(.*?)\s*\((.*?)\)\s*$/', $header, $match)) {
            $code = trim($match[1]);
            $airline = trim($match[2]);
        }

        [$fromAirport, $fromDateTime] = splitAirportAndDateTime($fromText);
        [$toAirport, $toDateTime] = splitAirportAndDateTime($toText);

        $flights[] = [
            'code' => $code,
            'airline' => $airline,
            'from_airport' => $fromAirport,
            'from_datetime' => $fromDateTime,
            'to_airport' => $toAirport,
            'to_datetime' => $toDateTime,
        ];
    }

    return $flights;
}

/** Separate the trailing date/time from an airport name. */
function splitAirportAndDateTime(string $value): array
{
    $value = trim($value);

    if (preg_match('/^(.*?)(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}(?::\d{2})?)$/', $value, $match)) {
        return [trim($match[1]), trim($match[2])];
    }

    return [$value, ''];
}


/** Convert dashboard status values into the exact values accepted by the API. */
function normalizeApiStatusValue(string $status): string
{
    $statusKey = strtolower(trim($status));

    return match ($statusKey) {
        'active', 'confirmed' => 'confirmed',
        'cancelled', 'canceled' => 'cancelled',
        default => throw new InvalidArgumentException('Unsupported reservation status.'),
    };
}

/**
 * Send one status update to the protected WordPress REST API.
 *
 * The endpoint format is:
 * /wp-json/transfer-now/v1/reservations/{reservation_id}/status
 *
 * The API key is kept on the PHP server and is never exposed to the browser.
 */
function postReservationStatusToApi(
    array $apiConfig,
    string $sourceId,
    string $status
): string {
    $sourceId = trim($sourceId);
    $apiStatus = normalizeApiStatusValue($status);

    if ($sourceId === '') {
        throw new RuntimeException('This reservation does not have an API reservation ID.');
    }

    return sendReservationApiRequest(
        $apiConfig,
        'POST',
        rawurlencode($sourceId) . '/status',
        ['status' => $apiStatus],
        'status API request'
    );
}

/** Save one status locally after the live API accepts the change. */
function updateLocalReservationStatus(PDO $pdo, string $bookingId, string $status): void
{
    $apiStatus = normalizeApiStatusValue($status);
    $displayStatus = displayLabel($apiStatus);

    $statement = $pdo->prepare(
        'UPDATE reservations
         SET status = ?
         WHERE booking_id = ?'
    );

    $statement->execute([
        $displayStatus,
        $bookingId,
    ]);

    clearDashboardCache();
}

/** Load the API reservation ID used by the status endpoint. */
function findReservationApiSourceId(PDO $pdo, string $bookingId): string
{
    $statement = $pdo->prepare(
        'SELECT source_id FROM reservations WHERE booking_id = ? LIMIT 1'
    );
    $statement->execute([$bookingId]);

    return trim((string)($statement->fetchColumn() ?: ''));
}

/**
 * Delete one reservation from the protected WordPress REST API.
 *
 * The delete endpoint uses the public booking ID, for example:
 * /wp-json/transfer-now/v1/reservations/030726-0826-285
 *
 * The API key is kept on the PHP server and is never exposed to the browser.
 */
function deleteReservationFromApi(array $apiConfig, string $bookingId): string
{
    $bookingId = trim($bookingId);

    if ($bookingId === '') {
        throw new RuntimeException('Missing booking ID.');
    }

    return sendReservationApiRequest(
        $apiConfig,
        'DELETE',
        rawurlencode($bookingId),
        null,
        'delete API request'
    );
}

/** Remove one reservation from the local database after the live API deletes it. */
function deleteLocalReservation(PDO $pdo, string $bookingId): void
{
    $statement = $pdo->prepare('DELETE FROM reservations WHERE booking_id = ?');
    $statement->execute([trim($bookingId)]);
    clearDashboardCache();
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

