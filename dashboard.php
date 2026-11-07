
<?php
require_once __DIR__ . '/includes/auth.php';

require_login();
require_permission('dashboard', 'view');

$pageTitle = 'Dashboard';
$pdo = db();

/*
|--------------------------------------------------------------------------
| DATABASE HELPERS
|--------------------------------------------------------------------------
*/

function dashTableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);

    return (int)$stmt->fetchColumn() > 0;
}

function dashColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);

    return (int)$stmt->fetchColumn() > 0;
}

function dashFindColumn(PDO $pdo, string $table, array $columns): ?string
{
    foreach ($columns as $column) {
        if (dashColumnExists($pdo, $table, $column)) {
            return $column;
        }
    }

    return null;
}

/*
|--------------------------------------------------------------------------
| DASHBOARD ANNOUNCEMENTS
|--------------------------------------------------------------------------
*/

$dashboardAnnouncements = [];

if (
    dashTableExists($pdo, 'announcements') &&
    dashColumnExists($pdo, 'announcements', 'announcement_id') &&
    dashColumnExists($pdo, 'announcements', 'title') &&
    dashColumnExists($pdo, 'announcements', 'body') &&
    dashColumnExists($pdo, 'announcements', 'status') &&
    dashColumnExists($pdo, 'announcements', 'publish_at') &&
    dashColumnExists($pdo, 'announcements', 'expires_at')
) {
    try {
        $announcementSql = "
            SELECT
                announcement_id,
                title,
                body,
                audience,
                publish_at,
                expires_at,
                created_at
            FROM announcements
            WHERE status = 'Published'
              AND (publish_at IS NULL OR publish_at <= NOW())
              AND (expires_at IS NULL OR expires_at > NOW())
            ORDER BY COALESCE(publish_at, created_at) DESC, announcement_id DESC
            LIMIT 6
        ";

        $dashboardAnnouncements = $pdo->query($announcementSql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Dashboard announcements error: ' . $e->getMessage());
        $dashboardAnnouncements = [];
    }
}

/*
|--------------------------------------------------------------------------
| INITIAL DATA
|--------------------------------------------------------------------------
*/

$stats = [
    'rooms' => 0,
    'available' => 0,
    'occupied' => 0,
    'tenants' => 0,
    'pending' => 0,
    'unpaid' => 0,
    'payments' => 0,
    'total_collected' => 0,
    'total_billed' => 0
];

$roomLabels = [];
$roomValues = [];
$recent = [];

$collectionCharts = [
    'daily' => ['labels' => [], 'values' => []],
    'weekly' => ['labels' => [], 'values' => []],
    'monthly' => ['labels' => [], 'values' => []]
];

$dormitories = [];
$mapError = '';

/*
|--------------------------------------------------------------------------
| ROOM STATISTICS
|--------------------------------------------------------------------------
*/

if (dashTableExists($pdo, 'rooms')) {

    $roomRows = $pdo->query("
        SELECT status, COUNT(*) AS total
        FROM rooms
        GROUP BY status
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($roomRows as $row) {

        $status = $row['status'] ?: 'Unspecified';
        $total = (int)$row['total'];

        $roomLabels[] = $status;
        $roomValues[] = $total;

        $stats['rooms'] += $total;

        if (strcasecmp($status, 'Available') === 0) {
            $stats['available'] = $total;
        }

        if (strcasecmp($status, 'Occupied') === 0) {
            $stats['occupied'] = $total;
        }
    }
}

/*
|--------------------------------------------------------------------------
| TENANT STATISTICS
|--------------------------------------------------------------------------
*/

if (
    dashTableExists($pdo, 'tenants') &&
    dashColumnExists($pdo, 'tenants', 'status')
) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM tenants
        WHERE status = ?
    ");

    $stmt->execute(['Active']);

    $stats['tenants'] = (int)$stmt->fetchColumn();
}

/*
|--------------------------------------------------------------------------
| RESERVATION STATISTICS
|--------------------------------------------------------------------------
*/

if (
    dashTableExists($pdo, 'reservations') &&
    dashColumnExists($pdo, 'reservations', 'status')
) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM reservations
        WHERE status = ?
    ");

    $stmt->execute(['Pending']);

    $stats['pending'] = (int)$stmt->fetchColumn();
}

/*
|--------------------------------------------------------------------------
| BILLING TOTALS
|--------------------------------------------------------------------------
*/

