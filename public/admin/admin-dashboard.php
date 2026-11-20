<?php
$title = 'Executive Admin Workspace';
require_once __DIR__ . '/../../config.php';
$user = require_role('admin');

$message = '';
$error = '';

// Handle quick dashboard actions (e.g., direct staff approvals)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);

    try {
        if ($action === 'approve_staff' && $id) {
            $stmt = db()->prepare("UPDATE users u INNER JOIN user_roles ur ON ur.user_id = u.id INNER JOIN roles r ON r.id = ur.role_id SET u.account_status = 'approved' WHERE u.id = ? AND r.name = 'staff'");
            $stmt->execute([$id]);
            write_audit(db(), (int)$user['id'], 'staff_approved', 'users', $id, 'Approved staff registration from dashboard.');
            $message = 'Operations staff account successfully approved.';
        } elseif ($action === 'reject_staff' && $id) {
            $stmt = db()->prepare("UPDATE users u INNER JOIN user_roles ur ON ur.user_id = u.id INNER JOIN roles r ON r.id = ur.role_id SET u.account_status = 'rejected' WHERE u.id = ? AND r.name = 'staff'");
            $stmt->execute([$id]);
            write_audit(db(), (int)$user['id'], 'staff_rejected', 'users', $id, 'Rejected staff registration from dashboard.');
            $message = 'Operations staff account registration was rejected.';
        }
    } catch (Throwable $e) {
        $error = 'Action could not be completed: ' . $e->getMessage();
    }
}

// Fleet & Regional Venue Scoping
$allLocations = get_active_locations();
if (!is_super_admin($user)) {
    $selectedHubId = current_user_location_id($user);
} else {
    $selectedHubId = filter_input(INPUT_GET, 'hub', FILTER_VALIDATE_INT);
}
$selectedHubName = '';
if ($selectedHubId) {
    foreach ($allLocations as $loc) {
        if ((int)$loc['id'] === $selectedHubId) {
            $selectedHubName = $loc['name'] . ' (' . $loc['city'] . ')';
            break;
        }
    }
}
$vehHubSql = $selectedHubId ? " AND current_location_id = {$selectedHubId}" : "";
$resHubSql = $selectedHubId ? " AND pickup_location_id = {$selectedHubId}" : "";

// Fleet Statistics
$totalVehicles = (int)db()->query("SELECT COUNT(*) FROM vehicles WHERE deleted_at IS NULL {$vehHubSql}")->fetchColumn();
$operationalVehicles = (int)db()->query("SELECT COUNT(*) FROM vehicles WHERE status = 'operational' AND deleted_at IS NULL {$vehHubSql}")->fetchColumn();
$maintenanceVehicles = (int)db()->query("SELECT COUNT(*) FROM vehicles WHERE status = 'maintenance' AND deleted_at IS NULL {$vehHubSql}")->fetchColumn();
$retiredVehicles = (int)db()->query("SELECT COUNT(*) FROM vehicles WHERE status = 'retired' AND deleted_at IS NULL {$vehHubSql}")->fetchColumn();

// User Statistics
$totalCustomers = (int)db()->query("SELECT COUNT(*) FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id INNER JOIN users u ON u.id = ur.user_id WHERE r.name = 'customer' AND u.deleted_at IS NULL")->fetchColumn();
$activeStaff = (int)db()->query("SELECT COUNT(*) FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id INNER JOIN users u ON u.id = ur.user_id WHERE r.name = 'staff' AND u.account_status = 'approved' AND u.deleted_at IS NULL")->fetchColumn();
$pendingStaff = (int)db()->query("SELECT COUNT(*) FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id INNER JOIN users u ON u.id = ur.user_id WHERE r.name = 'staff' AND u.account_status = 'pending' AND u.deleted_at IS NULL")->fetchColumn();

