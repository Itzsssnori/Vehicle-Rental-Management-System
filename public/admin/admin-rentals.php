<?php
$title = 'Reservation Oversight & Financial Ledger';
require_once __DIR__ . '/../../config.php';
$user = require_role('admin');

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    $reservationId = filter_var($_POST['reservation_id'] ?? null, FILTER_VALIDATE_INT);
    $rentalId = filter_var($_POST['rental_id'] ?? null, FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';
    try {
        if (!in_array($action, ['approve', 'reject', 'approve_cancel', 'reject_cancel', 'charge_damage', 'waive_damage', 'approve_penalty', 'waive_penalty', 'refund_payment', 'adjust_balance', 'suspend_customer', 'archive_customer', 'refer_incident'], true)) {
            throw new RuntimeException('Invalid reservation action.');
        }
        $pdo = db();
        $pdo->beginTransaction();

        if ($action === 'suspend_customer') {
            $incidentId = (int)($_POST['incident_id'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');
            if (!$incidentId || $reason === '') {
                throw new RuntimeException('Enter a reason for suspending the customer.');
            }
            $incident = $pdo->prepare(
                "SELECT i.*, r.customer_id FROM rental_incidents i
                 INNER JOIN rentals r ON r.id = i.rental_id
                 WHERE i.id = ? FOR UPDATE"
            );
            $incident->execute([$incidentId]);
            $incidentRow = $incident->fetch();
            if (!$incidentRow) {
                throw new RuntimeException('Return incident not found.');
            }
            mark_customer_suspended($pdo, (int)$incidentRow['customer_id'], (int)$user['id'], $reason);
            $pdo->prepare("UPDATE rental_incidents SET status = 'escalated', reviewed_by = ?, resolution = ? WHERE id = ?")->execute([$user['id'], $reason, $incidentId]);
            write_audit($pdo, (int)$user['id'], 'customer_suspended_non_return', 'rental_incidents', $incidentId, $reason);
            notify_user($pdo, (int)$incidentRow['customer_id'], 'Account suspended', 'Your account has been suspended while a vehicle return incident is reviewed.', null);
            $pdo->commit();
            $message = 'Customer account suspended and incident escalated.';
            goto render_reservations;
        }

        if ($action === 'archive_customer') {
            $incidentId = (int)($_POST['incident_id'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');
            if (!$incidentId || $reason === '') {
                throw new RuntimeException('Enter a reason for archiving the customer account.');
            }
            $incident = $pdo->prepare(
                "SELECT i.*, r.customer_id FROM rental_incidents i
                 INNER JOIN rentals r ON r.id = i.rental_id
                 WHERE i.id = ? FOR UPDATE"
            );
            $incident->execute([$incidentId]);
            $incidentRow = $incident->fetch();
            if (!$incidentRow) {
                throw new RuntimeException('Return incident not found.');
            }
            mark_customer_archived($pdo, (int)$incidentRow['customer_id'], (int)$user['id'], $reason);
            $pdo->prepare("UPDATE rental_incidents SET status = 'escalated', reviewed_by = ?, resolution = ? WHERE id = ?")->execute([$user['id'], 'Account archived: ' . $reason, $incidentId]);
            write_audit($pdo, (int)$user['id'], 'customer_archived_non_return', 'rental_incidents', $incidentId, $reason);
            notify_user($pdo, (int)$incidentRow['customer_id'], 'Account archived', 'Your account has been deactivated and archived following an unreturned vehicle incident.', null);
            $pdo->commit();
            $message = 'Customer account permanently archived and deactivated for unreturned vehicle incident.';
            goto render_reservations;
        }

        if ($action === 'refer_incident') {
            $incidentId = (int)($_POST['incident_id'] ?? 0);
            $type = $_POST['escalation_type'] ?? '';
            $reason = trim($_POST['reason'] ?? '');
            if (!$incidentId || $reason === '' || !in_array($type, ['institutional_review', 'security_review', 'legal_review'], true)) {
                throw new RuntimeException('Enter a valid escalation type and reason.');
            }
            $incident = $pdo->prepare("SELECT id FROM rental_incidents WHERE id = ? FOR UPDATE");
            $incident->execute([$incidentId]);
            if (!$incident->fetchColumn()) {
                throw new RuntimeException('Return incident not found.');
            }
            $referral = $pdo->prepare('INSERT INTO incident_escalations (incident_id, escalation_type, reason, referred_by) VALUES (?, ?, ?, ?)');
            $referral->execute([$incidentId, $type, $reason, $user['id']]);
            $pdo->prepare("UPDATE rental_incidents SET status = 'escalated', reviewed_by = ? WHERE id = ?")->execute([$user['id'], $incidentId]);
            write_audit($pdo, (int)$user['id'], 'return_incident_referred', 'rental_incidents', $incidentId, $type . ': ' . $reason);
            $pdo->commit();
            $message = 'Incident referral successfully recorded.';
            goto render_reservations;
        }

        if (in_array($action, ['charge_damage', 'waive_damage'], true)) {
            if (!$rentalId) {
                throw new RuntimeException('A rental record is required for damage review.');
            }
            $damage = $pdo->prepare(
                "SELECT d.*, r.reservation_id, r.customer_id, u.name AS customer_name
                 FROM damage_reports d
                 INNER JOIN rentals r ON r.id = d.rental_id
                 INNER JOIN users u ON u.id = r.customer_id
                 WHERE d.id = ? AND d.rental_id = ? AND d.status IN ('reported', 'under_review')
                 FOR UPDATE"
            );
            $damage->execute([(int)($_POST['damage_id'] ?? 0), $rentalId]);
            $damageReport = $damage->fetch();
            if (!$damageReport) {
                throw new RuntimeException('This damage report is no longer available for review.');
            }
            $reason = trim($_POST['reason'] ?? '');
            if ($action === 'waive_damage' && $reason === '') {
                throw new RuntimeException('Enter a reason when waiving a damage report.');
            }
            if ($action === 'charge_damage') {
                $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);
                if ($amount === false || $amount <= 0) {
                    throw new RuntimeException('Enter a valid damage charge amount.');
                }
                $existing = $pdo->prepare("SELECT id FROM rental_charges WHERE rental_id = ? AND charge_type = 'damage' LIMIT 1");
                $existing->execute([$rentalId]);
                if ($existing->fetchColumn()) {
                    throw new RuntimeException('A damage charge already exists for this rental.');
                }
                $charge = $pdo->prepare(
                    "INSERT INTO rental_charges (rental_id, reservation_id, charge_type, description, quantity, unit_price, amount)
                     VALUES (?, ?, 'damage', ?, 1, ?, ?)"
                );
                $charge->execute([$rentalId, $damageReport['reservation_id'], $reason ?: 'Damage repair charge', $amount, $amount]);
                $status = 'resolved';
                $message = 'Damage assessment charge created: ₱' . number_format($amount, 2);
                $notification = 'A damage charge of ₱' . number_format($amount, 2) . ' was assessed after administrative review.';
            } else {
                $status = 'waived';
                $message = 'Damage report waived with note.';
                $notification = 'Your reported damage assessment was reviewed and waived by administration.';
            }
            $updateDamage = $pdo->prepare('UPDATE damage_reports SET status = ? WHERE id = ?');
            $updateDamage->execute([$status, $damageReport['id']]);
            notify_user($pdo, (int)$damageReport['customer_id'], 'Damage report reviewed', $notification, $rentalId);
            write_audit($pdo, (int)$user['id'], 'damage_' . ($action === 'charge_damage' ? 'charged' : 'waived'), 'damage_reports', (int)$damageReport['id'], $reason ?: $notification);
            $pdo->commit();
            goto render_reservations;
        }

        if (in_array($action, ['approve_penalty', 'waive_penalty'], true)) {
            $penalty = $pdo->prepare(
                "SELECT * FROM penalty_events
                 WHERE id = ? AND status = 'pending'
                 FOR UPDATE"
            );
            $penalty->execute([(int)($_POST['penalty_id'] ?? 0)]);
            $penaltyEvent = $penalty->fetch();
            if (!$penaltyEvent) {
                throw new RuntimeException('This penalty event is no longer pending.');
            }
            $reason = trim($_POST['reason'] ?? '');
            if ($action === 'waive_penalty' && $reason === '') {
                throw new RuntimeException('Enter a reason when waiving a penalty.');
            }
            $status = $action === 'approve_penalty' ? 'approved' : 'waived';
            $update = $pdo->prepare('UPDATE penalty_events SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?');
            $update->execute([$status, $user['id'], $penaltyEvent['id']]);
            write_audit($pdo, (int)$user['id'], 'penalty_' . ($action === 'approve_penalty' ? 'approved' : 'waived'), 'penalty_events', (int)$penaltyEvent['id'], $reason ?: $penaltyEvent['reason']);
            notify_user($pdo, (int)$penaltyEvent['user_id'], 'Penalty event reviewed', $status === 'approved' ? 'A penalty event was approved on your account.' : 'A penalty event was waived on your account.', $penaltyEvent['rental_id'] ? (int)$penaltyEvent['rental_id'] : null);
            $pdo->commit();
            $message = 'Penalty event ' . $status . '.';
            goto render_reservations;
        }

        if ($action === 'refund_payment') {
            $paymentId = (int)($_POST['payment_id'] ?? 0);
            $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);
            $reason = trim($_POST['reason'] ?? '');
            $method = $_POST['method'] ?? '';
            if (!$paymentId || $amount === false || $amount <= 0 || $reason === '' || !in_array($method, ['cash', 'bank_transfer', 'gcash', 'card', 'online'], true)) {
                throw new RuntimeException('Enter a valid refund amount, method, and reason.');
            }
            $payment = $pdo->prepare(
                'SELECT p.*, COALESCE((SELECT SUM(amount) FROM refunds WHERE payment_id = p.id), 0) AS refunded
                 FROM payments p WHERE p.id = ? FOR UPDATE'
            );
            $payment->execute([$paymentId]);
            $paymentRow = $payment->fetch();
            if (!$paymentRow || $amount > (float)$paymentRow['amount'] - (float)$paymentRow['refunded'] + 0.0001) {
                throw new RuntimeException('Refund exceeds the remaining payment balance.');
            }
            $refund = $pdo->prepare('INSERT INTO refunds (payment_id, amount, reason, method, recorded_by) VALUES (?, ?, ?, ?, ?)');
            $refund->execute([$paymentId, $amount, $reason, $method === 'online' ? 'card' : $method, $user['id']]);
            write_audit($pdo, (int)$user['id'], 'payment_refunded', 'payments', $paymentId, $reason);
            $pdo->commit();
            $message = 'Refund of ₱' . number_format($amount, 2) . ' recorded successfully.';
            goto render_reservations;
        }

        if ($action === 'adjust_balance') {
            $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);
            $reason = trim($_POST['reason'] ?? '');
            $type = $_POST['adjustment_type'] ?? '';
            if ($amount === false || $amount <= 0 || $reason === '' || !in_array($type, ['credit', 'debit'], true) || !$reservationId) {
                throw new RuntimeException('Enter a valid adjustment amount, type, reason, and reservation.');
            }
            $rental = $pdo->prepare('SELECT id FROM rentals WHERE reservation_id = ?');
            $rental->execute([$reservationId]);
            $rentalIdForAdjustment = $rental->fetchColumn() ?: null;
            $adjustment = $pdo->prepare('INSERT INTO payment_adjustments (reservation_id, rental_id, adjustment_type, amount, reason, recorded_by) VALUES (?, ?, ?, ?, ?, ?)');
            $adjustment->execute([$reservationId, $rentalIdForAdjustment, $type, $amount, $reason, $user['id']]);
            write_audit($pdo, (int)$user['id'], 'payment_adjusted', 'payment_adjustments', (int)$pdo->lastInsertId(), $reason);
            $pdo->commit();
            $message = 'Balance adjustment recorded.';
            goto render_reservations;
        }

        if (!$reservationId) {
            throw new RuntimeException('A reservation ID is required.');
        }

        $stmt = $pdo->prepare(
            'SELECT r.*, v.status AS vehicle_status
             FROM reservations r INNER JOIN vehicles v ON v.id = r.vehicle_id
             WHERE r.id = ? FOR UPDATE'
        );
        $stmt->execute([$reservationId]);
        $reservation = $stmt->fetch();
        if (!$reservation || $reservation['status'] !== 'pending') {
            if (!in_array($action, ['approve_cancel', 'reject_cancel'], true)) {
                throw new RuntimeException('Only pending reservations can be reviewed.');
            }
        }

        if (in_array($action, ['approve_cancel', 'reject_cancel'], true)) {
            $cancel = $pdo->prepare("SELECT * FROM reservation_cancellation_requests WHERE reservation_id = ? AND status = 'requested' FOR UPDATE");
            $cancel->execute([$reservationId]);
            $request = $cancel->fetch();
            if (!$request || !in_array($reservation['status'], ['pending', 'confirmed'], true)) {
                throw new RuntimeException('No pending cancellation request exists.');
            }
            $reason = trim($_POST['reason'] ?? '');
            if ($action === 'reject_cancel' && $reason === '') {
                throw new RuntimeException('Enter a reason when rejecting a cancellation.');
            }
            $newStatus = $action === 'approve_cancel' ? 'cancelled' : $reservation['status'];
            $review = $pdo->prepare('UPDATE reservation_cancellation_requests SET status = ?, reviewed_by = ?, review_reason = ?, reviewed_at = NOW() WHERE id = ?');
            $review->execute([$action === 'approve_cancel' ? 'approved' : 'rejected', $user['id'], $reason ?: null, $request['id']]);
            if ($action === 'approve_cancel') {
                $pdo->prepare("UPDATE reservations SET status = 'cancelled' WHERE id = ?")->execute([$reservationId]);
                record_reservation_status($pdo, $reservationId, (int)$user['id'], 'cancelled', $reservation['status'], $reason ?: 'Cancellation approved.');
            }
            notify_reservation($pdo, (int)$reservation['customer_id'], 'Cancellation ' . ($action === 'approve_cancel' ? 'approved' : 'rejected'), $action === 'approve_cancel' ? 'Your cancellation was approved.' : 'Your cancellation was rejected: ' . $reason, $reservationId);
            write_audit($pdo, (int)$user['id'], 'cancellation_' . ($action === 'approve_cancel' ? 'approved' : 'rejected'), 'reservations', $reservationId, $reason);
            $pdo->commit();
            $message = 'Cancellation request reviewed.';
            goto render_reservations;
        }

        $newStatus = $action === 'approve' ? 'confirmed' : 'rejected';
        $reason = trim($_POST['reason'] ?? '');
        if ($newStatus === 'rejected' && $reason === '') {
            throw new RuntimeException('Enter a reason when rejecting a reservation.');
        }
        if ($newStatus === 'confirmed') {
            if ($reservation['vehicle_status'] !== 'operational') {
                throw new RuntimeException('This vehicle is not operational.');
            }
            $conflict = $pdo->prepare(
                "SELECT COUNT(*) FROM reservations
                 WHERE vehicle_id = ? AND id <> ? AND status = 'confirmed'
                 AND requested_pickup_at < ? AND requested_return_at > ?"
            );
            $conflict->execute([$reservation['vehicle_id'], $reservationId, $reservation['requested_return_at'], $reservation['requested_pickup_at']]);
            if ((int)$conflict->fetchColumn() > 0) {
                throw new RuntimeException('This reservation overlaps another confirmed reservation.');
            }
            $maintenance = $pdo->prepare(
                'SELECT COUNT(*) FROM maintenance
                 WHERE vehicle_id = ? AND maintenance_status IN ("scheduled","in_progress")
                 AND start_date <= DATE(?) AND (end_date IS NULL OR end_date >= DATE(?))'
            );
            $maintenance->execute([$reservation['vehicle_id'], $reservation['requested_return_at'], $reservation['requested_pickup_at']]);
            if ((int)$maintenance->fetchColumn() > 0) {
                throw new RuntimeException('This vehicle is scheduled for maintenance during the requested period.');
            }
        }

        $update = $pdo->prepare(
            'UPDATE reservations SET status = ?, rejection_reason = ?, approved_by = ?, approved_at = CASE WHEN ? = "confirmed" THEN NOW() ELSE NULL END WHERE id = ?'
        );
        $update->execute([$newStatus, $newStatus === 'rejected' ? $reason : null, $user['id'], $newStatus, $reservationId]);
        record_reservation_status($pdo, $reservationId, (int)$user['id'], $newStatus, 'pending', $reason ?: null);
        notify_reservation($pdo, (int)$reservation['customer_id'], 'Reservation ' . $newStatus, $newStatus === 'confirmed' ? 'Your reservation has been approved.' : 'Your reservation was rejected: ' . $reason, $reservationId);
        write_audit($pdo, (int)$user['id'], 'reservation_' . $newStatus, 'reservations', $reservationId, $reason ?: 'Administrator reviewed reservation.');
        $pdo->commit();
        $message = 'Reservation ' . $newStatus . ' successfully.';
    } catch (Throwable $exception) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Admin reservation action failed: ' . $exception->getMessage());
        $error = $exception->getMessage();
    }
}

