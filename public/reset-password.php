<?php
$title = 'Set New Password - Car Rental Portal';
require_once __DIR__ . '/../config.php';
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$error = '';
$message = '';
$record = $token !== '' ? find_auth_token(db(), $token, 'password_reset') : null;

if (!$record) {
    $error = 'This password reset link is invalid or has expired.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $record) {
    verify_csrf();
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    if (strlen($password) < 8 || $password !== $confirm) {
        $error = 'Use a password of at least 8 characters and make both fields match.';
    } else {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            consume_auth_token($pdo, (int)$record['token_id']);
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), (int)$record['user_id']]);
            write_audit($pdo, (int)$record['user_id'], 'password_reset', 'users', (int)$record['user_id'], 'Password reset completed.');
            $pdo->commit();
            session_regenerate_id(true);
            $message = 'Your password has been successfully reset. You can now log in with your new password.';
            $record = null;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Password reset failed: ' . $exception->getMessage());
            $error = 'The password could not be reset. Please request a new link.';
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
        <span class="auth-eyebrow">Account Security</span>
        <h1 class="auth-title">Choose a New Password</h1>
        <p class="auth-intro">Please enter and confirm your new secure password below to regain full account access.</p>
      </div>

      <?php if ($message): ?>
        <div class="portal-alert-success" role="status">
          <span class="alert-icon" aria-hidden="true">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          </span>
          <div>
            <strong>Password Reset Complete</strong>
            <p style="margin:4px 0 0;font-size:0.88rem;"><?= e($message) ?></p>
          </div>
        </div>

        <a href="/login.php" class="portal-btn-primary auth-submit-btn" style="text-decoration:none;">
          <span>Log in to Your Account</span>
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
        </a>
      <?php elseif ($error && !$record): ?>
        <div class="portal-alert-danger" role="alert">
          <span class="alert-icon" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
          </span>
          <div>
            <strong>Reset Link Invalid</strong>
            <p style="margin:4px 0 0;font-size:0.88rem;"><?= e($error) ?></p>
          </div>
        </div>

        <a href="/forgot-password.php" class="portal-btn-secondary auth-submit-btn" style="text-decoration:none;">
          <span>Request New Reset Link</span>
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
        </a>
      <?php else: ?>
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
          <input type="hidden" name="token" value="<?= e($token) ?>">

          <div class="auth-field-group">
            <label for="reset-password">
              <span class="field-icon" aria-hidden="true">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              </span>
              <span>New Password</span>
            </label>
            <div class="password-field">
              <input id="reset-password" type="password" name="password" minlength="8" autocomplete="new-password" placeholder="At least 8 characters" required>
              <button class="password-toggle" type="button" data-password-toggle="reset-password" aria-controls="reset-password" aria-pressed="false">
                <span class="toggle-icon" aria-hidden="true">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                </span>
                <span class="toggle-label">Show</span>
              </button>
            </div>
          </div>

          <div class="auth-field-group">
            <label for="reset-confirm-password">
              <span class="field-icon" aria-hidden="true">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10"/><path d="m9 12 2 2 4-4"/></svg>
              </span>
              <span>Confirm New Password</span>
            </label>
            <div class="password-field">
              <input id="reset-confirm-password" type="password" name="confirm_password" minlength="8" autocomplete="new-password" placeholder="Re-enter your new password" required>
              <button class="password-toggle" type="button" data-password-toggle="reset-confirm-password" aria-controls="reset-confirm-password" aria-pressed="false">
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
              <span>Security Requirements:</span>
            </div>
            <ul>
              <li>Minimum of 8 characters</li>
              <li>Must match password confirmation</li>
            </ul>
          </div>

          <button class="portal-btn-primary auth-submit-btn" type="submit">
            <span>Set New Password</span>
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
          </button>
        </form>
      <?php endif; ?>

      <div class="auth-divider-line"></div>

      <div class="auth-footer-links">
        <p><a href="/login.php">Back to login</a></p>
      </div>
    </section>
  </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
