<?php
$title = 'Browse Vehicles - VELO';
require_once __DIR__ . '/../config.php';
include __DIR__ . '/../includes/header.php';
$user = current_user();
$customerView = $user && $user['role'] === 'customer';

$search = trim($_GET['search'] ?? $_GET['q'] ?? '');
$cat   = $_GET['category'] ?? '';
$trans = $_GET['transmission'] ?? '';
$locFilter = filter_input(INPUT_GET, 'location', FILTER_VALIDATE_INT);
$max   = is_numeric($_GET['max_price'] ?? '') ? (float)$_GET['max_price'] : null;
$start = $_GET['start'] ?? '';
$end   = $_GET['end'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 6;
$validDates = preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) && $end >= $start;
$allLocations = get_active_locations();

$sql = "SELECT v.*, c.name AS category, l.name AS current_location_name, l.city AS current_location_city
        FROM vehicles v 
        INNER JOIN vehicle_categories c ON c.id = v.category_id 
        LEFT JOIN locations l ON l.id = v.current_location_id
        WHERE v.status = 'operational' AND v.deleted_at IS NULL";
$params = [];

if ($search !== '') {
  $sql .= ' AND (v.brand LIKE ? OR v.model LIKE ? OR c.name LIKE ?)';
  $params[] = '%' . $search . '%';
  $params[] = '%' . $search . '%';
  $params[] = '%' . $search . '%';
}
if (in_array($cat, ['Sedan', 'SUV', 'Premium'], true)) {
  $sql .= ' AND c.name = ?';
  $params[] = $cat;
}
if ($locFilter) {
  $sql .= ' AND v.current_location_id = ?';
  $params[] = $locFilter;
}
if (in_array($trans, ['Automatic', 'Manual'], true)) {
  $sql .= ' AND v.transmission = ?';
  $params[] = $trans;
}
if ($max !== null) {
  $sql .= ' AND v.daily_rate <= ?';
  $params[] = $max;
}
if ($validDates) {
  $sql .= " AND NOT EXISTS (
      SELECT 1 FROM reservations r
      WHERE r.vehicle_id = v.id
        AND r.status IN ('pending', 'confirmed')
        AND r.deleted_at IS NULL
        AND r.requested_pickup_at < DATE_ADD(CONCAT(?, ' 23:59:59'), INTERVAL 2 HOUR)
        AND DATE_ADD(r.requested_return_at, INTERVAL 2 HOUR) > CONCAT(?, ' 00:00:00')
  )
  AND NOT EXISTS (
      SELECT 1 FROM maintenance m
      WHERE m.vehicle_id = v.id
        AND m.start_date <= ?
        AND (m.end_date IS NULL OR m.end_date >= ?)
  )";
  $params[] = $end;
  $params[] = $start;
  $params[] = $end;
  $params[] = $start;
}
$countSql = preg_replace('/^SELECT v\.\*, .*? FROM /', 'SELECT COUNT(*) FROM ', $sql);
$countStmt = db()->prepare($countSql);
$countStmt->execute($params);
$totalVehicles = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalVehicles / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$sql .= " ORDER BY v.daily_rate ASC, v.id ASC LIMIT {$perPage} OFFSET {$offset}";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$vehicles = $stmt->fetchAll();
?>

<?php if ($customerView): ?>
<main class="portal-customer-page">
  <div class="portal-customer-layout">
    <?php include __DIR__ . '/../includes/customer-sidebar.php'; ?>
    <div class="customer-main-content">
      <section class="portal-hero-banner">
        <div class="hero-greeting-content">
          <span class="eyebrow">Fleet Discovery</span>
          <h1>Browse Vehicle Fleet</h1>
          <p>Compare premium vehicles, inspect specifications, check operational availability, and reserve in minutes.</p>
        </div>
        <span class="portal-badge badge-neutral" style="font-size:0.84rem;padding:6px 14px;background:rgba(255,255,255,0.12);color:#ffffff;border:1px solid rgba(255,255,255,0.22);white-space:nowrap;">
          <?= $totalVehicles ?> vehicles operational
        </span>
      </section>

      <section class="portal-card" style="padding:20px;">
<?php else: ?>
<main class="catalog-page">
  <section class="catalog-hero">
    <div>
      <p class="eyebrow">Fleet catalog</p>
      <h1>Car Listing & Availability</h1>
      <p>Compare vehicles, review features, check availability, and request a booking with transparent daily pricing.</p>
    </div>
    <span class="catalog-count"><?= $totalVehicles ?> vehicles found</span>
  </section>

  <section class="catalog-content">
