<?php
$title = 'Log in - Car Rental Portal';
require_once __DIR__ . '/../config.php';
$requestedRedirect = $_GET['redirect'] ?? '/customer/dashboard.php';
$redirect = preg_match('#^/?vehicle\.php\?id=\d+$#', $requestedRedirect) ? '/' . ltrim($requestedRedirect, '/') : '/customer/dashboard.php';
$error = '';

if (current_user()) {
    header('Location: ' . match (current_user()['role'] ?? '') {
        'admin' => '/admin/admin-dashboard.php',
        'staff' => '/staff/staff-dashboard.php',
        default => '/customer/dashboard.php',
    });
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $requestedRedirect = $_POST['redirect'] ?? '/customer/dashboard.php';
    $redirect = preg_match('#^/?vehicle\.php\?id=\d+$#', $requestedRedirect) ? '/' . ltrim($requestedRedirect, '/') : '/customer/dashboard.php';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        $error = 'Enter a valid email address and password.';
    } else {
        $stmt = db()->prepare(
            "SELECT u.id, u.name, u.email, u.password_hash, u.email_verified_at, u.account_status, r.name AS role
             FROM users u
             INNER JOIN user_roles ur ON ur.user_id = u.id
             INNER JOIN roles r ON r.id = ur.role_id
             WHERE u.email = ?
             ORDER BY CASE r.name WHEN 'admin' THEN 1 WHEN 'staff' THEN 2 ELSE 3 END
             LIMIT 1"
        );
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $error = 'The email or password is incorrect.';
        } elseif ($user['account_status'] === 'pending') {
            $error = 'Your staff registration is currently waiting for administrator approval.';
        } elseif ($user['account_status'] === 'rejected') {
            $error = 'Your staff registration was not approved. Please contact an administrator.';
        } elseif (empty($user['email_verified_at'])) {
            $error = 'Please verify your email address before logging in.';
        } else {
            session_regenerate_id(true);
            unset($user['password_hash']);
            $_SESSION['user'] = $user;
            $destination = match ($user['role']) {
                'admin' => '/admin/admin-dashboard.php',
                'staff' => '/staff/staff-dashboard.php',
                default => $redirect,
            };
            header('Location: ' . $destination);
            exit;
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
        <span class="auth-eyebrow">Welcome Back</span>
        <h1 class="auth-title">Log in to Your Account</h1>
        <p class="auth-intro">Sign in to submit rental requests, view receipts, or manage operations.</p>
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
        <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <div class="auth-field-group">
          <label for="login-email">
            <span class="field-icon" aria-hidden="true">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
            </span>
            <span>Email Address</span>
          </label>
          <input id="login-email" type="email" name="email" autocomplete="email" placeholder="name@example.com" required value="<?= e($_POST['email'] ?? '') ?>">
        </div>

        <div class="auth-field-group">
          <div class="auth-label-row">
            <label for="login-password">
              <span class="field-icon" aria-hidden="true">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              </span>
              <span>Password</span>
            </label>
            <a href="/forgot-password.php" class="auth-inline-link">Forgot password?</a>
          </div>
          <div class="password-field">
            <input id="login-password" type="password" name="password" autocomplete="current-password" placeholder="Enter your password" required>
            <button class="password-toggle" type="button" data-password-toggle="login-password" aria-controls="login-password" aria-pressed="false">
              <span class="toggle-icon" aria-hidden="true">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
              </span>
              <span class="toggle-label">Show</span>
            </button>
          </div>
        </div>

        <button class="portal-btn-primary auth-submit-btn" type="submit">
          <span>Log in to Account</span>
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
        </button>
      </form>

      <div class="auth-divider-line"></div>

      <div class="auth-footer-links">
        <p>New to Car Rental Portal? <a href="/register.php">Create a customer account</a></p>
        <p class="secondary-help">Staff access: Use administrator-provided credentials or <a href="/staff-register.php">submit staff registration</a></p>
        <p class="secondary-help">Didn't receive verification email? <a href="/resend-verification.php">Resend link</a></p>
      </div>
    </section>
  </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