// Rental & Booking Statistics
$confirmedBookings = (int)db()->query("SELECT COUNT(*) FROM reservations WHERE status = 'confirmed' AND deleted_at IS NULL {$resHubSql}")->fetchColumn();
$ongoingRentals = (int)db()->query("SELECT COUNT(*) FROM rentals rt INNER JOIN reservations r ON r.id = rt.reservation_id WHERE rt.status = 'ongoing' {$resHubSql}")->fetchColumn();
$activeTrips = $confirmedBookings + $ongoingRentals;
$pendingReservations = (int)db()->query("SELECT COUNT(*) FROM reservations WHERE status = 'pending' AND deleted_at IS NULL {$resHubSql}")->fetchColumn();

// Oversight Queue Counters
$pendingCancellations = (int)db()->query("SELECT COUNT(*) FROM reservation_cancellation_requests WHERE status = 'requested'")->fetchColumn();
$unreviewedDamage = (int)db()->query("SELECT COUNT(*) FROM damage_reports WHERE status IN ('reported', 'under_review')")->fetchColumn();
$pendingPenalties = (int)db()->query("SELECT COUNT(*) FROM penalty_events WHERE status = 'pending'")->fetchColumn();
$openIncidents = (int)db()->query("SELECT COUNT(*) FROM rental_incidents WHERE status IN ('open', 'contact_attempted', 'escalated')")->fetchColumn();
$totalActionQueue = $pendingStaff + $pendingCancellations + $unreviewedDamage + $pendingPenalties + $openIncidents;

// Financial Statistics
$outstanding = (float)db()->query(
    "SELECT COALESCE((SELECT SUM(amount) FROM rental_charges), 0)
          - COALESCE((SELECT SUM(amount) FROM payments), 0)"
)->fetchColumn();
$totalRevenue = (float)db()->query("SELECT COALESCE(SUM(amount), 0) FROM payments")->fetchColumn();

// Pending Staff Members List (for spotlight / approvals)
$pendingStaffList = db()->query(
    "SELECT u.id, u.name, u.email, u.created_at
     FROM users u
     INNER JOIN user_roles ur ON ur.user_id = u.id
     INNER JOIN roles r ON r.id = ur.role_id
     WHERE r.name = 'staff' AND u.account_status = 'pending'
     ORDER BY u.created_at ASC
     LIMIT 4"
)->fetchAll();

// Pending Damage Reports
$pendingDamageList = db()->query(
    "SELECT d.id, d.rental_id, d.description, d.estimated_cost, d.created_at,
            res.reference_number, u.name AS customer_name, v.brand, v.model
     FROM damage_reports d
     INNER JOIN rentals r ON r.id = d.rental_id
     INNER JOIN reservations res ON res.id = r.reservation_id
     INNER JOIN users u ON u.id = r.customer_id
     INNER JOIN vehicles v ON v.id = r.vehicle_id
     WHERE d.status IN ('reported', 'under_review')
     ORDER BY d.created_at ASC
     LIMIT 3"
)->fetchAll();

// Pending Cancellation Requests
$pendingCancelList = db()->query(
    "SELECT cr.id, cr.reservation_id, cr.reason, cr.requested_at,
            res.reference_number, u.name AS customer_name
     FROM reservation_cancellation_requests cr
     INNER JOIN reservations res ON res.id = cr.reservation_id
     INNER JOIN users u ON u.id = res.customer_id
     WHERE cr.status = 'requested'
     ORDER BY cr.requested_at ASC
     LIMIT 3"
)->fetchAll();

// Recent Audit Trail
$auditLogs = db()->query(
    "SELECT a.id, a.action, a.table_name, a.record_id, a.description, a.created_at,
            u.name AS user_name, u.email AS user_email
     FROM audit_logs a
     LEFT JOIN users u ON u.id = a.user_id
     ORDER BY a.created_at DESC
     LIMIT 6"
)->fetchAll();

