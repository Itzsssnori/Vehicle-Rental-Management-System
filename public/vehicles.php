<?php
$title = 'Browse Vehicles';
require_once __DIR__ . '/../config.php';
include __DIR__ . '/../includes/header.php';
$user = current_user();
$customerView = $user && $user['role'] === 'customer';

$cat   = $_GET['category'] ?? '';
$trans = $_GET['transmission'] ?? '';
$max   = is_numeric($_GET['max_price'] ?? '') ? (float)$_GET['max_price'] : null;
$start = $_GET['start'] ?? '';
$end   = $_GET['end'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 6;
$validDates = preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) && $end >= $start;

$sql = "SELECT v.* FROM vehicles v WHERE v.status = 'operational'";
$params = [];
if (in_array($cat, ['Sedan', 'SUV', 'Premium'], true)) {
  $sql .= ' AND v.category = ?';
  $params[] = $cat;
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
      SELECT 1 FROM rentals r
      WHERE r.vehicle_id = v.id
        AND r.status IN ('pending', 'confirmed', 'ongoing')
        AND r.start_date <= ?
        AND r.end_date >= ?
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
$countSql = preg_replace('/^SELECT v\.\* /', 'SELECT COUNT(*) ', $sql);
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

<main class="<?= $customerView ? 'dashboard-page customer-catalog-page' : 'catalog-page' ?>">
  <?php if ($customerView): ?><div class="dashboard-layout catalog-dashboard-layout"><?php include __DIR__ . '/../includes/customer-sidebar.php'; ?><div class="catalog-main"><?php endif; ?>
  <section class="catalog-hero">
    <div>
      <p class="eyebrow">Fleet catalog</p>
      <h1>Browse Vehicles</h1>
      <p>Find the right vehicle for your trip, compare pricing, and check availability before you submit a request.</p>
    </div>
    <span class="catalog-count"><?= $totalVehicles ?> vehicles available</span>
  </section>

  <section class="catalog-content">

  <form class="filter-bar" method="get">
    <input type="hidden" name="start" value="<?= e($start) ?>">
    <input type="hidden" name="end" value="<?= e($end) ?>">
    <input type="hidden" name="page" value="1">

    <label>
      Category
      <select name="category">
        <option value="">All</option>
        <?php foreach (['Sedan', 'SUV', 'Premium'] as $option): ?>
          <option value="<?= e($option) ?>" <?= $cat === $option ? 'selected' : '' ?>><?= e($option) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label>
      Transmission
      <select name="transmission">
        <option value="">All</option>
        <?php foreach (['Automatic', 'Manual'] as $option): ?>
          <option value="<?= e($option) ?>" <?= $trans === $option ? 'selected' : '' ?>><?= e($option) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label>
      Max daily rate (₱)
      <input type="number" name="max_price" min="0" step="100" value="<?= e($_GET['max_price'] ?? '') ?>">
    </label>

    <button class="btn btn-navy" type="submit">Apply Filters</button>
  </form>

  <?php if ($validDates): ?>
    <p class="note">Showing vehicles free from <strong><?= e($start) ?></strong> to <strong><?= e($end) ?></strong>.</p>
  <?php endif; ?>

  <?php if (!$vehicles): ?>
    <div class="empty-state">
      <h3>No vehicles match your filters</h3>
      <p>Try different dates, clear a filter, or check availability for a different travel window.</p>
      <a class="btn btn-navy" href="/vehicles.php">Clear Filters</a>
    </div>
  <?php else: ?>
    <div class="vehicle-grid">
      <?php foreach ($vehicles as $vehicle):
        $days = $validDates ? rental_days($start, $end) : null;
        $image = vehicle_image($vehicle['image']);
      ?>
        <article class="vehicle-card">
          <div class="vehicle-image" style="background-image:url('<?= e($image) ?>')"></div>
          <div class="vehicle-body">
            <div class="vehicle-meta">
              <span class="tag"><?= e($vehicle['category']) ?></span>
              <span class="status-badge status-success">Available</span>
            </div>
            <h3><?= e($vehicle['brand']) ?> <?= e($vehicle['model']) ?></h3>
            <p class="specs"><?= e($vehicle['transmission']) ?> · <?= (int)$vehicle['seats'] ?> seats</p>
            <div class="price-row">
              <strong>₱<?= number_format((float)$vehicle['daily_rate']) ?></strong>
              <span>/ day</span>
            </div>
            <?php if ($days): ?>
              <p class="estimate">Estimated total: ₱<?= number_format((float)$vehicle['daily_rate'] * $days) ?> for <?= $days ?> day(s)</p>
            <?php endif; ?>
            <div class="vehicle-actions">
              <span class="vehicle-availability"><?= $validDates ? 'Available for your dates' : 'Select dates to check availability' ?></span>
              <a class="btn btn-navy" href="/vehicle.php?id=<?= (int)$vehicle['id'] ?>&start=<?= e($start) ?>&end=<?= e($end) ?>">View Details</a>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if ($totalPages > 1): ?>
    <nav class="pagination" aria-label="Vehicle catalog pages">
      <?php for ($number = 1; $number <= $totalPages; $number++): ?>
        <a class="<?= $number === $page ? 'active' : '' ?>" href="?<?= e(http_build_query(['category' => $cat, 'transmission' => $trans, 'max_price' => $_GET['max_price'] ?? '', 'start' => $start, 'end' => $end, 'page' => $number])) ?>"><?= $number ?></a>
      <?php endfor; ?>
    </nav>
  <?php endif; ?>
  </section>
  <?php if ($customerView): ?></div></div><?php endif; ?>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
