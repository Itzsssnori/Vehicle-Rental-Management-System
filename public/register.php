<?php
$title = 'Register - Car Rental Portal';
require_once __DIR__ . '/../config.php';
$error = '';

if (current_user()) {
    header('Location: /customer/dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8 || $password !== $confirmPassword) {
        $error = 'Enter your name, a valid email, and matching passwords of at least 8 characters.';
    } else {
        $stmt = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'An account with that email already exists.';
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, account_status) VALUES (?, ?, ?, 'approved')");
                $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
                $userId = (int)$pdo->lastInsertId();
                $role = $pdo->prepare("SELECT id FROM roles WHERE name = 'customer' LIMIT 1");
                $role->execute();
                $roleId = $role->fetchColumn();
                if (!$roleId) {
                    throw new RuntimeException('Customer role is not configured.');
                }
                $assignment = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
                $assignment->execute([$userId, $roleId]);
                $token = issue_auth_token($pdo, $userId, 'email_verification', 24);
                write_audit($pdo, $userId, 'customer_registered', 'users', $userId, 'Public customer account created.');
                $pdo->commit();
                deliver_auth_link('email verification', $email, app_url('/verify-email.php?token=' . urlencode($token)));
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Customer registration failed: ' . $exception->getMessage());
                $error = 'The account could not be created. Please try again.';
            }
            if ($error === '') {
                header('Location: /verify-email.php?sent=1');
                exit;
            }
        }
    }
}
include __DIR__ . '/../includes/header.php';
?>

<main class="portal-auth-page">
  <div class="wrap auth-container-wrap">
    <section class="portal-auth-card">
      <div class="auth-card-brand">
        <span class="auth-brand-badge" aria-hidden="true">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/>
            <circle cx="7" cy="17" r="2"/>
            <path d="M9 17h6"/>
            <circle cx="17" cy="17" r="2"/>
          </svg>
        </span>
        <div class="auth-brand-text">
          <span class="auth-brand-title">Car Rental <em>Portal</em></span>
          <span class="auth-brand-sub">VELO FLEET SYSTEM</span>
        </div>
      </div>

      <div class="auth-header-block">
        <span class="auth-eyebrow">Start Your Journey</span>
        <h1 class="auth-title">Create Your Account</h1>
        <p class="auth-intro">Register as a customer to browse our fleet, verify availability, and book your next journey.</p>
      </div>

      <div class="portal-alert-info">
        <span class="alert-icon" aria-hidden="true">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
        </span>
        <span>A verification link will be sent to your email before completing reservations.</span>
      </div>

      <?php if ($error): ?>
        <div class="portal-alert-danger" role="alert">
          <span class="alert-icon" aria-hidden="true">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
          </span>
          <span><?= e($error) ?></span>
        </div>
      <?php endif; ?>

      <form class="portal-auth-form" method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <div class="auth-field-group">
          <label for="register-name">
            <span class="field-icon" aria-hidden="true">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            </span>
            <span>Full Name</span>
          </label>
          <input id="register-name" type="text" name="name" autocomplete="name" placeholder="e.g. Maria Santos" required value="<?= e($_POST['name'] ?? '') ?>">
        </div>

        <div class="auth-field-group">
          <label for="register-email">
            <span class="field-icon" aria-hidden="true">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
            </span>
            <span>Email Address</span>
          </label>
          <input id="register-email" type="email" name="email" autocomplete="email" placeholder="name@example.com" required value="<?= e($_POST['email'] ?? '') ?>">
        </div>

        <div class="auth-field-group">
          <label for="registration-password">
            <span class="field-icon" aria-hidden="true">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </span>
            <span>Password</span>
          </label>
          <div class="password-field">
            <input id="registration-password" type="password" name="password" autocomplete="new-password" placeholder="At least 8 characters" minlength="8" required>
            <button class="password-toggle" type="button" data-password-toggle="registration-password" aria-controls="registration-password" aria-pressed="false">
              <span class="toggle-icon" aria-hidden="true">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
              </span>
              <span class="toggle-label">Show</span>
            </button>
          </div>
        </div>

        <div class="auth-field-group">
          <label for="registration-confirm-password">
            <span class="field-icon" aria-hidden="true">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/><path d="m9 12 2 2 4-4"/></svg>
            </span>
            <span>Confirm Password</span>
          </label>
          <div class="password-field">
            <input id="registration-confirm-password" type="password" name="confirm_password" autocomplete="new-password" placeholder="Re-enter your password" minlength="8" required>
            <button class="password-toggle" type="button" data-password-toggle="registration-confirm-password" aria-controls="registration-confirm-password" aria-pressed="false">
              <span class="toggle-icon" aria-hidden="true">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
              </span>
              <span class="toggle-label">Show</span>
            </button>
          </div>
        </div>

        <div class="auth-req-box">
          <div class="req-title">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
            <span>Password Requirements:</span>
          </div>
          <ul>
            <li>Minimum of 8 characters</li>
            <li>Both password fields must match exactly</li>
          </ul>
        </div>

        <p class="auth-terms-text">By clicking Create Customer Account, you agree to the Car Rental Portal Terms of Service and Rental Agreement.</p>

        <button class="portal-btn-primary auth-submit-btn" type="submit">
          <span>Create Customer Account</span>
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
        </button>
      </form>

      <div class="auth-divider-line"></div>

      <div class="auth-footer-links">
        <p>Already have an account? <a href="/login.php">Log in</a></p>
        <p class="secondary-help">Didn't receive verification email? <a href="/resend-verification.php">Resend link</a></p>
      </div>
    </section>
  </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
