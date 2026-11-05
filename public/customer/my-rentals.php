<?php
$title = 'My Rentals & Bookings';
require_once __DIR__ . '/../../config.php';
$user = require_customer();
$pdo = db();

// Filter tab logic
$tab = $_GET['tab'] ?? 'all';
$q = trim($_GET['q'] ?? '');
$perPage = 6;
$page = max(1, (int)($_GET['page'] ?? 1));

// Tab counts
$countAllStmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE customer_id = ?");
$countAllStmt->execute([$user['id']]);
$allCount = (int)$countAllStmt->fetchColumn();

$countActiveStmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE customer_id = ? AND status IN ('confirmed', 'ongoing')");
$countActiveStmt->execute([$user['id']]);
$activeCount = (int)$countActiveStmt->fetchColumn();

$countPendingStmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE customer_id = ? AND status = 'pending'");
$countPendingStmt->execute([$user['id']]);
$pendingCount = (int)$countPendingStmt->fetchColumn();

$countCompletedStmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE customer_id = ? AND status = 'completed'");
$countCompletedStmt->execute([$user['id']]);
$completedCount = (int)$countCompletedStmt->fetchColumn();

$countCancelledStmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE customer_id = ? AND status IN ('cancelled', 'rejected')");
$countCancelledStmt->execute([$user['id']]);
$cancelledCount = (int)$countCancelledStmt->fetchColumn();

// Build query
$where = ['r.customer_id = ?'];
$params = [$user['id']];

if ($tab === 'active') {
    $where[] = "r.status IN ('confirmed', 'ongoing')";
} elseif ($tab === 'pending') {
    $where[] = "r.status = 'pending'";
} elseif ($tab === 'completed') {
    $where[] = "r.status = 'completed'";
} elseif ($tab === 'cancelled') {
    $where[] = "r.status IN ('cancelled', 'rejected')";
}

if ($q !== '') {
    $where[] = "(r.reference_number LIKE ? OR v.brand LIKE ? OR v.model LIKE ?)";
    $wildcard = '%' . $q . '%';
    $params[] = $wildcard;
    $params[] = $wildcard;
    $params[] = $wildcard;
}

$whereSql = implode(' AND ', $where);

// Total matching
$totalStmt = $pdo->prepare(
    "SELECT COUNT(DISTINCT r.id)
     FROM reservations r
     INNER JOIN vehicles v ON v.id = r.vehicle_id
     WHERE {$whereSql}"
);
$totalStmt->execute($params);
$totalRecords = (int)$totalStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRecords / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// Paginated query
$stmt = $pdo->prepare(
    "SELECT r.id, r.reference_number, r.requested_pickup_at AS start_date, r.requested_return_at AS end_date,
            r.base_amount, r.total_amount, r.status, r.payment_method, r.created_at,
            v.id AS vehicle_id, v.brand, v.model, c.name AS category, v.image, v.seats, v.transmission,
            pl.name AS pickup_location, rl.name AS return_location,
            rt.id AS rental_id, rt.status AS rental_status,
            COALESCE(SUM(p.amount), 0) AS paid_amount
     FROM reservations r
     INNER JOIN vehicles v ON v.id = r.vehicle_id
     INNER JOIN vehicle_categories c ON c.id = v.category_id
     LEFT JOIN locations pl ON pl.id = r.pickup_location_id
     LEFT JOIN locations rl ON rl.id = r.return_location_id
     LEFT JOIN rentals rt ON rt.reservation_id = r.id
     LEFT JOIN payments p ON p.reservation_id = r.id OR (rt.id IS NOT NULL AND p.rental_id = rt.id)
     WHERE {$whereSql}
     GROUP BY r.id, rt.id, v.id, c.id, pl.id, rl.id
     ORDER BY r.created_at DESC
     LIMIT ? OFFSET ?"
);