if (dashTableExists($pdo, 'billing_tbl')) {

    $billingAmount = dashFindColumn($pdo, 'billing_tbl', [
        'amount_due',
        'total_amount',
        'billing_amount',
        'amount',
        'total_due'
    ]);

    if ($billingAmount !== null) {

        $stats['total_billed'] = (float)$pdo->query("
            SELECT COALESCE(SUM(`$billingAmount`), 0)
            FROM billing_tbl
        ")->fetchColumn();

        if (dashColumnExists($pdo, 'billing_tbl', 'status')) {

            $stats['unpaid'] = (float)$pdo->query("
                SELECT COALESCE(SUM(`$billingAmount`), 0)
                FROM billing_tbl
                WHERE status IN (
                    'Unpaid',
                    'Partially Paid',
                    'Overdue'
                )
            ")->fetchColumn();
        }
    }
}

/*
|--------------------------------------------------------------------------
| PAYMENT TOTALS AND COLLECTION CHARTS
|--------------------------------------------------------------------------
*/

if (
    dashTableExists($pdo, 'payments_tbl') &&
    dashColumnExists($pdo, 'payments_tbl', 'amount_paid')
) {

    $stats['payments'] = (int)$pdo->query("
        SELECT COUNT(*)
        FROM payments_tbl
    ")->fetchColumn();

    $stats['total_collected'] = (float)$pdo->query("
        SELECT COALESCE(SUM(amount_paid), 0)
        FROM payments_tbl
    ")->fetchColumn();

    if (dashColumnExists($pdo, 'payments_tbl', 'payment_date')) {

        // DAILY

        $dailyRows = $pdo->query("
            SELECT
                DATE(payment_date) AS period_key,
                SUM(amount_paid) AS total
            FROM payments_tbl
            WHERE payment_date >= DATE_SUB(
                CURRENT_DATE,
                INTERVAL 6 DAY
            )
            AND payment_date < DATE_ADD(
                CURRENT_DATE,
                INTERVAL 1 DAY
            )
            GROUP BY DATE(payment_date)
        ")->fetchAll(PDO::FETCH_ASSOC);

        $dailyMap = [];

        foreach ($dailyRows as $row) {
            $dailyMap[$row['period_key']] = (float)$row['total'];
        }

        for ($i = 6; $i >= 0; $i--) {

            $date = new DateTime('today');
            $date->modify("-$i days");

            $key = $date->format('Y-m-d');

            $collectionCharts['daily']['labels'][] = $date->format('M j');
            $collectionCharts['daily']['values'][] = $dailyMap[$key] ?? 0;
        }

        // WEEKLY

        $weeklyRows = $pdo->query("
            SELECT
                YEARWEEK(payment_date, 1) AS period_key,
                SUM(amount_paid) AS total
            FROM payments_tbl
            WHERE payment_date >= DATE_SUB(
                DATE_SUB(
                    CURRENT_DATE,
                    INTERVAL WEEKDAY(CURRENT_DATE) DAY
                ),
                INTERVAL 7 WEEK
            )
            AND payment_date < DATE_ADD(
                CURRENT_DATE,
                INTERVAL 1 DAY
            )
            GROUP BY YEARWEEK(payment_date, 1)
        ")->fetchAll(PDO::FETCH_ASSOC);

        $weeklyMap = [];

        foreach ($weeklyRows as $row) {
            $weeklyMap[(string)$row['period_key']] = (float)$row['total'];
        }

        for ($i = 7; $i >= 0; $i--) {

            $start = new DateTime('monday this week');
            $start->modify("-$i weeks");

            $key = $start->format('oW');

            $end = clone $start;
            $end->modify('+6 days');

            $collectionCharts['weekly']['labels'][] =
                $start->format('M j') . ' - ' . $end->format('M j');

            $collectionCharts['weekly']['values'][] = $weeklyMap[$key] ?? 0;
        }

        // MONTHLY

        $monthlyRows = $pdo->query("
            SELECT
                DATE_FORMAT(payment_date, '%Y-%m') AS period_key,
                SUM(amount_paid) AS total
            FROM payments_tbl
            WHERE payment_date >= DATE_SUB(
                DATE_FORMAT(CURRENT_DATE, '%Y-%m-01'),
                INTERVAL 5 MONTH
            )
            AND payment_date < DATE_ADD(
                DATE_FORMAT(CURRENT_DATE, '%Y-%m-01'),
                INTERVAL 1 MONTH
            )
            GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
        ")->fetchAll(PDO::FETCH_ASSOC);

        $monthlyMap = [];

        foreach ($monthlyRows as $row) {
            $monthlyMap[$row['period_key']] = (float)$row['total'];
        }

        for ($i = 5; $i >= 0; $i--) {

            $date = new DateTime('first day of this month');
            $date->modify("-$i months");

            $key = $date->format('Y-m');

            $collectionCharts['monthly']['labels'][] = $date->format('M Y');
            $collectionCharts['monthly']['values'][] = $monthlyMap[$key] ?? 0;
        }
    }
}

/*
|--------------------------------------------------------------------------
| RECENT TENANTS
|--------------------------------------------------------------------------
*/

if (
    dashTableExists($pdo, 'tenants') &&
    dashColumnExists($pdo, 'tenants', 'tenant_id') &&
    dashColumnExists($pdo, 'tenants', 'tenant_code') &&
    dashColumnExists($pdo, 'tenants', 'first_name') &&
    dashColumnExists($pdo, 'tenants', 'last_name') &&
    dashColumnExists($pdo, 'tenants', 'status')
) {

    $recent = $pdo->query("
        SELECT
            tenant_code,
            first_name,
            last_name,
            status
        FROM tenants
        ORDER BY tenant_id DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);
}

/*
|--------------------------------------------------------------------------
| VISAYAS DORMITORY DATA
|--------------------------------------------------------------------------
*/

$mapTablesExist =
    dashTableExists($pdo, 'dormitory_locations') &&
    dashTableExists($pdo, 'rooms');

$requiredMapColumns = [
    'location_id',
    'dorm_name',
    'region',
    'province',
    'city',
    'address',
    'latitude',
    'longitude',
    'status'
];

$mapColumnsValid = true;

if ($mapTablesExist) {

    foreach ($requiredMapColumns as $column) {

        if (!dashColumnExists($pdo, 'dormitory_locations', $column)) {
            $mapColumnsValid = false;
            break;
        }
    }

    if (
        !dashColumnExists($pdo, 'rooms', 'location_id') ||
        !dashColumnExists($pdo, 'rooms', 'room_id') ||
        !dashColumnExists($pdo, 'rooms', 'status')
    ) {
        $mapColumnsValid = false;
    }
}

if (!$mapTablesExist) {

    $mapError = 'The dormitory map tables have not been created yet.';

} elseif (!$mapColumnsValid) {

    $mapError = 'Some required map database columns are missing.';

} else {

    try {

        $phoneColumn = dashColumnExists(
            $pdo,
            'dormitory_locations',
            'contact_phone'
        ) ? 'd.contact_phone' : 'NULL';

        $imageColumn = dashColumnExists(
            $pdo,
            'dormitory_locations',
            'image_url'
        ) ? 'd.image_url' : 'NULL';

        $descriptionColumn = dashColumnExists(
            $pdo,
            'dormitory_locations',
            'description'
        ) ? 'd.description' : 'NULL';

        $dormitories = $pdo->query("
            SELECT
                d.location_id,
                d.dorm_name,
                d.region,
                d.province,
                d.city,
                d.address,
                d.latitude,
                d.longitude,
                $phoneColumn AS contact_phone,
                $imageColumn AS image_url,
                $descriptionColumn AS description,

                COUNT(r.room_id) AS total_rooms,

                SUM(
                    CASE
                        WHEN r.status = 'Available' THEN 1
                        ELSE 0
                    END
                ) AS available_rooms,

                SUM(
                    CASE
                        WHEN r.status = 'Occupied' THEN 1
                        ELSE 0
                    END
                ) AS occupied_rooms,

                SUM(
                    CASE
                        WHEN r.status = 'Maintenance' THEN 1
                        ELSE 0
                    END
                ) AS maintenance_rooms

            FROM dormitory_locations d

            INNER JOIN rooms r
                ON r.location_id = d.location_id

            WHERE d.status = 'Active'
              AND d.latitude BETWEEN 9.0 AND 13.5
              AND d.longitude BETWEEN 122.0 AND 127.5

            GROUP BY
                d.location_id,
                d.dorm_name,
                d.region,
                d.province,
                d.city,
                d.address,
                d.latitude,
                d.longitude

            HAVING available_rooms > 0

            ORDER BY
                d.region,
                d.province,
                d.city,
                d.dorm_name
        ")->fetchAll(PDO::FETCH_ASSOC);

        $roomStmt = $pdo->prepare("
            SELECT
                room_number,
                room_type,
                capacity,
                monthly_rate
            FROM rooms
            WHERE location_id = ?
              AND status = 'Available'
            ORDER BY room_number
        ");

        foreach ($dormitories as &$dorm) {

            $roomStmt->execute([
                (int)$dorm['location_id']
            ]);

            $dorm['rooms'] = $roomStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        unset($dorm);

    } catch (Throwable $e) {

        error_log($e->getMessage());

        $dormitories = [];

        $mapError = 'Unable to load dormitory records. Check the map database setup.';
    }
}

require __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet"
      href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">

<style>
.dashboard-page {
    width: 100%;
    min-width: 0;
}

.dashboard-page .welcome-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;
    padding: 25px;
    border-radius: 16px;
    margin-bottom: 22px;
}

.dashboard-page .stats-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 18px;
    margin-bottom: 22px;
}

.dashboard-page .stat-card,
.dashboard-page .panel,
.dashboard-page .dashboard-finance-card {
    min-width: 0;
    padding: 20px;
    border-radius: 14px;
}

.dashboard-page .stat-card h2,
.dashboard-page .dashboard-finance-card h2 {
    overflow-wrap: anywhere;
}

/* INTERACTIVE HOVER ANIMATIONS FOR DASHBOARD DATA */
.dashboard-page .stat-card,
.dashboard-page .panel,
.dashboard-page .dashboard-finance-card,
.dashboard-page .visayas-map-count {
    position: relative;
    transition:
        transform .28s cubic-bezier(.2, .75, .25, 1),
        box-shadow .28s ease,
        border-color .28s ease,
        background-color .28s ease;
    will-change: transform;
}

.dashboard-page .stat-card:hover,
.dashboard-page .panel:hover,
.dashboard-page .dashboard-finance-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 12px 28px rgba(20, 45, 75, .12);
    border-color: rgba(59, 130, 246, .30);
}

.dashboard-page .visayas-map-count:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(20, 45, 75, .08);
}

.dashboard-page table tbody tr {
    transition:
        background-color .22s ease,
        box-shadow .22s ease,
        transform .22s ease;
}

.dashboard-page table tbody tr:hover {
    background-color: rgba(59, 130, 246, .075);
    box-shadow: inset 3px 0 0 #3b82f6;
}

.dashboard-page table tbody tr td {
    transition: color .22s ease;
}

.dashboard-page table tbody tr:hover td {
    color: var(--text-color, inherit);
}

.dashboard-page .stat-card:focus-within,
.dashboard-page .panel:focus-within,
.dashboard-page .dashboard-finance-card:focus-within {
    box-shadow: 0 0 0 3px rgba(59, 130, 246, .16),
                0 10px 24px rgba(20, 45, 75, .10);
}

@media (hover: none) {
    .dashboard-page .stat-card:hover,
    .dashboard-page .panel:hover,
    .dashboard-page .dashboard-finance-card:hover,
    .dashboard-page .visayas-map-count:hover {
        transform: none;
        box-shadow: none;
    }
}

@media (prefers-reduced-motion: reduce) {
    .dashboard-page .stat-card,
    .dashboard-page .panel,
    .dashboard-page .dashboard-finance-card,
    .dashboard-page .visayas-map-count,
    .dashboard-page table tbody tr,
    .dashboard-page table tbody tr td {
        transition: none !important;
    }
}

.dashboard-page .dashboard-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 20px;
    margin-bottom: 22px;
}

.dashboard-page .chart-container {
    position: relative;
    width: 100%;
    height: 300px;
}

.dashboard-page .dashboard-finance-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 18px;
    margin-bottom: 22px;
}

.dashboard-page .dashboard-tab-panel {
    display: none;
}

.dashboard-page .dashboard-tab-panel.active {
    display: block;
}

.dashboard-page .collection-period-tabs {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin: 18px 0;
}

.dashboard-page .collection-period {
    padding: 9px 17px;
    border: 1px solid #dce4ed;
    border-radius: 8px;
    background: #fff;
    color: #244968;
    font-weight: bold;
    cursor: pointer;
}

.dashboard-page .collection-period.active {
    background: #1d3856;
    color: #fff;
}

.dashboard-page .dashboard-empty {
    padding: 18px;
    text-align: center;
    color: #778899;
    font-size: 13px;
}

.visayas-map-panel {
    margin-top: 22px;
}

.visayas-map-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 15px;
}

.visayas-map-toolbar p {
    margin: 5px 0 0;
    color: #778899;
    font-size: 13px;
}

.visayas-map-filters {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin: 15px 0;
}

.visayas-map-filters input,
.visayas-map-filters select {
    min-width: 160px;
    max-width: 100%;
    padding: 10px 12px;
    border: 1px solid #dce4ed;
    border-radius: 8px;
    background: #fff;
}

/* MAP VIEWING FIX */
#visayasDormMap {
    width: 100% !important;
    height: 520px !important;
    min-height: 520px;
    display: block;
    position: relative;
    border-radius: 12px;
    overflow: hidden;
    background: #e8eef2;
    z-index: 1;
}

#visayasDormMap .leaflet-container {
    width: 100% !important;
    height: 100% !important;
}

#visayasDormMap .leaflet-tile {
    max-width: none !important;
}

