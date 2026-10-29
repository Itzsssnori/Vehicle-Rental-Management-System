<?php
$title = 'Rental Oversight';
require_once __DIR__ . '/../../config.php';
require_role('admin');

$message = '';
$error = '';
$status = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');
$perPage = 8;
$page = max(1, (int)($_GET['page'] ?? 1));
$allowedStatuses = ['pending', 'confirmed', 'ongoing', 'completed', 'cancelled', 'rejected'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $rentalId = filter_input(INPUT_POST, 'rental_id', FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';

    if (!$rentalId || !in_array($action, ['approve', 'reject'], true)) {
        $error = 'Invalid rental request action.';
    } else {
        $pdo = null;
        try {
            $pdo = db();
            $pdo->beginTransaction();
            $rentalStmt = $pdo->prepare(
                "SELECT r.id, r.vehicle_id, r.start_date, r.end_date, r.status,
                        v.status AS vehicle_status
                 FROM rentals r
                 INNER JOIN vehicles v ON v.id = r.vehicle_id
                 WHERE r.id = ?
                 FOR UPDATE"
            );
            $rentalStmt->execute([$rentalId]);
            $rental = $rentalStmt->fetch();

            if (!$rental || $rental['status'] !== 'pending') {
                throw new RuntimeException('Only pending rental requests can be reviewed.');
            }

            if ($action === 'reject') {
                $reason = trim($_POST['reason'] ?? '');
                if ($reason === '') {
                    throw new RuntimeException('Enter a reason when rejecting a rental request.');
                }
                $update = $pdo->prepare("UPDATE rentals SET status = 'rejected', rejection_reason = ? WHERE id = ? AND status = 'pending'");
                $update->execute([$reason, $rentalId]);
                $message = 'Rental request rejected.';
            } else {
                if ($rental['vehicle_status'] !== 'operational') {
                    throw new RuntimeException('This vehicle is not operational and cannot be approved.');
                }

                $conflict = $pdo->prepare(
                    "SELECT COUNT(*) FROM rentals
                     WHERE vehicle_id = ?
                       AND id <> ?
                       AND status IN ('confirmed', 'ongoing')
                       AND start_date <= ?
                       AND end_date >= ?"
                );
                $conflict->execute([$rental['vehicle_id'], $rentalId, $rental['end_date'], $rental['start_date']]);

                $maintenance = $pdo->prepare(
                    "SELECT COUNT(*) FROM maintenance
                     WHERE vehicle_id = ?
                       AND start_date <= ?
                       AND (end_date IS NULL OR end_date >= ?)"
                );
                $maintenance->execute([$rental['vehicle_id'], $rental['end_date'], $rental['start_date']]);

                if ((int)$conflict->fetchColumn() > 0) {
                    throw new RuntimeException('This request overlaps another confirmed or ongoing rental.');
                }
                if ((int)$maintenance->fetchColumn() > 0) {
                    throw new RuntimeException('This vehicle is scheduled for maintenance during the requested dates.');
                }

                $update = $pdo->prepare("UPDATE rentals SET status = 'confirmed' WHERE id = ? AND status = 'pending'");
                $update->execute([$rentalId]);
                $message = 'Rental request approved.';
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Admin rental action failed: ' . $exception->getMessage());
            $error = 'The action could not be completed. Please review the rental and try again.';
        }
    }
}

$sql = "SELECT r.id, r.start_date, r.end_date, r.actual_return, r.total_amount, r.status, r.created_at,
               u.name AS customer_name, u.email AS customer_email,
               v.brand, v.model, v.category,
               COALESCE(SUM(p.amount), 0) AS paid_amount,
               MAX(p.paid_at) AS last_paid_at
        FROM rentals r
        INNER JOIN users u ON u.id = r.user_id
        INNER JOIN vehicles v ON v.id = r.vehicle_id
        LEFT JOIN payments p ON p.rental_id = r.id
        WHERE 1 = 1";
$params = [];
if (in_array($status, $allowedStatuses, true)) {
    $sql .= ' AND r.status = ?';
    $params[] = $status;
}
if ($search !== '') {
    $sql .= ' AND (u.name LIKE ? OR u.email LIKE ? OR v.brand LIKE ? OR v.model LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term);
}
$sql .= ' GROUP BY r.id, u.id, v.id ORDER BY r.created_at DESC';
$countSql = "SELECT COUNT(DISTINCT r.id)
        FROM rentals r
        INNER JOIN users u ON u.id = r.user_id
        INNER JOIN vehicles v ON v.id = r.vehicle_id
        WHERE 1 = 1";
$countParams = [];
if (in_array($status, $allowedStatuses, true)) {
    $countSql .= ' AND r.status = ?';
    $countParams[] = $status;
}
if ($search !== '') {
    $countSql .= ' AND (u.name LIKE ? OR u.email LIKE ? OR v.brand LIKE ? OR v.model LIKE ?)';
    array_push($countParams, $term, $term, $term, $term);
}
$countStmt = db()->prepare($countSql);
$countStmt->execute($countParams);
$totalRentals = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRentals / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$sql .= " LIMIT {$perPage} OFFSET {$offset}";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rentals = $stmt->fetchAll();

$summary = db()->query(
    "SELECT
      COUNT(*) AS total_rentals,
      SUM(status = 'pending') AS pending_rentals,
      SUM(status IN ('confirmed', 'ongoing')) AS active_rentals,
      COALESCE(SUM(total_amount), 0) AS total_value
     FROM rentals"
)->fetch();
$paid = (float)db()->query('SELECT COALESCE(SUM(amount), 0) FROM payments')->fetchColumn();
$outstanding = max(0, (float)$summary['total_value'] - $paid);
include __DIR__ . '/../../includes/header.php';
?>
<main class="admin-page">
  <div class="admin-heading">
    <div><p class="eyebrow">Administration</p><h1>Rental oversight</h1><p>Review every booking, payment, and outstanding balance.</p></div>
    <a class="btn btn-navy" href="/admin/admin-dashboard.php">Back to workspace</a>
  </div>
  <?php if ($message): ?><p class="note request-success" role="status"><?= e($message) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="form-error" role="alert"><?= e($error) ?></p><?php endif; ?>

  <section class="dashboard-stats admin-stats">
    <article class="summary-card"><span class="summary-label">Total rentals</span><strong><?= (int)$summary['total_rentals'] ?></strong><span>All recorded bookings</span></article>
    <article class="summary-card"><span class="summary-label">Pending requests</span><strong><?= (int)$summary['pending_rentals'] ?></strong><span>Waiting for review</span></article>
    <article class="summary-card"><span class="summary-label">Outstanding balance</span><strong>₱<?= number_format($outstanding) ?></strong><span>Across all rentals</span></article>
  </section>

  <section class="admin-card">
    <form class="filter-bar admin-filter" method="get">
      <label>Search customer or vehicle<input name="search" value="<?= e($search) ?>" placeholder="Name, email, brand, model"></label>
      <label>Status<select name="status"><option value="">All statuses</option><?php foreach ($allowedStatuses as $option): ?><option value="<?= e($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= e(ucfirst($option)) ?></option><?php endforeach; ?></select></label>
      <button class="btn btn-navy" type="submit">Filter rentals</button>
      <a class="text-link" href="/admin/admin-rentals.php">Clear</a>
    </form>

    <div class="admin-table-wrap">
      <table class="admin-table rental-admin-table">
        <thead><tr><th>Rental</th><th>Customer</th><th>Dates</th><th>Status</th><th>Financials</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (!$rentals): ?>
          <tr><td colspan="6">No rentals match the selected filters.</td></tr>
        <?php else: foreach ($rentals as $rental):
          $balance = max(0, (float)$rental['total_amount'] - (float)$rental['paid_amount']);
        ?>
          <tr>
            <td><strong>#<?= (int)$rental['id'] ?> · <?= e($rental['brand'] . ' ' . $rental['model']) ?></strong><small><?= e($rental['category']) ?></small></td>
            <td><strong><?= e($rental['customer_name']) ?></strong><small><?= e($rental['customer_email']) ?></small></td>
            <td><?= e(date('M j, Y', strtotime($rental['start_date']))) ?><small>to <?= e(date('M j, Y', strtotime($rental['end_date']))) ?></small></td>
            <td><span class="status-badge <?= in_array($rental['status'], ['confirmed', 'ongoing', 'completed'], true) ? 'status-success' : 'status-neutral' ?>"><?= e(ucfirst($rental['status'])) ?></span></td>
            <?php $days = rental_days($rental['start_date'], $rental['end_date']); ?>
            <td><strong>₱<?= number_format((float)$rental['total_amount']) ?></strong><small><?= $days ?> day(s) · ₱<?= number_format((float)$rental['total_amount'] / $days) ?>/day</small><small>Paid ₱<?= number_format((float)$rental['paid_amount']) ?> · Balance ₱<?= number_format($balance) ?></small></td>
            <td class="admin-rental-actions">
              <?php if ($rental['status'] === 'pending'): ?>
                <div class="request-actions">
                  <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="rental_id" value="<?= (int)$rental['id'] ?>">
                    <input type="hidden" name="action" value="approve">
                    <button class="btn btn-coral" type="submit">Approve</button>
                  </form>
                  <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="rental_id" value="<?= (int)$rental['id'] ?>">
                    <input type="hidden" name="action" value="reject">
                    <input name="reason" placeholder="Reason" required>
                    <button class="btn btn-navy" type="submit">Reject</button>
                  </form>
                </div>
              <?php else: ?>
                <span class="muted-copy">No action needed</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($totalPages > 1): ?><nav class="pagination" aria-label="Rental oversight pages"><?php for ($number = 1; $number <= $totalPages; $number++): ?><a class="<?= $number === $page ? 'active' : '' ?>" href="?<?= e(http_build_query(['status' => $status, 'search' => $search, 'page' => $number])) ?>"><?= $number ?></a><?php endfor; ?></nav><?php endif; ?>
  </section>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
