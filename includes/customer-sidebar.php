<?php
$sidebarUser = $user ?? current_user() ?? [];
$sidebarStmt = db()->prepare('SELECT id, name, email, profile_image FROM users WHERE id = ? LIMIT 1');
$sidebarStmt->execute([(int)($sidebarUser['id'] ?? 0)]);
$sidebarUser = $sidebarStmt->fetch() ?: $sidebarUser;
$sidebarName = $sidebarUser['name'] ?? ($user['name'] ?? 'Customer');
$sidebarEmail = $sidebarUser['email'] ?? ($user['email'] ?? '');
$sidebarImage = trim((string)($sidebarUser['profile_image'] ?? ''));
$sidebarPage = basename($_SERVER['PHP_SELF']);

$sidebarStanding = customer_standing(db(), (int)($sidebarUser['id'] ?? 0));
$standingType = $sidebarStanding['standing'] ?? 'good';
$standingPoints = (int)($sidebarStanding['points'] ?? 0);
?>
<aside class="portal-customer-sidebar" aria-label="Customer dashboard navigation">
  <!-- Profile Header Card -->
  <div class="sidebar-user-card">
    <div class="sidebar-avatar-frame">
      <?php if ($sidebarImage !== ''): ?>
        <span class="user-avatar-img" style="background-image:url('<?= e($sidebarImage) ?>');" role="img" aria-label="<?= e($sidebarName) ?>"></span>
      <?php else: ?>
        <span class="user-avatar-initials" aria-hidden="true"><?= e(strtoupper(substr($sidebarName, 0, 1))) ?></span>
      <?php endif; ?>
      <span class="avatar-status-dot status-<?= e($standingType) ?>" title="Standing: <?= e(ucfirst($standingType)) ?>"></span>
    </div>

    <div class="sidebar-user-info">
      <strong class="sidebar-user-name"><?= e($sidebarName) ?></strong>
      <span class="sidebar-user-email"><?= e($sidebarEmail) ?></span>
    </div>

    <div class="sidebar-standing-pill standing-<?= e($standingType) ?>">
      <span class="standing-indicator-dot"></span>
      <span><?= e(ucfirst($standingType)) ?> Standing</span>
      <?php if ($standingPoints > 0): ?>
        <span class="standing-points-count">(<?= $standingPoints ?> pt<?= $standingPoints === 1 ? '' : 's' ?>)</span>
      <?php endif; ?>
    </div>
  </div>

  <!-- Navigation Links -->
  <nav class="portal-sidebar-nav">
    <div class="nav-section-label">MAIN WORKSPACE</div>

    <a href="/customer/dashboard.php" class="portal-nav-link <?= $sidebarPage === 'dashboard.php' ? 'active' : '' ?>">
      <span class="nav-link-icon" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
      </span>
      <span class="nav-link-text">Overview & Summary</span>
    </a>

    <a href="/customer/my-rentals.php" class="portal-nav-link <?= in_array($sidebarPage, ['my-rentals.php', 'rental.php'], true) ? 'active' : '' ?>">
      <span class="nav-link-icon" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      </span>
      <span class="nav-link-text">My Rentals & Bookings</span>
    </a>

    <a href="/customer/payments.php" class="portal-nav-link <?= in_array($sidebarPage, ['payments.php', 'online-checkout.php', 'payment-return.php'], true) ? 'active' : '' ?>">
      <span class="nav-link-icon" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
      </span>
      <span class="nav-link-text">Payments & Receipts</span>
    </a>

    <a href="/customer/standing.php" class="portal-nav-link <?= $sidebarPage === 'standing.php' ? 'active' : '' ?>">
      <span class="nav-link-icon" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      </span>
      <span class="nav-link-text">Standing & Penalties</span>
    </a>

    <div class="nav-section-label" style="margin-top:16px;">FLEET DISCOVERY</div>

    <a href="/vehicles.php" class="portal-nav-link <?= in_array($sidebarPage, ['vehicles.php', 'vehicle.php'], true) ? 'active' : '' ?>">
      <span class="nav-link-icon" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/></svg>
      </span>
      <span class="nav-link-text">Browse Vehicle Fleet</span>
    </a>

    <div class="nav-section-label" style="margin-top:16px;">SETTINGS & SECURITY</div>

    <a href="/customer/account-settings.php" class="portal-nav-link <?= $sidebarPage === 'account-settings.php' ? 'active' : '' ?>">
      <span class="nav-link-icon" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
      </span>
      <span class="nav-link-text">Account & Password</span>
    </a>

    <a href="/logout.php" class="portal-nav-link nav-link-logout">
      <span class="nav-link-icon" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      </span>
      <span class="nav-link-text">Sign Out</span>
    </a>
  </nav>
</aside>