// Fleet category breakdown
$categories = db()->query(
    "SELECT vc.name, COUNT(v.id) AS total_in_cat,
            SUM(CASE WHEN v.status = 'operational' THEN 1 ELSE 0 END) AS operational_in_cat
     FROM vehicle_categories vc
     LEFT JOIN vehicles v ON v.category_id = vc.id
     GROUP BY vc.id, vc.name
     ORDER BY total_in_cat DESC"
)->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<main class="portal-customer-page">
  <div class="portal-customer-layout">

    <!-- Unified Sidebar Navigation -->
    <?php include __DIR__ . '/../../includes/workspace-sidebar.php'; ?>

    <!-- Main Workspace Content -->
    <div class="customer-main-content">

      <!-- Executive Hero Greeting Banner -->
      <section class="portal-hero-banner" aria-label="Administrator greeting">
        <div class="portal-hero-text">
          <div class="portal-hero-eyebrow">
            <span class="pulse-indicator" aria-hidden="true"></span>
            <span>SYSTEM ADMINISTRATOR WORKSPACE</span>
          </div>
          <h1 class="portal-hero-title">Executive Fleet & Operations Overview</h1>
          <p class="portal-hero-subtitle">
            Welcome, <?= e(explode(' ', $user['name'])[0]) ?>. Overseeing <?= $totalVehicles ?> vehicles, <?= $activeTrips ?> active trips, and <?= $totalCustomers ?> registered customer accounts.
          </p>
        </div>
        <div class="portal-hero-actions">
          <a href="/admin/admin-vehicles.php#vehicle-form" class="btn btn-coral">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <span>Add Vehicle</span>
          </a>
          <a href="/admin/admin-vehicles.php" class="btn btn-outline-white">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/></svg>
            <span>Fleet Inventory</span>
          </a>
          <a href="/admin/admin-rentals.php" class="btn btn-outline-white">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            <span>Rental Oversight</span>
          </a>
        </div>
      </section>

      <!-- Regional Hub Venue Scope Selector -->
      <section class="portal-card" style="padding:12px 18px; margin-bottom:20px; background:#ffffff; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
        <div style="display:flex; align-items:center; gap:8px;">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#0284c7" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
          <strong style="font-size:0.86rem; color:#0f172a;">Regional Operations Scope:</strong>
          <span style="font-size:0.82rem; color:#64748b;">
            <?= $selectedHubId ? e($selectedHubName) : 'All Operations Hubs (Network View)' ?>
          </span>
        </div>

        <?php if (is_super_admin($user)): ?>
          <form method="get" style="display:flex; align-items:center; gap:8px;">
            <label for="admin-hub-filter" style="font-size:0.8rem; font-weight:700; color:#334155; margin:0;">Filter Hub:</label>
            <select id="admin-hub-filter" name="hub" onchange="this.form.submit()" style="font-size:0.84rem; padding:6px 12px; border:1px solid #cbd5e1; border-radius:8px; background:#f8fafc; font-weight:600;">
              <option value="">All Regional Hubs (Global)</option>
              <?php foreach ($allLocations as $loc): ?>
                <option value="<?= (int)$loc['id'] ?>" <?= $selectedHubId === (int)$loc['id'] ? 'selected' : '' ?>>
                  <?= e($loc['name']) ?> (<?= e($loc['city']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
            <?php if ($selectedHubId): ?>
              <a href="/admin/admin-dashboard.php" class="btn btn-secondary" style="padding:4px 10px; font-size:0.75rem; border-radius:6px; text-decoration:none;">Reset</a>
            <?php endif; ?>
          </form>
        <?php else: ?>
          <span class="portal-badge badge-neutral" style="padding:4px 12px; font-size:0.76rem;">Assigned Branch Depot</span>
        <?php endif; ?>
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

      <!-- 4 High-Impact KPI Metrics Cards -->
      <section class="portal-kpi-grid" aria-label="System key performance indicators">
        
        <!-- Fleet Capacity -->
        <a href="/admin/admin-vehicles.php" class="portal-kpi-card">
          <div class="kpi-header">
            <span class="kpi-label">Fleet Capacity</span>
            <div class="kpi-icon-pill" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/></svg>
            </div>
          </div>
          <div class="kpi-value"><?= $operationalVehicles ?> <span style="font-size:0.95rem; font-weight:600; color:#64748b;">/ <?= $totalVehicles ?></span></div>
          <div class="kpi-subtext">
            <span><?= $maintenanceVehicles ?> in maintenance · <?= $retiredVehicles ?> retired</span>
          </div>
        </a>

        <!-- Active Trips -->
        <a href="/admin/admin-rentals.php" class="portal-kpi-card">
          <div class="kpi-header">
            <span class="kpi-label">Active Trips</span>
            <div class="kpi-icon-pill" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
          </div>
          <div class="kpi-value"><?= $activeTrips ?></div>
          <div class="kpi-subtext">
            <span><?= $confirmedBookings ?> confirmed · <?= $ongoingRentals ?> on the road</span>
          </div>
        </a>

        <!-- Outstanding Balances -->
        <a href="/admin/admin-rentals.php" class="portal-kpi-card">
          <div class="kpi-header">
            <span class="kpi-label">Outstanding Balance</span>
            <div class="kpi-icon-pill" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            </div>
          </div>
          <div class="kpi-value">₱<?= number_format(max(0, $outstanding), 0) ?></div>
          <div class="kpi-subtext">
            <span>₱<?= number_format($totalRevenue, 0) ?> collected to date</span>
          </div>
        </a>

        <!-- Action Queue -->
        <a href="/admin/admin-users.php" class="portal-kpi-card" style="<?= $totalActionQueue > 0 ? 'border-color: #fecaca; background: #fffcfc;' : '' ?>">
          <div class="kpi-header">
            <span class="kpi-label" style="<?= $totalActionQueue > 0 ? 'color:#b91c1c;' : '' ?>">Action Queue</span>
            <div class="kpi-icon-pill" style="<?= $totalActionQueue > 0 ? 'background:#fef2f2; color:#b91c1c;' : '' ?>" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            </div>
          </div>
          <div class="kpi-value" style="<?= $totalActionQueue > 0 ? 'color:#b91c1c;' : '' ?>"><?= $totalActionQueue ?></div>
          <div class="kpi-subtext">
            <span><?= $pendingStaff ?> staff · <?= $unreviewedDamage ?> damage · <?= $pendingCancellations ?> cancel</span>
          </div>
        </a>

      </section>

      <!-- Spotlight Hero: Prioritized Administrative Action -->
      <?php if (!empty($pendingStaffList)): ?>
        <?php $topStaff = $pendingStaffList[0]; ?>
        <section class="portal-spotlight-card" style="margin-top: 24px; border-color: #fde68a;" aria-label="Administrative spotlight alert">
          <div class="spotlight-accent-bar" style="background: #f59e0b;"></div>
          
          <div class="spotlight-vehicle-media" style="display:flex; flex-direction:column; align-items:center; justify-content:center; background:#161a22; color:#ffffff; padding:20px; text-align:center;">
            <div style="width:54px; height:54px; border-radius:50%; background:#262d3d; border:2px solid #3b4559; display:flex; align-items:center; justify-content:center; font-size:1.4rem; font-weight:800; margin-bottom:8px;">
              <?= e(strtoupper(substr($topStaff['name'], 0, 1))) ?>
            </div>
            <strong style="font-size:0.95rem; margin-bottom:2px;"><?= e($topStaff['name']) ?></strong>
            <span style="font-size:0.75rem; color:#94a3b8;"><?= e($topStaff['email']) ?></span>
            <span class="portal-badge badge-warning" style="margin-top:10px;">Pending Review</span>
          </div>

          <div class="spotlight-content">
            <div class="spotlight-header-row">
              <div class="spotlight-title-group">
                <span style="font-size:0.74rem; font-weight:800; text-transform:uppercase; letter-spacing:0.05em; color:#d97706;">PENDING OPERATIONS STAFF APPLICATION</span>
                <h3 style="margin-top:4px;">Approve Staff Workspace Access</h3>
                <span class="spotlight-reference">Registered on <?= e(date('F j, Y · g:i A', strtotime($topStaff['created_at']))) ?></span>
              </div>
              <div class="spotlight-status-group">
                <span class="portal-badge badge-warning"><?= count($pendingStaffList) ?> Staff Request(s) Total</span>
              </div>
            </div>

            <p style="font-size:0.86rem; color:#475569; margin:0; line-height:1.5;">
              This staff member has registered for operational access to verify reservations, manage turnovers, and handle counter check-ins. Review their credentials and grant system permissions.
            </p>

            <div class="spotlight-action-row" style="margin-top: 8px;">
              <form method="post" style="display:inline-flex; gap:10px;">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="id" value="<?= (int)$topStaff['id'] ?>">
                <button type="submit" name="action" value="approve_staff" class="btn btn-coral" style="padding: 9px 18px; font-size:0.84rem; display:inline-flex; align-items:center; gap:6px;">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                  <span>Approve Access</span>
                </button>
                <button type="submit" name="action" value="reject_staff" class="btn btn-secondary" style="padding: 9px 16px; font-size:0.84rem; display:inline-flex; align-items:center; gap:6px;" onclick="return confirm('Reject this staff application?');">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                  <span>Reject</span>
                </button>
              </form>
              <a href="/admin/admin-users.php" class="text-link" style="font-size:0.84rem; font-weight:700;">View All Accounts &rarr;</a>
            </div>
          </div>
        </section>

      <?php elseif (!empty($pendingDamageList)): ?>
        <?php $topDamage = $pendingDamageList[0]; ?>
        <section class="portal-spotlight-card" style="margin-top: 24px; border-color: #fecaca;" aria-label="Damage review spotlight">
          <div class="spotlight-accent-bar" style="background: #e53e3e;"></div>
          
          <div class="spotlight-vehicle-media" style="display:flex; flex-direction:column; align-items:center; justify-content:center; background:#161a22; color:#ffffff; padding:20px; text-align:center;">
            <div style="width:48px; height:48px; border-radius:50%; background:#2d1616; color:#ef4444; display:flex; align-items:center; justify-content:center; margin-bottom:8px;">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </div>
            <strong style="font-size:0.92rem;"><?= e($topDamage['brand'] . ' ' . $topDamage['model']) ?></strong>
            <span style="font-size:0.75rem; color:#94a3b8;"><?= e($topDamage['reference_number']) ?></span>
            <span class="portal-badge badge-danger" style="margin-top:10px;">Est. ₱<?= number_format((float)$topDamage['estimated_cost'], 2) ?></span>
          </div>

          <div class="spotlight-content">
            <div class="spotlight-header-row">
              <div class="spotlight-title-group">
                <span style="font-size:0.74rem; font-weight:800; text-transform:uppercase; letter-spacing:0.05em; color:#b91c1c;">DAMAGE REPORT REVIEW REQUIRED</span>
                <h3 style="margin-top:4px;">Vehicle Return Incident · <?= e($topDamage['customer_name']) ?></h3>
                <span class="spotlight-reference"><?= e($topDamage['description']) ?></span>
              </div>
            </div>

            <p style="font-size:0.86rem; color:#475569; margin:0; line-height:1.5;">
              A damage inspection report was recorded upon return and is awaiting administrative determination to assess charges or grant a fee waiver.
            </p>

            <div class="spotlight-action-row" style="margin-top: 8px;">
              <a href="/admin/admin-rentals.php#damage-section" class="btn btn-coral" style="padding: 9px 18px; font-size:0.84rem;">Review in Rental Oversight</a>
              <span style="font-size:0.8rem; color:#64748b;">Reported <?= e(date('M j, Y', strtotime($topDamage['created_at']))) ?></span>
            </div>
          </div>
        </section>

      <?php else: ?>
        <!-- System Health Spotlight -->
        <section class="portal-spotlight-card" style="margin-top: 24px; border-color: #e2e8f0;" aria-label="System status overview">
          <div class="spotlight-accent-bar" style="background: #10b981;"></div>
          
          <div class="spotlight-vehicle-media" style="display:flex; flex-direction:column; align-items:center; justify-content:center; background:#161a22; color:#ffffff; padding:20px; text-align:center;">
            <div style="width:48px; height:48px; border-radius:50%; background:#132e22; color:#10b981; display:flex; align-items:center; justify-content:center; margin-bottom:8px;">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <strong style="font-size:0.95rem;">Fleet Health Nominal</strong>
            <span style="font-size:0.75rem; color:#94a3b8;"><?= round(($operationalVehicles / max(1, $totalVehicles)) * 100) ?>% Operational</span>
            <span class="portal-badge badge-success" style="margin-top:10px;">All Systems Active</span>
          </div>

          <div class="spotlight-content">
            <div class="spotlight-header-row">
              <div class="spotlight-title-group">
                <span style="font-size:0.74rem; font-weight:800; text-transform:uppercase; letter-spacing:0.05em; color:#059669;">OPERATIONAL STABILITY</span>
                <h3 style="margin-top:4px;">No Critical Administrative Bottlenecks</h3>
                <span class="spotlight-reference">All staff registration requests have been processed. Fleet turnover is running on schedule.</span>
              </div>
            </div>

            <div class="spotlight-schedule-grid">
              <div class="spotlight-schedule-item">
                <dt>Operational Vehicles</dt>
                <dd><?= $operationalVehicles ?> ready for dispatch</dd>
              </div>
              <div class="spotlight-schedule-item">
                <dt>Under Servicing</dt>
                <dd><?= $maintenanceVehicles ?> vehicle(s) scheduled</dd>
              </div>
            </div>

            <div class="spotlight-action-row">
              <div class="spotlight-buttons">
                <a href="/admin/admin-vehicles.php" class="btn btn-secondary" style="padding: 8px 16px; font-size:0.84rem;">Manage Vehicles</a>
                <a href="/admin/admin-reports.php" class="btn btn-outline" style="padding: 8px 16px; font-size:0.84rem;">View Analytics</a>
              </div>
            </div>
          </div>
        </section>
      <?php endif; ?>

      <!-- Executive Management Modules Hub -->
      <section style="margin-top: 32px;" aria-label="System management modules">
        <div class="portal-card-header" style="margin-bottom: 14px;">
          <div class="portal-card-title-group">
            <h2>Administrative Management Hub</h2>
            <p>Direct navigation to system configuration, fleet assets, rental oversight, and accounts.</p>
          </div>
        </div>

        <div class="admin-modules-grid">

          <!-- Fleet Management -->
          <a href="/admin/admin-vehicles.php" class="admin-module-card">
            <div>
              <div class="admin-module-header">
                <div class="admin-module-icon">
                  <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/></svg>
                </div>
                <span class="admin-module-arrow">&rarr;</span>
              </div>
              <h3 class="admin-module-title">Fleet Management</h3>
              <p class="admin-module-desc">Add new vehicles, edit specifications, configure daily pricing, and set operational readiness.</p>
            </div>
            <div class="admin-module-footer">
              <span><?= $totalVehicles ?> Total Fleet</span>
              <span class="portal-badge badge-neutral"><?= $operationalVehicles ?> Active</span>
            </div>
          </a>

          <!-- Rental Oversight -->
          <a href="/admin/admin-rentals.php" class="admin-module-card">
            <div>
              <div class="admin-module-header">
                <div class="admin-module-icon">
                  <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                </div>
                <span class="admin-module-arrow">&rarr;</span>
              </div>
              <h3 class="admin-module-title">Rental Oversight</h3>
              <p class="admin-module-desc">Review bookings, handle customer cancellation requests, resolve damage disputes, and issue refunds.</p>
            </div>
            <div class="admin-module-footer">
              <span><?= $activeTrips ?> Active Trips</span>
              <?php if ($unreviewedDamage + $pendingCancellations > 0): ?>
                <span class="portal-badge badge-warning"><?= $unreviewedDamage + $pendingCancellations ?> Reviews</span>
              <?php else: ?>
                <span class="portal-badge badge-success">Clear</span>
              <?php endif; ?>
            </div>
          </a>

          <!-- User Management -->
          <a href="/admin/admin-users.php" class="admin-module-card">
            <div>
              <div class="admin-module-header">
                <div class="admin-module-icon">
                  <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </div>
                <span class="admin-module-arrow">&rarr;</span>
              </div>
              <h3 class="admin-module-title">User Management</h3>
              <p class="admin-module-desc">Review customer and staff accounts, assign roles, approve staff registrations, and control access.</p>
            </div>
            <div class="admin-module-footer">
              <span><?= $totalCustomers + $activeStaff ?> Accounts</span>
              <?php if ($pendingStaff > 0): ?>
                <span class="portal-badge badge-warning"><?= $pendingStaff ?> Pending</span>
              <?php else: ?>
                <span class="portal-badge badge-success">All Approved</span>
              <?php endif; ?>
            </div>
          </a>

          <!-- Maintenance Scheduling -->
          <a href="/admin/admin-maintenance.php" class="admin-module-card">
            <div>
              <div class="admin-module-header">
                <div class="admin-module-icon">
                  <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                </div>
                <span class="admin-module-arrow">&rarr;</span>
              </div>
              <h3 class="admin-module-title">Maintenance</h3>
              <p class="admin-module-desc">Schedule routine vehicle maintenance, record inspections, and manage scheduled vehicle downtime.</p>
            </div>
            <div class="admin-module-footer">
              <span><?= $maintenanceVehicles ?> Active Servicing</span>
              <span class="portal-badge badge-neutral">Schedules</span>
            </div>
          </a>

          <!-- Performance Reports -->
          <a href="/admin/admin-reports.php" class="admin-module-card">
            <div>
              <div class="admin-module-header">
                <div class="admin-module-icon">
                  <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                </div>
                <span class="admin-module-arrow">&rarr;</span>
              </div>
              <h3 class="admin-module-title">Reports & Analytics</h3>
              <p class="admin-module-desc">Analyze fleet revenue, booking frequencies, popular categories, penalty metrics, and audit history.</p>
            </div>
            <div class="admin-module-footer">
              <span>Revenue & Volume</span>
              <span class="portal-badge badge-brand">Analytics</span>
            </div>
          </a>

          <!-- Workspace Security -->
          <a href="/workspace/workspace-account-settings.php" class="admin-module-card">
            <div>
              <div class="admin-module-header">
                <div class="admin-module-icon">
                  <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-1.8 1.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-2.5V20a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1-1.8-1.8.1-.1A1.7 1.7 0 0 0 8.1 15a1.7 1.7 0 0 0-1.6-1H6v-2.5h.2a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1 1.8-1.8.1.1a1.7 1.7 0 0 0 1.9.3 1.7 1.7 0 0 0 1-1.6v-.2h2.5v.2a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1 1.8 1.8-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2V14h-.2a1.7 1.7 0 0 0-1.6 1Z"/></svg>
                </div>
                <span class="admin-module-arrow">&rarr;</span>
              </div>
              <h3 class="admin-module-title">Workspace Profile</h3>
              <p class="admin-module-desc">Manage your administrator identity, update notification preferences, and change security credentials.</p>
            </div>
            <div class="admin-module-footer">
              <span><?= e($user['email']) ?></span>
              <span class="portal-badge badge-neutral">Security</span>
            </div>
          </a>

        </div>
      </section>

      <!-- Split Operational Feeds: Pending Approvals & Live Audit Trail -->
      <section class="admin-split-grid" aria-label="System feeds">

        <!-- Left: Actionable Administrative Queue -->
        <div class="portal-card">
          <div class="portal-card-header">
            <div class="portal-card-title-group">
              <h2>Action Required Queue</h2>
              <p>Staff registrations, cancellation requests, and damage escalations.</p>
            </div>
            <span class="portal-badge <?= $totalActionQueue > 0 ? 'badge-warning' : 'badge-success' ?>">
              <?= $totalActionQueue ?> Pending
            </span>
          </div>

          <?php if (empty($pendingStaffList) && empty($pendingCancelList) && empty($pendingDamageList)): ?>
            <div class="ops-empty-state" style="padding: 40px 16px; text-align: center;">
              <div class="ops-empty-icon" aria-hidden="true">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
              </div>
              <h3 style="font-size: 1rem; font-weight: 700; color: #0f172a; margin: 0 0 4px;">Queue Completely Cleared</h3>
              <p style="font-size: 0.82rem; color: #64748b; margin: 0;">No staff approvals, cancellation reviews, or damage assessments pending.</p>
            </div>
          <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 14px;">
              
              <!-- Pending Staff Approvals -->
              <?php foreach ($pendingStaffList as $staff): ?>
                <div style="background: #fffcf0; border: 1px solid #fde68a; border-radius: 12px; padding: 14px 16px; display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap;">
                  <div>
                    <span class="portal-badge badge-warning" style="font-size: 0.68rem; margin-bottom: 4px;">Staff Registration</span>
                    <strong style="display: block; font-size: 0.92rem; color: #0f172a;"><?= e($staff['name']) ?></strong>
                    <span style="font-size: 0.78rem; color: #64748b;"><?= e($staff['email']) ?> · Applied <?= e(date('M j, Y', strtotime($staff['created_at']))) ?></span>
                  </div>
                  <form method="post" style="display: flex; gap: 8px;">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="id" value="<?= (int)$staff['id'] ?>">
                    <button type="submit" name="action" value="approve_staff" class="btn btn-coral" style="padding: 6px 12px; font-size: 0.78rem;">Approve</button>
                    <button type="submit" name="action" value="reject_staff" class="btn btn-secondary" style="padding: 6px 12px; font-size: 0.78rem;" onclick="return confirm('Reject this staff application?');">Reject</button>
                  </form>
                </div>
              <?php endforeach; ?>

              <!-- Pending Cancellations -->
              <?php foreach ($pendingCancelList as $cancel): ?>
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px 16px; display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap;">
                  <div>
                    <span class="portal-badge badge-neutral" style="font-size: 0.68rem; margin-bottom: 4px;">Cancellation Request</span>
                    <strong style="display: block; font-size: 0.92rem; color: #0f172a;"><?= e($cancel['reference_number']) ?> · <?= e($cancel['customer_name']) ?></strong>
                    <span style="font-size: 0.78rem; color: #64748b;">Reason: <?= e($cancel['reason'] ?: 'No reason specified') ?></span>
                  </div>
                  <a href="/admin/admin-rentals.php" class="btn btn-secondary" style="padding: 6px 12px; font-size: 0.78rem;">Review in Rentals</a>
                </div>
              <?php endforeach; ?>

              <!-- Pending Damage -->
              <?php foreach ($pendingDamageList as $damage): ?>
                <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 12px; padding: 14px 16px; display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap;">
                  <div>
                    <span class="portal-badge badge-danger" style="font-size: 0.68rem; margin-bottom: 4px;">Damage Report</span>
                    <strong style="display: block; font-size: 0.92rem; color: #0f172a;"><?= e($damage['brand'] . ' ' . $damage['model']) ?> · <?= e($damage['customer_name']) ?></strong>
                    <span style="font-size: 0.78rem; color: #64748b;">Est. Cost: ₱<?= number_format((float)$damage['estimated_cost'], 2) ?></span>
                  </div>
                  <a href="/admin/admin-rentals.php" class="btn btn-coral" style="padding: 6px 12px; font-size: 0.78rem;">Assess</a>
                </div>
              <?php endforeach; ?>

            </div>
          <?php endif; ?>
        </div>

        <!-- Right: Real-time System Audit Trail -->
        <div class="portal-card">
          <div class="portal-card-header">
            <div class="portal-card-title-group">
              <h2>Recent Audit Trail</h2>
              <p>Logged activity from administrators and staff members.</p>
            </div>
            <a href="/admin/admin-reports.php" class="text-link" style="font-size: 0.8rem; font-weight: 700;">Full Log &rarr;</a>
          </div>

          <div class="audit-list">
            <?php if (empty($auditLogs)): ?>
              <p style="font-size: 0.82rem; color: #64748b; padding: 12px 0;">No system audit entries recorded yet.</p>
            <?php else: ?>
              <?php foreach ($auditLogs as $log): ?>
                <div class="audit-item">
                  <div class="audit-icon-frame" aria-hidden="true">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/></svg>
                  </div>
                  <div class="audit-details">
                    <div class="audit-action-title">
                      <span><?= e(ucwords(str_replace('_', ' ', $log['action']))) ?></span>
                      <span class="audit-time"><?= e(date('M j, g:i A', strtotime($log['created_at']))) ?></span>
                    </div>
                    <div class="audit-desc">
                      <?= e($log['user_name'] ?? 'System') ?> &middot; <?= e($log['description'] ?: 'Record update on ' . $log['table_name']) ?>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

      </section>

    </div>
  </div>
</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