render_reservations:

// Data queries
$reservations = db()->query(
    "SELECT r.id, r.reference_number, r.requested_pickup_at, r.requested_return_at,
            r.total_amount, r.status, r.created_at, u.name AS customer_name,
            u.email AS customer_email, v.brand, v.model,
            (SELECT id FROM reservation_cancellation_requests WHERE reservation_id = r.id AND status = 'requested' LIMIT 1) AS cancel_request_id,
            (SELECT reason FROM reservation_cancellation_requests WHERE reservation_id = r.id AND status = 'requested' LIMIT 1) AS cancel_reason
     FROM reservations r
     INNER JOIN users u ON u.id = r.customer_id
     INNER JOIN vehicles v ON v.id = r.vehicle_id
     ORDER BY r.status = 'pending' DESC, r.created_at DESC"
)->fetchAll();

$damageReports = db()->query(
    "SELECT d.id, d.rental_id, d.description, d.estimated_cost, d.created_at,
            r.reservation_id, res.reference_number, u.name AS customer_name,
            v.brand, v.model
     FROM damage_reports d
     INNER JOIN rentals r ON r.id = d.rental_id
     INNER JOIN reservations res ON res.id = r.reservation_id
     INNER JOIN users u ON u.id = r.customer_id
     INNER JOIN vehicles v ON v.id = r.vehicle_id
     WHERE d.status IN ('reported', 'under_review')
     ORDER BY d.created_at ASC"
)->fetchAll();

