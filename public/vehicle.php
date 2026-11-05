<?php
$title = 'Vehicle Details - Car Rental Portal';
require_once __DIR__ . '/../config.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$start = $_GET['start'] ?? date('Y-m-d');
$end = $_GET['end'] ?? date('Y-m-d', strtotime('+3 days'));
$error = '';
$success = '';

$vehicle = null;
if ($id > 0) {
  $stmt = db()->prepare('SELECT v.*, c.name AS category FROM vehicles v INNER JOIN vehicle_categories c ON c.id = v.category_id WHERE v.id = ?');
  $stmt->execute([$id]);
  $vehicle = $stmt->fetch();
}

if (!$vehicle) {
  include __DIR__ . '/../includes/header.php';
  echo '<main class="wrap"><div class="empty-state"><h3>Vehicle not found</h3><p>The selected vehicle could not be found or is no longer available.</p><a class="btn btn-navy" href="/vehicles.php">Back to catalog</a></div></main>';
  include __DIR__ . '/../includes/footer.php';
  exit;
}

$title = e($vehicle['brand'] . ' ' . $vehicle['model']) . ' - Car Rental Portal';
$today = date('Y-m-d');

// Retrieve available locations for pickup/return
$locationsStmt = db()->query("SELECT id, name, address, instructions FROM locations WHERE status = 'active' ORDER BY id ASC");
$locations = $locationsStmt->fetchAll();

// Check user status & standing
$user = current_user();
$standing = null;
$canReserve = false;
if ($user && $user['role'] === 'customer') {
  $standing = customer_standing(db(), (int)$user['id']);
  $canReserve = customer_can_reserve(db(), (int)$user['id']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf();
  $start = trim($_POST['start'] ?? '');
  $end = trim($_POST['end'] ?? '');
  $paymentMethod = $_POST['payment_method'] ?? '';
  $purpose = trim($_POST['purpose'] ?? '');
  $chosenLocationId = (int)($_POST['pickup_location_id'] ?? 0);
  $validFormat = preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end);

  if (!current_user()) {
    header('Location: /login.php?redirect=' . rawurlencode('/vehicle.php?id=' . $id));
    exit;
  }
  if (!customer_can_reserve(db(), (int)current_user()['id'])) {
    $error = 'Your account standing is currently not eligible to create new reservations.';
  } elseif ($purpose === '') {
    $error = 'Enter the purpose of this rental.';
  } elseif (!in_array($paymentMethod, ['cash', 'bank_transfer', 'gcash', 'card'], true)) {
    $error = 'Choose a valid payment method for this rental request.';
  } elseif (($vehicle['status'] ?? '') !== 'operational') {
    $error = 'This vehicle is not currently available for rental.';
  } elseif (!$validFormat || $start < $today || $end < $start) {
    $error = 'Choose valid rental dates. The return date must be on or after the pickup date.';
  } else {
    $pickupAt = $start . ' 09:00:00';
    $returnAt = $end . ' 17:00:00';
    $conflict = db()->prepare(
      "SELECT COUNT(*) FROM reservations
       WHERE vehicle_id = ?
         AND status IN ('pending', 'confirmed')
         AND requested_pickup_at < ?
         AND requested_return_at > ?"
    );
    $conflict->execute([$id, $returnAt, $pickupAt]);

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
      $pdo = db();
      $pdo->beginTransaction();
      try {
        $locationId = $chosenLocationId;
        if ($locationId <= 0) {
          $locationId = (int)$pdo->query("SELECT id FROM locations WHERE status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
        }
        if (!$locationId) {
          throw new RuntimeException('No active pickup location is configured.');
        }
        $reference = 'RES-' . strtoupper(bin2hex(random_bytes(5)));
        $insert = $pdo->prepare(
          "INSERT INTO reservations
           (reference_number, customer_id, vehicle_id, pickup_location_id, return_location_id,
            requested_pickup_at, requested_return_at, base_amount, total_amount, payment_method, purpose)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $insert->execute([$reference, current_user()['id'], $id, $locationId, $locationId, $pickupAt, $returnAt, $total, $total, $paymentMethod, $purpose]);
        $reservationId = (int)$pdo->lastInsertId();
        record_reservation_status($pdo, $reservationId, null, 'pending');
        $charge = $pdo->prepare(
          "INSERT INTO rental_charges (reservation_id, charge_type, description, quantity, unit_price, amount)
           VALUES (?, 'rental', 'Base rental charge', ?, ?, ?)"
        );
        $charge->execute([$reservationId, $days, $vehicle['daily_rate'], $total]);
        write_audit($pdo, (int)current_user()['id'], 'reservation_created', 'reservations', $reservationId, 'Customer submitted a reservation request.');
        $pdo->commit();
      } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
          $pdo->rollBack();
        }
        throw $exception;
      }
      header('Location: /customer/dashboard.php?requested=' . $reservationId);
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
       (SELECT COUNT(*) FROM reservations
        WHERE vehicle_id = ?
          AND status IN ('pending', 'confirmed')
          AND requested_pickup_at < ?
          AND requested_return_at > ?) AS reservation_conflicts,
       (SELECT COUNT(*) FROM maintenance
        WHERE vehicle_id = ?
          AND start_date <= ?
          AND (end_date IS NULL OR end_date >= ?)) AS maintenance_conflicts"
  );
  $availability->execute([$id, $end . ' 17:00:00', $start . ' 09:00:00', $id, $end, $start]);
  $availability = $availability->fetch();
  $availableForDates = (int)$availability['reservation_conflicts'] === 0
    && (int)$availability['maintenance_conflicts'] === 0;
}

