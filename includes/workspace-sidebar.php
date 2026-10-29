<?php
$workspaceUser = current_user() ?? [];
$workspaceRole = $workspaceUser['role'] ?? '';
$workspaceIsAdmin = $workspaceRole === 'admin';
$workspacePage = basename($_SERVER['PHP_SELF']);
?>
<aside class="workspace-sidebar" aria-label="Workspace navigation">
  <div class="workspace-profile">
    <span class="profile-avatar"><?= e(strtoupper(substr($workspaceUser['name'] ?? 'U', 0, 1))) ?></span>
    <div><strong><?= e($workspaceUser['name'] ?? 'Workspace user') ?></strong><span><?= $workspaceIsAdmin ? 'Administrator' : 'Faculty / Staff' ?></span></div>
  </div>
  <nav class="sidebar-nav">
    <a data-sidebar-section="overview" class="<?= in_array($workspacePage, ['admin-dashboard.php', 'staff-dashboard.php'], true) ? 'active' : '' ?>" href="<?= $workspaceIsAdmin ? '/admin/admin-dashboard.php' : '/staff/staff-dashboard.php' ?>">Overview</a>
    <?php if ($workspaceIsAdmin): ?>
      <a data-sidebar-section="fleet" class="<?= $workspacePage === 'admin-vehicles.php' ? 'active' : '' ?>" href="/admin/admin-vehicles.php">Fleet management</a>
      <a data-sidebar-section="rentals" class="<?= $workspacePage === 'admin-rentals.php' ? 'active' : '' ?>" href="/admin/admin-rentals.php">Rental oversight</a>
      <a data-sidebar-section="maintenance" class="<?= $workspacePage === 'admin-maintenance.php' ? 'active' : '' ?>" href="/admin/admin-maintenance.php">Maintenance</a>
      <a data-sidebar-section="users" class="<?= $workspacePage === 'admin-users.php' ? 'active' : '' ?>" href="/admin/admin-users.php">User management</a>
      <a data-sidebar-section="reports" class="<?= $workspacePage === 'admin-reports.php' ? 'active' : '' ?>" href="/admin/admin-reports.php">Reports</a>
      <a data-sidebar-section="account" class="<?= $workspacePage === 'workspace-account-settings.php' ? 'active' : '' ?>" href="/workspace/workspace-account-settings.php">Account settings</a>
    <?php else: ?>
      <a data-sidebar-section="requests" class="<?= $workspacePage === 'staff-dashboard.php' && ($_GET['section'] ?? '') === 'requests' ? 'active' : '' ?>" href="/staff/staff-dashboard.php?section=requests">Rental requests</a>
      <a data-sidebar-section="operations" class="<?= $workspacePage === 'staff-dashboard.php' && ($_GET['section'] ?? '') === 'operations' ? 'active' : '' ?>" href="/staff/staff-dashboard.php?section=operations">Operations</a>
      <a data-sidebar-section="account" class="<?= $workspacePage === 'workspace-account-settings.php' ? 'active' : '' ?>" href="/workspace/workspace-account-settings.php">Account settings</a>
    <?php endif; ?>
  </nav>
</aside>
