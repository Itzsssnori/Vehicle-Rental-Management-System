<?php
$title = 'Customer Dashboard';
require_once __DIR__ . '/../../config.php';
$user = require_customer();

$pdo = db();
$accountStmt = $pdo->prepare('SELECT id, name, email, phone, address, profile_image, preferred_payment_method, created_at FROM users WHERE id = ?');
$accountStmt->execute([$user['id']]);
$account = $accountStmt->fetch() ?: $user;

$standing = customer_standing($pdo, (int)$user['id']);
$standingType = $standing['standing'] ?? 'good';
$standingPoints = (int)($standing['points'] ?? 0);

// Fetch all customer reservations with details
$stmt = $pdo->prepare(
    "SELECT r.id, r.reference_number, r.requested_pickup_at AS start_date, r.requested_return_at AS end_date,
            r.base_amount, r.total_amount, r.status, r.payment_method, r.created_at,
            v.id AS vehicle_id, v.brand, v.model, c.name AS category, v.image,
            pl.name AS pickup_location, rl.name AS return_location,
            rt.id AS rental_id, rt.status AS rental_status,
            COALESCE(SUM(p.amount), 0) AS paid_amount
     FROM reservations r
     INNER JOIN vehicles v ON v.id = r.vehicle_id
     INNER JOIN vehicle_categories c ON c.id = v.category_id
     LEFT JOIN locations pl ON pl.id = r.pickup_location_id
     LEFT JOIN locations rl ON rl.id = r.return_location_id
     LEFT JOIN rentals rt ON rt.reservation_id = r.id
     LEFT JOIN payments p ON p.reservation_id = r.id OR (rt.id IS NOT NULL AND p.rental_id = rt.id)
     WHERE r.customer_id = ?
     GROUP BY r.id, rt.id, v.id, c.id, pl.id, rl.id
     ORDER BY r.created_at DESC"
);
$stmt->execute([$user['id']]);
$allReservations = $stmt->fetchAll();

// Group trips
$activeTrips = array_values(array_filter($allReservations, static fn(array $r): bool =>
    in_array($r['status'], ['pending', 'confirmed', 'ongoing'], true)
));
$ongoingTrips = array_values(array_filter($allReservations, static fn(array $r): bool =>
    $r['status'] === 'ongoing'
));
$upcomingTrips = array_values(array_filter($allReservations, static fn(array $r): bool =>
    in_array($r['status'], ['pending', 'confirmed'], true) && $r['start_date'] >= date('Y-m-d')
));

// Lifetime paid
$paidStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(p.amount), 0)
     FROM payments p
     INNER JOIN reservations r ON r.id = p.reservation_id
     WHERE r.customer_id = ?"
);
$paidStmt->execute([$user['id']]);
$lifetimePaid = (float)$paidStmt->fetchColumn();

// Calculate total outstanding balance across active bookings
$totalOutstanding = 0.0;
foreach ($activeTrips as $res) {
    $bal = rental_balance($pdo, (int)$res['id'], $res['rental_id'] ? (int)$res['rental_id'] : null);
    $totalOutstanding += $bal['outstanding'];
}

// Find Spotlight Trip (Ongoing first, then confirmed upcoming, then pending)
$spotlightTrip = null;
if (!empty($ongoingTrips)) {
    $spotlightTrip = $ongoingTrips[0];
} elseif (!empty($upcomingTrips)) {
    $spotlightTrip = $upcomingTrips[0];
} elseif (!empty($activeTrips)) {
    $spotlightTrip = $activeTrips[0];
}

// Payments and receipts
$paymentStmt = $pdo->prepare(
    "SELECT p.id, p.amount, p.method, p.reference_number, p.paid_at, pr.receipt_number,
            r.id AS reservation_id, r.reference_number AS reservation_reference,
            v.brand, v.model,
            COALESCE(SUM(rf.amount), 0) AS refunded_amount
     FROM payments p
     INNER JOIN reservations r ON r.id = p.reservation_id
     INNER JOIN vehicles v ON v.id = r.vehicle_id
     LEFT JOIN payment_receipts pr ON pr.payment_id = p.id
     LEFT JOIN refunds rf ON rf.payment_id = p.id
     WHERE r.customer_id = ?
     GROUP BY p.id, r.id, pr.id, v.id
     ORDER BY p.paid_at DESC LIMIT 10"
);
$paymentStmt->execute([$user['id']]);
$paymentHistory = $paymentStmt->fetchAll();

