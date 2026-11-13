<?php
$title = 'Penalties & Account Standing';
require_once __DIR__ . '/../../config.php';
$user = require_role('admin');
$isSuperAdmin = is_super_admin($user);
$branchHubId = !$isSuperAdmin ? current_user_location_id($user) : null;

$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$statusFilter = trim($_GET['status'] ?? 'pending');
$search = trim($_GET['search'] ?? '');
$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    try {
        $action = $_POST['action'] ?? '';
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id) {
            throw new RuntimeException('Invalid penalty record ID.');
        }
        $pdo = db();

        if ($action === 'approve_penalty') {
            $stmt = $pdo->prepare("UPDATE penalty_events SET status = 'approved', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
            $stmt->execute([(int)$user['id'], $id]);
            write_audit($pdo, (int)$user['id'], 'penalty_approved', 'penalty_events', $id, 'Penalty approved');
            $message = 'Penalty has been approved.';
        } elseif ($action === 'waive_penalty') {
            $stmt = $pdo->prepare("UPDATE penalty_events SET status = 'waived', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
            $stmt->execute([(int)$user['id'], $id]);
            write_audit($pdo, (int)$user['id'], 'penalty_waived', 'penalty_events', $id, 'Penalty waived');
            $message = 'Penalty has been waived.';
        } elseif ($action === 'reverse_penalty') {
            $stmt = $pdo->prepare("UPDATE penalty_events SET status = 'reversed', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
            $stmt->execute([(int)$user['id'], $id]);
            write_audit($pdo, (int)$user['id'], 'penalty_reversed', 'penalty_events', $id, 'Penalty reversed');
            $message = 'Penalty has been reversed.';
        } else {
            throw new RuntimeException('Invalid penalty action.');
        }
    } catch (Throwable $exception) {
        error_log('Penalty action failed: ' . $exception->getMessage());
        $error = $exception->getMessage();
    }
}

// KPI counts for penalty_events
$hubPenaltyJoin = $branchHubId ? " INNER JOIN users pu ON pu.id = pe.user_id AND pu.location_id = {$branchHubId}" : '';
$countPending  = (int)db()->query("SELECT COUNT(*) FROM penalty_events pe{$hubPenaltyJoin} WHERE pe.status = 'pending'")->fetchColumn();
$countApproved = (int)db()->query("SELECT COUNT(*) FROM penalty_events pe{$hubPenaltyJoin} WHERE pe.status = 'approved'")->fetchColumn();
$countWaived   = (int)db()->query("SELECT COUNT(*) FROM penalty_events pe{$hubPenaltyJoin} WHERE pe.status = 'waived'")->fetchColumn();
$countReversed = (int)db()->query("SELECT COUNT(*) FROM penalty_events pe{$hubPenaltyJoin} WHERE pe.status = 'reversed'")->fetchColumn();

// Account standing summary
$standingHubJoin = $branchHubId ? " INNER JOIN users su ON su.id = cas.user_id AND su.location_id = {$branchHubId}" : '';
$standingRows = db()->query("SELECT cas.standing, COUNT(*) as cnt FROM customer_account_standing cas{$standingHubJoin} GROUP BY cas.standing")->fetchAll();
$standingCounts = ['good' => 0, 'warning' => 0, 'restricted' => 0, 'suspended' => 0, 'under_review' => 0];
foreach ($standingRows as $row) {
    $key = strtolower($row['standing']);
    if (array_key_exists($key, $standingCounts)) {
        $standingCounts[$key] = (int)$row['cnt'];
    }
}

// Build WHERE clause
$validStatuses = ['pending', 'approved', 'waived', 'reversed'];
$whereParts = [];
$params = [];

if (in_array($statusFilter, $validStatuses, true)) {
    $whereParts[] = 'pe.status = ?';
    $params[] = $statusFilter;
}

if ($search !== '') {
    $whereParts[] = '(u.name LIKE ? OR u.email LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}

if ($branchHubId) {
    $whereParts[] = "u.location_id = {$branchHubId}";
}

$whereClause = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

// Total count
$countStmt = db()->prepare("SELECT COUNT(*) FROM penalty_events pe INNER JOIN users u ON u.id = pe.user_id {$whereClause}");
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalRecords / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

// Main query
$sql = "SELECT pe.id, pe.event_type, pe.points, pe.reason, pe.status, pe.expires_at, pe.created_at, pe.reviewed_at,
               u.name AS customer_name, u.email AS customer_email,
               res.reference_number,
               cas.standing AS account_standing,
               reviewer.name AS reviewed_by_name
        FROM penalty_events pe
        INNER JOIN users u ON u.id = pe.user_id
        LEFT JOIN reservations res ON res.id = pe.reservation_id
        LEFT JOIN customer_account_standing cas ON cas.user_id = pe.user_id
        LEFT JOIN users reviewer ON reviewer.id = pe.reviewed_by
        {$whereClause}
        ORDER BY pe.created_at DESC
        LIMIT {$perPage} OFFSET {$offset}";

$stmtParams = $params;
$recordsStmt = db()->prepare($sql);
$recordsStmt->execute($stmtParams);
$records = $recordsStmt->fetchAll();

// Build pagination query string helper
function penaltyPageUrl(int $p, string $status, string $search): string {
    $qs = http_build_query(array_filter(['page' => $p, 'status' => $status, 'search' => $search], fn($v) => $v !== ''));
    return '?' . $qs;
}

include __DIR__ . '/../../includes/header.php';
?>

<main class="portal-customer-page">
  <div class="portal-customer-layout">

    <!-- Unified Sidebar Navigation -->
    <?php include __DIR__ . '/../../includes/workspace-sidebar.php'; ?>

    <!-- Main Workspace Content -->
    <div class="customer-main-content">

      <!-- Hero Greeting Banner -->
      <section class="portal-hero-banner" aria-label="Penalties heading">
        <div class="portal-hero-text">
          <div class="portal-hero-eyebrow">
            <span class="pulse-indicator" aria-hidden="true"></span>
            <span>ADMINISTRATION · ACCOUNT STANDING</span>
          </div>
          <h1 class="portal-hero-title">Penalties &amp; Account Standing</h1>
          <p class="portal-hero-subtitle">
            Review and manage customer penalty events, approve or waive infractions, and monitor account standing classifications across the customer base.
          </p>
        </div>
        <div class="portal-hero-actions">
          <a href="/admin/admin-rentals.php" class="btn btn-outline-white">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <span>Rental Oversight</span>
          </a>
          <a href="/admin/admin-dashboard.php" class="btn btn-outline-white">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
            <span>Dashboard</span>
          </a>
        </div>
      </section>

      <!-- Feedback notifications -->
      <?php if ($message): ?>
        <div class="portal-card" style="border-left: 4px solid #10b981; background: #ecfdf5; padding: 14px 18px; margin-bottom: 20px;" role="status">
          <div style="display:flex; align-items:center; gap:10px; color:#065f46; font-size:0.88rem; font-weight:600;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            <span><?= e($message) ?></span>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="portal-card" style="border-left: 4px solid #ef4444; background: #fef2f2; padding: 14px 18px; margin-bottom: 20px;" role="alert">
          <div style="display:flex; align-items:center; gap:10px; color:#991b1b; font-size:0.88rem; font-weight:600;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span><?= e($error) ?></span>
          </div>
        </div>
      <?php endif; ?>

      <!-- KPI Summary Row -->
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 14px; margin-bottom: 24px;">

        <!-- Penalty KPIs -->
        <div class="portal-card" style="padding: 14px 16px;">
          <div style="font-size:0.7rem; font-weight:800; text-transform:uppercase; letter-spacing:0.05em; color:#94a3b8; margin-bottom:4px;">Pending</div>
          <div style="font-size:1.6rem; font-weight:800; color:#d97706;"><?= $countPending ?></div>
          <div style="font-size:0.74rem; color:#64748b; margin-top:2px;">Penalty Events</div>
        </div>

        <div class="portal-card" style="padding: 14px 16px;">
          <div style="font-size:0.7rem; font-weight:800; text-transform:uppercase; letter-spacing:0.05em; color:#94a3b8; margin-bottom:4px;">Approved</div>
          <div style="font-size:1.6rem; font-weight:800; color:#059669;"><?= $countApproved ?></div>
          <div style="font-size:0.74rem; color:#64748b; margin-top:2px;">Penalty Events</div>
        </div>

        <div class="portal-card" style="padding: 14px 16px;">
          <div style="font-size:0.7rem; font-weight:800; text-transform:uppercase; letter-spacing:0.05em; color:#94a3b8; margin-bottom:4px;">Waived</div>
          <div style="font-size:1.6rem; font-weight:800; color:#64748b;"><?= $countWaived ?></div>
          <div style="font-size:0.74rem; color:#64748b; margin-top:2px;">Penalty Events</div>
        </div>

        <div class="portal-card" style="padding: 14px 16px;">
          <div style="font-size:0.7rem; font-weight:800; text-transform:uppercase; letter-spacing:0.05em; color:#94a3b8; margin-bottom:4px;">Reversed</div>
          <div style="font-size:1.6rem; font-weight:800; color:#1d4ed8;"><?= $countReversed ?></div>
          <div style="font-size:0.74rem; color:#64748b; margin-top:2px;">Penalty Events</div>
        </div>

        <!-- Account Standing KPIs -->
        <div class="portal-card" style="padding: 14px 16px;">
          <div style="font-size:0.7rem; font-weight:800; text-transform:uppercase; letter-spacing:0.05em; color:#94a3b8; margin-bottom:4px;">Good</div>
          <div style="font-size:1.6rem; font-weight:800; color:#059669;"><?= $standingCounts['good'] ?></div>
          <div style="font-size:0.74rem; color:#64748b; margin-top:2px;">Accounts</div>
        </div>

        <div class="portal-card" style="padding: 14px 16px;">
          <div style="font-size:0.7rem; font-weight:800; text-transform:uppercase; letter-spacing:0.05em; color:#94a3b8; margin-bottom:4px;">Warning</div>
          <div style="font-size:1.6rem; font-weight:800; color:#d97706;"><?= $standingCounts['warning'] ?></div>
          <div style="font-size:0.74rem; color:#64748b; margin-top:2px;">Accounts</div>
        </div>

        <div class="portal-card" style="padding: 14px 16px;">
          <div style="font-size:0.7rem; font-weight:800; text-transform:uppercase; letter-spacing:0.05em; color:#94a3b8; margin-bottom:4px;">Restricted</div>
          <div style="font-size:1.6rem; font-weight:800; color:#ea580c;"><?= $standingCounts['restricted'] ?></div>
          <div style="font-size:0.74rem; color:#64748b; margin-top:2px;">Accounts</div>
        </div>

        <div class="portal-card" style="padding: 14px 16px;">
          <div style="font-size:0.7rem; font-weight:800; text-transform:uppercase; letter-spacing:0.05em; color:#94a3b8; margin-bottom:4px;">Suspended</div>
          <div style="font-size:1.6rem; font-weight:800; color:#dc2626;"><?= $standingCounts['suspended'] ?></div>
          <div style="font-size:0.74rem; color:#64748b; margin-top:2px;">Accounts</div>
        </div>

        <div class="portal-card" style="padding: 14px 16px;">
          <div style="font-size:0.7rem; font-weight:800; text-transform:uppercase; letter-spacing:0.05em; color:#94a3b8; margin-bottom:4px;">Under Review</div>
          <div style="font-size:1.6rem; font-weight:800; color:#7c3aed;"><?= $standingCounts['under_review'] ?></div>
          <div style="font-size:0.74rem; color:#64748b; margin-top:2px;">Accounts</div>
        </div>

      </div>

      <!-- Penalty Records Table Card -->
      <section class="portal-card" aria-label="Penalty records">
        <div class="portal-card-header" style="align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
          <div class="portal-card-title-group">
            <h2>Penalty Events</h2>
            <p>Showing <?= count($records) ?> of <?= $totalRecords ?> penalty records.</p>
          </div>

          <!-- Search Bar -->
          <form method="get" style="display:flex; align-items:center; gap:8px;">
            <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search customer name or email..." style="font-size:0.83rem; padding:7px 12px; min-width:240px;">
            <button type="submit" class="btn btn-secondary" style="padding:7px 14px; font-size:0.83rem;">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              Search
            </button>
            <?php if ($search !== ''): ?>
              <a href="?status=<?= e($statusFilter) ?>" class="btn btn-outline" style="padding:7px 12px; font-size:0.83rem;">Clear</a>
            <?php endif; ?>
          </form>
        </div>

        <!-- Status Filter Pills -->
        <div class="admin-filter-bar">
          <a href="?status=pending<?= $search !== '' ? '&search=' . urlencode($search) : '' ?>" class="admin-filter-pill <?= $statusFilter === 'pending' ? 'active' : '' ?>">
            <span>Pending</span>
            <span class="pill-count"><?= $countPending ?></span>
          </a>
          <a href="?status=approved<?= $search !== '' ? '&search=' . urlencode($search) : '' ?>" class="admin-filter-pill <?= $statusFilter === 'approved' ? 'active' : '' ?>">
            <span>Approved</span>
            <span class="pill-count"><?= $countApproved ?></span>
          </a>
          <a href="?status=waived<?= $search !== '' ? '&search=' . urlencode($search) : '' ?>" class="admin-filter-pill <?= $statusFilter === 'waived' ? 'active' : '' ?>">
            <span>Waived</span>
            <span class="pill-count"><?= $countWaived ?></span>
          </a>
          <a href="?status=reversed<?= $search !== '' ? '&search=' . urlencode($search) : '' ?>" class="admin-filter-pill <?= $statusFilter === 'reversed' ? 'active' : '' ?>">
            <span>Reversed</span>
            <span class="pill-count"><?= $countReversed ?></span>
          </a>
          <a href="?status=all<?= $search !== '' ? '&search=' . urlencode($search) : '' ?>" class="admin-filter-pill <?= $statusFilter === 'all' ? 'active' : '' ?>">
            <span>All Records</span>
          </a>
        </div>

        <!-- Table -->
        <div class="portal-table-container">
          <table class="portal-table">
            <thead>
              <tr>
                <th>Customer</th>
                <th>Event Type</th>
                <th>Points</th>
                <th>Reason</th>
                <th>Ref #</th>
                <th>Status</th>
                <th>Expires</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($records)): ?>
                <tr>
                  <td colspan="8" style="text-align:center; padding: 36px 16px; color:#64748b;">
                    No penalty records found for the selected view.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($records as $record): ?>
                  <tr>
                    <td>
                      <strong style="display:block; font-size:0.88rem; color:#0f172a;"><?= e($record['customer_name']) ?></strong>
                      <span style="font-size:0.75rem; color:#64748b;"><?= e($record['customer_email']) ?></span>
                      <?php if ($record['account_standing']): ?>
                        <?php
                          $standing = strtolower($record['account_standing']);
                          $standingStyle = match($standing) {
                              'good'        => 'background:#ecfdf5; color:#065f46;',
                              'warning'     => 'background:#fffbeb; color:#92400e;',
                              'restricted'  => 'background:#fff7ed; color:#9a3412;',
                              'suspended'   => 'background:#fef2f2; color:#991b1b;',
                              'under_review'=> 'background:#f5f3ff; color:#5b21b6;',
                              default       => 'background:#f1f5f9; color:#475569;',
                          };
                        ?>
                        <span style="display:inline-block; margin-top:4px; font-size:0.68rem; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; padding:2px 7px; border-radius:999px; <?= $standingStyle ?>">
                          <?= e(ucwords(str_replace('_', ' ', $record['account_standing']))) ?>
                        </span>
                      <?php endif; ?>
                    </td>

                    <td>
                      <span style="font-size:0.84rem; color:#1e293b; font-weight:600;"><?= e(ucwords(str_replace('_', ' ', $record['event_type']))) ?></span>
                    </td>

                    <td>
                      <span style="font-size:0.9rem; font-weight:800; color:<?= (int)$record['points'] > 0 ? '#dc2626' : '#059669' ?>;">
                        <?= (int)$record['points'] > 0 ? '+' : '' ?><?= (int)$record['points'] ?>
                      </span>
                    </td>

                    <td>
                      <span style="font-size:0.82rem; color:#334155; max-width:200px; display:block;"><?= e($record['reason']) ?></span>
                    </td>

                    <td>
                      <?php if ($record['reference_number']): ?>
                        <span style="font-size:0.8rem; font-weight:600; color:#0f172a; font-family:monospace;"><?= e($record['reference_number']) ?></span>
                      <?php else: ?>
                        <span style="font-size:0.75rem; color:#94a3b8;">N/A</span>
                      <?php endif; ?>
                    </td>

                    <td>
                      <?php if ($record['status'] === 'pending'): ?>
                        <span class="portal-badge badge-warning">Pending</span>
                      <?php elseif ($record['status'] === 'approved'): ?>
                        <span class="portal-badge badge-success">Approved</span>
                      <?php elseif ($record['status'] === 'waived'): ?>
                        <span class="portal-badge badge-neutral">Waived</span>
                      <?php elseif ($record['status'] === 'reversed'): ?>
                        <span class="portal-badge" style="background:#eff6ff; color:#1d4ed8;">Reversed</span>
                      <?php else: ?>
                        <span class="portal-badge badge-neutral"><?= e(ucfirst($record['status'])) ?></span>
                      <?php endif; ?>
                    </td>

                    <td>
                      <?php if ($record['expires_at']): ?>
                        <span style="font-size:0.8rem; color:#1e293b; font-weight:500;"><?= e(date('M j, Y', strtotime($record['expires_at']))) ?></span>
                      <?php else: ?>
                        <span style="font-size:0.75rem; color:#94a3b8;">No expiry</span>
                      <?php endif; ?>
                      <?php if ($record['reviewed_by_name']): ?>
                        <span style="font-size:0.72rem; color:#94a3b8; display:block; margin-top:2px;">by <?= e($record['reviewed_by_name']) ?></span>
                      <?php endif; ?>
                    </td>

                    <td>
                      <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                        <?php if ($record['status'] === 'pending'): ?>
                          <form method="post" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="approve_penalty">
                            <input type="hidden" name="id" value="<?= (int)$record['id'] ?>">
                            <button type="submit" class="btn btn-coral" style="padding: 5px 10px; font-size:0.76rem;" title="Approve this penalty">Approve</button>
                          </form>
                          <form method="post" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="waive_penalty">
                            <input type="hidden" name="id" value="<?= (int)$record['id'] ?>">
                            <button type="submit" class="btn btn-secondary" style="padding: 5px 10px; font-size:0.76rem;" title="Waive this penalty" onclick="return confirm('Waive this penalty event?');">Waive</button>
                          </form>
                        <?php elseif ($record['status'] === 'approved'): ?>
                          <form method="post" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="reverse_penalty">
                            <input type="hidden" name="id" value="<?= (int)$record['id'] ?>">
                            <button type="submit" class="btn btn-outline" style="padding: 5px 10px; font-size:0.76rem; color:#1d4ed8; border-color:#bfdbfe;" title="Reverse this approved penalty" onclick="return confirm('Reverse this approved penalty?');">Reverse</button>
                          </form>
                        <?php else: ?>
                          <span style="font-size:0.75rem; color:#94a3b8;">No actions</span>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
          <div style="display:flex; justify-content:center; gap:6px; margin-top:20px;">
            <?php for ($num = 1; $num <= $totalPages; $num++): ?>
              <a href="<?= penaltyPageUrl($num, $statusFilter, $search) ?>" class="btn <?= $num === $page ? 'btn-coral' : 'btn-secondary' ?>" style="padding: 6px 12px; font-size: 0.8rem;">
                <?= $num ?>
              </a>
            <?php endfor; ?>
          </div>
        <?php endif; ?>

      </section>

    </div>
  </div>
</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