<?php endif; ?>

  <form class="portal-filter-card" method="get">
    <input type="hidden" name="start" value="<?= e($start) ?>">
    <input type="hidden" name="end" value="<?= e($end) ?>">
    <input type="hidden" name="page" value="1">

    <div class="filter-field">
      <label>Search Keyword</label>
      <input type="text" name="search" placeholder="e.g. Toyota, Fortuner..." value="<?= e($search) ?>">
    </div>

    <div class="filter-field">
      <label>Category</label>
      <select name="category">
        <option value="">All Categories</option>
        <?php foreach (['Sedan', 'SUV', 'Premium'] as $option): ?>
          <option value="<?= e($option) ?>" <?= $cat === $option ? 'selected' : '' ?>><?= e($option) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="filter-field">
      <label>Transmission</label>
      <select name="transmission">
        <option value="">All Transmissions</option>
        <?php foreach (['Automatic', 'Manual'] as $option): ?>
          <option value="<?= e($option) ?>" <?= $trans === $option ? 'selected' : '' ?>><?= e($option) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="filter-field">
      <label>Max Daily Rate (₱)</label>
      <input type="number" name="max_price" min="0" step="100" placeholder="e.g. 3500" value="<?= e($_GET['max_price'] ?? '') ?>">
    </div>

    <div class="filter-field">
      <label>Pickup Venue / Hub</label>
      <select name="location">
        <option value="">All Venues &amp; Hubs</option>
        <?php foreach ($allLocations as $loc): ?>
          <option value="<?= (int)$loc['id'] ?>" <?= $locFilter === (int)$loc['id'] ? 'selected' : '' ?>>
            <?= e($loc['name']) ?> (<?= e($loc['city']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="filter-actions">
      <button class="btn btn-navy" type="submit">Filter Fleet</button>
      <?php if ($search !== '' || $cat !== '' || $trans !== '' || $max !== null || $locFilter): ?>
        <a class="btn-clear-filter" href="/vehicles.php">Reset</a>
      <?php endif; ?>
    </div>
  </form>

  <?php if ($validDates): ?>
    <div class="portal-date-alert">
      <span>Showing vehicles verified available from <strong><?= e($start) ?></strong> to <strong><?= e($end) ?></strong>.</span>
    </div>
  <?php endif; ?>

  <?php if (!$vehicles): ?>
    <div class="empty-state">
      <h3>No vehicles match your criteria</h3>
      <p>Try clearing your search keyword, adjusting price filters, or choosing different rental dates.</p>
      <a class="btn btn-navy" href="/vehicles.php">Reset Filters</a>
    </div>
  <?php else: ?>
    <div class="car-portal-grid">
      <?php foreach ($vehicles as $vehicle):
        $days = $validDates ? rental_days($start, $end) : null;
        $image = vehicle_image($vehicle['image']);
        $fuel = $vehicle['fuel_type'] ?: 'Petrol';
        $year = $vehicle['year'] ?: 2022;
        $seats = (int)($vehicle['seats'] ?: 5);
        $desc = $vehicle['description'] ?: ($vehicle['category'] . ' with ' . strtolower($vehicle['transmission']) . ' transmission, well-maintained for comfort and fuel economy.');
      ?>
        <article class="car-listing-card">
          <div class="card-media-wrapper">
            <a href="/vehicle.php?id=<?= (int)$vehicle['id'] ?>&start=<?= e($start) ?>&end=<?= e($end) ?>" class="card-img-link" tabindex="-1" aria-hidden="true">
              <div class="card-img" style="background-image:url('<?= e($image) ?>');" role="img" aria-label="<?= e($vehicle['brand'] . ' ' . $vehicle['model']) ?>"></div>
            </a>

            <!-- Dark Specification Ribbon Underneath Image -->
            <div class="car-spec-ribbon">
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
                <span class="spec-lbl"><?= $seats ?> seats</span>
              </div>
            </div>
          </div>

          <div class="card-info-content">
            <div class="card-title-price-row">
              <h3 class="card-vehicle-title">
                <a href="/vehicle.php?id=<?= (int)$vehicle['id'] ?>&start=<?= e($start) ?>&end=<?= e($end) ?>">
                  <?= e($vehicle['brand']) ?> <?= e($vehicle['model']) ?>
                </a>
              </h3>
              <div class="card-daily-price">
                <strong>₱<?= number_format((float)$vehicle['daily_rate']) ?></strong>
                <span class="price-cycle">/Day</span>
              </div>
            </div>

            <div style="display:inline-flex; align-items:center; gap:5px; font-size:0.74rem; font-weight:600; color:#334155; background:#f8fafc; padding:3px 9px; border-radius:999px; margin:4px 0 8px 0; border:1px solid #e2e8f0;">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--coral);"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
              <span><?= e(trim(explode('(', $vehicle['current_location_name'] ?? 'Main Campus Hub')[0])) ?> &bull; <?= e($vehicle['current_location_city'] ?? 'Metro Manila') ?></span>
            </div>

            <p class="card-snippet-desc">
              <?= e(strlen($desc) > 95 ? substr($desc, 0, 92) . '...' : $desc) ?>
            </p>

            <?php if ($days): ?>
              <div class="card-estimate-pill">
                <span>Total for <?= $days ?> day(s): <strong>₱<?= number_format((float)$vehicle['daily_rate'] * $days) ?></strong></span>
              </div>
            <?php endif; ?>

            <div class="card-action-bar">
              <span class="card-status-pill status-ready"><?= $validDates ? 'Available for Dates' : 'Operational' ?></span>
              <a href="/vehicle.php?id=<?= (int)$vehicle['id'] ?>&start=<?= e($start) ?>&end=<?= e($end) ?>" class="card-action-btn">
                View Details &rarr;
              </a>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($totalPages > 1): ?>
    <nav class="pagination" aria-label="Vehicle catalog pages">
      <?php for ($number = 1; $number <= $totalPages; $number++): ?>
        <a class="<?= $number === $page ? 'active' : '' ?>" href="?<?= e(http_build_query(['search' => $search, 'category' => $cat, 'transmission' => $trans, 'max_price' => $_GET['max_price'] ?? '', 'start' => $start, 'end' => $end, 'page' => $number])) ?>"><?= $number ?></a>
      <?php endfor; ?>
    </nav>
  <?php endif; ?>
  </section>
  <?php if ($customerView): ?>
      </div>
    </div>
  </main>
  <?php else: ?>
  </main>
  <?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
