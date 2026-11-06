<?php
$workspaceUser = current_user() ?? [];
$workspaceRole = $workspaceUser['role'] ?? '';
$workspaceIsAdmin = $workspaceRole === 'admin';
$workspacePage = basename($_SERVER['PHP_SELF']);
$currentTab = $_GET['tab'] ?? ($_GET['section'] ?? '');

$sidebarStmt = db()->prepare('SELECT id, name, email, profile_image FROM users WHERE id = ? LIMIT 1');
$sidebarStmt->execute([(int)($workspaceUser['id'] ?? 0)]);
$sidebarUserRow = $sidebarStmt->fetch();
$sidebarName = $sidebarUserRow['name'] ?? ($workspaceUser['name'] ?? 'Staff Member');
$sidebarEmail = $sidebarUserRow['email'] ?? ($workspaceUser['email'] ?? '');
$sidebarImage = trim((string)($sidebarUserRow['profile_image'] ?? ''));

// Fetch badge counts
$pendingReservationsCount = 0;
$pendingStaffAccountsCount = 0;
try {
    if (!$workspaceIsAdmin) {
        $pendingReservationsCount = (int)db()->query("SELECT COUNT(*) FROM reservations WHERE status = 'pending'")->fetchColumn();
    } else {
        $pendingStaffAccountsCount = (int)db()->query("SELECT COUNT(*) FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id INNER JOIN users u ON u.id = ur.user_id WHERE r.name = 'staff' AND u.account_status = 'pending'")->fetchColumn();
    }
} catch (Throwable $e) {
    // Fail silently if db query error
}
?>
<aside class="workspace-sidebar portal-customer-sidebar" aria-label="Workspace navigation">
  <!-- Profile Card (Matches Customer Sidebar Identity Frame) -->
  <div class="sidebar-user-card">
    <div class="sidebar-avatar-frame">
      <?php if ($sidebarImage !== ''): ?>
        <span class="user-avatar-img" style="background-image:url('<?= e($sidebarImage) ?>');" role="img" aria-label="<?= e($sidebarName) ?>"></span>
      <?php else: ?>
        <span class="user-avatar-initials" aria-hidden="true"><?= e(strtoupper(substr($sidebarName, 0, 1))) ?></span>
      <?php endif; ?>
      <span class="avatar-status-dot status-good" title="Status: Online Active"></span>
    </div>

    <strong class="sidebar-user-name" title="<?= e($sidebarName) ?>"><?= e($sidebarName) ?></strong>
    <span class="sidebar-user-email" title="<?= e($sidebarEmail) ?>"><?= e($sidebarEmail) ?></span>

    <div class="role-badge-pill <?= $workspaceIsAdmin ? 'role-badge-admin' : 'role-badge-staff' ?>" style="margin-top:10px;">
      <span class="role-pill-dot" aria-hidden="true"></span>
      <span><?= $workspaceIsAdmin ? 'Administrator' : 'Operations Staff' ?></span>
    </div>
  </div>

  <!-- Navigation Menu -->
  <nav class="portal-sidebar-nav">
    <?php if ($workspaceIsAdmin): ?>
      <div class="nav-section-label">SYSTEM OVERSIGHT</div>

      <a data-sidebar-section="overview" class="portal-nav-link <?= $workspacePage === 'admin-dashboard.php' ? 'active' : '' ?>" href="/admin/admin-dashboard.php">
        <span class="nav-link-icon" aria-hidden="true">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
        </span>
        <span class="nav-link-text">Overview</span>
      </a>

      <a data-sidebar-section="fleet" class="portal-nav-link <?= $workspacePage === 'admin-vehicles.php' ? 'active' : '' ?>" href="/admin/admin-vehicles.php">
        <span class="nav-link-icon" aria-hidden="true">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/></svg>
        </span>
        <span class="nav-link-text">Fleet Management</span>
      </a>

      <a data-sidebar-section="rentals" class="portal-nav-link <?= $workspacePage === 'admin-rentals.php' ? 'active' : '' ?>" href="/admin/admin-rentals.php">
        <span class="nav-link-icon" aria-hidden="true">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
        </span>
        <span class="nav-link-text">Rental Oversight</span>
      </a>

      <a data-sidebar-section="maintenance" class="portal-nav-link <?= $workspacePage === 'admin-maintenance.php' ? 'active' : '' ?>" href="/admin/admin-maintenance.php">
        <span class="nav-link-icon" aria-hidden="true">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
        </span>
        <span class="nav-link-text">Maintenance</span>
      </a>

      <a data-sidebar-section="users" class="portal-nav-link <?= $workspacePage === 'admin-users.php' ? 'active' : '' ?>" href="/admin/admin-users.php">
        <span class="nav-link-icon" aria-hidden="true">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
        </span>
        <span class="nav-link-text">User Management</span>
        <?php if ($pendingStaffAccountsCount > 0): ?>
          <span class="nav-badge" title="<?= $pendingStaffAccountsCount ?> pending staff accounts"><?= $pendingStaffAccountsCount ?></span>
        <?php endif; ?>
      </a>

      <a data-sidebar-section="reports" class="portal-nav-link <?= $workspacePage === 'admin-reports.php' ? 'active' : '' ?>" href="/admin/admin-reports.php">
        <span class="nav-link-icon" aria-hidden="true">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
        </span>
        <span class="nav-link-text">Reports</span>
      </a>

    <?php else: ?>
      <!-- Staff Operations Navigation -->
      <div class="nav-section-label">DAILY OPERATIONS</div>

      <a data-sidebar-section="overview" class="portal-nav-link <?= ($workspacePage === 'staff-dashboard.php' && ($currentTab === '' || $currentTab === 'overview')) ? 'active' : '' ?>" href="/staff/staff-dashboard.php?tab=overview">
        <span class="nav-link-icon" aria-hidden="true">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
        </span>
        <span class="nav-link-text">Operations Overview</span>
      </a>

      <a data-sidebar-section="reservations" class="portal-nav-link <?= ($workspacePage === 'staff-dashboard.php' && in_array($currentTab, ['reservations', 'requests'], true)) ? 'active' : '' ?>" href="/staff/staff-dashboard.php?tab=reservations">
        <span class="nav-link-icon" aria-hidden="true">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        </span>
        <span class="nav-link-text">Reservation Approvals</span>
        <?php if ($pendingReservationsCount > 0): ?>
          <span class="nav-badge" title="<?= $pendingReservationsCount ?> pending reservations"><?= $pendingReservationsCount ?></span>
        <?php endif; ?>
      </a>

      <a data-sidebar-section="pickups" class="portal-nav-link <?= ($workspacePage === 'staff-dashboard.php' && in_array($currentTab, ['pickups', 'operations'], true)) ? 'active' : '' ?>" href="/staff/staff-dashboard.php?tab=pickups">
        <span class="nav-link-icon" aria-hidden="true">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/></svg>
        </span>
        <span class="nav-link-text">Pickup Turnover Desk</span>
      </a>

      <a data-sidebar-section="returns" class="portal-nav-link <?= ($workspacePage === 'staff-dashboard.php' && $currentTab === 'returns') ? 'active' : '' ?>" href="/staff/staff-dashboard.php?tab=returns">
        <span class="nav-link-icon" aria-hidden="true">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
        </span>
        <span class="nav-link-text">Returns &amp; Check-in</span>
      </a>

      <a data-sidebar-section="payments" class="portal-nav-link <?= ($workspacePage === 'staff-dashboard.php' && $currentTab === 'payments') ? 'active' : '' ?>" href="/staff/staff-dashboard.php?tab=payments">
        <span class="nav-link-icon" aria-hidden="true">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
        </span>
        <span class="nav-link-text">Payment Collection Desk</span>
      </a>

      <a data-sidebar-section="incidents" class="portal-nav-link <?= ($workspacePage === 'staff-dashboard.php' && $currentTab === 'incidents') ? 'active' : '' ?>" href="/staff/staff-dashboard.php?tab=incidents">
        <span class="nav-link-icon" aria-hidden="true">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        </span>
        <span class="nav-link-text">Incidents &amp; Damages</span>
      </a>
    <?php endif; ?>

    <div class="nav-section-label" style="margin-top:20px;">ACCOUNT &amp; ACCESS</div>

    <a data-sidebar-section="account" class="portal-nav-link <?= $workspacePage === 'workspace-account-settings.php' ? 'active' : '' ?>" href="/workspace/workspace-account-settings.php">
      <span class="nav-link-icon" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
      </span>
      <span class="nav-link-text">Account Settings</span>
    </a>

    <a href="/logout.php" class="portal-nav-link nav-link-logout">
      <span class="nav-link-icon" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      </span>
      <span class="nav-link-text">Sign Out</span>
    </a>
  </nav>
</aside>
