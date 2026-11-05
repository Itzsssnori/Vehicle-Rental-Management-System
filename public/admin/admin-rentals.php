<?php
$title = 'Reservation Oversight';
require_once __DIR__ . '/../../config.php';
$user = require_role('admin');
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $reservationId = filter_input(INPUT_POST, 'reservation_id', FILTER_VALIDATE_INT);
    $rentalId = filter_input(INPUT_POST, 'rental_id', FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';
    try {
        if (!in_array($action, ['approve', 'reject', 'approve_cancel', 'reject_cancel', 'charge_damage', 'waive_damage', 'approve_penalty', 'waive_penalty', 'refund_payment', 'adjust_balance', 'suspend_customer', 'refer_incident'], true)) {
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
            $message = 'Incident referral recorded.';
            goto render_reservations;
        }
        if (in_array($action, ['charge_damage', 'waive_damage'], true)) {
            if (!$rentalId) {
                throw new RuntimeException('A rental is required for damage review.');
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
                $charge->execute([$rentalId, $damageReport['reservation_id'], $reason ?: 'Damage charge', $amount, $amount]);
                $status = 'resolved';
                $message = 'Damage charge created.';
                $notification = 'A damage charge of ₱' . number_format($amount, 2) . ' was added after review.';
            } else {
                $status = 'waived';
                $message = 'Damage report waived.';
                $notification = 'Your reported damage was reviewed and waived.';
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
            $message = 'Penalty event reviewed.';
            goto render_reservations;
        }
        if ($action === 'refund_payment') {
            $paymentId = (int)($_POST['payment_id'] ?? 0);
            $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);
            $reason = trim($_POST['reason'] ?? '');
            $method = $_POST['method'] ?? '';
            if (!$paymentId || $amount === false || $amount <= 0 || $reason === '' || !in_array($method, ['cash', 'bank_transfer', 'gcash', 'card'], true)) {
                throw new RuntimeException('Enter a valid refund amount, method, and reason.');
            }
            $payment = $pdo->prepare(
                'SELECT p.*, COALESCE((SELECT SUM(amount) FROM refunds WHERE payment_id = p.id), 0) AS refunded
                 FROM payments p WHERE p.id = ? FOR UPDATE'
            );
            $payment->execute([$paymentId]);
            $paymentRow = $payment->fetch();
            if (!$paymentRow || $amount > (float)$paymentRow['amount'] - (float)$paymentRow['refunded'] + 0.0001) {
                throw new RuntimeException('Refund exceeds the remaining payment amount.');
            }
            $refund = $pdo->prepare('INSERT INTO refunds (payment_id, amount, reason, method, recorded_by) VALUES (?, ?, ?, ?, ?)');
            $refund->execute([$paymentId, $amount, $reason, $method, $user['id']]);
            write_audit($pdo, (int)$user['id'], 'payment_refunded', 'payments', $paymentId, $reason);
            $pdo->commit();
            $message = 'Refund recorded.';
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
            throw new RuntimeException('A reservation is required for this action.');
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
                throw new RuntimeException('This vehicle is scheduled for maintenance.');
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
        $message = 'Reservation ' . $newStatus . '.';
    } catch (Throwable $exception) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Admin reservation action failed: ' . $exception->getMessage());
        $error = $exception->getMessage();
    }
}

