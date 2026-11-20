<?php
$title = 'User Management';
require_once __DIR__ . '/../../config.php';
$user = require_role('admin');

$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$filter = trim($_GET['filter'] ?? 'all');
$search = trim($_GET['search'] ?? '');
$adminIsSuperAdmin = is_super_admin($user);
$adminHubId = !$adminIsSuperAdmin ? current_user_location_id($user) : null;
$hubScope = $adminHubId ? " AND u.location_id = {$adminHubId}" : '';

$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
    $role = $_POST['role'] ?? '';
    $action = $_POST['action'] ?? 'role';

    // Branch admin protection: cannot target admin/super_admin users
    $targetIsAdmin = false;
    if ($id && !is_super_admin($user)) {
        $chkStmt = db()->prepare('SELECT r.name FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?');
        $chkStmt->execute([$id]);
        $chkRole = $chkStmt->fetchColumn();
        $targetIsAdmin = in_array($chkRole, ['admin', 'super_admin'], true);
    }

    if (!$id) {
        $error = 'Invalid account update.';
    } elseif ($id === (int)$user['id']) {
        $error = 'You cannot modify your own administrator account status or role.';
    } elseif ($targetIsAdmin && in_array($action, ['suspend', 'archive', 'delete', 'restore', 'update_user', 'role'], true)) {
        $error = 'Only Super Administrators can manage other administrator accounts.';
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
    } elseif ($action === 'assign_location') {
        $locId = filter_var($_POST['location_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $stmt = db()->prepare("UPDATE users SET location_id = ? WHERE id = ?");
        $stmt->execute([$locId, $id]);
        write_audit(db(), (int)$user['id'], 'user_location_assigned', 'users', $id, 'Assigned location ID: ' . ($locId ?: 'Global / None'));
        entity_log_change(db(), 'users', $id, 'UPDATE', null, ['location_id' => $locId], (int)$user['id']);
        $message = 'User venue hub assignment updated successfully.';
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
            soft_delete_entity(db(), 'users', $id, (int)$user['id']);
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
    } elseif ($action === 'update_user') {
        $allowedRoles = is_super_admin($user) ? ['customer', 'staff', 'admin', 'super_admin'] : ['customer', 'staff'];
        if (!in_array($role, $allowedRoles, true)) {
            $error = 'Invalid account role or insufficient permissions to assign this role tier.';
        } else {
            $locId     = filter_var($_POST['location_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
            $newName   = trim($_POST['name'] ?? '');
            $newEmail  = trim($_POST['email'] ?? '');
            $newPhone  = trim($_POST['phone'] ?? '');
            $standing  = $_POST['standing'] ?? 'good';
            $standingNote = trim($_POST['standing_reason'] ?? '');
            $allowedStandings = ['good', 'warning', 'restricted', 'suspended', 'under_review'];
            if (!in_array($standing, $allowedStandings, true)) $standing = 'good';

            $roleStmt = db()->prepare('SELECT id FROM roles WHERE name = ?');
            $roleStmt->execute([$role]);
            $roleId = $roleStmt->fetchColumn();
            if (!$roleId) {
                throw new RuntimeException('Invalid account role.');
            }
            db()->beginTransaction();
            db()->prepare('DELETE FROM user_roles WHERE user_id = ?')->execute([$id]);
            db()->prepare('INSERT INTO user_roles (user_id, role_id, assigned_by) VALUES (?, ?, ?)')->execute([$id, $roleId, $user['id']]);
            db()->prepare('UPDATE users SET location_id = ?, name = ?, email = ?, phone = ? WHERE id = ?')->execute([$locId, $newName ?: null, $newEmail ?: null, $newPhone ?: null, $id]);
            // Upsert account standing
            $existsStmt = db()->prepare('SELECT COUNT(*) FROM customer_account_standing WHERE user_id = ?');
            $existsStmt->execute([$id]);
            if ((int)$existsStmt->fetchColumn() > 0) {
                db()->prepare('UPDATE customer_account_standing SET standing = ?, reason = ?, reviewed_by = ? WHERE user_id = ?')
                    ->execute([$standing, $standingNote ?: null, (int)$user['id'], $id]);
            } else {
                db()->prepare('INSERT INTO customer_account_standing (user_id, standing, reason, reviewed_by) VALUES (?, ?, ?, ?)')
                    ->execute([$id, $standing, $standingNote ?: null, (int)$user['id']]);
            }
            db()->commit();
            write_audit(db(), (int)$user['id'], 'user_settings_updated', 'users', $id, "Role: {$role}, Hub: " . ($locId ?: 'Global') . ", Standing: {$standing}");
            entity_log_change(db(), 'users', $id, 'UPDATE', null, ['role' => $role, 'location_id' => $locId, 'standing' => $standing], (int)$user['id']);
            $message = 'User settings updated successfully.';
        }
    } elseif ($action === 'role') {
        $simpleAllowedRoles = is_super_admin($user) ? ['customer', 'staff', 'admin', 'super_admin'] : ['customer', 'staff'];
        if (!in_array($role, $simpleAllowedRoles, true)) {
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
    } elseif ($action === 'verify_statutory') {
        $verId = filter_var($_POST['verification_id'] ?? null, FILTER_VALIDATE_INT);
        if ($verId) {
            db()->prepare("UPDATE customer_eligibility_verifications SET verification_status = 'verified', verified_by = ?, verified_at = NOW(), rejection_reason = NULL WHERE id = ?")
                ->execute([(int)$user['id'], $verId]);
            write_audit(db(), (int)$user['id'], 'statutory_id_verified', 'customer_eligibility_verifications', $verId, 'Verified Senior/PWD ID.');
            $message = 'Statutory Senior/PWD ID has been verified and activated.';
        }
    } elseif ($action === 'reject_statutory') {
        $verId = filter_var($_POST['verification_id'] ?? null, FILTER_VALIDATE_INT);
        if ($verId) {
            db()->prepare("UPDATE customer_eligibility_verifications SET verification_status = 'rejected', verified_by = ?, verified_at = NOW(), rejection_reason = 'ID could not be validated.' WHERE id = ?")
                ->execute([(int)$user['id'], $verId]);
            write_audit(db(), (int)$user['id'], 'statutory_id_rejected', 'customer_eligibility_verifications', $verId, 'Rejected Senior/PWD ID.');
            $message = 'Statutory ID submission was rejected.';
        }
    }
}

// Fetch Statutory Senior Citizen / PWD verifications (hub-scoped for branch admins)
$statutoryQueue = [];
try {
    $sqWhere = $adminHubId ? "WHERE u.location_id = {$adminHubId}" : "";
    $statutoryQueue = db()->query(
        "SELECT cev.*, u.name AS user_name, u.email AS user_email
         FROM customer_eligibility_verifications cev
         INNER JOIN users u ON u.id = cev.user_id
         {$sqWhere}
         ORDER BY (cev.verification_status = 'pending') DESC, cev.updated_at DESC
         LIMIT 20"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ignore) {}

// Counts for filter badges
$allLocations = get_active_locations();
$countAll = (int)db()->query('SELECT COUNT(*) FROM users u WHERE u.deleted_at IS NULL' . $hubScope)->fetchColumn();
$countPendingStaff = (int)db()->query("SELECT COUNT(*) FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id INNER JOIN users u ON u.id = ur.user_id WHERE r.name = 'staff' AND u.account_status = 'pending' AND u.deleted_at IS NULL" . $hubScope)->fetchColumn();
$countStaff = (int)db()->query("SELECT COUNT(*) FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id INNER JOIN users u ON u.id = ur.user_id WHERE r.name = 'staff' AND u.deleted_at IS NULL" . $hubScope)->fetchColumn();
$countCustomers = (int)db()->query("SELECT COUNT(*) FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id INNER JOIN users u ON u.id = ur.user_id WHERE r.name = 'customer' AND u.deleted_at IS NULL" . $hubScope)->fetchColumn();
$countAdmins = (int)db()->query("SELECT COUNT(*) FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id INNER JOIN users u ON u.id = ur.user_id WHERE r.name IN ('admin', 'super_admin') AND u.deleted_at IS NULL" . $hubScope)->fetchColumn();
$countSuspended = (int)db()->query("SELECT COUNT(*) FROM users u WHERE u.account_status = 'suspended' AND u.deleted_at IS NULL" . $hubScope)->fetchColumn();
$countArchived = (int)db()->query("SELECT COUNT(*) FROM users u WHERE (u.account_status = 'archived' OR u.deleted_at IS NOT NULL)" . $hubScope)->fetchColumn();

// Build query with filter and search
$whereConditions = [];
$queryParams = [];

if ($filter === 'pending') {
    $whereConditions[] = "r.name = 'staff' AND u.account_status = 'pending' AND u.deleted_at IS NULL";
} elseif ($filter === 'staff') {
    $whereConditions[] = "r.name = 'staff' AND u.deleted_at IS NULL";
} elseif ($filter === 'customer') {
    $whereConditions[] = "r.name = 'customer' AND u.deleted_at IS NULL";
} elseif ($filter === 'admin') {
    $whereConditions[] = "r.name IN ('admin', 'super_admin') AND u.deleted_at IS NULL";
} elseif ($filter === 'suspended') {
    $whereConditions[] = "u.account_status = 'suspended' AND u.deleted_at IS NULL";
} elseif ($filter === 'archived') {
    $whereConditions[] = "(u.account_status = 'archived' OR u.deleted_at IS NOT NULL)";
} else {
    $whereConditions[] = "u.deleted_at IS NULL";
}

if ($search !== '') {
    $whereConditions[] = '(u.name LIKE ? OR u.email LIKE ?)';
    $queryParams[] = '%' . $search . '%';
    $queryParams[] = '%' . $search . '%';
}

if ($adminHubId) {
    $whereConditions[] = "u.location_id = {$adminHubId}";
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

$countSql = "SELECT COUNT(DISTINCT u.id) FROM users u INNER JOIN user_roles ur ON ur.user_id = u.id INNER JOIN roles r ON r.id = ur.role_id {$whereClause}";
$countStmt = db()->prepare($countSql);
$countStmt->execute($queryParams);
$totalUsers = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalUsers / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$usersSql = "SELECT u.id, u.name, u.email, u.phone, u.location_id, u.deleted_at, r.name AS role, u.account_status, u.created_at,
                    l.name AS location_name, l.city AS location_city,
                    cas.standing, cas.reason AS standing_reason
             FROM users u
             INNER JOIN user_roles ur ON ur.user_id = u.id
             INNER JOIN roles r ON r.id = ur.role_id
             LEFT JOIN locations l ON l.id = u.location_id
             LEFT JOIN customer_account_standing cas ON cas.user_id = u.id
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
            Review user profiles, assign operational roles, approve staff registrations, and audit account records.
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

      <?php if (!empty($statutoryQueue)): ?>
      <!-- Statutory Senior Citizen / PWD Verification Queue -->
      <section class="portal-card" style="margin-bottom:20px;" aria-label="Statutory ID verifications">
        <div class="portal-card-header" style="margin-bottom:12px;">
          <div class="portal-card-title-group">
            <h2>Senior Citizen &amp; PWD ID Verifications (RA 9994 / RA 10754)</h2>
            <p>Review customer OSCA/NCDA ID submissions to unlock 20% statutory discounts and 12% PH VAT exemption.</p>
          </div>
          <span class="portal-badge badge-neutral"><?= count($statutoryQueue) ?> Record(s)</span>
        </div>
        <div class="portal-table-container">
          <table class="portal-table">
            <thead>
              <tr>
                <th>Customer</th>
                <th>Privilege Type</th>
                <th>ID Number &amp; Authority</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($statutoryQueue as $sq): ?>
                <tr>
                  <td>
                    <strong><?= e($sq['user_name']) ?></strong>
                    <span style="display:block; font-size:0.74rem; color:#64748b;"><?= e($sq['user_email']) ?></span>
                  </td>
                  <td><?= $sq['eligibility_type'] === 'senior_citizen' ? 'Senior Citizen (RA 9994)' : 'PWD (RA 10754)' ?></td>
                  <td>
                    <code><?= e($sq['id_number']) ?></code>
                    <span style="display:block; font-size:0.74rem; color:#64748b;"><?= e($sq['issuing_authority']) ?></span>
                  </td>
                  <td>
                    <span class="portal-badge <?= $sq['verification_status'] === 'verified' ? 'badge-success' : ($sq['verification_status'] === 'pending' ? 'badge-warning' : 'badge-danger') ?>">
                      <?= e(ucfirst($sq['verification_status'])) ?>
                    </span>
                  </td>
                  <td>
                    <?php if ($sq['verification_status'] !== 'verified'): ?>
                      <form method="post" style="display:inline-flex; gap:6px;">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="id" value="<?= (int)$sq['user_id'] ?>">
                        <input type="hidden" name="verification_id" value="<?= (int)$sq['id'] ?>">
                        <button type="submit" name="action" value="verify_statutory" class="portal-btn-action btn-action-emerald" style="height:26px; padding:0 10px;">Verify ID</button>
                        <button type="submit" name="action" value="reject_statutory" class="portal-btn-action btn-action-slate" style="height:26px; padding:0 8px;">Reject</button>
                      </form>
                    <?php else: ?>
                      <span style="font-size:0.74rem; color:#059669; font-weight:700;">Active Privilege</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
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
          <form method="get" style="display:flex; gap:6px; align-items:center;">
            <?php if ($filter !== 'all'): ?><input type="hidden" name="filter" value="<?= e($filter) ?>"><?php endif; ?>
            <input type="search" name="search" value="<?= e($search) ?>" placeholder="Search name or email..." style="height: 34px; padding: 0 12px; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 0.82rem; min-width: 210px;">
            <button type="submit" class="btn btn-secondary" style="height: 34px; min-height: 34px; padding: 0 14px; font-size: 0.80rem; border-radius: 6px;">Search</button>
            <?php if ($search !== ''): ?>
              <a href="?filter=<?= e($filter) ?>" class="btn btn-outline" style="height: 34px; min-height: 34px; padding: 0 12px; font-size: 0.80rem; border-radius: 6px; display:inline-flex; align-items:center;">Reset</a>
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
            <span>Staff Accounts</span>
            <span class="pill-count"><?= $countStaff ?></span>
          </a>
          <a href="?filter=customer<?= $search !== '' ? '&search=' . urlencode($search) : '' ?>" class="admin-filter-pill <?= $filter === 'customer' ? 'active' : '' ?>">
            <span>Customers</span>
            <span class="pill-count"><?= $countCustomers ?></span>
          </a>
          <?php if ($adminIsSuperAdmin): ?>
          <a href="?filter=admin<?= $search !== '' ? '&search=' . urlencode($search) : '' ?>" class="admin-filter-pill <?= $filter === 'admin' ? 'active' : '' ?>">
            <span>Administrators</span>
            <span class="pill-count"><?= $countAdmins ?></span>
          </a>
          <?php endif; ?>
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
                <th>Role &amp; Operating Hub</th>
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
                      <div class="user-identity-cell">
                        <div class="user-avatar-circle">
                          <?= e(strtoupper(substr($account['name'], 0, 1))) ?>
                        </div>
                        <div class="user-identity-info">
                          <strong><?= e($account['name']) ?></strong>
                          <span><?= e($account['email']) ?></span>
                        </div>
                      </div>
                    </td>

                    <td>
                      <?php if ($account['role'] === 'super_admin'): ?>
                        <span class="role-badge-pill role-badge-admin" style="font-size:0.72rem; background:#4338ca; color:#ffffff;">
                          <span class="role-pill-dot" aria-hidden="true" style="background:#a5b4fc;"></span>
                          <span>Super Administrator</span>
                        </span>
                      <?php elseif ($account['role'] === 'admin'): ?>
                        <span class="role-badge-pill role-badge-admin" style="font-size:0.72rem;">
                          <span class="role-pill-dot" aria-hidden="true"></span>
                          <span>Branch Administrator</span>
                        </span>
                      <?php elseif ($account['role'] === 'staff'): ?>
                        <span class="role-badge-pill role-badge-staff" style="font-size:0.72rem;">
                          <span class="role-pill-dot" aria-hidden="true"></span>
                          <span>Operations Staff</span>
                        </span>
                      <?php else: ?>
                        <span class="portal-badge badge-neutral" style="font-size:0.72rem;">Customer</span>
                      <?php endif; ?>

                      <?php if (!empty($account['location_name'])): ?>
                        <div>
                          <span class="hub-scope-pill" title="Assigned operations hub">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                            <span><?= e($account['location_name']) ?></span>
                          </span>
                        </div>
                      <?php elseif (in_array($account['role'], ['admin', 'super_admin'], true)): ?>
                        <div>
                          <span class="hub-scope-pill is-global" title="Global access across all hubs">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg>
                            <span>Global / All Hubs</span>
                          </span>
                        </div>
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
                        <form method="post" style="display:flex; gap:5px;">
                          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                          <input type="hidden" name="id" value="<?= (int)$account['id'] ?>">
                          <input type="hidden" name="role" value="staff">
                          <button class="portal-btn-action btn-action-emerald" name="action" value="approve" type="submit" style="height:25px; padding:0 8px; font-weight:700;">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                            <span>Approve</span>
                          </button>
                          <button class="portal-btn-action btn-action-slate" name="action" value="reject" type="submit" style="height:25px; padding:0 7px;" onclick="return confirm('Reject this staff application?');">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            <span>Reject</span>
                          </button>
                        </form>
                      <?php elseif ((int)$account['id'] === (int)$user['id']): ?>
                        <span class="badge-self-protected">Current Account</span>
                      <?php else: ?>
                        <?php $canManageThisUser = $adminIsSuperAdmin || !in_array($account['role'], ['admin', 'super_admin'], true); ?>
                        <div class="user-action-group">
                          <?php if ($canManageThisUser): ?>
                          <!-- User Settings Modal Trigger -->
                          <button type="button" class="portal-btn-action btn-action-edit" title="User Settings" onclick="openUserModal(<?= htmlspecialchars(json_encode([
                            'id'             => (int)$account['id'],
                            'name'           => $account['name'],
                            'email'          => $account['email'],
                            'phone'          => $account['phone'] ?? '',
                            'role'           => $account['role'],
                            'location_id'    => (int)($account['location_id'] ?? 0),
                            'standing'       => $account['standing'] ?? 'good',
                            'standing_reason'=> $account['standing_reason'] ?? '',
                          ]), ENT_QUOTES, 'UTF-8') ?>)">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                            <span>User Settings</span>
                          </button>

                          <?php if ($account['account_status'] === 'approved'): ?>
                            <!-- Suspend -->
                            <form method="post" style="display:inline;">
                              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                              <input type="hidden" name="id" value="<?= (int)$account['id'] ?>">
                              <button type="submit" name="action" value="suspend" class="portal-btn-action btn-action-amber" title="Suspend account" onclick="return confirm('Suspend this account? User will be temporarily blocked from booking and signing in.');">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="10" y1="15" x2="10" y2="9"/><line x1="14" y1="15" x2="14" y2="9"/></svg>
                                <span>Suspend</span>
                              </button>
                            </form>
                            <!-- Archive -->
                            <form method="post" style="display:inline;">
                              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                              <input type="hidden" name="id" value="<?= (int)$account['id'] ?>">
                              <button type="submit" name="action" value="archive" class="portal-btn-action btn-action-slate" title="Archive and deactivate" onclick="return confirm('Archive and deactivate this account?');">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/></svg>
                                <span>Archive</span>
                              </button>
                            </form>
                          <?php elseif ($account['account_status'] === 'suspended' || $account['account_status'] === 'archived'): ?>
                            <!-- Restore -->
                            <form method="post" style="display:inline;">
                              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                              <input type="hidden" name="id" value="<?= (int)$account['id'] ?>">
                              <button type="submit" name="action" value="restore" class="portal-btn-action btn-action-emerald" title="Restore account to active status" onclick="return confirm('Restore this account to active status?');">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                <span>Restore</span>
                              </button>
                            </form>
                            <?php if ($account['account_status'] === 'suspended'): ?>
                              <!-- Archive suspended -->
                              <form method="post" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="id" value="<?= (int)$account['id'] ?>">
                                <button type="submit" name="action" value="archive" class="portal-btn-action btn-action-slate" title="Archive account" onclick="return confirm('Archive this suspended account permanently?');">
                                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/></svg>
                                  <span>Archive</span>
                                </button>
                              </form>
                            <?php endif; ?>
                          <?php endif; ?>

                          <!-- Delete -->
                          <form method="post" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="id" value="<?= (int)$account['id'] ?>">
                            <button type="submit" name="action" value="delete" class="portal-btn-action btn-action-coral" title="Delete account" onclick="return confirm('Permanently delete this account? If this user has active or historical rentals, it will be safely archived instead to preserve legal and financial audit trails.');">
                              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                              <span>Delete</span>
                            </button>
                          </form>
                          <?php else: ?>
                            <span style="font-size:0.72rem; color:#94a3b8; font-style:italic;">Super Admin managed</span>
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
          <div style="display:flex; justify-content:center; gap:6px; margin-top:16px;">
            <?php for ($num = 1; $num <= $totalPages; $num++): ?>
              <a href="?page=<?= $num ?>&filter=<?= e($filter) ?><?= $search !== '' ? '&search=' . urlencode($search) : '' ?>" class="btn <?= $num === $page ? 'btn-coral' : 'btn-secondary' ?>" style="min-height:28px; height:28px; padding: 0 10px; font-size: 0.76rem; border-radius:6px;">
                <?= $num ?>
              </a>
            <?php endfor; ?>
          </div>
        <?php endif; ?>

      </section>

    </div>
  </div>
</main>

<!-- Modal for User Settings -->
<div class="ops-modal" id="user-assignment-modal" role="dialog" aria-modal="true" aria-labelledby="assignment-modal-title">
  <div class="ops-modal-backdrop" onclick="closeUserModal();"></div>
  <div class="ops-modal-card" style="width: min(100%, 500px);">
    <div class="ops-modal-header" style="padding:14px 18px;">
      <div>
        <h3 id="assignment-modal-title" style="margin:0; font-size:1.05rem; color:#0f172a;">User Settings</h3>
        <p id="assignment-modal-subtitle" style="margin:2px 0 0; font-size:0.78rem; color:#64748b;">Edit profile, role, hub assignment, and account standing</p>
      </div>
      <button type="button" class="ops-modal-close" onclick="closeUserModal();" aria-label="Close modal">&times;</button>
    </div>

    <form method="post" id="assignment-modal-form" style="display:flex; flex-direction:column; gap:12px; padding:14px 18px;">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="update_user">
      <input type="hidden" name="id" id="modal-user-id" value="">

      <!-- Section: Profile -->
      <p style="margin:0 0 2px; font-size:0.70rem; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:0.06em;">Profile</p>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
        <div>
          <label for="modal-name" style="display:block; font-size:0.74rem; font-weight:700; color:#334155; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.04em;">Full Name</label>
          <input type="text" name="name" id="modal-name" placeholder="Full Name" style="width:100%; height:34px; padding:0 10px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:0.84rem; box-sizing:border-box;">
        </div>
        <div>
          <label for="modal-phone" style="display:block; font-size:0.74rem; font-weight:700; color:#334155; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.04em;">Phone</label>
          <input type="text" name="phone" id="modal-phone" placeholder="+63 9XX XXX XXXX" style="width:100%; height:34px; padding:0 10px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:0.84rem; box-sizing:border-box;">
        </div>
      </div>

      <div>
        <label for="modal-email" style="display:block; font-size:0.74rem; font-weight:700; color:#334155; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.04em;">Email Address</label>
        <input type="email" name="email" id="modal-email" placeholder="user@example.com" style="width:100%; height:34px; padding:0 10px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:0.84rem; box-sizing:border-box;">
      </div>

      <!-- Section: Role & Hub -->
      <p style="margin:4px 0 2px; font-size:0.70rem; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:0.06em;">Access & Assignment</p>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
        <div>
          <label for="modal-role-select" style="display:block; font-size:0.74rem; font-weight:700; color:#334155; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.04em;">System Role</label>
          <select name="role" id="modal-role-select" required style="width:100%; height:34px; padding:0 8px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:0.82rem; background:#ffffff;">
            <option value="customer">Customer</option>
            <option value="staff">Operations Staff</option>
            <?php if ($adminIsSuperAdmin): ?>
              <option value="admin">Branch Administrator</option>
              <option value="super_admin">Super Administrator</option>
            <?php endif; ?>
          </select>
        </div>
        <div>
          <label for="modal-location-select" style="display:block; font-size:0.74rem; font-weight:700; color:#334155; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.04em;">Operating Hub</label>
          <select name="location_id" id="modal-location-select" style="width:100%; height:34px; padding:0 8px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:0.82rem; background:#ffffff;">
            <option value="">Global / All Branches</option>
            <?php foreach ($allLocations as $loc): ?>
              <option value="<?= (int)$loc['id'] ?>">
                <?= e($loc['name']) ?> (<?= e($loc['city']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <!-- Section: Account Standing -->
      <p style="margin:4px 0 2px; font-size:0.70rem; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:0.06em;">Account Standing</p>

      <div>
        <label for="modal-standing" style="display:block; font-size:0.74rem; font-weight:700; color:#334155; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.04em;">Standing Status</label>
        <select name="standing" id="modal-standing" style="width:100%; height:34px; padding:0 8px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:0.82rem; background:#ffffff;">
          <option value="good">Good — No restrictions</option>
          <option value="warning">Warning — Flagged for review</option>
          <option value="restricted">Restricted — Limited booking access</option>
          <option value="under_review">Under Review — Pending investigation</option>
          <option value="suspended">Suspended — Full access block</option>
        </select>
      </div>

      <div>
        <label for="modal-standing-reason" style="display:block; font-size:0.74rem; font-weight:700; color:#334155; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.04em;">Standing Notes <span style="font-weight:400; color:#94a3b8;">(optional)</span></label>
        <textarea name="standing_reason" id="modal-standing-reason" rows="2" placeholder="Reason for this standing status (e.g. incident report, late return, payment dispute)..." style="width:100%; padding:8px 10px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:0.82rem; resize:vertical; box-sizing:border-box; font-family:inherit;"></textarea>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:4px; padding-top:12px; border-top:1px solid #e2e8f0;">
        <button type="button" class="btn btn-secondary" onclick="closeUserModal();" style="height:34px; min-height:34px; padding:0 14px; font-size:0.80rem; border-radius:6px;">Cancel</button>
        <button type="submit" class="btn btn-coral" style="height:34px; min-height:34px; padding:0 16px; font-size:0.80rem; border-radius:6px;">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
function openUserModal(userData) {
  const modal = document.getElementById('user-assignment-modal');
  if (!modal) return;
  document.getElementById('modal-user-id').value        = userData.id;
  document.getElementById('assignment-modal-subtitle').textContent = userData.name + ' (' + userData.email + ')';
  document.getElementById('modal-name').value           = userData.name  || '';
  document.getElementById('modal-email').value          = userData.email || '';
  document.getElementById('modal-phone').value          = userData.phone || '';
  document.getElementById('modal-role-select').value    = userData.role  || 'customer';
  document.getElementById('modal-location-select').value = userData.location_id || '';
  document.getElementById('modal-standing').value       = userData.standing || 'good';
  document.getElementById('modal-standing-reason').value = userData.standing_reason || '';
  modal.classList.add('is-open');
}

function closeUserModal() {
  const modal = document.getElementById('user-assignment-modal');
  if (modal) modal.classList.remove('is-open');
}

document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeUserModal();
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
