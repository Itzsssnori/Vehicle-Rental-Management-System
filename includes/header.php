<?php require_once __DIR__ . '/../config.php'; ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title ?? 'Vehicle Rental Management System') ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="nav">
  <a class="brand" href="/index.php" aria-label="VELO Home">
    <span class="brand-wrap">
      <span class="brand-mark">V</span>
      <span class="brand-text">VELO</span>
    </span>
  </a>
  <button class="menu-btn" aria-label="Menu" aria-expanded="false" onclick="toggleMenu(this)">Menu</button>
  <nav id="nav-links">
    <a href="/index.php">Home</a>
    <a href="/vehicles.php">Browse Vehicles</a>
    <a href="/index.php#how">How It Works</a>
    <a href="/index.php#about">About</a>
    <?php if (current_user()): ?>
      <a href="<?= current_user()['role'] === 'admin' ? '/admin/admin-dashboard.php' : (current_user()['role'] === 'staff' ? '/staff/staff-dashboard.php' : '/customer/dashboard.php') ?>">Dashboard</a>
      <?php if (current_user()['role'] === 'customer'): ?><a href="/customer/my-rentals.php">My Rentals</a><?php endif; ?>
      <a href="/logout.php">Log out</a>
    <?php else: ?>
      <a href="/login.php">Login</a>
    <?php endif; ?>
    <?php if (!current_user()): ?><a class="btn btn-coral" href="/vehicles.php">Find a Vehicle</a><?php endif; ?>
  </nav>
</header>
<?php
$workspacePages = ['staff-dashboard.php', 'admin-dashboard.php', 'admin-vehicles.php', 'admin-rentals.php', 'admin-maintenance.php', 'admin-users.php', 'admin-reports.php', 'workspace-account-settings.php'];
if (current_user() && current_user()['role'] !== 'customer' && in_array(basename($_SERVER['PHP_SELF']), $workspacePages, true)) {
    include __DIR__ . '/workspace-sidebar.php';
}
?>
<?php if (basename($_SERVER['PHP_SELF']) === 'vehicle.php'): ?>
  <a class="back-button" href="/index.php" onclick="if (window.history.length > 1) { window.history.back(); return false; }" aria-label="Go back to the previous page" title="Go back">
    <span aria-hidden="true">↪</span>
  </a>
<?php endif; ?>
<script>
  (() => {
    const sidebars = document.querySelectorAll('.dashboard-sidebar, .workspace-sidebar');
    if (!sidebars.length) return;

    const syncSidebar = () => {
      const page = window.location.pathname.split('/').pop();
      const hash = window.location.hash.slice(1);
      sidebars.forEach((sidebar) => {
        const links = [...sidebar.querySelectorAll('[data-sidebar-section]')];
        let activeSection = null;
        if (page === 'staff-dashboard.php') {
          const section = new URLSearchParams(window.location.search).get('section');
          activeSection = section === 'operations' ? 'operations' : section === 'requests' ? 'requests' : 'overview';
        } else if (page === 'dashboard.php' && hash === 'account-modal') {
          activeSection = 'account';
        } else if (page === 'account-settings.php') {
          activeSection = 'account';
        } else if (page === 'vehicles.php') {
          activeSection = 'browse';
        } else if (page === 'my-rentals.php' || page === 'rental.php') {
          activeSection = 'rentals';
        } else if (page === 'admin-vehicles.php') {
          activeSection = 'fleet';
        } else if (page === 'admin-rentals.php') {
          activeSection = 'rentals';
        } else if (page === 'admin-maintenance.php') {
          activeSection = 'maintenance';
        } else if (page === 'admin-users.php') {
          activeSection = 'users';
        } else if (page === 'admin-reports.php') {
          activeSection = 'reports';
        } else if (page === 'workspace-account-settings.php') {
          activeSection = 'account';
        } else if (page === 'dashboard.php' || page === 'admin-dashboard.php' || page === 'staff-dashboard.php') {
          activeSection = 'overview';
        }
        links.forEach((link) => {
          const isActive = link.dataset.sidebarSection === activeSection;
          link.classList.toggle('active', isActive);
          if (isActive) link.setAttribute('aria-current', 'page');
          else link.removeAttribute('aria-current');
        });
      });
    };

    syncSidebar();
    window.addEventListener('hashchange', syncSidebar);
  })();
</script>
