<?php

require_once __DIR__ . '/../config.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Tests are available from the command line only.');
}

$pdo = db();
$suffix = (string)time();

try {
    $pdo->beginTransaction();
    $createUser = $pdo->prepare(
        "INSERT INTO users (name, email, password_hash, email_verified_at, account_status)
         VALUES (?, ?, ?, NOW(), 'approved')"
    );
    $createUser->execute(['Integration Staff', "staff.{$suffix}@test.invalid", password_hash('TestPass123!', PASSWORD_DEFAULT)]);
    $staffId = (int)$pdo->lastInsertId();
    $createUser->execute(['Integration Customer', "customer.{$suffix}@test.invalid", password_hash('TestPass123!', PASSWORD_DEFAULT)]);
    $customerId = (int)$pdo->lastInsertId();

    $assignRole = $pdo->prepare(
        'INSERT INTO user_roles (user_id, role_id)
         SELECT ?, id FROM roles WHERE name = ?'
    );
    $assignRole->execute([$staffId, 'staff']);
    $assignRole->execute([$customerId, 'customer']);

    $vehicleId = (int)$pdo->query('SELECT id FROM vehicles ORDER BY id LIMIT 1')->fetchColumn();
    $locationId = (int)$pdo->query("SELECT id FROM locations WHERE status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
    assert_true($vehicleId > 0 && $locationId > 0, 'Seed fleet and location are required.');

    $reservation = $pdo->prepare(
        "INSERT INTO reservations
         (reference_number, customer_id, vehicle_id, pickup_location_id, return_location_id,
          requested_pickup_at, requested_return_at, base_amount, total_amount, payment_method, purpose, status)
         VALUES (?, ?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY),
                 1800, 1800, 'cash', 'Automated integration test', 'confirmed')"
    );
    $reservation->execute(["TEST-{$suffix}", $customerId, $vehicleId, $locationId, $locationId]);
    $reservationId = (int)$pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO rental_charges (reservation_id, charge_type, description, quantity, unit_price, amount)
         VALUES (?, 'rental', 'Base rental', 1, 1800, 1800)"
    )->execute([$reservationId]);

    $payment = $pdo->prepare(
        "INSERT INTO payments (reservation_id, amount, method, recorded_by)
         VALUES (?, 1800, 'cash', ?)"
    );
    $payment->execute([$reservationId, $staffId]);
    $paymentId = (int)$pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO payment_receipts (payment_id, receipt_number, issued_by) VALUES (?, ?, ?)'
    )->execute([$paymentId, "TEST-RECEIPT-{$suffix}", $staffId]);
    assert_true(rental_balance($pdo, $reservationId)['outstanding'] < 0.01, 'Base payment must settle the reservation.');

    $rental = $pdo->prepare(
        'INSERT INTO rentals (reservation_id, customer_id, vehicle_id, expected_return_at, created_by)
         SELECT id, customer_id, vehicle_id, requested_return_at, ? FROM reservations WHERE id = ?'
    );
    $rental->execute([$staffId, $reservationId]);
    $rentalId = (int)$pdo->lastInsertId();
    record_rental_status($pdo, $rentalId, $staffId, 'ongoing');
    $pdo->prepare(
        "INSERT INTO penalty_events
         (user_id, rental_id, event_type, points, reason, status, expires_at, recorded_by)
         VALUES (?, ?, 'late_return', 1, 'Automated test event', 'approved',
                 DATE_ADD(CURRENT_DATE, INTERVAL 12 MONTH), ?)"
    )->execute([$customerId, $rentalId, $staffId]);
    assert_true(customer_standing($pdo, $customerId)['standing'] === 'good', 'One point should remain good standing.');

    $incident = $pdo->prepare(
        "INSERT INTO rental_incidents (rental_id, incident_type, description, reported_by)
         VALUES (?, 'missed_return', 'Automated test incident', ?)"
    );
    $incident->execute([$rentalId, $staffId]);
    assert_true($incident->rowCount() === 1, 'Incident creation failed.');

    echo "Integration tests passed.\n";
    $pdo->rollBack();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Integration tests failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
