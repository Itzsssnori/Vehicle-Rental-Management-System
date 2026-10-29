<?php
$title = 'My Dashboard';
require_once __DIR__ . '/../../config.php';
$user = require_customer();
$accountStmt = db()->prepare('SELECT name, email, phone, address, profile_image, preferred_payment_method FROM users WHERE id = ?');
$accountStmt->execute([$user['id']]);
$account = $accountStmt->fetch();
$accountMessage = '';
$accountError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_account') {
    verify_csrf();
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $profileImage = trim($_POST['profile_image'] ?? '');
    $paymentMethod = $_POST['preferred_payment_method'] ?? '';
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($paymentMethod, ['', 'cash', 'gcash', 'card'], true)) {
        $accountError = 'Enter a valid name, email, and payment method.';
    } else {
        $duplicate = db()->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
        $duplicate->execute([$email, $user['id']]);
        if ($duplicate->fetch()) {
            $accountError = 'That email address is already in use.';
        } elseif ($profileImage !== '' && !preg_match('#^https?://#i', $profileImage)) {
            $accountError = 'Profile image must be a valid http or https image URL.';
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
            ]);
            $account['preferred_payment_method'] = $paymentMethod;
            $accountMessage = 'Account details updated.';
        }
    }
}
include __DIR__ . '/../../includes/header.php';

$stmt = db()->prepare(
    "SELECT r.id, r.start_date, r.end_date, r.actual_return, r.total_amount, r.status,
            v.brand, v.model, v.category, v.image,
            COALESCE(SUM(p.amount), 0) AS paid_amount
     FROM rentals r
     INNER JOIN vehicles v ON v.id = r.vehicle_id
     LEFT JOIN payments p ON p.rental_id = r.id
     WHERE r.user_id = ?
     GROUP BY r.id, v.id
     ORDER BY r.start_date DESC, r.created_at DESC"
);
$stmt->execute([$user['id']]);
$rentals = $stmt->fetchAll();

