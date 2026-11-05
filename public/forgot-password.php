<?php
$title = 'Forgot Password - Car Rental Portal';
require_once __DIR__ . '/../config.php';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim((string)($_POST['email'] ?? ''));
    if (!auth_rate_limited('password_reset', $email)) {
        $stmt = db()->prepare('SELECT id, email FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user) {
            $token = issue_auth_token(db(), (int)$user['id'], 'password_reset', 1);
            deliver_auth_link('password reset', $email, app_url('/reset-password.php?token=' . urlencode($token)));
        }
    }
    $message = 'If an account exists for that email, reset instructions will be sent.';
}

$demoLink = $_SESSION['demo_auth_link'] ?? '';
unset($_SESSION['demo_auth_link']);
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
        <span class="auth-eyebrow">Account Recovery</span>
        <h1 class="auth-title">Reset Your Password</h1>
        <p class="auth-intro">Enter the email address registered with your account. We will send a secure link to reset your password.</p>
      </div>

      <?php if ($message): ?>
        <div class="portal-alert-success" role="status">
          <span class="alert-icon" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          </span>
          <div>
            <strong>Request Received</strong>
            <p style="margin:4px 0 0;font-size:0.88rem;"><?= e($message) ?></p>
          </div>
        </div>
      <?php endif; ?>

      <form class="portal-auth-form" method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <div class="auth-field-group">
          <label for="recovery-email">
            <span class="field-icon" aria-hidden="true">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
            </span>
            <span>Registered Email Address</span>
          </label>
          <input id="recovery-email" type="email" name="email" autocomplete="email" placeholder="name@example.com" required value="<?= e($_POST['email'] ?? '') ?>">
        </div>

        <button class="portal-btn-primary auth-submit-btn" type="submit">
          <span>Send Reset Instructions</span>
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
        </button>
      </form>

      <?php if ($demoLink): ?>
        <div class="portal-demo-banner" role="note">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
          <div>
            <strong>Demo Environment Link:</strong>
            <a href="<?= e($demoLink) ?>">Open the generated password reset link</a>
          </div>
        </div>
      <?php endif; ?>

      <div class="auth-divider-line"></div>

      <div class="auth-footer-links">
        <p>Remember your password? <a href="/login.php">Back to login</a></p>
        <p class="secondary-help">Need a new account? <a href="/register.php">Create customer account</a></p>
      </div>
    </section>
  </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
