<?php
$title = 'Account Profile & Security';
require_once __DIR__ . '/../../config.php';
$user = require_customer();
$pdo = db();

$profileMessage = '';
$profileError = '';
$passwordMessage = '';
$passwordError = '';

// Load user record
$stmt = $pdo->prepare('SELECT id, name, email, phone, address, profile_image, preferred_payment_method, account_status, email_verified_at, created_at, password_hash FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$account = $stmt->fetch();

$standing = customer_standing($pdo, (int)$user['id']);
$standingType = $standing['standing'] ?? 'good';
$standingPoints = (int)($standing['points'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $profileImage = trim($_POST['profile_image'] ?? '');
        $paymentMethod = $_POST['preferred_payment_method'] ?? '';

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($paymentMethod, ['', 'cash', 'bank_transfer', 'gcash', 'card'], true)) {
            $profileError = 'Please enter a valid full name, email address, and preferred payment option.';
        } else {
            $duplicate = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
            $duplicate->execute([$email, $user['id']]);
            if ($duplicate->fetch()) {
                $profileError = 'That email address is already registered to another account.';
            } elseif ($profileImage !== '' && !preg_match('#^https?://#i', $profileImage)) {
                $profileError = 'Profile image must be a valid http:// or https:// URL.';
            } else {
                $update = $pdo->prepare('UPDATE users SET name = ?, email = ?, phone = ?, address = ?, profile_image = ?, preferred_payment_method = ? WHERE id = ?');
                $update->execute([$name, $email, $phone ?: null, $address ?: null, $profileImage ?: null, $paymentMethod ?: null, $user['id']]);
                $_SESSION['user']['name'] = $name;
                $_SESSION['user']['email'] = $email;
                $account['name'] = $name;
                $account['email'] = $email;
                $account['phone'] = $phone;
                $account['address'] = $address;
                $account['profile_image'] = $profileImage;
                $account['preferred_payment_method'] = $paymentMethod;
                write_audit($pdo, (int)$user['id'], 'profile_update', 'users', $user['id'], 'Customer updated contact details');
                $profileMessage = 'Your contact and billing profile has been successfully saved.';
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
            $updatePass = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $updatePass->execute([$newHash, $user['id']]);
            $account['password_hash'] = $newHash;
            write_audit($pdo, (int)$user['id'], 'password_change', 'users', $user['id'], 'Customer changed account password');
            $passwordMessage = 'Your security password has been successfully updated.';
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<main class="portal-customer-page">
  <div class="portal-customer-layout">
    <?php include __DIR__ . '/../../includes/customer-sidebar.php'; ?>

    <div class="customer-main-content">
      <!-- Page Hero Header -->
      <section class="portal-hero-banner">
        <div class="hero-greeting-content">
          <span class="eyebrow">Settings & Security</span>
          <h1>Account Profile & Security</h1>
          <p>Manage your verified contact credentials, update security keys, and review driving trust standing.</p>
        </div>
        <div style="display:flex;align-items:center;gap:8px;">
          <span class="portal-badge badge-neutral" style="font-size:0.82rem;padding:6px 14px;background:rgba(255,255,255,0.12);color:#ffffff;border:1px solid rgba(255,255,255,0.22);white-space:nowrap;">
            Verified Customer
          </span>
        </div>
      </section>

      <!-- 2-Column Responsive Workspace Grid -->
      <div class="account-settings-grid">
        <!-- LEFT COLUMN: Identity & Standing Card -->
        <aside class="account-identity-card" aria-label="Customer profile summary">
          <div class="identity-avatar-large">
            <?php if (!empty($account['profile_image'])): ?>
              <span class="identity-avatar-img" style="background-image:url('<?= e($account['profile_image']) ?>');" role="img" aria-label="<?= e($account['name']) ?>"></span>
            <?php else: ?>
              <span class="identity-avatar-initials" aria-hidden="true"><?= e(strtoupper(substr($account['name'], 0, 1))) ?></span>
            <?php endif; ?>
            <span class="avatar-status-dot status-<?= e($standingType) ?>" title="Standing: <?= e(ucfirst($standingType)) ?>"></span>
          </div>

          <h2 class="identity-name"><?= e($account['name']) ?></h2>
          <span class="identity-email">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
            <span><?= e($account['email']) ?></span>
          </span>

          <div class="identity-badges-stack">
            <div style="display:flex;justify-content:center;gap:6px;flex-wrap:wrap;">
              <span class="portal-badge <?= $account['account_status'] === 'approved' ? 'badge-success' : 'badge-warning' ?>">
                Account: <?= e(ucfirst($account['account_status'])) ?>
              </span>
              <span class="portal-badge <?= $account['email_verified_at'] ? 'badge-success' : 'badge-neutral' ?>">
                <?= $account['email_verified_at'] ? 'Email Verified' : 'Unverified' ?>
              </span>
            </div>

            <div class="sidebar-standing-pill standing-<?= e($standingType) ?>" style="justify-content:center;margin:4px auto 0;">
              <span class="standing-indicator-dot"></span>
              <span><?= e(ucfirst($standingType)) ?> Standing (<?= $standingPoints ?> pt<?= $standingPoints === 1 ? '' : 's' ?>)</span>
            </div>
          </div>

          <div class="identity-meta-box">
            <div class="identity-meta-row">
              <span>Member Since:</span>
              <strong><?= e(date('M Y', strtotime($account['created_at']))) ?></strong>
            </div>
            <div class="identity-meta-row">
              <span>Primary Role:</span>
              <strong>Customer Workspace</strong>
            </div>
            <div class="identity-meta-row">
              <span>Encryption:</span>
              <strong>bcrypt (cost 10)</strong>
            </div>
          </div>

          <div style="display:flex;flex-direction:column;gap:8px;margin-top:20px;">
            <a href="#contact-profile" class="portal-btn-outline" style="justify-content:center;height:38px;font-size:0.82rem;">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              <span>Contact Profile</span>
            </a>
            <a href="#security-password" class="portal-btn-outline" style="justify-content:center;height:38px;font-size:0.82rem;">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              <span>Change Password</span>
            </a>
            <a href="/customer/standing.php" class="portal-btn-outline" style="justify-content:center;height:38px;font-size:0.82rem;">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
              <span>Fleet Standing Policy</span>
            </a>
          </div>
        </aside>

        <!-- RIGHT COLUMN: Settings Forms -->
        <div style="display:flex;flex-direction:column;gap:24px;">
          <!-- Card 1: Contact & Billing Profile -->
          <section class="settings-section-card" id="contact-profile">
            <div class="settings-card-header">
              <div style="display:flex;align-items:center;gap:10px;">
                <span class="security-protection-icon" style="background:#0f172a;" aria-hidden="true">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                </span>
                <div>
                  <h2 style="margin:0 0 2px;">Contact Information & Billing Profile</h2>
                  <p style="margin:0;">These credentials appear on vehicle turnover agreements and official receipts.</p>
                </div>
              </div>
            </div>

            <?php if ($profileMessage): ?>
              <div class="portal-alert-success" style="margin-bottom:18px;">
                <span class="alert-icon" aria-hidden="true">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                </span>
                <span><?= e($profileMessage) ?></span>
              </div>
            <?php endif; ?>

            <?php if ($profileError): ?>
              <div class="portal-alert-danger" style="margin-bottom:18px;">
                <span class="alert-icon" aria-hidden="true">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                </span>
                <span><?= e($profileError) ?></span>
              </div>
            <?php endif; ?>

            <form method="post">
              <input type="hidden" name="action" value="update_profile">
              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

              <div class="settings-form-grid">
                <!-- Full Name -->
                <div class="settings-field-group">
                  <label for="profile-name">Full Legal Name</label>
                  <div class="auth-input-wrapper">
                    <span class="auth-input-icon" aria-hidden="true">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    </span>
                    <input id="profile-name" type="text" name="name" value="<?= e($account['name']) ?>" required autocomplete="name">
                  </div>
                </div>

                <!-- Email Address -->
                <div class="settings-field-group">
                  <label for="profile-email">Email Address</label>
                  <div class="auth-input-wrapper">
                    <span class="auth-input-icon" aria-hidden="true">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                    </span>
                    <input id="profile-email" type="email" name="email" value="<?= e($account['email']) ?>" required autocomplete="email">
                  </div>
                </div>

                <!-- Phone -->
                <div class="settings-field-group">
                  <label for="profile-phone">Mobile Phone Number</label>
                  <div class="auth-input-wrapper">
                    <span class="auth-input-icon" aria-hidden="true">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                    </span>
                    <input id="profile-phone" type="tel" name="phone" value="<?= e($account['phone'] ?? '') ?>" placeholder="+63 900 000 0000" autocomplete="tel">
                  </div>
                </div>

                <!-- Profile Photo URL -->
                <div class="settings-field-group">
                  <label for="profile-image">Profile Photo URL (Optional)</label>
                  <div class="auth-input-wrapper">
                    <span class="auth-input-icon" aria-hidden="true">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>
                    </span>
                    <input id="profile-image" type="url" name="profile_image" value="<?= e($account['profile_image'] ?? '') ?>" placeholder="https://example.com/avatar.jpg">
                  </div>
                </div>

                <!-- Preferred Payment Method Selectable Cards -->
                <div class="settings-field-group settings-full-width">
                  <label>Preferred Settlement Method</label>
                  <input type="hidden" name="preferred_payment_method" id="selected-payment-method" value="<?= e($account['preferred_payment_method'] ?? '') ?>">
                  <div class="payment-method-selector-grid">
                    <div class="payment-method-radio-card <?= ($account['preferred_payment_method'] ?? '') === 'card' ? 'active' : '' ?>" onclick="selectPaymentMethod('card')">
                      <span class="radio-card-indicator"></span>
                      <div class="radio-card-text">
                        <strong>Credit / Debit Card</strong>
                        <span>Visa, Mastercard, JCB</span>
                      </div>
                    </div>

                    <div class="payment-method-radio-card <?= ($account['preferred_payment_method'] ?? '') === 'gcash' ? 'active' : '' ?>" onclick="selectPaymentMethod('gcash')">
                      <span class="radio-card-indicator"></span>
                      <div class="radio-card-text">
                        <strong>GCash / Maya e-Wallet</strong>
                        <span>Instant QR & mobile checkout</span>
                      </div>
                    </div>

                    <div class="payment-method-radio-card <?= ($account['preferred_payment_method'] ?? '') === 'bank_transfer' ? 'active' : '' ?>" onclick="selectPaymentMethod('bank_transfer')">
                      <span class="radio-card-indicator"></span>
                      <div class="radio-card-text">
                        <strong>Online Bank Transfer</strong>
                        <span>BDO, BPI, UnionBank</span>
                      </div>
                    </div>

                    <div class="payment-method-radio-card <?= ($account['preferred_payment_method'] ?? '') === 'cash' ? 'active' : '' ?>" onclick="selectPaymentMethod('cash')">
                      <span class="radio-card-indicator"></span>
                      <div class="radio-card-text">
                        <strong>Cash on Pickup</strong>
                        <span>Settled at hub counter</span>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Address -->
                <div class="settings-field-group settings-full-width">
                  <label for="profile-address">Address / Regional Billing Location</label>
                  <textarea id="profile-address" name="address" rows="2" placeholder="Unit / Street, Barangay, City, Postal Code"><?= e($account['address'] ?? '') ?></textarea>
                </div>
              </div>

              <div style="margin-top:24px;display:flex;justify-content:flex-end;">
                <button type="submit" class="portal-btn-primary" style="height:44px;padding:0 24px;">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                  <span>Save Contact Profile</span>
                </button>
              </div>
            </form>
          </section>

          <!-- Card 2: Security & Password Management -->
          <section class="settings-section-card" id="security-password">
            <div class="settings-card-header">
              <div style="display:flex;align-items:center;gap:10px;">
                <span class="security-protection-icon" style="background:#e53e3e;" aria-hidden="true">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                </span>
                <div>
                  <h2 style="margin:0 0 2px;">Security & Password Protection</h2>
                  <p style="margin:0;">Update your account access credentials with an encrypted password.</p>
                </div>
              </div>
            </div>

            <!-- Security Banner -->
            <div class="security-protection-banner">
              <span class="security-protection-icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
              </span>
              <div>
                <strong style="display:block;font-size:0.84rem;color:#0f172a;">Cryptographic Protection</strong>
                <span style="font-size:0.78rem;color:#64748b;">Credentials are salted and hashed using standard bcrypt algorithm before persistence.</span>
              </div>
            </div>

            <?php if ($passwordMessage): ?>
              <div class="portal-alert-success" style="margin-bottom:18px;">
                <span class="alert-icon" aria-hidden="true">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                </span>
                <span><?= e($passwordMessage) ?></span>
              </div>
            <?php endif; ?>

            <?php if ($passwordError): ?>
              <div class="portal-alert-danger" style="margin-bottom:18px;">
                <span class="alert-icon" aria-hidden="true">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                </span>
                <span><?= e($passwordError) ?></span>
              </div>
            <?php endif; ?>

            <form method="post">
              <input type="hidden" name="action" value="change_password">
              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

              <div style="display:flex;flex-direction:column;gap:18px;max-width:540px;">
                <!-- Current Password -->
                <div class="settings-field-group">
                  <label for="current_password">Current Account Password</label>
                  <div class="auth-input-wrapper">
                    <span class="auth-input-icon" aria-hidden="true">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    </span>
                    <input id="current_password" type="password" name="current_password" required autocomplete="current-password" placeholder="Enter your current password">
                    <button type="button" class="auth-password-toggle" data-password-toggle="current_password" aria-label="Toggle current password visibility" aria-pressed="false">
                      <span class="toggle-icon" aria-hidden="true">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                      </span>
                      <span class="toggle-label">Show</span>
                    </button>
                  </div>
                </div>

                <!-- New Password -->
                <div class="settings-field-group">
                  <label for="new_password">New Password</label>
                  <div class="auth-input-wrapper">
                    <span class="auth-input-icon" aria-hidden="true">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2l-2 2m-1.5 1.5L14 9l-1.5-1.5 2.5-2.5L15 5l2-2 4 4-2 2z"/><path d="M3 21h7l9-9-7-7-9 9v7z"/></svg>
                    </span>
                    <input id="new_password" type="password" name="new_password" required minlength="8" autocomplete="new-password" placeholder="Minimum 8 characters">
                    <button type="button" class="auth-password-toggle" data-password-toggle="new_password" aria-label="Toggle new password visibility" aria-pressed="false">
                      <span class="toggle-icon" aria-hidden="true">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                      </span>
                      <span class="toggle-label">Show</span>
                    </button>
                  </div>
                </div>

                <!-- Confirm New Password -->
                <div class="settings-field-group">
                  <label for="confirm_password">Confirm New Password</label>
                  <div class="auth-input-wrapper">
                    <span class="auth-input-icon" aria-hidden="true">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
                    </span>
                    <input id="confirm_password" type="password" name="confirm_password" required minlength="8" autocomplete="new-password" placeholder="Re-enter your new password">
                    <button type="button" class="auth-password-toggle" data-password-toggle="confirm_password" aria-label="Toggle confirm password visibility" aria-pressed="false">
                      <span class="toggle-icon" aria-hidden="true">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                      </span>
                      <span class="toggle-label">Show</span>
                    </button>
                  </div>
                </div>

                <!-- Password Guidelines Checklist -->
                <div class="settings-password-checklist">
                  <div class="checklist-item">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                    <span>Must be at least 8 characters in length</span>
                  </div>
                  <div class="checklist-item" style="margin-top:4px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
                    <span>Use a secure combination of letters, numbers, and symbols</span>
                  </div>
                </div>

                <div style="margin-top:12px;">
                  <button type="submit" class="portal-btn-primary" style="height:44px;padding:0 24px;">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    <span>Update Security Password</span>
                  </button>
                </div>
              </div>
            </form>
          </section>
        </div>
      </div>
    </div>
  </div>
</main>

<script>
function selectPaymentMethod(method) {
  const input = document.getElementById('selected-payment-method');
  if (input) input.value = method;
  document.querySelectorAll('.payment-method-radio-card').forEach(card => {
    card.classList.remove('active');
  });
  event.currentTarget.classList.add('active');
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
