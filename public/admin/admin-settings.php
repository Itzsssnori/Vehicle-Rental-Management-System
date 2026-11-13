<?php
$title = 'System & Account Settings';
require_once __DIR__ . '/../../config.php';
$user = require_role('admin');

$profileMessage = '';
$profileError = '';
$passwordMessage = '';
$passwordError = '';

$accountStmt = db()->prepare(
    "SELECT u.id, u.name, u.email, u.phone, u.account_status, u.created_at, u.location_id,
            r.name AS role, u.password_hash, l.name AS location_name, l.city AS location_city
     FROM users u
     LEFT JOIN user_roles ur ON ur.user_id = u.id
     LEFT JOIN roles r ON r.id = ur.role_id
     LEFT JOIN locations l ON l.id = u.location_id
     WHERE u.id = ?
     LIMIT 1"
);
$accountStmt->execute([(int)$user['id']]);
$account = $accountStmt->fetch();

$allLocations = get_active_locations();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $profileError = 'Please enter a valid full name and work email address.';
        } else {
            $duplicate = db()->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
            $duplicate->execute([$email, (int)$user['id']]);
            if ($duplicate->fetch()) {
                $profileError = 'That email address is already in use by another registered account.';
            } else {
                $update = db()->prepare('UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?');
                $update->execute([$name, $email, $phone ?: null, (int)$user['id']]);
                $_SESSION['user']['name'] = $name;
                $_SESSION['user']['email'] = $email;
                $account['name'] = $name;
                $account['email'] = $email;
                $account['phone'] = $phone;
                write_audit(db(), (int)$user['id'], 'admin_profile_updated', 'users', (int)$user['id'], 'Updated admin profile and contact details');
                $profileMessage = 'Your administrator profile details have been successfully saved.';
            }
        }
    } elseif ($action === 'change_password') {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            $passwordError = 'Please fill out all password fields.';
        } elseif (!password_verify($currentPassword, $account['password_hash'])) {
            $passwordError = 'The existing account password you entered is incorrect.';
        } elseif (strlen($newPassword) < 8) {
            $passwordError = 'Your new password must be at least 8 characters in length.';
        } elseif ($newPassword !== $confirmPassword) {
            $passwordError = 'The new password and confirmation password do not match.';
        } else {
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $updatePass = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $updatePass->execute([$newHash, (int)$user['id']]);
            $account['password_hash'] = $newHash;
            write_audit(db(), (int)$user['id'], 'password_changed', 'users', (int)$user['id'], 'Updated administrator sign-in password');
            $passwordMessage = 'Your account security credentials have been updated successfully.';
        }
    }
}

// Hub fleet counts
$hubFleetCounts = [];
try {
    $hubRows = db()->query("SELECT current_location_id, COUNT(*) AS total FROM vehicles WHERE deleted_at IS NULL GROUP BY current_location_id")->fetchAll();
    foreach ($hubRows as $hr) {
        $hubFleetCounts[(int)$hr['current_location_id']] = (int)$hr['total'];
    }
} catch (Throwable $e) {
    // Fail silently
}

include __DIR__ . '/../../includes/header.php';
?>

