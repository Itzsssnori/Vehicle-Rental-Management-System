<?php
$title = 'Staff Workspace';
require_once __DIR__ . '/../../config.php';
$user = require_role('staff');
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $reservationId = filter_input(INPUT_POST, 'reservation_id', FILTER_VALIDATE_INT);
    $rentalId = filter_input(INPUT_POST, 'rental_id', FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';
    $pdo = db();
    try {
        $pdo->beginTransaction();
        if ($action === 'report_incident') {
            if (!$rentalId || !user_has_permission((int)$user['id'], 'rentals.return')) {
                throw new RuntimeException('You are not authorized to report a return incident.');
            }
            $description = trim($_POST['description'] ?? '');
            if ($description === '') {
                throw new RuntimeException('Enter a description of the missed return.');
            }
            $stmt = $pdo->prepare("SELECT id, customer_id FROM rentals WHERE id = ? AND status = 'ongoing' FOR UPDATE");
            $stmt->execute([$rentalId]);
            $rental = $stmt->fetch();
            if (!$rental) {
                throw new RuntimeException('Only ongoing rentals can receive a return incident.');
            }
            $incident = $pdo->prepare("INSERT INTO rental_incidents (rental_id, incident_type, description, reported_by) VALUES (?, 'missed_return', ?, ?)");
            $incident->execute([$rentalId, $description, $user['id']]);
            write_audit($pdo, (int)$user['id'], 'return_incident_reported', 'rental_incidents', (int)$pdo->lastInsertId(), $description);
            $message = 'Return incident reported for administrator review.';
        } elseif ($action === 'contact_attempt') {
            if (!$rentalId || !user_has_permission((int)$user['id'], 'rentals.return')) {
                throw new RuntimeException('You are not authorized to record contact attempts.');
            }
            $incidentId = filter_input(INPUT_POST, 'incident_id', FILTER_VALIDATE_INT);
            $method = $_POST['contact_method'] ?? '';
            $outcome = trim($_POST['outcome'] ?? '');
            if (!$incidentId || !in_array($method, ['phone', 'email', 'in_person', 'other'], true) || $outcome === '') {
                throw new RuntimeException('Enter a valid contact method and outcome.');
            }
            $contact = $pdo->prepare("INSERT INTO incident_contact_attempts (incident_id, contact_method, attempted_at, outcome, recorded_by) VALUES (?, ?, NOW(), ?, ?)");
            $contact->execute([$incidentId, $method, $outcome, $user['id']]);
            $pdo->prepare("UPDATE rental_incidents SET status = 'contact_attempted' WHERE id = ? AND status = 'open'")->execute([$incidentId]);
            write_audit($pdo, (int)$user['id'], 'incident_contact_attempted', 'rental_incidents', $incidentId, $outcome);
            $message = 'Contact attempt recorded.';
        } elseif (in_array($action, ['approve', 'reject'], true)) {
            if (!$reservationId || !user_has_permission((int)$user['id'], 'reservations.approve')) {
                throw new RuntimeException('You are not authorized to review this reservation.');
            }
            $stmt = $pdo->prepare('SELECT r.*, v.status AS vehicle_status FROM reservations r INNER JOIN vehicles v ON v.id = r.vehicle_id WHERE r.id = ? FOR UPDATE');
            $stmt->execute([$reservationId]);
            $reservation = $stmt->fetch();
            if (!$reservation || $reservation['status'] !== 'pending') {
                throw new RuntimeException('Only pending reservations can be reviewed.');
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
                $conflict = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE vehicle_id = ? AND id <> ? AND status = 'confirmed' AND requested_pickup_at < ? AND requested_return_at > ?");
                $conflict->execute([$reservation['vehicle_id'], $reservationId, $reservation['requested_return_at'], $reservation['requested_pickup_at']]);
                if ((int)$conflict->fetchColumn() > 0) {
                    throw new RuntimeException('This reservation overlaps another confirmed reservation.');
                }
            }
            $update = $pdo->prepare('UPDATE reservations SET status = ?, rejection_reason = ?, approved_by = ?, approved_at = CASE WHEN ? = "confirmed" THEN NOW() ELSE NULL END WHERE id = ?');
            $update->execute([$newStatus, $newStatus === 'rejected' ? $reason : null, $user['id'], $newStatus, $reservationId]);
            record_reservation_status($pdo, $reservationId, (int)$user['id'], $newStatus, 'pending', $reason ?: null);
            notify_reservation($pdo, (int)$reservation['customer_id'], 'Reservation ' . $newStatus, $newStatus === 'confirmed' ? 'Your reservation has been approved.' : 'Your reservation was rejected: ' . $reason, $reservationId);
            write_audit($pdo, (int)$user['id'], 'reservation_' . $newStatus, 'reservations', $reservationId, $reason ?: 'Staff reviewed reservation.');
            $message = 'Reservation ' . $newStatus . '.';
        } elseif ($action === 'no_show') {
            if (!$reservationId || !user_has_permission((int)$user['id'], 'rentals.pickup')) {
                throw new RuntimeException('You are not authorized to record no-shows.');
            }
            $stmt = $pdo->prepare('SELECT * FROM reservations WHERE id = ? FOR UPDATE');
            $stmt->execute([$reservationId]);
            $reservation = $stmt->fetch();
            if (!$reservation || $reservation['status'] !== 'confirmed') {
                throw new RuntimeException('Only confirmed reservations can be marked as no-shows.');
            }
            $graceEndsAt = (new DateTimeImmutable($reservation['requested_pickup_at']))->modify('+30 minutes');
            if (new DateTimeImmutable('now') < $graceEndsAt) {
                throw new RuntimeException('The 30-minute pickup grace period has not ended.');
            }
            $reason = trim($_POST['reason'] ?? '');
            if ($reason === '') {
                throw new RuntimeException('Enter a reason when recording a no-show.');
            }
            $update = $pdo->prepare("UPDATE reservations SET status = 'no_show', rejection_reason = NULL WHERE id = ? AND status = 'confirmed'");
            $update->execute([$reservationId]);
            record_reservation_status($pdo, $reservationId, (int)$user['id'], 'no_show', 'confirmed', $reason);
            $penalty = $pdo->prepare(
                "INSERT INTO penalty_events
                 (user_id, reservation_id, event_type, points, reason, status, expires_at, recorded_by)
                 VALUES (?, ?, 'no_show', 3, ?, 'pending', DATE_ADD(CURRENT_DATE, INTERVAL 12 MONTH), ?)"
            );
            $penalty->execute([(int)$reservation['customer_id'], $reservationId, $reason, (int)$user['id']]);
            notify_reservation($pdo, (int)$reservation['customer_id'], 'Reservation marked as no-show', 'Your reservation was marked as a no-show: ' . $reason, $reservationId);
            write_audit($pdo, (int)$user['id'], 'reservation_no_show', 'reservations', $reservationId, $reason);
            $message = 'Reservation marked as a no-show.';
        } elseif ($action === 'pickup') {
            if (!$reservationId || !user_has_permission((int)$user['id'], 'rentals.pickup')) {
                throw new RuntimeException('You are not authorized to record pickups.');
            }
            $mileage = filter_input(INPUT_POST, 'starting_mileage', FILTER_VALIDATE_INT);
            $fuel = filter_input(INPUT_POST, 'starting_fuel_level', FILTER_VALIDATE_FLOAT);
            $notes = trim($_POST['condition_notes'] ?? '');
            if ($mileage === false || $mileage < 0 || $fuel === false || $fuel < 0 || $fuel > 100 || !$notes) {
                throw new RuntimeException('Enter valid mileage, fuel level, and condition notes.');
            }
            $stmt = $pdo->prepare('SELECT * FROM reservations WHERE id = ? FOR UPDATE');
            $stmt->execute([$reservationId]);
            $reservation = $stmt->fetch();
            if (!$reservation || $reservation['status'] !== 'confirmed') {
                throw new RuntimeException('Only confirmed reservations can be picked up.');
            }
            $balance = rental_balance($pdo, $reservationId);
            if ($balance['outstanding'] > 0.0001) {
                throw new RuntimeException('Record payment for the outstanding base charge before pickup.');
            }
            $existing = $pdo->prepare('SELECT id FROM rentals WHERE reservation_id = ?');
            $existing->execute([$reservationId]);
            if ($existing->fetch()) {
                throw new RuntimeException('This reservation already has a rental.');
            }
            $insert = $pdo->prepare('INSERT INTO rentals (reservation_id, customer_id, vehicle_id, expected_return_at, created_by) VALUES (?, ?, ?, ?, ?)');
            $insert->execute([$reservationId, $reservation['customer_id'], $reservation['vehicle_id'], $reservation['requested_return_at'], $user['id']]);
            $newRentalId = (int)$pdo->lastInsertId();
            $pickup = $pdo->prepare('INSERT INTO pickup_records (rental_id, recorded_by, picked_up_at, starting_mileage, starting_fuel_level, condition_notes, customer_acknowledged) VALUES (?, ?, NOW(), ?, ?, ?, ?)');
            $pickup->execute([$newRentalId, $user['id'], $mileage, $fuel, $notes, isset($_POST['customer_acknowledged']) ? 1 : 0]);
            $inspection = $pdo->prepare("INSERT INTO vehicle_inspections (rental_id, inspection_type, inspected_by, mileage, fuel_level, condition_notes) VALUES (?, 'pickup', ?, ?, ?, ?)");
            $inspection->execute([$newRentalId, $user['id'], $mileage, $fuel, $notes]);
            record_rental_status($pdo, $newRentalId, (int)$user['id'], 'ongoing');
            write_audit($pdo, (int)$user['id'], 'rental_started', 'rentals', $newRentalId, 'Vehicle pickup recorded.');
            notify_reservation($pdo, (int)$reservation['customer_id'], 'Vehicle picked up', 'Your rental is now ongoing.', $reservationId);
            $message = 'Pickup recorded and rental started.';
        } elseif ($action === 'return') {
            if (!$rentalId || !user_has_permission((int)$user['id'], 'rentals.return')) {
                throw new RuntimeException('You are not authorized to record returns.');
            }
            $mileage = filter_input(INPUT_POST, 'ending_mileage', FILTER_VALIDATE_INT);
            $fuel = filter_input(INPUT_POST, 'ending_fuel_level', FILTER_VALIDATE_FLOAT);
            $notes = trim($_POST['condition_notes'] ?? '');
            if ($mileage === false || $mileage < 0 || $fuel === false || $fuel < 0 || $fuel > 100 || !$notes) {
                throw new RuntimeException('Enter valid mileage, fuel level, and condition notes.');
            }
            $stmt = $pdo->prepare('SELECT r.*, p.starting_mileage, res.customer_id, res.id AS reservation_id, res.requested_return_at, v.daily_rate FROM rentals r INNER JOIN pickup_records p ON p.rental_id = r.id INNER JOIN reservations res ON res.id = r.reservation_id INNER JOIN vehicles v ON v.id = r.vehicle_id WHERE r.id = ? FOR UPDATE');
            $stmt->execute([$rentalId]);
            $rental = $stmt->fetch();
            if (!$rental || $rental['status'] !== 'ongoing') {
                throw new RuntimeException('Only ongoing rentals can be returned.');
            }
            if ($mileage < (int)$rental['starting_mileage']) {
                throw new RuntimeException('Ending mileage cannot be lower than starting mileage.');
            }
            $damageDescription = trim($_POST['damage_description'] ?? '');
            $estimatedDamage = filter_input(INPUT_POST, 'estimated_damage', FILTER_VALIDATE_FLOAT);
            if (isset($_POST['damage_reported']) && $damageDescription === '') {
                throw new RuntimeException('Enter a damage description when reporting damage.');
            }
            if ($estimatedDamage !== false && $estimatedDamage !== null && $estimatedDamage < 0) {
                throw new RuntimeException('Estimated damage cost cannot be negative.');
            }
            $return = $pdo->prepare('INSERT INTO return_records (rental_id, recorded_by, returned_at, ending_mileage, ending_fuel_level, condition_notes, damage_reported) VALUES (?, ?, NOW(), ?, ?, ?, ?)');
            $return->execute([$rentalId, $user['id'], $mileage, $fuel, $notes, isset($_POST['damage_reported']) ? 1 : 0]);
            $inspection = $pdo->prepare("INSERT INTO vehicle_inspections (rental_id, inspection_type, inspected_by, mileage, fuel_level, condition_notes) VALUES (?, 'return', ?, ?, ?, ?)");
            $inspection->execute([$rentalId, $user['id'], $mileage, $fuel, $notes]);
            if (isset($_POST['damage_reported'])) {
                $damage = $pdo->prepare('INSERT INTO damage_reports (rental_id, reported_by, description, estimated_cost) VALUES (?, ?, ?, ?)');
                $damage->execute([$rentalId, $user['id'], $damageDescription, $estimatedDamage ?: null]);
            }
            $lateFee = late_fee_amount($rental['requested_return_at'], date('Y-m-d H:i:s'), (float)$rental['daily_rate']);
            if ($lateFee['amount'] > 0) {
                $existingLate = $pdo->prepare("SELECT id FROM rental_charges WHERE rental_id = ? AND charge_type = 'late_fee' LIMIT 1");
                $existingLate->execute([$rentalId]);
                if (!$existingLate->fetchColumn()) {
                    $charge = $pdo->prepare("INSERT INTO rental_charges (rental_id, charge_type, description, quantity, unit_price, amount) VALUES (?, 'late_fee', ?, ?, ?, ?)");
                    $charge->execute([$rentalId, "{$lateFee['hours']} billable late hour(s)", $lateFee['hours'], 250.00, $lateFee['amount']]);
                }
                $penalty = $pdo->prepare(
                    "INSERT INTO penalty_events
                     (user_id, rental_id, event_type, points, reason, status, expires_at, recorded_by)
                     VALUES (?, ?, 'late_return', 1, ?, 'pending', DATE_ADD(CURRENT_DATE, INTERVAL 12 MONTH), ?)"
                );
                $penalty->execute([(int)$rental['customer_id'], $rentalId, "Returned {$lateFee['hours']} billable hour(s) late.", (int)$user['id']]);
            }
            $update = $pdo->prepare("UPDATE rentals SET status = 'returned' WHERE id = ? AND status = 'ongoing'");
            $update->execute([$rentalId]);
            record_rental_status($pdo, $rentalId, (int)$user['id'], 'returned', 'ongoing', 'Vehicle return recorded.');
            write_audit($pdo, (int)$user['id'], 'rental_returned', 'rentals', $rentalId, 'Vehicle return recorded.');
            notify_reservation($pdo, (int)$rental['customer_id'], 'Vehicle returned', 'Your vehicle return was recorded and is awaiting final inspection and settlement.', (int)$rental['reservation_id']);
            $message = 'Return recorded. The rental is awaiting completion.';
        } elseif ($action === 'complete') {
            if (!$rentalId || !user_has_permission((int)$user['id'], 'rentals.return')) {
                throw new RuntimeException('You are not authorized to complete rentals.');
            }
            $stmt = $pdo->prepare(
                'SELECT r.*, res.customer_id, res.id AS reservation_id,
                        res.total_amount,
                        COALESCE((SELECT SUM(amount) FROM rental_charges WHERE reservation_id = res.id OR rental_id = r.id), 0) AS charge_total,
                        COALESCE((SELECT SUM(amount) FROM payments WHERE reservation_id = res.id OR rental_id = r.id), 0) AS paid_total
                 FROM rentals r
                 INNER JOIN reservations res ON res.id = r.reservation_id
                 WHERE r.id = ? FOR UPDATE'
            );
            $stmt->execute([$rentalId]);
            $rental = $stmt->fetch();
            if (!$rental || $rental['status'] !== 'returned') {
                throw new RuntimeException('Only returned rentals can be completed.');
            }
            $chargeTotal = max((float)$rental['charge_total'], (float)$rental['total_amount']);
            if ((float)$rental['paid_total'] + 0.0001 < $chargeTotal) {
                throw new RuntimeException('The rental cannot be completed until its charges are fully paid.');
            }
            $update = $pdo->prepare("UPDATE rentals SET status = 'completed', completed_at = NOW() WHERE id = ? AND status = 'returned'");
            $update->execute([$rentalId]);
            record_rental_status($pdo, $rentalId, (int)$user['id'], 'completed', 'returned', 'Inspection and settlement completed.');
            write_audit($pdo, (int)$user['id'], 'rental_completed', 'rentals', $rentalId, 'Rental inspection and settlement completed.');
            notify_reservation($pdo, (int)$rental['customer_id'], 'Rental completed', 'Your rental has completed its inspection and settlement.', (int)$rental['reservation_id']);
            $message = 'Rental completed.';
        } elseif ($action === 'payment') {
            if (!$reservationId || !user_has_permission((int)$user['id'], 'payments.record')) {
                throw new RuntimeException('You are not authorized to record payments.');
            }
            $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
            $method = $_POST['method'] ?? '';
            if ($amount === false || $amount <= 0 || !in_array($method, ['cash', 'bank_transfer', 'gcash', 'card'], true)) {
                throw new RuntimeException('Enter a valid payment amount and method.');
            }
            $reservation = $pdo->prepare('SELECT r.id, rt.id AS rental_id FROM reservations r LEFT JOIN rentals rt ON rt.reservation_id = r.id WHERE r.id = ? FOR UPDATE');
            $reservation->execute([$reservationId]);
            $reservationRow = $reservation->fetch();
            if (!$reservationRow) {
                throw new RuntimeException('Reservation not found.');
            }
            $balance = rental_balance($pdo, $reservationId, $reservationRow['rental_id'] ? (int)$reservationRow['rental_id'] : null);
            if ($amount > $balance['outstanding'] + 0.0001) {
                throw new RuntimeException('Payment exceeds the remaining balance.');
            }
            $payment = $pdo->prepare('INSERT INTO payments (reservation_id, rental_id, amount, method, reference_number, recorded_by) VALUES (?, ?, ?, ?, ?, ?)');
            $payment->execute([$reservationId, $reservationRow['rental_id'] ?: null, $amount, $method, trim($_POST['reference_number'] ?? '') ?: null, $user['id']]);
            $paymentId = (int)$pdo->lastInsertId();
            $receipt = $pdo->prepare('INSERT INTO payment_receipts (payment_id, receipt_number, issued_by) VALUES (?, ?, ?)');
            $receipt->execute([$paymentId, 'RCPT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4))), $user['id']]);
            write_audit($pdo, (int)$user['id'], 'payment_recorded', 'payments', $paymentId, 'Payment recorded and receipt issued.');
            $message = 'Payment recorded.';
        } else {
            throw new RuntimeException('Invalid staff action.');
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Staff operation failed: ' . $exception->getMessage());
        $error = $exception->getMessage();
    }
}

$reservations = db()->query("SELECT r.id, r.reference_number, r.requested_pickup_at, r.requested_return_at, r.status, u.name AS customer_name, v.brand, v.model FROM reservations r INNER JOIN users u ON u.id = r.customer_id INNER JOIN vehicles v ON v.id = r.vehicle_id ORDER BY CASE WHEN r.status = 'pending' THEN 0 WHEN r.status = 'confirmed' THEN 1 ELSE 2 END, r.requested_pickup_at")->fetchAll();
$rentals = db()->query("SELECT rt.id, rt.status, res.reference_number, res.requested_return_at, u.name AS customer_name, v.brand, v.model FROM rentals rt INNER JOIN reservations res ON res.id = rt.reservation_id INNER JOIN users u ON u.id = rt.customer_id INNER JOIN vehicles v ON v.id = rt.vehicle_id WHERE rt.status IN ('ongoing', 'returned') ORDER BY CASE WHEN rt.status = 'ongoing' THEN 0 ELSE 1 END, rt.expected_return_at")->fetchAll();
$incidents = db()->query(
    "SELECT i.id, i.rental_id, i.status, i.description, r.reference_number, u.name AS customer_name
     FROM rental_incidents i
     INNER JOIN rentals rt ON rt.id = i.rental_id
     INNER JOIN reservations r ON r.id = rt.reservation_id
     INNER JOIN users u ON u.id = rt.customer_id
     WHERE i.status IN ('open', 'contact_attempted', 'escalated')
     ORDER BY i.created_at ASC"
)->fetchAll();
include __DIR__ . '/../../includes/header.php';
?>
<main class="admin-page"><div class="admin-heading"><div><p class="eyebrow">Staff workspace</p><h1>Rental operations</h1><p>Review reservations, record pickups, returns, and payments.</p></div></div>
<?php if ($message): ?><p class="note request-success" role="status"><?= e($message) ?></p><?php endif; ?><?php if ($error): ?><p class="form-error" role="alert"><?= e($error) ?></p><?php endif; ?>
<section class="admin-card"><h2>Reservations</h2><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Reference</th><th>Customer</th><th>Vehicle</th><th>Schedule</th><th>Status/actions</th></tr></thead><tbody><?php foreach ($reservations as $r): ?><tr><td><?= e($r['reference_number']) ?></td><td><?= e($r['customer_name']) ?></td><td><?= e($r['brand'].' '.$r['model']) ?></td><td><?= e(date('M j, Y g:i A', strtotime($r['requested_pickup_at']))) ?><br>to <?= e(date('M j, Y g:i A', strtotime($r['requested_return_at']))) ?></td><td><span class="status-badge"><?= e(ucfirst($r['status'])) ?></span><?php if ($r['status'] === 'pending'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="reservation_id" value="<?= (int)$r['id'] ?>"><input name="reason" placeholder="Reason if rejecting"><button class="table-action" name="action" value="approve">Approve</button><button class="table-action" name="action" value="reject">Reject</button></form><?php elseif ($r['status'] === 'confirmed'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="reservation_id" value="<?= (int)$r['id'] ?>"><input type="number" name="starting_mileage" min="0" required placeholder="Mileage"><input type="number" name="starting_fuel_level" min="0" max="100" step="0.01" required placeholder="Fuel %"><input name="condition_notes" required placeholder="Pickup condition"><label><input type="checkbox" name="customer_acknowledged"> Acknowledged</label><button class="table-action" name="action" value="pickup">Record pickup</button></form><?php if (new DateTimeImmutable('now') >= (new DateTimeImmutable($r['requested_pickup_at']))->modify('+30 minutes')): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="reservation_id" value="<?= (int)$r['id'] ?>"><input name="reason" required placeholder="No-show reason"><button class="table-action" name="action" value="no_show">Mark no-show</button></form><?php endif; ?><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="admin-card"><h2>Rental operations</h2><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Reference</th><th>Customer</th><th>Vehicle</th><th>Return</th><th>Status/actions</th></tr></thead><tbody><?php foreach ($rentals as $r): ?><tr><td><?= e($r['reference_number']) ?></td><td><?= e($r['customer_name']) ?></td><td><?= e($r['brand'].' '.$r['model']) ?></td><td><?= e(date('M j, Y g:i A', strtotime($r['requested_return_at']))) ?></td><td><span class="status-badge"><?= e(ucfirst($r['status'])) ?></span><?php if ($r['status'] === 'ongoing'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="rental_id" value="<?= (int)$r['id'] ?>"><input type="number" name="ending_mileage" min="0" required placeholder="Mileage"><input type="number" name="ending_fuel_level" min="0" max="100" step="0.01" required placeholder="Fuel %"><input name="condition_notes" required placeholder="Return condition"><label><input type="checkbox" name="damage_reported"> Damage</label><input name="damage_description" maxlength="255" placeholder="Damage description"><input type="number" name="estimated_damage" min="0" step="0.01" placeholder="Estimated damage cost"><button class="table-action" name="action" value="return">Record return</button></form><?php else: ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="rental_id" value="<?= (int)$r['id'] ?>"><button class="table-action" name="action" value="complete">Complete after settlement</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="admin-card"><h2>Report a missed return</h2><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Rental</th><th>Customer</th><th>Expected return</th><th>Action</th></tr></thead><tbody><?php foreach ($rentals as $r): ?><?php if ($r['status'] === 'ongoing'): ?><tr><td><?= e($r['reference_number']) ?></td><td><?= e($r['customer_name']) ?></td><td><?= e(date('M j, Y g:i A', strtotime($r['requested_return_at']))) ?></td><td><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="rental_id" value="<?= (int)$r['id'] ?>"><input name="description" maxlength="255" required placeholder="Incident description"><button class="table-action" name="action" value="report_incident">Report incident</button></form></td></tr><?php endif; ?><?php endforeach; ?></tbody></table></div></section>
<section class="admin-card"><h2>Return incidents</h2><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Reference</th><th>Customer</th><th>Status</th><th>Contact attempt</th></tr></thead><tbody><?php foreach ($incidents as $incident): ?><tr><td><?= e($incident['reference_number']) ?></td><td><?= e($incident['customer_name']) ?><small><?= e($incident['description']) ?></small></td><td><?= e(ucfirst(str_replace('_', ' ', $incident['status']))) ?></td><td><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="rental_id" value="<?= (int)$incident['rental_id'] ?>"><input type="hidden" name="incident_id" value="<?= (int)$incident['id'] ?>"><select name="contact_method"><option value="phone">Phone</option><option value="email">Email</option><option value="in_person">In person</option><option value="other">Other</option></select><input name="outcome" maxlength="255" required placeholder="Outcome"><button class="table-action" name="action" value="contact_attempt">Record attempt</button></form></td></tr><?php endforeach; ?><?php if (!$incidents): ?><tr><td colspan="4">No open return incidents.</td></tr><?php endif; ?></tbody></table></div></section>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