$reservations = db()->query(
    "SELECT r.id, r.reference_number, r.requested_pickup_at, r.requested_return_at,
            r.total_amount, r.status, r.created_at, u.name AS customer_name,
            u.email AS customer_email, v.brand, v.model
     FROM reservations r
     INNER JOIN users u ON u.id = r.customer_id
     INNER JOIN vehicles v ON v.id = r.vehicle_id
     ORDER BY r.created_at DESC"
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
render_reservations:
include __DIR__ . '/../../includes/header.php';
?>
<main class="admin-page">
  <div class="admin-heading"><div><p class="eyebrow">Administration</p><h1>Reservation oversight</h1><p>Review customer reservation requests before pickup.</p></div><a class="btn btn-navy" href="/admin/admin-dashboard.php">Back to workspace</a></div>
  <?php if ($message): ?><p class="note request-success" role="status"><?= e($message) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="form-error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <section class="admin-card"><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Reference</th><th>Customer</th><th>Vehicle</th><th>Schedule</th><th>Status</th><th>Action</th></tr></thead><tbody>
  <?php foreach ($reservations as $reservation): ?><tr>
    <td><?= e($reservation['reference_number']) ?></td>
    <td><strong><?= e($reservation['customer_name']) ?></strong><small><?= e($reservation['customer_email']) ?></small></td>
    <td><?= e($reservation['brand'] . ' ' . $reservation['model']) ?></td>
    <td><?= e(date('M j, Y g:i A', strtotime($reservation['requested_pickup_at']))) ?><br>to <?= e(date('M j, Y g:i A', strtotime($reservation['requested_return_at']))) ?></td>
    <td><span class="status-badge"><?= e(ucfirst($reservation['status'])) ?></span></td>
    <td><?php if ($reservation['status'] === 'pending'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="reservation_id" value="<?= (int)$reservation['id'] ?>"><input name="reason" maxlength="255" placeholder="Reason if rejecting"><button class="table-action" name="action" value="approve">Approve</button><button class="table-action" name="action" value="reject">Reject</button></form><?php else: ?>Reviewed<?php endif; ?><br><?php $cancelStmt = db()->prepare("SELECT id FROM reservation_cancellation_requests WHERE reservation_id = ? AND status = 'requested'"); $cancelStmt->execute([$reservation['id']]); if ($cancelStmt->fetch()): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="reservation_id" value="<?= (int)$reservation['id'] ?>"><input name="reason" maxlength="255" placeholder="Reason"><button class="table-action" name="action" value="approve_cancel">Approve cancellation</button><button class="table-action" name="action" value="reject_cancel">Reject cancellation</button></form><?php endif; ?></td>
  </tr><?php endforeach; ?>
  </tbody></table></div></section>
  <section class="admin-card"><p class="eyebrow">Financial review</p><h2>Reported damage</h2><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Rental</th><th>Customer</th><th>Vehicle</th><th>Description</th><th>Estimated cost</th><th>Action</th></tr></thead><tbody><?php foreach ($damageReports as $damage): ?><tr><td><?= e($damage['reference_number']) ?></td><td><?= e($damage['customer_name']) ?></td><td><?= e($damage['brand'] . ' ' . $damage['model']) ?></td><td><?= e($damage['description']) ?></td><td>₱<?= number_format((float)$damage['estimated_cost'], 2) ?></td><td><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="rental_id" value="<?= (int)$damage['rental_id'] ?>"><input type="hidden" name="damage_id" value="<?= (int)$damage['id'] ?>"><input name="amount" type="number" min="0.01" step="0.01" value="<?= e((string)$damage['estimated_cost']) ?>" placeholder="Charge amount"><input name="reason" maxlength="255" placeholder="Review note"><button class="table-action" name="action" value="charge_damage">Create charge</button><button class="table-action" name="action" value="waive_damage">Waive</button></form></td></tr><?php endforeach; ?><?php if (!$damageReports): ?><tr><td colspan="6">No damage reports require review.</td></tr><?php endif; ?></tbody></table></div></section>
  <section class="admin-card"><p class="eyebrow">Account standing</p><h2>Pending penalty events</h2><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Customer</th><th>Event</th><th>Points</th><th>Reason</th><th>Action</th></tr></thead><tbody><?php foreach ($penaltyEvents as $penalty): ?><tr><td><?= e($penalty['customer_name']) ?><small><?= e($penalty['customer_email']) ?></small></td><td><?= e(ucfirst(str_replace('_', ' ', $penalty['event_type']))) ?></td><td><?= (int)$penalty['points'] ?></td><td><?= e($penalty['reason']) ?></td><td><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="penalty_id" value="<?= (int)$penalty['id'] ?>"><input name="reason" maxlength="255" placeholder="Reason if waiving"><button class="table-action" name="action" value="approve_penalty">Approve</button><button class="table-action" name="action" value="waive_penalty">Waive</button></form></td></tr><?php endforeach; ?><?php if (!$penaltyEvents): ?><tr><td colspan="5">No penalty events require review.</td></tr><?php endif; ?></tbody></table></div></section>
  <section class="admin-card"><p class="eyebrow">Operational escalation</p><h2>Return incidents</h2><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Reference</th><th>Customer</th><th>Vehicle</th><th>Status</th><th>Action</th></tr></thead><tbody><?php foreach ($incidents as $incident): ?><tr><td><?= e($incident['reference_number']) ?></td><td><?= e($incident['customer_name']) ?></td><td><?= e($incident['brand'] . ' ' . $incident['model']) ?><small><?= e($incident['description']) ?></small></td><td><?= e(ucfirst(str_replace('_', ' ', $incident['status']))) ?></td><td><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="incident_id" value="<?= (int)$incident['id'] ?>"><input name="reason" maxlength="255" required placeholder="Decision reason"><button class="table-action" name="action" value="suspend_customer">Suspend account</button><select name="escalation_type"><option value="institutional_review">Institutional review</option><option value="security_review">Security review</option><option value="legal_review">Legal review</option></select><button class="table-action" name="action" value="refer_incident">Record referral</button></form></td></tr><?php endforeach; ?><?php if (!$incidents): ?><tr><td colspan="5">No open return incidents.</td></tr><?php endif; ?></tbody></table></div></section>
  <section class="admin-card"><p class="eyebrow">Payment settlement</p><h2>Receipts and corrections</h2><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Receipt</th><th>Customer</th><th>Amount</th><th>Method</th><th>Refund</th></tr></thead><tbody><?php foreach ($payments as $payment): ?><tr><td><?= e($payment['receipt_number']) ?><small><?= e($payment['reservation_reference']) ?></small></td><td><?= e($payment['customer_name']) ?></td><td>₱<?= number_format((float)$payment['amount'], 2) ?></td><td><?= e(ucwords(str_replace('_', ' ', $payment['method']))) ?></td><td><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="payment_id" value="<?= (int)$payment['id'] ?>"><input name="amount" type="number" min="0.01" step="0.01" placeholder="Amount"><input name="method" value="<?= e($payment['method']) ?>" type="hidden"><input name="reason" maxlength="255" placeholder="Refund reason"><button class="table-action" name="action" value="refund_payment">Refund</button></form></td></tr><?php endforeach; ?><?php if (!$payments): ?><tr><td colspan="5">No payments recorded.</td></tr><?php endif; ?></tbody></table></div></section>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