.visayas-map-count {
    display: flex;
    flex-wrap: wrap;
    gap: 18px;
    margin-top: 14px;
    padding: 13px;
    background: #f2f6fa;
    border-radius: 10px;
    font-size: 13px;
}

.visayas-map-count strong {
    color: #1d3856;
}

.dorm-map-popup {
    width: 245px;
    color: #263445;
}

.dorm-map-popup img {
    display: block;
    width: 100%;
    height: 135px;
    object-fit: cover;
    border-radius: 8px;
    margin-bottom: 9px;
}

.dorm-map-popup h3 {
    margin: 0 0 7px;
    font-size: 16px;
}

.dorm-map-popup p {
    margin: 5px 0;
    font-size: 12px;
}

.dorm-map-popup .popup-rooms {
    border-top: 1px solid #e3e8ee;
    margin-top: 10px;
    padding-top: 8px;
    max-height: 130px;
    overflow-y: auto;
}

.dorm-map-popup .popup-room {
    padding: 6px 0;
    border-bottom: 1px solid #edf0f3;
    font-size: 12px;
}

.dorm-map-popup .popup-contact {
    display: inline-block;
    margin-top: 8px;
    color: #175da8;
}

/* DASHBOARD ANNOUNCEMENTS */
.dashboard-announcements {
    margin: 0 0 22px;
}

