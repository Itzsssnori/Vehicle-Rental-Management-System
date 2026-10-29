<?php
$title = 'Workspace Account Settings';
require_once __DIR__ . '/../../config.php';
$user = current_user();
if (!$user || !in_array($user['role'] ?? '', ['staff', 'admin'], true)) {
    header('Location: /login.php');
    exit;
}

$message = '';
$error = '';
$accountStmt = db()->prepare('SELECT id, name, email, role, account_status, created_at FROM users WHERE id = ? LIMIT 1');
$accountStmt->execute([(int)$user['id']]);
$account = $accountStmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid name and email address.';
    } elseif ($newPassword !== '' && (strlen($newPassword) < 8 || $newPassword !== $confirmPassword)) {
        $error = 'A new password must be at least 8 characters and match the confirmation.';
    } else {
        $duplicate = db()->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
        $duplicate->execute([$email, (int)$user['id']]);
        if ($duplicate->fetch()) {
            $error = 'That email address is already in use.';
        } else {
            $pdo = db();
            if ($newPassword !== '') {
                $passwordStmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
                $passwordStmt->execute([(int)$user['id']]);
                $passwordHash = $passwordStmt->fetchColumn();
                if (!$currentPassword || !$passwordHash || !password_verify($currentPassword, $passwordHash)) {
                    $error = 'Enter your current password to set a new password.';
                }
            }
            if ($error === '') {
                if ($newPassword !== '') {
                    $update = $pdo->prepare('UPDATE users SET name = ?, email = ?, password_hash = ? WHERE id = ?');
                    $update->execute([$name, $email, password_hash($newPassword, PASSWORD_DEFAULT), (int)$user['id']]);
                } else {
                    $update = $pdo->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?');
                    $update->execute([$name, $email, (int)$user['id']]);
                }
                $_SESSION['user']['name'] = $name;
                $_SESSION['user']['email'] = $email;
                $account = array_merge($account, ['name' => $name, 'email' => $email]);
                $message = $newPassword !== '' ? 'Profile and password updated.' : 'Profile details updated.';
            }
        }
    }
}
include __DIR__ . '/../../includes/header.php';
?>
<main class="admin-page workspace-account-page">
  <div class="admin-heading">
    <div><p class="eyebrow"><?= $user['role'] === 'admin' ? 'Administrator account' : 'Faculty / staff account' ?></p><h1>Account settings</h1><p>Manage your workspace identity and sign-in security.</p></div>
    <a class="btn btn-navy" href="<?= $user['role'] === 'admin' ? '/admin/admin-dashboard.php' : '/staff/staff-dashboard.php' ?>">Back to workspace</a>
  </div>
  <?php if ($message): ?><p class="note request-success" role="status"><?= e($message) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="form-error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <div class="workspace-account-grid">
    <section class="admin-card">
      <p class="eyebrow">Profile</p>
      <h2><?= e($user['role'] === 'admin' ? 'Administrator profile' : 'Staff profile') ?></h2>
      <p class="admin-form-help">These details identify you in the VELO workspace and activity records.</p>
      <form class="detail-form" method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label>Full name<input name="name" value="<?= e($account['name']) ?>" required></label>
        <label>Work email<input type="email" name="email" value="<?= e($account['email']) ?>" required></label>
        <label>Role<input value="<?= e($user['role'] === 'admin' ? 'Administrator' : 'Faculty / Staff') ?>" disabled></label>
        <label>Account status<input value="<?= e(ucfirst($account['account_status'])) ?>" disabled></label>
        <button class="btn btn-coral" type="submit">Save profile</button>
      </form>
    </section>
    <section class="admin-card">
      <p class="eyebrow">Security</p>
      <h2>Change password</h2>
      <p class="admin-form-help">Use your current password to protect changes to your workspace account.</p>
      <form class="detail-form" method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="name" value="<?= e($account['name']) ?>">
        <input type="hidden" name="email" value="<?= e($account['email']) ?>">
        <label>Current password<input type="password" name="current_password" autocomplete="current-password"></label>
        <label>New password<input type="password" name="new_password" minlength="8" autocomplete="new-password"></label>
        <label>Confirm new password<input type="password" name="confirm_password" minlength="8" autocomplete="new-password"></label>
        <button class="btn btn-navy" type="submit">Update security</button>
      </form>
    </section>
  </div>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
