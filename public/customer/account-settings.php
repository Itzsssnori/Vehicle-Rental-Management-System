<?php
$title = 'Account Settings';
require_once __DIR__ . '/../../config.php';
$user = require_customer();
$message = '';
$error = '';
$stmt = db()->prepare('SELECT id, name, email, phone, address, profile_image, preferred_payment_method FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$account = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $profileImage = trim($_POST['profile_image'] ?? '');
    $paymentMethod = $_POST['preferred_payment_method'] ?? '';

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($paymentMethod, ['', 'cash', 'gcash', 'card'], true)) {
        $error = 'Enter a valid name, email, and payment method.';
    } else {
        $duplicate = db()->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
        $duplicate->execute([$email, $user['id']]);
        if ($duplicate->fetch()) {
            $error = 'That email address is already in use.';
        } elseif ($profileImage !== '' && !preg_match('#^https?://#i', $profileImage)) {
            $error = 'Profile image must be a valid http or https image URL.';
        } else {
            $update = db()->prepare('UPDATE users SET name = ?, email = ?, phone = ?, address = ?, profile_image = ?, preferred_payment_method = ? WHERE id = ?');
            $update->execute([$name, $email, $phone ?: null, $address ?: null, $profileImage ?: null, $paymentMethod ?: null, $user['id']]);
            $_SESSION['user']['name'] = $name;
            $_SESSION['user']['email'] = $email;
            $account = array_merge($account, [
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'address' => $address,
                'profile_image' => $profileImage,
                'preferred_payment_method' => $paymentMethod,
            ]);
            $message = 'Account details updated.';
        }
    }
}
include __DIR__ . '/../../includes/header.php';
?>
<main class="dashboard-page">
  <div class="dashboard-layout">
    <?php include __DIR__ . '/../../includes/customer-sidebar.php'; ?>
    <div class="dashboard-content account-settings-content">
      <section class="dashboard-hero"><div><p class="eyebrow">Customer account</p><h1>Account settings</h1><p>Keep your contact details and preferred payment method up to date.</p></div></section>
      <?php if ($message): ?><p class="note request-success"><?= e($message) ?></p><?php endif; ?>
      <?php if ($error): ?><p class="form-error"><?= e($error) ?></p><?php endif; ?>
      <section class="admin-card account-form-card">
        <form class="detail-form" method="post">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <div class="account-profile-preview">
        <div class="account-avatar" style="<?= $account['profile_image'] ? 'background-image:url(' . e($account['profile_image']) . ')' : '' ?>">
          <?php if (!$account['profile_image']): ?><?= e(strtoupper(substr($account['name'], 0, 1))) ?><?php endif; ?>
        </div>
        <div><strong><?= e($account['name']) ?></strong><span>Customer profile</span></div>
      </div>
      <label>Full name<input type="text" name="name" value="<?= e($account['name']) ?>" required></label>
      <label>Email address<input type="email" name="email" value="<?= e($account['email']) ?>" required></label>
      <label>Phone number<input type="tel" name="phone" value="<?= e($account['phone']) ?>" placeholder="+63 900 000 0000"></label>
      <label>Address<textarea name="address" rows="3" placeholder="Your pickup or billing address"><?= e($account['address']) ?></textarea></label>
      <label>Profile image URL<input type="url" name="profile_image" value="<?= e($account['profile_image']) ?>" placeholder="https://example.com/profile.jpg"></label>
      <label>Preferred payment method<select name="preferred_payment_method"><option value="">No preference</option><?php foreach (['cash', 'gcash', 'card'] as $method): ?><option value="<?= $method ?>" <?= $account['preferred_payment_method'] === $method ? 'selected' : '' ?>><?= ucfirst($method) ?></option><?php endforeach; ?></select></label>
      <button class="btn btn-coral" type="submit">Save account details</button>
        </form>
      </section>
    </div>
  </div>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
