<?php
$title = 'Log in';
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
        $stmt = db()->prepare('SELECT id, name, email, password_hash, role, account_status FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $error = 'The email or password is incorrect.';
        } elseif ($user['account_status'] === 'pending') {
            $error = 'Your faculty/staff registration is waiting for administrator approval.';
        } elseif ($user['account_status'] === 'rejected') {
            $error = 'Your faculty/staff registration was not approved. Please contact an administrator.';
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
<main class="auth-page">
  <section class="auth-card">
    <p class="eyebrow">Welcome back</p>
    <h1>Log in to VELO.</h1>
    <p class="auth-intro">Customers can track rentals here. Faculty/staff and administrators are sent to their workspaces automatically after login.</p>

    <?php if ($error): ?>
      <p class="form-error" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <form class="detail-form" method="post">
      <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <label>
        Email address
        <input type="email" name="email" autocomplete="email" required value="<?= e($_POST['email'] ?? '') ?>">
      </label>
      <label>
        Password
        <input type="password" name="password" autocomplete="current-password" required>
      </label>
      <button class="btn btn-coral" type="submit">Log in</button>
    </form>

    <p class="auth-footnote">New to VELO? <a href="/register.php">Create a customer account</a></p>
  </section>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
