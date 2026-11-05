<?php
$sidebarUser = $user ?? current_user() ?? [];
$sidebarStmt = db()->prepare('SELECT name, profile_image FROM users WHERE id = ? LIMIT 1');
$sidebarStmt->execute([(int)($sidebarUser['id'] ?? 0)]);
$sidebarUser = $sidebarStmt->fetch() ?: $sidebarUser;
$sidebarName = $sidebarUser['name'] ?? $user['name'];
$sidebarImage = trim((string)($sidebarUser['profile_image'] ?? ''));
$sidebarPage = basename($_SERVER['PHP_SELF']);
?>
<aside class="dashboard-sidebar" aria-label="Customer dashboard navigation">
  <div class="sidebar-profile">
    <span class="profile-avatar<?= $sidebarImage !== '' ? ' has-profile-image' : '' ?>"<?= $sidebarImage !== '' ? ' style="background-image:url(\'' . e($sidebarImage) . '\')"' : '' ?>><?= $sidebarImage === '' ? e(strtoupper(substr($sidebarName, 0, 1))) : '' ?></span>
    <div>
      <strong><?= e($sidebarName) ?></strong>
      <span>Customer account</span>
    </div>
  </div>
  <nav class="sidebar-nav">
    <a data-sidebar-section="overview" class="<?= $sidebarPage === 'dashboard.php' ? 'active' : '' ?>" href="/customer/dashboard.php">Overview</a>
    <a data-sidebar-section="rentals" class="<?= in_array($sidebarPage, ['my-rentals.php', 'rental.php'], true) ? 'active' : '' ?>" href="/customer/my-rentals.php">My rentals</a>
    <a data-sidebar-section="browse" class="<?= $sidebarPage === 'vehicles.php' ? 'active' : '' ?>" href="/vehicles.php">Browse vehicles</a>
    <a data-sidebar-section="account" class="<?= $sidebarPage === 'account-settings.php' || ($sidebarPage === 'dashboard.php' && ($_GET['section'] ?? '') === 'account') ? 'active' : '' ?>" href="/customer/dashboard.php#account-modal">Account settings</a>
  </nav>
</aside>