// Financial charges ledger
$financialStmt = $pdo->prepare(
    "SELECT r.id AS reservation_id, r.reference_number, rc.charge_type, rc.description, rc.amount, rc.created_at
     FROM reservations r
     LEFT JOIN rentals rt ON rt.reservation_id = r.id
     INNER JOIN rental_charges rc ON rc.reservation_id = r.id OR rc.rental_id = rt.id
     WHERE r.customer_id = ?
     ORDER BY rc.created_at DESC
     LIMIT 10"
);
$financialStmt->execute([$user['id']]);
$financialRecords = $financialStmt->fetchAll();

// Penalty events
$penaltyStmt = $pdo->prepare(
    "SELECT event_type, points, reason, status, expires_at, created_at
     FROM penalty_events WHERE user_id = ? ORDER BY created_at DESC LIMIT 10"
);
$penaltyStmt->execute([$user['id']]);
$penaltyHistory = $penaltyStmt->fetchAll();

// Notifications
$notificationStmt = $pdo->prepare(
    'SELECT id, title, message, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5'
);
$notificationStmt->execute([$user['id']]);
$notifications = $notificationStmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<main class="portal-customer-page">
  <div class="portal-customer-layout">
    <?php include __DIR__ . '/../../includes/customer-sidebar.php'; ?>

    <div class="customer-main-content">
      <!-- Top Greeting Banner -->
      <section class="portal-hero-banner" id="overview">
        <div class="hero-greeting-content">
          <span class="eyebrow">Customer Workspace</span>
          <h1>Good day, <?= e($account['name']) ?></h1>
          <p>Track your reservations, review trip dispatches, and manage your payments securely.</p>
        </div>
        <a class="portal-btn-primary" href="/vehicles.php" style="white-space:nowrap;">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/></svg>
          <span>Find a Vehicle</span>
        </a>
      </section>

      <?php if (isset($_GET['requested'])): ?>
        <div class="portal-alert-success">
          <span class="alert-icon" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          </span>
          <span><strong>Reservation Submitted:</strong> Your booking request has been received. Our operations staff will verify vehicle availability shortly.</span>
        </div>
      <?php endif; ?>

      <!-- 4 Metric KPI Cards -->
      <section class="portal-kpi-grid" aria-label="Customer quick statistics">
        <!-- Standing Card -->
        <a href="/customer/standing.php" class="portal-kpi-card">
          <div class="kpi-header">
            <span class="kpi-label">Account Standing</span>
            <span class="kpi-icon-pill" aria-hidden="true">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            </span>
          </div>
          <div class="kpi-value">
            <span class="portal-badge <?= $standingType === 'good' ? 'badge-success' : ($standingType === 'warning' ? 'badge-warning' : 'badge-danger') ?>">
              <?= e(ucfirst($standingType)) ?>
            </span>
          </div>
          <div class="kpi-subtext">
            <span><?= $standingPoints ?> penalty point<?= $standingPoints === 1 ? '' : 's' ?></span>
            <span>· 12 mo expiry</span>
          </div>
        </a>

        <!-- Active Trips Card -->
        <a href="/customer/my-rentals.php" class="portal-kpi-card">
          <div class="kpi-header">
            <span class="kpi-label">Active & Upcoming</span>
            <span class="kpi-icon-pill" aria-hidden="true">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            </span>
          </div>
          <div class="kpi-value"><?= count($activeTrips) ?></div>
          <div class="kpi-subtext">
            <span><?= count($ongoingTrips) ?> ongoing</span>
            <span>· <?= count($upcomingTrips) ?> upcoming</span>
          </div>
        </a>

        <!-- Total Paid Card -->
        <a href="/customer/payments.php" class="portal-kpi-card">
          <div class="kpi-header">
            <span class="kpi-label">Settled Payments</span>
            <span class="kpi-icon-pill" aria-hidden="true">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
            </span>
          </div>
          <div class="kpi-value">₱<?= number_format($lifetimePaid, 2) ?></div>
          <div class="kpi-subtext">
            <span>Verified across history</span>
          </div>
        </a>

        <!-- Outstanding Balance Card -->
        <a href="/customer/payments.php" class="portal-kpi-card">
          <div class="kpi-header">
            <span class="kpi-label">Outstanding Balance</span>
            <span class="kpi-icon-pill" aria-hidden="true">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </span>
          </div>
          <div class="kpi-value" style="<?= $totalOutstanding > 0 ? 'color:#e53e3e;' : 'color:#065f46;' ?>">
            ₱<?= number_format($totalOutstanding, 2) ?>
          </div>
          <div class="kpi-subtext">
            <span><?= $totalOutstanding > 0 ? 'Action required' : 'Paid in full' ?></span>
          </div>
        </a>
      </section>

      <!-- Active Trip Spotlight Card (Hero item if active trip exists) -->
      <?php if ($spotlightTrip):
        $spotlightBal = rental_balance($pdo, (int)$spotlightTrip['id'], $spotlightTrip['rental_id'] ? (int)$spotlightTrip['rental_id'] : null);
        $isPaid = $spotlightBal['outstanding'] <= 0.0001;
        $isOnline = in_array($spotlightTrip['payment_method'], ['online', 'paymongo', 'paypal'], true);
        $days = rental_days($spotlightTrip['start_date'], $spotlightTrip['end_date']);
      ?>
        <section class="portal-spotlight-card" aria-label="Spotlight trip card">
          <div class="spotlight-accent-bar"></div>
          <div class="spotlight-vehicle-media">
            <img src="<?= e(vehicle_image($spotlightTrip['image'])) ?>" alt="<?= e($spotlightTrip['brand'] . ' ' . $spotlightTrip['model']) ?>" class="spotlight-vehicle-img" loading="lazy">
            <span class="spotlight-category-tag"><?= e($spotlightTrip['category']) ?></span>
          </div>
          <div class="spotlight-content">
            <div class="spotlight-header-row">
              <div class="spotlight-title-group">
                <h3><?= e($spotlightTrip['brand'] . ' ' . $spotlightTrip['model']) ?></h3>
                <span class="spotlight-reference">Booking Ref: <?= e($spotlightTrip['reference_number']) ?></span>
              </div>
              <div class="spotlight-status-group">
                <span class="portal-badge <?= in_array($spotlightTrip['status'], ['confirmed', 'ongoing'], true) ? 'badge-success' : 'badge-neutral' ?>">
                  <?= e(ucfirst($spotlightTrip['status'])) ?>
                </span>
                <span class="portal-badge <?= $isPaid ? 'badge-success' : ($spotlightTrip['status'] === 'confirmed' ? 'badge-warning' : 'badge-neutral') ?>">
                  <?= $isPaid ? 'Paid in Full' : 'Payment Due' ?>
                </span>
              </div>
            </div>

            <div class="spotlight-schedule-grid">
              <div class="spotlight-schedule-item">
                <dt>Pickup Schedule & Hub</dt>
                <dd><?= e(date('M j, Y g:i A', strtotime($spotlightTrip['start_date']))) ?></dd>
                <span style="font-size:0.75rem;color:#64748b;"><?= e($spotlightTrip['pickup_location'] ?: 'Main Hub') ?></span>
              </div>
              <div class="spotlight-schedule-item">
                <dt>Return Schedule & Hub</dt>
                <dd><?= e(date('M j, Y g:i A', strtotime($spotlightTrip['end_date']))) ?></dd>
                <span style="font-size:0.75rem;color:#64748b;"><?= e($spotlightTrip['return_location'] ?: 'Main Hub') ?> (<?= $days ?> day<?= $days === 1 ? '' : 's' ?>)</span>
              </div>
            </div>

            <div class="spotlight-action-row">
              <div class="spotlight-pricing">
                <strong>₱<?= number_format((float)$spotlightTrip['total_amount'], 2) ?></strong>
                <span>Total Amount · <?= e(ucwords(str_replace('_', ' ', $spotlightTrip['payment_method']))) ?></span>
              </div>
              <div class="spotlight-buttons">
                <?php if ($spotlightTrip['status'] === 'confirmed' && !$isPaid && $isOnline): ?>
                  <a href="/online-checkout.php?reservation_id=<?= (int)$spotlightTrip['id'] ?>" class="portal-btn-primary" style="height:38px;padding:0 16px;font-size:0.84rem;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                    <span>Pay Online</span>
                  </a>
                <?php elseif ($isPaid): ?>
                  <a href="/customer/rental.php?id=<?= (int)$spotlightTrip['id'] ?>" class="portal-btn-outline" style="height:38px;padding:0 14px;font-size:0.84rem;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                    <span>View Receipt</span>
                  </a>
                <?php endif; ?>
                <a href="/customer/rental.php?id=<?= (int)$spotlightTrip['id'] ?>" class="portal-btn-outline" style="height:38px;padding:0 14px;font-size:0.84rem;">
                  <span>Trip Details</span>
                </a>
              </div>
            </div>
          </div>
        </section>
      <?php endif; ?>

      <!-- Recent Bookings Section -->
      <section class="portal-card" id="rentals">
        <div class="portal-card-header">
          <div class="portal-card-title-group">
            <h2>Recent Bookings & Trips</h2>
            <p>Your current requests, ongoing rentals, and recent vehicle dispatch history.</p>
          </div>
          <a href="/customer/my-rentals.php" class="portal-btn-outline" style="height:36px;padding:0 14px;font-size:0.82rem;">
            <span>View All Bookings (<?= count($allReservations) ?>)</span>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
          </a>
        </div>

        <?php if (empty($allReservations)): ?>
          <div class="portal-empty-card">
            <span class="portal-empty-icon" aria-hidden="true">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/></svg>
            </span>
            <h3>No Bookings Recorded</h3>
            <p>You have not made any vehicle reservations yet. Browse our premium fleet and submit your first rental booking in minutes.</p>
            <a href="/vehicles.php" class="portal-btn-primary" style="margin-top:8px;">Browse Vehicle Fleet</a>
          </div>
        <?php else: ?>
          <div class="portal-table-container">
            <table class="portal-table">
              <thead>
                <tr>
                  <th>Vehicle</th>
                  <th>Booking Ref</th>
                  <th>Dates & Duration</th>
                  <th>Total Amount</th>
                  <th>Booking Status</th>
                  <th>Payment</th>
                  <th style="text-align:right;">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach (array_slice($allReservations, 0, 5) as $res):
                  $resBal = rental_balance($pdo, (int)$res['id'], $res['rental_id'] ? (int)$res['rental_id'] : null);
                  $resPaid = $resBal['outstanding'] <= 0.0001;
                  $days = rental_days($res['start_date'], $res['end_date']);
                  $bookingBadgeClass = match($res['status']) {
                    'confirmed', 'ongoing', 'completed' => 'badge-success',
                    'cancelled', 'rejected' => 'badge-danger',
                    default => 'badge-neutral',
                  };
                ?>
                  <tr>
                    <td>
                      <div class="table-vehicle-cell">
                        <div class="table-vehicle-thumb" style="background-image:url('<?= e(vehicle_image($res['image'])) ?>');"></div>
                        <div class="table-vehicle-info">
                          <strong><?= e($res['brand'] . ' ' . $res['model']) ?></strong>
                          <span><?= e($res['category']) ?></span>
                        </div>
                      </div>
                    </td>
                    <td><span style="font-family:monospace;font-weight:700;color:#475569;"><?= e($res['reference_number']) ?></span></td>
                    <td>
                      <div style="font-size:0.82rem;font-weight:700;color:#0f172a;"><?= e(date('M j', strtotime($res['start_date']))) ?> – <?= e(date('M j, Y', strtotime($res['end_date']))) ?></div>
                      <span style="font-size:0.74rem;color:#64748b;"><?= $days ?> day<?= $days === 1 ? '' : 's' ?></span>
                    </td>
                    <td>
                      <strong style="font-size:0.92rem;color:#0f172a;">₱<?= number_format((float)$res['total_amount'], 2) ?></strong>
                    </td>
                    <td>
                      <span class="portal-badge <?= $bookingBadgeClass ?>"><?= e(ucfirst($res['status'])) ?></span>
                    </td>
                    <td>
                      <span class="portal-badge <?= $resPaid ? 'badge-success' : ($res['status'] === 'confirmed' ? 'badge-warning' : 'badge-neutral') ?>">
                        <?= $resPaid ? 'Paid' : 'Pending' ?>
                      </span>
                    </td>
                    <td style="text-align:right;">
                      <a href="/customer/rental.php?id=<?= (int)$res['id'] ?>" class="portal-btn-outline" style="height:32px;padding:0 12px;font-size:0.78rem;">
                        <span>Details</span>
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

      <!-- Payments & Receipts Section -->
      <section class="portal-card" id="payment-history">
        <div class="portal-card-header">
          <div class="portal-card-title-group">
            <h2>Payment & Official Receipts Ledger</h2>
            <p>Verified electronic transaction records, settlement methods, and downloadable VAT-exempt receipts.</p>
          </div>
          <a href="/customer/payments.php" class="portal-btn-outline" style="height:36px;padding:0 14px;font-size:0.82rem;">
            <span>Full Ledger</span>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
          </a>
        </div>

        <?php if (empty($paymentHistory)): ?>
          <div class="portal-empty-card">
            <span class="portal-empty-icon" aria-hidden="true">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
            </span>
            <h3>No Payments Recorded Yet</h3>
            <p>Once your reservation is confirmed by staff, you can complete settlement online or at vehicle pickup.</p>
          </div>
        <?php else: ?>
          <div class="portal-table-container">
            <table class="portal-table">
              <thead>
                <tr>
                  <th>Receipt Number</th>
                  <th>Reservation Ref</th>
                  <th>Vehicle</th>
                  <th>Payment Method</th>
                  <th>Date Settled</th>
                  <th>Amount Paid</th>
                  <th style="text-align:right;">Official Receipt</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($paymentHistory as $pay): ?>
                  <tr>
                    <td>
                      <strong style="font-family:monospace;color:#0f172a;"><?= e($pay['receipt_number'] ?: 'RCP-PENDING') ?></strong>
                    </td>
                    <td>
                      <a href="/customer/rental.php?id=<?= (int)$pay['reservation_id'] ?>" style="color:#0f172a;font-weight:700;text-decoration:underline;">
                        <?= e($pay['reservation_reference']) ?>
                      </a>
                    </td>
                    <td><?= e($pay['brand'] . ' ' . $pay['model']) ?></td>
                    <td>
                      <span class="portal-badge badge-neutral">
                        <?= e(ucwords(str_replace('_', ' ', $pay['method']))) ?>
                      </span>
                    </td>
                    <td><?= e(date('M j, Y g:i A', strtotime($pay['paid_at']))) ?></td>
                    <td>
                      <strong style="color:#065f46;">₱<?= number_format((float)$pay['amount'], 2) ?></strong>
                      <?php if ((float)$pay['refunded_amount'] > 0): ?>
                        <span style="display:block;font-size:0.72rem;color:#b91c1c;">Refunded ₱<?= number_format((float)$pay['refunded_amount'], 2) ?></span>
                      <?php endif; ?>
                    </td>
                    <td style="text-align:right;">
                      <a href="/payment-return.php?provider=<?= e($pay['method']) ?>&reservation=<?= (int)$pay['reservation_id'] ?>" class="portal-btn-outline" style="height:32px;padding:0 12px;font-size:0.78rem;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        <span>View Receipt</span>
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

      <!-- Charges & Incurred Fees Breakdown -->
      <?php if (!empty($financialRecords)): ?>
        <section class="portal-card" id="financial-history">
          <div class="portal-card-header">
            <div class="portal-card-title-group">
              <h2>Rental Charges & Assessment Breakdown</h2>
              <p>Itemized base rental fees, adjustments, and incidental charges recorded by the rental hub.</p>
            </div>
          </div>
          <div class="portal-table-container">
            <table class="portal-table">
              <thead>
                <tr>
                  <th>Reservation Ref</th>
                  <th>Charge Category</th>
                  <th>Description</th>
                  <th>Assessment Date</th>
                  <th style="text-align:right;">Charge Amount</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($financialRecords as $charge): ?>
                  <tr>
                    <td><span style="font-family:monospace;font-weight:700;"><?= e($charge['reference_number']) ?></span></td>
                    <td>
                      <span class="portal-badge badge-neutral">
                        <?= e(ucfirst(str_replace('_', ' ', $charge['charge_type']))) ?>
                      </span>
                    </td>
                    <td><?= e($charge['description'] ?: 'Base rental rate calculation') ?></td>
                    <td><?= e(date('M j, Y', strtotime($charge['created_at']))) ?></td>
                    <td style="text-align:right;">
                      <strong>₱<?= number_format((float)$charge['amount'], 2) ?></strong>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php endif; ?>

      <!-- Account Standing & Penalty Log -->
      <section class="portal-card" id="penalty-history">
        <div class="portal-card-header">
          <div class="portal-card-title-group">
            <h2>Driving Standing & Penalty Log</h2>
            <p>Transparent record of vehicle usage infractions, penalty points, and policy status.</p>
          </div>
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <span class="portal-badge <?= $standingType === 'good' ? 'badge-success' : ($standingType === 'warning' ? 'badge-warning' : 'badge-danger') ?>" style="padding:6px 14px;font-size:0.82rem;">
              <?= e(ucfirst($standingType)) ?> Standing (<?= $standingPoints ?> Point<?= $standingPoints === 1 ? '' : 's' ?>)
            </span>
            <a href="/customer/standing.php" class="portal-btn-outline" style="height:36px;padding:0 14px;font-size:0.82rem;">
              <span>Policy & Infractions</span>
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
          </div>
        </div>

        <!-- Standing Guidelines Banner -->
        <div class="standing-guidelines-banner">
          <div>
            <strong style="display:block;font-size:0.84rem;color:#0f172a;margin-bottom:2px;">VELO Vehicle Fleet Access Policy</strong>
            <span style="font-size:0.78rem;color:#64748b;">Points automatically expire 12 months after the incident date.</span>
          </div>
          <div class="standing-tier-list">
            <div class="standing-tier-item">
              <span class="standing-tier-dot tier-good"></span>
              <span><strong>0–2 Pts:</strong> Good (Full fleet access)</span>
            </div>
            <div class="standing-tier-item">
              <span class="standing-tier-dot tier-warning"></span>
              <span><strong>3–4 Pts:</strong> Warning (Prepayment required)</span>
            </div>
            <div class="standing-tier-item">
              <span class="standing-tier-dot tier-restricted"></span>
              <span><strong>5+ Pts:</strong> Restricted (Reservations suspended)</span>
            </div>
          </div>
        </div>

        <?php if (empty($penaltyHistory)): ?>
          <div class="portal-empty-card" style="padding:32px 24px;">
            <span class="portal-empty-icon" style="background:#ecfdf5;color:#059669;" aria-hidden="true">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </span>
            <h3>Clean Driving Record</h3>
            <p>You have zero penalty points on file. Continue returning vehicles on time and maintaining vehicle care to ensure uninhibited reservation approval.</p>
          </div>
        <?php else: ?>
          <div class="portal-table-container">
            <table class="portal-table">
              <thead>
                <tr>
                  <th>Event Category</th>
                  <th>Points Assessed</th>
                  <th>Reason / Particulars</th>
                  <th>Status</th>
                  <th>Date Logged</th>
                  <th>Expiration Date</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($penaltyHistory as $penalty): ?>
                  <tr>
                    <td>
                      <strong><?= e(ucfirst(str_replace('_', ' ', $penalty['event_type']))) ?></strong>
                    </td>
                    <td>
                      <span class="portal-badge badge-danger">+<?= (int)$penalty['points'] ?> pt<?= (int)$penalty['points'] === 1 ? '' : 's' ?></span>
                    </td>
                    <td><?= e($penalty['reason']) ?></td>
                    <td>
                      <span class="portal-badge <?= $penalty['status'] === 'approved' ? 'badge-warning' : 'badge-neutral' ?>">
                        <?= e(ucfirst($penalty['status'])) ?>
                      </span>
                    </td>
                    <td><?= e(date('M j, Y', strtotime($penalty['created_at']))) ?></td>
                    <td>
                      <?= $penalty['expires_at'] ? e(date('M j, Y', strtotime($penalty['expires_at']))) : 'No Expiry' ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

      <!-- Notifications & Updates Feed -->
      <?php if (!empty($notifications)): ?>
        <section class="portal-card">
          <div class="portal-card-header">
            <div class="portal-card-title-group">
              <h2>Important Account Notifications</h2>
              <p>System announcements, reservation approvals, and transaction receipts.</p>
            </div>
          </div>
          <div style="display:flex;flex-direction:column;gap:12px;">
            <?php foreach ($notifications as $notif): ?>
              <div style="padding:14px 16px;background:#f8fafc;border-radius:10px;border:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:flex-start;gap:16px;">
                <div>
                  <strong style="display:block;font-size:0.9rem;color:#0f172a;margin-bottom:3px;"><?= e($notif['title']) ?></strong>
                  <p style="margin:0;font-size:0.84rem;color:#475569;"><?= e($notif['message']) ?></p>
                </div>
                <span style="font-size:0.75rem;color:#94a3b8;white-space:nowrap;"><?= e(date('M j, g:i A', strtotime($notif['created_at']))) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endif; ?>
    </div>
  </div>
</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
