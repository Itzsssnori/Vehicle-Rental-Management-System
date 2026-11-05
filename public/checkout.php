<?php
$title = 'Secure Checkout - VELO';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/payment.php';

$user = require_customer();
$reservationId = filter_input(INPUT_POST, 'reservation_id', FILTER_VALIDATE_INT);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$reservationId) {
    http_response_code(400);
    exit('Invalid checkout request.');
}
verify_csrf();

$stmt = db()->prepare(
    "SELECT r.*, v.brand, v.model
     FROM reservations r INNER JOIN vehicles v ON v.id = r.vehicle_id
     WHERE r.id = ? AND r.customer_id = ? LIMIT 1"
);
$stmt->execute([$reservationId, $user['id']]);
$reservation = $stmt->fetch();
if (!$reservation || $reservation['status'] !== 'confirmed' || !in_array($reservation['payment_method'], ['online', 'paymongo', 'paypal'], true)) {
    http_response_code(422);
    exit('This reservation is not ready for online checkout.');
}

$balance = rental_balance(db(), $reservationId);
if ($balance['outstanding'] <= 0.0001) {
    header('Location: /customer/rental.php?id=' . $reservationId);
    exit;
}

$existing = db()->prepare("SELECT checkout_url FROM payment_attempts WHERE reservation_id = ? AND status IN ('created', 'pending') ORDER BY id DESC LIMIT 1");
$existing->execute([$reservationId]);
$existingUrl = $existing->fetchColumn();
if ($existingUrl) {
    header('Location: ' . $existingUrl);
    exit;
}

$provider = $reservation['payment_method'];
$pdo = db();
$insert = $pdo->prepare("INSERT INTO payment_attempts (reservation_id, provider, status, amount, currency) VALUES (?, ?, 'created', ?, 'PHP')");
$insert->execute([$reservationId, $provider, $balance['outstanding']]);
$attemptId = (int)$pdo->lastInsertId();

try {
    $checkout = match ($provider) {
        'online' => create_online_checkout($reservation, $attemptId),
        'paymongo' => create_paymongo_checkout($reservation),
        'paypal' => create_paypal_checkout($reservation),
    };
    $update = $pdo->prepare("UPDATE payment_attempts SET status = 'pending', provider_session_id = ?, checkout_url = ? WHERE id = ?");
    $update->execute([$checkout['session_id'], $checkout['checkout_url'], $attemptId]);
    header('Location: ' . $checkout['checkout_url']);
    exit;
} catch (Throwable $exception) {
    $pdo->prepare("UPDATE payment_attempts SET status = 'failed', failure_reason = ? WHERE id = ?")->execute([substr($exception->getMessage(), 0, 255), $attemptId]);
    error_log('Online checkout creation failed: ' . $exception->getMessage());
    http_response_code(502);
    exit('The online checkout could not be started. Please try again or choose manual payment.');
}
