<?php
$title = 'Reports';
require_once __DIR__ . '/../../config.php';
require_role('admin');
$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$overview = db()->query("SELECT COUNT(*) AS rentals, COALESCE(SUM(total_amount),0) AS value, SUM(status IN ('confirmed','ongoing')) AS active FROM rentals")->fetch();
$paid = (float)db()->query('SELECT COALESCE(SUM(amount),0) FROM payments')->fetchColumn();
$totalVehicles = (int)db()->query('SELECT COUNT(*) FROM vehicles')->fetchColumn();
$totalPages = max(1, (int)ceil($totalVehicles / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$popular = db()->query("SELECT v.brand, v.model, COUNT(r.id) AS rentals, COALESCE(SUM(r.total_amount),0) AS revenue FROM vehicles v LEFT JOIN rentals r ON r.vehicle_id = v.id GROUP BY v.id ORDER BY rentals DESC, revenue DESC LIMIT {$perPage} OFFSET {$offset}")->fetchAll();
include __DIR__ . '/../../includes/header.php';
?>
<main class="admin-page">
  <div class="admin-heading"><div><p class="eyebrow">Administration</p><h1>Reports</h1><p>Review rental activity and fleet performance.</p></div><a class="btn btn-navy" href="/admin/admin-dashboard.php">Back to workspace</a></div>
  <section class="dashboard-stats"><article class="summary-card"><span class="summary-label">Rental value</span><strong>₱<?= number_format((float)$overview['value']) ?></strong><span>All recorded rentals</span></article><article class="summary-card"><span class="summary-label">Payments received</span><strong>₱<?= number_format($paid) ?></strong><span>Recorded payments</span></article><article class="summary-card"><span class="summary-label">Active rentals</span><strong><?= (int)$overview['active'] ?></strong><span>Confirmed or ongoing</span></article></section>
  <section class="admin-card"><p class="eyebrow">Fleet performance</p><h2>Most requested vehicles</h2><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Vehicle</th><th>Rentals</th><th>Rental value</th></tr></thead><tbody><?php foreach ($popular as $vehicle): ?><tr><td><?= e($vehicle['brand'] . ' ' . $vehicle['model']) ?></td><td><?= (int)$vehicle['rentals'] ?></td><td>₱<?= number_format((float)$vehicle['revenue']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php if ($totalPages > 1): ?><nav class="pagination" aria-label="Fleet report pages"><?php for ($number = 1; $number <= $totalPages; $number++): ?><a class="<?= $number === $page ? 'active' : '' ?>" href="?page=<?= $number ?>"><?= $number ?></a><?php endfor; ?></nav><?php endif; ?></section>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
