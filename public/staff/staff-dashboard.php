<?php
$title = 'Staff Operations Workspace';
require_once __DIR__ . '/../../config.php';
$user = require_role('staff');
$message = '';
$error = '';

$pdo = db();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    $reservationId = filter_input(INPUT_POST, 'reservation_id', FILTER_VALIDATE_INT);
    $rentalId = filter_input(INPUT_POST, 'rental_id', FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';

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
            $message = 'Contact attempt successfully recorded.';

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
                    throw new RuntimeException('This vehicle is currently not operational.');
                }
                $conflict = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE vehicle_id = ? AND id <> ? AND status = 'confirmed' AND requested_pickup_at < ? AND requested_return_at > ?");
                $conflict->execute([$reservation['vehicle_id'], $reservationId, $reservation['requested_return_at'], $reservation['requested_pickup_at']]);
                if ((int)$conflict->fetchColumn() > 0) {
                    throw new RuntimeException('This reservation overlaps another confirmed reservation for this vehicle.');
                }
            }
            $update = $pdo->prepare('UPDATE reservations SET status = ?, rejection_reason = ?, approved_by = ?, approved_at = CASE WHEN ? = "confirmed" THEN NOW() ELSE NULL END WHERE id = ?');
            $update->execute([$newStatus, $newStatus === 'rejected' ? $reason : null, $user['id'], $newStatus, $reservationId]);
            record_reservation_status($pdo, $reservationId, (int)$user['id'], $newStatus, 'pending', $reason ?: null);
            notify_reservation($pdo, (int)$reservation['customer_id'], 'Reservation ' . ucfirst($newStatus), $newStatus === 'confirmed' ? 'Your reservation has been approved.' : 'Your reservation was rejected: ' . $reason, $reservationId);
            write_audit($pdo, (int)$user['id'], 'reservation_' . $newStatus, 'reservations', $reservationId, $reason ?: 'Staff reviewed reservation.');
            $message = 'Reservation ' . $newStatus . ' successfully.';

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
            $message = 'Reservation marked as a no-show and penalty recorded.';

        } elseif ($action === 'pickup') {
            if (!$reservationId || !user_has_permission((int)$user['id'], 'rentals.pickup')) {
                throw new RuntimeException('You are not authorized to record pickups.');
            }
            $mileage = filter_input(INPUT_POST, 'starting_mileage', FILTER_VALIDATE_INT);
            $fuel = filter_input(INPUT_POST, 'starting_fuel_level', FILTER_VALIDATE_FLOAT);
            $notes = trim($_POST['condition_notes'] ?? '');
            if ($mileage === false || $mileage < 0 || $fuel === false || $fuel < 0 || $fuel > 100 || !$notes) {
                throw new RuntimeException('Enter valid mileage, fuel level (0-100%), and condition notes.');
            }
            $stmt = $pdo->prepare('SELECT * FROM reservations WHERE id = ? FOR UPDATE');
            $stmt->execute([$reservationId]);
            $reservation = $stmt->fetch();
            if (!$reservation || $reservation['status'] !== 'confirmed') {
                throw new RuntimeException('Only confirmed reservations can be picked up.');
            }
            $balance = rental_balance($pdo, $reservationId);
            if ($balance['outstanding'] > 0.0001) {
                throw new RuntimeException('Record payment for the outstanding base charge (₱' . number_format($balance['outstanding'], 2) . ') before vehicle turnover.');
            }
            $existing = $pdo->prepare('SELECT id FROM rentals WHERE reservation_id = ?');
            $existing->execute([$reservationId]);
            if ($existing->fetch()) {
                throw new RuntimeException('This reservation already has an active or past rental.');
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
            $message = 'Vehicle pickup recorded successfully. Rental is now ongoing.';

        } elseif ($action === 'return') {
            if (!$rentalId || !user_has_permission((int)$user['id'], 'rentals.return')) {
                throw new RuntimeException('You are not authorized to record returns.');
            }
            $mileage = filter_input(INPUT_POST, 'ending_mileage', FILTER_VALIDATE_INT);
            $fuel = filter_input(INPUT_POST, 'ending_fuel_level', FILTER_VALIDATE_FLOAT);
            $notes = trim($_POST['condition_notes'] ?? '');
            if ($mileage === false || $mileage < 0 || $fuel === false || $fuel < 0 || $fuel > 100 || !$notes) {
                throw new RuntimeException('Enter valid mileage, fuel level (0-100%), and condition notes.');
            }
            $stmt = $pdo->prepare('SELECT r.*, p.starting_mileage, res.customer_id, res.id AS reservation_id, res.requested_return_at, v.daily_rate FROM rentals r INNER JOIN pickup_records p ON p.rental_id = r.id INNER JOIN reservations res ON res.id = r.reservation_id INNER JOIN vehicles v ON v.id = r.vehicle_id WHERE r.id = ? FOR UPDATE');
            $stmt->execute([$rentalId]);
            $rental = $stmt->fetch();
            if (!$rental || $rental['status'] !== 'ongoing') {
                throw new RuntimeException('Only ongoing rentals can be returned.');
            }
            if ($mileage < (int)$rental['starting_mileage']) {
                throw new RuntimeException('Ending mileage cannot be lower than starting mileage (' . (int)$rental['starting_mileage'] . ' km).');
            }
            $damageDescription = trim($_POST['damage_description'] ?? '');
            $estimatedDamage = filter_input(INPUT_POST, 'estimated_damage', FILTER_VALIDATE_FLOAT);
            $isDamageReported = !empty($_POST['damage_reported']);
            if ($isDamageReported && $damageDescription === '') {
                throw new RuntimeException('Enter a damage description when reporting damage.');
            }
            if ($estimatedDamage !== false && $estimatedDamage !== null && $estimatedDamage < 0) {
                throw new RuntimeException('Estimated damage cost cannot be negative.');
            }
            $return = $pdo->prepare('INSERT INTO return_records (rental_id, recorded_by, returned_at, ending_mileage, ending_fuel_level, condition_notes, damage_reported) VALUES (?, ?, NOW(), ?, ?, ?, ?)');
            $return->execute([$rentalId, $user['id'], $mileage, $fuel, $notes, $isDamageReported ? 1 : 0]);
            $inspection = $pdo->prepare("INSERT INTO vehicle_inspections (rental_id, inspection_type, inspected_by, mileage, fuel_level, condition_notes) VALUES (?, 'return', ?, ?, ?, ?)");
            $inspection->execute([$rentalId, $user['id'], $mileage, $fuel, $notes]);
            if ($isDamageReported) {
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
            $message = 'Vehicle return recorded. The rental is now awaiting final settlement.';

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
                throw new RuntimeException('The rental cannot be completed until its charges are fully settled (Outstanding: ₱' . number_format($chargeTotal - (float)$rental['paid_total'], 2) . ').');
            }
            $update = $pdo->prepare("UPDATE rentals SET status = 'completed', completed_at = NOW() WHERE id = ? AND status = 'returned'");
            $update->execute([$rentalId]);
            record_rental_status($pdo, $rentalId, (int)$user['id'], 'completed', 'returned', 'Inspection and settlement completed.');
            write_audit($pdo, (int)$user['id'], 'rental_completed', 'rentals', $rentalId, 'Rental inspection and settlement completed.');
            notify_reservation($pdo, (int)$rental['customer_id'], 'Rental completed', 'Your rental has completed its inspection and settlement.', (int)$rental['reservation_id']);
            $message = 'Rental completed and ledger closed.';

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
                throw new RuntimeException('Reservation record not found.');
            }
            $balance = rental_balance($pdo, $reservationId, $reservationRow['rental_id'] ? (int)$reservationRow['rental_id'] : null);
            if ($amount > $balance['outstanding'] + 0.0001) {
                throw new RuntimeException('Payment amount (₱' . number_format($amount, 2) . ') exceeds remaining outstanding balance (₱' . number_format($balance['outstanding'], 2) . ').');
            }
            $payment = $pdo->prepare('INSERT INTO payments (reservation_id, rental_id, amount, method, reference_number, recorded_by) VALUES (?, ?, ?, ?, ?, ?)');
            $payment->execute([$reservationId, $reservationRow['rental_id'] ?: null, $amount, $method, trim($_POST['reference_number'] ?? '') ?: null, $user['id']]);
            $paymentId = (int)$pdo->lastInsertId();
            $receiptNum = 'RCPT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
            $receipt = $pdo->prepare('INSERT INTO payment_receipts (payment_id, receipt_number, issued_by) VALUES (?, ?, ?)');
            $receipt->execute([$paymentId, $receiptNum, $user['id']]);
            write_audit($pdo, (int)$user['id'], 'payment_recorded', 'payments', $paymentId, 'Payment recorded and receipt ' . $receiptNum . ' issued.');
            $message = 'Payment of ₱' . number_format($amount, 2) . ' recorded. Official receipt ' . $receiptNum . ' generated.';

        } else {
            throw new RuntimeException('Invalid operational action.');
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

// Active tab selection
$activeTab = $_GET['tab'] ?? ($_GET['section'] ?? 'overview');
if ($activeTab === 'requests') $activeTab = 'reservations';
if ($activeTab === 'operations') $activeTab = 'pickups';

// 1. Fetch Pending Reservations
$pendingReservations = $pdo->query(
    "SELECT r.*,
            u.id AS customer_user_id, u.name AS customer_name, u.email AS customer_email, u.phone AS customer_phone,
            v.brand, v.model, vc.name AS category, v.plate_number, v.daily_rate, v.image AS image_url, v.status AS vehicle_status,
            (SELECT COUNT(*) FROM penalty_events pe WHERE pe.user_id = u.id AND pe.status = 'approved' AND (pe.expires_at IS NULL OR pe.expires_at >= CURRENT_DATE)) AS active_points
     FROM reservations r
     INNER JOIN users u ON u.id = r.customer_id
     INNER JOIN vehicles v ON v.id = r.vehicle_id
     LEFT JOIN vehicle_categories vc ON vc.id = v.category_id
     WHERE r.status = 'pending'
     ORDER BY r.requested_pickup_at ASC"
)->fetchAll();

// 2. Fetch Confirmed Reservations Ready for Pickup
$readyPickups = $pdo->query(
    "SELECT r.*,
            u.id AS customer_user_id, u.name AS customer_name, u.email AS customer_email, u.phone AS customer_phone,
            v.brand, v.model, vc.name AS category, v.plate_number, v.daily_rate, v.image AS image_url, v.status AS vehicle_status, v.mileage AS current_mileage,
            (SELECT id FROM rentals WHERE reservation_id = r.id LIMIT 1) AS rental_id
     FROM reservations r
     INNER JOIN users u ON u.id = r.customer_id
     INNER JOIN vehicles v ON v.id = r.vehicle_id
     LEFT JOIN vehicle_categories vc ON vc.id = v.category_id
     WHERE r.status = 'confirmed' AND (SELECT id FROM rentals WHERE reservation_id = r.id LIMIT 1) IS NULL
     ORDER BY r.requested_pickup_at ASC"
)->fetchAll();

// Pre-calculate balances for confirmed reservations
$readyPickupsData = [];
foreach ($readyPickups as $rp) {
    $bal = rental_balance($pdo, (int)$rp['id']);
    $readyPickupsData[] = array_merge($rp, [
        'balance' => $bal,
        'grace_ends' => (new DateTimeImmutable($rp['requested_pickup_at']))->modify('+30 minutes'),
    ]);
}

// 3. Fetch Ongoing Rentals (Vehicles Out on Road)
$ongoingRentals = $pdo->query(
    "SELECT rt.*,
            r.reference_number, r.requested_pickup_at, r.requested_return_at, r.total_amount,
            u.name AS customer_name, u.email AS customer_email, u.phone AS customer_phone,
            v.brand, v.model, vc.name AS category, v.plate_number, v.daily_rate, v.image AS image_url,
            p.starting_mileage, p.starting_fuel_level, p.picked_up_at
     FROM rentals rt
     INNER JOIN reservations r ON r.id = rt.reservation_id
     INNER JOIN users u ON u.id = rt.customer_id
     INNER JOIN vehicles v ON v.id = rt.vehicle_id
     LEFT JOIN vehicle_categories vc ON vc.id = v.category_id
     LEFT JOIN pickup_records p ON p.rental_id = rt.id
     WHERE rt.status = 'ongoing'
     ORDER BY rt.expected_return_at ASC"
)->fetchAll();

// 4. Fetch Returned Rentals (Awaiting Final Settlement & Completion)
$returnedRentals = $pdo->query(
    "SELECT rt.*,
            r.reference_number, r.requested_return_at, r.total_amount,
            u.name AS customer_name, u.email AS customer_email,
            v.brand, v.model, v.plate_number, v.daily_rate, v.image AS image_url,
            ret.ending_mileage, ret.ending_fuel_level, ret.returned_at, ret.damage_reported,
            p.starting_mileage
     FROM rentals rt
     INNER JOIN reservations r ON r.id = rt.reservation_id
     INNER JOIN users u ON u.id = rt.customer_id
     INNER JOIN vehicles v ON v.id = rt.vehicle_id
     LEFT JOIN pickup_records p ON p.rental_id = rt.id
     LEFT JOIN return_records ret ON ret.rental_id = rt.id
     WHERE rt.status = 'returned'
     ORDER BY ret.returned_at DESC"
)->fetchAll();

$returnedRentalsData = [];
foreach ($returnedRentals as $rr) {
    $bal = rental_balance($pdo, (int)$rr['reservation_id'], (int)$rr['id']);
    $returnedRentalsData[] = array_merge($rr, ['balance' => $bal]);
}

// 5. Open Incidents and Contact Attempts
$incidents = $pdo->query(
    "SELECT i.*, rt.expected_return_at, r.reference_number, u.name AS customer_name, u.phone AS customer_phone, u.email AS customer_email,
            v.brand, v.model, v.plate_number
     FROM rental_incidents i
     INNER JOIN rentals rt ON rt.id = i.rental_id
     INNER JOIN reservations r ON r.id = rt.reservation_id
     INNER JOIN users u ON u.id = rt.customer_id
     INNER JOIN vehicles v ON v.id = rt.vehicle_id
     WHERE i.status IN ('open', 'contact_attempted', 'escalated')
     ORDER BY CASE WHEN i.status = 'open' THEN 0 WHEN i.status = 'contact_attempted' THEN 1 ELSE 2 END, i.created_at DESC"
)->fetchAll();

$incidentsWithContacts = [];
foreach ($incidents as $inc) {
    $attemptsStmt = $pdo->prepare(
        "SELECT ca.*, u.name AS recorded_by_name
         FROM incident_contact_attempts ca
         LEFT JOIN users u ON u.id = ca.recorded_by
         WHERE ca.incident_id = ?
         ORDER BY ca.attempted_at DESC LIMIT 5"
    );
    $attemptsStmt->execute([(int)$inc['id']]);
    $incidentsWithContacts[] = array_merge($inc, ['attempts' => $attemptsStmt->fetchAll()]);
}

// 6. Damage Reports
$damageReports = $pdo->query(
    "SELECT dr.*, rt.id AS rental_id, r.reference_number, u.name AS customer_name, v.brand, v.model, v.plate_number
     FROM damage_reports dr
     INNER JOIN rentals rt ON rt.id = dr.rental_id
     INNER JOIN reservations r ON r.id = rt.reservation_id
     INNER JOIN users u ON u.id = rt.customer_id
     INNER JOIN vehicles v ON v.id = rt.vehicle_id
     ORDER BY dr.created_at DESC LIMIT 10"
)->fetchAll();

// 7. Recent Payments Recorded
$recentPayments = $pdo->query(
    "SELECT p.*, r.reference_number, u.name AS customer_name, pr.receipt_number
     FROM payments p
     LEFT JOIN reservations r ON r.id = p.reservation_id
     LEFT JOIN users u ON u.id = r.customer_id
     LEFT JOIN payment_receipts pr ON pr.payment_id = p.id
     ORDER BY p.paid_at DESC LIMIT 8"
)->fetchAll();

// 8. Open Balance Accounts (For Payment Counter Dropdown)
$allActiveForPayment = $pdo->query(
    "SELECT r.id AS reservation_id, r.reference_number, r.status AS reservation_status,
            u.name AS customer_name, v.brand, v.model, v.plate_number,
            rt.id AS rental_id, rt.status AS rental_status
     FROM reservations r
     INNER JOIN users u ON u.id = r.customer_id
     INNER JOIN vehicles v ON v.id = r.vehicle_id
     LEFT JOIN rentals rt ON rt.reservation_id = r.id
     WHERE r.status IN ('confirmed', 'pending') OR (rt.id IS NOT NULL AND rt.status IN ('ongoing', 'returned'))
     ORDER BY r.created_at DESC"
)->fetchAll();

$paymentCandidates = [];
foreach ($allActiveForPayment as $c) {
    $bal = rental_balance($pdo, (int)$c['reservation_id'], $c['rental_id'] ? (int)$c['rental_id'] : null);
    if ($bal['outstanding'] > 0.0001) {
        $paymentCandidates[] = array_merge($c, ['balance' => $bal]);
    }
}

// KPI Statistics
$kpiPending = count($pendingReservations);
$kpiPickupsToday = (int)$pdo->query("SELECT COUNT(*) FROM reservations r WHERE r.status = 'confirmed' AND DATE(r.requested_pickup_at) = CURRENT_DATE AND (SELECT id FROM rentals WHERE reservation_id = r.id LIMIT 1) IS NULL")->fetchColumn();
$kpiReturnsToday = (int)$pdo->query("SELECT COUNT(*) FROM rentals WHERE status = 'ongoing' AND DATE(expected_return_at) = CURRENT_DATE")->fetchColumn();
$kpiOverdue = (int)$pdo->query("SELECT COUNT(*) FROM rentals WHERE status = 'ongoing' AND expected_return_at < NOW()")->fetchColumn();
$kpiUnpaidTotal = array_sum(array_map(fn($item) => $item['balance']['outstanding'], $paymentCandidates));
$kpiIncidents = count($incidents) + count($damageReports);

// Determine Spotlight Operational Task
$spotlightOp = null;
$spotlightType = '';
foreach ($ongoingRentals as $og) {
    if (new DateTimeImmutable('now') > new DateTimeImmutable($og['expected_return_at'])) {
        $spotlightOp = $og;
        $spotlightType = 'overdue';
        break;
    }
}
if (!$spotlightOp) {
    foreach ($readyPickupsData as $rp) {
        if (date('Y-m-d', strtotime($rp['requested_pickup_at'])) === date('Y-m-d')) {
            $spotlightOp = $rp;
            $spotlightType = 'pickup';
            break;
        }
    }
}
if (!$spotlightOp && !empty($pendingReservations)) {
    $spotlightOp = $pendingReservations[0];
    $spotlightType = 'pending';
}
if (!$spotlightOp && !empty($ongoingRentals)) {
    $spotlightOp = $ongoingRentals[0];
    $spotlightType = 'ongoing';
}

include __DIR__ . '/../../includes/header.php';
?>

<main class="portal-customer-page">
  <div class="portal-customer-layout">
    <?php include __DIR__ . '/../../includes/workspace-sidebar.php'; ?>

    <div class="customer-main-content">
      <!-- 1. Hero Greeting Banner (Unified 38px actions matching Admin and Customer) -->
      <section class="portal-hero-banner" id="overview">
        <div class="portal-hero-text">
          <div class="portal-hero-eyebrow">
            <span class="staff-live-dot" style="display:inline-block; vertical-align:middle; margin-right:6px;" aria-hidden="true"></span>
            Operations Workspace &bull; Front Counter
          </div>
          <h2>Operations Command, <?= e($user['name']) ?></h2>
          <p>Review customer reservations, coordinate vehicle turnovers, return check-ins, and front-desk settlements.</p>
        </div>
        <div class="portal-hero-actions">
          <a class="btn btn-coral" href="?tab=reservations">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <span>Review Queue</span>
          </a>
          <a class="btn btn-outline-white" href="?tab=pickups">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/></svg>
            <span>Turnover</span>
          </a>
          <a class="btn btn-outline-white" href="?tab=returns">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>
            <span>Check-in</span>
          </a>
          <a class="btn btn-outline-white" href="?tab=payments">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
            <span>Payment Desk</span>
          </a>
        </div>
      </section>

      <!-- 2. Flash Notifications -->
      <?php if ($message): ?>
        <div class="portal-alert-success">
          <span class="alert-icon" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          </span>
          <span><?= e($message) ?></span>
        </div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="form-error" role="alert" style="margin-bottom:0;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="margin-right:8px; vertical-align:middle; color:#dc2626;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <span><?= e($error) ?></span>
        </div>
      <?php endif; ?>

      <!-- 3. 4 Metric KPI Cards (Centered grid matching customer portal) -->
      <section class="portal-kpi-grid" aria-label="Staff operations summary">
        <!-- Pending Approvals Card -->
        <a href="?tab=reservations" class="portal-kpi-card">
          <div class="kpi-header">
            <span class="kpi-label">Pending Reviews</span>
            <span class="kpi-icon-pill" aria-hidden="true" style="background:#fef3c7; color:#d97706;">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            </span>
          </div>
          <div class="kpi-value" style="<?= $kpiPending > 0 ? 'color:#d97706;' : '' ?>"><?= $kpiPending ?></div>
          <div class="kpi-subtext">
            <span><?= $kpiPending === 1 ? '1 reservation awaits action' : $kpiPending . ' reservations to review' ?></span>
          </div>
        </a>

        <!-- Pickups Card -->
        <a href="?tab=pickups" class="portal-kpi-card">
          <div class="kpi-header">
            <span class="kpi-label">Today's Pickups</span>
            <span class="kpi-icon-pill" aria-hidden="true" style="background:#e0f2fe; color:#0284c7;">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/></svg>
            </span>
          </div>
          <div class="kpi-value"><?= $kpiPickupsToday ?></div>
          <div class="kpi-subtext">
            <span><?= $kpiPickupsToday === 1 ? '1 handover scheduled' : $kpiPickupsToday . ' handovers scheduled' ?></span>
          </div>
        </a>

        <!-- Returns Card -->
        <a href="?tab=returns" class="portal-kpi-card">
          <div class="kpi-header">
            <span class="kpi-label">Today's Returns</span>
            <span class="kpi-icon-pill" aria-hidden="true" style="background:#ccfbf1; color:#0d9488;">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
            </span>
          </div>
          <div class="kpi-value"><?= $kpiReturnsToday ?></div>
          <div class="kpi-subtext">
            <?php if ($kpiOverdue > 0): ?>
              <span style="color:#dc2626; font-weight:700;"><?= $kpiOverdue ?> overdue</span>
            <?php else: ?>
              <span>All returns on track</span>
            <?php endif; ?>
          </div>
        </a>

        <!-- Unpaid Balances Card -->
        <a href="?tab=payments" class="portal-kpi-card">
          <div class="kpi-header">
            <span class="kpi-label">Unsettled Balances</span>
            <span class="kpi-icon-pill" aria-hidden="true" style="background:#f1f5f9; color:#475569;">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
            </span>
          </div>
          <div class="kpi-value" style="font-size:1.35rem; <?= $kpiUnpaidTotal > 0 ? 'color:#dc2626;' : 'color:#059669;' ?>">
            ₱<?= number_format($kpiUnpaidTotal, 2) ?>
          </div>
          <div class="kpi-subtext">
            <span><?= count($paymentCandidates) ?> open balance ledger(s)</span>
          </div>
        </a>
      </section>

      <!-- 4. Operations Spotlight Card (Matches customer spotlight card design) -->
      <?php if ($spotlightOp): ?>
        <?php
          $spotlightImg = vehicle_image($spotlightOp['image_url'] ?? '');
          $spotlightCarName = ($spotlightOp['brand'] ?? 'Vehicle') . ' ' . ($spotlightOp['model'] ?? '');
          $spotlightRef = $spotlightOp['reference_number'] ?? '';
          $spotlightCustomer = $spotlightOp['customer_name'] ?? '';
          $spotlightCategory = $spotlightOp['category'] ?? 'Sedan';
        ?>
        <section class="portal-spotlight-card" aria-label="Spotlight operational task">
          <div class="spotlight-accent-bar" style="<?= $spotlightType === 'overdue' ? 'background:#dc2626;' : ($spotlightType === 'pickup' ? 'background:#0284c7;' : 'background:#e53e3e;') ?>"></div>
          <div class="spotlight-vehicle-media">
            <img src="<?= e($spotlightImg) ?>" alt="<?= e($spotlightCarName) ?>" class="spotlight-vehicle-img" loading="lazy">
            <span class="spotlight-category-tag"><?= e($spotlightCategory) ?></span>
          </div>
          <div class="spotlight-content">
            <div class="spotlight-header-row">
              <div class="spotlight-title-group">
                <h3><?= e($spotlightCarName) ?></h3>
                <span class="spotlight-reference">Booking Ref: <?= e($spotlightRef) ?> &bull; Customer: <?= e($spotlightCustomer) ?></span>
              </div>
              <div class="spotlight-status-group">
                <?php if ($spotlightType === 'overdue'): ?>
                  <span class="portal-badge badge-danger">OVERDUE RETURN</span>
                <?php elseif ($spotlightType === 'pickup'): ?>
                  <span class="portal-badge badge-info">PICKUP DUE TODAY</span>
                <?php elseif ($spotlightType === 'pending'): ?>
                  <span class="portal-badge badge-warning">AWAITING REVIEW</span>
                <?php else: ?>
                  <span class="portal-badge badge-success">ACTIVE TRIP</span>
                <?php endif; ?>
              </div>
            </div>

            <div class="spotlight-schedule-grid">
              <div class="spotlight-schedule-item">
                <dt>Scheduled Pickup</dt>
                <dd><?= date('M j, Y g:i A', strtotime($spotlightOp['requested_pickup_at'])) ?></dd>
                <span style="font-size:0.75rem; color:#64748b;">Plate: <?= e($spotlightOp['plate_number'] ?: 'NO-PLATE') ?></span>
              </div>
              <div class="spotlight-schedule-item">
                <dt>Scheduled Return</dt>
                <dd><?= date('M j, Y g:i A', strtotime($spotlightOp['requested_return_at'] ?? $spotlightOp['expected_return_at'])) ?></dd>
                <span style="font-size:0.75rem; color:#64748b;">Rate: ₱<?= number_format((float)$spotlightOp['daily_rate'], 2) ?>/day</span>
              </div>
            </div>

            <div class="spotlight-action-row">
              <div class="spotlight-pricing">
                <strong>₱<?= number_format((float)($spotlightOp['total_amount'] ?? 0), 2) ?></strong>
                <span>Total Amount</span>
              </div>
              <div class="spotlight-buttons">
                <?php if ($spotlightType === 'overdue'): ?>
                  <?php $spLateFee = late_fee_amount($spotlightOp['requested_return_at'], date('Y-m-d H:i:s'), (float)$spotlightOp['daily_rate']); ?>
                  <button type="button" class="portal-btn-primary"
                          data-modal-open="return-modal"
                          data-rental-id="<?= (int)$spotlightOp['id'] ?>"
                          data-reference="<?= e($spotlightOp['reference_number']) ?>"
                          data-customer="<?= e($spotlightOp['customer_name']) ?>"
                          data-vehicle="<?= e($spotlightCarName . ' (' . ($spotlightOp['plate_number'] ?: 'NO-PLATE') . ')') ?>"
                          data-start-mileage="<?= (int)($spotlightOp['starting_mileage'] ?? 0) ?>"
                          data-late-hours="<?= (int)$spLateFee['hours'] ?>"
                          data-late-amount="<?= number_format($spLateFee['amount'], 2) ?>">
                    <span>Process Return Check-in</span>
                  </button>
                <?php elseif ($spotlightType === 'pickup'): ?>
                  <button type="button" class="portal-btn-primary"
                          data-modal-open="pickup-modal"
                          data-reservation-id="<?= (int)$spotlightOp['id'] ?>"
                          data-reference="<?= e($spotlightOp['reference_number']) ?>"
                          data-customer="<?= e($spotlightOp['customer_name']) ?>"
                          data-vehicle="<?= e($spotlightCarName . ' (' . ($spotlightOp['plate_number'] ?: 'NO-PLATE') . ')') ?>"
                          data-start-mileage="<?= (int)($spotlightOp['current_mileage'] ?? 0) ?>">
                    <span>Process Vehicle Turnover</span>
                  </button>
                <?php elseif ($spotlightType === 'pending'): ?>
                  <form method="post" style="display:inline;" onsubmit="return confirm('Approve this reservation for <?= e($spotlightCarName) ?>?');">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="reservation_id" value="<?= (int)$spotlightOp['id'] ?>">
                    <input type="hidden" name="action" value="approve">
                    <button type="submit" class="portal-btn-primary">
                      <span>Approve Reservation</span>
                    </button>
                  </form>
                <?php else: ?>
                  <a href="?tab=returns" class="portal-btn-outline">
                    <span>View Field Rentals</span>
                  </a>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </section>
      <?php endif; ?>

      <!-- 6. SECTION A: PENDING APPROVALS QUEUE (.portal-card) -->
      <?php if ($activeTab === 'overview' || $activeTab === 'reservations'): ?>
        <section class="portal-card" id="reservations-section">
          <div class="portal-card-header">
            <div class="portal-card-title-group">
              <h2>Reservations Awaiting Staff Review</h2>
              <p>Verify customer account standing, ensure vehicle availability, and confirm or reject requests.</p>
            </div>
          </div>

          <?php if (empty($pendingReservations)): ?>
            <div class="ops-empty-state">
              <div class="ops-empty-icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 14 14"/></svg>
              </div>
              <h3>All Reservations Cleared</h3>
              <p>There are no pending rental reservations requiring staff approval at this time.</p>
            </div>
          <?php else: ?>
            <div class="portal-table-container">
              <table class="portal-table">
                <thead>
                  <tr>
                    <th>Booking Reference</th>
                    <th>Assigned Vehicle</th>
                    <th>Customer &amp; Standing</th>
                    <th>Rental Schedule</th>
                    <th>Amount</th>
                    <th>Review Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($pendingReservations as $r): ?>
                    <?php
                      $standingPoints = (int)$r['active_points'];
                      $standingType = $standingPoints >= 5 ? 'restricted' : ($standingPoints >= 3 ? 'warning' : 'good');
                      $rCarImg = vehicle_image($r['image_url'] ?? '');
                    ?>
                    <tr>
                      <td>
                        <strong style="color:#0f172a; font-size:0.88rem;"><?= e($r['reference_number']) ?></strong>
                        <div style="font-size:0.75rem; color:#64748b; margin-top:2px;">
                          <?= date('M j, Y g:i A', strtotime($r['created_at'])) ?>
                        </div>
                      </td>
                      <td>
                        <div class="ops-car-cell">
                          <div class="ops-car-thumb" style="background-image:url('<?= e($rCarImg) ?>');"></div>
                          <div class="ops-car-meta">
                            <span class="ops-car-name"><?= e($r['brand'] . ' ' . $r['model']) ?></span>
                            <div class="ops-car-tags">
                              <span class="plate-tag"><?= e($r['plate_number'] ?: 'NO-PLATE') ?></span>
                              <span class="category-mini-tag"><?= e(ucfirst($r['category'] ?? 'Vehicle')) ?></span>
                            </div>
                          </div>
                        </div>
                      </td>
                      <td>
                        <div class="ops-customer-cell">
                          <span class="ops-customer-name"><?= e($r['customer_name']) ?></span>
                          <span class="ops-customer-contact"><?= e($r['customer_email']) ?></span>
                          <?php if (!empty($r['customer_phone'])): ?>
                            <span class="ops-customer-contact"><?= e($r['customer_phone']) ?></span>
                          <?php endif; ?>
                          <span class="customer-standing-pill standing-<?= e($standingType) ?>">
                            <span class="role-pill-dot"></span>
                            <span><?= ucfirst($standingType) ?> (<?= $standingPoints ?> pt<?= $standingPoints === 1 ? '' : 's' ?>)</span>
                          </span>
                        </div>
                      </td>
                      <td>
                        <div class="ops-schedule-cell">
                          <span class="ops-schedule-date"><?= date('M j, Y g:i A', strtotime($r['requested_pickup_at'])) ?></span>
                          <span class="ops-schedule-time">to <?= date('M j, Y g:i A', strtotime($r['requested_return_at'])) ?></span>
                        </div>
                      </td>
                      <td>
                        <strong style="color:#0f172a; font-size:0.95rem;">₱<?= number_format((float)$r['total_amount'], 2) ?></strong>
                      </td>
                      <td>
                        <div class="ops-actions">
                          <form method="post" style="display:inline;" onsubmit="return confirm('Approve this reservation for <?= e($r['brand'] . ' ' . $r['model']) ?>?');">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="reservation_id" value="<?= (int)$r['id'] ?>">
                            <input type="hidden" name="action" value="approve">
                            <button type="submit" class="btn-ops-accent">
                              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                              <span>Approve</span>
                            </button>
                          </form>

                          <button type="button" class="btn-ops-secondary"
                                  data-modal-open="reject-modal"
                                  data-reservation-id="<?= (int)$r['id'] ?>"
                                  data-reference="<?= e($r['reference_number']) ?>"
                                  data-customer="<?= e($r['customer_name']) ?>">
                            <span>Reject</span>
                          </button>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>
      <?php endif; ?>

      <!-- 7. SECTION B: READY FOR PICKUP TURNOVER DESK (.portal-card) -->
      <?php if ($activeTab === 'overview' || $activeTab === 'pickups'): ?>
        <section class="portal-card" id="pickups-section">
          <div class="portal-card-header">
            <div class="portal-card-title-group">
              <h2>Confirmed Reservations &bull; Ready for Turnover</h2>
              <p>Perform identity checks, verify condition and initial mileage, verify balance settlement, and dispatch the vehicle.</p>
            </div>
          </div>

          <?php if (empty($readyPickupsData)): ?>
            <div class="ops-empty-state">
              <div class="ops-empty-icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
              </div>
              <h3>No Pending Pickups</h3>
              <p>There are no confirmed reservations awaiting vehicle turnover right now.</p>
            </div>
          <?php else: ?>
            <div class="portal-table-container">
              <table class="portal-table">
                <thead>
                  <tr>
                    <th>Reference</th>
                    <th>Vehicle &amp; Plate</th>
                    <th>Customer</th>
                    <th>Scheduled Pickup</th>
                    <th>Payment Status</th>
                    <th>Turnover Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($readyPickupsData as $rp): ?>
                    <?php
                      $isOverdueGrace = new DateTimeImmutable('now') >= $rp['grace_ends'];
                      $hasBalance = $rp['balance']['outstanding'] > 0.0001;
                      $rpCarImg = vehicle_image($rp['image_url'] ?? '');
                    ?>
                    <tr>
                      <td>
                        <strong style="color:#0f172a; font-size:0.88rem;"><?= e($rp['reference_number']) ?></strong>
                      </td>
                      <td>
                        <div class="ops-car-cell">
                          <div class="ops-car-thumb" style="background-image:url('<?= e($rpCarImg) ?>');"></div>
                          <div class="ops-car-meta">
                            <span class="ops-car-name"><?= e($rp['brand'] . ' ' . $rp['model']) ?></span>
                            <div class="ops-car-tags">
                              <span class="plate-tag"><?= e($rp['plate_number'] ?: 'NO-PLATE') ?></span>
                            </div>
                          </div>
                        </div>
                      </td>
                      <td>
                        <div class="ops-customer-cell">
                          <span class="ops-customer-name"><?= e($rp['customer_name']) ?></span>
                          <span class="ops-customer-contact"><?= e($rp['customer_phone'] ?: $rp['customer_email']) ?></span>
                        </div>
                      </td>
                      <td>
                        <div class="ops-schedule-cell">
                          <span class="ops-schedule-date"><?= date('M j, Y g:i A', strtotime($rp['requested_pickup_at'])) ?></span>
                          <?php if ($isOverdueGrace): ?>
                            <span style="color:#dc2626; font-size:0.75rem; font-weight:700;">+30m Grace Period Ended</span>
                          <?php else: ?>
                            <span style="color:#64748b; font-size:0.75rem;">Grace ends <?= $rp['grace_ends']->format('g:i A') ?></span>
                          <?php endif; ?>
                        </div>
                      </td>
                      <td>
                        <?php if ($hasBalance): ?>
                          <span class="ops-status-badge badge-pending" title="Base rental balance must be paid before turnover">
                            ₱<?= number_format($rp['balance']['outstanding'], 2) ?> Due
                          </span>
                        <?php else: ?>
                          <span class="ops-status-badge badge-ongoing">
                            Fully Cleared
                          </span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <div class="ops-actions">
                          <?php if ($hasBalance): ?>
                            <a href="?tab=payments&reservation_id=<?= (int)$rp['id'] ?>" class="btn-ops-accent" title="Collect base payment prior to turnover">
                              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                              <span>Pay First</span>
                            </a>
                          <?php else: ?>
                            <button type="button" class="btn-ops-primary"
                                    data-modal-open="pickup-modal"
                                    data-reservation-id="<?= (int)$rp['id'] ?>"
                                    data-reference="<?= e($rp['reference_number']) ?>"
                                    data-customer="<?= e($rp['customer_name']) ?>"
                                    data-vehicle="<?= e($rp['brand'] . ' ' . $rp['model'] . ' (' . ($rp['plate_number'] ?: 'NO-PLATE') . ')') ?>"
                                    data-start-mileage="<?= (int)($rp['current_mileage'] ?? 0) ?>">
                              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                              <span>Process Pickup</span>
                            </button>
                          <?php endif; ?>

                          <?php if ($isOverdueGrace): ?>
                            <button type="button" class="btn-ops-danger"
                                    data-modal-open="noshow-modal"
                                    data-reservation-id="<?= (int)$rp['id'] ?>"
                                    data-reference="<?= e($rp['reference_number']) ?>"
                                    data-customer="<?= e($rp['customer_name']) ?>">
                              <span>Mark No-Show</span>
                            </button>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>
      <?php endif; ?>

      <!-- 8. SECTION C: RETURNS & CHECK-IN DESK (.portal-card) -->
      <?php if ($activeTab === 'overview' || $activeTab === 'returns'): ?>
        <section class="portal-card" id="returns-section">
          <div class="portal-card-header">
            <div class="portal-card-title-group">
              <h2>Active Field Rentals &bull; Ongoing Dispatches</h2>
              <p>Monitor ongoing trips, check for late arrivals, record return inspections, and evaluate damages.</p>
            </div>
          </div>

          <?php if (empty($ongoingRentals)): ?>
            <div class="ops-empty-state">
              <div class="ops-empty-icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
              </div>
              <h3>No Vehicles Currently Dispatched</h3>
              <p>There are no active rentals out in the field right now.</p>
            </div>
          <?php else: ?>
            <div class="portal-table-container">
              <table class="portal-table">
                <thead>
                  <tr>
                    <th>Booking Ref</th>
                    <th>Vehicle &amp; Plate</th>
                    <th>Customer</th>
                    <th>Expected Return</th>
                    <th>Trip Status</th>
                    <th>Check-in Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($ongoingRentals as $og): ?>
                    <?php
                      $isLate = new DateTimeImmutable('now') > new DateTimeImmutable($og['expected_return_at']);
                      $lateFee = late_fee_amount($og['requested_return_at'], date('Y-m-d H:i:s'), (float)$og['daily_rate']);
                      $ogCarImg = vehicle_image($og['image_url'] ?? '');
                    ?>
                    <tr>
                      <td>
                        <strong style="color:#0f172a; font-size:0.88rem;"><?= e($og['reference_number']) ?></strong>
                        <div style="font-size:0.75rem; color:#64748b;">
                          Picked up: <?= date('M j, g:i A', strtotime($og['picked_up_at'])) ?>
                        </div>
                      </td>
                      <td>
                        <div class="ops-car-cell">
                          <div class="ops-car-thumb" style="background-image:url('<?= e($ogCarImg) ?>');"></div>
                          <div class="ops-car-meta">
                            <span class="ops-car-name"><?= e($og['brand'] . ' ' . $og['model']) ?></span>
                            <div class="ops-car-tags">
                              <span class="plate-tag"><?= e($og['plate_number'] ?: 'NO-PLATE') ?></span>
                              <span class="category-mini-tag"><?= (int)$og['starting_mileage'] ?> km start</span>
                            </div>
                          </div>
                        </div>
                      </td>
                      <td>
                        <div class="ops-customer-cell">
                          <span class="ops-customer-name"><?= e($og['customer_name']) ?></span>
                          <span class="ops-customer-contact"><?= e($og['customer_phone'] ?: $og['customer_email']) ?></span>
                        </div>
                      </td>
                      <td>
                        <div class="ops-schedule-cell">
                          <span class="ops-schedule-date"><?= date('M j, Y g:i A', strtotime($og['expected_return_at'])) ?></span>
                          <?php if ($isLate): ?>
                            <span style="color:#dc2626; font-weight:700; font-size:0.75rem;">OVERDUE (<?= $lateFee['hours'] ?> hr<?= $lateFee['hours'] === 1 ? '' : 's' ?>)</span>
                          <?php else: ?>
                            <span style="color:#10b981; font-size:0.75rem;">On Schedule</span>
                          <?php endif; ?>
                        </div>
                      </td>
                      <td>
                        <?php if ($isLate): ?>
                          <span class="ops-status-badge badge-overdue">
                            Late (Est. ₱<?= number_format($lateFee['amount'], 2) ?>)
                          </span>
                        <?php else: ?>
                          <span class="ops-status-badge badge-ongoing">
                            In Progress
                          </span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <div class="ops-actions">
                          <button type="button" class="btn-ops-accent"
                                  data-modal-open="return-modal"
                                  data-rental-id="<?= (int)$og['id'] ?>"
                                  data-reference="<?= e($og['reference_number']) ?>"
                                  data-customer="<?= e($og['customer_name']) ?>"
                                  data-vehicle="<?= e($og['brand'] . ' ' . $og['model'] . ' (' . ($og['plate_number'] ?: 'NO-PLATE') . ')') ?>"
                                  data-start-mileage="<?= (int)$og['starting_mileage'] ?>"
                                  data-late-hours="<?= (int)$lateFee['hours'] ?>"
                                  data-late-amount="<?= number_format($lateFee['amount'], 2) ?>">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                            <span>Record Return</span>
                          </button>

                          <?php if ($isLate): ?>
                            <button type="button" class="btn-ops-danger"
                                    data-modal-open="incident-modal"
                                    data-rental-id="<?= (int)$og['id'] ?>"
                                    data-reference="<?= e($og['reference_number']) ?>"
                                    data-customer="<?= e($og['customer_name']) ?>">
                              <span>Report Incident</span>
                            </button>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>

        <!-- Returned Vehicles Awaiting Final Settlement -->
        <?php if (!empty($returnedRentalsData)): ?>
          <section class="portal-card" style="margin-top:24px;">
            <div class="portal-card-header">
              <div class="portal-card-title-group">
                <h2>Returned Vehicles &bull; Settlement &amp; Closure</h2>
                <p>Vehicles physically checked-in. Collect any remaining late fees or damages before completing the rental.</p>
              </div>
            </div>

            <div class="portal-table-container">
              <table class="portal-table">
                <thead>
                  <tr>
                    <th>Booking Ref</th>
                    <th>Vehicle</th>
                    <th>Customer</th>
                    <th>Return Odometer</th>
                    <th>Balance Ledger</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($returnedRentalsData as $rr): ?>
                    <?php $bal = $rr['balance']; ?>
                    <tr>
                      <td>
                        <strong style="color:#0f172a; font-size:0.88rem;"><?= e($rr['reference_number']) ?></strong>
                        <div style="font-size:0.75rem; color:#64748b;">
                          Returned: <?= date('M j, g:i A', strtotime($rr['returned_at'])) ?>
                        </div>
                      </td>
                      <td>
                        <span style="font-weight:700; color:#0f172a;"><?= e($rr['brand'] . ' ' . $rr['model']) ?></span>
                        <div style="font-size:0.75rem; color:#64748b;">Plate: <?= e($rr['plate_number'] ?: 'NO-PLATE') ?></div>
                      </td>
                      <td>
                        <span style="font-weight:600; color:#0f172a;"><?= e($rr['customer_name']) ?></span>
                      </td>
                      <td>
                        <span style="font-weight:700; color:#0f172a;"><?= (int)$rr['ending_mileage'] ?> km</span>
                        <div style="font-size:0.75rem; color:#64748b;">
                          +<?= (int)$rr['ending_mileage'] - (int)$rr['starting_mileage'] ?> km trip
                        </div>
                      </td>
                      <td>
                        <?php if ($bal['outstanding'] > 0.0001): ?>
                          <span class="ops-status-badge badge-pending">
                            ₱<?= number_format($bal['outstanding'], 2) ?> Outstanding
                          </span>
                        <?php else: ?>
                          <span class="ops-status-badge badge-ongoing">
                            Fully Settled
                          </span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <div class="ops-actions">
                          <?php if ($bal['outstanding'] > 0.0001): ?>
                            <a href="?tab=payments&reservation_id=<?= (int)$rr['reservation_id'] ?>" class="btn-ops-accent">
                              <span>Collect ₱<?= number_format($bal['outstanding'], 2) ?></span>
                            </a>
                          <?php else: ?>
                            <form method="post" style="display:inline;">
                              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                              <input type="hidden" name="rental_id" value="<?= (int)$rr['id'] ?>">
                              <input type="hidden" name="action" value="complete">
                              <button type="submit" class="btn-ops-primary">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                <span>Complete &amp; Close</span>
                              </button>
                            </form>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </section>
        <?php endif; ?>
      <?php endif; ?>

      <!-- 9. SECTION D: COUNTER PAYMENT DESK (.portal-card) -->
      <?php if ($activeTab === 'overview' || $activeTab === 'payments'): ?>
        <section class="staff-payment-grid" id="payments-section">
          <!-- Record Payment Counter Card -->
          <div class="portal-card">
            <div class="portal-card-header">
              <div class="portal-card-title-group">
                <h2>Counter Payment Desk</h2>
                <p>Collect in-person payments, compute resulting balances, and issue official receipts.</p>
              </div>
            </div>

            <?php if (empty($paymentCandidates)): ?>
              <div class="ops-empty-state" style="padding:32px 16px;">
                <div class="ops-empty-icon" style="width:48px; height:48px;">
                  <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                <h3>All Active Balances Cleared</h3>
                <p>Every current reservation and active rental has a zero outstanding balance.</p>
              </div>
            <?php else: ?>
              <form method="post" id="counter-payment-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="payment">

                <label for="reservation_id_select" style="font-size:0.8rem; font-weight:700; color:#334155; display:block; margin-bottom:6px;">
                  Select Reservation / Rental Ledger:
                </label>
                <select name="reservation_id" id="reservation_id_select" required style="width:100%; margin-bottom:16px;" onchange="updatePaymentCounterDetails(this);">
                  <option value="">-- Choose active reservation with outstanding balance --</option>
                  <?php
                    $preselectedId = filter_input(INPUT_GET, 'reservation_id', FILTER_VALIDATE_INT);
                    foreach ($paymentCandidates as $pc):
                      $bal = $pc['balance'];
                      $selected = ($preselectedId === (int)$pc['reservation_id']) ? 'selected' : '';
                  ?>
                    <option value="<?= (int)$pc['reservation_id'] ?>"
                            data-outstanding="<?= htmlspecialchars($bal['outstanding']) ?>"
                            data-charged="<?= htmlspecialchars($bal['charged']) ?>"
                            data-paid="<?= htmlspecialchars($bal['paid']) ?>"
                            data-customer="<?= e($pc['customer_name']) ?>"
                            data-vehicle="<?= e($pc['brand'] . ' ' . $pc['model']) ?>"
                            <?= $selected ?>>
                      <?= e($pc['reference_number']) ?> &mdash; <?= e($pc['customer_name']) ?> (₱<?= number_format($bal['outstanding'], 2) ?> due)
                    </option>
                  <?php endforeach; ?>
                </select>

                <!-- Dynamic Balance Box -->
                <div class="payment-info-box" id="payment-info-box">
                  <div class="payment-info-stat">
                    <label>Total Charged</label>
                    <strong id="counter-charged">₱0.00</strong>
                  </div>
                  <div class="payment-info-stat">
                    <label>Paid to Date</label>
                    <strong id="counter-paid" style="color:#059669;">₱0.00</strong>
                  </div>
                  <div class="payment-info-stat">
                    <label>Outstanding</label>
                    <strong id="counter-outstanding" style="color:#dc2626;">₱0.00</strong>
                  </div>
                </div>

                <!-- Payment Amount -->
                <div style="margin-bottom:16px;">
                  <label for="payment_amount" style="font-size:0.8rem; font-weight:700; color:#334155; display:block; margin-bottom:6px;">
                    Payment Amount (₱):
                  </label>
                  <input type="number" step="0.01" min="0.01" name="amount" id="payment_amount" required placeholder="0.00" style="width:100%; font-size:1.1rem; font-weight:700;" oninput="updateResultingBalance();">
                </div>

                <!-- Payment Method Cards -->
                <label style="font-size:0.8rem; font-weight:700; color:#334155; display:block; margin-bottom:6px;">
                  Counter Payment Method:
                </label>
                <div class="payment-methods-grid">
                  <label class="payment-method-card active">
                    <input type="radio" name="method" value="cash" checked>
                    <div class="payment-method-meta">
                      <strong>Cash Counter</strong>
                      <span>Front desk cash drawer</span>
                    </div>
                  </label>

                  <label class="payment-method-card">
                    <input type="radio" name="method" value="card">
                    <div class="payment-method-meta">
                      <strong>POS Terminal / Card</strong>
                      <span>Debit / Credit swipe</span>
                    </div>
                  </label>

                  <label class="payment-method-card">
                    <input type="radio" name="method" value="gcash">
                    <div class="payment-method-meta">
                      <strong>GCash / E-Wallet</strong>
                      <span>Mobile scan &amp; transfer</span>
                    </div>
                  </label>

                  <label class="payment-method-card">
                    <input type="radio" name="method" value="bank_transfer">
                    <div class="payment-method-meta">
                      <strong>Bank Deposit</strong>
                      <span>Wire or online transfer</span>
                    </div>
                  </label>
                </div>

                <!-- Reference Number -->
                <div style="margin-bottom:16px;">
                  <label for="payment_ref" style="font-size:0.8rem; font-weight:700; color:#334155; display:block; margin-bottom:6px;">
                    Transaction Reference / Auth Code (Optional):
                  </label>
                  <input type="text" name="reference_number" id="payment_ref" placeholder="e.g. POS Auth #, Cash Receipt #, or Slip Ref" style="width:100%;">
                </div>

                <!-- Resulting Balance Live Banner -->
                <div class="resulting-balance-banner">
                  <span>Resulting Balance after payment:</span>
                  <strong id="resulting-balance-val">₱0.00</strong>
                </div>

                <button type="submit" class="portal-btn-primary" style="width:100%; justify-content:center; padding:12px; margin-top:8px;">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                  <span>Record Payment &amp; Issue Official Receipt</span>
                </button>
              </form>
            <?php endif; ?>
          </div>

          <!-- Recent Official Receipts Issued -->
          <div class="portal-card">
            <div class="portal-card-header">
              <div class="portal-card-title-group">
                <h2>Recent Counter Receipts</h2>
                <p>Audit trail of counter payments recorded by staff.</p>
              </div>
            </div>

            <?php if (empty($recentPayments)): ?>
              <div class="ops-empty-state" style="padding:32px 16px;">
                <p>No recent counter payment transactions recorded.</p>
              </div>
            <?php else: ?>
              <div class="portal-table-container">
                <table class="portal-table">
                  <thead>
                    <tr>
                      <th>Receipt #</th>
                      <th>Customer &amp; Ref</th>
                      <th>Amount &amp; Method</th>
                      <th>Date</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($recentPayments as $p): ?>
                      <tr>
                        <td>
                          <strong style="color:#0f172a; font-size:0.8rem;"><?= e($p['receipt_number'] ?: 'RCPT-' . $p['id']) ?></strong>
                        </td>
                        <td>
                          <div style="font-weight:600; color:#0f172a; font-size:0.82rem;"><?= e($p['customer_name']) ?></div>
                          <span style="font-size:0.74rem; color:#64748b;"><?= e($p['reference_number']) ?></span>
                        </td>
                        <td>
                          <strong style="color:#059669; font-size:0.88rem;">₱<?= number_format((float)$p['amount'], 2) ?></strong>
                          <div style="font-size:0.72rem; color:#64748b; text-transform:uppercase;"><?= e($p['method']) ?></div>
                        </td>
                        <td>
                          <span style="font-size:0.75rem; color:#64748b;"><?= date('M j, g:i A', strtotime($p['paid_at'])) ?></span>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </section>
      <?php endif; ?>

      <!-- 10. SECTION E: INCIDENTS & DAMAGE REPORTS (.portal-card) -->
      <?php if ($activeTab === 'overview' || $activeTab === 'incidents'): ?>
        <section class="portal-card" id="incidents-section">
          <div class="portal-card-header">
            <div class="portal-card-title-group">
              <h2>Missed Return Incidents &amp; Contact Timeline</h2>
              <p>Track overdue non-returns, record customer communication attempts, and review inspection damage reports.</p>
            </div>
          </div>

          <?php if (empty($incidentsWithContacts)): ?>
            <div class="ops-empty-state">
              <div class="ops-empty-icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
              </div>
              <h3>No Open Return Incidents</h3>
              <p>No non-return incidents are currently open or undergoing contact attempts.</p>
            </div>
          <?php else: ?>
            <div class="portal-table-container">
              <table class="portal-table">
                <thead>
                  <tr>
                    <th>Incident Details</th>
                    <th>Vehicle &amp; Customer</th>
                    <th>Status</th>
                    <th>Contact Log</th>
                    <th>Record Contact Attempt</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($incidentsWithContacts as $inc): ?>
                    <tr>
                      <td>
                        <strong style="color:#0f172a;"><?= e($inc['reference_number']) ?></strong>
                        <div style="font-size:0.8rem; color:#475569; margin-top:4px;">
                          <?= e($inc['description']) ?>
                        </div>
                      </td>
                      <td>
                        <span style="font-weight:700; color:#0f172a;"><?= e($inc['brand'] . ' ' . $inc['model']) ?></span>
                        <div style="font-size:0.75rem; color:#64748b;">Plate: <?= e($inc['plate_number'] ?: 'NO-PLATE') ?></div>
                        <div style="font-weight:600; color:#0f172a; margin-top:4px; font-size:0.82rem;"><?= e($inc['customer_name']) ?></div>
                        <div style="font-size:0.75rem; color:#64748b;"><?= e($inc['customer_phone'] ?: $inc['customer_email']) ?></div>
                      </td>
                      <td>
                        <span class="ops-status-badge <?= $inc['status'] === 'open' ? 'badge-overdue' : 'badge-pending' ?>">
                          <?= ucfirst(str_replace('_', ' ', $inc['status'])) ?>
                        </span>
                      </td>
                      <td>
                        <?php if (empty($inc['attempts'])): ?>
                          <span style="font-size:0.75rem; color:#94a3b8;">No attempts recorded yet</span>
                        <?php else: ?>
                          <div style="display:flex; flex-direction:column; gap:6px;">
                            <?php foreach ($inc['attempts'] as $att): ?>
                              <div style="font-size:0.75rem; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:4px 8px;">
                                <strong><?= ucfirst($att['contact_method']) ?></strong>
                                <span style="color:#64748b;">(<?= date('M j, g:i A', strtotime($att['attempted_at'])) ?>):</span>
                                <div><?= e($att['outcome']) ?></div>
                              </div>
                            <?php endforeach; ?>
                          </div>
                        <?php endif; ?>
                      </td>
                      <td>
                        <form method="post" style="display:grid; gap:8px;">
                          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                          <input type="hidden" name="rental_id" value="<?= (int)$inc['rental_id'] ?>">
                          <input type="hidden" name="incident_id" value="<?= (int)$inc['id'] ?>">
                          <input type="hidden" name="action" value="contact_attempt">

                          <select name="contact_method" required style="font-size:0.8rem; padding:6px;">
                            <option value="phone">Phone Call</option>
                            <option value="email">Email</option>
                            <option value="in_person">In Person / Counter</option>
                            <option value="other">Other Channel</option>
                          </select>

                          <input type="text" name="outcome" maxlength="255" required placeholder="Outcome / Notes" style="font-size:0.8rem; padding:6px;">

                          <button type="submit" class="btn-ops-primary" style="justify-content:center;">
                            <span>Save Contact</span>
                          </button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>

        <!-- Recorded Damage Reports -->
        <?php if (!empty($damageReports)): ?>
          <section class="portal-card" style="margin-top:24px;">
            <div class="portal-card-header">
              <div class="portal-card-title-group">
                <h2>Vehicle Inspection Damage Log</h2>
                <p>Factual inspection findings recorded by staff. Financial charges require administrator approval.</p>
              </div>
            </div>

            <div class="portal-table-container">
              <table class="portal-table">
                <thead>
                  <tr>
                    <th>Booking Ref</th>
                    <th>Vehicle</th>
                    <th>Customer</th>
                    <th>Damage Description</th>
                    <th>Estimated Cost</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($damageReports as $dr): ?>
                    <tr>
                      <td><strong style="color:#0f172a;"><?= e($dr['reference_number']) ?></strong></td>
                      <td>
                        <span style="font-weight:700; color:#0f172a;"><?= e($dr['brand'] . ' ' . $dr['model']) ?></span>
                        <div style="font-size:0.75rem; color:#64748b;">Plate: <?= e($dr['plate_number'] ?: 'NO-PLATE') ?></div>
                      </td>
                      <td><span style="font-weight:600; color:#0f172a;"><?= e($dr['customer_name']) ?></span></td>
                      <td><div style="max-width:320px; font-size:0.82rem; color:#334155;"><?= e($dr['description']) ?></div></td>
                      <td>
                        <?php if ($dr['estimated_cost']): ?>
                          <strong style="color:#dc2626;">₱<?= number_format((float)$dr['estimated_cost'], 2) ?></strong>
                        <?php else: ?>
                          <span style="color:#94a3b8; font-size:0.75rem;">Pending Appraisal</span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <span class="ops-status-badge badge-pending">
                          <?= ucfirst(str_replace('_', ' ', $dr['status'])) ?>
                        </span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </section>
        <?php endif; ?>
      <?php endif; ?>

    </div>
  </div>
</main>

<!-- =========================================================================
     MODAL 1: GUIDED PICKUP TURNOVER CHECKLIST
     ========================================================================= -->
<div class="ops-modal" id="pickup-modal" role="dialog" aria-modal="true" aria-labelledby="pickup-modal-title">
  <div class="ops-modal-backdrop" onclick="closeOpsModal('pickup-modal');"></div>
  <div class="ops-modal-card">
    <div class="ops-modal-header">
      <div>
        <h3 id="pickup-modal-title">Guided Vehicle Turnover Checklist</h3>
        <p>Complete physical handover steps before releasing vehicle keys to the customer.</p>
      </div>
      <button type="button" class="ops-modal-close" onclick="closeOpsModal('pickup-modal');" aria-label="Close modal">&times;</button>
    </div>

    <form method="post" id="pickup-form">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="reservation_id" id="pickup-reservation-id">
      <input type="hidden" name="action" value="pickup">

      <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:12px 16px; margin-bottom:16px;">
        <div style="font-size:0.75rem; color:#64748b;">Reservation Reference:</div>
        <strong id="pickup-ref-display" style="font-size:1rem; color:#0f172a;"></strong>
        <div style="font-size:0.82rem; color:#334155; margin-top:2px;">
          Customer: <strong id="pickup-customer-display"></strong> &bull; Vehicle: <span id="pickup-vehicle-display"></span>
        </div>
      </div>

      <!-- Guided Checklist Steps -->
      <div class="checklist-section">
        <div class="checklist-item">
          <input type="checkbox" id="chk-id" required>
          <label for="chk-id">
            <strong>1. Customer Identity &amp; Driver's License Verified</strong>
            <span>Confirm customer's physical driver's license is valid and matches the account name.</span>
          </label>
        </div>

        <div class="checklist-item">
          <input type="checkbox" id="chk-plate" required>
          <label for="chk-plate">
            <strong>2. Physical Vehicle &amp; Plate Match</strong>
            <span>Verify the license plate and physical vehicle keys correspond with the assigned fleet record.</span>
          </label>
        </div>

        <div class="checklist-item">
          <input type="checkbox" id="chk-walkaround" required>
          <label for="chk-walkaround">
            <strong>3. Pre-Rental Walkaround Inspection</strong>
            <span>Inspect body panels, tires, glass, and interior cabin cleanliness with the customer present.</span>
          </label>
        </div>
      </div>

      <!-- Odometer and Fuel -->
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
        <div>
          <label for="starting_mileage" style="font-size:0.8rem; font-weight:700; color:#334155; display:block; margin-bottom:6px;">
            Starting Odometer (km):
          </label>
          <input type="number" name="starting_mileage" id="starting_mileage" min="0" required placeholder="e.g. 15420" style="width:100%;">
        </div>
        <div>
          <label for="starting_fuel_level" style="font-size:0.8rem; font-weight:700; color:#334155; display:block; margin-bottom:6px;">
            Starting Fuel Level (%):
          </label>
          <input type="number" name="starting_fuel_level" id="starting_fuel_level" min="0" max="100" step="0.1" value="100" required placeholder="0 - 100" style="width:100%;">
        </div>
      </div>

      <!-- Pre-Rental Condition Notes -->
      <div style="margin-bottom:16px;">
        <label for="condition_notes" style="font-size:0.8rem; font-weight:700; color:#334155; display:block; margin-bottom:6px;">
          Pre-Rental Condition Notes:
        </label>
        <textarea name="condition_notes" id="condition_notes" rows="3" required placeholder="Note any existing scratches, stone chips, clean interior, or accessories..." style="width:100%;">Vehicle clean, no visible exterior damage, all lights and safety kit verified.</textarea>
      </div>

      <!-- Customer Acknowledgement -->
      <div class="checklist-item" style="border:none; padding:8px 0 16px;">
        <input type="checkbox" name="customer_acknowledged" id="customer_acknowledged" value="1" required>
        <label for="customer_acknowledged">
          <strong>Customer Acknowledgement &amp; Turnover Agreement</strong>
          <span>The customer has reviewed the vehicle's starting condition, fuel level, and acknowledges custody terms.</span>
        </label>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" class="btn-ops-secondary" onclick="closeOpsModal('pickup-modal');">Cancel</button>
        <button type="submit" class="btn-ops-primary" style="padding:10px 20px;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
          <span>Confirm Turnover &amp; Release Keys</span>
        </button>
      </div>
    </form>
  </div>
</div>

<!-- =========================================================================
     MODAL 2: GUIDED RETURN CHECK-IN & INSPECTION
     ========================================================================= -->
<div class="ops-modal" id="return-modal" role="dialog" aria-modal="true" aria-labelledby="return-modal-title">
  <div class="ops-modal-backdrop" onclick="closeOpsModal('return-modal');"></div>
  <div class="ops-modal-card">
    <div class="ops-modal-header">
      <div>
        <h3 id="return-modal-title">Guided Vehicle Return &amp; Check-in</h3>
        <p>Record ending mileage, fuel level, condition notes, and inspect for new damages.</p>
      </div>
      <button type="button" class="ops-modal-close" onclick="closeOpsModal('return-modal');" aria-label="Close modal">&times;</button>
    </div>

    <form method="post" id="return-form">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="rental_id" id="return-rental-id">
      <input type="hidden" name="action" value="return">

      <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:12px 16px; margin-bottom:16px;">
        <div style="font-size:0.75rem; color:#64748b;">Booking Reference:</div>
        <strong id="return-ref-display" style="font-size:1rem; color:#0f172a;"></strong>
        <div style="font-size:0.82rem; color:#334155; margin-top:2px;">
          Customer: <strong id="return-customer-display"></strong> &bull; Vehicle: <span id="return-vehicle-display"></span>
        </div>
      </div>

      <!-- Late Fee Assessment Alert -->
      <div id="return-late-alert" style="display:none; background:#fef2f2; border:1px solid #fecaca; border-radius:10px; padding:12px 16px; margin-bottom:16px; color:#b91c1c; font-size:0.84rem;">
        <strong>Late Return Violation Detected:</strong>
        <div>This vehicle is <span id="return-late-hours"></span> billable hour(s) overdue. A late fee of ₱<span id="return-late-amount"></span> will be added to the customer's ledger, and 1 penalty point will be logged.</div>
      </div>

      <!-- Odometer and Fuel -->
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
        <div>
          <label for="ending_mileage" style="font-size:0.8rem; font-weight:700; color:#334155; display:block; margin-bottom:6px;">
            Ending Odometer (km):
          </label>
          <input type="number" name="ending_mileage" id="ending_mileage" min="0" required placeholder="e.g. 15680" style="width:100%;" oninput="calculateTripDistance();">
          <small id="start-mileage-hint" style="color:#64748b; font-size:0.72rem; display:block; margin-top:4px;"></small>
        </div>
        <div>
          <label for="ending_fuel_level" style="font-size:0.8rem; font-weight:700; color:#334155; display:block; margin-bottom:6px;">
            Ending Fuel Level (%):
          </label>
          <input type="number" name="ending_fuel_level" id="ending_fuel_level" min="0" max="100" step="0.1" value="100" required placeholder="0 - 100" style="width:100%;">
        </div>
      </div>

      <!-- Return Condition Notes -->
      <div style="margin-bottom:16px;">
        <label for="return_condition_notes" style="font-size:0.8rem; font-weight:700; color:#334155; display:block; margin-bottom:6px;">
          Return Condition Notes:
        </label>
        <textarea name="condition_notes" id="return_condition_notes" rows="2" required placeholder="Describe vehicle return state, cleanliness, exterior..." style="width:100%;">Vehicle returned clean, keys retrieved, cabin clear.</textarea>
      </div>

      <!-- Damage Reporting Toggle -->
      <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:14px; margin-bottom:20px;">
        <label style="display:flex; align-items:center; gap:10px; cursor:pointer;">
          <input type="checkbox" name="damage_reported" id="damage_reported" value="1" onchange="toggleDamageFields(this.checked);" style="accent-color:#e11d48; width:18px; height:18px;">
          <strong style="font-size:0.86rem; color:#0f172a;">Report New Damage or Incidents</strong>
        </label>

        <div id="damage-fields-wrap" style="display:none; margin-top:14px; padding-top:14px; border-top:1px solid #e2e8f0;">
          <div style="margin-bottom:12px;">
            <label for="damage_description" style="font-size:0.78rem; font-weight:700; color:#334155; display:block; margin-bottom:4px;">
              Damage Description:
            </label>
            <input type="text" name="damage_description" id="damage_description" maxlength="255" placeholder="e.g. Scratched rear bumper on passenger side..." style="width:100%;">
          </div>
          <div>
            <label for="estimated_damage" style="font-size:0.78rem; font-weight:700; color:#334155; display:block; margin-bottom:4px;">
              Estimated Repair Cost (₱, Optional):
            </label>
            <input type="number" step="0.01" min="0" name="estimated_damage" id="estimated_damage" placeholder="0.00" style="width:100%;">
            <small style="color:#64748b; font-size:0.72rem; display:block; margin-top:2px;">Factual findings logged for administrator review; does not bill customer automatically.</small>
          </div>
        </div>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" class="btn-ops-secondary" onclick="closeOpsModal('return-modal');">Cancel</button>
        <button type="submit" class="btn-ops-accent" style="padding:10px 20px;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
          <span>Confirm Return &amp; Check-in</span>
        </button>
      </div>
    </form>
  </div>
</div>

<!-- =========================================================================
     MODAL 3: REJECTION REASON MODAL
     ========================================================================= -->
<div class="ops-modal" id="reject-modal" role="dialog" aria-modal="true" aria-labelledby="reject-modal-title">
  <div class="ops-modal-backdrop" onclick="closeOpsModal('reject-modal');"></div>
  <div class="ops-modal-card" style="width:min(100%, 500px);">
    <div class="ops-modal-header">
      <div>
        <h3 id="reject-modal-title">Reject Reservation Request</h3>
        <p>A reason is required and will be communicated to the customer.</p>
      </div>
      <button type="button" class="ops-modal-close" onclick="closeOpsModal('reject-modal');" aria-label="Close modal">&times;</button>
    </div>

    <form method="post" id="reject-form">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="reservation_id" id="reject-reservation-id">
      <input type="hidden" name="action" value="reject">

      <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:10px 14px; margin-bottom:16px;">
        <span style="font-size:0.75rem; color:#64748b;">Booking:</span>
        <strong id="reject-ref-display" style="font-size:0.95rem; color:#0f172a;"></strong>
      </div>

      <div style="margin-bottom:16px;">
        <label for="reject-reason" style="font-size:0.8rem; font-weight:700; color:#334155; display:block; margin-bottom:6px;">
          Rejection Reason:
        </label>
        <textarea name="reason" id="reject-reason" rows="3" required placeholder="Specify why this reservation is rejected..." style="width:100%;"></textarea>

        <!-- Quick Reason Chips -->
        <div style="display:flex; flex-wrap:wrap; gap:6px; margin-top:8px;">
          <button type="button" class="btn-ops-secondary" style="font-size:0.72rem; padding:4px 8px;" onclick="setRejectReason('Vehicle currently undergoing unscheduled mechanical service.');">Mechanical service</button>
          <button type="button" class="btn-ops-secondary" style="font-size:0.72rem; padding:4px 8px;" onclick="setRejectReason('Customer identification documents could not be verified.');">Unverified ID</button>
          <button type="button" class="btn-ops-secondary" style="font-size:0.72rem; padding:4px 8px;" onclick="setRejectReason('Fleet scheduling conflict with another confirmed booking.');">Schedule conflict</button>
        </div>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" class="btn-ops-secondary" onclick="closeOpsModal('reject-modal');">Cancel</button>
        <button type="submit" class="btn-ops-danger" style="padding:8px 18px;">
          <span>Confirm Rejection</span>
        </button>
      </div>
    </form>
  </div>
</div>

<!-- =========================================================================
     MODAL 4: MARK NO-SHOW MODAL
     ========================================================================= -->
<div class="ops-modal" id="noshow-modal" role="dialog" aria-modal="true" aria-labelledby="noshow-modal-title">
  <div class="ops-modal-backdrop" onclick="closeOpsModal('noshow-modal');"></div>
  <div class="ops-modal-card" style="width:min(100%, 500px);">
    <div class="ops-modal-header">
      <div>
        <h3 id="noshow-modal-title">Record Customer No-Show</h3>
        <p>Customer has not arrived 30 minutes past scheduled pickup time.</p>
      </div>
      <button type="button" class="ops-modal-close" onclick="closeOpsModal('noshow-modal');" aria-label="Close modal">&times;</button>
    </div>

    <form method="post" id="noshow-form">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="reservation_id" id="noshow-reservation-id">
      <input type="hidden" name="action" value="no_show">

      <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:10px; padding:12px; margin-bottom:16px; color:#b91c1c; font-size:0.84rem;">
        <strong>Notice:</strong> Marking a no-show cancels this booking, releases the vehicle back to fleet availability, and logs <strong>3 penalty points</strong> against the customer's account.
      </div>

      <div style="margin-bottom:16px;">
        <label for="noshow-reason" style="font-size:0.8rem; font-weight:700; color:#334155; display:block; margin-bottom:6px;">
          No-Show Documentation Reason:
        </label>
        <textarea name="reason" id="noshow-reason" rows="3" required placeholder="Document the missed pickup..." style="width:100%;">Customer failed to appear within 30-minute grace period without prior notice.</textarea>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" class="btn-ops-secondary" onclick="closeOpsModal('noshow-modal');">Cancel</button>
        <button type="submit" class="btn-ops-danger" style="padding:8px 18px;">
          <span>Confirm No-Show &amp; Apply Penalty</span>
        </button>
      </div>
    </form>
  </div>
</div>

<!-- =========================================================================
     MODAL 5: REPORT MISSED RETURN INCIDENT
     ========================================================================= -->
<div class="ops-modal" id="incident-modal" role="dialog" aria-modal="true" aria-labelledby="incident-modal-title">
  <div class="ops-modal-backdrop" onclick="closeOpsModal('incident-modal');"></div>
  <div class="ops-modal-card" style="width:min(100%, 500px);">
    <div class="ops-modal-header">
      <div>
        <h3 id="incident-modal-title">Report Missed Return Incident</h3>
        <p>Escalate an overdue vehicle that has not been returned.</p>
      </div>
      <button type="button" class="ops-modal-close" onclick="closeOpsModal('incident-modal');" aria-label="Close modal">&times;</button>
    </div>

    <form method="post" id="incident-form">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="rental_id" id="incident-rental-id">
      <input type="hidden" name="action" value="report_incident">

      <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:10px 14px; margin-bottom:16px;">
        <span style="font-size:0.75rem; color:#64748b;">Overdue Booking:</span>
        <strong id="incident-ref-display" style="font-size:0.95rem; color:#0f172a;"></strong>
      </div>

      <div style="margin-bottom:16px;">
        <label for="incident-description" style="font-size:0.8rem; font-weight:700; color:#334155; display:block; margin-bottom:6px;">
          Incident Description &amp; Staff Findings:
        </label>
        <textarea name="description" id="incident-description" rows="3" required placeholder="Detail missed return, customer phone contact attempts, or vehicle status..." style="width:100%;">Vehicle past scheduled return time. Customer not reachable at primary phone number.</textarea>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" class="btn-ops-secondary" onclick="closeOpsModal('incident-modal');">Cancel</button>
        <button type="submit" class="btn-ops-danger" style="padding:8px 18px;">
          <span>Submit Incident for Admin Escalation</span>
        </button>
      </div>
    </form>
  </div>
</div>

<!-- =========================================================================
     STAFF INTERACTIVE JAVASCRIPT CONTROLLERS
     ========================================================================= -->
<script>
  let currentStartMileage = 0;
  let currentOutstandingBalance = 0;

  function openOpsModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
      modal.classList.add('is-open');
      document.body.style.overflow = 'hidden';
    }
  }

  function closeOpsModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
      modal.classList.remove('is-open');
      document.body.style.overflow = '';
    }
  }

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      document.querySelectorAll('.ops-modal.is-open').forEach((m) => {
        m.classList.remove('is-open');
      });
      document.body.style.overflow = '';
    }
  });

  // Modal openers
  document.querySelectorAll('[data-modal-open]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const modalId = btn.dataset.modalOpen;

      if (modalId === 'pickup-modal') {
        document.getElementById('pickup-reservation-id').value = btn.dataset.reservationId || '';
        document.getElementById('pickup-ref-display').textContent = btn.dataset.reference || '';
        document.getElementById('pickup-customer-display').textContent = btn.dataset.customer || '';
        document.getElementById('pickup-vehicle-display').textContent = btn.dataset.vehicle || '';
        document.getElementById('starting_mileage').value = btn.dataset.startMileage || '0';
      } else if (modalId === 'return-modal') {
        document.getElementById('return-rental-id').value = btn.dataset.rentalId || '';
        document.getElementById('return-ref-display').textContent = btn.dataset.reference || '';
        document.getElementById('return-customer-display').textContent = btn.dataset.customer || '';
        document.getElementById('return-vehicle-display').textContent = btn.dataset.vehicle || '';

        currentStartMileage = parseInt(btn.dataset.startMileage, 10) || 0;
        const endMilInput = document.getElementById('ending_mileage');
        endMilInput.min = currentStartMileage;
        endMilInput.value = currentStartMileage;
        document.getElementById('start-mileage-hint').textContent = 'Starting mileage was ' + currentStartMileage + ' km.';

        const lateHours = parseInt(btn.dataset.lateHours, 10) || 0;
        const lateAmount = btn.dataset.lateAmount || '0.00';
        const lateAlert = document.getElementById('return-late-alert');
        if (lateHours > 0) {
          document.getElementById('return-late-hours').textContent = lateHours;
          document.getElementById('return-late-amount').textContent = lateAmount;
          lateAlert.style.display = 'block';
        } else {
          lateAlert.style.display = 'none';
        }
      } else if (modalId === 'reject-modal') {
        document.getElementById('reject-reservation-id').value = btn.dataset.reservationId || '';
        document.getElementById('reject-ref-display').textContent = btn.dataset.reference + ' (' + btn.dataset.customer + ')';
        document.getElementById('reject-reason').value = '';
      } else if (modalId === 'noshow-modal') {
        document.getElementById('noshow-reservation-id').value = btn.dataset.reservationId || '';
      } else if (modalId === 'incident-modal') {
        document.getElementById('incident-rental-id').value = btn.dataset.rentalId || '';
        document.getElementById('incident-ref-display').textContent = btn.dataset.reference + ' (' + btn.dataset.customer + ')';
      }

      openOpsModal(modalId);
    });
  });

  function calculateTripDistance() {
    const val = parseInt(document.getElementById('ending_mileage').value, 10) || 0;
    const diff = Math.max(0, val - currentStartMileage);
    document.getElementById('start-mileage-hint').textContent =
      'Starting mileage: ' + currentStartMileage + ' km &bull; Trip distance: ' + diff + ' km';
  }

  function toggleDamageFields(checked) {
    const wrap = document.getElementById('damage-fields-wrap');
    if (wrap) wrap.style.display = checked ? 'block' : 'none';
    const desc = document.getElementById('damage_description');
    if (desc) desc.required = checked;
  }

  function setRejectReason(reason) {
    const textarea = document.getElementById('reject-reason');
    if (textarea) textarea.value = reason;
  }

  // Payment counter controller
  function updatePaymentCounterDetails(select) {
    const opt = select.options[select.selectedIndex];
    if (!opt || !opt.value) {
      document.getElementById('counter-charged').textContent = '₱0.00';
      document.getElementById('counter-paid').textContent = '₱0.00';
      document.getElementById('counter-outstanding').textContent = '₱0.00';
      document.getElementById('payment_amount').value = '';
      document.getElementById('payment_amount').max = '';
      currentOutstandingBalance = 0;
      updateResultingBalance();
      return;
    }

    const outstanding = parseFloat(opt.dataset.outstanding) || 0;
    const charged = parseFloat(opt.dataset.charged) || 0;
    const paid = parseFloat(opt.dataset.paid) || 0;

    currentOutstandingBalance = outstanding;
    document.getElementById('counter-charged').textContent = '₱' + charged.toFixed(2);
    document.getElementById('counter-paid').textContent = '₱' + paid.toFixed(2);
    document.getElementById('counter-outstanding').textContent = '₱' + outstanding.toFixed(2);

    const amtInput = document.getElementById('payment_amount');
    amtInput.value = outstanding.toFixed(2);
    amtInput.max = outstanding.toFixed(2);
    updateResultingBalance();
  }

  function updateResultingBalance() {
    const payAmt = parseFloat(document.getElementById('payment_amount').value) || 0;
    const resulting = Math.max(0, currentOutstandingBalance - payAmt);
    document.getElementById('resulting-balance-val').textContent = '₱' + resulting.toFixed(2);
  }

  // Initialize counter if preselected
  const selectElem = document.getElementById('reservation_id_select');
  if (selectElem && selectElem.value) {
    updatePaymentCounterDetails(selectElem);
  }
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
