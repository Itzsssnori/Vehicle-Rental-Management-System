<?php
$title = 'Verify your email';
require_once __DIR__ . '/../config.php';
$message = '';
$error = '';
$token = trim((string)($_GET['token'] ?? ''));

if ($token !== '') {
    $record = find_auth_token(db(), $token, 'email_verification');
    if (!$record) {
        $error = 'This verification link is invalid or has expired.';
    } else {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            consume_auth_token($pdo, (int)$record['token_id']);
            $pdo->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = ?')->execute([(int)$record['user_id']]);
            write_audit($pdo, (int)$record['user_id'], 'email_verified', 'users', (int)$record['user_id'], 'Email address verified.');
            $pdo->commit();
            $message = 'Your email is verified. You can now log in.';
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Email verification failed: ' . $exception->getMessage());
            $error = 'The verification link could not be completed. Please request a new link.';
        }
    }
} elseif (isset($_GET['sent'])) {
    $message = 'Check your email for a verification link.';
}
$demoLink = $_SESSION['demo_auth_link'] ?? '';
unset($_SESSION['demo_auth_link']);
include __DIR__ . '/../includes/header.php';
?>
<main class="auth-page"><section class="auth-card">
  <p class="eyebrow">Account verification</p><h1>Verify your email.</h1>
  <?php if ($message): ?><p class="request-success" role="status"><?= e($message) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="form-error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <?php if ($demoLink): ?><p class="note">Demo mode: <a href="<?= e($demoLink) ?>">Open the generated verification link</a>.</p><?php endif; ?>
  <p class="auth-footnote">Already verified? <a href="/login.php">Log in</a></p>
</section></main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