$fuel = $vehicle['fuel_type'] ?: 'Petrol';
$year = $vehicle['year'] ?: 2022;
$seats = (int)($vehicle['seats'] ?: 5);
$image = vehicle_image($vehicle['image']);
$gallery = [
  $image,
  'https://images.unsplash.com/photo-1492144534655-ae79c964c9d7?auto=format&fit=crop&w=900&q=80',
  'https://images.unsplash.com/photo-1511919884226-fd3cad34687c?auto=format&fit=crop&w=900&q=80',
];

$days = rental_days($start, $end);
$estimate = (float)$vehicle['daily_rate'] * max(1, $days);

include __DIR__ . '/../includes/header.php';
?>

<main class="portal-detail-page">
  <!-- Breadcrumb and Top Navigation -->
  <div class="wrap detail-breadcrumb-row">
    <nav class="portal-breadcrumb" aria-label="Breadcrumb">
      <a href="/index.php">Home</a>
      <span class="crumb-separator">&rsaquo;</span>
      <a href="/vehicles.php">Car Listing</a>
      <span class="crumb-separator">&rsaquo;</span>
      <span class="crumb-current"><?= e($vehicle['brand']) ?> <?= e($vehicle['model']) ?></span>
    </nav>
    <a href="/vehicles.php" class="detail-back-link">Back to Car Listing</a>
  </div>

  <div class="wrap detail-main-layout">
    <!-- Left Column: Vehicle Visuals & Detailed Info -->
    <div class="detail-left-col">
      <!-- Gallery Card -->
      <div class="vehicle-gallery-card">
        <div class="gallery-primary-frame">
          <div id="gallery-active-img" class="gallery-active-img" style="background-image: url('<?= e($gallery[0]) ?>');" role="img" aria-label="<?= e($vehicle['brand'] . ' ' . $vehicle['model']) ?>">
            <span class="gallery-status-pill <?= $availableForDates ? 'status-pill-avail' : 'status-pill-unavail' ?>">
              <?= $availableForDates ? '✓ Available for Selected Dates' : '✕ Unavailable for Selected Dates' ?>
            </span>
          </div>
        </div>

        <!-- Dark Specification Ribbon Directly Underneath Hero Photo -->
        <div class="car-spec-ribbon detail-spec-ribbon">
          <div class="spec-col">
            <span class="spec-glyph" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 20V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v15M4 20h14M6 8h10M18 7l2 2v8a2 2 0 0 1-4 0v-3h2"/></svg></span>
            <span class="spec-lbl"><?= e($fuel) ?></span>
          </div>
          <div class="spec-col">
            <span class="spec-glyph" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg></span>
            <span class="spec-lbl"><?= (int)$year ?> Model</span>
          </div>
          <div class="spec-col">
            <span class="spec-glyph" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="8" r="3"/><path d="M3 20v-1a6 6 0 0 1 12 0v1M16 11a3 3 0 1 0 0-6M17 14a5 5 0 0 1 4 5v1"/></svg></span>
            <span class="spec-lbl"><?= $seats ?> Seats</span>
          </div>
          <div class="spec-col">
            <span class="spec-glyph" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-1.8 1.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-2.5V20a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1-1.8-1.8.1-.1A1.7 1.7 0 0 0 8.1 15a1.7 1.7 0 0 0-1.6-1H6v-2.5h.2a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1 1.8-1.8.1.1a1.7 1.7 0 0 0 1.9.3 1.7 1.7 0 0 0 1-1.6v-.2h2.5v.2a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1 1.8 1.8-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2V14h-.2a1.7 1.7 0 0 0-1.6 1Z"/></svg></span>
            <span class="spec-lbl"><?= e($vehicle['transmission']) ?></span>
          </div>
        </div>

        <!-- Thumbnails Strip -->
        <div class="gallery-thumb-strip">
          <?php foreach ($gallery as $idx => $thumbUrl): ?>
            <button type="button" class="thumb-btn <?= $idx === 0 ? 'active' : '' ?>" onclick="switchDetailPhoto(this, '<?= e($thumbUrl) ?>')" aria-label="View photo <?= $idx + 1 ?>">
              <span class="thumb-preview" style="background-image: url('<?= e($thumbUrl) ?>');"></span>
            </button>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Vehicle Title & Headline Block -->
      <div class="vehicle-header-block">
        <div class="title-meta-line">
          <span class="detail-cat-tag"><?= e($vehicle['category']) ?></span>
          <span class="detail-fleet-id">Plate: <?= e($vehicle['plate_number'] ?: 'VRM-' . $vehicle['id']) ?></span>
        </div>
        <h1 class="detail-car-title"><?= e($vehicle['brand']) ?> <?= e($vehicle['model']) ?></h1>
        <div class="detail-price-banner">
          <span class="price-val">₱<?= number_format((float)$vehicle['daily_rate']) ?></span>
          <span class="price-unit">/ Day</span>
          <span class="price-subtext">&bull; Standard Daily Rental Rate</span>
        </div>
      </div>

      <!-- Overview & Description -->
      <div class="detail-section-card">
        <h3 class="section-card-title">Vehicle Overview</h3>
        <p class="detail-desc-text">
          <?= e($vehicle['description'] ?: 'This premium ' . strtolower($vehicle['category']) . ' offers outstanding comfort, responsive handling, and high fuel economy for both city commutes and long-distance provincial travel.') ?>
        </p>
      </div>

      <!-- Technical Specifications Matrix -->
      <div class="detail-section-card">
        <h3 class="section-card-title">Technical Specifications</h3>
        <div class="specs-matrix-grid">
          <div class="matrix-item">
            <span class="matrix-lbl">Make & Model</span>
            <strong class="matrix-val"><?= e($vehicle['brand']) ?> <?= e($vehicle['model']) ?></strong>
          </div>
          <div class="matrix-item">
            <span class="matrix-lbl">Vehicle Category</span>
            <strong class="matrix-val"><?= e($vehicle['category']) ?></strong>
          </div>
          <div class="matrix-item">
            <span class="matrix-lbl">Transmission</span>
            <strong class="matrix-val"><?= e($vehicle['transmission']) ?></strong>
          </div>
          <div class="matrix-item">
            <span class="matrix-lbl">Fuel Type</span>
            <strong class="matrix-val"><?= e($fuel) ?></strong>
          </div>
          <div class="matrix-item">
            <span class="matrix-lbl">Seating Capacity</span>
            <strong class="matrix-val"><?= $seats ?> Passengers</strong>
          </div>
          <div class="matrix-item">
            <span class="matrix-lbl">Model Year</span>
            <strong class="matrix-val"><?= (int)$year ?></strong>
          </div>
          <div class="matrix-item">
            <span class="matrix-lbl">Color</span>
            <strong class="matrix-val"><?= e($vehicle['color'] ?: 'Factory Silver') ?></strong>
          </div>
          <div class="matrix-item">
            <span class="matrix-lbl">Fleet Status</span>
            <strong class="matrix-val text-status-good">✓ Operational & Inspected</strong>
          </div>
        </div>
      </div>

      <!-- Features & Amenities Checklist -->
      <div class="detail-section-card">
        <h3 class="section-card-title">Features & Included Amenities</h3>
        <div class="features-checklist-grid">
          <div class="check-item"><span class="check-glyph">✓</span> Air Conditioning & Climate Control</div>
          <div class="check-item"><span class="check-glyph">✓</span> Bluetooth / USB Audio Connectivity</div>
          <div class="check-item"><span class="check-glyph">✓</span> Anti-Lock Braking System (ABS) & Dual Airbags</div>
          <div class="check-item"><span class="check-glyph">✓</span> Power Steering & Power Windows</div>
          <div class="check-item"><span class="check-glyph">✓</span> Multi-Point Safety Inspection Prior to Handover</div>
          <div class="check-item"><span class="check-glyph">✓</span> 24/7 Helpline & Roadside Support</div>
        </div>
      </div>

      <!-- Rental Policies -->
      <div class="detail-section-card policies-card">
        <h3 class="section-card-title">Reservation & Rental Policies</h3>
        <ul class="policies-list">
          <li><strong>Driver Requirements:</strong> Valid government-issued Driver's License and secondary ID required at vehicle pickup.</li>
          <li><strong>Fuel Policy:</strong> Same-to-same. Return the vehicle with the equivalent fuel level as recorded at handover.</li>
          <li><strong>Grace Period:</strong> 30-minute grace period on returns before hourly late charges apply.</li>
          <li><strong>Cancellation Policy:</strong> Free cancellation requests can be submitted prior to vehicle pickup dispatch.</li>
        </ul>
      </div>
    </div>

    <!-- Right Column: Sticky Booking & Reservation Sidebar -->
    <aside class="detail-right-col">
      <div class="portal-booking-card">
        <div class="booking-card-header">
          <h2>Request This Vehicle</h2>
          <p>Submit your dates and details for quick staff confirmation.</p>
        </div>

        <?php if ($error): ?>
          <div class="portal-alert-danger" role="alert">
            <span class="alert-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m12 3 9 17H3L12 3Z"/><path d="M12 9v5M12 17v.1"/></svg></span>
            <span><?= e($error) ?></span>
          </div>
        <?php endif; ?>

        <?php if ($user && $user['role'] === 'customer'): ?>
          <div class="customer-standing-pill standing-<?= e($standing['standing'] ?? 'good') ?>">
            <span class="standing-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3 20 6v5c0 5-3.4 8.5-8 10-4.6-1.5-8-5-8-10V6l8-3Z"/><path d="m8 12 2.5 2.5L16 9"/></svg></span>
            <div class="standing-copy">
              <span class="standing-lbl">Account Standing</span>
              <strong><?= e(ucfirst($standing['standing'] ?? 'Good')) ?> &bull; Eligible to Book</strong>
            </div>
          </div>
        <?php elseif (!current_user()): ?>
          <div class="guest-booking-tip">
            <span><span class="tip-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 18h6M10 21h4M8 14a6 6 0 1 1 8 0c-.9.7-1 1.5-1 2H9c0-.5-.1-1.3-1-2Z"/></svg></span> <strong>Quick Note:</strong> You can submit this reservation by logging in below or creating a customer account.</span>
          </div>
        <?php endif; ?>

        <form class="portal-booking-form" method="post" id="reservation-form">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="id" value="<?= (int)$vehicle['id'] ?>">
          <input type="hidden" id="booking-daily-rate" value="<?= (float)$vehicle['daily_rate'] ?>">

          <!-- Pickup / Return Location -->
          <div class="booking-form-group">
            <label for="pickup_location_id">
              <span class="field-icon">📍</span> Pickup & Return Hub
            </label>
            <select id="pickup_location_id" name="pickup_location_id" required>
              <?php foreach ($locations as $loc): ?>
                <option value="<?= (int)$loc['id'] ?>"><?= e($loc['name']) ?> (<?= e($loc['address']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Pickup Date -->
          <div class="booking-form-group">
            <label for="booking-start">
              <span class="field-icon">📅</span> Pickup Date
            </label>
            <input id="booking-start" type="date" name="start" value="<?= e($start) ?>" min="<?= e($today) ?>" required onchange="updatePriceEstimate()">
          </div>

          <!-- Return Date -->
          <div class="booking-form-group">
            <label for="booking-end">
              <span class="field-icon">📅</span> Return Date
            </label>
            <input id="booking-end" type="date" name="end" value="<?= e($end) ?>" min="<?= e($start >= $today ? $start : $today) ?>" required onchange="updatePriceEstimate()">
          </div>

          <!-- Live Price Breakdown Box -->
          <div class="portal-price-calc-box">
            <div class="calc-row">
              <span class="calc-lbl">Daily Rate:</span>
              <strong class="calc-val">₱<?= number_format((float)$vehicle['daily_rate']) ?></strong>
            </div>
            <div class="calc-row">
              <span class="calc-lbl">Rental Duration:</span>
              <strong class="calc-val" id="calc-days"><?= $days ?> day(s)</strong>
            </div>
            <div class="calc-divider"></div>
            <div class="calc-row total-row">
              <span class="calc-lbl">Estimated Total:</span>
              <strong class="total-amount" id="calc-total">₱<?= number_format($estimate) ?></strong>
            </div>
            <p class="calc-note">Transparent rates &bull; Zero hidden reservation charges</p>
          </div>

          <!-- Purpose of Rental -->
          <div class="booking-form-group">
            <label for="booking-purpose">
              <span class="field-icon">📝</span> Purpose of Rental
            </label>
            <input id="booking-purpose" type="text" name="purpose" value="<?= e($_POST['purpose'] ?? '') ?>" maxlength="255" placeholder="e.g. Business travel, family event" required>
          </div>

          <!-- Payment Method -->
          <div class="booking-form-group">
            <label for="booking-payment-method">
              <span class="field-icon">💳</span> Preferred Payment Method
            </label>
            <select id="booking-payment-method" name="payment_method" required>
              <option value="">Select Payment Method</option>
              <option value="cash" <?= ($_POST['payment_method'] ?? '') === 'cash' ? 'selected' : '' ?>>Cash / In Person at Pickup</option>
              <option value="bank_transfer" <?= ($_POST['payment_method'] ?? '') === 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer</option>
              <option value="gcash" <?= ($_POST['payment_method'] ?? '') === 'gcash' ? 'selected' : '' ?>>GCash</option>
              <option value="card" <?= ($_POST['payment_method'] ?? '') === 'card' ? 'selected' : '' ?>>Credit / Debit Card</option>
            </select>
          </div>

          <!-- Action Buttons / States -->
          <?php if (current_user() && current_user()['role'] === 'customer' && $availableForDates && $canReserve): ?>
            <button class="portal-btn-primary" type="submit">
              <span>Submit Rental Request</span>
              <span aria-hidden="true">&rarr;</span>
            </button>
          <?php elseif (current_user() && current_user()['role'] === 'customer' && !$availableForDates): ?>
            <div class="portal-alert-warning">
              <span>This vehicle is unavailable for the selected dates. Please adjust your travel dates above.</span>
            </div>
            <button class="portal-btn-primary" type="submit" disabled>Unavailable for Selected Dates</button>
          <?php elseif (current_user() && current_user()['role'] === 'customer' && !$canReserve): ?>
            <div class="portal-alert-danger">
              <span>Your account standing currently restricts new reservation requests. Please check your dashboard or contact administration.</span>
            </div>
          <?php elseif (current_user()): ?>
            <div class="portal-alert-info">
              <span>You are signed in as <?= e(ucfirst(current_user()['role'])) ?>. Only customer accounts can submit vehicle rental requests.</span>
            </div>
            <a href="/customer/dashboard.php" class="portal-btn-secondary">Go to Dashboard</a>
          <?php else: ?>
            <a class="portal-btn-primary" href="/login.php?redirect=<?= rawurlencode('/vehicle.php?id=' . (int)$vehicle['id']) ?>">
              <span>Log In to Request Booking</span>
              <span aria-hidden="true">&rarr;</span>
            </a>
            <div class="booking-register-prompt">
              <span>New customer? <a href="/register.php">Create an account</a></span>
            </div>
          <?php endif; ?>
        </form>

        <!-- Helpline Assistance Widget -->
        <div class="booking-support-widget">
          <span class="support-icon">📞</span>
          <div class="support-text">
            <span>Need assistance with booking?</span>
            <a href="tel:+638974561236">+63 897 456 1236</a>
          </div>
        </div>
      </div>
    </aside>
  </div>
</main>

<script>
function switchDetailPhoto(btn, url) {
  const mainImg = document.getElementById('gallery-active-img');
  if (mainImg) {
    mainImg.style.backgroundImage = 'url(' + url + ')';
  }
  document.querySelectorAll('.thumb-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
}

function updatePriceEstimate() {
  const startInput = document.getElementById('booking-start');
  const endInput = document.getElementById('booking-end');
  const daysEl = document.getElementById('calc-days');
  const totalEl = document.getElementById('calc-total');
  const dailyRate = parseFloat(document.getElementById('booking-daily-rate')?.value || '0');

  if (!startInput || !endInput || !daysEl || !totalEl || !dailyRate) return;

  const startVal = startInput.value;
  const endVal = endInput.value;

  if (startVal && endVal && endVal >= startVal) {
    const s = new Date(startVal + 'T00:00:00');
    const e = new Date(endVal + 'T00:00:00');
    const diffTime = e.getTime() - s.getTime();
    const diffDays = Math.round(diffTime / (1000 * 3600 * 24)) + 1;
    const total = diffDays * dailyRate;

    daysEl.textContent = diffDays + ' day(s)';
    totalEl.textContent = '₱' + total.toLocaleString('en-US');
  }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
