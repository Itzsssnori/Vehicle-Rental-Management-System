<?php
$title = 'Reports';
require_once __DIR__ . '/../../config.php';
require_role('admin');
$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$overview = db()->query(
    "SELECT
        (SELECT COUNT(*) FROM reservations) AS reservations,
        (SELECT COUNT(*) FROM rentals WHERE status IN ('ongoing','returned')) AS active_rentals,
        (SELECT COALESCE(SUM(amount), 0) FROM rental_charges) AS charge_value"
)->fetch();
$paid = (float)db()->query('SELECT COALESCE(SUM(amount),0) FROM payments')->fetchColumn();
$refunded = (float)db()->query('SELECT COALESCE(SUM(amount),0) FROM refunds')->fetchColumn();
$reportCounts = db()->query(
    "SELECT
      (SELECT COUNT(*) FROM reservations WHERE status = 'no_show') AS no_shows,
      (SELECT COUNT(*) FROM penalty_events WHERE status = 'approved' AND (expires_at IS NULL OR expires_at >= CURRENT_DATE)) AS active_penalties,
      (SELECT COUNT(*) FROM rental_incidents WHERE status IN ('open','contact_attempted','escalated')) AS open_incidents,
      (SELECT COUNT(*) FROM rentals WHERE status = 'completed') AS completed_rentals"
)->fetch();
$totalVehicles = (int)db()->query('SELECT COUNT(*) FROM vehicles')->fetchColumn();
$totalPages = max(1, (int)ceil($totalVehicles / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$popular = db()->query(
    "SELECT v.brand, v.model,
            COUNT(res.id) AS rentals,
            COALESCE(SUM(res.total_amount), 0) AS revenue
     FROM vehicles v
     LEFT JOIN reservations res ON res.vehicle_id = v.id
     GROUP BY v.id, v.brand, v.model
     ORDER BY rentals DESC, revenue DESC
     LIMIT {$perPage} OFFSET {$offset}"
)->fetchAll();
include __DIR__ . '/../../includes/header.php';
?>
<main class="admin-page">
  <div class="admin-heading"><div><p class="eyebrow">Administration</p><h1>Reports</h1><p>Review rental activity and fleet performance.</p></div><a class="btn btn-navy" href="/admin/admin-dashboard.php">Back to workspace</a></div>
  <section class="dashboard-stats"><article class="summary-card"><span class="summary-label">Reservations</span><strong><?= (int)$overview['reservations'] ?></strong><span>All submitted requests</span></article><article class="summary-card"><span class="summary-label">Charge value</span><strong>₱<?= number_format((float)$overview['charge_value']) ?></strong><span>Recorded charge lines</span></article><article class="summary-card"><span class="summary-label">Payments received</span><strong>₱<?= number_format($paid) ?></strong><span>Recorded payments</span></article><article class="summary-card"><span class="summary-label">Refunds</span><strong>₱<?= number_format($refunded) ?></strong><span>Recorded refunds</span></article><article class="summary-card"><span class="summary-label">Active rentals</span><strong><?= (int)$overview['active_rentals'] ?></strong><span>Ongoing or returned</span></article></section>
  <section class="dashboard-stats"><article class="summary-card"><span class="summary-label">Completed rentals</span><strong><?= (int)$reportCounts['completed_rentals'] ?></strong></article><article class="summary-card"><span class="summary-label">No-shows</span><strong><?= (int)$reportCounts['no_shows'] ?></strong></article><article class="summary-card"><span class="summary-label">Active penalties</span><strong><?= (int)$reportCounts['active_penalties'] ?></strong></article><article class="summary-card"><span class="summary-label">Open incidents</span><strong><?= (int)$reportCounts['open_incidents'] ?></strong></article></section>
  <section class="admin-card"><p class="eyebrow">Fleet performance</p><h2>Most requested vehicles</h2><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Vehicle</th><th>Rentals</th><th>Rental value</th></tr></thead><tbody><?php foreach ($popular as $vehicle): ?><tr><td><?= e($vehicle['brand'] . ' ' . $vehicle['model']) ?></td><td><?= (int)$vehicle['rentals'] ?></td><td>₱<?= number_format((float)$vehicle['revenue']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php if ($totalPages > 1): ?><nav class="pagination" aria-label="Fleet report pages"><?php for ($number = 1; $number <= $totalPages; $number++): ?><a class="<?= $number === $page ? 'active' : '' ?>" href="?page=<?= $number ?>"><?= $number ?></a><?php endfor; ?></nav><?php endif; ?></section>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
