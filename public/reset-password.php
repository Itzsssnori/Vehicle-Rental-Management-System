<?php
$title = 'Set a new password';
require_once __DIR__ . '/../config.php';
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$error = '';
$message = '';
$record = $token !== '' ? find_auth_token(db(), $token, 'password_reset') : null;
if (!$record) $error = 'This password reset link is invalid or has expired.';
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
            $message = 'Your password was reset. You can now log in.';
            $record = null;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Password reset failed: ' . $exception->getMessage());
            $error = 'The password could not be reset. Please request a new link.';
        }
    }
}
include __DIR__ . '/../includes/header.php';
?>
<main class="auth-page"><section class="auth-card"><p class="eyebrow">Account recovery</p><h1>Choose a new password.</h1>
<?php if ($message): ?><p class="request-success" role="status"><?= e($message) ?></p><p class="auth-footnote"><a href="/login.php">Log in</a></p>
<?php elseif ($error): ?><p class="form-error" role="alert"><?= e($error) ?></p><p class="auth-footnote"><a href="/forgot-password.php">Request another link</a></p>
<?php else: ?><form class="detail-form" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="token" value="<?= e($token) ?>"><label>New password<span class="password-field"><input id="reset-password" type="password" name="password" minlength="8" autocomplete="new-password" required><button class="password-toggle" type="button" data-password-toggle="reset-password" aria-controls="reset-password" aria-pressed="false">Show</button></span></label><label>Confirm new password<span class="password-field"><input id="reset-confirm-password" type="password" name="confirm_password" minlength="8" autocomplete="new-password" required><button class="password-toggle" type="button" data-password-toggle="reset-confirm-password" aria-controls="reset-confirm-password" aria-pressed="false">Show</button></span></label><button class="btn btn-coral" type="submit">Set new password</button></form><?php endif; ?>
</section></main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
