<?php
$title = 'Vehicle Details';
require_once __DIR__ . '/../config.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$start = $_GET['start'] ?? date('Y-m-d');
$end = $_GET['end'] ?? date('Y-m-d', strtotime('+3 days'));
$error = '';
$success = '';

$vehicle = null;
if ($id > 0) {
  $stmt = db()->prepare('SELECT * FROM vehicles WHERE id = ?');
  $stmt->execute([$id]);
  $vehicle = $stmt->fetch();
}

if (!$vehicle) {
  include __DIR__ . '/../includes/header.php';
  echo '<main class="wrap"><div class="empty-state"><h3>Vehicle not found</h3><p>The selected vehicle could not be found or is no longer available.</p><a class="btn btn-navy" href="/vehicles.php">Back to catalog</a></div></main>';
  include __DIR__ . '/../includes/footer.php';
  exit;
}

$today = date('Y-m-d');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf();
  $start = trim($_POST['start'] ?? '');
  $end = trim($_POST['end'] ?? '');
  $paymentMethod = $_POST['payment_method'] ?? '';
  $purpose = trim($_POST['purpose'] ?? '');
  $validFormat = preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end);

  if (!current_user()) {
    header('Location: /login.php?redirect=' . rawurlencode('/vehicle.php?id=' . $id));
    exit;
  }
  if ($purpose === '') {
    $error = 'Enter the purpose of this rental.';
  } elseif (!in_array($paymentMethod, ['cash', 'gcash', 'card'], true)) {
    $error = 'Choose a payment method for this rental request.';
  } elseif (($vehicle['status'] ?? '') !== 'operational') {
    $error = 'This vehicle is not currently available for rental.';
  } elseif (!$validFormat || $start < $today || $end < $start) {
    $error = 'Choose valid rental dates. The return date must be on or after the pickup date.';
  } else {
    $conflict = db()->prepare(
      "SELECT COUNT(*) FROM rentals
       WHERE vehicle_id = ?
         AND status IN ('pending', 'confirmed', 'ongoing')
         AND start_date <= ?
         AND end_date >= ?"
    );
    $conflict->execute([$id, $end, $start]);

    $maintenance = db()->prepare(
      "SELECT COUNT(*) FROM maintenance
       WHERE vehicle_id = ?
         AND start_date <= ?
         AND (end_date IS NULL OR end_date >= ?)"
    );
    $maintenance->execute([$id, $end, $start]);

    if ((int)$conflict->fetchColumn() > 0) {
      $error = 'This vehicle is already requested or booked for those dates.';
    } elseif ((int)$maintenance->fetchColumn() > 0) {
      $error = 'This vehicle is scheduled for maintenance during those dates.';
    } else {
      $days = rental_days($start, $end);
      $total = (float)$vehicle['daily_rate'] * $days;
      $insert = db()->prepare(
        "INSERT INTO rentals (user_id, vehicle_id, start_date, end_date, total_amount, payment_method, purpose, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')"
      );
      $insert->execute([current_user()['id'], $id, $start, $end, $total, $paymentMethod, $purpose]);
      $rentalId = (int)db()->lastInsertId();
      header('Location: /customer/dashboard.php?requested=' . $rentalId);
      exit;
    }
  }
}

$validLookupDates = preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)
  && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)
  && $start >= $today
  && $end >= $start;
$availableForDates = ($vehicle['status'] ?? '') === 'operational';
if ($availableForDates && $validLookupDates) {
  $availability = db()->prepare(
    "SELECT
       (SELECT COUNT(*) FROM rentals
        WHERE vehicle_id = ?
          AND status IN ('pending', 'confirmed', 'ongoing')
          AND start_date <= ?
          AND end_date >= ?) AS rental_conflicts,
       (SELECT COUNT(*) FROM maintenance
        WHERE vehicle_id = ?
          AND start_date <= ?
          AND (end_date IS NULL OR end_date >= ?)) AS maintenance_conflicts"
  );
  $availability->execute([$id, $end, $start, $id, $end, $start]);
  $availability = $availability->fetch();
  $availableForDates = (int)$availability['rental_conflicts'] === 0
    && (int)$availability['maintenance_conflicts'] === 0;
}

