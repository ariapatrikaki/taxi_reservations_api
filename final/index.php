<?php
declare(strict_types=1);

session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$flashMessage = (string)($_SESSION['flash_message'] ?? '');
$flashType = (string)($_SESSION['flash_type'] ?? 'info');
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

require_once __DIR__ . '/app.php';

$config = appConfig();

try {
    $database = initializeDatabase($config['database']);
    $pdo = $database['pdo'];

    $syncResult = syncReservationsFromApi(
        $pdo,
        (array)$config['api'],
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
    $currentApiHash = $syncResult['hash'];
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
    <title>Reservations</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* Passenger and luggage icons used on desktop and mobile. */
        .passenger-icon-summary {
            display: inline-flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 7px 10px;
            min-width: 0;
        }

        .passenger-stat {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            color: #475569;
            white-space: nowrap;
            font-weight: 750;
            font-variant-numeric: tabular-nums;
        }

        .passenger-stat svg {
            width: 16px;
            height: 16px;
            flex: 0 0 16px;
            fill: none;
            stroke: currentColor;
            stroke-width: 1.9;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .passenger-stat--adults svg,
        .passenger-stat--child svg {
            width: 18px;
            height: 18px;
            flex-basis: 18px;
        }

        .passenger-stat--child {
            color: #6d5bd0;
        }

        .passenger-stat--child svg {
            width: 19px;
            height: 19px;
            flex-basis: 19px;
            stroke-width: 1.75;
        }

        .passenger-stat--luggage {
            color: #0f7490;
        }

        .passenger-extra-items {
            display: grid;
            gap: 3px;
            width: 100%;
            margin-top: 5px;
            color: #64748b;
            font-size: 0.72rem;
            line-height: 1.3;
        }

        @media (max-width: 760px) {
            .mobile-ride-meta {
                row-gap: 4px;
            }

            .mobile-ride-meta .passenger-icon-summary {
                gap: 5px 7px;
            }

            .mobile-ride-meta .passenger-stat {
                gap: 3px;
                font-weight: 800;
            }

            .mobile-ride-meta .passenger-stat svg {
                width: 16px;
                height: 16px;
                flex-basis: 16px;
            }

            .mobile-ride-meta .passenger-stat--adults svg,
            .mobile-ride-meta .passenger-stat--child svg {
                width: 18px;
                height: 18px;
                flex-basis: 18px;
            }
        }


        @media (max-width: 760px) {
            .mobile-reservation-overview {
                display: block;
                min-height: 0;
                padding: 0;
            }

            .mobile-primary-trip-row {
                display: grid;
                grid-template-columns: 47px minmax(0, 1fr) 9px;
                align-items: center;
                gap: 8px;
                min-height: 68px;
                padding: 8px 9px 8px 42px;
            }

            .mobile-reservation-overview.has-mobile-return .mobile-primary-trip-row {
                padding-bottom: 7px;
            }

            .mobile-ride-meta {
                margin-top: 5px;
            }

            .mobile-meta-divider {
                color: #a1aabc;
            }

            .mobile-vehicle-name {
                max-width: 42%;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .mobile-return-trip {
                position: relative;
                display: grid;
                grid-template-columns: 47px minmax(0, 1fr);
                align-items: center;
                gap: 8px;
                min-width: 0;
                margin: -1px 9px 8px 42px;
                padding: 7px 9px;
                border: 1px solid #dbe4f2;
                border-left: 3px solid #6d5bd0;
                border-radius: 4px 4px 11px 11px;
                background: linear-gradient(145deg, #faf9ff, #f4f6ff);
                box-shadow: 0 3px 9px rgba(79, 70, 229, 0.06);
            }

            .mobile-return-time {
                display: grid;
                align-content: center;
                min-width: 0;
                padding-right: 7px;
                border-right: 1px solid #dde3f0;
                text-align: center;
            }

            .mobile-return-time strong {
                color: #4438a8;
                font-size: 0.82rem;
                line-height: 1.05;
            }

            .mobile-return-time span {
                margin-top: 4px;
                color: #7b7898;
                font-size: 0.51rem;
                font-weight: 850;
                letter-spacing: 0.035em;
                line-height: 1;
                white-space: nowrap;
            }

            .mobile-return-main {
                display: grid;
                gap: 3px;
                min-width: 0;
            }

            .mobile-return-label {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                width: fit-content;
                color: #6555c7;
                font-size: 0.56rem;
                font-weight: 900;
                letter-spacing: 0.055em;
                line-height: 1;
                text-transform: uppercase;
            }

            .mobile-return-label svg {
                width: 12px;
                height: 12px;
                fill: none;
                stroke: currentColor;
                stroke-width: 2;
                stroke-linecap: round;
                stroke-linejoin: round;
            }

            .mobile-return-route {
                display: flex;
                align-items: baseline;
                gap: 4px;
                min-width: 0;
                overflow: hidden;
                color: #4b5568;
                font-size: 0.69rem;
                line-height: 1.2;
                white-space: nowrap;
            }

            .mobile-return-route strong,
            .mobile-return-route span {
                min-width: 0;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .mobile-return-route strong {
                flex: 0 1 auto;
                color: #30364a;
            }

            .mobile-return-route span {
                flex: 1 1 auto;
                color: #697386;
            }
        }


        /* --------------------------------------------------------------
           Final mobile refinements
        -------------------------------------------------------------- */
        @media (max-width: 760px) {
            /* Keep a fixed right-hand slot for the customer message icon. */
            .mobile-primary-trip-row {
                grid-template-columns: 47px minmax(0, 1fr) 30px;
            }

            /* Date is the primary value; time is secondary. */
            .mobile-ride-time,
            .mobile-return-time {
                display: flex !important;
                flex-direction: column;
                align-items: center;
                justify-content: center;
            }

            .mobile-ride-time span,
            .mobile-return-time span {
                order: 1;
                margin: 0;
                color: #172033 !important;
                font-size: 0.79rem !important;
                font-weight: 900 !important;
                letter-spacing: 0.025em;
                line-height: 1.1;
                white-space: nowrap;
            }

            .mobile-ride-time strong,
            .mobile-return-time strong {
                order: 2;
                margin-top: 4px;
                color: #64748b !important;
                font-size: 0.63rem !important;
                font-weight: 750 !important;
                line-height: 1;
            }

            /* Status is shown with an almost-white card tint. */
            .mobile-status-dot,
            .mobile-message-dot {
                display: none !important;
            }

            .reservation-card.status-active,
            .reservation-card.status-confirmed {
                border-color: #dceee2;
                background: #f7fcf8;
            }

            .reservation-card.status-active .reservation-summary,
            .reservation-card.status-active .mobile-reservation-overview,
            .reservation-card.status-active .mobile-primary-trip-row,
            .reservation-card.status-confirmed .reservation-summary,
            .reservation-card.status-confirmed .mobile-reservation-overview,
            .reservation-card.status-confirmed .mobile-primary-trip-row {
                background: #f7fcf8 !important;
            }

            .reservation-card.status-active .mobile-return-trip,
            .reservation-card.status-confirmed .mobile-return-trip {
                border-color: #dceee2;
                border-left-color: #78b88a;
                background: #f6fbf7 !important;
            }

            .reservation-card.status-cancelled,
            .reservation-card.status-canceled {
                border-color: #f2dddd;
                background: #fffafa;
            }

            .reservation-card.status-cancelled .reservation-summary,
            .reservation-card.status-cancelled .mobile-reservation-overview,
            .reservation-card.status-cancelled .mobile-primary-trip-row,
            .reservation-card.status-canceled .reservation-summary,
            .reservation-card.status-canceled .mobile-reservation-overview,
            .reservation-card.status-canceled .mobile-primary-trip-row {
                background: #fffafa !important;
            }

            .reservation-card.status-cancelled .mobile-return-trip,
            .reservation-card.status-canceled .mobile-return-trip {
                border-color: #f2dddd;
                border-left-color: #dca0a0;
                background: #fff6f6 !important;
            }

            /* Static customer-message icon in a consistent position. */
            .mobile-message-icon {
                display: grid;
                place-items: center;
                justify-self: center;
                align-self: center;
                width: 27px;
                height: 27px;
                border: 1px solid #fed7aa;
                border-radius: 50%;
                background: #fff7ed;
                color: #ea580c;
            }

            .mobile-message-icon svg {
                width: 15px;
                height: 15px;
                fill: none;
                stroke: currentColor;
                stroke-width: 1.9;
                stroke-linecap: round;
                stroke-linejoin: round;
            }

            /* Make the vehicle easier to scan. */
            .mobile-vehicle-name {
                color: #172033 !important;
                font-weight: 900 !important;
            }

            /* Booking information in the expanded details. */
            .booking-info-box .detail-row dd {
                color: #172033;
                font-weight: 750;
            }

            .booking-info-box .detail-row:first-child dd {
                color: #3154a5;
                font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            }
        }


        /* The expanded route card is mobile-only. */
        .mobile-route-details-box {
            display: none;
        }

        @media (max-width: 760px) {
            /* A more compact filter panel without changing its layout. */
            .filter-card {
                margin-bottom: 7px !important;
                padding: 7px 8px !important;
                border-radius: 11px !important;
            }

            .filters {
                gap: 5px !important;
            }

            .field {
                gap: 3px !important;
            }

            .field label {
                font-size: 0.56rem !important;
                line-height: 1.05;
            }

            .field input {
                min-height: 34px !important;
                height: 34px;
                padding: 6px 9px !important;
                border-radius: 8px !important;
                font-size: 16px !important;
            }

            .filter-actions {
                gap: 5px !important;
            }

            .filter-actions .button {
                min-height: 34px !important;
                padding: 6px 9px !important;
                border-radius: 8px !important;
                font-size: 0.74rem !important;
            }

            /* Full route information shown after opening a reservation. */
            .mobile-route-details-box {
                display: block;
            }

            .mobile-route-details-list {
                display: grid;
                gap: 8px;
            }

            .mobile-route-detail {
                display: grid;
                gap: 7px;
                padding: 9px 10px;
                border: 1px solid #e1e7f0;
                border-radius: 10px;
                background: #fbfcfe;
            }

            .mobile-route-detail-heading {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 8px;
            }

            .mobile-route-detail-label {
                display: inline-flex;
                align-items: center;
                width: fit-content;
                padding: 3px 7px;
                border-radius: 999px;
                background: #eef2ff;
                color: #5145b8;
                font-size: 0.58rem;
                font-weight: 900;
                letter-spacing: 0.04em;
                line-height: 1;
                text-transform: uppercase;
            }

            .mobile-route-detail-date-time {
                color: #64748b;
                font-size: 0.67rem;
                font-weight: 750;
                white-space: nowrap;
            }

            .mobile-route-detail-list {
                display: grid;
                gap: 6px;
                margin: 0;
            }

            .mobile-route-detail-row {
                display: grid;
                grid-template-columns: 42px minmax(0, 1fr);
                align-items: start;
                gap: 8px;
            }

            .mobile-route-detail-row dt {
                color: #8a94a6;
                font-size: 0.59rem;
                font-weight: 850;
                letter-spacing: 0.035em;
                line-height: 1.35;
                text-transform: uppercase;
            }

            .mobile-route-detail-row dd {
                min-width: 0;
                margin: 0;
                color: #263247;
                font-size: 0.76rem;
                font-weight: 700;
                line-height: 1.38;
                overflow-wrap: anywhere;
                word-break: break-word;
            }
        }



        /* --------------------------------------------------------------
           Desktop customer-message notification and delayed tooltip
        -------------------------------------------------------------- */
        .desktop-customer-message {
            display: none;
        }

        @media (min-width: 761px) {
            .customer-cell {
                position: relative;
                overflow: visible;
            }

            .customer-content {
                position: relative;
                display: flex;
                flex-direction: column;
                align-items: flex-start;
                min-width: 0;
                width: 100%;
            }

            .customer-name-line {
                display: flex;
                align-items: center;
                gap: 7px;
                min-width: 0;
                width: 100%;
            }

            .customer-name-line > strong {
                min-width: 0;
            }

            .desktop-customer-message {
                position: relative;
                z-index: 20;
                display: grid;
                place-items: center;
                flex: 0 0 25px;
                width: 25px;
                height: 25px;
                border: 1px solid #fed7aa;
                border-radius: 50%;
                background: #fff7ed;
                color: #ea580c;
                cursor: help;
                outline: none;
            }

            .desktop-customer-message svg {
                width: 14px;
                height: 14px;
                fill: none;
                stroke: currentColor;
                stroke-width: 1.9;
                stroke-linecap: round;
                stroke-linejoin: round;
            }

            .desktop-customer-message:focus-visible {
                box-shadow: 0 0 0 3px rgba(234, 88, 12, 0.16);
            }

            .desktop-customer-message-tooltip {
                position: absolute;
                top: auto;
                bottom: calc(100% + 8px);
                left: 50%;
                z-index: 50;
                width: max-content;
                min-width: 180px;
                max-width: min(330px, 36vw);
                padding: 10px 12px;
                border: 1px solid #fed7aa;
                border-radius: 10px;
                background: #fffdf9;
                color: #7c2d12;
                font-size: 0.76rem;
                font-weight: 650;
                line-height: 1.42;
                text-align: left;
                white-space: pre-wrap;
                overflow-wrap: anywhere;
                box-shadow: 0 10px 28px rgba(15, 23, 42, 0.16);
                opacity: 0;
                visibility: hidden;
                transform: translate(-50%, 4px);
                pointer-events: none;
                transition:
                    opacity 0.14s ease,
                    transform 0.14s ease,
                    visibility 0s linear 0.14s;
            }

            /*
             * The message appears only after the mouse remains over the icon
             * for about 0.6 seconds. It also works with keyboard focus.
             */
            .desktop-customer-message:hover .desktop-customer-message-tooltip,
            .desktop-customer-message:focus .desktop-customer-message-tooltip,
            .desktop-customer-message:focus-visible .desktop-customer-message-tooltip {
                opacity: 1;
                visibility: visible;
                transform: translate(-50%, 0);
                transition-delay: 0.6s, 0.6s, 0.6s;
            }

            /* Disable any animation inherited from older notification styles. */
            .desktop-customer-message,
            .desktop-customer-message::before,
            .desktop-customer-message::after {
                animation: none !important;
            }
        }


        /* Booking Information is required only in the expanded mobile view. */
        .booking-info-box {
            display: none;
        }

        @media (max-width: 760px) {
            .booking-info-box {
                display: block;
            }
        }

        @media (min-width: 761px) {
            .customer-content {
                position: relative;
                width: 100%;
                padding-right: 36px;
            }

            .customer-name-line {
                display: block;
                width: 100%;
            }

            .desktop-customer-message {
                position: absolute;
                top: 0;
                right: 0;
                left: auto;

                display: grid;
                place-items: center;

                width: 25px;
                height: 25px;
                margin: 0;
            }
        }

    </style>
</head>
<body
    data-api-hash="<?= e($currentApiHash) ?>"
    data-watch-url="watch.php"
    data-watch-interval="<?= (int)$config['api']['poll_interval_milliseconds'] ?>"
    data-open-storage-key="openReservationCards"
>
<main class="page-shell">
    <header class="topbar">
            <div>
                <h1>Reservations</h1>
            </div>
    </header>

    <section class="stats-grid" aria-label="Reservation summary and quick filters">
        <a
            class="stat-card<?= $filters['status'] === '' && $filters['payment'] === '' ? ' active' : '' ?>"
            href="<?= e(dashboardUrl(['status' => null, 'payment' => null])) ?>"
        >
            <span class="stat-label">Total</span>
            <strong class="stat-value"><?= (int)$summary['total_count'] ?></strong>
        </a>

        <a
            class="stat-card<?= in_array(strtolower($filters['status']), ['active', 'confirmed'], true) ? ' active' : '' ?>"
            href="<?= e(dashboardUrl(['status' => 'Confirmed', 'payment' => null])) ?>"
        >
            <span class="stat-label">Confirmed</span>
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
            $primaryTrip = $trips[0] ?? [];
            $primaryTripDate = trim((string)($primaryTrip['trip_date'] ?? ''));
            $primaryTripTime = trim((string)($primaryTrip['trip_time'] ?? ''));
            $primaryDateTimestamp = $primaryTripDate !== '' ? strtotime($primaryTripDate) : false;
            $hasReturnTrip = count($trips) > 1;
            $returnTrip = null;

            foreach ($trips as $tripItem) {
                if (strtolower(trim((string)($tripItem['label'] ?? ''))) === 'return') {
                    $returnTrip = $tripItem;
                    break;
                }
            }

            if ($returnTrip === null && $hasReturnTrip) {
                $returnTrip = $trips[1] ?? null;
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

                <div class="details-panel">
                    <article class="detail-box status-action-box">
                    <h2>Reservation Status</h2>

                    <p>
                        Current status:
                        <strong><?= e(displayLabel($reservation['status'])) ?></strong>
                    </p>

                    <?php if (trim((string)($reservation['source_id'] ?? '')) === ''): ?>
                        <p>Status changes require the numeric API reservation ID.</p>
                    <?php elseif (!in_array($statusKey, ['cancelled', 'canceled'], true)): ?>
                        <form
                            method="post"
                            action="update-status.php"
                            onsubmit="return confirm('Are you sure you want to cancel this reservation?');"
                        >
                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= e($_SESSION['csrf_token']) ?>"
                            >

                            <input
                                type="hidden"
                                name="booking_id"
                                value="<?= e($reservation['booking_id']) ?>"
                            >

                            <input type="hidden" name="status" value="cancelled">

                            <button class="cancel-reservation-button" type="submit">
                                Cancel reservation
                            </button>
                        </form>
                    <?php else: ?>
                        <form
                            method="post"
                            action="update-status.php"
                            onsubmit="return confirm('Mark this reservation as confirmed?');"
                        >
                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= e($_SESSION['csrf_token']) ?>"
                            >

                            <input
                                type="hidden"
                                name="booking_id"
                                value="<?= e($reservation['booking_id']) ?>"
                            >

                            <input type="hidden" name="status" value="confirmed">

                            <button class="reactivate-reservation-button" type="submit">
                                Mark as confirmed
                            </button>
                        </form>
                    <?php endif; ?>

                    <div class="danger-zone" aria-label="Danger zone">
                        <p class="danger-zone-title">Danger zone</p>
                        <form
                            method="post"
                            action="delete-reservation.php"
                            onsubmit="return confirm('This will permanently delete this reservation from the live dataset. Are you sure?');"
                        >
                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= e($_SESSION['csrf_token']) ?>"
                            >

                            <input
                                type="hidden"
                                name="booking_id"
                                value="<?= e($reservation['booking_id']) ?>"
                            >

                            <button class="delete-reservation-button" type="submit" title="Delete reservation">
                                <svg aria-hidden="true" viewBox="0 0 24 24">
                                    <path d="M3 6h18"></path>
                                    <path d="M8 6V4.5A1.5 1.5 0 0 1 9.5 3h5A1.5 1.5 0 0 1 16 4.5V6"></path>
                                    <path d="M19 6l-1 14.2A2 2 0 0 1 16 22H8a2 2 0 0 1-2-1.8L5 6"></path>
                                    <path d="M10 11v6"></path>
                                    <path d="M14 11v6"></path>
                                </svg>
                                Delete reservation
                            </button>
                        </form>
                    </div>
                </article>
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


                    <article class="detail-box mobile-route-details-box">
                        <h2>Route Details</h2>

                        <div class="mobile-route-details-list">
                            <?php foreach ($trips as $trip): ?>
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
                </div>
            </details>
        <?php endforeach; ?>
    </section>
</main>


<script src="app.js" defer></script>
</body>
</html>
