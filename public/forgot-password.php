<?php
$title = 'Forgot password';
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
<main class="auth-page"><section class="auth-card"><p class="eyebrow">Account recovery</p><h1>Reset your password.</h1>
<?php if ($message): ?><p class="request-success" role="status"><?= e($message) ?></p><?php endif; ?>
<form class="detail-form" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label>Email address<input type="email" name="email" autocomplete="email" required></label><button class="btn btn-coral" type="submit">Send reset link</button></form>
<?php if ($demoLink): ?><p class="note">Demo mode: <a href="<?= e($demoLink) ?>">Open the generated reset link</a>.</p><?php endif; ?>
<p class="auth-footnote"><a href="/login.php">Back to login</a></p></section></main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
