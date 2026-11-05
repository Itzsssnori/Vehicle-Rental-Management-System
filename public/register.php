<?php
$title = 'Create an account';
require_once __DIR__ . '/../config.php';
$error = '';

if (current_user()) {
    header('Location: /customer/dashboard.php');
    exit;
}
include __DIR__ . '/../includes/header.php';

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
?>
<main class="auth-page">
  <section class="auth-card">
    <p class="eyebrow">Start your journey</p>
    <h1>Create your VELO account.</h1>
    <p class="auth-intro">Create a customer account to browse vehicles and submit reservations.</p>
    <p class="note">You will need to verify your email address before making a reservation.</p>

    <?php if ($error): ?>
      <p class="form-error" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <form class="detail-form" method="post">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <label>
        Full name
        <input type="text" name="name" autocomplete="name" required value="<?= e($_POST['name'] ?? '') ?>">
      </label>
      <label>
        Email address
        <input type="email" name="email" autocomplete="email" required value="<?= e($_POST['email'] ?? '') ?>">
      </label>
      <label>
        Confirm password
        <span class="password-field">
          <input id="registration-confirm-password" type="password" name="confirm_password" autocomplete="new-password" minlength="8" required>
          <button class="password-toggle" type="button" data-password-toggle="registration-confirm-password" aria-controls="registration-confirm-password" aria-pressed="false">Show</button>
        </span>
      </label>
      <label>
        Password
        <span class="password-field">
          <input id="registration-password" type="password" name="password" autocomplete="new-password" minlength="8" required>
          <button class="password-toggle" type="button" data-password-toggle="registration-password" aria-controls="registration-password" aria-pressed="false">Show</button>
        </span>
      </label>
      <button class="btn btn-coral" type="submit">Create account</button>
    </form>

    <p class="auth-footnote">Already have an account? <a href="/login.php">Log in</a></p>
  </section>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
