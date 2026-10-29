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
    $accountType = $_POST['account_type'] ?? 'customer';

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8 || !in_array($accountType, ['customer', 'staff'], true)) {
        $error = 'Enter your name, a valid email, and a password of at least 8 characters.';
    } else {
        $stmt = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'An account with that email already exists.';
        } else {
            $role = $accountType === 'staff' ? 'staff' : 'customer';
            $status = $role === 'staff' ? 'pending' : 'approved';
            $stmt = db()->prepare('INSERT INTO users (name, email, password_hash, role, account_status) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $status]);
            header('Location: /login.php?created=' . ($role === 'staff' ? 'staff' : '1'));
            exit;
        }
    }
}
?>
<main class="auth-page">
  <section class="auth-card">
    <p class="eyebrow">Start your journey</p>
    <h1>Create your VELO account.</h1>
    <p class="auth-intro">Create a customer account or request access to the faculty and staff workspace.</p>

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
        Password
        <input type="password" name="password" autocomplete="new-password" minlength="8" required>
      </label>
      <label>
        Account type
        <select name="account_type">
          <option value="customer" <?= ($_POST['account_type'] ?? 'customer') === 'customer' ? 'selected' : '' ?>>Customer</option>
          <option value="staff" <?= ($_POST['account_type'] ?? '') === 'staff' ? 'selected' : '' ?>>Faculty / Staff — requires admin approval</option>
        </select>
      </label>
      <button class="btn btn-coral" type="submit">Create account</button>
    </form>

    <p class="auth-footnote">Already have an account? <a href="/login.php">Log in</a></p>
  </section>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
