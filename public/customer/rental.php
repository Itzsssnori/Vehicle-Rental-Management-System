<?php
$title = 'Reservation Details';
require_once __DIR__ . '/../../config.php';
$user = require_customer();
$reservationId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$error = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $reservationId) {
    verify_csrf();
    $reason = trim($_POST['reason'] ?? '');
    if ($reason === '') {
        $error = 'Enter a reason for the cancellation request.';
    } else {
        try {
            $pdo = db();
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                "SELECT status FROM reservations WHERE id = ? AND customer_id = ? FOR UPDATE"
            );
            $stmt->execute([$reservationId, $user['id']]);
            $reservationStatus = $stmt->fetchColumn();
            if (!$reservationStatus || !in_array($reservationStatus, ['pending', 'confirmed'], true)) {
                throw new RuntimeException('This reservation cannot be cancelled.');
            }
            $existing = $pdo->prepare("SELECT id FROM reservation_cancellation_requests WHERE reservation_id = ? AND status = 'requested' LIMIT 1");
            $existing->execute([$reservationId]);
            if ($existing->fetch()) {
                throw new RuntimeException('A cancellation request is already pending.');
            }
            $insert = $pdo->prepare('INSERT INTO reservation_cancellation_requests (reservation_id, requested_by, reason) VALUES (?, ?, ?)');
            $insert->execute([$reservationId, $user['id'], $reason]);
            write_audit($pdo, (int)$user['id'], 'cancellation_requested', 'reservations', $reservationId, $reason);
            $pdo->commit();
            $message = 'Your cancellation request has been submitted for review.';
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Customer cancellation request failed: ' . $exception->getMessage());
            $error = $exception->getMessage();
        }
    }
}

$stmt = db()->prepare(
    "SELECT r.*, v.brand, v.model, v.image, c.name AS category,
            pl.name AS pickup_location, rl.name AS return_location
     FROM reservations r
     INNER JOIN vehicles v ON v.id = r.vehicle_id
     INNER JOIN vehicle_categories c ON c.id = v.category_id
     INNER JOIN locations pl ON pl.id = r.pickup_location_id
     INNER JOIN locations rl ON rl.id = r.return_location_id
     WHERE r.id = ? AND r.customer_id = ?"
);
$stmt->execute([$reservationId ?: 0, $user['id']]);
$reservation = $stmt->fetch();
if (!$reservation) {
    $error = 'The reservation could not be found.';
}
include __DIR__ . '/../../includes/header.php';
?>
<main class="dashboard-page"><div class="dashboard-layout"><?php include __DIR__ . '/../../includes/customer-sidebar.php'; ?><div class="dashboard-content"><div class="wrap rental-detail-page">
<?php if (!$reservation): ?><div class="empty-state"><h3><?= e($error) ?></h3><a class="btn btn-navy" href="/customer/dashboard.php">Back to dashboard</a></div>
<?php else: ?>
<a class="text-link back-link" href="/customer/dashboard.php">← Back to dashboard</a>
<div class="rental-detail-header"><div><p class="eyebrow">Reservation details</p><h1><?= e($reservation['brand'] . ' ' . $reservation['model']) ?></h1><p><?= e($reservation['reference_number']) ?></p></div><span class="status-badge"><?= e(ucfirst($reservation['status'])) ?></span></div>
<?php if ($message): ?><p class="note request-success" role="status"><?= e($message) ?></p><?php endif; ?><?php if ($error): ?><p class="form-error" role="alert"><?= e($error) ?></p><?php endif; ?>
<div class="rental-detail-grid"><section class="rental-detail-card"><div class="rental-vehicle-image" style="background-image:url('<?= e(vehicle_image($reservation['image'])) ?>')"></div><p class="eyebrow">Trip information</p><dl class="detail-list"><div><dt>Schedule</dt><dd><?= e(date('M j, Y g:i A', strtotime($reservation['requested_pickup_at']))) ?> – <?= e(date('M j, Y g:i A', strtotime($reservation['requested_return_at']))) ?></dd></div><div><dt>Pickup location</dt><dd><?= e($reservation['pickup_location']) ?></dd></div><div><dt>Return location</dt><dd><?= e($reservation['return_location']) ?></dd></div><div><dt>Purpose</dt><dd><?= e($reservation['purpose'] ?: 'Not provided') ?></dd></div><?php if ($reservation['rejection_reason']): ?><div><dt>Rejection reason</dt><dd><?= e($reservation['rejection_reason']) ?></dd></div><?php endif; ?></dl></section><aside class="rental-detail-card"><p class="eyebrow">Reservation total</p><div class="payment-summary-row"><span>Total amount</span><strong>₱<?= number_format((float)$reservation['total_amount']) ?></strong></div><?php if (in_array($reservation['status'], ['pending', 'confirmed'], true)): ?><form method="post" class="cancel-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label>Reason for cancellation<input name="reason" maxlength="255" required></label><button class="btn btn-navy" type="submit">Request cancellation</button></form><?php endif; ?></aside></div>
<?php endif; ?></div></div></div></main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