<main class="portal-customer-page">
  <div class="portal-customer-layout">

    <!-- Unified Workspace Sidebar -->
    <?php include __DIR__ . '/../../includes/workspace-sidebar.php'; ?>

    <!-- Main Content Area -->
    <div class="customer-main-content">

      <!-- Hero Greeting Banner -->
      <section class="portal-hero-banner" aria-label="Settings heading">
        <div class="portal-hero-text">
          <div class="portal-hero-eyebrow">
            <span class="pulse-indicator" aria-hidden="true"></span>
            <span>SYSTEM GOVERNANCE · ADMIN PREFERENCES</span>
          </div>
          <h1 class="portal-hero-title">System &amp; Account Settings</h1>
          <p class="portal-hero-subtitle">
            Configure administrator profile credentials, review operating hub depots, and audit commercial rental policies.
          </p>
        </div>
        <div class="portal-hero-actions">
          <a href="/admin/admin-dashboard.php" class="btn btn-outline-white">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
            <span>Back to Dashboard</span>
          </a>
        </div>
      </section>

      <!-- Feedback notifications -->
      <?php if ($profileMessage): ?>
        <div class="portal-card" style="border-left: 4px solid #10b981; background: #ecfdf5; padding: 12px 16px; margin-bottom: 18px;" role="status">
          <div style="display:flex; align-items:center; gap:8px; color:#065f46; font-size:0.84rem; font-weight:600;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            <span><?= e($profileMessage) ?></span>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($profileError): ?>
        <div class="portal-card" style="border-left: 4px solid #ef4444; background: #fef2f2; padding: 12px 16px; margin-bottom: 18px;" role="alert">
          <div style="display:flex; align-items:center; gap:8px; color:#991b1b; font-size:0.84rem; font-weight:600;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span><?= e($profileError) ?></span>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($passwordMessage): ?>
        <div class="portal-card" style="border-left: 4px solid #10b981; background: #ecfdf5; padding: 12px 16px; margin-bottom: 18px;" role="status">
          <div style="display:flex; align-items:center; gap:8px; color:#065f46; font-size:0.84rem; font-weight:600;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            <span><?= e($passwordMessage) ?></span>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($passwordError): ?>
        <div class="portal-card" style="border-left: 4px solid #ef4444; background: #fef2f2; padding: 12px 16px; margin-bottom: 18px;" role="alert">
          <div style="display:flex; align-items:center; gap:8px; color:#991b1b; font-size:0.84rem; font-weight:600;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span><?= e($passwordError) ?></span>
          </div>
        </div>
      <?php endif; ?>

      <!-- Row 1: Profile & Password Grid -->
      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; align-items: start; margin-bottom: 24px;">

        <!-- Card 1: Admin Profile -->
        <section class="portal-card">
          <div class="portal-card-header" style="margin-bottom:12px; padding-bottom:10px;">
            <div class="portal-card-title-group">
              <span style="font-size:0.70rem; font-weight:800; text-transform:uppercase; color:#e53e3e; letter-spacing:0.05em;">ADMIN IDENTITY</span>
              <h2 style="font-size:1.15rem; margin-top:2px;">Administrator Profile</h2>
            </div>
            <?php if (is_super_admin($user)): ?>
              <span class="role-badge-pill role-badge-admin" style="font-size:0.70rem; background:#4338ca; color:#ffffff;">
                <span class="role-pill-dot" aria-hidden="true" style="background:#a5b4fc;"></span>
                <span>Super Administrator</span>
              </span>
            <?php else: ?>
              <span class="role-badge-pill role-badge-admin" style="font-size:0.70rem;">
                <span class="role-pill-dot" aria-hidden="true"></span>
                <span>Branch Administrator</span>
              </span>
            <?php endif; ?>
          </div>

          <form method="post" style="display:flex; flex-direction:column; gap:12px;">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_profile">

            <div>
              <label for="admin-name" style="display:block; font-size:0.74rem; font-weight:700; color:#475569; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.04em;">Full Name</label>
              <input type="text" id="admin-name" name="name" value="<?= e($account['name']) ?>" required style="height:36px; font-size:0.84rem;">
            </div>

            <div>
              <label for="admin-email" style="display:block; font-size:0.74rem; font-weight:700; color:#475569; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.04em;">Work Email Address</label>
              <input type="email" id="admin-email" name="email" value="<?= e($account['email']) ?>" required style="height:36px; font-size:0.84rem;">
            </div>

            <div>
              <label for="admin-phone" style="display:block; font-size:0.74rem; font-weight:700; color:#475569; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.04em;">Official Contact Number</label>
              <input type="text" id="admin-phone" name="phone" value="<?= e($account['phone'] ?? '') ?>" placeholder="e.g. +63 917 123 4567" style="height:36px; font-size:0.84rem;">
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
              <div>
                <label style="display:block; font-size:0.74rem; font-weight:700; color:#475569; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.04em;">Assigned Operating Hub</label>
                <input value="<?= e($account['location_name'] ?? 'Global / Multi-Branch') ?>" disabled style="height:36px; font-size:0.82rem; background:#f8fafc; color:#64748b;">
              </div>
              <div>
                <label style="display:block; font-size:0.74rem; font-weight:700; color:#475569; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.04em;">Account Status</label>
                <input value="<?= e(ucfirst($account['account_status'])) ?> Active" disabled style="height:36px; font-size:0.82rem; background:#f8fafc; color:#059669; font-weight:700;">
              </div>
            </div>

            <div style="display:flex; justify-content:flex-end; margin-top:4px;">
              <button class="btn btn-coral" type="submit" style="height:34px; padding:0 16px; font-size:0.80rem; border-radius:6px;">
                Save Profile Changes
              </button>
            </div>
          </form>
        </section>

        <!-- Card 2: Security & Credentials -->
        <section class="portal-card">
          <div class="portal-card-header" style="margin-bottom:12px; padding-bottom:10px;">
            <div class="portal-card-title-group">
              <span style="font-size:0.70rem; font-weight:800; text-transform:uppercase; color:#e53e3e; letter-spacing:0.05em;">AUTHENTICATION</span>
              <h2 style="font-size:1.15rem; margin-top:2px;">Change Password</h2>
            </div>
            <span class="portal-badge badge-neutral">Security Keys</span>
          </div>

          <form method="post" style="display:flex; flex-direction:column; gap:12px;">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="change_password">

            <div>
              <label for="current-pwd" style="display:block; font-size:0.74rem; font-weight:700; color:#475569; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.04em;">Current Password</label>
              <div class="password-field">
                <input id="current-pwd" type="password" name="current_password" required autocomplete="current-password" placeholder="Enter existing password" style="height:36px; font-size:0.84rem;">
                <button class="password-toggle" type="button" data-password-toggle="current-pwd" aria-controls="current-pwd" aria-pressed="false">
                  <span class="toggle-icon" aria-hidden="true">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                  </span>
                  <span class="toggle-label" style="font-size:0.70rem;">Show</span>
                </button>
              </div>
            </div>

            <div>
              <label for="new-pwd" style="display:block; font-size:0.74rem; font-weight:700; color:#475569; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.04em;">New Password (Min 8 Characters)</label>
              <div class="password-field">
                <input id="new-pwd" type="password" name="new_password" minlength="8" required autocomplete="new-password" placeholder="Create strong password" style="height:36px; font-size:0.84rem;">
                <button class="password-toggle" type="button" data-password-toggle="new-pwd" aria-controls="new-pwd" aria-pressed="false">
                  <span class="toggle-icon" aria-hidden="true">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                  </span>
                  <span class="toggle-label" style="font-size:0.70rem;">Show</span>
                </button>
              </div>
            </div>

            <div>
              <label for="confirm-pwd" style="display:block; font-size:0.74rem; font-weight:700; color:#475569; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.04em;">Confirm New Password</label>
              <div class="password-field">
                <input id="confirm-pwd" type="password" name="confirm_password" minlength="8" required autocomplete="new-password" placeholder="Re-type new password" style="height:36px; font-size:0.84rem;">
                <button class="password-toggle" type="button" data-password-toggle="confirm-pwd" aria-controls="confirm-pwd" aria-pressed="false">
                  <span class="toggle-icon" aria-hidden="true">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                  </span>
                  <span class="toggle-label" style="font-size:0.70rem;">Show</span>
                </button>
              </div>
            </div>

            <div style="display:flex; justify-content:flex-end; margin-top:4px;">
              <button class="btn btn-secondary" type="submit" style="height:34px; padding:0 16px; font-size:0.80rem; border-radius:6px;">
                Update Password
              </button>
            </div>
          </form>
        </section>

      </div>

      <?php if (is_super_admin($user)): ?>
      <!-- Row 2: Operational Policies & Rates Matrix -->
      <section class="portal-card" style="margin-bottom:24px;">
        <div class="portal-card-header" style="margin-bottom:14px; padding-bottom:10px;">
          <div class="portal-card-title-group">
            <span style="font-size:0.70rem; font-weight:800; text-transform:uppercase; color:#e53e3e; letter-spacing:0.05em;">COMMERCIAL RULES</span>
            <h2 style="font-size:1.15rem; margin-top:2px;">Operational Policies &amp; Penalty Rates</h2>
            <p style="font-size:0.78rem; color:#64748b; margin-top:2px;">System-wide parameters enforced across vehicle reservation turnover, return check-in, and billing.</p>
          </div>
          <span class="portal-badge badge-neutral">Platform Config</span>
        </div>

        <div style="display:grid; grid-template-columns: repeat(4, 1fr); gap:12px;">
          <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px;">
            <span style="display:block; font-size:0.70rem; font-weight:800; text-transform:uppercase; color:#64748b; letter-spacing:0.04em;">Turnaround Buffer</span>
            <strong style="display:block; font-size:1.15rem; color:#0f172a; margin:4px 0 2px;">2.0 Hours</strong>
            <span style="font-size:0.72rem; color:#64748b; line-height:1.3; display:block;">Mandatory cleaning, safety inspection &amp; detailing window between rentals.</span>
          </div>

          <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px;">
            <span style="display:block; font-size:0.70rem; font-weight:800; text-transform:uppercase; color:#64748b; letter-spacing:0.04em;">Fuel Deficiency Surcharge</span>
            <strong style="display:block; font-size:1.15rem; color:#b91c1c; margin:4px 0 2px;">₱65.00 / Liter</strong>
            <span style="font-size:0.72rem; color:#64748b; line-height:1.3; display:block;">Refill fee billed if return fuel level is lower than recorded dispatch level.</span>
          </div>

          <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px;">
            <span style="display:block; font-size:0.70rem; font-weight:800; text-transform:uppercase; color:#64748b; letter-spacing:0.04em;">Excess Mileage Rate</span>
            <strong style="display:block; font-size:1.15rem; color:#b91c1c; margin:4px 0 2px;">₱12.00 / km</strong>
            <span style="font-size:0.72rem; color:#64748b; line-height:1.3; display:block;">Applied when driving exceeds standard 200 km/day daily allowance limit.</span>
          </div>

          <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:14px;">
            <span style="display:block; font-size:0.70rem; font-weight:800; text-transform:uppercase; color:#64748b; letter-spacing:0.04em;">Grace Period</span>
            <strong style="display:block; font-size:1.15rem; color:#059669; margin:4px 0 2px;">30 Minutes</strong>
            <span style="font-size:0.72rem; color:#64748b; line-height:1.3; display:block;">Complimentary buffer on scheduled return time before late penalties accrue.</span>
          </div>
        </div>

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:16px; margin-top:16px;">
          <!-- Protection Tier Overview -->
          <div style="border:1px solid #e2e8f0; border-radius:8px; padding:12px 14px;">
            <strong style="font-size:0.82rem; color:#0f172a; display:block; margin-bottom:8px;">Damage Protection Tiers</strong>
            <ul style="list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:6px; font-size:0.76rem;">
              <li style="display:flex; justify-content:space-between; align-items:center; padding-bottom:6px; border-bottom:1px solid #f1f5f9;">
                <span><strong>Basic Liability:</strong> Included (₱0)</span>
                <span style="color:#64748b;">₱15,000 max excess</span>
              </li>
              <li style="display:flex; justify-content:space-between; align-items:center; padding-bottom:6px; border-bottom:1px solid #f1f5f9;">
                <span><strong>Standard CDW:</strong> +₱350/day</span>
                <span style="color:#e53e3e; font-weight:700;">₱5,000 low excess</span>
              </li>
              <li style="display:flex; justify-content:space-between; align-items:center;">
                <span><strong>Zero-Excess:</strong> +₱600/day</span>
                <span style="color:#059669; font-weight:700;">₱0 deductible</span>
              </li>
            </ul>
          </div>

          <!-- Base Security Deposits -->
          <div style="border:1px solid #e2e8f0; border-radius:8px; padding:12px 14px;">
            <strong style="font-size:0.82rem; color:#0f172a; display:block; margin-bottom:8px;">Base Security Deposit Schedule</strong>
            <ul style="list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:6px; font-size:0.76rem;">
              <li style="display:flex; justify-content:space-between; align-items:center; padding-bottom:6px; border-bottom:1px solid #f1f5f9;">
                <span><strong>Sedan Fleet:</strong></span>
                <strong style="color:#0f172a;">₱5,000 hold</strong>
              </li>
              <li style="display:flex; justify-content:space-between; align-items:center; padding-bottom:6px; border-bottom:1px solid #f1f5f9;">
                <span><strong>SUV / MPV Fleet:</strong></span>
                <strong style="color:#0f172a;">₱8,000 hold</strong>
              </li>
              <li style="display:flex; justify-content:space-between; align-items:center;">
                <span><strong>Premium / Luxury Fleet:</strong></span>
                <strong style="color:#0f172a;">₱15,000 hold</strong>
              </li>
            </ul>
          </div>
        </div>
      </section>

      <!-- Row 3: Operating Hubs Directory -->
      <section class="portal-card">
        <div class="portal-card-header" style="margin-bottom:12px; padding-bottom:10px;">
          <div class="portal-card-title-group">
            <span style="font-size:0.70rem; font-weight:800; text-transform:uppercase; color:#e53e3e; letter-spacing:0.05em;">NETWORK HUBS</span>
            <h2 style="font-size:1.15rem; margin-top:2px;">Operating Venue &amp; Depot Directory</h2>
          </div>
        </div>

        <div class="portal-table-container">
          <table class="portal-table">
            <thead>
              <tr>
                <th>Hub Name</th>
                <th>City &amp; Address</th>
                <th>Contact Phone</th>
                <th>Active Fleet</th>
                <th>Branch Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($allLocations as $loc): ?>
                <tr>
                  <td>
                    <strong style="color:#0f172a; font-size:0.84rem;"><?= e($loc['name']) ?></strong>
                    <span style="font-size:0.72rem; color:#64748b; display:block;">Hub ID: #<?= (int)$loc['id'] ?></span>
                  </td>
                  <td>
                    <span style="font-size:0.82rem; color:#334155;"><?= e($loc['address']) ?></span>
                    <span style="font-size:0.72rem; color:#64748b; display:block;"><?= e($loc['city']) ?>, <?= e($loc['postal_code'] ?? 'PH') ?></span>
                  </td>
                  <td>
                    <span style="font-size:0.80rem; color:#475569;"><?= e($loc['phone'] ?: '+63 897 456 1236') ?></span>
                  </td>
                  <td>
                    <span class="portal-badge badge-neutral" style="font-size:0.72rem; font-weight:700;">
                      <?= (int)($hubFleetCounts[(int)$loc['id']] ?? 0) ?> Vehicles
                    </span>
                  </td>
                  <td>
                    <span class="portal-badge badge-success" style="font-size:0.70rem;">Operational</span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
      <?php endif; ?>

    </div>
  </div>
</main>

<script>
document.querySelectorAll('[data-password-toggle]').forEach(function(btn) {
  btn.addEventListener('click', function() {
    const targetId = this.getAttribute('data-password-toggle');
    const input = document.getElementById(targetId);
    if (!input) return;
    const isPassword = input.type === 'password';
    input.type = isPassword ? 'text' : 'password';
    this.setAttribute('aria-pressed', isPassword ? 'true' : 'false');
    const label = this.querySelector('.toggle-label');
    if (label) label.textContent = isPassword ? 'Hide' : 'Show';
  });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