.announcement-section-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 13px;
}

.announcement-section-title h3 {
    margin: 0;
    font-size: 18px;
    font-weight: 700;
}

.announcement-section-title > span {
    padding: 5px 11px;
    border-radius: 20px;
    background: rgba(34, 197, 94, .12);
    color: #16a34a;
    font-size: 12px;
    font-weight: 700;
}

.announcement-card {
    margin-bottom: 12px;
    padding: 18px;
    border: 1px solid rgba(128, 128, 128, .18);
    border-left: 4px solid #eab308;
    border-radius: 12px;
    background: var(--card-bg, #fff);
    transition: transform .2s ease, box-shadow .2s ease;
}

.announcement-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 18px rgba(0, 0, 0, .07);
}

.announcement-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
}

.announcement-top h4 {
    margin: 0;
    font-size: 16px;
    font-weight: 700;
    overflow-wrap: anywhere;
}

.announcement-audience {
    padding: 5px 10px;
    border-radius: 20px;
    background: rgba(59, 130, 246, .12);
    color: #2563eb;
    font-size: 11px;
    font-weight: 700;
}

.announcement-message {
    margin-top: 12px;
    font-size: 14px;
    line-height: 1.7;
    overflow-wrap: anywhere;
}

.announcement-dates {
    display: flex;
    flex-wrap: wrap;
    gap: 10px 18px;
    margin-top: 15px;
    padding-top: 12px;
    border-top: 1px solid rgba(128, 128, 128, .18);
    color: #8492a6;
    font-size: 12px;
}

.announcement-empty {
    padding: 24px;
    border: 1px dashed rgba(128, 128, 128, .35);
    border-radius: 12px;
    color: #8492a6;
    text-align: center;
}

.announcement-empty i {
    font-size: 27px;
}

.announcement-empty p {
    margin: 8px 0 0;
}

@media (max-width: 600px) {
    .announcement-dates {
        flex-direction: column;
        gap: 8px;
    }
}

@media (max-width: 1000px) {
    .dashboard-page .stats-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .dashboard-page .dashboard-finance-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 700px) {
    .dashboard-page .dashboard-grid,
    .dashboard-page .dashboard-finance-grid {
        grid-template-columns: 1fr;
    }

    .dashboard-page .welcome-card {
        align-items: flex-start;
        flex-direction: column;
    }

    #visayasDormMap {
        height: 430px !important;
        min-height: 430px;
    }
}

@media (max-width: 480px) {
    .dashboard-page .stats-grid {
        grid-template-columns: 1fr;
    }

    .visayas-map-filters input,
    .visayas-map-filters select {
        width: 100%;
    }
}
</style>

