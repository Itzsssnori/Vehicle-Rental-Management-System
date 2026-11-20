<?php
$title = 'Vehicle Details - Car Rental Portal';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/pricing-engine.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$start = $_GET['start'] ?? date('Y-m-d');
$end = $_GET['end'] ?? date('Y-m-d', strtotime('+3 days'));
$error = '';
$success = '';

$vehicle = null;
if ($id > 0) {
  $stmt = db()->prepare('
    SELECT v.*, c.name AS category,
           hl.name AS home_location_name, hl.city AS home_location_city,
           cl.name AS current_location_name, cl.city AS current_location_city
    FROM vehicles v 
    INNER JOIN vehicle_categories c ON c.id = v.category_id 
    LEFT JOIN locations hl ON hl.id = v.home_location_id
    LEFT JOIN locations cl ON cl.id = v.current_location_id
    WHERE v.id = ? AND v.deleted_at IS NULL
  ');
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
$locations = get_active_locations();
$defaultPickupLocationId = (int)($vehicle['current_location_id'] ?: ($locations[0]['id'] ?? 1));

// Check user status, standing & verified statutory eligibility (Senior/PWD)
$user = current_user();
$standing = null;
$canReserve = false;
$verifiedStatutory = null;
if ($user && $user['role'] === 'customer') {
  $standing = customer_standing(db(), (int)$user['id']);
  $canReserve = customer_can_reserve(db(), (int)$user['id']);
  try {
    $vStmt = db()->prepare(
      "SELECT id, eligibility_type, id_number, issuing_authority
       FROM customer_eligibility_verifications
       WHERE user_id = ? AND verification_status = 'verified'
         AND (expiry_date IS NULL OR expiry_date >= CURRENT_DATE)
       ORDER BY verified_at DESC LIMIT 1"
    );
    $vStmt->execute([(int)$user['id']]);
    $verifiedStatutory = $vStmt->fetch(PDO::FETCH_ASSOC) ?: null;
  } catch (Throwable $ignore) {}
}

// Fetch active promo codes for client-side live calculator preview
$activePromosForJs = [];
try {
  $pRows = db()->query(
    "SELECT pc.code, dr.calculation_mode, dr.discount_value, dr.max_discount_cap, dr.min_rental_days, dr.min_base_amount
     FROM promo_codes pc
     INNER JOIN discount_rules dr ON dr.id = pc.discount_rule_id
     WHERE pc.is_active = 1 AND dr.is_active = 1"
  )->fetchAll(PDO::FETCH_ASSOC);
  foreach ($pRows as $pr) {
    $activePromosForJs[strtoupper($pr['code'])] = [
      'mode' => $pr['calculation_mode'],
      'val' => (float)$pr['discount_value'],
      'cap' => $pr['max_discount_cap'] !== null ? (float)$pr['max_discount_cap'] : null,
      'minDays' => (int)$pr['min_rental_days'],
      'minAmt' => (float)$pr['min_base_amount'],
    ];
  }
} catch (Throwable $ignore) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf();
  $start = trim($_POST['start'] ?? '');
  $end = trim($_POST['end'] ?? '');
  $paymentMethod = $_POST['payment_method'] ?? '';
  $purpose = trim($_POST['purpose'] ?? '');
  $pickupLocationId = (int)($_POST['pickup_location_id'] ?? $defaultPickupLocationId);
  $returnLocationId = (int)($_POST['return_location_id'] ?? $pickupLocationId);
  $protectionTier = $_POST['protection_tier'] ?? 'basic';
  if (!in_array($protectionTier, ['basic', 'medium', 'premium'], true)) {
    $protectionTier = 'basic';
  }
  $validFormat = preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end);

  if (!current_user()) {
    header('Location: /login.php?redirect=' . rawurlencode('/vehicle.php?id=' . $id));
    exit;
  }
  if (!customer_can_reserve(db(), (int)current_user()['id'])) {
    $error = 'Your account standing is currently not eligible to create new reservations.';
  } elseif ($purpose === '') {
    $error = 'Enter the purpose of this rental.';
  } elseif (!in_array($paymentMethod, ['cash', 'bank_transfer', 'online', 'paymongo', 'paypal'], true)) {
    $error = 'Choose a valid payment method for this rental request.';
  } elseif (($vehicle['status'] ?? '') !== 'operational') {
    $error = 'This vehicle is not currently available for rental.';
  } elseif (!$validFormat || $start < $today || $end < $start) {
    $error = 'Choose valid rental dates. The return date must be on or after the pickup date.';
  } else {
    $pickupAt = $start . ' 09:00:00';
    $returnAt = $end . ' 17:00:00';
    
    // Enforce 2-hour turnaround buffer
    $conflict = db()->prepare(
      "SELECT COUNT(*) FROM reservations
       WHERE vehicle_id = ?
         AND status IN ('pending', 'confirmed')
         AND deleted_at IS NULL
         AND requested_pickup_at < DATE_ADD(?, INTERVAL 2 HOUR)
         AND DATE_ADD(requested_return_at, INTERVAL 2 HOUR) > ?"
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
      $error = 'This vehicle is already requested or booked for those dates (including the mandatory 2-hour cleaning and prep turnaround).';
    } elseif ((int)$maintenance->fetchColumn() > 0) {
      $error = 'This vehicle is scheduled for maintenance during those dates.';
    } else {
      $days = rental_days($start, $end);
      $baseTotal = (float)$vehicle['daily_rate'] * $days;
      $promoCodeInput = strtoupper(trim((string)($_POST['promo_code'] ?? '')));
      $applyStatutory = !empty($_POST['apply_statutory']) && $verifiedStatutory !== null;
      $applyVatExemption = !empty($_POST['apply_vat_exemption']);

      // Calculate protection fee, security deposit, and Philippine PricingEngine breakdown
      $planInfo = get_protection_plan_info($protectionTier);
      $protectionFee = (float)$planInfo['daily_fee'] * $days;
      $securityDeposit = calculate_security_deposit((int)$vehicle['category_id']);

      $pdo = db();
      $pricingEngine = new PricingEngine($pdo, 12.0);
      $pricing = $pricingEngine->calculate([
        'customer_id'          => (int)current_user()['id'],
        'daily_rate'           => (float)$vehicle['daily_rate'],
        'pickup_at'            => $pickupAt,
        'return_at'            => $returnAt,
        'protection_daily_fee' => (float)$planInfo['daily_fee'],
        'promo_code'           => $promoCodeInput,
        'apply_statutory'      => $applyStatutory,
        'apply_vat_exemption'  => $applyVatExemption,
      ]);

      if ($promoCodeInput !== '' && !empty($pricing['promo_error'])) {
        $error = $pricing['promo_error'];
      } else {
      $grandTotal = (float)$pricing['final_total'];

      $pdo->beginTransaction();
      try {
        $reference = 'RES-' . strtoupper(bin2hex(random_bytes(5)));
        $insert = $pdo->prepare(
          "INSERT INTO reservations
           (reference_number, customer_id, vehicle_id, pickup_location_id, return_location_id,
            requested_pickup_at, requested_return_at, base_amount, total_amount, payment_method, purpose,
            protection_tier, protection_fee, security_deposit_amount, security_deposit_status)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
        );
        $insert->execute([
          $reference,
          current_user()['id'],
          $id,
          $pickupLocationId,
          $returnLocationId,
          $pickupAt,
          $returnAt,
          $baseTotal,
          $grandTotal,
          $paymentMethod,
          $purpose,
          $protectionTier,
          $protectionFee,
          $securityDeposit
        ]);
        $reservationId = (int)$pdo->lastInsertId();
        $pricingEngine->persistToReservation($reservationId, $pricing);
        record_reservation_status($pdo, $reservationId, null, 'pending');

        // 1. Base rental charge
        $charge = $pdo->prepare(
          "INSERT INTO rental_charges (reservation_id, charge_type, description, quantity, unit_price, amount)
           VALUES (?, 'rental', 'Base rental charge', ?, ?, ?)"
        );
        $charge->execute([$reservationId, $days, $vehicle['daily_rate'], $baseTotal]);

        // 2. Protection plan charge if applicable
        if ($protectionFee > 0) {
          $planCharge = $pdo->prepare(
            "INSERT INTO rental_charges (reservation_id, charge_type, description, quantity, unit_price, amount)
             VALUES (?, 'insurance', ?, ?, ?, ?)"
          );
          $planCharge->execute([$reservationId, $planInfo['name'], $days, $planInfo['daily_fee'], $protectionFee]);
        }

        // 3. Deposit memo charge
        $depCharge = $pdo->prepare(
          "INSERT INTO rental_charges (reservation_id, charge_type, description, quantity, unit_price, amount)
           VALUES (?, 'deposit', 'Refundable Security Deposit (Held at vehicle turnover)', 1, ?, ?)"
        );
        $depCharge->execute([$reservationId, $securityDeposit, $securityDeposit]);

        // Audit & CDC logs
        write_audit($pdo, (int)current_user()['id'], 'reservation_created', 'reservations', $reservationId, "Customer submitted reservation $reference with {$planInfo['name']}.");
        entity_log_change($pdo, 'reservations', $reservationId, 'INSERT', null, [
          'reference_number' => $reference,
          'vehicle_id' => $id,
          'total_amount' => $grandTotal,
          'protection_tier' => $protectionTier,
          'protection_fee' => $protectionFee,
          'security_deposit' => $securityDeposit,
          'pickup_hub' => $pickupLocationId,
          'return_hub' => $returnLocationId,
        ], (int)current_user()['id']);

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
  <div class="wrap portal-detail-wrap">
    <!-- Breadcrumb and Top Navigation -->
    <div class="detail-breadcrumb-row">
      <nav class="portal-breadcrumb" aria-label="Breadcrumb">
        <a href="/index.php">Home</a>
        <span class="crumb-separator">&rsaquo;</span>
        <a href="/vehicles.php">Car Listing</a>
        <span class="crumb-separator">&rsaquo;</span>
        <span class="crumb-current"><?= e($vehicle['brand']) ?> <?= e($vehicle['model']) ?></span>
      </nav>
      <a href="/vehicles.php" class="detail-back-link">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        <span>Back to Car Listing</span>
      </a>
    </div>

    <div class="detail-main-layout">
      <!-- Left Column: Vehicle Visuals & Detailed Info -->
      <div class="detail-left-col">
        <!-- Gallery Card -->
        <div class="vehicle-gallery-card">
          <div class="gallery-primary-frame">
            <div id="gallery-active-img" class="gallery-active-img" style="background-image: url('<?= e($gallery[0]) ?>');" role="img" aria-label="<?= e($vehicle['brand'] . ' ' . $vehicle['model']) ?>">
              <span class="gallery-status-pill <?= $availableForDates ? 'status-pill-avail' : 'status-pill-unavail' ?>">
                <?php if ($availableForDates): ?>
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                  <span>Available for Selected Dates</span>
                <?php else: ?>
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                  <span>Unavailable for Selected Dates</span>
                <?php endif; ?>
              </span>
            </div>
          </div>

          <!-- Dark Specification Ribbon Directly Underneath Hero Photo -->
          <div class="car-spec-ribbon detail-spec-ribbon">
            <div class="spec-col">
              <span class="spec-glyph" aria-hidden="true">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 22h12M4 9h10M4 5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v17H4V5zM14 9h2a2 2 0 0 1 2 2v3a2 2 0 0 0 2 2h0a2 2 0 0 0 2-2V9l-3-3"/></svg>
              </span>
              <span class="spec-lbl"><?= e($fuel) ?></span>
            </div>
            <div class="spec-col">
              <span class="spec-glyph" aria-hidden="true">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
              </span>
              <span class="spec-lbl"><?= (int)$year ?> Model</span>
            </div>
            <div class="spec-col">
              <span class="spec-glyph" aria-hidden="true">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
              </span>
              <span class="spec-lbl"><?= $seats ?> Seats</span>
            </div>
            <div class="spec-col">
              <span class="spec-glyph" aria-hidden="true">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
              </span>
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
              <span class="matrix-lbl">Current Station Hub</span>
              <strong class="matrix-val"><?= e($vehicle['current_location_name'] ?? 'Main Campus Hub') ?></strong>
              <span style="font-size:0.75rem; color:#64748b; font-weight:500; display:block; margin-top:2px;"><?= e($vehicle['current_location_city'] ?? 'Quezon City') ?></span>
            </div>
            <div class="matrix-item">
              <span class="matrix-lbl">Fuel Tank</span>
              <strong class="matrix-val"><?= number_format((float)($vehicle['fuel_capacity_liters'] ?? 45.0), 1) ?> Liters</strong>
            </div>
            <div class="matrix-item">
              <span class="matrix-lbl">Mileage Allowance</span>
              <strong class="matrix-val"><?= (int)($vehicle['daily_mileage_limit'] ?? 200) ?> km / day</strong>
            </div>
            <div class="matrix-item">
              <span class="matrix-lbl">Excess Mileage</span>
              <strong class="matrix-val">₱<?= number_format((float)($vehicle['excess_mileage_rate'] ?? 12.0), 2) ?> / km</strong>
            </div>
            <div class="matrix-item">
              <span class="matrix-lbl">Fleet Status</span>
              <strong class="matrix-val text-status-good">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align: -1px; margin-right: 4px;"><polyline points="20 6 9 17 4 12"/></svg>Operational &amp; Inspected
              </strong>
            </div>
          </div>
        </div>

        <!-- Features & Amenities Checklist -->
        <div class="detail-section-card">
          <h3 class="section-card-title">Features & Included Amenities</h3>
          <div class="features-checklist-grid">
            <div class="check-item">
              <span class="check-glyph" aria-hidden="true"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>
              <span>Air Conditioning & Climate Control</span>
            </div>
            <div class="check-item">
              <span class="check-glyph" aria-hidden="true"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>
              <span>Bluetooth / USB Audio Connectivity</span>
            </div>
            <div class="check-item">
              <span class="check-glyph" aria-hidden="true"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>
              <span>Anti-Lock Braking System (ABS) & Dual Airbags</span>
            </div>
            <div class="check-item">
              <span class="check-glyph" aria-hidden="true"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>
              <span>Power Steering & Power Windows</span>
            </div>
            <div class="check-item">
              <span class="check-glyph" aria-hidden="true"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>
              <span>Multi-Point Safety Inspection Prior to Handover</span>
            </div>
            <div class="check-item">
              <span class="check-glyph" aria-hidden="true"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>
              <span>24/7 Helpline & Roadside Support</span>
            </div>
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
              <span class="alert-icon" aria-hidden="true">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
              </span>
              <span><?= e($error) ?></span>
            </div>
          <?php endif; ?>

          <?php if ($user && $user['role'] === 'customer'): ?>
            <div class="customer-standing-pill standing-<?= e($standing['standing'] ?? 'good') ?>">
              <span class="standing-icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
              </span>
              <div class="standing-copy">
                <span class="standing-lbl">Account Standing</span>
                <strong><?= e(ucfirst($standing['standing'] ?? 'Good')) ?> &bull; Eligible to Book</strong>
              </div>
            </div>
          <?php elseif (!current_user()): ?>
            <div class="guest-booking-tip">
              <span class="tip-icon" aria-hidden="true">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18h6"/><path d="M10 22h4"/><path d="M15.09 14c.18-.98.65-1.74 1.41-2.5A4.65 4.65 0 0 0 18 8 6 6 0 0 0 6 8c0 1 .23 2.23 1.5 3.5.76.76 1.23 1.52 1.41 2.5"/></svg>
              </span>
              <span><strong>Quick Note:</strong> Log in below or create an account to submit your request.</span>
            </div>
          <?php endif; ?>

          <form class="portal-booking-form" method="post" id="reservation-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int)$vehicle['id'] ?>">
            <input type="hidden" id="booking-daily-rate" value="<?= (float)$vehicle['daily_rate'] ?>">

            <!-- Pickup & Return Location Hubs -->
            <div style="display:grid; grid-template-columns:repeat(2, 1fr); gap:10px; margin-bottom:12px;">
              <div class="booking-form-group" style="margin-bottom:0;">
                <label for="pickup_location_id">
                  <span class="field-icon" aria-hidden="true">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                  </span>
                  <span>Pickup Hub</span>
                </label>
                <select id="pickup_location_id" name="pickup_location_id" required style="font-size:0.83rem;">
                  <?php foreach ($locations as $loc): ?>
                    <option value="<?= (int)$loc['id'] ?>" <?= (int)$loc['id'] === $defaultPickupLocationId ? 'selected' : '' ?>>
                      <?= e($loc['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="booking-form-group" style="margin-bottom:0;">
                <label for="return_location_id">
                  <span class="field-icon" aria-hidden="true">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
                  </span>
                  <span>Return Hub</span>
                </label>
                <select id="return_location_id" name="return_location_id" required style="font-size:0.83rem;">
                  <?php foreach ($locations as $loc): ?>
                    <option value="<?= (int)$loc['id'] ?>" <?= (int)$loc['id'] === $defaultPickupLocationId ? 'selected' : '' ?>>
                      <?= e($loc['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <!-- Date Selection Row -->
            <div style="display:grid; grid-template-columns:repeat(2, 1fr); gap:10px; margin-bottom:12px;">
              <div class="booking-form-group" style="margin-bottom:0;">
                <label for="booking-start">
                  <span class="field-icon" aria-hidden="true">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
                  </span>
                  <span>Pickup Date</span>
                </label>
                <input id="booking-start" type="date" name="start" value="<?= e($start) ?>" min="<?= e($today) ?>" required onchange="updatePriceEstimate()" style="font-size:0.83rem;">
              </div>

              <div class="booking-form-group" style="margin-bottom:0;">
                <label for="booking-end">
                  <span class="field-icon" aria-hidden="true">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" x2="16" y1="2" y2="6"/><line x1="8" x2="8" y1="2" y2="6"/><line x1="3" x2="21" y1="10" y2="10"/></svg>
                  </span>
                  <span>Return Date</span>
                </label>
                <input id="booking-end" type="date" name="end" value="<?= e($end) ?>" min="<?= e($start >= $today ? $start : $today) ?>" required onchange="updatePriceEstimate()" style="font-size:0.83rem;">
              </div>
            </div>

            <!-- Protection Plan Selection -->
            <div class="booking-form-group" style="margin-bottom:12px;">
              <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                <label style="margin-bottom:0; font-size:0.73rem;">
                  <span class="field-icon" aria-hidden="true">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                  </span>
                  <span>Protection Tier</span>
                </label>
                <span style="font-size:0.68rem; color:#64748b; font-weight:600;">Tap to select</span>
              </div>

              <!-- 3-Segment Compact Grid -->
              <div class="prot-tier-grid">
                <!-- Tier 1: Basic -->
                <label class="prot-tier-pill is-active" id="prot-pill-basic" onclick="selectProtectionTier('basic')">
                  <input type="radio" name="protection_tier" value="basic" checked onchange="selectProtectionTier('basic')">
                  <span class="prot-pill-name">Basic</span>
                  <strong class="prot-pill-rate">Included</strong>
                  <span class="prot-pill-sub">₱15k excess</span>
                </label>

                <!-- Tier 2: Standard CDW -->
                <label class="prot-tier-pill" id="prot-pill-medium" onclick="selectProtectionTier('medium')">
                  <input type="radio" name="protection_tier" value="medium" onchange="selectProtectionTier('medium')">
                  <span class="prot-pill-name">Standard</span>
                  <strong class="prot-pill-rate is-accent">+₱350/d</strong>
                  <span class="prot-pill-sub">₱5k excess</span>
                </label>

                <!-- Tier 3: Zero-Excess -->
                <label class="prot-tier-pill" id="prot-pill-premium" onclick="selectProtectionTier('premium')">
                  <input type="radio" name="protection_tier" value="premium" onchange="selectProtectionTier('premium')">
                  <span class="prot-pill-name">Zero-Excess</span>
                  <strong class="prot-pill-rate is-accent">+₱600/d</strong>
                  <span class="prot-pill-sub">₱0 deductible</span>
                </label>
              </div>

              <!-- Single Compact Dynamic Detail Line -->
              <div class="prot-info-bar" id="prot-info-bar">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
                <span id="prot-info-text"><strong>Basic CTPL:</strong> Compulsory third-party liability (₱15,000 max customer excess).</span>
              </div>
            </div>

            <!-- Discounts & Statutory Privileges (PH Pricing Engine) -->
            <div class="booking-form-group" style="margin-bottom:12px;">
              <label for="booking-promo-code" style="font-size:0.73rem;">
                <span class="field-icon" aria-hidden="true">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                </span>
                <span>Promo Code <span style="font-weight:500; color:#64748b;">(optional: VELO10, WEEKEND500)</span></span>
              </label>
              <input id="booking-promo-code" type="text" name="promo_code" value="<?= e($_POST['promo_code'] ?? '') ?>" maxlength="40" placeholder="Enter voucher code" oninput="updatePriceEstimate()" style="height:34px; font-size:0.82rem; text-transform:uppercase;">

              <?php if ($verifiedStatutory): ?>
                <div style="margin-top:8px; padding:8px 10px; background:#ecfdf5; border:1px solid #a7f3d0; border-radius:8px; font-size:0.74rem; color:#065f46;">
                  <label style="display:flex; align-items:center; gap:6px; margin:0; cursor:pointer; font-weight:700; color:#065f46; text-transform:none; letter-spacing:0;">
                    <input type="checkbox" id="booking-apply-statutory" name="apply_statutory" value="1" checked onchange="updatePriceEstimate()" style="width:auto; height:auto;">
                    <span>Apply 20% <?= $verifiedStatutory['eligibility_type'] === 'senior_citizen' ? 'Senior Citizen (RA 9994)' : 'PWD (RA 10754)' ?> Discount</span>
                  </label>
                  <label style="display:flex; align-items:center; gap:6px; margin:4px 0 0 18px; cursor:pointer; font-weight:600; color:#047857; text-transform:none; letter-spacing:0; font-size:0.71rem;">
                    <input type="checkbox" id="booking-apply-vat-exempt" name="apply_vat_exemption" value="1" checked onchange="updatePriceEstimate()" style="width:auto; height:auto;">
                    <span>Include 12% PH VAT Exemption (Verified ID: <?= e($verifiedStatutory['id_number']) ?>)</span>
                  </label>
                </div>
              <?php elseif (current_user() && current_user()['role'] === 'customer'): ?>
                <p style="margin:6px 0 0; font-size:0.71rem; color:#64748b;">
                  Senior Citizen or PWD? <a href="/customer/account-settings.php#statutory-id" style="color:var(--coral); font-weight:700;">Verify your OSCA/NCDA ID</a> for 20% + VAT-exempt rates.
                </p>
              <?php endif; ?>
            </div>

            <!-- Live Price Breakdown Box -->
            <div class="portal-price-calc-box" style="margin-bottom:10px; padding:10px 14px;">
              <div class="calc-row">
                <span class="calc-lbl">Base Daily Rate:</span>
                <strong class="calc-val">₱<?= number_format((float)$vehicle['daily_rate']) ?>/day</strong>
              </div>
              <div class="calc-row">
                <span class="calc-lbl">Rental Duration:</span>
                <strong class="calc-val" id="calc-days"><?= $days ?> day(s)</strong>
              </div>
              <div class="calc-row">
                <span class="calc-lbl">Base Rental Total:</span>
                <strong class="calc-val" id="calc-base-total">₱<?= number_format((float)$vehicle['daily_rate'] * $days) ?></strong>
              </div>
              <div class="calc-row">
                <span class="calc-lbl">Protection Plan:</span>
                <strong class="calc-val" id="calc-protection-fee">₱0</strong>
              </div>
              <div class="calc-row" id="calc-discount-row" style="display:none; color:#059669;">
                <span class="calc-lbl" id="calc-discount-label">Discount &amp; VAT Savings:</span>
                <strong class="calc-val" id="calc-discount-amount" style="color:#059669;">-₱0</strong>
              </div>
              <div class="calc-divider" style="margin:6px 0;"></div>
              <div class="calc-row total-row">
                <span class="calc-lbl">Estimated Total Due:</span>
                <strong class="total-amount" id="calc-total" style="font-size:1.22rem;">₱<?= number_format($estimate) ?></strong>
              </div>
              <div style="margin-top:6px; padding-top:6px; border-top:1px dashed #cbd5e1; font-size:0.74rem; color:#475569; display:flex; justify-content:space-between; align-items:center;">
                <span>Refundable Deposit:</span>
                <strong style="color:#0f172a; font-size:0.8rem;">₱<?= number_format(calculate_security_deposit((int)$vehicle['category_id'])) ?></strong>
              </div>
              <p class="calc-note" style="margin-top:3px; font-size:0.67rem; color:#64748b;">Deposit is held at turnover and released after vehicle inspection.</p>
            </div>

            <!-- Purpose of Rental -->
            <div class="booking-form-group" style="margin-bottom:10px;">
              <label for="booking-purpose">
                <span class="field-icon" aria-hidden="true">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                </span>
                <span>Purpose of Rental</span>
              </label>
              <input id="booking-purpose" type="text" name="purpose" value="<?= e($_POST['purpose'] ?? '') ?>" maxlength="255" placeholder="e.g. Business travel, family event" required style="height:36px; font-size:0.83rem;">
            </div>

            <!-- Payment Method -->
            <div class="booking-form-group" style="margin-bottom:12px;">
              <label for="booking-payment-method">
                <span class="field-icon" aria-hidden="true">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" x2="22" y1="10" y2="10"/></svg>
                </span>
                <span>Preferred Payment Method</span>
              </label>
              <select id="booking-payment-method" name="payment_method" required style="height:36px; font-size:0.83rem;">
                <option value="">Select Payment Method</option>
                <option value="cash" <?= ($_POST['payment_method'] ?? '') === 'cash' ? 'selected' : '' ?>>Cash / In Person at Pickup</option>
                <option value="bank_transfer" <?= ($_POST['payment_method'] ?? '') === 'bank_transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                <option value="online" <?= ($_POST['payment_method'] ?? '') === 'online' ? 'selected' : '' ?>>Online Payment</option>
              </select>
            </div>

            <!-- Action Buttons / States -->
            <?php if (current_user() && current_user()['role'] === 'customer' && $availableForDates && $canReserve): ?>
              <button class="portal-btn-primary" type="submit" style="height:40px; font-size:0.88rem;">
                <span>Submit Rental Request</span>
                <span aria-hidden="true">&rarr;</span>
              </button>
            <?php elseif (current_user() && current_user()['role'] === 'customer' && !$availableForDates): ?>
              <div class="portal-alert-warning" style="padding:10px 12px; font-size:0.82rem;">
                <span>This vehicle is unavailable for the selected dates. Please adjust your travel dates above.</span>
              </div>
              <button class="portal-btn-primary" type="submit" disabled style="height:40px; font-size:0.88rem;">Unavailable for Selected Dates</button>
            <?php elseif (current_user() && current_user()['role'] === 'customer' && !$canReserve): ?>
              <div class="portal-alert-danger" style="padding:10px 12px; font-size:0.82rem;">
                <span>Your account standing currently restricts new reservation requests. Please check your dashboard or contact administration.</span>
              </div>
            <?php elseif (current_user()): ?>
              <div class="portal-alert-info" style="padding:10px 12px; font-size:0.82rem;">
                <span>You are signed in as <?= e(ucfirst(current_user()['role'])) ?>. Only customer accounts can submit vehicle rental requests.</span>
              </div>
              <a href="/customer/dashboard.php" class="portal-btn-secondary" style="height:38px; font-size:0.84rem;">Go to Dashboard</a>
            <?php else: ?>
              <a class="portal-btn-primary" href="/login.php?redirect=<?= rawurlencode('/vehicle.php?id=' . (int)$vehicle['id']) ?>" style="height:40px; font-size:0.88rem;">
                <span>Log In to Request Booking</span>
                <span aria-hidden="true">&rarr;</span>
              </a>
              <div class="booking-register-prompt" style="margin-top:6px;">
                <span style="font-size:0.78rem;">New customer? <a href="/register.php">Create an account</a></span>
              </div>
            <?php endif; ?>
          </form>

          <!-- Helpline Assistance Widget -->
          <div class="booking-support-widget">
            <span class="support-icon" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
            </span>
            <div class="support-text">
              <span>Need assistance with booking?</span>
              <a href="tel:+638974561236">+63 897 456 1236</a>
            </div>
          </div>
        </div>
      </aside>
    </div>
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

const protDescriptions = {
  basic: '<strong>Basic CTPL:</strong> Compulsory third-party liability (₱15,000 max customer excess).',
  medium: '<strong>Standard CDW:</strong> Covers collisions, scratches and body dents (₱5,000 excess).',
  premium: '<strong>Zero-Excess:</strong> Full body, glass, tires & roadside protection (₱0 excess).'
};

function selectProtectionTier(tier) {
  const radio = document.querySelector('input[name="protection_tier"][value="' + tier + '"]');
  if (radio) {
    radio.checked = true;
  }
  document.querySelectorAll('.prot-tier-pill').forEach(function(pill) {
    pill.classList.toggle('is-active', pill.getAttribute('id') === 'prot-pill-' + tier);
  });
  const infoText = document.getElementById('prot-info-text');
  if (infoText && protDescriptions[tier]) {
    infoText.innerHTML = protDescriptions[tier];
  }
  updatePriceEstimate();
}

const ACTIVE_PROMOS = <?= json_encode($activePromosForJs, JSON_UNESCAPED_SLASHES) ?>;

function updatePriceEstimate() {
  const startInput = document.getElementById('booking-start');
  const endInput = document.getElementById('booking-end');
  const daysEl = document.getElementById('calc-days');
  const baseTotalEl = document.getElementById('calc-base-total');
  const protFeeEl = document.getElementById('calc-protection-fee');
  const discountRow = document.getElementById('calc-discount-row');
  const discountLabelEl = document.getElementById('calc-discount-label');
  const discountAmtEl = document.getElementById('calc-discount-amount');
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
    const baseTotal = diffDays * dailyRate;

    let protDaily = 0;
    const selectedProt = document.querySelector('input[name="protection_tier"]:checked')?.value || 'basic';
    if (selectedProt === 'medium') protDaily = 350;
    else if (selectedProt === 'premium') protDaily = 600;

    const protFee = diffDays * protDaily;
    const grossTotal = baseTotal + protFee;

    // 1. Statutory Senior/PWD savings
    let statutorySavings = 0;
    const applyStat = document.getElementById('booking-apply-statutory')?.checked || false;
    const applyVatEx = document.getElementById('booking-apply-vat-exempt')?.checked || false;
    if (applyStat) {
      const vatableSales = grossTotal / 1.12;
      const vatExempt = applyVatEx ? (grossTotal - vatableSales) : 0;
      const discBase = applyVatEx ? vatableSales : grossTotal;
      statutorySavings = vatExempt + (discBase * 0.20);
    }

    // 2. Commercial savings (Duration tier + Promo code)
    let commSavings = 0;
    let commLabels = [];
    if (diffDays >= 30) {
      commSavings += grossTotal * 0.20;
      commLabels.push('30+ Days 20%');
    } else if (diffDays >= 7) {
      commSavings += grossTotal * 0.10;
      commLabels.push('7+ Days 10%');
    }
    const promoCode = (document.getElementById('booking-promo-code')?.value || '').trim().toUpperCase();
    if (promoCode && ACTIVE_PROMOS[promoCode]) {
      const p = ACTIVE_PROMOS[promoCode];
      if (diffDays >= p.minDays && grossTotal >= p.minAmt) {
        const rem = Math.max(0, grossTotal - commSavings);
        let pAmt = p.mode === 'percentage' ? rem * (p.val / 100) : Math.min(rem, p.val);
        if (p.cap !== null) pAmt = Math.min(pAmt, p.cap);
        commSavings += pAmt;
        commLabels.push(promoCode);
      }
    }

    // RA 9994 / RA 10754: Higher benefit wins (non-stackable)
    let totalSavings = 0;
    let savingsLbl = 'Discount Savings:';
    if (applyStat && statutorySavings >= commSavings) {
      totalSavings = statutorySavings;
      savingsLbl = applyVatEx ? 'Senior/PWD 20% + VAT Exempt:' : 'Senior/PWD 20% Discount:';
    } else if (commSavings > 0) {
      totalSavings = commSavings;
      savingsLbl = 'Promo (' + commLabels.join(' + ') + '):';
    }

    const grandTotal = Math.max(0, Math.round((grossTotal - totalSavings) * 100) / 100);

    daysEl.textContent = diffDays + ' day(s)';
    if (baseTotalEl) baseTotalEl.textContent = '₱' + baseTotal.toLocaleString('en-US', { maximumFractionDigits: 2 });
    if (protFeeEl) protFeeEl.textContent = '₱' + protFee.toLocaleString('en-US', { maximumFractionDigits: 2 });
    if (discountRow && discountAmtEl && discountLabelEl) {
      if (totalSavings > 0) {
        discountRow.style.display = 'flex';
        discountLabelEl.textContent = savingsLbl;
        discountAmtEl.textContent = '-₱' + Math.round(totalSavings).toLocaleString('en-US');
      } else {
        discountRow.style.display = 'none';
      }
    }
    totalEl.textContent = '₱' + Math.round(grandTotal).toLocaleString('en-US');
  }
}
document.addEventListener('DOMContentLoaded', updatePriceEstimate);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
