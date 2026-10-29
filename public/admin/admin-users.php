<?php
$title = 'User Management';
require_once __DIR__ . '/../../config.php';
$user = require_role('admin');
$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $role = $_POST['role'] ?? '';
    $action = $_POST['action'] ?? 'role';
    if (!$id) {
        $error = 'Invalid account update.';
    } elseif ($id === (int)$user['id']) {
        $error = 'You cannot change your own administrator role.';
    } elseif ($action === 'approve' && $role === 'staff') {
        $stmt = db()->prepare("UPDATE users SET account_status = 'approved' WHERE id = ? AND role = 'staff'");
        $stmt->execute([$id]);
        $message = 'Staff registration approved.';
    } elseif ($action === 'reject' && $role === 'staff') {
        $stmt = db()->prepare("UPDATE users SET account_status = 'rejected' WHERE id = ? AND role = 'staff'");
        $stmt->execute([$id]);
        $message = 'Staff registration rejected.';
    } elseif (!in_array($role, ['customer', 'staff', 'admin'], true)) {
        $error = 'Invalid account role.';
    } else {
        $stmt = db()->prepare('UPDATE users SET role = ? WHERE id = ?');
        $stmt->execute([$role, $id]);
        $message = 'Account role updated.';
    }
}
$totalUsers = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
$totalPages = max(1, (int)ceil($totalUsers / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$users = db()->query("SELECT id, name, email, role, account_status, created_at FROM users ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}")->fetchAll();
include __DIR__ . '/../../includes/header.php';
?>
<main class="admin-page">
  <div class="admin-heading"><div><p class="eyebrow">Administration</p><h1>User management</h1><p>Review accounts, assign roles, and approve faculty/staff requests.</p></div><a class="btn btn-navy" href="/admin/admin-dashboard.php">Back to workspace</a></div>
  <?php if ($message): ?><p class="note request-success"><?= e($message) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="form-error"><?= e($error) ?></p><?php endif; ?>
  <section class="admin-card"><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>User</th><th>Role</th><th>Status</th><th>Created</th><th>Update</th></tr></thead><tbody><?php foreach ($users as $account): ?><tr><td><strong><?= e($account['name']) ?></strong><small><?= e($account['email']) ?></small></td><td><span class="status-badge status-neutral"><?= e($account['role'] === 'staff' ? 'Faculty / Staff' : ucfirst($account['role'])) ?></span></td><td><span class="status-badge <?= $account['account_status'] === 'approved' ? 'status-success' : ($account['account_status'] === 'pending' ? 'status-warning' : 'status-neutral') ?>"><?= e(ucfirst($account['account_status'])) ?></span></td><td><?= e(date('M j, Y', strtotime($account['created_at']))) ?></td><td><?php if ($account['role'] === 'staff' && $account['account_status'] === 'pending'): ?><form class="inline-form" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$account['id'] ?>"><input type="hidden" name="role" value="staff"><button class="table-action" name="action" value="approve" type="submit">Approve</button><button class="table-action" name="action" value="reject" type="submit">Reject</button></form><?php else: ?><form class="inline-role-form" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$account['id'] ?>"><select name="role" <?= (int)$account['id'] === (int)$user['id'] ? 'disabled' : '' ?>><?php foreach (['customer','staff','admin'] as $role): ?><option value="<?= $role ?>" <?= $account['role'] === $role ? 'selected' : '' ?>><?= $role === 'staff' ? 'Faculty / Staff' : ucfirst($role) ?></option><?php endforeach; ?></select><button class="table-action" type="submit" <?= (int)$account['id'] === (int)$user['id'] ? 'disabled' : '' ?>>Save</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php if ($totalPages > 1): ?><nav class="pagination" aria-label="User pages"><?php for ($number = 1; $number <= $totalPages; $number++): ?><a class="<?= $number === $page ? 'active' : '' ?>" href="?page=<?= $number ?>"><?= $number ?></a><?php endfor; ?></nav><?php endif; ?></section>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
