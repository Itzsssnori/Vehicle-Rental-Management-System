<?php require_once __DIR__ . '/../config.php'; ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title ?? 'Vehicle Rental Management System') ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/style.css?v=<?= filemtime(__DIR__ . '/../public/assets/style.css') ?>">
</head>
<body>

<header class="site-header">
  <!-- Top utility bar (Email, Helpline, Greeting) -->
  <div class="header-topbar">
    <div class="topbar-inner wrap">
      <div class="topbar-left">
        <a class="topbar-brand" href="/index.php" aria-label="VELO">
          <span class="brand-badge-icon" aria-hidden="true">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/>
              <circle cx="7" cy="17" r="2"/>
              <path d="M9 17h6"/>
              <circle cx="17" cy="17" r="2"/>
            </svg>
          </span>
          <div class="brand-text-group">
            <span class="brand-main-title">VELO</span>
            <span class="brand-sub-title">VELO FLEET SYSTEM</span>
          </div>
        </a>
      </div>

      <div class="topbar-right">
        <div class="topbar-info-item">
          <span class="topbar-info-icon" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <rect width="20" height="16" x="2" y="4" rx="2"/>
              <path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>
            </svg>
          </span>
          <div class="topbar-info-text">
            <span class="topbar-info-label">FOR SUPPORT MAIL US :</span>
            <a href="mailto:info@velo.com" class="topbar-info-val">info@velo.com</a>
          </div>
        </div>

        <div class="topbar-info-item">
          <span class="topbar-info-icon" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/>
            </svg>
          </span>
          <div class="topbar-info-text">
            <span class="topbar-info-label">SERVICE HELPLINE CALL US:</span>
            <a href="tel:+638974561236" class="topbar-info-val">+63 897 456 1236</a>
          </div>
        </div>

        <div class="topbar-greeting">
          <?php if (current_user()): ?>
            <span>Welcome, <strong><?= e(explode(' ', current_user()['name'])[0]) ?></strong></span>
          <?php else: ?>
            <span>Welcome to VELO</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Main dark navigation bar -->
  <nav class="header-navbar">
    <div class="navbar-inner wrap">
      <button class="nav-mobile-toggle menu-btn" aria-label="Toggle navigation menu" aria-expanded="false" onclick="toggleMenu(this)">
        <span class="toggle-bar"></span>
        <span class="toggle-bar"></span>
        <span class="toggle-bar"></span>
      </button>

      <div class="nav-links-menu" id="nav-links">
        <a href="/index.php" data-nav-section="home" class="<?= basename($_SERVER['PHP_SELF']) === 'index.php' ? 'active' : '' ?>">HOME</a>
        <a href="/index.php#about" data-nav-section="about">ABOUT US</a>
        <a href="/vehicles.php" class="<?= basename($_SERVER['PHP_SELF']) === 'vehicles.php' ? 'active' : '' ?>">CAR LISTING</a>
        <a href="/index.php#how" data-nav-section="how">HOW IT WORKS</a>
        <a href="/index.php#contact" data-nav-section="contact">CONTACT US</a>
      </div>

      <div class="navbar-utilities">
        <?php if (current_user()): ?>
          <div class="nav-user-dropdown" id="user-dropdown">
            <button class="user-dropdown-btn" type="button" aria-expanded="false" onclick="toggleUserDropdown(this)">
              <span class="user-avatar-badge" aria-hidden="true"><?= e(strtoupper(substr(current_user()['name'], 0, 1))) ?></span>
              <span class="user-display-name"><?= e(strtoupper(explode(' ', current_user()['name'])[0])) ?></span>
              <span class="user-dropdown-arrow" aria-hidden="true">&#9662;</span>
            </button>
            <div class="dropdown-popover" id="user-dropdown-menu">
              <div class="dropdown-popover-header">
                <strong><?= e(current_user()['name']) ?></strong>
                <span class="dropdown-role-badge role-<?= e(current_user()['role']) ?>"><?= e(ucfirst(current_user()['role'])) ?></span>
              </div>
              <div class="dropdown-popover-body">
                <a href="<?= current_user()['role'] === 'admin' ? '/admin/admin-dashboard.php' : (current_user()['role'] === 'staff' ? '/staff/staff-dashboard.php' : '/customer/dashboard.php') ?>" class="dropdown-link">
                  <span class="dropdown-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg></span>
                  <span>Dashboard</span>
                </a>
                <?php if (current_user()['role'] === 'customer'): ?>
                  <a href="/customer/my-rentals.php" class="dropdown-link">
                    <span class="dropdown-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 17h14l1-5-2-4H6L4 12l1 5Z"/><circle cx="7.5" cy="17" r="1.5"/><circle cx="16.5" cy="17" r="1.5"/></svg></span>
                    <span>My Rentals</span>
                  </a>
                  <a href="/customer/account-settings.php" class="dropdown-link">
                    <span class="dropdown-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-1.8 1.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-2.5V20a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1-1.8-1.8.1-.1A1.7 1.7 0 0 0 8.1 15a1.7 1.7 0 0 0-1.6-1H6v-2.5h.2a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1 1.8-1.8.1.1a1.7 1.7 0 0 0 1.9.3 1.7 1.7 0 0 0 1-1.6v-.2h2.5v.2a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1 1.8 1.8-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2V14h-.2a1.7 1.7 0 0 0-1.6 1Z"/></svg></span>
                    <span>Account Settings</span>
                  </a>
                <?php else: ?>
                  <a href="/workspace/workspace-account-settings.php" class="dropdown-link">
                    <span class="dropdown-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-1.8 1.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-2.5V20a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1-1.8-1.8.1-.1A1.7 1.7 0 0 0 8.1 15a1.7 1.7 0 0 0-1.6-1H6v-2.5h.2a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1 1.8-1.8.1.1a1.7 1.7 0 0 0 1.9.3 1.7 1.7 0 0 0 1-1.6v-.2h2.5v.2a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1 1.8 1.8-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2V14h-.2a1.7 1.7 0 0 0-1.6 1Z"/></svg></span>
                    <span>Account Settings</span>
                  </a>
                <?php endif; ?>
                <div class="dropdown-popover-divider"></div>
                <a href="/logout.php" class="dropdown-link logout-link">
                  <span class="dropdown-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M10 4H5v16h5M14 8l4 4-4 4M18 12H9"/></svg></span>
                  <span>Log out</span>
                </a>
              </div>
            </div>
          </div>
        <?php else: ?>
          <div class="nav-auth-buttons">
            <a href="/login.php" class="nav-login-btn">Login</a>
            <a href="/register.php" class="nav-register-btn">Register</a>
          </div>
        <?php endif; ?>

        <form class="nav-search-widget" action="/vehicles.php" method="get">
          <input type="text" name="search" placeholder="Search..." aria-label="Search vehicles" value="<?= e($_GET['search'] ?? '') ?>">
          <button type="submit" aria-label="Search fleet">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <circle cx="11" cy="11" r="8"/>
              <path d="m21 21-4.35-4.35"/>
            </svg>
          </button>
        </form>
      </div>
    </div>
  </nav>
</header>

<?php
$workspacePages = ['staff-dashboard.php', 'admin-dashboard.php', 'admin-vehicles.php', 'admin-rentals.php', 'admin-maintenance.php', 'admin-users.php', 'admin-reports.php', 'workspace-account-settings.php'];
if (current_user() && current_user()['role'] !== 'customer' && in_array(basename($_SERVER['PHP_SELF']), $workspacePages, true)) {
    include __DIR__ . '/workspace-sidebar.php';
}
?>
<?php if (basename($_SERVER['PHP_SELF']) === 'vehicle.php'): ?>
  <a class="back-button" href="/vehicles.php" onclick="if (window.history.length > 1) { window.history.back(); return false; }" aria-label="Go back to the previous page" title="Go back">
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