$bindIndex = 1;
foreach ($params as $param) {
    $stmt->bindValue($bindIndex++, $param);
}
$stmt->bindValue($bindIndex++, $perPage, PDO::PARAM_INT);
$stmt->bindValue($bindIndex++, $offset, PDO::PARAM_INT);
$stmt->execute();
$rentals = $stmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<main class="portal-customer-page">
  <div class="portal-customer-layout">
    <?php include __DIR__ . '/../../includes/customer-sidebar.php'; ?>

    <div class="customer-main-content">
      <!-- Page Hero Header -->
      <section class="portal-hero-banner">
        <div class="hero-greeting-content">
          <span class="eyebrow">Customer Workspace</span>
          <h1>My Rentals & Bookings</h1>
          <p>Review active vehicle dispatches, upcoming reservations, and historical trip logs.</p>
        </div>
        <a class="portal-btn-primary" href="/vehicles.php" style="white-space:nowrap;">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/></svg>
          <span>Reserve Another Car</span>
        </a>
      </section>

      <!-- Filter Tabs & Search Bar -->
      <div style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:8px;">
        <!-- Tabs -->
        <nav class="portal-filter-tabs" aria-label="Filter bookings by status" style="margin-bottom:0;">
          <a href="?tab=all<?= $q ? '&q=' . urlencode($q) : '' ?>" class="filter-tab-btn <?= $tab === 'all' ? 'active' : '' ?>">
            <span>All Bookings</span>
            <span class="filter-tab-count"><?= $allCount ?></span>
          </a>
          <a href="?tab=active<?= $q ? '&q=' . urlencode($q) : '' ?>" class="filter-tab-btn <?= $tab === 'active' ? 'active' : '' ?>">
            <span>Active & Ongoing</span>
            <span class="filter-tab-count"><?= $activeCount ?></span>
          </a>
          <a href="?tab=pending<?= $q ? '&q=' . urlencode($q) : '' ?>" class="filter-tab-btn <?= $tab === 'pending' ? 'active' : '' ?>">
            <span>Pending Review</span>
            <span class="filter-tab-count"><?= $pendingCount ?></span>
          </a>
          <a href="?tab=completed<?= $q ? '&q=' . urlencode($q) : '' ?>" class="filter-tab-btn <?= $tab === 'completed' ? 'active' : '' ?>">
            <span>Completed</span>
            <span class="filter-tab-count"><?= $completedCount ?></span>
          </a>
          <a href="?tab=cancelled<?= $q ? '&q=' . urlencode($q) : '' ?>" class="filter-tab-btn <?= $tab === 'cancelled' ? 'active' : '' ?>">
            <span>Cancelled</span>
            <span class="filter-tab-count"><?= $cancelledCount ?></span>
          </a>
        </nav>

        <!-- Search Form -->
        <form method="get" style="display:flex;align-items:center;gap:8px;">
          <input type="hidden" name="tab" value="<?= e($tab) ?>">
          <div style="position:relative;display:flex;align-items:center;">
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search by model or ref..." style="height:38px;padding:0 34px 0 14px;border-radius:999px;border:1.5px solid #cbd5e1;font-size:0.84rem;width:220px;background:#ffffff;">
            <span style="position:absolute;right:12px;color:#94a3b8;pointer-events:none;" aria-hidden="true">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            </span>
          </div>
          <?php if ($q): ?>
            <a href="?tab=<?= e($tab) ?>" class="portal-btn-outline" style="height:38px;padding:0 12px;border-radius:999px;font-size:0.82rem;">Clear</a>
          <?php endif; ?>
        </form>
      </div>

      <!-- Bookings Cards Grid -->
      <?php if (empty($rentals)): ?>
        <div class="portal-empty-card" style="margin-top:16px;">
          <span class="portal-empty-icon" aria-hidden="true">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          </span>
          <h3>No Reservations Found</h3>
          <p>
            <?= $q ? 'No bookings matched your search query. Try searching with different keywords.' : ($tab === 'all' ? 'You have no vehicle reservations on file yet.' : 'There are no reservations matching the "' . ucfirst($tab) . '" category.') ?>
          </p>
          <?php if ($q): ?>
            <a href="?tab=<?= e($tab) ?>" class="portal-btn-outline" style="margin-top:6px;">Reset Search</a>
          <?php else: ?>
            <a href="/vehicles.php" class="portal-btn-primary" style="margin-top:6px;">Browse Vehicle Fleet</a>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="customer-bookings-grid">
          <?php foreach ($rentals as $rental):
            $resBal = rental_balance($pdo, (int)$rental['id'], $rental['rental_id'] ? (int)$rental['rental_id'] : null);
            $resPaid = $resBal['outstanding'] <= 0.0001;
            $days = rental_days($rental['start_date'], $rental['end_date']);
            $isOnline = in_array($rental['payment_method'], ['online', 'paymongo', 'paypal'], true);
            $bookingBadgeClass = match($rental['status']) {
              'confirmed', 'ongoing', 'completed' => 'badge-success',
              'cancelled', 'rejected' => 'badge-danger',
              default => 'badge-neutral',
            };
          ?>
            <article class="customer-booking-card">
              <div class="booking-card-media">
                <img src="<?= e(vehicle_image($rental['image'])) ?>" alt="<?= e($rental['brand'] . ' ' . $rental['model']) ?>" class="booking-card-img" loading="lazy">
                <span class="spotlight-category-tag"><?= e($rental['category']) ?></span>
              </div>

              <div class="booking-card-header">
                <h3 class="booking-card-title"><?= e($rental['brand'] . ' ' . $rental['model']) ?></h3>
                <div style="display:flex;justify-content:space-between;align-items:center;">
                  <span class="booking-card-ref">Ref: <?= e($rental['reference_number']) ?></span>
                  <span style="font-size:0.75rem;color:#64748b;"><?= e(date('M j, Y', strtotime($rental['created_at']))) ?></span>
                </div>
                <div class="booking-card-badges">
                  <span class="portal-badge <?= $bookingBadgeClass ?>"><?= e(ucfirst($rental['status'])) ?></span>
                  <span class="portal-badge <?= $resPaid ? 'badge-success' : ($rental['status'] === 'confirmed' ? 'badge-warning' : 'badge-neutral') ?>">
                    <?= $resPaid ? 'Paid in Full' : 'Payment Due' ?>
                  </span>
                  <span class="portal-badge badge-neutral">
                    <?= e(ucwords(str_replace('_', ' ', $rental['payment_method']))) ?>
                  </span>
                </div>
              </div>

              <div class="booking-card-body">
                <div class="booking-itinerary-box">
                  <div class="itinerary-step">
                    <span class="itinerary-dot" aria-hidden="true"></span>
                    <div class="itinerary-text">
                      <strong>Pickup: <?= e(date('M j, Y · g:i A', strtotime($rental['start_date']))) ?></strong>
                      <span><?= e($rental['pickup_location'] ?: 'Main Operations Hub') ?></span>
                    </div>
                  </div>
                  <div class="itinerary-step">
                    <span class="itinerary-dot dot-return" aria-hidden="true"></span>
                    <div class="itinerary-text">
                      <strong>Return: <?= e(date('M j, Y · g:i A', strtotime($rental['end_date']))) ?></strong>
                      <span><?= e($rental['return_location'] ?: 'Main Operations Hub') ?> (<?= $days ?> day<?= $days === 1 ? '' : 's' ?>)</span>
                    </div>
                  </div>
                </div>

                <div class="booking-financial-row">
                  <div>
                    <span>Total Cost</span>
                    <span style="font-size:0.75rem;color:#94a3b8;display:block;"><?= $days ?>d × ₱<?= number_format((float)$rental['total_amount'] / max(1, $days)) ?></span>
                  </div>
                  <strong>₱<?= number_format((float)$rental['total_amount'], 2) ?></strong>
                </div>
              </div>

              <div class="booking-card-footer">
                <?php if ($rental['status'] === 'confirmed' && !$resPaid && $isOnline): ?>
                  <a href="/online-checkout.php?reservation_id=<?= (int)$rental['id'] ?>" class="portal-btn-primary" style="flex:1;height:38px;padding:0 12px;font-size:0.84rem;justify-content:center;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                    <span>Pay Online</span>
                  </a>
                <?php elseif ($resPaid): ?>
                  <a href="/payment-return.php?provider=<?= e($rental['payment_method']) ?>&reservation=<?= (int)$rental['id'] ?>" class="portal-btn-outline" style="flex:1;height:38px;padding:0 12px;font-size:0.84rem;justify-content:center;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    <span>Receipt</span>
                  </a>
                <?php endif; ?>

                <a href="/customer/rental.php?id=<?= (int)$rental['id'] ?>" class="portal-btn-outline" style="flex:1;height:38px;padding:0 12px;font-size:0.84rem;justify-content:center;">
                  <span>Trip Details</span>
                </a>
              </div>
            </article>
          <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
          <nav class="portal-pagination" aria-label="Bookings pagination" style="display:flex;justify-content:center;gap:8px;margin-top:32px;">
            <?php for ($num = 1; $num <= $totalPages; $num++): ?>
              <a href="?page=<?= $num ?>&tab=<?= e($tab) ?><?= $q ? '&q=' . urlencode($q) : '' ?>" class="portal-btn-outline <?= $num === $page ? 'active' : '' ?>" style="<?= $num === $page ? 'background:#0f172a;color:#ffffff;border-color:#0f172a;' : '' ?>min-width:40px;height:40px;justify-content:center;padding:0;">
                <?= $num ?>
              </a>
            <?php endfor; ?>
          </nav>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