<div class="dashboard-page">

    <div class="welcome-card">
        <div>
            <h2>Welcome to Dormitory Management System</h2>
            <p>Here's an overview of your dormitory operations.</p>
        </div>

        <strong><?= date('F d, Y') ?></strong>
    </div>

    <!-- DASHBOARD ANNOUNCEMENTS -->
    <section class="dashboard-announcements" aria-labelledby="announcementHeading">
        <div class="announcement-section-title">
            <h3 id="announcementHeading">
                <i class="bi bi-megaphone-fill" aria-hidden="true"></i>
                Latest Announcements
            </h3>
            <span><?= count($dashboardAnnouncements) ?> Active</span>
        </div>

        <?php if (!empty($dashboardAnnouncements)): ?>
            <?php foreach ($dashboardAnnouncements as $announcement): ?>
                <article class="announcement-card">
                    <div class="announcement-top">
                        <h4><?= h($announcement['title'] ?? '') ?></h4>
                        <span class="announcement-audience">
                            <?= h($announcement['audience'] ?? 'All') ?>
                        </span>
                    </div>

                    <div class="announcement-message">
                        <?= nl2br(h($announcement['body'] ?? '')) ?>
                    </div>

                    <div class="announcement-dates">
                        <span>
                            <i class="bi bi-calendar-check" aria-hidden="true"></i>
                            Published: 
                            <?= !empty($announcement['publish_at'])
                                ? h(date('M d, Y h:i A', strtotime($announcement['publish_at'])))
                                : 'Immediately' ?>
                        </span>

                        <span>
                            <i class="bi bi-calendar-x" aria-hidden="true"></i>
                            Expires: 
                            <?= !empty($announcement['expires_at'])
                                ? h(date('M d, Y h:i A', strtotime($announcement['expires_at'])))
                                : 'No expiration date' ?>
                        </span>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="announcement-empty">
                <i class="bi bi-megaphone" aria-hidden="true"></i>
                <p>No active announcements at the moment.</p>
            </div>
        <?php endif; ?>
    </section>

    <!-- DASHBOARD TABS -->

    <div class="dashboard-tabs" role="tablist"
         aria-label="Dashboard sections">

        <button type="button"
                class="dashboard-tab active"
                data-tab="overview"
                role="tab"
                aria-selected="true">
            Overview
        </button>

        <button type="button"
                class="dashboard-tab"
                data-tab="finance"
                role="tab"
                aria-selected="false">
            Financial Summary
        </button>

        <button type="button"
                class="dashboard-tab"
                data-tab="charts"
                role="tab"
                aria-selected="false">
            Charts
        </button>

        <button type="button"
                class="dashboard-tab"
                data-tab="tenants"
                role="tab"
                aria-selected="false">
            Recent Tenants
        </button>
        

    </div>


    <!-- OVERVIEW -->

    <section class="dashboard-tab-panel active"
             id="tab-overview"
             role="tabpanel">

        <section class="stats-grid">

            <?php
            $cards = [
                ['Total Rooms', $stats['rooms'], '🏠'],
                ['Available Rooms', $stats['available'], '🟢'],
                ['Occupied Rooms', $stats['occupied'], '🔴'],
                ['Active Tenants', $stats['tenants'], '👥']
            ];

            foreach ($cards as $card):
            ?>

                <div class="stat-card">
                    <div><?= $card[2] ?></div>
                    <p><?= h($card[0]) ?></p>
                    <h2><?= number_format($card[1]) ?></h2>
                    <small>Database records</small>
                </div>

            <?php endforeach; ?>

        </section>

        <section class="stats-grid">

            <div class="stat-card">
                <p>Pending Reservations</p>
                <h2><?= number_format($stats['pending']) ?></h2>
            </div>

            <div class="stat-card">
                <p>Outstanding Billing</p>
                <h2>₱<?= number_format($stats['unpaid'], 2) ?></h2>
            </div>

            <div class="stat-card">
                <p>Total Payments</p>
                <h2><?= number_format($stats['payments']) ?></h2>
            </div>

            <div class="stat-card">
                <p>Total Amount Collected</p>
                <h2>₱<?= number_format($stats['total_collected'], 2) ?></h2>
            </div>

        </section>

        <!-- VISAYAS DORMITORY MAP -->

        <div class="panel visayas-map-panel">

            <div class="visayas-map-toolbar">

                <div>
                    <h3>
                        <i class="bi bi-geo-alt-fill"></i>
                        Visayas Dormitory Finder
                    </h3>

                    <p>
                        Find registered dormitories with available rooms
                        across the Visayas.
                    </p>
                </div>

                <strong>OpenStreetMap</strong>

            </div>

            <div class="visayas-map-filters">

                <input
                    type="search"
                    id="dormMapSearch"
                    placeholder="Search dorm, city, province..."
                    aria-label="Search dormitories"
                >

                <select
                    id="dormRegionFilter"
                    aria-label="Filter Visayas region"
                >
                    <option value="">All Visayas</option>
                    <option value="Western Visayas">Western Visayas</option>
                    <option value="Central Visayas">Central Visayas</option>
                    <option value="Eastern Visayas">Eastern Visayas</option>
                    <option value="Negros Island Region">Negros Island Region</option>
                </select>

                <select
                    id="dormProvinceFilter"
                    aria-label="Filter province"
                >
                    <option value="">All Provinces</option>
                </select>

            </div>

            <?php if ($mapError): ?>
                <div class="dashboard-empty">
                    <?= h($mapError) ?>
                </div>
            <?php endif; ?>

            <div id="visayasDormMap"></div>

            <div class="visayas-map-count">

                <span>
                    Visible dormitories:
                    <strong id="visibleDormCount">0</strong>
                </span>

                <span>
                    Available rooms:
                    <strong id="visibleRoomCount">0</strong>
                </span>

                <span>
                    Map coverage:
                    <strong>Visayas, Philippines</strong>
                </span>

            </div>

        </div>

    </section>

    <!-- FINANCIAL SUMMARY -->

    <section class="dashboard-tab-panel"
             id="tab-finance"
             role="tabpanel">

        <div class="dashboard-finance-grid">

            <div class="dashboard-finance-card">
                <p>Total Billed</p>
                <h2>₱<?= number_format($stats['total_billed'], 2) ?></h2>
            </div>

            <div class="dashboard-finance-card">
                <p>Total Collected</p>
                <h2>₱<?= number_format($stats['total_collected'], 2) ?></h2>
            </div>

            <div class="dashboard-finance-card">
                <p>Outstanding Billing</p>
                <h2>₱<?= number_format($stats['unpaid'], 2) ?></h2>
            </div>

        </div>

        <div class="panel">

            <h3>Financial Overview</h3>

            <div class="table-wrap">

                <table>

                    <thead>
                        <tr>
                            <th>Description</th>
                            <th>Amount</th>
                        </tr>
                    </thead>

                    <tbody>

                        <tr>
                            <td>Total Billed</td>
                            <td>₱<?= number_format($stats['total_billed'], 2) ?></td>
                        </tr>

                        <tr>
                            <td>Total Collected</td>
                            <td>₱<?= number_format($stats['total_collected'], 2) ?></td>
                        </tr>

                        <tr>
                            <td>Outstanding Billing</td>
                            <td>₱<?= number_format($stats['unpaid'], 2) ?></td>
                        </tr>

                    </tbody>

                </table>

            </div>

        </div>

    </section>

    <!-- CHARTS -->

    <section class="dashboard-tab-panel"
             id="tab-charts"
             role="tabpanel">

        <section class="dashboard-grid">

            <div class="panel">

                <h3>Room Availability</h3>
                <p>Distribution based on actual room records</p>

                <div class="chart-container">
                    <canvas id="roomChart"></canvas>
                </div>

            </div>

            <div class="panel">

                <h3>Collections Report</h3>
                <p>Actual payments received from your database</p>

                <div class="collection-period-tabs">

                    <button type="button"
                            class="collection-period active"
                            data-period="daily"
                            aria-pressed="true">
                        Daily
                    </button>

                    <button type="button"
                            class="collection-period"
                            data-period="weekly"
                            aria-pressed="false">
                        Weekly
                    </button>

                    <button type="button"
                            class="collection-period"
                            data-period="monthly"
                            aria-pressed="false">
                        Monthly
                    </button>

                </div>

                <div class="chart-container">
                    <canvas id="incomeChart"></canvas>
                </div>

            </div>

        </section>

    </section>

    <!-- RECENT TENANTS -->

    <section class="dashboard-tab-panel"
             id="tab-tenants"
             role="tabpanel">

        <section class="panel">

            <h3>Recent Tenants</h3>

            <div class="table-wrap">

                <table>

                    <thead>
                        <tr>
                            <th>Tenant Code</th>
                            <th>Name</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($recent as $tenant): ?>

                            <tr>
                                <td><?= h($tenant['tenant_code']) ?></td>

                                <td>
                                    <?= h(
                                        $tenant['first_name'] . ' ' .
                                        $tenant['last_name']
                                    ) ?>
                                </td>

                                <td><?= h($tenant['status']) ?></td>
                            </tr>

                        <?php endforeach; ?>

                        <?php if (!$recent): ?>
                            <tr>
                                <td colspan="3">No tenant records yet.</td>
                            </tr>
                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

    </section>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script>