$penaltyEvents = db()->query(
    "SELECT p.id, p.user_id, p.event_type, p.points, p.reason, p.created_at,
            u.name AS customer_name, u.email AS customer_email
     FROM penalty_events p
     INNER JOIN users u ON u.id = p.user_id
     WHERE p.status = 'pending'
     ORDER BY p.created_at ASC"
)->fetchAll();

$payments = db()->query(
    "SELECT p.id, p.reservation_id, p.amount, p.method, p.reference_number, p.paid_at,
            pr.receipt_number, r.reference_number AS reservation_reference,
            u.name AS customer_name
     FROM payments p
     INNER JOIN reservations r ON r.id = p.reservation_id
     INNER JOIN users u ON u.id = r.customer_id
     LEFT JOIN payment_receipts pr ON pr.payment_id = p.id
     ORDER BY p.paid_at DESC
     LIMIT 50"
)->fetchAll();

$incidents = db()->query(
    "SELECT i.id, i.status, i.description, i.created_at, r.customer_id,
            res.reference_number, u.name AS customer_name, v.brand, v.model
     FROM rental_incidents i
     INNER JOIN rentals r ON r.id = i.rental_id
     INNER JOIN reservations res ON res.id = r.reservation_id
     INNER JOIN users u ON u.id = r.customer_id
     INNER JOIN vehicles v ON v.id = r.vehicle_id
     WHERE i.status IN ('open', 'contact_attempted', 'escalated')
     ORDER BY i.created_at ASC"
)->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<main class="portal-customer-page">
  <div class="portal-customer-layout">

    <!-- Unified Sidebar Navigation -->
    <?php include __DIR__ . '/../../includes/workspace-sidebar.php'; ?>

    <!-- Main Workspace Content -->
    <div class="customer-main-content">

      <!-- Hero Greeting Banner -->
      <section class="portal-hero-banner" aria-label="Oversight heading">
        <div class="portal-hero-text">
          <div class="portal-hero-eyebrow">
            <span class="pulse-indicator" aria-hidden="true"></span>
            <span>ADMINISTRATION · FINANCIAL SETTLEMENT & INTEGRITY</span>
          </div>
          <h1 class="portal-hero-title">Rental Oversight & Resolution</h1>
          <p class="portal-hero-subtitle">
            Authorize reservations, process cancellation requests, review reported damages, enforce demerit penalties, and record payment refunds.
          </p>
        </div>
        <div class="portal-hero-actions">
          <a href="/admin/admin-vehicles.php" class="btn btn-coral">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
            <span>Fleet Inventory</span>
          </a>
          <a href="/admin/admin-reports.php" class="btn btn-outline-white">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            <span>Financial Reports</span>
          </a>
          <a href="/admin/admin-dashboard.php" class="btn btn-outline-white">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
            <span>Overview</span>
          </a>
        </div>
      </section>

      <!-- Feedback notifications -->
      <?php if ($message): ?>
        <div class="portal-card" style="border-left: 4px solid #10b981; background: #ecfdf5; padding: 14px 18px; margin-bottom: 20px;" role="status">
          <div style="display:flex; align-items:center; gap:10px; color:#065f46; font-size:0.88rem; font-weight:600;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            <span><?= e($message) ?></span>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="portal-card" style="border-left: 4px solid #ef4444; background: #fef2f2; padding: 14px 18px; margin-bottom: 20px;" role="alert">
          <div style="display:flex; align-items:center; gap:10px; color:#991b1b; font-size:0.88rem; font-weight:600;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span><?= e($error) ?></span>
          </div>
        </div>
      <?php endif; ?>

      <!-- 1. RESERVATIONS & CANCELLATIONS SECTION -->
      <section class="portal-card" id="reservations-section" style="margin-bottom: 24px;" aria-label="Reservations ledger">
        <div class="portal-card-header">
          <div class="portal-card-title-group">
            <h2>Reservations & Cancellation Requests</h2>
            <p>Authorize incoming requests, verify fleet conflicts, or grant requested cancellations.</p>
          </div>
          <span class="portal-badge badge-neutral"><?= count($reservations) ?> Total Records</span>
        </div>

        <div class="portal-table-container">
          <table class="portal-table">
            <thead>
              <tr>
                <th>Reference</th>
                <th>Customer</th>
                <th>Vehicle</th>
                <th>Schedule Window</th>
                <th>Status</th>
                <th>Administrative Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($reservations)): ?>
                <tr>
                  <td colspan="6" style="text-align:center; padding:36px; color:#64748b;">No reservation records available.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($reservations as $res): ?>
                  <tr style="<?= $res['status'] === 'pending' ? 'background:#fffdfa;' : '' ?>">
                    <td>
                      <strong style="font-size:0.86rem; font-family:monospace; color:#0f172a;"><?= e($res['reference_number']) ?></strong>
                      <span style="font-size:0.72rem; color:#64748b; display:block;">₱<?= number_format((float)$res['total_amount'], 2) ?></span>
                    </td>

                    <td>
                      <strong style="display:block; font-size:0.86rem; color:#0f172a;"><?= e($res['customer_name']) ?></strong>
                      <span style="font-size:0.74rem; color:#64748b;"><?= e($res['customer_email']) ?></span>
                    </td>

                    <td>
                      <span style="font-size:0.84rem; font-weight:700; color:#1e293b;"><?= e($res['brand'] . ' ' . $res['model']) ?></span>
                    </td>

                    <td>
                      <span style="font-size:0.8rem; font-weight:600; color:#0f172a;"><?= e(date('M j, Y · g:i A', strtotime($res['requested_pickup_at']))) ?></span>
                      <span style="font-size:0.74rem; color:#64748b; display:block;">to <?= e(date('M j, Y · g:i A', strtotime($res['requested_return_at']))) ?></span>
                    </td>

                    <td>
                      <?php if ($res['status'] === 'confirmed'): ?>
                        <span class="portal-badge badge-success">Confirmed</span>
                      <?php elseif ($res['status'] === 'pending'): ?>
                        <span class="portal-badge badge-warning">Pending Review</span>
                      <?php elseif ($res['status'] === 'cancelled'): ?>
                        <span class="portal-badge badge-neutral">Cancelled</span>
                      <?php elseif ($res['status'] === 'rejected'): ?>
                        <span class="portal-badge badge-danger">Rejected</span>
                      <?php else: ?>
                        <span class="portal-badge badge-neutral"><?= e(ucfirst($res['status'])) ?></span>
                      <?php endif; ?>
                    </td>

                    <td>
                      <?php if ($res['status'] === 'pending'): ?>
                        <form method="post" style="display:flex; flex-direction:column; gap:6px;">
                          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                          <input type="hidden" name="reservation_id" value="<?= (int)$res['id'] ?>">
                          <div style="display:flex; gap:6px;">
                            <button class="btn btn-coral" name="action" value="approve" type="submit" style="padding: 5px 12px; font-size: 0.76rem;">Approve</button>
                            <input name="reason" maxlength="255" placeholder="Reason if rejecting..." style="padding: 4px 8px; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 0.76rem; width: 140px;">
                            <button class="btn btn-secondary" name="action" value="reject" type="submit" style="padding: 5px 10px; font-size: 0.76rem;" onclick="return confirm('Reject this reservation?');">Reject</button>
                          </div>
                        </form>
                      <?php elseif (!empty($res['cancel_request_id'])): ?>
                        <div style="background:#fff1f2; border:1px solid #fecaca; border-radius:8px; padding:8px 10px;">
                          <span style="font-size:0.72rem; font-weight:800; color:#b91c1c; display:block;">CANCELLATION REQUESTED</span>
                          <span style="font-size:0.74rem; color:#475569; display:block; margin:2px 0 6px;">Reason: <?= e($res['cancel_reason'] ?: 'No reason stated') ?></span>
                          <form method="post" style="display:flex; gap:6px;">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="reservation_id" value="<?= (int)$res['id'] ?>">
                            <button class="btn btn-coral" name="action" value="approve_cancel" type="submit" style="padding: 4px 10px; font-size: 0.74rem;">Approve</button>
                            <input name="reason" maxlength="255" placeholder="Reason if declining..." style="padding: 4px 6px; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 0.74rem; width: 120px;">
                            <button class="btn btn-secondary" name="action" value="reject_cancel" type="submit" style="padding: 4px 8px; font-size: 0.74rem;">Decline</button>
                          </form>
                        </div>
                      <?php else: ?>
                        <span style="font-size:0.76rem; color:#64748b;">Processed</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>

      <!-- 2. DAMAGE REPORTS SECTION -->
      <section class="portal-card" id="damage-section" style="margin-bottom: 24px;" aria-label="Damage review">
        <div class="portal-card-header">
          <div class="portal-card-title-group">
            <h2>Vehicle Damage Assessment Queue</h2>
            <p>Staff-recorded check-in damage reports awaiting administrative cost approval or waiver.</p>
          </div>
          <span class="portal-badge <?= count($damageReports) > 0 ? 'badge-danger' : 'badge-success' ?>">
            <?= count($damageReports) ?> Pending Review
          </span>
        </div>

        <div class="portal-table-container">
          <table class="portal-table">
            <thead>
              <tr>
                <th>Booking Ref</th>
                <th>Customer</th>
                <th>Vehicle</th>
                <th>Damage Description</th>
                <th>Estimated Cost</th>
                <th>Action Determination</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($damageReports)): ?>
                <tr>
                  <td colspan="6" style="text-align:center; padding:36px; color:#64748b;">No damage reports currently pending review.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($damageReports as $damage): ?>
                  <tr>
                    <td>
                      <strong style="font-size:0.86rem; font-family:monospace; color:#0f172a;"><?= e($damage['reference_number']) ?></strong>
                      <span style="font-size:0.72rem; color:#64748b; display:block;">Reported <?= e(date('M j, Y', strtotime($damage['created_at']))) ?></span>
                    </td>

                    <td>
                      <strong style="display:block; font-size:0.86rem; color:#0f172a;"><?= e($damage['customer_name']) ?></strong>
                    </td>

                    <td>
                      <span style="font-size:0.84rem; font-weight:700; color:#1e293b;"><?= e($damage['brand'] . ' ' . $damage['model']) ?></span>
                    </td>

                    <td>
                      <span style="font-size:0.84rem; color:#475569;"><?= e($damage['description']) ?></span>
                    </td>

                    <td>
                      <strong style="font-size:0.92rem; color:#b91c1c;">₱<?= number_format((float)$damage['estimated_cost'], 2) ?></strong>
                    </td>

                    <td>
                      <form method="post" style="display:flex; flex-direction:column; gap:6px;">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="rental_id" value="<?= (int)$damage['rental_id'] ?>">
                        <input type="hidden" name="damage_id" value="<?= (int)$damage['id'] ?>">
                        
                        <div style="display:flex; gap:6px;">
                          <input name="amount" type="number" min="0.01" step="0.01" value="<?= e((string)$damage['estimated_cost']) ?>" style="padding: 4px 8px; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 0.76rem; width: 85px;" title="Charge amount">
                          <input name="reason" maxlength="255" placeholder="Review note / repair description..." style="padding: 4px 8px; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 0.76rem; flex: 1;">
                        </div>

                        <div style="display:flex; gap:6px;">
                          <button class="btn btn-coral" name="action" value="charge_damage" type="submit" style="padding: 5px 12px; font-size: 0.76rem;">Assess Charge</button>
                          <button class="btn btn-secondary" name="action" value="waive_damage" type="submit" style="padding: 5px 10px; font-size: 0.76rem;" onclick="return confirm('Waive this damage report without charging?');">Waive Report</button>
                        </div>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>

      <!-- 3. PENALTY EVENTS SECTION -->
      <section class="portal-card" id="penalties-section" style="margin-bottom: 24px;" aria-label="Penalties queue">
        <div class="portal-card-header">
          <div class="portal-card-title-group">
            <h2>Pending Demerit Penalty Events</h2>
            <p>System-generated or staff-flagged customer infractions (late returns, policy violations).</p>
          </div>
          <span class="portal-badge <?= count($penaltyEvents) > 0 ? 'badge-warning' : 'badge-success' ?>">
            <?= count($penaltyEvents) ?> Pending Events
          </span>
        </div>

        <div class="portal-table-container">
          <table class="portal-table">
            <thead>
              <tr>
                <th>Customer Identity</th>
                <th>Infraction Type</th>
                <th>Demerit Points</th>
                <th>Description / Justification</th>
                <th>Action Determination</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($penaltyEvents)): ?>
                <tr>
                  <td colspan="5" style="text-align:center; padding:36px; color:#64748b;">No pending penalty events require review.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($penaltyEvents as $penalty): ?>
                  <tr>
                    <td>
                      <strong style="display:block; font-size:0.86rem; color:#0f172a;"><?= e($penalty['customer_name']) ?></strong>
                      <span style="font-size:0.74rem; color:#64748b;"><?= e($penalty['customer_email']) ?></span>
                    </td>

                    <td>
                      <span class="portal-badge badge-neutral"><?= e(ucwords(str_replace('_', ' ', $penalty['event_type']))) ?></span>
                    </td>

                    <td>
                      <strong style="font-size:0.92rem; color:#b91c1c;">+<?= (int)$penalty['points'] ?> pts</strong>
                    </td>

                    <td>
                      <span style="font-size:0.84rem; color:#475569;"><?= e($penalty['reason']) ?></span>
                    </td>

                    <td>
                      <form method="post" style="display:flex; gap:6px;">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="penalty_id" value="<?= (int)$penalty['id'] ?>">
                        <input name="reason" maxlength="255" placeholder="Reason if waiving..." style="padding: 4px 8px; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 0.76rem; width: 140px;">
                        <button class="btn btn-coral" name="action" value="approve_penalty" type="submit" style="padding: 5px 12px; font-size: 0.76rem;">Approve</button>
                        <button class="btn btn-secondary" name="action" value="waive_penalty" type="submit" style="padding: 5px 10px; font-size: 0.76rem;" onclick="return confirm('Waive this penalty event?');">Waive</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>

      <!-- 4. RETURN INCIDENTS SECTION -->
      <section class="portal-card" id="incidents-section" style="margin-bottom: 24px;" aria-label="Incidents queue">
        <div class="portal-card-header">
          <div class="portal-card-title-group">
            <h2>Open Return Incidents & Non-Returns</h2>
            <p>Escalated vehicle overdue cases, customer contact failures, or unreturned vehicles.</p>
          </div>
          <span class="portal-badge <?= count($incidents) > 0 ? 'badge-danger' : 'badge-success' ?>">
            <?= count($incidents) ?> Open Cases
          </span>
        </div>

        <div class="portal-table-container">
          <table class="portal-table">
            <thead>
              <tr>
                <th>Booking Ref</th>
                <th>Customer</th>
                <th>Vehicle Asset</th>
                <th>Status</th>
                <th>Administrative Escalation</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($incidents)): ?>
                <tr>
                  <td colspan="5" style="text-align:center; padding:36px; color:#64748b;">No active or open vehicle return incidents.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($incidents as $incident): ?>
                  <tr>
                    <td>
                      <strong style="font-size:0.86rem; font-family:monospace; color:#0f172a;"><?= e($incident['reference_number']) ?></strong>
                      <span style="font-size:0.72rem; color:#64748b; display:block;">Logged <?= e(date('M j, Y', strtotime($incident['created_at']))) ?></span>
                    </td>

                    <td>
                      <strong style="display:block; font-size:0.86rem; color:#0f172a;"><?= e($incident['customer_name']) ?></strong>
                    </td>

                    <td>
                      <span style="font-size:0.84rem; font-weight:700; color:#1e293b;"><?= e($incident['brand'] . ' ' . $incident['model']) ?></span>
                      <span style="font-size:0.74rem; color:#64748b; display:block;"><?= e($incident['description']) ?></span>
                    </td>

                    <td>
                      <span class="portal-badge badge-danger"><?= e(ucwords(str_replace('_', ' ', $incident['status']))) ?></span>
                    </td>

                    <td>
                      <form method="post" style="display:flex; flex-direction:column; gap:6px;">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="incident_id" value="<?= (int)$incident['id'] ?>">
                        
                        <input name="reason" maxlength="255" required placeholder="Decision justification..." style="padding: 4px 8px; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 0.76rem;">

                        <div style="display:flex; gap:6px; flex-wrap:wrap;">
                          <button class="btn btn-coral" name="action" value="suspend_customer" type="submit" style="padding: 5px 10px; font-size: 0.74rem;" onclick="return confirm('Suspend customer account for this incident?');">Suspend</button>
                          <button class="btn btn-secondary" name="action" value="archive_customer" type="submit" style="padding: 5px 10px; font-size: 0.74rem; color:#991b1b; border-color:#fecaca;" onclick="return confirm('Archive and deactivate customer account for unreturned vehicle?');">Archive</button>
                          
                          <select name="escalation_type" style="padding: 4px 6px; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 0.74rem; background: #ffffff;">
                            <option value="institutional_review">Institutional Review</option>
                            <option value="security_review">Campus Security</option>
                            <option value="legal_review">Legal Counsel</option>
                          </select>

                          <button class="btn btn-secondary" name="action" value="refer_incident" type="submit" style="padding: 5px 10px; font-size: 0.74rem;">Refer</button>
                        </div>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>

      <!-- 5. SETTLEMENT RECEIPTS & REFUNDS SECTION -->
      <section class="portal-card" id="payments-section" aria-label="Settlement ledger">
        <div class="portal-card-header">
          <div class="portal-card-title-group">
            <h2>Settlement Receipts & Refund Corrections</h2>
            <p>Audit payments, record formal customer refunds, or make manual ledger balance adjustments.</p>
          </div>
          <span class="portal-badge badge-neutral"><?= count($payments) ?> Payments</span>
        </div>

        <div class="portal-table-container">
          <table class="portal-table">
            <thead>
              <tr>
                <th>Receipt / Reference</th>
                <th>Customer</th>
                <th>Amount</th>
                <th>Method</th>
                <th>Paid Date</th>
                <th>Refund Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($payments)): ?>
                <tr>
                  <td colspan="6" style="text-align:center; padding:36px; color:#64748b;">No payment records logged in the system.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($payments as $payment): ?>
                  <tr>
                    <td>
                      <strong style="font-size:0.86rem; color:#0f172a;"><?= e($payment['receipt_number'] ?: 'RCP-TBD') ?></strong>
                      <span style="font-size:0.72rem; color:#64748b; display:block;">Booking: <?= e($payment['reservation_reference']) ?></span>
                    </td>

                    <td>
                      <span style="font-size:0.86rem; font-weight:600; color:#1e293b;"><?= e($payment['customer_name']) ?></span>
                    </td>

                    <td>
                      <strong style="font-size:0.92rem; color:#065f46;">₱<?= number_format((float)$payment['amount'], 2) ?></strong>
                    </td>

                    <td>
                      <span class="portal-badge badge-neutral"><?= e(ucwords(str_replace('_', ' ', $payment['method']))) ?></span>
                    </td>

                    <td>
                      <span style="font-size:0.8rem; color:#64748b;"><?= e(date('M j, Y · g:i A', strtotime($payment['paid_at']))) ?></span>
                    </td>

                    <td>
                      <form method="post" style="display:flex; gap:6px; align-items:center;">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="payment_id" value="<?= (int)$payment['id'] ?>">
                        <input type="hidden" name="method" value="<?= e($payment['method']) ?>">
                        <input name="amount" type="number" min="0.01" step="0.01" placeholder="Refund ₱" style="padding: 4px 6px; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 0.76rem; width: 80px;" required>
                        <input name="reason" maxlength="255" placeholder="Refund reason..." style="padding: 4px 8px; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 0.76rem; width: 130px;" required>
                        <button class="btn btn-secondary" name="action" value="refund_payment" type="submit" style="padding: 5px 10px; font-size: 0.76rem; color:#991b1b; border-color:#fecaca;" onclick="return confirm('Issue this refund?');">Refund</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>

    </div>
  </div>
</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
