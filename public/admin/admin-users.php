<?php
$title = 'User Management';
require_once __DIR__ . '/../../config.php';
$user = require_role('admin');

$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$filter = trim($_GET['filter'] ?? 'all');
$search = trim($_GET['search'] ?? '');

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    $role = $_POST['role'] ?? '';
    $action = $_POST['action'] ?? 'role';

    if (!$id) {
        $error = 'Invalid account update.';
    } elseif ($id === (int)$user['id']) {
        $error = 'You cannot modify your own administrator account status or role.';
    } elseif ($action === 'approve') {
        $stmt = db()->prepare("UPDATE users SET account_status = 'approved' WHERE id = ?");
        $stmt->execute([$id]);
        write_audit(db(), (int)$user['id'], 'user_approved', 'users', $id);
        $message = 'Account registration approved successfully.';
    } elseif ($action === 'reject') {
        $stmt = db()->prepare("UPDATE users SET account_status = 'rejected' WHERE id = ?");
        $stmt->execute([$id]);
        write_audit(db(), (int)$user['id'], 'user_rejected', 'users', $id);
        $message = 'Account registration was rejected.';
    } elseif ($action === 'suspend') {
        $reason = trim($_POST['reason'] ?? 'Account suspended by administrator (policy/incident investigation).');
        mark_customer_suspended(db(), $id, (int)$user['id'], $reason);
        write_audit(db(), (int)$user['id'], 'account_suspended', 'users', $id, $reason);
        $message = 'Account has been suspended and restricted from booking or signing in.';
    } elseif ($action === 'archive') {
        $reason = trim($_POST['reason'] ?? 'Account archived and deactivated by administrator (e.g. unreturned car incident or deactivation).');
        mark_customer_archived(db(), $id, (int)$user['id'], $reason);
        write_audit(db(), (int)$user['id'], 'account_archived', 'users', $id, $reason);
        $message = 'Account has been archived and permanently deactivated.';
    } elseif ($action === 'restore') {
        restore_customer_account(db(), $id, (int)$user['id']);
        write_audit(db(), (int)$user['id'], 'account_restored', 'users', $id, 'Account restored to active approved status.');
        $message = 'Account has been restored to active status.';
    } elseif ($action === 'delete') {
        // Check if user has associated operational / financial records
        $hasRentals = (int)db()->query("SELECT COUNT(*) FROM rentals WHERE customer_id = {$id}")->fetchColumn();
        $hasReservations = (int)db()->query("SELECT COUNT(*) FROM reservations WHERE customer_id = {$id}")->fetchColumn();
        $hasPenalties = (int)db()->query("SELECT COUNT(*) FROM penalty_events WHERE user_id = {$id}")->fetchColumn();
        $hasIncidents = (int)db()->query("SELECT COUNT(*) FROM rental_incidents ri INNER JOIN rentals r ON r.id = ri.rental_id WHERE r.customer_id = {$id}")->fetchColumn();

        if ($hasRentals > 0 || $hasReservations > 0 || $hasPenalties > 0 || $hasIncidents > 0) {
            // Cannot drop row due to foreign key restrictions and legal audit requirements
            $reason = 'Archived during deletion attempt due to linked rental/incident history (unreturned vehicle or booking ledger)';
            mark_customer_archived(db(), $id, (int)$user['id'], $reason);
            write_audit(db(), (int)$user['id'], 'account_archived_on_delete', 'users', $id, $reason);
            $message = 'Account cannot be permanently deleted because it has linked rental or incident history (e.g. unreturned vehicle or payment records). For legal compliance and audit integrity, the account has been safely archived and permanently deactivated instead.';
        } else {
            // Safe to permanently delete clean user
            db()->beginTransaction();
            db()->prepare("DELETE FROM auth_tokens WHERE user_id = ?")->execute([$id]);
            db()->prepare("DELETE FROM user_roles WHERE user_id = ?")->execute([$id]);
            db()->prepare("DELETE FROM customer_account_standing WHERE user_id = ?")->execute([$id]);
            db()->prepare("DELETE FROM notifications WHERE user_id = ?")->execute([$id]);
            db()->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
            db()->commit();
            write_audit(db(), (int)$user['id'], 'account_deleted', 'users', $id, 'User account permanently deleted.');
            $message = 'User account was permanently deleted.';
        }
    } elseif ($action === 'role') {
        if (!in_array($role, ['customer', 'staff', 'admin'], true)) {
            $error = 'Invalid account role.';
        } else {
            $roleStmt = db()->prepare('SELECT id FROM roles WHERE name = ?');
            $roleStmt->execute([$role]);
            $roleId = $roleStmt->fetchColumn();
            if (!$roleId) {
                throw new RuntimeException('Invalid account role.');
            }
            db()->beginTransaction();
            db()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$id]);
            db()->prepare('INSERT INTO user_roles (user_id, role_id, assigned_by) VALUES (?, ?, ?)')->execute([$id, $roleId, $user['id']]);
            db()->commit();
            write_audit(db(), (int)$user['id'], 'user_role_changed', 'users', $id, 'Role changed to ' . $role . '.');
            $message = 'Account role updated successfully.';
        }
    }
}