/*
|--------------------------------------------------------------------------
| SAFE JSON DATA
|--------------------------------------------------------------------------
*/

const roomLabels = <?= json_encode(
    $roomLabels,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?>;

const roomValues = <?= json_encode($roomValues) ?>;

const collectionCharts = <?= json_encode(
    $collectionCharts,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?>;

const dormitories = <?= json_encode(
    $dormitories,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?>;

/*
|--------------------------------------------------------------------------
| DASHBOARD TABS
|--------------------------------------------------------------------------
*/

document.querySelectorAll('.dashboard-tab').forEach(function (tab) {

    tab.addEventListener('click', function () {

        const target = tab.dataset.tab;

        document.querySelectorAll('.dashboard-tab').forEach(function (item) {

            const selected = item === tab;

            item.classList.toggle('active', selected);

            item.setAttribute(
                'aria-selected',
                selected ? 'true' : 'false'
            );

        });

        document.querySelectorAll('.dashboard-tab-panel').forEach(function (panel) {

            panel.classList.toggle(
                'active',
                panel.id === 'tab-' + target
            );

        });

        setTimeout(function () {

            if (window.roomChartInstance) {
                window.roomChartInstance.resize();
            }

            if (window.incomeChartInstance) {
                window.incomeChartInstance.resize();
            }

            if (window.visayasDormMap) {
                window.visayasDormMap.invalidateSize(true);
            }

        }, 200);

    });

});

/*
|--------------------------------------------------------------------------
| ROOM CHART
|--------------------------------------------------------------------------
*/

const roomCanvas = document.getElementById('roomChart');

if (roomCanvas && typeof Chart !== 'undefined') {

    window.roomChartInstance = new Chart(roomCanvas, {

        type: 'doughnut',

        data: {
            labels: roomLabels,

            datasets: [{
                label: 'Rooms',
                data: roomValues,
                borderWidth: 2,
                hoverOffset: 8
            }]
        },

        options: {
            responsive: true,
            maintainAspectRatio: false,

            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }

    });

}

/*
|--------------------------------------------------------------------------
| COLLECTION CHART
|--------------------------------------------------------------------------
*/

const incomeCanvas = document.getElementById('incomeChart');

if (incomeCanvas && typeof Chart !== 'undefined') {

    window.incomeChartInstance = new Chart(incomeCanvas, {

        type: 'bar',

        data: {
            labels: collectionCharts.daily.labels,

            datasets: [{
                label: 'Daily Collections (₱)',
                data: collectionCharts.daily.values,
                borderWidth: 1,
                borderRadius: 6
            }]
        },

        options: {

            responsive: true,
            maintainAspectRatio: false,

            plugins: {

                legend: {
                    position: 'bottom'
                },

                tooltip: {

                    callbacks: {

                        label: function (context) {

                            return '₱' + Number(
                                context.parsed.y
                            ).toLocaleString('en-PH', {
                                minimumFractionDigits: 2,
                                maximumFractionDigits: 2
                            });

                        }

                    }

                }

            },

            scales: {

                y: {

                    beginAtZero: true,

                    ticks: {

                        callback: function (value) {
                            return '₱' + Number(value).toLocaleString('en-PH');
                        }

                    }

                }

            }

        }

    });

    document.querySelectorAll('.collection-period').forEach(function (button) {

        button.addEventListener('click', function () {

            const period = button.dataset.period;
            const chartData = collectionCharts[period];

            if (!chartData) return;

            document.querySelectorAll('.collection-period').forEach(function (item) {

                const selected = item === button;

                item.classList.toggle('active', selected);

                item.setAttribute(
                    'aria-pressed',
                    selected ? 'true' : 'false'
                );

            });

            window.incomeChartInstance.data.labels = chartData.labels;
            window.incomeChartInstance.data.datasets[0].data = chartData.values;

            window.incomeChartInstance.data.datasets[0].label =
                period.charAt(0).toUpperCase() +
                period.slice(1) +
                ' Collections (₱)';

            window.incomeChartInstance.update();

        });

    });

}

/*
|--------------------------------------------------------------------------
| VISAYAS MAP
|--------------------------------------------------------------------------
*/

(function () {

    const mapElement = document.getElementById('visayasDormMap');

    if (!mapElement) return;

    if (typeof L === 'undefined') {

        mapElement.innerHTML =
            '<p class="dashboard-empty">Leaflet could not load. Check your internet connection.</p>';

        return;
    }

    /*
    |--------------------------------------------------------------------------
    | MAP BOUNDS
    |--------------------------------------------------------------------------
    | The rectangular bounds limit panning to the Visayas area.
    |--------------------------------------------------------------------------
    */

    const visayasBounds = L.latLngBounds(
        [9.0, 121.5],
        [13.5, 127.5]
    );

    const visayasCenter = [11.2, 124.5];

    /*
    |--------------------------------------------------------------------------
    | CREATE MAP
    |--------------------------------------------------------------------------
    */

    const map = L.map('visayasDormMap', {

        center: visayasCenter,
        zoom: 7.5,

        minZoom: 6,
        maxZoom: 18,

        maxBounds: visayasBounds,
        maxBoundsViscosity: 1.0,

        scrollWheelZoom: true,
        worldCopyJump: false

    });

    window.visayasDormMap = map;

    /*
    |--------------------------------------------------------------------------
    | TILE LAYER
    |--------------------------------------------------------------------------
    | IMPORTANT: No bounds option here.
    | This allows the map tiles to fill the entire map container.
    |--------------------------------------------------------------------------
    */

    L.tileLayer(
        'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
        {
            minZoom: 6,
            maxZoom: 19,
            noWrap: true,

            attribution:
                '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap contributors</a>'
        }
    ).addTo(map);

    map.setMaxBounds(visayasBounds);

    /*
    |--------------------------------------------------------------------------
    | MAP MARKERS
    |--------------------------------------------------------------------------
    */

    const markerLayer = L.featureGroup().addTo(map);
    const markerRecords = [];

    const searchInput = document.getElementById('dormMapSearch');
    const regionFilter = document.getElementById('dormRegionFilter');
    const provinceFilter = document.getElementById('dormProvinceFilter');

    const visibleDormCount = document.getElementById('visibleDormCount');
    const visibleRoomCount = document.getElementById('visibleRoomCount');

    /*
    |--------------------------------------------------------------------------
    | SAFE HTML
    |--------------------------------------------------------------------------
    */

    function escapeHTML(value) {

        return String(value ?? '').replace(/[&<>"']/g, function (char) {

            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[char];

        });

    }

    /*
    |--------------------------------------------------------------------------
    | COORDINATE VALIDATION
    |--------------------------------------------------------------------------
    */

    function validCoordinates(dorm) {

        const lat = Number(dorm.latitude);
        const lng = Number(dorm.longitude);

        return Number.isFinite(lat) &&
            Number.isFinite(lng) &&
            visayasBounds.contains([lat, lng]);

    }

    /*
    |--------------------------------------------------------------------------
    | POPUP CONTENT
    |--------------------------------------------------------------------------
    */

    function popupContent(dorm) {

        const image = dorm.image_url
            ? `
                <img
                    src="${escapeHTML(dorm.image_url)}"
                    alt="${escapeHTML(dorm.dorm_name)}"
                    onerror="this.style.display='none'"
                >
            `
            : '';

        const rooms = Array.isArray(dorm.rooms) ? dorm.rooms : [];

        const roomHTML = rooms.length

            ? rooms.map(function (room) {

                return `
                    <div class="popup-room">

                        <strong>
                            Room ${escapeHTML(room.room_number)}
                        </strong>

                        <br>

                        ${escapeHTML(room.room_type)}
                        · Capacity:
                        ${escapeHTML(room.capacity)}

                        <br>

                        ₱${Number(room.monthly_rate).toLocaleString(
                            'en-PH',
                            {
                                minimumFractionDigits: 2,
                                maximumFractionDigits: 2
                            }
                        )} / month

                    </div>
                `;

            }).join('')

            : '<p>No available rooms.</p>';

        const phone = dorm.contact_phone

            ? `
                <a
                    class="popup-contact"
                    href="tel:${escapeHTML(dorm.contact_phone)}"
                >
                    <i class="bi bi-telephone"></i>
                    ${escapeHTML(dorm.contact_phone)}
                </a>
            `

            : '';

        return `

            <div class="dorm-map-popup">

                ${image}

                <h3>${escapeHTML(dorm.dorm_name)}</h3>

                <p>
                    <strong>
                        ${escapeHTML(dorm.city)},
                        ${escapeHTML(dorm.province)}
                    </strong>
                </p>

                <p>${escapeHTML(dorm.address)}</p>

                <p>${escapeHTML(dorm.description || '')}</p>

                <p>
                    <strong>
                        ${Number(dorm.available_rooms)}
                        Available Room(s)
                    </strong>
                </p>

                <p>
                    ${Number(dorm.occupied_rooms)} Occupied ·
                    ${Number(dorm.maintenance_rooms)} Maintenance
                </p>

                <div class="popup-rooms">
                    <strong>Available Rooms</strong>
                    ${roomHTML}
                </div>

                ${phone}

            </div>

        `;

    }

    /*
    |--------------------------------------------------------------------------
    | CREATE DORMITORY PINS
    |--------------------------------------------------------------------------
    */

    dormitories.forEach(function (dorm) {

        if (!validCoordinates(dorm)) return;

        const availableRooms = Number(dorm.available_rooms || 0);

        if (availableRooms <= 0) return;

        const marker = L.marker([
            Number(dorm.latitude),
            Number(dorm.longitude)
        ]);

        marker.bindPopup(popupContent(dorm), {
            maxWidth: 310
        });

        markerRecords.push({
            dorm: dorm,
            marker: marker
        });

    });

    /*
    |--------------------------------------------------------------------------
    | PROVINCE FILTER OPTIONS
    |--------------------------------------------------------------------------
    */

    const provinces = [...new Set(

        dormitories
            .filter(validCoordinates)
            .map(function (dorm) {
                return dorm.province;
            })
            .filter(Boolean)

    )].sort();

    provinces.forEach(function (province) {

        const option = document.createElement('option');

        option.value = province;
        option.textContent = province;

        provinceFilter.appendChild(option);

    });

    /*
    |--------------------------------------------------------------------------
    | APPLY FILTERS
    |--------------------------------------------------------------------------
    */

    function applyFilters() {

        const search = searchInput.value.trim().toLowerCase();

        const selectedRegion = regionFilter.value;
        const selectedProvince = provinceFilter.value;

        markerLayer.clearLayers();

        let dormCount = 0;
        let roomCount = 0;

        const visibleMarkers = [];

        markerRecords.forEach(function (record) {

            const dorm = record.dorm;

            const searchable = [
                dorm.dorm_name,
                dorm.region,
                dorm.province,
                dorm.city,
                dorm.address
            ].join(' ').toLowerCase();

            const matchesSearch =
                !search || searchable.includes(search);

            const matchesRegion =
                !selectedRegion || dorm.region === selectedRegion;

            const matchesProvince =
                !selectedProvince || dorm.province === selectedProvince;

            if (
                matchesSearch &&
                matchesRegion &&
                matchesProvince
            ) {

                record.marker.addTo(markerLayer);

                visibleMarkers.push(record.marker);

                dormCount++;

                roomCount += Number(dorm.available_rooms || 0);

            }

        });

        visibleDormCount.textContent = dormCount;
        visibleRoomCount.textContent = roomCount;

        /*
        |--------------------------------------------------------------------------
        | ZOOM TO RESULTS WITHOUT LEAVING VISAYAS
        |--------------------------------------------------------------------------
        */

        if (visibleMarkers.length > 0) {

            const group = L.featureGroup(visibleMarkers);

            map.fitBounds(group.getBounds().pad(0.18), {
                maxZoom: 12,
                maxBounds: visayasBounds
            });

        } else {

            map.setView(visayasCenter, 7.5);

        }

    }

    /*
    |--------------------------------------------------------------------------
    | FILTER EVENTS
    |--------------------------------------------------------------------------
    */

    searchInput.addEventListener('input', applyFilters);

    regionFilter.addEventListener('change', function () {

        provinceFilter.value = '';

        applyFilters();

    });

    provinceFilter.addEventListener('change', applyFilters);

    /*
    |--------------------------------------------------------------------------
    | INITIAL VIEW AND RESIZE FIX
    |--------------------------------------------------------------------------
    */

    map.setView(visayasCenter, 7.5);

    map.whenReady(function () {

        setTimeout(function () {

            map.invalidateSize(true);
            map.setView(visayasCenter, 7.5);

        }, 300);

        setTimeout(function () {

            map.invalidateSize(true);

        }, 1000);

    });

    window.addEventListener('resize', function () {

        setTimeout(function () {
            map.invalidateSize(true);
        }, 200);

    });

    /*
    |--------------------------------------------------------------------------
    | REFRESH WHEN OVERVIEW TAB IS OPENED
    |--------------------------------------------------------------------------
    */

    document.querySelectorAll('.dashboard-tab').forEach(function (tab) {

        tab.addEventListener('click', function () {

            if (tab.dataset.tab === 'overview') {

                setTimeout(function () {

                    map.invalidateSize(true);

                }, 250);

            }

        });

    });

    applyFilters();

})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
