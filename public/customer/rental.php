<?php
$title = 'Rental Details';
require_once __DIR__ . '/../../config.php';
$user = require_customer();
$rentalId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$error = '';
$message = '';

if (!$rentalId) {
    $error = 'The rental request could not be found.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $rentalId) {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $reason = trim($_POST['reason'] ?? '');
    if ($action !== 'cancel' || $reason === '') {
        $error = 'Invalid rental action.';
    } else {
        $cancel = db()->prepare(
            "UPDATE rentals SET cancellation_reason = ?, cancellation_requested = 1, cancellation_requested_at = CURRENT_TIMESTAMP
             WHERE id = ? AND user_id = ? AND status IN ('pending', 'confirmed', 'ongoing') AND cancellation_requested = 0"
        );
        $cancel->execute([$reason, $rentalId, $user['id']]);
        if ($cancel->rowCount() === 1) {
            $message = 'Your cancellation request has been submitted for staff review.';
        } else {
            $error = 'This rental cannot receive another cancellation request.';
        }
    }
}

$stmt = db()->prepare(
    "SELECT r.id, r.start_date, r.end_date, r.actual_return, r.total_amount, r.status, r.created_at,
            r.purpose, r.rejection_reason, r.cancellation_reason, r.cancellation_requested,
            v.brand, v.model, v.category, v.transmission, v.seats, v.image,
            r.payment_method,
            COALESCE(SUM(p.amount), 0) AS paid_amount
     FROM rentals r
     INNER JOIN vehicles v ON v.id = r.vehicle_id
     LEFT JOIN payments p ON p.rental_id = r.id
     WHERE r.id = ? AND r.user_id = ?
     GROUP BY r.id, v.id"
);
$stmt->execute([$rentalId ?: 0, $user['id']]);
$rental = $stmt->fetch();
if (!$rental && !$error) {
    $error = 'The rental request could not be found.';
}
include __DIR__ . '/../../includes/header.php';
?>
<main class="dashboard-page">
  <div class="dashboard-layout">
    <?php include __DIR__ . '/../../includes/customer-sidebar.php'; ?>
    <div class="dashboard-content">
      <div class="wrap rental-detail-page">
      <?php if ($error && !$rental): ?>
        <div class="empty-state">
          <h3><?= e($error) ?></h3>
          <a class="btn btn-navy" href="/customer/dashboard.php">Back to dashboard</a>
        </div>
      <?php else: ?>
        <a class="text-link back-link" href="/customer/dashboard.php">← Back to dashboard</a>
    <div class="rental-detail-header">
      <div>
        <p class="eyebrow">Rental details</p>
        <h1><?= e($rental['brand']) ?> <?= e($rental['model']) ?></h1>
        <p>Booking #<?= (int)$rental['id'] ?> · Requested <?= e(date('M j, Y', strtotime($rental['created_at']))) ?></p>
      </div>
      <span class="status-badge <?= in_array($rental['status'], ['confirmed', 'ongoing', 'completed'], true) ? 'status-success' : (in_array($rental['status'], ['cancelled', 'rejected'], true) ? 'status-danger' : 'status-neutral') ?>"><?= e(ucfirst($rental['status'])) ?></span>
    </div>

    <?php if ($message): ?><p class="note request-success" role="status"><?= e($message) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="form-error" role="alert"><?= e($error) ?></p><?php endif; ?>

    <div class="rental-detail-grid">
      <section class="rental-detail-card">
        <div class="rental-vehicle-image" style="background-image:url('<?= e(vehicle_image($rental['image'])) ?>')" role="img" aria-label="<?= e($rental['brand'] . ' ' . $rental['model']) ?>"></div>
        <p class="eyebrow">Trip information</p>
        <dl class="detail-list">
          <div><dt>Vehicle</dt><dd><?= e($rental['brand']) ?> <?= e($rental['model']) ?></dd></div>
          <div><dt>Category</dt><dd><?= e($rental['category']) ?></dd></div>
          <div><dt>Dates</dt><dd><?= e(date('M j, Y', strtotime($rental['start_date']))) ?> – <?= e(date('M j, Y', strtotime($rental['end_date']))) ?></dd></div>
          <div><dt>Purpose</dt><dd><?= e($rental['purpose'] ?: 'Not provided') ?></dd></div>
          <?php if ($rental['rejection_reason']): ?><div><dt>Rejection reason</dt><dd><?= e($rental['rejection_reason']) ?></dd></div><?php endif; ?>
          <?php if ($rental['cancellation_reason']): ?><div><dt>Cancellation reason</dt><dd><?= e($rental['cancellation_reason']) ?></dd></div><?php endif; ?>
          <div><dt>Transmission</dt><dd><?= e($rental['transmission']) ?></dd></div>
          <div><dt>Seats</dt><dd><?= (int)$rental['seats'] ?></dd></div>
          <?php if ($rental['actual_return']): ?><div><dt>Actual return</dt><dd><?= e(date('M j, Y', strtotime($rental['actual_return']))) ?></dd></div><?php endif; ?>
        </dl>
      </section>
      <aside class="rental-detail-card">
        <p class="eyebrow">Payment summary</p>
        <?php $days = rental_days($rental['start_date'], $rental['end_date']); ?>
        <div class="payment-summary-row"><span><?= $days ?> day(s) × daily rate</span><strong>₱<?= number_format((float)$rental['total_amount'] / $days) ?> / day</strong></div>
        <div class="payment-summary-row"><span>Total amount</span><strong>₱<?= number_format((float)$rental['total_amount']) ?></strong></div>
        <div class="payment-summary-row"><span>Paid</span><strong>₱<?= number_format((float)$rental['paid_amount']) ?></strong></div>
        <div class="payment-summary-row balance"><span>Remaining balance</span><strong>₱<?= number_format(max(0, (float)$rental['total_amount'] - (float)$rental['paid_amount'])) ?></strong></div>
        <div class="payment-summary-row"><span>Selected method</span><strong><?= e($rental['payment_method'] ? ucfirst($rental['payment_method']) : 'Not selected') ?></strong></div>
        <div class="payment-process">
          <strong>Payment process</strong>
          <p>Payments are completed with VELO staff during pickup or return. Staff can record cash, GCash, or card payments here.</p>
          <div class="payment-methods">
            <span>Cash</span>
            <span>GCash</span>
            <span>Card</span>
          </div>
        </div>
        <?php if ($rental['cancellation_requested']): ?>
          <div class="payment-process"><strong>Cancellation pending</strong><p>Your cancellation request is waiting for staff review.</p></div>
        <?php elseif (in_array($rental['status'], ['pending', 'confirmed', 'ongoing'], true)): ?>
          <form method="post" class="cancel-form" onsubmit="return confirm('Cancel this rental request?');">
            <input type="hidden" name="action" value="cancel">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <label>Reason for cancellation<input name="reason" maxlength="255" required placeholder="Why do you need to cancel?"></label>
            <button class="btn btn-navy" type="submit">Cancel request</button>
          </form>
        <?php endif; ?>
      </aside>
    </div>
      <?php endif; ?>
      </div>
    </div>
  </div>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