// Counts for filter badges
$countAll = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
$countPendingStaff = (int)db()->query("SELECT COUNT(*) FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id INNER JOIN users u ON u.id = ur.user_id WHERE r.name = 'staff' AND u.account_status = 'pending'")->fetchColumn();
$countStaff = (int)db()->query("SELECT COUNT(*) FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id WHERE r.name = 'staff'")->fetchColumn();
$countCustomers = (int)db()->query("SELECT COUNT(*) FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id WHERE r.name = 'customer'")->fetchColumn();
$countAdmins = (int)db()->query("SELECT COUNT(*) FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id WHERE r.name = 'admin'")->fetchColumn();
$countSuspended = (int)db()->query("SELECT COUNT(*) FROM users WHERE account_status = 'suspended'")->fetchColumn();
$countArchived = (int)db()->query("SELECT COUNT(*) FROM users WHERE account_status = 'archived'")->fetchColumn();

// Build query with filter and search
$whereConditions = [];
$queryParams = [];

if ($filter === 'pending') {
    $whereConditions[] = "r.name = 'staff' AND u.account_status = 'pending'";
} elseif ($filter === 'staff') {
    $whereConditions[] = "r.name = 'staff'";
} elseif ($filter === 'customer') {
    $whereConditions[] = "r.name = 'customer'";
} elseif ($filter === 'admin') {
    $whereConditions[] = "r.name = 'admin'";
} elseif ($filter === 'suspended') {
    $whereConditions[] = "u.account_status = 'suspended'";
} elseif ($filter === 'archived') {
    $whereConditions[] = "u.account_status = 'archived'";
}

if ($search !== '') {
    $whereConditions[] = '(u.name LIKE ? OR u.email LIKE ?)';
    $queryParams[] = '%' . $search . '%';
    $queryParams[] = '%' . $search . '%';
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

$countSql = "SELECT COUNT(*) FROM users u INNER JOIN user_roles ur ON ur.user_id = u.id INNER JOIN roles r ON r.id = ur.role_id {$whereClause}";
$countStmt = db()->prepare($countSql);
$countStmt->execute($queryParams);
$totalUsers = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalUsers / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$usersSql = "SELECT u.id, u.name, u.email, r.name AS role, u.account_status, u.created_at
             FROM users u
             INNER JOIN user_roles ur ON ur.user_id = u.id
             INNER JOIN roles r ON r.id = ur.role_id
             {$whereClause}
             ORDER BY u.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}";
