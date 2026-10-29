<?php
$title = 'Staff Workspace';
require_once __DIR__ . '/../../config.php';
$user = require_role('staff');
$message = '';
$error = '';
$statusFilter = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');
$staffSection = in_array($_GET['section'] ?? '', ['requests', 'operations'], true) ? $_GET['section'] : 'overview';
$perPage = 5;
$page = max(1, (int)($_GET['page'] ?? 1));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $rentalId = filter_input(INPUT_POST, 'rental_id', FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';

    if (!$rentalId || !in_array($action, ['approve', 'reject', 'approve_cancel', 'reject_cancel', 'start', 'complete', 'payment'], true)) {
        $error = 'Invalid rental request action.';
    } else {
        try {
            $pdo = db();
            $pdo->beginTransaction();

            $rentalStmt = $pdo->prepare(
                "SELECT r.id, r.vehicle_id, r.start_date, r.end_date, r.total_amount, r.status,
                        r.cancellation_requested,
                        v.status AS vehicle_status
                 FROM rentals r
                 INNER JOIN vehicles v ON v.id = r.vehicle_id
                 WHERE r.id = ?
                 FOR UPDATE"
            );
            $rentalStmt->execute([$rentalId]);
            $rental = $rentalStmt->fetch();

            if (!$rental) {
                throw new RuntimeException('Rental request not found.');
            }

            if ($action === 'approve_cancel' || $action === 'reject_cancel') {
                if (!$rental['cancellation_requested']) {
                    throw new RuntimeException('This rental has no pending cancellation request.');
                }
                $reason = trim($_POST['reason'] ?? '');
                if ($action === 'reject_cancel' && $reason === '') {
                    throw new RuntimeException('Enter a reason when rejecting a cancellation request.');
                }
                if ($action === 'approve_cancel') {
                    $update = $pdo->prepare("UPDATE rentals SET status = 'cancelled', cancellation_requested = 0 WHERE id = ? AND cancellation_requested = 1");
                    $update->execute([$rentalId]);
                    $message = 'Cancellation request approved.';
                } else {
                    $update = $pdo->prepare("UPDATE rentals SET cancellation_requested = 0, cancellation_reason = ? WHERE id = ? AND cancellation_requested = 1");
                    $update->execute([$reason, $rentalId]);
                    $message = 'Cancellation request rejected.';
                }
            } elseif ($action === 'reject') {
                if ($rental['status'] !== 'pending') {
                    throw new RuntimeException('Only pending rental requests can be rejected.');
                }
                $reason = trim($_POST['reason'] ?? '');
                if ($reason === '') {
                    throw new RuntimeException('Enter a reason when rejecting a rental request.');
                }
                $update = $pdo->prepare("UPDATE rentals SET status = 'rejected', rejection_reason = ? WHERE id = ? AND status = 'pending'");
                $update->execute([$reason, $rentalId]);
                $message = 'Rental request rejected.';
            } elseif ($action === 'approve') {
                if ($rental['status'] !== 'pending') {
                    throw new RuntimeException('Only pending rental requests can be approved.');
                }
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
            } elseif ($action === 'start') {
                if ($rental['status'] !== 'confirmed') {
                    throw new RuntimeException('Only confirmed rentals can be started.');
                }
                if ($rental['start_date'] > date('Y-m-d')) {
                    throw new RuntimeException('This rental cannot start before the pickup date.');
                }
                $update = $pdo->prepare("UPDATE rentals SET status = 'ongoing' WHERE id = ? AND status = 'confirmed'");
                $update->execute([$rentalId]);
                $message = 'Rental marked as ongoing.';
            } elseif ($action === 'complete') {
                if ($rental['status'] !== 'ongoing') {
                    throw new RuntimeException('Only ongoing rentals can be completed.');
                }
                $update = $pdo->prepare("UPDATE rentals SET status = 'completed', actual_return = ? WHERE id = ? AND status = 'ongoing'");
                $update->execute([date('Y-m-d'), $rentalId]);
                $message = 'Rental marked as completed.';
            } else {
                if (!in_array($rental['status'], ['confirmed', 'ongoing', 'completed'], true)) {
                    throw new RuntimeException('Payments can only be recorded for an active or completed rental.');
                }
                $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
                $method = $_POST['method'] ?? '';
                if ($amount === false || $amount <= 0 || !in_array($method, ['cash', 'gcash', 'card'], true)) {
                    throw new RuntimeException('Enter a valid payment amount and method.');
                }
                $paidStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE rental_id = ?');
                $paidStmt->execute([$rentalId]);
                $remaining = (float)$rental['total_amount'] - (float)$paidStmt->fetchColumn();
                if ($amount > $remaining + 0.0001) {
                    throw new RuntimeException('Payment cannot be greater than the remaining balance.');
                }
                $payment = $pdo->prepare('INSERT INTO payments (rental_id, amount, method, recorded_by) VALUES (?, ?, ?, ?)');
                $payment->execute([$rentalId, $amount, $method, $user['id']]);
                $message = 'Payment recorded.';
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Staff rental action failed: ' . $exception->getMessage());
            $error = 'The action could not be completed. Please review the rental and try again.';
        }
    }
}

$pending = (int)db()->query("SELECT COUNT(*) FROM rentals WHERE status = 'pending'")->fetchColumn();
$active = (int)db()->query("SELECT COUNT(*) FROM rentals WHERE status IN ('confirmed', 'ongoing')")->fetchColumn();
$maintenance = (int)db()->query("SELECT COUNT(*) FROM vehicles WHERE status = 'maintenance'")->fetchColumn();

$sql = "SELECT r.id, r.start_date, r.end_date, r.total_amount, r.payment_method, r.status,
            r.purpose, r.rejection_reason, r.cancellation_reason, r.cancellation_requested,
            u.name AS customer_name, u.email AS customer_email,
            v.brand, v.model, v.category,
            COALESCE(SUM(p.amount), 0) AS paid_amount
     FROM rentals r
     INNER JOIN users u ON u.id = r.user_id
     INNER JOIN vehicles v ON v.id = r.vehicle_id
     LEFT JOIN payments p ON p.rental_id = r.id
     WHERE 1 = 1";
$params = [];
$allowedStatuses = ['pending', 'confirmed', 'ongoing', 'completed', 'cancelled', 'rejected'];
if (in_array($statusFilter, $allowedStatuses, true)) {
    $sql .= ' AND r.status = ?';
    $params[] = $statusFilter;
}
if ($search !== '') {
    $sql .= ' AND (u.name LIKE ? OR u.email LIKE ? OR v.brand LIKE ? OR v.model LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term, $term);
}
$sql .= " GROUP BY r.id, u.id, v.id
          ORDER BY CASE WHEN r.status = 'pending' THEN 0 WHEN r.status IN ('confirmed', 'ongoing') THEN 1 ELSE 2 END,
                   r.start_date ASC, r.created_at DESC";
$countSql = "SELECT COUNT(DISTINCT r.id)
     FROM rentals r
     INNER JOIN users u ON u.id = r.user_id
     INNER JOIN vehicles v ON v.id = r.vehicle_id
     WHERE 1 = 1";
$countParams = [];
if (in_array($statusFilter, $allowedStatuses, true)) {
    $countSql .= ' AND r.status = ?';
    $countParams[] = $statusFilter;
}
if ($search !== '') {
    $countSql .= ' AND (u.name LIKE ? OR u.email LIKE ? OR v.brand LIKE ? OR v.model LIKE ?)';
    array_push($countParams, $term, $term, $term, $term);
}
$countStmt = db()->prepare($countSql);
$countStmt->execute($countParams);
$totalRequests = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRequests / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$sql .= " LIMIT {$perPage} OFFSET {$offset}";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

$today = date('Y-m-d');
$upcomingPickups = db()->prepare(
    "SELECT r.id, r.start_date, r.end_date, u.name AS customer_name, v.brand, v.model
     FROM rentals r
     INNER JOIN users u ON u.id = r.user_id
     INNER JOIN vehicles v ON v.id = r.vehicle_id
     WHERE r.status = 'confirmed' AND r.start_date >= ?
     ORDER BY r.start_date ASC LIMIT " . ($staffSection === 'operations' ? 50 : 5)
);
$upcomingPickups->execute([$today]);
$upcomingPickups = $upcomingPickups->fetchAll();
$expectedReturns = db()->prepare(
    "SELECT r.id, r.end_date, u.name AS customer_name, v.brand, v.model
     FROM rentals r
     INNER JOIN users u ON u.id = r.user_id
     INNER JOIN vehicles v ON v.id = r.vehicle_id
     WHERE r.status = 'ongoing'
     ORDER BY r.end_date ASC LIMIT " . ($staffSection === 'operations' ? 50 : 5)
);
$expectedReturns->execute();
$expectedReturns = $expectedReturns->fetchAll();
$overduePerPage = $staffSection === 'operations' ? 50 : 5;
$overduePage = max(1, (int)($_GET['overdue_page'] ?? 1));
$overdueTotal = (int)db()->query(
    "SELECT COUNT(*)
     FROM rentals r
     INNER JOIN users u ON u.id = r.user_id
     INNER JOIN vehicles v ON v.id = r.vehicle_id
     WHERE r.status = 'ongoing' AND r.end_date < CURDATE()"
)->fetchColumn();
$overduePages = max(1, (int)ceil($overdueTotal / $overduePerPage));
$overduePage = min($overduePage, $overduePages);
$overdueOffset = ($overduePage - 1) * $overduePerPage;
$overdue = db()->query(
    "SELECT r.id, r.end_date, u.name AS customer_name, v.brand, v.model
     FROM rentals r
     INNER JOIN users u ON u.id = r.user_id
     INNER JOIN vehicles v ON v.id = r.vehicle_id
     WHERE r.status = 'ongoing' AND r.end_date < CURDATE()
     ORDER BY r.end_date ASC
     LIMIT " . ($staffSection === 'operations' ? 50 : $overduePerPage) . " OFFSET {$overdueOffset}"
)->fetchAll();
include __DIR__ . '/../../includes/header.php';
?>
<main class="dashboard-page staff-workspace">
  <section class="dashboard-hero">
    <div>
      <p class="eyebrow">Staff workspace</p>
      <h1><?= $staffSection === 'requests' ? 'Rental requests.' : ($staffSection === 'operations' ? 'Operations.' : 'Operations overview.') ?></h1>
      <p>Welcome, <?= e($user['name']) ?>. <?= $staffSection === 'requests' ? 'Review and process customer bookings.' : ($staffSection === 'operations' ? 'Manage pickups, returns, payments, and overdue rentals.' : 'Review requests and manage rental handovers.') ?></p>
    </div>
    <?php if ($staffSection === 'overview'): ?><a class="btn btn-coral" href="/staff/staff-dashboard.php?section=requests">Review requests</a><?php elseif ($staffSection === 'requests'): ?><a class="btn btn-coral" href="/staff/staff-dashboard.php?section=operations">View operations</a><?php else: ?><a class="btn btn-coral" href="/staff/staff-dashboard.php?section=requests">Review requests</a><?php endif; ?>
  </section>

  <?php if ($message): ?><p class="note request-success" role="status"><?= e($message) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="form-error" role="alert"><?= e($error) ?></p><?php endif; ?>

  <section class="dashboard-stats">
    <article class="summary-card"><span class="summary-label">Pending requests</span><strong><?= $pending ?></strong><span>Need review</span></article>
    <article class="summary-card"><span class="summary-label">Active rentals</span><strong><?= $active ?></strong><span>Confirmed or ongoing</span></article>
    <article class="summary-card"><span class="summary-label">Maintenance vehicles</span><strong><?= $maintenance ?></strong><span>Currently unavailable</span></article>
  </section>

  <?php if ($staffSection !== 'requests'): ?><section class="operations-grid" id="operations">
    <div class="admin-card">
      <p class="eyebrow">Next pickups</p>
      <h2>Upcoming handovers</h2>
      <?php if (!$upcomingPickups): ?><p class="muted-copy">No confirmed pickups scheduled.</p><?php else: ?><ul class="operations-list"><?php foreach ($upcomingPickups as $pickup): ?><li><strong><?= e(date('M j', strtotime($pickup['start_date']))) ?></strong><span><?= e($pickup['customer_name']) ?> · <?= e($pickup['brand'] . ' ' . $pickup['model']) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
    </div>
    <div class="admin-card">
      <p class="eyebrow">Expected returns</p>
      <h2>Vehicle returns</h2>
      <?php if (!$expectedReturns): ?><p class="muted-copy">No ongoing rentals.</p><?php else: ?><ul class="operations-list"><?php foreach ($expectedReturns as $return): ?><li><strong><?= e(date('M j', strtotime($return['end_date']))) ?></strong><span><?= e($return['customer_name']) ?> · <?= e($return['brand'] . ' ' . $return['model']) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
    </div>
    <?php if ($staffSection === 'overview'): ?><div class="staff-section-link"><a class="text-link" href="/staff/staff-dashboard.php?section=operations">View complete operations</a></div><?php endif; ?>
  </section>
  <?php if ($overdue): ?>
    <section class="overdue-panel"><strong><?= count($overdue) ?> overdue rental<?= count($overdue) === 1 ? '' : 's' ?></strong><span><?php foreach ($overdue as $index => $item): ?><?= $index ? '; ' : '' ?><?= e($item['brand'] . ' ' . $item['model']) ?> for <?= e($item['customer_name']) ?><?php endforeach; ?></span></section>
    <?php if ($overduePages > 1): ?><nav class="pagination" aria-label="Overdue rental pages"><?php for ($number = 1; $number <= $overduePages; $number++): ?><a class="<?= $number === $overduePage ? 'active' : '' ?>" href="?<?= e(http_build_query(['status' => $statusFilter, 'search' => $search, 'page' => $page, 'overdue_page' => $number])) ?>#operations"><?= $number ?></a><?php endfor; ?></nav><?php endif; ?>
  <?php endif; ?>

  <?php endif; ?>

  <?php if ($staffSection !== 'operations'): ?><section class="dashboard-section" id="requests">
    <div class="section-head">
      <div>
        <p class="eyebrow">Rental requests</p>
        <h2>Review bookings</h2>
      </div>
    </div>
    <form class="filter-bar admin-filter" method="get">
      <label>Search customer or vehicle<input name="search" value="<?= e($search) ?>" placeholder="Name, email, brand, model"></label>
      <label>Status<select name="status"><option value="">All statuses</option><?php foreach ($allowedStatuses as $option): ?><option value="<?= e($option) ?>" <?= $statusFilter === $option ? 'selected' : '' ?>><?= e(ucfirst($option)) ?></option><?php endforeach; ?></select></label>
      <button class="btn btn-navy" type="submit">Filter</button>
      <a class="text-link" href="/staff/staff-dashboard.php?section=requests">Clear</a>
    </form>

    <?php if (!$requests): ?>
      <div class="empty-state"><h3>No rental requests yet</h3><p>Customer requests will appear here after submission.</p></div>
    <?php else: ?>
      <div class="request-list">
        <?php foreach ($requests as $request): ?>
          <article class="request-row">
            <div class="request-main">
              <div>
                <span class="tag"><?= e($request['category']) ?></span>
                <h3><?= e($request['brand']) ?> <?= e($request['model']) ?></h3>
                <p><strong><?= e($request['customer_name']) ?></strong> · <?= e($request['customer_email']) ?></p>
                <p><?= e(date('M j, Y', strtotime($request['start_date']))) ?> – <?= e(date('M j, Y', strtotime($request['end_date']))) ?></p>
                <p>Purpose: <strong><?= e($request['purpose'] ?: 'Not provided') ?></strong></p>
                <p>Preferred payment: <strong><?= e($request['payment_method'] ? ucfirst($request['payment_method']) : 'Not selected') ?></strong></p>
              </div>
              <div class="rental-meta">
                <span class="status-badge <?= in_array($request['status'], ['confirmed', 'ongoing', 'completed'], true) ? 'status-success' : 'status-neutral' ?>"><?= e(ucfirst($request['status'])) ?></span>
                <strong>₱<?= number_format((float)$request['total_amount']) ?></strong>
                <span>Paid: ₱<?= number_format((float)$request['paid_amount']) ?></span>
                <span><?= rental_days($request['start_date'], $request['end_date']) ?> day(s) · ₱<?= number_format((float)$request['total_amount'] / rental_days($request['start_date'], $request['end_date'])) ?>/day</span>
                <span>Request #<?= (int)$request['id'] ?></span>
              </div>
            </div>
            <?php if ($request['cancellation_requested']): ?>
              <div class="request-actions">
                <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="rental_id" value="<?= (int)$request['id'] ?>"><input type="hidden" name="action" value="approve_cancel"><button class="btn btn-coral" type="submit">Approve cancellation</button></form>
                <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="rental_id" value="<?= (int)$request['id'] ?>"><input type="hidden" name="action" value="reject_cancel"><input name="reason" placeholder="Reason for rejecting cancellation" required><button class="btn btn-navy" type="submit">Keep rental</button></form>
              </div>
            <?php elseif ($request['status'] === 'pending'): ?>
              <div class="request-actions">
                <form method="post">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="rental_id" value="<?= (int)$request['id'] ?>">
                  <input type="hidden" name="action" value="approve">
                  <button class="btn btn-coral" type="submit">Approve</button>
                </form>
                <form method="post">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="rental_id" value="<?= (int)$request['id'] ?>">
                  <input type="hidden" name="action" value="reject">
                  <input name="reason" placeholder="Reason for rejection" required>
                  <button class="btn btn-navy" type="submit">Reject</button>
                </form>
              </div>
            <?php endif; ?>
            <?php if ($request['status'] === 'confirmed' || $request['status'] === 'ongoing'): ?>
              <div class="request-actions">
                <?php if ($request['status'] === 'confirmed'): ?>
                  <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="rental_id" value="<?= (int)$request['id'] ?>">
                    <input type="hidden" name="action" value="start">
                    <button class="btn btn-coral" type="submit">Start rental</button>
                  </form>
                <?php else: ?>
                  <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="rental_id" value="<?= (int)$request['id'] ?>">
                    <input type="hidden" name="action" value="complete">
                    <button class="btn btn-coral" type="submit">Complete return</button>
                  </form>
                <?php endif; ?>
                <?php if ((float)$request['paid_amount'] < (float)$request['total_amount']): ?>
                  <form class="payment-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="rental_id" value="<?= (int)$request['id'] ?>">
                    <input type="hidden" name="action" value="payment">
                    <input type="number" name="amount" min="0.01" max="<?= e((float)$request['total_amount'] - (float)$request['paid_amount']) ?>" step="0.01" placeholder="Amount" required>
                    <select name="method" required>
                      <option value="">Method</option>
                      <option value="cash">Cash</option>
                      <option value="gcash">GCash</option>
                      <option value="card">Card</option>
                    </select>
                    <button class="btn btn-navy" type="submit">Record payment</button>
                  </form>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
      <?php if ($totalPages > 1): ?><nav class="pagination" aria-label="Rental request pages"><?php for ($number = 1; $number <= $totalPages; $number++): ?><a class="<?= $number === $page ? 'active' : '' ?>" href="?<?= e(http_build_query(['status' => $statusFilter, 'search' => $search, 'page' => $number])) ?>#requests"><?= $number ?></a><?php endfor; ?></nav><?php endif; ?>
    <?php endif; ?>
    <?php if ($staffSection === 'overview'): ?><div class="staff-section-link"><a class="text-link" href="/staff/staff-dashboard.php?section=requests">View all rental requests</a></div><?php endif; ?>
  </section><?php endif; ?>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
