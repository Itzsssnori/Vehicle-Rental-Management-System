<?php
$title = 'My Rentals';
require_once __DIR__ . '/../../config.php';
$user = require_customer();
$perPage = 4;
$page = max(1, (int)($_GET['page'] ?? 1));
$countStmt = db()->prepare('SELECT COUNT(*) FROM rentals WHERE user_id = ?');
$countStmt->execute([$user['id']]);
$totalRentals = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRentals / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$stmt = db()->prepare(
    "SELECT r.id, r.start_date, r.end_date, r.actual_return, r.total_amount, r.status, r.payment_method,
            v.brand, v.model, v.category, v.image,
            COALESCE(SUM(p.amount), 0) AS paid_amount
     FROM rentals r
     INNER JOIN vehicles v ON v.id = r.vehicle_id
     LEFT JOIN payments p ON p.rental_id = r.id
     WHERE r.user_id = ?
     GROUP BY r.id, v.id
     ORDER BY r.created_at DESC, r.start_date DESC
     LIMIT {$perPage} OFFSET {$offset}"
);
$stmt->execute([$user['id']]);
$rentals = $stmt->fetchAll();
include __DIR__ . '/../../includes/header.php';
?>
<main class="dashboard-page">
  <div class="dashboard-layout">
    <?php include __DIR__ . '/../../includes/customer-sidebar.php'; ?>
    <div class="dashboard-content">
      <section class="dashboard-hero">
        <div>
          <p class="eyebrow">Customer dashboard</p>
          <h1>My rentals</h1>
          <p>View every request and transaction, including approved, rejected, cancelled, and completed rentals.</p>
        </div>
        <a class="btn btn-coral" href="/vehicles.php">Find a vehicle</a>
      </section>
      <section class="dashboard-section rentals-history-section">
        <div class="section-head">
          <div><p class="eyebrow">Complete history</p><h2>All transactions</h2></div>
          <span class="history-count"><?= $totalRentals ?> record<?= $totalRentals === 1 ? '' : 's' ?></span>
        </div>
        <?php if (!$rentals): ?>
          <div class="empty-state"><h3>No rentals yet</h3><p>Your rental requests and transaction history will appear here.</p><a class="btn btn-navy" href="/vehicles.php">Browse Vehicles</a></div>
        <?php else: ?>
          <div class="rental-list">
            <?php foreach ($rentals as $rental):
              $statusClass = in_array($rental['status'], ['confirmed', 'ongoing', 'completed'], true) ? 'status-success' : (in_array($rental['status'], ['cancelled', 'rejected'], true) ? 'status-danger' : 'status-neutral');
              $paymentStatus = (float)$rental['paid_amount'] >= (float)$rental['total_amount'] ? 'Paid' : 'Payment pending';
            ?>
              <article class="rental-row history-row">
                <div class="rental-main">
                  <div class="history-vehicle">
                    <div class="history-image" style="background-image:url('<?= e(vehicle_image($rental['image'])) ?>')"></div>
                    <div><span class="tag"><?= e($rental['category']) ?></span><h3><?= e($rental['brand']) ?> <?= e($rental['model']) ?></h3><p><?= e(date('M j, Y', strtotime($rental['start_date']))) ?> – <?= e(date('M j, Y', strtotime($rental['end_date']))) ?></p></div>
                  </div>
                  <div class="rental-statuses"><span class="status-badge <?= $statusClass ?>"><?= e(ucfirst($rental['status'])) ?></span><span class="status-badge <?= $paymentStatus === 'Paid' ? 'status-success' : 'status-neutral' ?>"><?= e($paymentStatus) ?></span></div>
                </div>
                <?php $days = rental_days($rental['start_date'], $rental['end_date']); ?>
                <div class="rental-meta"><span><?= $days ?> day(s) × ₱<?= number_format((float)$rental['total_amount'] / $days) ?> / day</span><strong>₱<?= number_format((float)$rental['total_amount']) ?></strong><span>Total amount · Booking #<?= (int)$rental['id'] ?></span><a class="text-link rental-detail-link" href="/customer/rental.php?id=<?= (int)$rental['id'] ?>">View details</a></div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php if ($totalPages > 1): ?>
          <nav class="pagination" aria-label="Rental history pages">
            <?php for ($number = 1; $number <= $totalPages; $number++): ?>
              <a class="<?= $number === $page ? 'active' : '' ?>" href="?page=<?= $number ?>"><?= $number ?></a>
            <?php endfor; ?>
          </nav>
        <?php endif; ?>
      </section>
    </div>
  </div>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