$usersStmt = db()->prepare($usersSql);
$usersStmt->execute($queryParams);
$users = $usersStmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<main class="portal-customer-page">
  <div class="portal-customer-layout">

    <!-- Unified Sidebar Navigation -->
    <?php include __DIR__ . '/../../includes/workspace-sidebar.php'; ?>

    <!-- Main Workspace Content -->
    <div class="customer-main-content">

      <!-- Hero Greeting Banner -->
      <section class="portal-hero-banner" aria-label="User directory heading">
        <div class="portal-hero-text">
          <div class="portal-hero-eyebrow">
            <span class="pulse-indicator" aria-hidden="true"></span>
            <span>ADMINISTRATION · USER DIRECTORY</span>
          </div>
          <h1 class="portal-hero-title">User & Account Management</h1>
          <p class="portal-hero-subtitle">
            Review user profiles, assign operational roles, approve faculty/staff credentials, and audit registration records.
          </p>
        </div>
        <div class="portal-hero-actions">
          <a href="/admin/admin-dashboard.php" class="btn btn-outline-white">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
            <span>Workspace Overview</span>
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

      <!-- Main Accounts Container Card -->
      <section class="portal-card" aria-label="User directory">
        
        <!-- Header & Search -->
        <div class="portal-card-header" style="align-items: center; justify-content: space-between;">
          <div class="portal-card-title-group">
            <h2>Registered System Accounts</h2>
            <p>Showing <?= count($users) ?> of <?= $totalUsers ?> matching accounts.</p>
          </div>

          <!-- Quick Search -->
          <form method="get" style="display:flex; gap:8px; align-items:center;">
            <?php if ($filter !== 'all'): ?><input type="hidden" name="filter" value="<?= e($filter) ?>"><?php endif; ?>
            <input type="search" name="search" value="<?= e($search) ?>" placeholder="Search name or email..." style="height: 40px; padding: 0 14px; border: 1.5px solid #cbd5e1; border-radius: 8px; font-size: 0.86rem; min-width: 240px;">
            <button type="submit" class="btn btn-secondary" style="height: 40px; padding: 0 16px; font-size: 0.84rem;">Search</button>
            <?php if ($search !== ''): ?>
              <a href="?filter=<?= e($filter) ?>" class="btn btn-outline" style="height: 40px; padding: 0 14px; font-size: 0.84rem; display:inline-flex; align-items:center;">Reset</a>
            <?php endif; ?>
          </form>
        </div>

        <!-- Filter Pill Tabs -->
        <div class="admin-filter-bar">
          <a href="?filter=all<?= $search !== '' ? '&search=' . urlencode($search) : '' ?>" class="admin-filter-pill <?= $filter === 'all' ? 'active' : '' ?>">
            <span>All Accounts</span>
            <span class="pill-count"><?= $countAll ?></span>
          </a>
          <a href="?filter=pending<?= $search !== '' ? '&search=' . urlencode($search) : '' ?>" class="admin-filter-pill <?= $filter === 'pending' ? 'active' : '' ?>" style="<?= $countPendingStaff > 0 ? 'border-color:#f59e0b;' : '' ?>">
            <span>Staff Pending</span>
            <span class="pill-count" style="<?= $countPendingStaff > 0 ? 'background:#f59e0b; color:#ffffff;' : '' ?>"><?= $countPendingStaff ?></span>
          </a>
          <a href="?filter=staff<?= $search !== '' ? '&search=' . urlencode($search) : '' ?>" class="admin-filter-pill <?= $filter === 'staff' ? 'active' : '' ?>">
            <span>Faculty & Staff</span>
            <span class="pill-count"><?= $countStaff ?></span>
          </a>
          <a href="?filter=customer<?= $search !== '' ? '&search=' . urlencode($search) : '' ?>" class="admin-filter-pill <?= $filter === 'customer' ? 'active' : '' ?>">
            <span>Customers</span>
            <span class="pill-count"><?= $countCustomers ?></span>
          </a>
          <a href="?filter=admin<?= $search !== '' ? '&search=' . urlencode($search) : '' ?>" class="admin-filter-pill <?= $filter === 'admin' ? 'active' : '' ?>">
            <span>Administrators</span>
            <span class="pill-count"><?= $countAdmins ?></span>
          </a>
          <a href="?filter=suspended<?= $search !== '' ? '&search=' . urlencode($search) : '' ?>" class="admin-filter-pill <?= $filter === 'suspended' ? 'active' : '' ?>" style="<?= $countSuspended > 0 ? 'border-color:#fca5a5;' : '' ?>">
            <span>Suspended</span>
            <span class="pill-count" style="<?= $countSuspended > 0 ? 'background:#ef4444; color:#ffffff;' : '' ?>"><?= $countSuspended ?></span>
          </a>
          <a href="?filter=archived<?= $search !== '' ? '&search=' . urlencode($search) : '' ?>" class="admin-filter-pill <?= $filter === 'archived' ? 'active' : '' ?>">
            <span>Archived</span>
            <span class="pill-count"><?= $countArchived ?></span>
          </a>
        </div>

        <!-- Accounts Table -->
        <div class="portal-table-container">
          <table class="portal-table">
            <thead>
              <tr>
                <th>User Identity</th>
                <th>Role</th>
                <th>Status</th>
                <th>Registered</th>
                <th>Account Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($users)): ?>
                <tr>
                  <td colspan="5" style="text-align:center; padding: 36px 16px; color:#64748b;">
                    No accounts found matching the selected filter or search criteria.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($users as $account): ?>
                  <tr>
                    <td>
                      <div style="display:flex; align-items:center; gap:12px;">
                        <div style="width:36px; height:36px; border-radius:50%; background:#0f172a; color:#ffffff; display:flex; align-items:center; justify-content:center; font-size:0.88rem; font-weight:800; flex-shrink:0;">
                          <?= e(strtoupper(substr($account['name'], 0, 1))) ?>
                        </div>
                        <div>
                          <strong style="display:block; font-size:0.88rem; color:#0f172a;"><?= e($account['name']) ?></strong>
                          <span style="font-size:0.75rem; color:#64748b;"><?= e($account['email']) ?></span>
                        </div>
                      </div>
                    </td>

                    <td>
                      <?php if ($account['role'] === 'admin'): ?>
                        <span class="role-badge-pill role-badge-admin" style="font-size: 0.72rem;">
                          <span class="role-pill-dot" aria-hidden="true"></span>
                          <span>Administrator</span>
                        </span>
                      <?php elseif ($account['role'] === 'staff'): ?>
                        <span class="role-badge-pill role-badge-staff" style="font-size: 0.72rem;">
                          <span class="role-pill-dot" aria-hidden="true"></span>
                          <span>Faculty / Staff</span>
                        </span>
                      <?php else: ?>
                        <span class="portal-badge badge-neutral" style="font-size: 0.72rem;">Customer</span>
                      <?php endif; ?>
                    </td>

                    <td>
                      <?php if ($account['account_status'] === 'approved'): ?>
                        <span class="portal-badge badge-success">Active / Approved</span>
                      <?php elseif ($account['account_status'] === 'pending'): ?>
                        <span class="portal-badge badge-warning">Pending Review</span>
                      <?php elseif ($account['account_status'] === 'suspended'): ?>
                        <span class="portal-badge badge-danger">Suspended</span>
                      <?php elseif ($account['account_status'] === 'archived'): ?>
                        <span class="portal-badge badge-neutral" style="background:#334155; color:#f8fafc; border:1px solid #475569;">Archived</span>
                      <?php else: ?>
                        <span class="portal-badge badge-neutral"><?= e(ucfirst($account['account_status'])) ?></span>
                      <?php endif; ?>
                    </td>

                    <td>
                      <span style="font-size:0.8rem; color:#475569;"><?= e(date('M j, Y', strtotime($account['created_at']))) ?></span>
                    </td>

                    <td>
                      <?php if ($account['role'] === 'staff' && $account['account_status'] === 'pending'): ?>
                        <form method="post" style="display:flex; gap:6px;">
                          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                          <input type="hidden" name="id" value="<?= (int)$account['id'] ?>">
                          <input type="hidden" name="role" value="staff">
                          <button class="btn btn-coral" name="action" value="approve" type="submit" style="padding: 5px 12px; font-size:0.76rem;">Approve</button>
                          <button class="btn btn-secondary" name="action" value="reject" type="submit" style="padding: 5px 10px; font-size:0.76rem;" onclick="return confirm('Reject this staff application?');">Reject</button>
                        </form>
                      <?php else: ?>
                        <div style="display:flex; flex-direction:column; gap:6px;">
                          <!-- Role Selector -->
                          <form method="post" style="display:flex; align-items:center; gap:6px;">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="id" value="<?= (int)$account['id'] ?>">
                            <input type="hidden" name="action" value="role">
                            <select name="role" style="padding: 4px 8px; border-radius: 6px; border: 1.5px solid #cbd5e1; font-size: 0.78rem; background: #ffffff; height: 32px;" <?= (int)$account['id'] === (int)$user['id'] ? 'disabled title="You cannot modify your own role"' : '' ?>>
                              <?php foreach (['customer' => 'Customer', 'staff' => 'Faculty / Staff', 'admin' => 'Administrator'] as $roleKey => $roleLabel): ?>
                                <option value="<?= $roleKey ?>" <?= $account['role'] === $roleKey ? 'selected' : '' ?>><?= $roleLabel ?></option>
                              <?php endforeach; ?>
                            </select>
                            <button class="btn btn-secondary" type="submit" style="padding: 0 10px; height: 32px; font-size:0.76rem;" <?= (int)$account['id'] === (int)$user['id'] ? 'disabled' : '' ?>>Save</button>
                          </form>

                          <!-- Lifecycle / Archive / Delete / Suspend Actions -->
                          <?php if ((int)$account['id'] !== (int)$user['id']): ?>
                            <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                              <?php if ($account['account_status'] === 'approved'): ?>
                                <form method="post" style="display:inline;">
                                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                  <input type="hidden" name="id" value="<?= (int)$account['id'] ?>">
                                  <button type="submit" name="action" value="suspend" class="btn btn-outline" style="padding: 3px 8px; height: 28px; font-size: 0.72rem; color: #b45309; border-color: #fde68a;" onclick="return confirm('Suspend this account? User will be temporarily blocked from booking and signing in.');">Suspend</button>
                                </form>
                                <form method="post" style="display:inline;">
                                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                  <input type="hidden" name="id" value="<?= (int)$account['id'] ?>">
                                  <button type="submit" name="action" value="archive" class="btn btn-outline" style="padding: 3px 8px; height: 28px; font-size: 0.72rem; color: #475569; border-color: #cbd5e1;" onclick="return confirm('Archive and deactivate this account? For example, if they violated rental terms or failed to return a vehicle.');">Archive</button>
                                </form>
                              <?php elseif ($account['account_status'] === 'suspended' || $account['account_status'] === 'archived'): ?>
                                <form method="post" style="display:inline;">
                                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                  <input type="hidden" name="id" value="<?= (int)$account['id'] ?>">
                                  <button type="submit" name="action" value="restore" class="btn btn-outline" style="padding: 3px 8px; height: 28px; font-size: 0.72rem; color: #047857; border-color: #a7f3d0;" onclick="return confirm('Restore this account to active status?');">Restore</button>
                                </form>
                                <?php if ($account['account_status'] === 'suspended'): ?>
                                  <form method="post" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="id" value="<?= (int)$account['id'] ?>">
                                    <button type="submit" name="action" value="archive" class="btn btn-outline" style="padding: 3px 8px; height: 28px; font-size: 0.72rem; color: #475569; border-color: #cbd5e1;" onclick="return confirm('Archive this suspended account permanently?');">Archive</button>
                                  </form>
                                <?php endif; ?>
                              <?php endif; ?>

                              <!-- Delete Account Button -->
                              <form method="post" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="id" value="<?= (int)$account['id'] ?>">
                                <button type="submit" name="action" value="delete" class="btn btn-outline" style="padding: 3px 8px; height: 28px; font-size: 0.72rem; color: #991b1b; border-color: #fecaca;" onclick="return confirm('Permanently delete this account? If this user has active or historical rentals (e.g. unreturned car or payment logs), it will be safely archived instead to preserve legal and financial audit trails.');">Delete</button>
                              </form>
                            </div>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>
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
              <a href="?page=<?= $num ?>&filter=<?= e($filter) ?><?= $search !== '' ? '&search=' . urlencode($search) : '' ?>" class="btn <?= $num === $page ? 'btn-coral' : 'btn-secondary' ?>" style="padding: 6px 12px; font-size: 0.8rem;">
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
