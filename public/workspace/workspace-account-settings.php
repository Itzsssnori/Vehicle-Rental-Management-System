<?php
$title = 'Workspace Account Settings';
require_once __DIR__ . '/../../config.php';
$user = current_user();
if (!$user || !in_array($user['role'] ?? '', ['staff', 'admin', 'super_admin'], true)) {
    header('Location: /login.php');
    exit;
}

$profileMessage = '';
$profileError = '';
$passwordMessage = '';
$passwordError = '';

$accountStmt = db()->prepare(
    "SELECT u.id, u.name, u.email, u.phone, u.account_status, u.created_at, r.name AS role, u.password_hash
     FROM users u
     LEFT JOIN user_roles ur ON ur.user_id = u.id
     LEFT JOIN roles r ON r.id = ur.role_id
     WHERE u.id = ?
     LIMIT 1"
);
$accountStmt->execute([(int)$user['id']]);
$account = $accountStmt->fetch();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $profileError = 'Please enter a valid full name and email address.';
        } else {
            $duplicate = db()->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
            $duplicate->execute([$email, (int)$user['id']]);
            if ($duplicate->fetch()) {
                $profileError = 'That email address is already in use by another account.';
            } else {
                $update = db()->prepare('UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?');
                $update->execute([$name, $email, $phone ?: null, (int)$user['id']]);
                $_SESSION['user']['name'] = $name;
                $_SESSION['user']['email'] = $email;
                $account['name'] = $name;
                $account['email'] = $email;
                $account['phone'] = $phone;
                write_audit(db(), (int)$user['id'], 'profile_updated', 'users', (int)$user['id'], 'Updated workspace profile details');
                $profileMessage = 'Profile identity details have been successfully updated.';
            }
        }
    } elseif ($action === 'change_password') {
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            $passwordError = 'Please fill out all password fields.';
        } elseif (!password_verify($currentPassword, $account['password_hash'])) {
            $passwordError = 'The current password you entered is incorrect.';
        } elseif (strlen($newPassword) < 8) {
            $passwordError = 'Your new password must be at least 8 characters in length.';
        } elseif ($newPassword !== $confirmPassword) {
            $passwordError = 'The new password and confirmation password do not match.';
        } else {
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $updatePass = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $updatePass->execute([$newHash, (int)$user['id']]);
            $account['password_hash'] = $newHash;
            write_audit(db(), (int)$user['id'], 'password_changed', 'users', (int)$user['id'], 'Updated workspace sign-in password');
            $passwordMessage = 'Your account password has been successfully updated.';
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<main class="portal-customer-page">
  <div class="portal-customer-layout">

    <!-- Unified Sidebar Navigation -->
    <?php include __DIR__ . '/../../includes/workspace-sidebar.php'; ?>

    <!-- Main Workspace Content -->
    <div class="customer-main-content">

      <!-- Hero Greeting Banner -->
      <section class="portal-hero-banner" aria-label="Account settings heading">
        <div class="portal-hero-text">
          <div class="portal-hero-eyebrow">
            <span class="pulse-indicator" aria-hidden="true"></span>
            <span>WORKSPACE SECURITY · ACCOUNT PROFILE</span>
          </div>
          <h1 class="portal-hero-title">Account Profile & Security</h1>
          <p class="portal-hero-subtitle">
            Manage your workspace identity, work email address, and sign-in authentication credentials.
          </p>
        </div>
        <div class="portal-hero-actions">
          <a href="<?= in_array($user['role'], ['admin', 'super_admin'], true) ? '/admin/admin-dashboard.php' : '/staff/staff-dashboard.php' ?>" class="btn btn-outline-white">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
            <span>Back to Dashboard</span>
          </a>
        </div>
      </section>

      <!-- 2-Column Grid: Profile & Security -->
      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; align-items: start;">

        <!-- Card 1: Workspace Profile -->
        <section class="portal-card">
          <div class="portal-card-header">
            <div class="portal-card-title-group">
              <span style="font-size:0.72rem; font-weight:800; text-transform:uppercase; color:#e53e3e; letter-spacing:0.05em;">IDENTITY DETAILS</span>
              <h2><?= in_array($user['role'], ['admin', 'super_admin'], true) ? ($user['role'] === 'super_admin' ? 'Super Administrator Profile' : 'Administrator Profile') : 'Operations Staff Profile' ?></h2>
            </div>
            <span class="portal-badge badge-success"><?= e(ucfirst($account['account_status'])) ?></span>
          </div>

          <?php if ($profileMessage): ?>
            <div style="background:#ecfdf5; border-left:4px solid #10b981; padding:10px 14px; border-radius:6px; margin-bottom:14px; font-size:0.84rem; color:#065f46;">
              <?= e($profileMessage) ?>
            </div>
          <?php endif; ?>

          <?php if ($profileError): ?>
            <div style="background:#fef2f2; border-left:4px solid #ef4444; padding:10px 14px; border-radius:6px; margin-bottom:14px; font-size:0.84rem; color:#991b1b;">
              <?= e($profileError) ?>
            </div>
          <?php endif; ?>

          <form method="post" style="display:flex; flex-direction:column; gap:14px;">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_profile">

            <div>
              <label for="name">Full Name</label>
              <input type="text" id="name" name="name" value="<?= e($account['name']) ?>" required>
            </div>

            <div>
              <label for="email">Work Email Address</label>
              <input type="email" id="email" name="email" value="<?= e($account['email']) ?>" required>
            </div>

            <div>
              <label for="phone">Phone Number</label>
              <input type="text" id="phone" name="phone" value="<?= e($account['phone'] ?? '') ?>" placeholder="+63 9XX XXX XXXX" autocomplete="tel">
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
              <div>
                <label>Assigned Role</label>
                <input value="<?= e($user['role'] === 'super_admin' ? 'Super Administrator' : ($user['role'] === 'admin' ? 'Branch Administrator' : 'Operations Staff')) ?>" disabled>
              </div>
              <div>
                <label>Account Status</label>
                <input value="<?= e(ucfirst($account['account_status'])) ?>" disabled>
              </div>
            </div>

            <div>
              <span style="font-size:0.76rem; color:#94a3b8;">
                Account registered on <?= e(date('F j, Y', strtotime($account['created_at']))) ?>
              </span>
            </div>

            <button class="btn btn-coral" type="submit" style="margin-top:8px; justify-content:center; padding:10px;">
              Save Profile Changes
            </button>
          </form>
        </section>

        <!-- Card 2: Security & Password -->
        <section class="portal-card">
          <div class="portal-card-header">
            <div class="portal-card-title-group">
              <span style="font-size:0.72rem; font-weight:800; text-transform:uppercase; color:#e53e3e; letter-spacing:0.05em;">AUTHENTICATION</span>
              <h2>Change Password</h2>
            </div>
            <span class="portal-badge badge-neutral">Security Keys</span>
          </div>

          <?php if ($passwordMessage): ?>
            <div style="background:#ecfdf5; border-left:4px solid #10b981; padding:10px 14px; border-radius:6px; margin-bottom:14px; font-size:0.84rem; color:#065f46;">
              <?= e($passwordMessage) ?>
            </div>
          <?php endif; ?>

          <?php if ($passwordError): ?>
            <div style="background:#fef2f2; border-left:4px solid #ef4444; padding:10px 14px; border-radius:6px; margin-bottom:14px; font-size:0.84rem; color:#991b1b;">
              <?= e($passwordError) ?>
            </div>
          <?php endif; ?>

          <form method="post" style="display:flex; flex-direction:column; gap:14px;">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="change_password">

            <div class="portal-form-group">
              <label for="current-pwd">Current Password</label>
              <div class="password-field">
                <input id="current-pwd" type="password" name="current_password" required autocomplete="current-password" placeholder="Enter existing password">
                <button class="password-toggle" type="button" data-password-toggle="current-pwd" aria-controls="current-pwd" aria-pressed="false">
                  <span class="toggle-icon" aria-hidden="true">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                  </span>
                  <span class="toggle-label">Show</span>
                </button>
              </div>
            </div>

            <div class="portal-form-group">
              <label for="new-pwd">New Password (Min 8 Characters)</label>
              <div class="password-field">
                <input id="new-pwd" type="password" name="new_password" minlength="8" required autocomplete="new-password" placeholder="Create strong password">
                <button class="password-toggle" type="button" data-password-toggle="new-pwd" aria-controls="new-pwd" aria-pressed="false">
                  <span class="toggle-icon" aria-hidden="true">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                  </span>
                  <span class="toggle-label">Show</span>
                </button>
              </div>
            </div>

            <div class="portal-form-group">
              <label for="confirm-pwd">Confirm New Password</label>
              <div class="password-field">
                <input id="confirm-pwd" type="password" name="confirm_password" minlength="8" required autocomplete="new-password" placeholder="Re-type new password">
                <button class="password-toggle" type="button" data-password-toggle="confirm-pwd" aria-controls="confirm-pwd" aria-pressed="false">
                  <span class="toggle-icon" aria-hidden="true">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                  </span>
                  <span class="toggle-label">Show</span>
                </button>
              </div>
            </div>

            <button class="btn btn-secondary" type="submit" style="margin-top:8px; justify-content:center; padding:10px;">
              Update Security Credentials
            </button>
          </form>
        </section>

      </div>

    </div>
  </div>
</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
