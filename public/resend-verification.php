<?php
$title = 'Resend verification';
require_once __DIR__ . '/../config.php';
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim((string)($_POST['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || auth_rate_limited('email_verification', $email)) {
        $message = 'If an account exists for that email, a verification link will be sent.';
    } else {
        $stmt = db()->prepare('SELECT id, email, email_verified_at FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user && empty($user['email_verified_at'])) {
            $token = issue_auth_token(db(), (int)$user['id'], 'email_verification', 24);
            deliver_auth_link('email verification', $email, app_url('/verify-email.php?token=' . urlencode($token)));
        }
        $message = 'If an account exists for that email, a verification link will be sent.';
    }
}
$demoLink = $_SESSION['demo_auth_link'] ?? '';
unset($_SESSION['demo_auth_link']);
include __DIR__ . '/../includes/header.php';
?>
<main class="auth-page"><section class="auth-card"><p class="eyebrow">Account verification</p><h1>Resend verification.</h1>
<?php if ($message): ?><p class="request-success" role="status"><?= e($message) ?></p><?php endif; ?>
<form class="detail-form" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label>Email address<input type="email" name="email" autocomplete="email" required></label><button class="btn btn-coral" type="submit">Send verification link</button></form>
<?php if ($demoLink): ?><p class="note">Demo mode: <a href="<?= e($demoLink) ?>">Open the generated verification link</a>.</p><?php endif; ?>
</section></main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
