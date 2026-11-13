<?php
$title = 'Reservation Details';
require_once __DIR__ . '/../../config.php';
$user = require_customer();
$pdo = db();

$reservationId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$error = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $reservationId) {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'request_cancellation') {
        $reason = trim($_POST['reason'] ?? '');
        if ($reason === '') {
            $error = 'Please provide a reason for the cancellation request.';
        } else {
            try {
                $pdo->beginTransaction();
                $checkStmt = $pdo->prepare("SELECT status FROM reservations WHERE id = ? AND customer_id = ? FOR UPDATE");
                $checkStmt->execute([$reservationId, $user['id']]);
                $currentStatus = $checkStmt->fetchColumn();

                if (!$currentStatus || !in_array($currentStatus, ['pending', 'confirmed'], true)) {
                    throw new RuntimeException('This reservation is not eligible for cancellation.');
                }

                $existing = $pdo->prepare("SELECT id FROM reservation_cancellation_requests WHERE reservation_id = ? AND status = 'requested' LIMIT 1");
                $existing->execute([$reservationId]);
                if ($existing->fetch()) {
                    throw new RuntimeException('A cancellation request is already under administrative review.');
                }

                $insert = $pdo->prepare('INSERT INTO reservation_cancellation_requests (reservation_id, requested_by, reason) VALUES (?, ?, ?)');
                $insert->execute([$reservationId, $user['id'], $reason]);
                write_audit($pdo, (int)$user['id'], 'cancellation_requested', 'reservations', $reservationId, $reason);
                $pdo->commit();
                $message = 'Your cancellation request has been submitted for review.';
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Customer cancellation request failed: ' . $exception->getMessage());
                $error = $exception->getMessage();
            }
        }
    }
}

// Fetch reservation details
$stmt = $pdo->prepare(
    "SELECT r.*, v.brand, v.model, v.image, v.seats, v.transmission, v.fuel_type, v.plate_number, v.daily_rate,
            c.name AS category,
            pl.name AS pickup_location, rl.name AS return_location,
            rt.id AS rental_id, rt.status AS rental_status
     FROM reservations r
     INNER JOIN vehicles v ON v.id = r.vehicle_id
     INNER JOIN vehicle_categories c ON c.id = v.category_id
     INNER JOIN locations pl ON pl.id = r.pickup_location_id
     INNER JOIN locations rl ON rl.id = r.return_location_id
     LEFT JOIN rentals rt ON rt.reservation_id = r.id
     WHERE r.id = ? AND r.customer_id = ?"
);
$stmt->execute([$reservationId ?: 0, $user['id']]);
$reservation = $stmt->fetch();

if (!$reservation) {
    $error = 'The requested reservation could not be found.';
}

$balance = ['charged' => 0.0, 'paid' => 0.0, 'outstanding' => 0.0];
$chargesList = [];
$paymentsList = [];
$cancellationRequest = null;
$paymentAttempt = null;

