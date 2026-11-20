<?php
$title = 'Verify Email - Car Rental Portal';
require_once __DIR__ . '/../config.php';
$message = '';
$error = '';
$token = trim((string)($_GET['token'] ?? ''));

if ($token !== '') {
    $record = find_auth_token(db(), $token, 'email_verification');
    if (!$record) {
        // Check if token was already consumed and user is already verified
        $alreadyStmt = db()->prepare(
            'SELECT t.id, t.used_at, u.id AS user_id, u.email, u.email_verified_at, u.name
             FROM auth_tokens t
             INNER JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ? AND t.token_type = ?
             LIMIT 1'
        );
        $alreadyStmt->execute([auth_token_hash($token), 'email_verification']);
        $already = $alreadyStmt->fetch();

        if ($already && !empty($already['email_verified_at'])) {
            $message = 'Your email address is already verified. You can log in and start booking vehicles.';
        } else {
            $error = 'This verification link is invalid or has expired. You can request a new verification link below.';
        }
    } else {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            consume_auth_token($pdo, (int)$record['token_id']);
            $pdo->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = ?')->execute([(int)$record['user_id']]);
            write_audit($pdo, (int)$record['user_id'], 'email_verified', 'users', (int)$record['user_id'], 'Email address verified.');
            $pdo->commit();
            $message = 'Your email has been verified successfully. You can now log in and book rentals.';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Email verification failed: ' . $exception->getMessage());
            $error = 'The verification could not be completed. Please request a new link.';
        }
    }
} elseif (isset($_GET['sent'])) {
    $message = 'A verification link has been prepared. Please check your inbox or spam folder.';
}

$demoLink = $_SESSION['demo_auth_link'] ?? ($_SESSION['last_auth_link'] ?? '');
if (!empty($_SESSION['demo_auth_link'])) {
    $_SESSION['last_auth_link'] = $_SESSION['demo_auth_link'];
}

$mailDispatch = $_SESSION['auth_mail_dispatch'] ?? ($_SESSION['last_mail_dispatch'] ?? null);
if (!empty($_SESSION['auth_mail_dispatch'])) {
    $_SESSION['last_mail_dispatch'] = $_SESSION['auth_mail_dispatch'];
}

// Clean up stored session links when verification successfully completes
if ($token !== '' && $message) {
    unset($_SESSION['demo_auth_link'], $_SESSION['last_auth_link'], $_SESSION['auth_mail_dispatch'], $_SESSION['last_mail_dispatch']);
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
        <span class="auth-eyebrow">Email Verification</span>
        <h1 class="auth-title">Confirm Your Email</h1>
        <p class="auth-intro">Email confirmation keeps your account secure and ensures instant booking confirmations.</p>
      </div>

      <?php if ($token !== '' && $message): ?>
        <div class="portal-alert-success" role="status">
          <span class="alert-icon" aria-hidden="true">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          </span>
          <div>
            <strong>Email Successfully Verified!</strong>
            <p style="margin:4px 0 0;font-size:0.88rem;"><?= e($message) ?></p>
          </div>
        </div>

        <a href="/login.php" class="portal-btn-primary auth-submit-btn" style="text-decoration:none;">
          <span>Log in to Your Account</span>
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
        </a>

      <?php elseif (isset($_GET['sent'])): ?>
        <?php if ($mailDispatch && $mailDispatch['success']): ?>
          <div class="portal-alert-success" role="status">
            <span class="alert-icon" aria-hidden="true">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </span>
            <div>
              <strong>Verification Email Dispatched!</strong>
              <p style="margin:4px 0 0;font-size:0.88rem;">
                A confirmation message was sent via <?= e(strtoupper($mailDispatch['mode'])) ?> to <strong><?= e($mailDispatch['email']) ?></strong>. Check your inbox and click the verification link.
              </p>
            </div>
          </div>
        <?php else: ?>
          <div class="portal-alert-info" role="status">
            <span class="alert-icon" aria-hidden="true">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
            </span>
            <div>
              <strong>Check Your Inbox</strong>
              <p style="margin:4px 0 0;font-size:0.88rem;"><?= e($message) ?></p>
            </div>
          </div>
        <?php endif; ?>

        <div style="margin-top:20px;">
          <a href="/login.php" class="portal-btn-primary auth-submit-btn" style="text-decoration:none;">
            <span>Go to Login</span>
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
          </a>
        </div>

      <?php elseif ($error): ?>
        <div class="portal-alert-danger" role="alert">
          <span class="alert-icon" aria-hidden="true">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
          </span>
          <div>
            <strong>Verification Link Failed</strong>
            <p style="margin:4px 0 0;font-size:0.88rem;"><?= e($error) ?></p>
          </div>
        </div>

        <a href="/resend-verification.php" class="portal-btn-secondary auth-submit-btn" style="text-decoration:none;">
          <span>Request New Verification Link</span>
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
        </a>
      <?php endif; ?>

      <div class="auth-divider-line"></div>

      <div class="auth-footer-links">
        <p>Already verified? <a href="/login.php">Log in</a></p>
        <p class="secondary-help">Didn't receive the email? <a href="/resend-verification.php">Resend link</a></p>
      </div>
    </section>
  </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