$active = array_values(array_filter($rentals, static fn(array $r): bool =>
    in_array($r['status'], ['pending', 'confirmed', 'ongoing'], true)
));
$upcoming = array_values(array_filter($rentals, static fn(array $r): bool =>
    in_array($r['status'], ['pending', 'confirmed'], true) && $r['start_date'] >= date('Y-m-d')
));
$paidTotal = array_sum(array_map(static fn(array $r): float => (float)$r['paid_amount'], $rentals));
$activeRentals = array_values(array_filter($rentals, static fn(array $r): bool =>
    in_array($r['status'], ['pending', 'confirmed', 'ongoing'], true)
));
$historyRentals = array_values(array_filter($rentals, static fn(array $r): bool =>
    in_array($r['status'], ['completed', 'cancelled', 'rejected'], true)
));
$historyRentals = array_slice($historyRentals, 0, 3);
?>
<main class="dashboard-page">
  <div class="dashboard-layout">
    <?php include __DIR__ . '/../../includes/customer-sidebar.php'; ?>

    <div class="dashboard-content">
      <section class="dashboard-hero" id="overview">
        <div>
          <p class="eyebrow">Customer dashboard</p>
          <h1>Good to see you, <?= e($user['name']) ?>.</h1>
          <p>Keep track of your requests, upcoming trips, and rental history.</p>
        </div>
        <a class="btn btn-coral" href="/vehicles.php">Find a vehicle</a>
      </section>
      <?php if (isset($_GET['requested'])): ?>
        <p class="note request-success" role="status">Your rental request was submitted successfully. Staff or an administrator will review it before confirmation.</p>
      <?php endif; ?>

      <section class="dashboard-stats" aria-label="Rental summary">
    <article class="summary-card">
      <span class="summary-label">Active rentals</span>
      <strong><?= count($active) ?></strong>
      <span>Pending, confirmed, or ongoing</span>
    </article>
    <article class="summary-card">
      <span class="summary-label">Upcoming trips</span>
      <strong><?= count($upcoming) ?></strong>
      <span>Ready for your next journey</span>
    </article>
    <article class="summary-card">
      <span class="summary-label">Recorded payments</span>
      <strong>₱<?= number_format($paidTotal) ?></strong>
      <span>Across your rental history</span>
    </article>
      </section>

      <section class="dashboard-section" id="rentals">
        <div class="section-head">
          <div>
            <p class="eyebrow">Your rentals</p>
            <h2>Booking overview</h2>
          </div>
          <a class="text-link" href="/customer/my-rentals.php">View all rentals</a>
        </div>

        <?php if (!$activeRentals): ?>
          <div class="empty-state">
            <h3>No active rentals</h3>
            <p>Submit a request to see its approval and trip status here.</p>
            <a class="btn btn-navy" href="/vehicles.php">Browse Vehicles</a>
          </div>
        <?php else: ?>
          <div class="rental-list">
            <?php foreach (array_slice($activeRentals, 0, 3) as $rental):
              $paymentStatus = (float)$rental['paid_amount'] >= (float)$rental['total_amount'] ? 'Paid' : 'Payment pending';
              $statusClass = in_array($rental['status'], ['confirmed', 'ongoing', 'completed'], true) ? 'status-success' : (in_array($rental['status'], ['cancelled', 'rejected'], true) ? 'status-danger' : 'status-neutral');
            ?>
              <article class="rental-row">
                <div class="rental-main">
                  <div>
                    <span class="tag"><?= e($rental['category']) ?></span>
                    <h3><?= e($rental['brand']) ?> <?= e($rental['model']) ?></h3>
                    <p><?= e(date('M j, Y', strtotime($rental['start_date']))) ?> – <?= e(date('M j, Y', strtotime($rental['end_date']))) ?></p>
                  </div>
                  <div class="rental-statuses">
                    <span class="status-badge <?= $statusClass ?>"><?= e(ucfirst($rental['status'])) ?></span>
                    <span class="status-badge <?= $paymentStatus === 'Paid' ? 'status-success' : 'status-neutral' ?>"><?= e($paymentStatus) ?></span>
                  </div>
                </div>
                <div class="rental-meta">
                  <?php $days = rental_days($rental['start_date'], $rental['end_date']); ?>
                  <span><?= $days ?> day(s) × ₱<?= number_format((float)$rental['total_amount'] / $days) ?> / day</span>
                  <strong>₱<?= number_format((float)$rental['total_amount']) ?></strong>
                  <span>Total amount</span>
                  <span>Booking #<?= (int)$rental['id'] ?></span>
                  <a class="text-link rental-detail-link" href="/customer/rental.php?id=<?= (int)$rental['id'] ?>">View details</a>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <section class="dashboard-account" id="account">
        <div>
          <p class="eyebrow">Account</p>
          <h2>Account settings</h2>
          <p>Signed in as <strong><?= e($user['email']) ?></strong>. Update your profile, address, and preferred payment method.</p>
        </div>
        <a class="btn btn-navy" href="#account-modal">Manage account</a>
      </section>
    </div>
  </div>
  <div class="account-modal" id="account-modal" role="dialog" aria-modal="true" aria-labelledby="account-modal-title">
    <a class="account-modal-backdrop" href="#overview" aria-label="Close account settings"></a>
    <section class="account-modal-card">
      <a class="account-modal-close" href="#overview" aria-label="Close account settings">×</a>
      <p class="eyebrow">Customer account</p>
      <h2 id="account-modal-title">Manage account</h2>
      <p class="modal-intro">Update your contact details and preferred payment method.</p>
      <?php if ($accountMessage): ?><p class="note request-success"><?= e($accountMessage) ?></p><?php endif; ?>
      <?php if ($accountError): ?><p class="form-error"><?= e($accountError) ?></p><?php endif; ?>
      <form class="detail-form" method="post">
        <input type="hidden" name="action" value="update_account">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <div class="account-profile-preview">
          <div class="account-avatar" style="<?= $account['profile_image'] ? 'background-image:url(' . e($account['profile_image']) . ')' : '' ?>"><?php if (!$account['profile_image']): ?><?= e(strtoupper(substr($account['name'], 0, 1))) ?><?php endif; ?></div>
          <div><strong><?= e($account['name']) ?></strong><span>Customer profile</span></div>
        </div>
        <div class="modal-form-grid">
          <label>Full name<input type="text" name="name" value="<?= e($account['name']) ?>" required></label>
          <label>Email address<input type="email" name="email" value="<?= e($account['email']) ?>" required></label>
          <label>Phone number<input type="tel" name="phone" value="<?= e($account['phone']) ?>" placeholder="+63 900 000 0000"></label>
          <label>Preferred payment<select name="preferred_payment_method"><option value="">No preference</option><?php foreach (['cash', 'gcash', 'card'] as $method): ?><option value="<?= $method ?>" <?= $account['preferred_payment_method'] === $method ? 'selected' : '' ?>><?= ucfirst($method) ?></option><?php endforeach; ?></select></label>
        </div>
        <label>Address<textarea name="address" rows="2" placeholder="Your pickup or billing address"><?= e($account['address']) ?></textarea></label>
        <label>Profile image URL<input type="url" name="profile_image" value="<?= e($account['profile_image']) ?>" placeholder="https://example.com/profile.jpg"></label>
        <button class="btn btn-coral" type="submit">Save account details</button>
      </form>
    </section>
  </div>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