if ($reservation) {
    $balance = rental_balance($pdo, (int)$reservation['id'], $reservation['rental_id'] ? (int)$reservation['rental_id'] : null);

    // Charges
    $chargeSql = "SELECT * FROM rental_charges WHERE reservation_id = ?";
    $chargeParams = [$reservationId];
    if ($reservation['rental_id']) {
        $chargeSql .= " OR rental_id = ?";
        $chargeParams[] = $reservation['rental_id'];
    }
    $chargeSql .= " ORDER BY created_at ASC";
    $chargeStmt = $pdo->prepare($chargeSql);
    $chargeStmt->execute($chargeParams);
    $chargesList = $chargeStmt->fetchAll();

    // Payments
    $paymentSql = "SELECT p.*, pr.receipt_number, COALESCE(SUM(rf.amount), 0) AS refunded_amount
                   FROM payments p
                   LEFT JOIN payment_receipts pr ON pr.payment_id = p.id
                   LEFT JOIN refunds rf ON rf.payment_id = p.id
                   WHERE p.reservation_id = ?";
    $paymentParams = [$reservationId];
    if ($reservation['rental_id']) {
        $paymentSql .= " OR p.rental_id = ?";
        $paymentParams[] = $reservation['rental_id'];
    }
    $paymentSql .= " GROUP BY p.id, pr.id ORDER BY p.paid_at DESC";
    $paymentStmt = $pdo->prepare($paymentSql);
    $paymentStmt->execute($paymentParams);
    $paymentsList = $paymentStmt->fetchAll();

    // Cancellation requests
    $cancelStmt = $pdo->prepare("SELECT * FROM reservation_cancellation_requests WHERE reservation_id = ? ORDER BY id DESC LIMIT 1");
    $cancelStmt->execute([$reservationId]);
    $cancellationRequest = $cancelStmt->fetch();

    // Payment attempt
    $attemptStmt = $pdo->prepare("SELECT * FROM payment_attempts WHERE reservation_id = ? ORDER BY id DESC LIMIT 1");
    $attemptStmt->execute([$reservationId]);
    $paymentAttempt = $attemptStmt->fetch();

    // Operational Pickup & Return Records
    $pickupRecord = null;
    $returnRecord = null;
    if ($reservation['rental_id']) {
        $pStmt = $pdo->prepare("SELECT * FROM pickup_records WHERE rental_id = ? ORDER BY id DESC LIMIT 1");
        $pStmt->execute([$reservation['rental_id']]);
        $pickupRecord = $pStmt->fetch();

        $rStmt = $pdo->prepare("SELECT * FROM return_records WHERE rental_id = ? ORDER BY id DESC LIMIT 1");
        $rStmt->execute([$reservation['rental_id']]);
        $returnRecord = $rStmt->fetch();
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<main class="portal-customer-page">
  <div class="portal-customer-layout">
    <?php include __DIR__ . '/../../includes/customer-sidebar.php'; ?>

    <div class="customer-main-content">
      <!-- Breadcrumb and Back link -->
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:4px;">
        <a href="/customer/my-rentals.php" class="portal-btn-outline" style="height:36px;padding:0 14px;font-size:0.82rem;">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
          <span>Back to My Bookings</span>
        </a>
        <?php if ($reservation): ?>
          <span style="font-family:monospace;font-size:0.84rem;color:#64748b;font-weight:700;">
            Booking Reference: <?= e($reservation['reference_number']) ?>
          </span>
        <?php endif; ?>
      </div>

      <?php if (!$reservation): ?>
        <div class="portal-empty-card" style="margin-top:20px;">
          <span class="portal-empty-icon" aria-hidden="true">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          </span>
          <h3>Reservation Not Found</h3>
          <p><?= e($error) ?></p>
          <a href="/customer/my-rentals.php" class="portal-btn-primary" style="margin-top:8px;">View My Reservations</a>
        </div>
      <?php else:
        $isPaid = $balance['outstanding'] <= 0.0001;
        $isOnline = in_array($reservation['payment_method'], ['online', 'paymongo', 'paypal'], true);
        $days = rental_days($reservation['requested_pickup_at'], $reservation['requested_return_at']);
        $bookingBadgeClass = match($reservation['status']) {
          'confirmed', 'ongoing', 'completed' => 'badge-success',
          'cancelled', 'rejected' => 'badge-danger',
          default => 'badge-neutral',
        };

        // Complete 5-Stage Lifecycle Process Calculation
        $isTerminalNegative = in_array($reservation['status'], ['cancelled', 'rejected'], true);
        $currentStep = 1;
        if ($isTerminalNegative) {
          $currentStep = 2; // Terminal state at review
        } elseif ($reservation['status'] === 'pending') {
          $currentStep = 1; // Reservation submitted, awaiting review
        } elseif ($reservation['status'] === 'confirmed' && empty($reservation['rental_id'])) {
          $currentStep = 2; // Approved by staff, ready for turnover
        } elseif (!empty($reservation['rental_id'])) {
          if ($reservation['rental_status'] === 'ongoing') {
            $currentStep = 4; // Vehicle turned over, active journey
          } elseif (in_array($reservation['rental_status'], ['returned', 'completed'], true)) {
            $currentStep = 5; // Vehicle returned, settlement & closure
          } else {
            $currentStep = 3; // Turnover handover
          }
        }
      ?>
        <!-- Notifications / Messages -->
        <?php if ($message): ?>
          <div class="portal-alert-success">
            <span class="alert-icon" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </span>
            <span><?= e($message) ?></span>
          </div>
        <?php endif; ?>

        <?php if ($error): ?>
          <div class="portal-alert-danger">
            <span class="alert-icon" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            </span>
            <span><?= e($error) ?></span>
          </div>
        <?php endif; ?>

        <!-- Booking Header Banner -->
        <section class="portal-card" style="padding:22px 28px;">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:20px;flex-wrap:wrap;">
            <div>
              <span class="spotlight-category-tag" style="position:static;display:inline-block;margin-bottom:8px;">
                <?= e($reservation['category']) ?>
              </span>
              <h1 style="font-size:1.6rem;font-weight:800;color:#0f172a;margin:0 0 4px;">
                <?= e($reservation['brand'] . ' ' . $reservation['model']) ?>
              </h1>
              <p style="margin:0;font-size:0.84rem;color:#64748b;">
                Booked on <?= e(date('F j, Y · g:i A', strtotime($reservation['created_at']))) ?>
              </p>
            </div>

            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
              <span class="portal-badge <?= $bookingBadgeClass ?>" style="padding:6px 14px;font-size:0.82rem;">
                <?= e(ucfirst($reservation['status'])) ?>
              </span>
              <span class="portal-badge <?= $isPaid ? 'badge-success' : ($reservation['status'] === 'confirmed' ? 'badge-warning' : 'badge-neutral') ?>" style="padding:6px 14px;font-size:0.82rem;">
                <?= $isPaid ? 'Paid in Full' : 'Payment Due' ?>
              </span>
              <?php if ($reservation['rental_status']): ?>
                <span class="portal-badge badge-brand" style="padding:6px 14px;font-size:0.82rem;">
                  Rental: <?= e(ucfirst($reservation['rental_status'])) ?>
                </span>
              <?php endif; ?>
            </div>
          </div>
        </section>

        <!-- Complete Rental Lifecycle Process Stepper -->
        <section class="portal-process-stepper-card" aria-label="Rental lifecycle progress">
          <div class="stepper-header">
            <div class="stepper-header-title">
              <span class="staff-live-dot" style="display:inline-block; vertical-align:middle; background:#e53e3e;" aria-hidden="true"></span>
              <h3>Rental Journey Progression</h3>
              <span>&bull; Step <?= $currentStep ?> of 5: <?= match($currentStep) {
                1 => 'Reservation Requested',
                2 => $isTerminalNegative ? 'Review Ended (' . ucfirst($reservation['status']) . ')' : 'Staff Review & Approval',
                3 => 'Vehicle Turnover & Key Handover',
                4 => 'Active Journey on the Road',
                5 => $reservation['rental_status'] === 'completed' ? 'Final Settlement & Trip Completed' : 'Return Check-in & Settlement',
                default => 'Processing'
              } ?></span>
            </div>
            <div>
              <?php if ($isTerminalNegative): ?>
                <span class="portal-badge badge-danger"><?= e(ucfirst($reservation['status'])) ?></span>
              <?php elseif ($currentStep === 4): ?>
                <span class="portal-badge badge-brand">Active on Road</span>
              <?php elseif ($currentStep === 5 && $reservation['rental_status'] === 'completed'): ?>
                <span class="portal-badge badge-success">Completed &amp; Closed</span>
              <?php elseif ($currentStep === 2 && $reservation['status'] === 'confirmed'): ?>
                <span class="portal-badge badge-success">Confirmed &bull; Ready for Turnover</span>
              <?php else: ?>
                <span class="portal-badge badge-neutral"><?= e(ucfirst($reservation['status'])) ?></span>
              <?php endif; ?>
            </div>
          </div>

          <div class="stepper-track">
            <!-- Step 1: Reservation Request -->
            <div class="stepper-step <?= $currentStep > 1 ? 'is-complete' : 'is-active' ?>">
              <div class="step-node">
                <?php if ($currentStep > 1): ?>
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polyline points="20 6 9 17 4 12"/></svg>
                <?php else: ?>
                  <span>1</span>
                <?php endif; ?>
              </div>
              <div class="step-label">1. Reservation</div>
              <div class="step-subtext">Booked <?= date('M j', strtotime($reservation['created_at'])) ?></div>
            </div>

            <!-- Step 2: Staff Review & Approval -->
            <div class="stepper-step <?= $isTerminalNegative ? 'is-cancelled' : ($currentStep > 2 ? 'is-complete' : ($currentStep === 2 ? 'is-active' : '')) ?>">
              <div class="step-node">
                <?php if ($isTerminalNegative): ?>
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                <?php elseif ($currentStep > 2): ?>
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polyline points="20 6 9 17 4 12"/></svg>
                <?php else: ?>
                  <span>2</span>
                <?php endif; ?>
              </div>
              <div class="step-label">2. Staff Review</div>
              <div class="step-subtext"><?= $isTerminalNegative ? ucfirst($reservation['status']) : ($reservation['status'] === 'confirmed' ? 'Approved' : 'Awaiting Review') ?></div>
            </div>

            <!-- Step 3: Turnover & Handover -->
            <div class="stepper-step <?= $currentStep > 3 ? 'is-complete' : ($currentStep === 3 ? 'is-active' : '') ?>">
              <div class="step-node">
                <?php if ($currentStep > 3): ?>
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polyline points="20 6 9 17 4 12"/></svg>
                <?php else: ?>
                  <span>3</span>
                <?php endif; ?>
              </div>
              <div class="step-label">3. Turnover</div>
              <div class="step-subtext"><?= $pickupRecord ? 'Key Handover Done' : ($reservation['status'] === 'confirmed' ? ($isPaid ? 'Ready for Handover' : 'Deposit/Payment Due') : 'Pending Review') ?></div>
            </div>

            <!-- Step 4: Active Journey -->
            <div class="stepper-step <?= $currentStep > 4 ? 'is-complete' : ($currentStep === 4 ? 'is-active' : '') ?>">
              <div class="step-node">
                <?php if ($currentStep > 4): ?>
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polyline points="20 6 9 17 4 12"/></svg>
                <?php else: ?>
                  <span>4</span>
                <?php endif; ?>
              </div>
              <div class="step-label">4. Active Journey</div>
              <div class="step-subtext"><?= $reservation['rental_status'] === 'ongoing' ? 'On the Road' : ($currentStep > 4 ? 'Returned to Hub' : 'Scheduled') ?></div>
            </div>

            <!-- Step 5: Return Inspection & Settlement -->
            <div class="stepper-step <?= $currentStep === 5 && $reservation['rental_status'] === 'completed' ? 'is-complete' : ($currentStep === 5 ? 'is-active' : '') ?>">
              <div class="step-node">
                <?php if ($reservation['rental_status'] === 'completed'): ?>
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8"><polyline points="20 6 9 17 4 12"/></svg>
                <?php else: ?>
                  <span>5</span>
                <?php endif; ?>
              </div>
              <div class="step-label">5. Settlement</div>
              <div class="step-subtext"><?= $reservation['rental_status'] === 'completed' ? 'Finalized & Closed' : ($returnRecord ? 'Inspection Review' : 'Scheduled Return') ?></div>
            </div>
          </div>
        </section>

        <!-- 2-Column Responsive Layout -->
        <div class="rental-detail-portal-layout">
          <!-- LEFT COLUMN: Vehicle & Schedule -->
          <div style="display:flex;flex-direction:column;gap:20px;">
            <!-- Vehicle Hero Media Card -->
            <section class="rental-hero-media-card">
              <div class="rental-hero-image-box">
                <img src="<?= e(vehicle_image($reservation['image'])) ?>" alt="<?= e($reservation['brand'] . ' ' . $reservation['model']) ?>" loading="lazy">
              </div>
              <div class="rental-hero-body">
                <div style="display:flex;justify-content:space-between;align-items:baseline;">
                  <strong style="font-size:1.1rem;color:#0f172a;"><?= e($reservation['brand'] . ' ' . $reservation['model']) ?></strong>
                  <span style="font-size:0.9rem;font-weight:800;color:#0f172a;">₱<?= number_format((float)$reservation['daily_rate'], 2) ?> <span style="font-size:0.75rem;font-weight:400;color:#64748b;">/ day</span></span>
                </div>

                <div class="rental-specs-pill-row">
                  <span class="spec-pill-item">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                    <span><?= (int)$reservation['seats'] ?> Seats</span>
                  </span>
                  <span class="spec-pill-item">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="6" x2="12" y2="12"/><line x1="12" y1="12" x2="16" y2="16"/></svg>
                    <span><?= e($reservation['transmission']) ?></span>
                  </span>
                  <span class="spec-pill-item">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="3" y1="22" x2="21" y2="22"/><line x1="6" y1="18" x2="6" y2="11"/><line x1="10" y1="18" x2="10" y2="11"/><line x1="14" y1="18" x2="14" y2="11"/><line x1="18" y1="18" x2="18" y2="11"/><polygon points="12 2 20 7 4 7"/></svg>
                    <span><?= e($reservation['fuel_type'] ?: 'Gasoline') ?></span>
                  </span>
                  <?php if ($reservation['plate_number']): ?>
                    <span class="spec-pill-item">
                      <span style="font-family:monospace;font-weight:800;color:#0f172a;">Plate: <?= e($reservation['plate_number']) ?></span>
                    </span>
                  <?php endif; ?>
                </div>
              </div>
            </section>

            <!-- Schedule & Itinerary Card -->
            <section class="rental-section-box" style="margin-top:0;">
              <h2 style="font-size:1.1rem;font-weight:800;color:#0f172a;margin:0 0 4px;">Trip Schedule & Locations</h2>
              <p style="font-size:0.82rem;color:#64748b;margin:0;">Dispatched through authorized VELO regional operations hubs.</p>

              <div class="rental-timeline-row">
                <div class="timeline-point">
                  <dt>Pickup Date & Time</dt>
                  <dd><?= e(date('M j, Y · g:i A', strtotime($reservation['requested_pickup_at']))) ?></dd>
                  <div class="timeline-location-sub">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    <span><?= e($reservation['pickup_location']) ?></span>
                  </div>
                </div>

                <div class="timeline-point">
                  <dt>Return Date & Time (<?= $days ?> day<?= $days === 1 ? '' : 's' ?>)</dt>
                  <dd><?= e(date('M j, Y · g:i A', strtotime($reservation['requested_return_at']))) ?></dd>
                  <div class="timeline-location-sub">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    <span><?= e($reservation['return_location']) ?></span>
                  </div>
                </div>
              </div>

              <?php
                $protInfo = get_protection_plan_info($reservation['protection_tier'] ?? 'basic');
              ?>
              <div style="margin-top:16px;padding:12px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
                <div>
                  <span style="font-size:0.72rem;font-weight:800;text-transform:uppercase;color:#64748b;display:block;">Selected Protection Tier</span>
                  <strong style="font-size:0.9rem;color:#0f172a;"><?= e($protInfo['name']) ?></strong>
                  <span style="font-size:0.75rem;color:#64748b;display:block;"><?= e($protInfo['desc']) ?></span>
                </div>
                <?php if ((float)($reservation['security_deposit_amount'] ?? 0) > 0): ?>
                  <div style="text-align:right;">
                    <span style="font-size:0.72rem;font-weight:800;text-transform:uppercase;color:#64748b;display:block;">Security Deposit</span>
                    <strong style="font-size:0.9rem;color:#0f172a;">₱<?= number_format((float)$reservation['security_deposit_amount']) ?></strong>
                    <span class="portal-badge badge-neutral" style="font-size:0.68rem;padding:2px 6px;">
                      <?= e(ucfirst($reservation['security_deposit_status'] ?: 'Held')) ?>
                    </span>
                  </div>
                <?php endif; ?>
              </div>

              <?php if ($reservation['purpose']): ?>
                <div style="margin-top:16px;padding-top:14px;border-top:1px solid #f1f5f9;">
                  <span style="font-size:0.74rem;font-weight:800;text-transform:uppercase;color:#94a3b8;display:block;margin-bottom:4px;">Stated Rental Purpose</span>
                  <p style="margin:0;font-size:0.86rem;color:#334155;"><?= e($reservation['purpose']) ?></p>
                </div>
              <?php endif; ?>

              <?php if ($reservation['rejection_reason']): ?>
                <div class="portal-alert-danger" style="margin-top:16px;">
                  <span class="alert-icon" aria-hidden="true">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                  </span>
                  <div>
                    <strong>Rejection Notice:</strong>
                    <p style="margin:2px 0 0;font-size:0.84rem;"><?= e($reservation['rejection_reason']) ?></p>
                  </div>
                </div>
              <?php endif; ?>
            </section>

            <!-- Turnover & Active Journey Operations Box -->
            <?php if ($pickupRecord): ?>
              <section class="rental-section-box" style="margin-top:0;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:12px;">
                  <div>
                    <h2 style="font-size:1.1rem;font-weight:800;color:#0f172a;margin:0 0 4px;">Vehicle Handover &amp; Turnover Log</h2>
                    <p style="font-size:0.82rem;color:#64748b;margin:0;">Official vehicle inspection and dispatch log recorded at turnover.</p>
                  </div>
                  <span class="portal-badge badge-success">Turnover Completed</span>
                </div>

                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(130px, 1fr));gap:12px;background:#f8fafc;padding:14px;border-radius:10px;border:1px solid #e2e8f0;">
                  <div>
                    <span style="font-size:0.72rem;color:#64748b;text-transform:uppercase;font-weight:700;display:block;">Starting Mileage</span>
                    <strong style="font-size:0.95rem;color:#0f172a;"><?= number_format((int)$pickupRecord['starting_mileage']) ?> km</strong>
                  </div>
                  <div>
                    <span style="font-size:0.72rem;color:#64748b;text-transform:uppercase;font-weight:700;display:block;">Fuel Level</span>
                    <strong style="font-size:0.95rem;color:#0f172a;"><?= number_format((float)$pickupRecord['starting_fuel_level'], 1) ?>%</strong>
                  </div>
                  <div>
                    <span style="font-size:0.72rem;color:#64748b;text-transform:uppercase;font-weight:700;display:block;">Dispatched At</span>
                    <strong style="font-size:0.88rem;color:#0f172a;"><?= date('M j, Y g:i A', strtotime($pickupRecord['picked_up_at'])) ?></strong>
                  </div>
                </div>

                <?php if ($pickupRecord['condition_notes']): ?>
                  <div style="margin-top:12px;padding:10px 12px;background:#f1f5f9;border-radius:8px;font-size:0.82rem;color:#334155;">
                    <strong style="color:#0f172a;display:block;margin-bottom:2px;">Turnover Inspector Notes:</strong>
                    <?= e($pickupRecord['condition_notes']) ?>
                  </div>
                <?php endif; ?>

                <?php if ($reservation['rental_status'] === 'ongoing'): ?>
                  <!-- Active Journey Assistance Banner -->
                  <div style="margin-top:16px;padding:14px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:10px;">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                      <strong style="color:#065f46;font-size:0.88rem;">24/7 Roadside Assistance &amp; Fleet Operations Hotline</strong>
                    </div>
                    <p style="margin:0 0 8px;font-size:0.82rem;color:#047857;">For vehicle queries, return schedule adjustments, or roadside emergencies, contact dispatch:</p>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;font-size:0.84rem;font-weight:700;color:#065f46;">
                      <span>Hotline: (02) 8888-VELO</span>
                      <span>Mobile: +63 917 835 6835</span>
                    </div>
                  </div>
                <?php endif; ?>
              </section>
            <?php endif; ?>

            <!-- Return Inspection Summary Box -->
            <?php if ($returnRecord): ?>
              <section class="rental-section-box" style="margin-top:0;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:12px;">
                  <div>
                    <h2 style="font-size:1.1rem;font-weight:800;color:#0f172a;margin:0 0 4px;">Return Inspection &amp; Check-in Log</h2>
                    <p style="font-size:0.82rem;color:#64748b;margin:0;">Verified vehicle condition at check-in return.</p>
                  </div>
                  <span class="portal-badge badge-neutral">Return Recorded</span>
                </div>

                <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(130px, 1fr));gap:12px;background:#f8fafc;padding:14px;border-radius:10px;border:1px solid #e2e8f0;">
                  <div>
                    <span style="font-size:0.72rem;color:#64748b;text-transform:uppercase;font-weight:700;display:block;">Final Mileage</span>
                    <strong style="font-size:0.95rem;color:#0f172a;"><?= number_format((int)$returnRecord['ending_mileage']) ?> km</strong>
                  </div>
                  <div>
                    <span style="font-size:0.72rem;color:#64748b;text-transform:uppercase;font-weight:700;display:block;">Return Fuel</span>
                    <strong style="font-size:0.95rem;color:#0f172a;"><?= number_format((float)$returnRecord['ending_fuel_level'], 1) ?>%</strong>
                  </div>
                  <div>
                    <span style="font-size:0.72rem;color:#64748b;text-transform:uppercase;font-weight:700;display:block;">Returned At</span>
                    <strong style="font-size:0.88rem;color:#0f172a;"><?= date('M j, Y g:i A', strtotime($returnRecord['returned_at'])) ?></strong>
                  </div>
                </div>

                <?php if ($returnRecord['condition_notes']): ?>
                  <div style="margin-top:12px;padding:10px 12px;background:#f1f5f9;border-radius:8px;font-size:0.82rem;color:#334155;">
                    <strong style="color:#0f172a;display:block;margin-bottom:2px;">Check-in Inspector Notes:</strong>
                    <?= e($returnRecord['condition_notes']) ?>
                  </div>
                <?php endif; ?>
              </section>
            <?php endif; ?>

            <!-- Cancellation Handling -->
            <?php if (in_array($reservation['status'], ['pending', 'confirmed'], true)): ?>
              <section class="rental-section-box" style="margin-top:0;">
                <h2 style="font-size:1.1rem;font-weight:800;color:#0f172a;margin:0 0 4px;">Cancellation Request</h2>

                <?php if ($cancellationRequest && $cancellationRequest['status'] === 'requested'): ?>
                  <div class="portal-alert-warning" style="margin-top:12px;">
                    <span class="alert-icon" aria-hidden="true">
                      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    </span>
                    <div>
                      <strong>Cancellation Under Administrative Review</strong>
                      <p style="margin:3px 0 0;font-size:0.82rem;">
                        Reason provided: "<?= e($cancellationRequest['reason']) ?>" submitted on <?= e(date('M j, Y g:i A', strtotime($cancellationRequest['requested_at']))) ?>.
                      </p>
                    </div>
                  </div>
                <?php else: ?>
                  <p style="font-size:0.82rem;color:#64748b;margin:0 0 14px;">
                    If your schedule changes, you may request cancellation before vehicle dispatch. An administrator or desk staff member will verify your request.
                  </p>
                  <form method="post">
                    <input type="hidden" name="action" value="request_cancellation">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <div class="settings-field-group">
                      <label for="cancellation-reason">Reason for Cancellation</label>
                      <input id="cancellation-reason" type="text" name="reason" maxlength="255" required placeholder="e.g. Schedule conflict, changed destination, flight cancellation">
                    </div>
                    <button type="submit" class="portal-btn-outline" style="margin-top:12px;color:#dc2626;border-color:#fca5a5;">
                      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                      <span>Submit Cancellation Request</span>
                    </button>
                  </form>
                <?php endif; ?>
              </section>
            <?php endif; ?>
          </div>

          <!-- RIGHT COLUMN: Financial Ledger & Payment Callout -->
          <div style="display:flex;flex-direction:column;gap:20px;">
            <!-- Financial Ledger Card -->
            <section class="financial-ledger-card">
              <div class="ledger-summary-header">
                <span style="font-size:0.74rem;font-weight:800;letter-spacing:0.06em;text-transform:uppercase;color:#94a3b8;display:block;margin-bottom:4px;">Billing & Settlement</span>
                <h2 style="font-size:1.2rem;font-weight:800;color:#0f172a;margin:0;">Payment Ledger</h2>
              </div>

              <!-- Itemized Rows -->
              <div class="ledger-row">
                <span>Base Daily Rental (<?= $days ?> day<?= $days === 1 ? '' : 's' ?>)</span>
                <span>₱<?= number_format((float)$reservation['base_amount'], 2) ?></span>
              </div>

              <?php
              $additionalCharges = array_filter($chargesList, static fn($c) => $c['charge_type'] !== 'rental');
              foreach ($additionalCharges as $extra):
              ?>
                <div class="ledger-row">
                  <span><?= e($extra['description'] ?: ucfirst(str_replace('_', ' ', $extra['charge_type']))) ?></span>
                  <span>₱<?= number_format((float)$extra['amount'], 2) ?></span>
                </div>
              <?php endforeach; ?>

              <div class="ledger-row total-row">
                <span>Total Assessed</span>
                <span>₱<?= number_format($balance['charged'] > 0 ? $balance['charged'] : (float)$reservation['total_amount'], 2) ?></span>
              </div>

              <div class="ledger-row">
                <span style="color:#065f46;font-weight:700;">Total Payments Settled</span>
                <span style="color:#065f46;font-weight:800;">- ₱<?= number_format($balance['paid'], 2) ?></span>
              </div>

              <div class="ledger-row balance-row <?= $isPaid ? 'paid-zero' : '' ?>">
                <div>
                  <strong style="display:block;font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;margin-bottom:2px;">Outstanding Balance</strong>
                  <span style="font-size:0.78rem;color:#64748b;">
                    <?= $isPaid ? 'Fully settled and confirmed' : 'Required before vehicle turnover' ?>
                  </span>
                </div>
                <strong>₱<?= number_format($balance['outstanding'], 2) ?></strong>
              </div>

              <!-- Payment Action Box -->
              <div style="margin-top:20px;padding-top:16px;border-top:1px solid #f1f5f9;">
                <?php if ($reservation['status'] === 'confirmed' && !$isPaid && $isOnline): ?>
                  <div class="portal-alert-info" style="margin-bottom:14px;">
                    <span class="alert-icon" aria-hidden="true">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                    </span>
                    <span><strong>Reservation Approved:</strong> Staff confirmed your request. Please complete online payment to guarantee vehicle dispatch.</span>
                  </div>

                  <a href="/online-checkout.php?reservation_id=<?= (int)$reservation['id'] ?>" class="portal-btn-primary auth-submit-btn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                    <span><?= $reservation['payment_method'] === 'online' ? 'Continue to Online Payment' : 'Pay Securely Online' ?></span>
                  </a>
                <?php elseif ($isPaid): ?>
                  <div class="portal-alert-success">
                    <span class="alert-icon" aria-hidden="true">
                      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    </span>
                    <div>
                      <strong>Payment Verified & Paid in Full</strong>
                      <p style="margin:2px 0 0;font-size:0.82rem;">Official electronic receipt generated.</p>
                    </div>
                  </div>

                  <a href="/payment-return.php?provider=<?= e($reservation['payment_method']) ?>&reservation=<?= (int)$reservation['id'] ?>" class="portal-btn-outline auth-submit-btn" style="margin-top:12px;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                    <span>View & Print Official Receipt</span>
                  </a>
                <?php elseif (!$isPaid && $reservation['payment_method'] === 'cash'): ?>
                  <div class="portal-alert-neutral">
                    <span class="alert-icon" aria-hidden="true">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                    </span>
                    <div>
                      <strong>Cash on Pickup Selected</strong>
                      <p style="margin:2px 0 0;font-size:0.82rem;">Please present the remaining balance of ₱<?= number_format($balance['outstanding'], 2) ?> at the hub counter during vehicle pickup.</p>
                    </div>
                  </div>
                <?php elseif (!$isPaid && $reservation['payment_method'] === 'bank_transfer'): ?>
                  <div class="portal-alert-neutral">
                    <span class="alert-icon" aria-hidden="true">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                    </span>
                    <div>
                      <strong>Manual Bank Transfer Selected</strong>
                      <p style="margin:2px 0 0;font-size:0.82rem;">Present your electronic bank transfer confirmation or deposit slip at the pickup hub for verification.</p>
                    </div>
                  </div>
                <?php endif; ?>
              </div>
            </section>

            <!-- Verified Payments History Box -->
            <?php if (!empty($paymentsList)): ?>
              <section class="portal-card" style="padding:20px;">
                <h3 style="font-size:0.95rem;font-weight:800;color:#0f172a;margin:0 0 12px;">Payment Records</h3>
                <div style="display:flex;flex-direction:column;gap:10px;">
                  <?php foreach ($paymentsList as $pRecord): ?>
                    <div style="background:#f8fafc;padding:10px 12px;border-radius:8px;border:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:center;">
                      <div>
                        <strong style="display:block;font-size:0.84rem;font-family:monospace;color:#0f172a;"><?= e($pRecord['receipt_number'] ?: 'RCP-SETTLED') ?></strong>
                        <span style="font-size:0.74rem;color:#64748b;"><?= e(date('M j, Y g:i A', strtotime($pRecord['paid_at']))) ?> · <?= e(ucwords(str_replace('_', ' ', $pRecord['method']))) ?></span>
                      </div>
                      <div style="text-align:right;">
                        <strong style="color:#065f46;font-size:0.9rem;">₱<?= number_format((float)$pRecord['amount'], 2) ?></strong>
                        <a href="/payment-return.php?provider=<?= e($pRecord['method']) ?>&reservation=<?= (int)$reservation['id'] ?>" style="display:block;font-size:0.72rem;color:#0f172a;text-decoration:underline;">Receipt</a>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </section>
            <?php endif; ?>
          </div>
        </div>

        <!-- Payment History -->
        <section class="portal-card" aria-label="Payment history">
          <div class="portal-card-header">
            <div class="portal-card-title-group">
              <h2>Payment History</h2>
              <p>All payments recorded against this booking.</p>
            </div>
            <?php if ($balance['outstanding'] > 0.0001): ?>
              <span style="background:#fef9c3; color:#854d0e; border:1px solid #fde68a; border-radius:8px; padding:5px 12px; font-size:0.80rem; font-weight:700;">
                Outstanding: &#8369;<?= number_format($balance['outstanding'], 2) ?>
              </span>
            <?php else: ?>
              <span style="background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; border-radius:8px; padding:5px 12px; font-size:0.80rem; font-weight:700;">
                Fully Paid
              </span>
            <?php endif; ?>
          </div>

          <!-- Balance Summary Row -->
          <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:12px; margin-bottom:20px;">
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px 16px; text-align:center;">
              <div style="font-size:0.70rem; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:4px;">Total Charged</div>
              <div style="font-size:1.10rem; font-weight:800; color:#0f172a;">&#8369;<?= number_format($balance['charged'] > 0 ? $balance['charged'] : (float)$reservation['total_amount'], 2) ?></div>
            </div>
            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px 16px; text-align:center;">
              <div style="font-size:0.70rem; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:4px;">Total Paid</div>
              <div style="font-size:1.10rem; font-weight:800; color:#059669;">&#8369;<?= number_format($balance['paid'], 2) ?></div>
            </div>
            <div style="background:<?= $balance['outstanding'] > 0.0001 ? '#fefce8' : '#ecfdf5' ?>; border:1px solid <?= $balance['outstanding'] > 0.0001 ? '#fde68a' : '#a7f3d0' ?>; border-radius:10px; padding:14px 16px; text-align:center;">
              <div style="font-size:0.70rem; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:0.05em; margin-bottom:4px;">Outstanding</div>
              <div style="font-size:1.10rem; font-weight:800; color:<?= $balance['outstanding'] > 0.0001 ? '#92400e' : '#065f46' ?>;">&#8369;<?= number_format($balance['outstanding'], 2) ?></div>
            </div>
          </div>

          <?php if (empty($paymentsList)): ?>
            <p style="text-align:center; color:#94a3b8; font-size:0.86rem; padding:24px 0;">No payments have been recorded for this booking yet.</p>
          <?php else: ?>
            <div class="portal-table-container">
              <table class="portal-table">
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Method</th>
                    <th>Amount</th>
                    <th>Status</th>
                    <th>Notes</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($paymentsList as $pmt): ?>
                    <tr>
                      <td style="font-size:0.82rem; color:#334155;"><?= e(date('M j, Y g:i A', strtotime($pmt['paid_at'] ?? $pmt['created_at']))) ?></td>
                      <td>
                        <span style="font-size:0.82rem; font-weight:600; text-transform:capitalize; color:#1e293b;">
                          <?= e(str_replace('_', ' ', $pmt['method'] ?? 'Cash')) ?>
                        </span>
                      </td>
                      <td style="font-weight:700; color:#0f172a;">&#8369;<?= number_format((float)$pmt['amount'], 2) ?></td>
                      <td>
                        <?php if (($pmt['status'] ?? '') === 'paid' || ($pmt['status'] ?? '') === 'completed'): ?>
                          <span class="portal-badge badge-success">Paid</span>
                        <?php elseif (($pmt['status'] ?? '') === 'pending'): ?>
                          <span class="portal-badge badge-warning">Pending</span>
                        <?php else: ?>
                          <span class="portal-badge badge-neutral"><?= e(ucfirst($pmt['status'] ?? 'Recorded')) ?></span>
                        <?php endif; ?>
                      </td>
                      <td style="font-size:0.80rem; color:#64748b;"><?= e($pmt['notes'] ?? '') ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>

          <?php
            $additionalChargesForBreakdown = array_filter($chargesList, static fn($c) => $c['charge_type'] !== 'rental');
          ?>
          <?php if (!empty($additionalChargesForBreakdown)): ?>
            <div style="margin-top:16px; padding-top:14px; border-top:1px solid #f1f5f9;">
              <p style="font-size:0.74rem; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:0.05em; margin:0 0 10px;">Charge Breakdown</p>
              <?php foreach ($additionalChargesForBreakdown as $chg): ?>
                <div style="display:flex; justify-content:space-between; align-items:center; padding:6px 0; border-bottom:1px solid #f8fafc; font-size:0.84rem;">
                  <span style="color:#334155;"><?= e(ucfirst(str_replace('_', ' ', $chg['charge_type']))) ?><?= ($chg['description'] ?? '') ? ' — ' . e($chg['description']) : '' ?></span>
                  <span style="font-weight:700; color:#0f172a;">&#8369;<?= number_format((float)$chg['amount'], 2) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>
      <?php endif; ?>
    </div>
  </div>
</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