$image = vehicle_image($vehicle['image']);
$gallery = [
  $image,
  'https://images.unsplash.com/photo-1492144534655-ae79c964c9d7?auto=format&fit=crop&w=900&q=80',
  'https://images.unsplash.com/photo-1511919884226-fd3cad34687c?auto=format&fit=crop&w=900&q=80',
];
$days = rental_days($start, $end);
$estimate = (float)$vehicle['daily_rate'] * $days;
include __DIR__ . '/../includes/header.php';
?>
<main class="wrap">
  <div class="vehicle-page">
    <div class="gallery-panel">
      <div class="gallery-main" style="background-image:url('<?= e($gallery[0]) ?>')"></div>
      <div class="gallery-thumb-row">
        <div class="gallery-thumb" style="background-image:url('<?= e($gallery[1]) ?>')"></div>
        <div class="gallery-thumb" style="background-image:url('<?= e($gallery[2]) ?>')"></div>
      </div>
    </div>

    <div class="detail-copy">
      <span class="status-badge <?= $availableForDates ? 'status-success' : 'status-danger' ?>">
        <?= $availableForDates ? 'Available' : 'Unavailable for selected dates' ?>
      </span>
      <h1><?= e($vehicle['brand']) ?> <?= e($vehicle['model']) ?></h1>
      <div class="price-row">
        <strong>₱<?= number_format((float)$vehicle['daily_rate']) ?></strong>
        <span>/ day</span>
      </div>
      <p class="summary">This <?= strtolower(e($vehicle['category'])) ?> is ideal for business travel, family outings, and multi-day excursions with flexible daily pricing.</p>

      <ul class="detail-specs">
        <li><strong>Category</strong><span><?= e($vehicle['category']) ?></span></li>
        <li><strong>Transmission</strong><span><?= e($vehicle['transmission']) ?></span></li>
        <li><strong>Seats</strong><span><?= (int)$vehicle['seats'] ?> passengers</span></li>
        <li><strong>Status</strong><span><?= e($vehicle['status']) ?></span></li>
      </ul>
    </div>

    <aside class="booking-panel">
      <h3>Request this vehicle</h3>
      <p class="booking-help">Choose your dates to submit a rental request for staff or administrator approval.</p>
      <?php if ($error): ?>
        <p class="form-error" role="alert"><?= e($error) ?></p>
      <?php endif; ?>
      <form class="detail-form" method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= (int)$vehicle['id'] ?>">
        <label>
          Pickup date
          <input type="date" name="start" value="<?= e($start) ?>" min="<?= e($today) ?>" required>
        </label>
        <label>
          Return date
          <input type="date" name="end" value="<?= e($end) ?>" min="<?= e($start >= $today ? $start : $today) ?>" required>
        </label>
        <div class="price-box">
          Estimated total: ₱<?= number_format($estimate) ?> for <?= $days ?> day(s)
        </div>
        <label>
          Purpose
          <input type="text" name="purpose" value="<?= e($_POST['purpose'] ?? '') ?>" maxlength="255" placeholder="e.g. Business trip or family event" required>
        </label>
        <label>
          Preferred payment method
          <select name="payment_method" required>
            <option value="">Choose a method</option>
            <option value="cash" <?= ($_POST['payment_method'] ?? '') === 'cash' ? 'selected' : '' ?>>Cash</option>
            <option value="gcash" <?= ($_POST['payment_method'] ?? '') === 'gcash' ? 'selected' : '' ?>>GCash</option>
            <option value="card" <?= ($_POST['payment_method'] ?? '') === 'card' ? 'selected' : '' ?>>Card</option>
          </select>
        </label>
        <?php if (current_user() && current_user()['role'] === 'customer' && $availableForDates): ?>
          <button class="btn btn-coral" type="submit">Submit rental request</button>
        <?php elseif (current_user() && current_user()['role'] === 'customer'): ?>
          <p class="form-error">This vehicle is not available for the selected dates.</p>
        <?php else: ?>
          <a class="btn btn-coral" href="/login.php?redirect=<?= rawurlencode('/vehicle.php?id=' . (int)$vehicle['id']) ?>">Log in to request</a>
        <?php endif; ?>
      </form>
      <a class="btn btn-navy" style="width:100%;margin-top:14px;" href="/vehicles.php">Back to vehicles</a>
    </aside>
  </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>