<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

$config = appConfig();

try {
    $database = initializeDatabase($config['database']);
    $pdo = $database['pdo'];

    $syncResult = syncReservationsFromJson(
        $pdo,
        (string)$config['json_file'],
        (bool)$database['force_resync']
    );

    $filters = getDashboardFilters();
    $reservations = findReservations($pdo, $filters);
    $reservationIds = array_map(
        static fn(array $reservation): int => (int)$reservation['id'],
        $reservations
    );
    $tripsByReservation = findTripsByReservationIds($pdo, $reservationIds);
    $summary = getReservationSummary($pdo);

    $syncMessage = $syncResult['message'];
    $currentJsonHash = $syncResult['hash'];
} catch (Throwable $error) {
    http_response_code(500);
    $errorMessage = $error->getMessage();
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <title>Dashboard Error</title>
        <link rel="stylesheet" href="style.css">
    </head>
    <body>
        <main class="error-page">
            <section class="error-card">
                <h1>The dashboard could not start</h1>
                <p><?= e($errorMessage) ?></p>
                <p>Make sure Apache and MySQL are running in XAMPP.</p>
            </section>
        </main>
    </body>
    </html>
    <?php
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#f4f7fb">
    <title>Reservations Dashboard</title>
    <link rel="stylesheet" href="style.css">
</head>
<body
    data-json-hash="<?= e($currentJsonHash) ?>"
    data-watch-url="watch.php"
    data-open-storage-key="openReservationCards"
>
<main class="page-shell">
    <header class="topbar">
        <div class="brand-wrap">
            <div class="brand-mark" aria-hidden="true">R</div>
            <div>
                <h1>Reservations Dashboard</h1>
                <p class="subtitle">Live taxi and transfer reservations</p>
            </div>
        </div>
    </header>

    <section class="stats-grid" aria-label="Reservation summary and quick filters">
        <a
            class="stat-card<?= $filters['status'] === '' && $filters['payment'] === '' ? ' active' : '' ?>"
            href="<?= e(dashboardUrl(['status' => null, 'payment' => null])) ?>"
        >
            <span class="stat-label">Total reservations</span>
            <strong class="stat-value"><?= (int)$summary['total_count'] ?></strong>
        </a>

        <a
            class="stat-card<?= strtolower($filters['status']) === 'active' ? ' active' : '' ?>"
            href="<?= e(dashboardUrl(['status' => 'Active', 'payment' => null])) ?>"
        >
            <span class="stat-label">Active</span>
            <strong class="stat-value"><?= (int)$summary['active_count'] ?></strong>
        </a>

        <a
            class="stat-card<?= strtolower($filters['status']) === 'cancelled' ? ' active' : '' ?>"
            href="<?= e(dashboardUrl(['status' => 'Cancelled', 'payment' => null])) ?>"
        >
            <span class="stat-label">Cancelled</span>
            <strong class="stat-value"><?= (int)$summary['cancelled_count'] ?></strong>
        </a>

        <a
            class="stat-card<?= $filters['payment'] === 'open' ? ' active' : '' ?>"
            href="<?= e(dashboardUrl(['status' => null, 'payment' => 'open'])) ?>"
        >
            <span class="stat-label">Open payments</span>
            <strong class="stat-value"><?= (int)$summary['open_payments'] ?></strong>
        </a>
    </section>

    <?php if ($syncMessage !== ''): ?>
        <div class="sync-banner" role="status"><?= e($syncMessage) ?></div>
    <?php endif; ?>

    <section class="filter-card">
        <form class="filters" method="get" action="index.php">
            <?php if ($filters['status'] !== ''): ?>
                <input type="hidden" name="status" value="<?= e($filters['status']) ?>">
            <?php endif; ?>

            <?php if ($filters['payment'] !== ''): ?>
                <input type="hidden" name="payment" value="<?= e($filters['payment']) ?>">
            <?php endif; ?>

            <div class="field search-field">
                <label for="q">Search</label>
                <input
                    id="q"
                    type="search"
                    name="q"
                    value="<?= e($filters['q']) ?>"
                    placeholder="Booking, customer, email, route, flight, driver..."
                >
            </div>

            <div class="field">
                <label for="date_from">From date</label>
                <input id="date_from" type="date" name="date_from" value="<?= e($filters['date_from']) ?>">
            </div>

            <div class="field">
                <label for="date_to">To date</label>
                <input id="date_to" type="date" name="date_to" value="<?= e($filters['date_to']) ?>">
            </div>

            <div class="filter-actions">
                <button class="button button-primary" type="submit">Apply</button>
                <a class="button" href="index.php">Reset</a>
            </div>
        </form>
    </section>

    <div class="result-line">
        <span><strong><?= count($reservations) ?></strong> reservations shown</span>
        <span class="live-indicator">
            <span class="live-dot" aria-hidden="true"></span>
            Watching reservations.json for valid changes
        </span>
    </div>

    <section class="table-shell" aria-label="Reservations table">
        <div class="table-head" aria-hidden="true">
            <div>Booking ID</div>
            <div>Trip</div>
            <div>Route</div>
            <div>Date</div>
            <div>Time</div>
            <div>Customer</div>
            <div>Pax / Luggage</div>
            <div>Vehicle</div>
            <div>Flight</div>
            <div>Price</div>
            <div>Status</div>
            <div>Payment</div>
        </div>

        <?php if (!$reservations): ?>
            <div class="empty-state">
                <strong>No reservations found</strong>
                Try another search or reset the filters.
            </div>
        <?php endif; ?>

        <?php foreach ($reservations as $reservation): ?>
            <?php
            $reservationId = (int)$reservation['id'];
            $trips = $tripsByReservation[$reservationId] ?? [];
            $statusClass = statusBadgeClass($reservation['status'] ?? null);
            $paymentClass = paymentBadgeClass($reservation['payment_status'] ?? null);
            $extraItems = decodeExtraItems($reservation['extra_items_json'] ?? null);
            $compactItems = buildCompactReservationItems($reservation, $extraItems);
            $flightDetails = parseFlightDetails($reservation['flight_details_text'] ?? null);
            $hasFlightDetails = !empty($flightDetails)
                || hasVisibleValue($reservation['flight_number'] ?? null);
            $customerMessage = trim((string)($reservation['customer_message'] ?? ''));
            $hasCustomerMessage = $customerMessage !== '';
            $hasCreatedAt = trim((string)($reservation['created_at'] ?? '')) !== '';
            $paymentMethod = displayPaymentMethod($reservation['payment_method'] ?? null);
            $driverId = trim((string)($reservation['driver_id'] ?? ''));
            ?>

            <details
                class="reservation-card<?= $hasCustomerMessage ? ' has-customer-message' : '' ?>"
                data-booking-id="<?= e($reservation['booking_id']) ?>"
            >
                <summary class="reservation-summary<?= $hasFlightDetails ? ' has-flight' : ' no-flight' ?>">
                    <div class="cell booking-cell" data-label="Booking ID">
                        <div class="booking-reference">
                            <div class="booking-id-row">
                                <span class="booking-id"><?= e($reservation['booking_id']) ?></span>

                                <?php if ($hasCustomerMessage): ?>
                                    <span
                                        class="message-notification"
                                        role="img"
                                        aria-label="Customer message available"
                                        title="Customer message available"
                                    >
                                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                            <path d="M5.5 5.5h13a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2h-7.1l-4.7 3.2v-3.2H5.5a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2Z"/>
                                            <path d="M7.5 9h9M7.5 12.5h6"/>
                                        </svg>
                                        <span class="message-notification-dot" aria-hidden="true"></span>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <?php if ($hasCreatedAt): ?>
                                <span class="booking-created">Created <?= e(showDateTime($reservation['created_at'])) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="cell trip-cell" data-label="Trip">
                        <div class="trip-stack">
                            <?php foreach ($trips as $trip): ?>
                                <?php $isReturn = strtolower((string)$trip['label']) === 'return'; ?>
                                <span class="trip-tag<?= $isReturn ? ' return' : '' ?>"><?= e($trip['label']) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="cell route-cell span-2-mobile" data-label="Route">
                        <div class="route-stack">
                            <?php foreach ($trips as $trip): ?>
                                <div class="route-item">
                                    <strong><?= e($trip['route_from']) ?></strong>
                                    <span class="route-arrow">→ <?= e($trip['route_to']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="cell date-cell" data-label="Date">
                        <div class="date-stack">
                            <?php foreach ($trips as $trip): ?>
                                <strong><?= e(date('d/m/Y', strtotime((string)$trip['trip_date']))) ?></strong>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="cell time-cell" data-label="Time">
                        <div class="time-stack">
                            <?php foreach ($trips as $trip): ?>
                                <strong><?= $trip['trip_time'] ? e(substr((string)$trip['trip_time'], 0, 5)) : '-' ?></strong>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="cell customer-cell span-2-mobile" data-label="Customer">
                        <div>
                            <strong><?= e($reservation['customer_name']) ?></strong>
                            <?php if ($reservation['customer_phone']): ?>
                                <a class="subtext phone-link" href="tel:<?= e($reservation['customer_phone']) ?>">
                                    <?= e($reservation['customer_phone']) ?>
                                </a>
                            <?php endif; ?>
                            <?php if ($reservation['customer_email']): ?>
                                <a class="subtext email-link" href="mailto:<?= e($reservation['customer_email']) ?>">
                                    <?= e(strtolower((string)$reservation['customer_email'])) ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="cell passengers-cell" data-label="Pax / Luggage">
                        <div class="pax-line">
                            <?php if ($compactItems): ?>
                                <?php foreach ($compactItems as $item): ?>
                                    <span><?= e($item) ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span>-</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="cell vehicle-cell" data-label="Vehicle">
                        <div>
                            <strong><?= e($reservation['vehicle_type'] ?: '-') ?></strong>
                            <?php if ($driverId !== ''): ?>
                                <span class="subtext">Driver #<?= e($driverId) ?></span>
                            <?php else: ?>
                                <span class="subtext">Unassigned</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="cell flight-cell" data-label="Flight">
                        <strong><?= e($reservation['flight_number'] ?: '-') ?></strong>
                    </div>

                    <div class="cell price-cell" data-label="Price">
                        <div>
                            <span class="money">€<?= number_format((float)$reservation['current_price'], 2) ?></span>
                            <?php if (
                                (float)$reservation['original_price'] > 0
                                && (float)$reservation['original_price'] !== (float)$reservation['current_price']
                            ): ?>
                                <span class="subtext old-price">€<?= number_format((float)$reservation['original_price'], 2) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="cell status-cell" data-label="Status">
                        <span class="<?= e($statusClass) ?>"><?= e(displayLabel($reservation['status'])) ?></span>
                    </div>

                    <div class="cell payment-cell" data-label="Payment">
                        <div>
                            <span class="<?= e($paymentClass) ?>"><?= e(displayLabel($reservation['payment_status'])) ?></span>
                            <?php if ($paymentMethod !== ''): ?>
                                <span class="subtext"><?= e($paymentMethod) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </summary>

                <div class="details-panel">
                    <?php if ($hasFlightDetails): ?>
                        <article class="detail-box flight-detail-box">
                            <h2>Flight Details</h2>

                            <?php if ($flightDetails): ?>
                                <div class="flight-detail-cards">
                                    <?php foreach ($flightDetails as $flight): ?>
                                        <section class="flight-detail-card">
                                            <strong class="flight-detail-title">
                                                <?= e($flight['code'] ?: $reservation['flight_number']) ?>
                                                <?php if ($flight['airline']): ?>
                                                    <span><?= e($flight['airline']) ?></span>
                                                <?php endif; ?>
                                            </strong>

                                            <?php if ($flight['from_airport'] || $flight['from_datetime']): ?>
                                                <div class="flight-route-line">
                                                    <span>From</span>
                                                    <div>
                                                        <?= e($flight['from_airport'] ?: '-') ?>
                                                        <?php if ($flight['from_datetime']): ?>
                                                            <small><?= e(showDateTime($flight['from_datetime'])) ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <?php if ($flight['to_airport'] || $flight['to_datetime']): ?>
                                                <div class="flight-route-line">
                                                    <span>To</span>
                                                    <div>
                                                        <?= e($flight['to_airport'] ?: '-') ?>
                                                        <?php if ($flight['to_datetime']): ?>
                                                            <small><?= e(showDateTime($flight['to_datetime'])) ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </section>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p><?= e($reservation['flight_number']) ?></p>
                            <?php endif; ?>
                        </article>
                    <?php endif; ?>

                    <article class="detail-box">
                        <h2>Passengers &amp; Luggage</h2>

                        <?php if ($compactItems): ?>
                            <dl class="detail-list">
                                <?php if ((int)$reservation['adults'] > 0): ?>
                                    <div class="detail-row"><dt>Adults</dt><dd><?= (int)$reservation['adults'] ?></dd></div>
                                <?php endif; ?>

                                <?php if ((int)$reservation['children'] > 0): ?>
                                    <div class="detail-row"><dt>Children</dt><dd><?= (int)$reservation['children'] ?></dd></div>
                                <?php endif; ?>

                                <?php if ((int)$reservation['luggage_count'] > 0): ?>
                                    <div class="detail-row"><dt>Luggage</dt><dd><?= (int)$reservation['luggage_count'] ?></dd></div>
                                <?php endif; ?>

                                <?php foreach ($extraItems as $key => $value): ?>
                                    <?php if (hasVisibleValue($value)): ?>
                                        <div class="detail-row">
                                            <dt><?= e(humanizeFieldName((string)$key)) ?></dt>
                                            <dd><?= e(extraValueText($value)) ?></dd>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </dl>
                        <?php else: ?>
                            <p>No passenger or luggage details are available.</p>
                        <?php endif; ?>
                    </article>

                    <?php if ($hasCustomerMessage): ?>
                        <article class="detail-box customer-message-box">
                            <h2>
                                <span class="customer-message-heading-icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" focusable="false">
                                        <path d="M5.5 5.5h13a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2h-7.1l-4.7 3.2v-3.2H5.5a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2Z"/>
                                        <path d="M7.5 9h9M7.5 12.5h6"/>
                                    </svg>
                                </span>
                                Customer Message
                                <span class="new-message-label">Attention</span>
                            </h2>
                            <p><?= nl2br(e($customerMessage)) ?></p>
                        </article>
                    <?php endif; ?>
                </div>
            </details>
        <?php endforeach; ?>
    </section>
</main>

<div id="json-watch-status" class="refresh-toast" role="status" aria-live="polite">
    Watching reservations.json for changes
</div>

<script src="app.js" defer></script>
</body>
</html>
