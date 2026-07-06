<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

$csrfToken = ensureCsrfToken();
$flash = consumeFlashMessage();
$flashMessage = $flash['message'];
$flashType = $flash['type'];

$config = appConfig();

try {
    $database = initializeDatabase($config['database']);
    $pdo = $database['pdo'];

    $savedApiHash = getSavedApiHash($pdo);
    $forceResync = (bool)$database['force_resync'];
    $syncRequested = dashboardSyncRequested();
    $automaticSyncRequested = dashboardAutomaticSyncRequested();
    $shouldCheckLiveApi = $syncRequested
        || $forceResync
        || $savedApiHash === false;

    if ($shouldCheckLiveApi) {
        $syncResult = syncReservationsFromApi(
            $pdo,
            (array)$config['api'],
            $forceResync
        );

        if (
            $syncRequested
            && !$automaticSyncRequested
            && $syncResult['message'] === ''
        ) {
            $syncResult['message'] = 'Live API checked. No new reservation changes found.';
        }
    } else {
        $syncResult = [
            'message' => '',
            'hash' => (string)$savedApiHash,
        ];
    }

    $filters = getDashboardFilters();
    $currentApiHash = $syncResult['hash'];
    $dashboardData = loadDashboardData(
        $pdo,
        $filters,
        $currentApiHash,
        dashboardCacheConfig($config)
    );
    $reservations = $dashboardData['reservations'];
    $tripsByReservation = $dashboardData['trips_by_reservation'];
    $summary = $dashboardData['summary'];

    $syncMessage = $syncResult['message'];
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
$quickDateFilters = buildQuickDateFilters();
$selectedQuickFilter = trim((string)($_GET['quick'] ?? ''));
$selectedQuickRange = findQuickDateFilter($quickDateFilters, $selectedQuickFilter);
$useQuickDateScopedTrips = $selectedQuickRange !== null
    && $selectedQuickFilter !== ''
    && quickDateFilterMatchesDashboardFilters($selectedQuickRange, $filters);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#f4f7fb">
    <title>Reservations</title>
    <link rel="stylesheet" href="style.css">
</head>
<body
    data-api-hash="<?= e($currentApiHash) ?>"
    data-watch-url="watch.php"
    data-watch-interval="<?= (int)$config['api']['poll_interval_milliseconds'] ?>"
    data-auto-refresh-interval="<?= (int)$config['api']['auto_refresh_milliseconds'] ?>"
    data-auto-refresh-url="<?= e(dashboardUrl(['sync' => '1', 'auto_refresh' => '1'])) ?>"
    data-open-storage-key="openReservationCards"
>
<main class="page-shell">
    <header class="topbar">
        <div>
            <h1>Reservations</h1>
        </div>

        <div class="topbar-actions">
             <a
                class="button button-primary refresh-icon-button"
                href="<?= e(dashboardUrl(['sync' => '1'])) ?>"
                data-manual-refresh
                aria-label="Refresh live data"
                title="Refresh live data"
            >
                <span class="refresh-icon" aria-hidden="true">↻</span>
            </a>
        </div>
    </header>

    <div id="dashboard-content" class="dashboard-content" data-dashboard-content>
    <section class="quick-date-panel" aria-label="Quick date filters">
        <?php foreach ($quickDateFilters as $quickFilter): ?>
            <?php
            $quickFilterKey = (string)($quickFilter['key'] ?? '');
            $isActiveQuickFilter =
                ($quickFilterKey === '' && $selectedQuickFilter === '' && $filters['date_from'] === '' && $filters['date_to'] === '')
                || (
                    $quickFilterKey !== ''
                    && $selectedQuickFilter === $quickFilterKey
                    && $filters['date_from'] === $quickFilter['from']
                    && $filters['date_to'] === $quickFilter['to']
                );

            $quickFilterUrl = dashboardUrl([
                'quick' => $quickFilterKey === '' ? null : $quickFilterKey,
                'date_from' => $quickFilter['from'] === '' ? null : $quickFilter['from'],
                'date_to' => $quickFilter['to'] === '' ? null : $quickFilter['to'],
            ]);
            ?>
            <a
                class="quick-date-card<?= $isActiveQuickFilter ? ' active' : '' ?>"
                href="<?= e($quickFilterUrl) ?>"
            >
                <strong class="quick-date-value"><?= e($quickFilter['label']) ?></strong>
                <span class="quick-date-meta"><?= e($quickFilter['meta']) ?></span>
            </a>
        <?php endforeach; ?>
    </section>

    <?php if ($syncMessage !== ''): ?>
        <div class="sync-banner" role="status"><?= e($syncMessage) ?></div>
    <?php endif; ?>

    <?php if ($flashMessage !== ''): ?>
        <div class="sync-banner <?= $flashType === 'error' ? 'sync-banner-error' : 'sync-banner-success' ?>" role="status">
            <?= e($flashMessage) ?>
        </div>
    <?php endif; ?>

    <section class="filter-card">
        <form class="filters" method="get" action="index.php">
            <?php if ($filters['status'] !== ''): ?>
                <input type="hidden" name="status" value="<?= e($filters['status']) ?>">
            <?php endif; ?>

            <?php if ($filters['payment'] !== ''): ?>
                <input type="hidden" name="payment" value="<?= e($filters['payment']) ?>">
            <?php endif; ?>

            <?php if ($selectedQuickFilter !== ''): ?>
                <input type="hidden" name="quick" value="<?= e($selectedQuickFilter) ?>">
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
            $displayTrips = $trips;

            if (
                $useQuickDateScopedTrips
                && $filters['date_from'] !== ''
                && $filters['date_to'] !== ''
            ) {
                $displayTrips = array_values(array_filter(
                    $trips,
                    static function (array $trip) use ($filters): bool {
                        $tripDate = trim((string)($trip['trip_date'] ?? ''));

                        return $tripDate !== ''
                            && $tripDate >= $filters['date_from']
                            && $tripDate <= $filters['date_to'];
                    }
                ));

                if (!$displayTrips) {
                    $displayTrips = $trips;
                }
            }

            $primaryTrip = $displayTrips[0] ?? [];
            $primaryTripLabel = trim((string)($primaryTrip['label'] ?? ''));
            $isPrimaryReturnTrip = strtolower($primaryTripLabel) === 'return';
            $primaryTripDate = trim((string)($primaryTrip['trip_date'] ?? ''));
            $primaryTripTime = trim((string)($primaryTrip['trip_time'] ?? ''));
            $primaryDateTimestamp = $primaryTripDate !== '' ? strtotime($primaryTripDate) : false;
            $hasReturnTrip = count($displayTrips) > 1;
            $returnTrip = null;

            foreach (array_slice($displayTrips, 1) as $tripItem) {
                if (strtolower(trim((string)($tripItem['label'] ?? ''))) === 'return') {
                    $returnTrip = $tripItem;
                    break;
                }
            }

            if ($returnTrip === null && $hasReturnTrip) {
                $returnTrip = $displayTrips[1] ?? null;
            }

            $returnTripDate = trim((string)($returnTrip['trip_date'] ?? ''));
            $returnTripTime = trim((string)($returnTrip['trip_time'] ?? ''));
            $returnDateTimestamp = $returnTripDate !== '' ? strtotime($returnTripDate) : false;
            $statusKey = preg_replace(
                '/[^a-z0-9]+/',
                '-',
                strtolower(trim((string)($reservation['status'] ?? '')))
            ) ?: 'unknown';
            ?>

            <details
    class="
        reservation-card
        status-<?= e($statusKey) ?>
        <?= $hasCustomerMessage ? ' has-customer-message' : '' ?>
    "
    data-booking-id="<?= e($reservation['booking_id']) ?>"
>
                <summary class="reservation-summary<?= $hasFlightDetails ? ' has-flight' : ' no-flight' ?>">
                    <div class="mobile-reservation-overview<?= $returnTrip ? ' has-mobile-return' : '' ?>">
                        <div class="mobile-primary-trip-row">
                            <div class="mobile-ride-time">
                                <strong><?= $primaryTripTime !== '' ? e(substr($primaryTripTime, 0, 5)) : '--:--' ?></strong>
                                <span>
                                    <?= $primaryDateTimestamp !== false
                                        ? e(strtoupper(date('d M', $primaryDateTimestamp)))
                                        : 'NO DATE' ?>
                                </span>
                            </div>

                            <div class="mobile-ride-main">
                                <div class="mobile-route-line">
                                    <?php if ($primaryTripLabel !== ''): ?>
                                        <span class="mobile-primary-trip-label<?= $isPrimaryReturnTrip ? ' return' : '' ?>">
                                            <?= e($primaryTripLabel) ?>
                                        </span>
                                    <?php endif; ?>

                                    <span class="mobile-route-text">
                                        <strong><?= e($primaryTrip['route_from'] ?? '-') ?></strong>
                                        <span>→ <?= e($primaryTrip['route_to'] ?? '-') ?></span>
                                    </span>

                                </div>

                                <div class="mobile-ride-meta">
                                    <span class="passenger-icon-summary">
                                        <?php if ((int)$reservation['adults'] > 0): ?>
                                            <span
                                                class="passenger-stat passenger-stat--adults"
                                                title="Adults"
                                                aria-label="<?= (int)$reservation['adults'] ?> adults"
                                            >
                                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                                    <circle cx="8" cy="7" r="3"></circle>
                                                    <circle cx="16" cy="7" r="3"></circle>
                                                    <path d="M3 20v-1.5a5 5 0 0 1 10 0V20"></path>
                                                    <path d="M11 20v-1.5a5 5 0 0 1 10 0V20"></path>
                                                </svg>
                                                <span><?= (int)$reservation['adults'] ?></span>
                                            </span>
                                        <?php endif; ?>

                                        <?php if ((int)$reservation['children'] > 0): ?>
                                            <span
                                                class="passenger-stat passenger-stat--child"
                                                title="Children"
                                                aria-label="<?= (int)$reservation['children'] ?> children"
                                            >
                                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                                    <path d="M4.6 10.2a7.6 7.6 0 0 1 14.8 0"></path>
                                                    <path d="M4.6 10.2v3.2a7.4 7.4 0 0 0 14.8 0v-3.2"></path>
                                                    <path d="M4.5 10.8c-1.35 0-2.2.9-2.2 2.1s.85 2.1 2.2 2.1"></path>
                                                    <path d="M19.5 10.8c1.35 0 2.2.9 2.2 2.1s-.85 2.1-2.2 2.1"></path>
                                                    <path d="M10.3 5.1c.2-1.75 1.8-2.85 3.35-2.25 1.45.55 1.9 2.35.95 3.45-.85 1-2.55 1.05-3.5.15"></path>
                                                    <circle cx="9.25" cy="11.6" r="0.72" style="fill: currentColor; stroke: none;"></circle>
                                                    <circle cx="14.75" cy="11.6" r="0.72" style="fill: currentColor; stroke: none;"></circle>
                                                    <path d="M9.5 15.1c1.45 1.25 3.55 1.25 5 0"></path>
                                                </svg>
                                                <span><?= (int)$reservation['children'] ?></span>
                                            </span>
                                        <?php endif; ?>

                                        <?php if ((int)$reservation['luggage_count'] > 0): ?>
                                            <span
                                                class="passenger-stat passenger-stat--luggage"
                                                title="Luggage"
                                                aria-label="<?= (int)$reservation['luggage_count'] ?> luggage items"
                                            >
                                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                                    <path d="M9 6V4.5A1.5 1.5 0 0 1 10.5 3h3A1.5 1.5 0 0 1 15 4.5V6"></path>
                                                    <rect x="6" y="6" width="12" height="14" rx="2"></rect>
                                                    <path d="M9 9v8M15 9v8M9 20v1M15 20v1"></path>
                                                </svg>
                                                <span><?= (int)$reservation['luggage_count'] ?></span>
                                            </span>
                                        <?php endif; ?>

                                        <?php if (
                                            (int)$reservation['adults'] === 0
                                            && (int)$reservation['children'] === 0
                                            && (int)$reservation['luggage_count'] === 0
                                        ): ?>
                                            <span class="passenger-stat">No pax details</span>
                                        <?php endif; ?>
                                    </span>

                                    <span class="mobile-meta-divider" aria-hidden="true">·</span>
                                    <span class="mobile-vehicle-name"><?= e($reservation['vehicle_type'] ?: 'No vehicle') ?></span>
                                    <span class="mobile-status-pill mobile-status-pill--<?= e($statusKey) ?>">
                                        <?= e(displayLabel($reservation['status'])) ?>
                                    </span>
                                </div>
                            </div>

                            <?php if ($hasCustomerMessage): ?>
                                <span
                                    class="mobile-message-icon"
                                    role="img"
                                    aria-label="Customer message available"
                                    title="Customer message available"
                                >
                                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                        <path d="M5.5 5.5h13a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2h-7.1l-4.7 3.2v-3.2H5.5a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2Z"/>
                                        <path d="M7.5 9h9M7.5 12.5h6"/>
                                    </svg>
                                </span>
                            <?php endif; ?>

                            
                        </div>

                        <?php if ($returnTrip): ?>
                            <div class="mobile-return-trip" aria-label="Return trip">
                                <div class="mobile-return-time">
                                    <strong><?= $returnTripTime !== '' ? e(substr($returnTripTime, 0, 5)) : '--:--' ?></strong>
                                    <span>
                                        <?= $returnDateTimestamp !== false
                                            ? e(strtoupper(date('d M', $returnDateTimestamp)))
                                            : 'NO DATE' ?>
                                    </span>
                                </div>

                                <div class="mobile-return-main">
                                    <span class="mobile-return-label">
                                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                            <path d="M9 7 4 12l5 5"></path>
                                            <path d="M4 12h10a6 6 0 0 1 6 6"></path>
                                        </svg>
                                        Return
                                    </span>
                                    <span class="mobile-return-route">
                                        <strong><?= e($returnTrip['route_from'] ?? '-') ?></strong>
                                        <span>→ <?= e($returnTrip['route_to'] ?? '-') ?></span>
                                    </span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="cell booking-cell" data-label="Booking ID">
                        <div class="booking-reference">
                            <div class="booking-id-row">
                                <span class="booking-id"><?= e($reservation['booking_id']) ?></span>

                            </div>

                            <?php if ($hasCreatedAt): ?>
                                <span class="booking-created">Created at <?= e(showDateTime($reservation['created_at'])) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="cell trip-cell" data-label="Trip">
                        <div class="trip-stack">
                            <?php foreach ($displayTrips as $trip): ?>
                                <?php $isReturn = strtolower((string)$trip['label']) === 'return'; ?>
                                <span class="trip-tag<?= $isReturn ? ' return' : '' ?>"><?= e($trip['label']) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="cell route-cell span-2-mobile" data-label="Route">
                        <div class="route-stack">
                            <?php foreach ($displayTrips as $trip): ?>
                                <div class="route-item">
                                    <strong><?= e($trip['route_from']) ?></strong>
                                    <span class="route-arrow">→ <?= e($trip['route_to']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="cell date-cell" data-label="Date">
                        <div class="date-stack">
                            <?php foreach ($displayTrips as $trip): ?>
                                <strong><?= e(date('d/m/Y', strtotime((string)$trip['trip_date']))) ?></strong>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="cell time-cell" data-label="Time">
                        <div class="time-stack">
                            <?php foreach ($displayTrips as $trip): ?>
                                <strong><?= $trip['trip_time'] ? e(substr((string)$trip['trip_time'], 0, 5)) : '-' ?></strong>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="cell customer-cell span-2-mobile" data-label="Customer">
                        <div class="customer-content<?= $hasCustomerMessage ? ' has-desktop-message' : '' ?>">
                            <div class="customer-name-line">
                                <strong><?= e($reservation['customer_name']) ?></strong>

                                <?php if ($hasCustomerMessage): ?>
                                    <span
                                        class="desktop-customer-message"
                                        tabindex="0"
                                        aria-label="Customer message available"
                                        aria-describedby="desktop-message-tooltip-<?= $reservationId ?>"
                                    >
                                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                            <path d="M5.5 5.5h13a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2h-7.1l-4.7 3.2v-3.2H5.5a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2Z"/>
                                            <path d="M7.5 9h9M7.5 12.5h6"/>
                                        </svg>

                                        <span
                                            id="desktop-message-tooltip-<?= $reservationId ?>"
                                            class="desktop-customer-message-tooltip"
                                            role="tooltip"
                                        ><?= e($customerMessage) ?></span>
                                    </span>
                                <?php endif; ?>
                            </div>

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
                            <span class="passenger-icon-summary">
                                <?php if ((int)$reservation['adults'] > 0): ?>
                                    <span
                                        class="passenger-stat passenger-stat--adults"
                                        title="Adults"
                                        aria-label="<?= (int)$reservation['adults'] ?> adults"
                                    >
                                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                            <circle cx="8" cy="7" r="3"></circle>
                                            <circle cx="16" cy="7" r="3"></circle>
                                            <path d="M3 20v-1.5a5 5 0 0 1 10 0V20"></path>
                                            <path d="M11 20v-1.5a5 5 0 0 1 10 0V20"></path>
                                        </svg>
                                        <span><?= (int)$reservation['adults'] ?></span>
                                    </span>
                                <?php endif; ?>

                                <?php if ((int)$reservation['children'] > 0): ?>
                                    <span
                                        class="passenger-stat passenger-stat--child"
                                        title="Children"
                                        aria-label="<?= (int)$reservation['children'] ?> children"
                                    >
                                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                                <path d="M4.6 10.2a7.6 7.6 0 0 1 14.8 0"></path>
                                                <path d="M4.6 10.2v3.2a7.4 7.4 0 0 0 14.8 0v-3.2"></path>
                                                <path d="M4.5 10.8c-1.35 0-2.2.9-2.2 2.1s.85 2.1 2.2 2.1"></path>
                                                <path d="M19.5 10.8c1.35 0 2.2.9 2.2 2.1s-.85 2.1-2.2 2.1"></path>
                                                <path d="M10.3 5.1c.2-1.75 1.8-2.85 3.35-2.25 1.45.55 1.9 2.35.95 3.45-.85 1-2.55 1.05-3.5.15"></path>
                                                <circle cx="9.25" cy="11.6" r="0.72" style="fill: currentColor; stroke: none;"></circle>
                                                <circle cx="14.75" cy="11.6" r="0.72" style="fill: currentColor; stroke: none;"></circle>
                                                <path d="M9.5 15.1c1.45 1.25 3.55 1.25 5 0"></path>
                                            </svg>
                                        <span><?= (int)$reservation['children'] ?></span>
                                    </span>
                                <?php endif; ?>

                                <?php if ((int)$reservation['luggage_count'] > 0): ?>
                                    <span
                                        class="passenger-stat passenger-stat--luggage"
                                        title="Luggage"
                                        aria-label="<?= (int)$reservation['luggage_count'] ?> luggage items"
                                    >
                                        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                            <path d="M9 6V4.5A1.5 1.5 0 0 1 10.5 3h3A1.5 1.5 0 0 1 15 4.5V6"></path>
                                            <rect x="6" y="6" width="12" height="14" rx="2"></rect>
                                            <path d="M9 9v8M15 9v8M9 20v1M15 20v1"></path>
                                        </svg>
                                        <span><?= (int)$reservation['luggage_count'] ?></span>
                                    </span>
                                <?php endif; ?>

                                <?php if (
                                    (int)$reservation['adults'] === 0
                                    && (int)$reservation['children'] === 0
                                    && (int)$reservation['luggage_count'] === 0
                                ): ?>
                                    <span>-</span>
                                <?php endif; ?>
                            </span>

                            <?php if ((int)$reservation['baby_seats'] > 0 || $extraItems): ?>
                                <span class="passenger-extra-items">
                                    <?php if ((int)$reservation['baby_seats'] > 0): ?>
                                        <span>
                                            <?= (int)$reservation['baby_seats'] ?> baby seat<?= (int)$reservation['baby_seats'] === 1 ? '' : 's' ?>
                                        </span>
                                    <?php endif; ?>

                                    <?php foreach ($extraItems as $key => $value): ?>
                                        <?php if (hasVisibleValue($value)): ?>
                                            <span>
                                                <?= e((string)$key === 'child_seats'
                                                    ? extraValueText($value)
                                                    : humanizeFieldName((string)$key) . ': ' . extraValueText($value)) ?>
                                            </span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="cell vehicle-cell" data-label="Vehicle">
                        <div>
                            <strong><?= e($reservation['vehicle_type'] ?: '-') ?></strong>
                            <?php if ($driverId !== ''): ?>
                                <span class="subtext">Driver #<?= e($driverId) ?></span>
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


                    <span class="expand-control" aria-hidden="true"></span>
                </summary>

                <form
                    id="delete-reservation-form-<?= $reservationId ?>"
                    class="row-delete-hidden-form"
                    method="post"
                    action="delete-reservation.php"
                >
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= e($csrfToken) ?>"
                    >

                    <input
                        type="hidden"
                        name="booking_id"
                        value="<?= e($reservation['booking_id']) ?>"
                    >
                </form>

                <div class="details-panel">
                    <?php
                    $canChangeStatus = trim((string)($reservation['source_id'] ?? '')) !== '';
                    $isCancelledStatus = in_array($statusKey, ['cancelled', 'canceled'], true);
                    $isActiveStatus = $statusKey === 'active';
                    ?>

                    <article class="detail-box booking-info-box">
                        <h2>Booking Information</h2>
                        <dl class="detail-list">
                            <div class="detail-row">
                                <dt>Booking ID</dt>
                                <dd><?= e($reservation['booking_id']) ?></dd>
                            </div>

                            <?php if ($hasCreatedAt): ?>
                                <div class="detail-row">
                                    <dt>Created at</dt>
                                    <dd><?= e(showDateTime($reservation['created_at'])) ?></dd>
                                </div>
                            <?php endif; ?>
                        </dl>
                    </article>


                    <article class="detail-box mobile-customer-info-box">
                        <h2>Customer Information</h2>

                        <dl class="detail-list">
                            <?php if (hasVisibleValue($reservation['customer_name'] ?? null)): ?>
                                <div class="detail-row">
                                    <dt>Name</dt>
                                    <dd><?= e($reservation['customer_name']) ?></dd>
                                </div>
                            <?php endif; ?>

                            <?php if (hasVisibleValue($reservation['customer_phone'] ?? null)): ?>
                                <div class="detail-row">
                                    <dt>Phone</dt>
                                    <dd>
                                        <a class="phone-link" href="tel:<?= e($reservation['customer_phone']) ?>">
                                            <?= e($reservation['customer_phone']) ?>
                                        </a>
                                    </dd>
                                </div>
                            <?php endif; ?>

                            <?php if (hasVisibleValue($reservation['customer_email'] ?? null)): ?>
                                <div class="detail-row">
                                    <dt>Email</dt>
                                    <dd>
                                        <a class="email-link" href="mailto:<?= e($reservation['customer_email']) ?>">
                                            <?= e(strtolower((string)$reservation['customer_email'])) ?>
                                        </a>
                                    </dd>
                                </div>
                            <?php endif; ?>
                        </dl>
                    </article>


                    <article class="detail-box mobile-route-details-box">
                        <h2>Route Details</h2>

                        <div class="mobile-route-details-list">
                            <?php foreach ($displayTrips as $trip): ?>
                                <?php
                                $detailTripLabel = trim((string)($trip['label'] ?? ''));
                                $detailTripDate = trim((string)($trip['trip_date'] ?? ''));
                                $detailTripTime = trim((string)($trip['trip_time'] ?? ''));
                                $detailTripTimestamp = $detailTripDate !== '' ? strtotime($detailTripDate) : false;
                                ?>
                                <section class="mobile-route-detail">
                                    <div class="mobile-route-detail-heading">
                                        <span class="mobile-route-detail-label">
                                            <?= e($detailTripLabel !== '' ? $detailTripLabel : 'Trip') ?>
                                        </span>

                                        <span class="mobile-route-detail-date-time">
                                            <?= $detailTripTimestamp !== false
                                                ? e(date('d/m/Y', $detailTripTimestamp))
                                                : 'No date' ?>
                                            ·
                                            <?= $detailTripTime !== '' ? e(substr($detailTripTime, 0, 5)) : 'No time' ?>
                                        </span>
                                    </div>

                                    <dl class="mobile-route-detail-list">
                                        <div class="mobile-route-detail-row">
                                            <dt>From</dt>
                                            <dd><?= e($trip['route_from'] ?? '-') ?></dd>
                                        </div>

                                        <div class="mobile-route-detail-row">
                                            <dt>To</dt>
                                            <dd><?= e($trip['route_to'] ?? '-') ?></dd>
                                        </div>
                                    </dl>
                                </section>
                            <?php endforeach; ?>
                        </div>
                    </article>

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
                            </h2>
                            <p><?= nl2br(e($customerMessage)) ?></p>
                        </article>
                    <?php endif; ?>


                    <div class="reservation-inline-actions" aria-label="Reservation actions">
                        <?php if ($canChangeStatus && $isActiveStatus): ?>
                            <form
                                class="status-action-form"
                                method="post"
                                action="update-status.php"
                            >
                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e($csrfToken) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="booking_id"
                                    value="<?= e($reservation['booking_id']) ?>"
                                >

                                <input type="hidden" name="status" value="confirmed">

                                <button
                                    class="compact-status-button compact-status-button--confirm"
                                    type="submit"
                                    data-confirm-action="confirm"
                                    data-confirm-title="Confirm reservation?"
                                    data-confirm-message="This will mark the reservation as confirmed."
                                    data-confirm-button="Confirmation"
                                    data-confirm-danger="false"
                                >
                                    Confirmation
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if ($canChangeStatus && !$isCancelledStatus): ?>
                            <form
                                class="status-action-form"
                                method="post"
                                action="update-status.php"
                            >
                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e($csrfToken) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="booking_id"
                                    value="<?= e($reservation['booking_id']) ?>"
                                >

                                <input type="hidden" name="status" value="cancelled">

                                <button
                                    class="compact-status-button compact-status-button--cancel"
                                    type="submit"
                                    data-confirm-action="cancel"
                                    data-confirm-title="Cancel reservation?"
                                    data-confirm-message="This will mark the reservation as cancelled."
                                    data-confirm-button="Cancel reservation"
                                    data-confirm-danger="true"
                                >
                                    Cancel reservation
                                </button>
                            </form>
                        <?php endif; ?>

                        <button
                            class="compact-status-button compact-status-button--delete"
                            type="submit"
                            form="delete-reservation-form-<?= $reservationId ?>"
                            data-confirm-action="delete"
                            data-confirm-title="Delete reservation?"
                            data-confirm-message="This will permanently delete this reservation from the live dataset. This action cannot be undone."
                            data-confirm-button="Delete"
                            data-confirm-danger="true"
                        >
                            <svg aria-hidden="true" viewBox="0 0 24 24" focusable="false">
                                <path d="M3 6h18"></path>
                                <path d="M8 6V4.5A1.5 1.5 0 0 1 9.5 3h5A1.5 1.5 0 0 1 16 4.5V6"></path>
                                <path d="M19 6l-1 14.2A2 2 0 0 1 16 22H8a2 2 0 0 1-2-1.8L5 6"></path>
                                <path d="M10 11v6"></path>
                                <path d="M14 11v6"></path>
                            </svg>
                            Delete
                        </button>
                    </div>

                </div>
            </details>
        <?php endforeach; ?>
    </section>
    </div>
</main>



<div
    class="confirm-modal"
    id="confirmModal"
    aria-hidden="true"
>
    <div class="confirm-modal-backdrop" data-confirm-close></div>

    <section
        class="confirm-modal-card"
        role="dialog"
        aria-modal="true"
        aria-labelledby="confirmModalTitle"
        aria-describedby="confirmModalMessage"
    >
        <div class="confirm-modal-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false">
                <path d="M12 9v4"></path>
                <path d="M12 17h.01"></path>
                <path d="M10.3 3.9 2.7 17.1A2 2 0 0 0 4.4 20h15.2a2 2 0 0 0 1.7-2.9L13.7 3.9a2 2 0 0 0-3.4 0Z"></path>
            </svg>
        </div>

        <div class="confirm-modal-content">
            <h2 id="confirmModalTitle">Are you sure?</h2>
            <p id="confirmModalMessage">Please confirm this action.</p>
        </div>

        <div class="confirm-modal-actions">
            <button class="confirm-modal-cancel" type="button" data-confirm-close>
                Keep reservation
            </button>
            <button class="confirm-modal-submit" type="button">
                Continue
            </button>
        </div>
    </section>
</div>


<script src="app.js" defer></script>
</body>
</html>
