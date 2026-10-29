<?php
$title = 'Maintenance Management';
require_once __DIR__ . '/../../config.php';
require_role('admin');
$perPage = 8;
$page = max(1, (int)($_GET['page'] ?? 1));
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $action = $_POST['action'] ?? '';
        $pdo = db();
        if ($action === 'add') {
            $vehicleId = filter_input(INPUT_POST, 'vehicle_id', FILTER_VALIDATE_INT);
            $description = trim($_POST['description'] ?? '');
            $start = $_POST['start_date'] ?? '';
            $end = $_POST['end_date'] ?? '';
            if (!$vehicleId || $description === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)
                || ($end !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) || ($end !== '' && $end < $start)) {
                throw new RuntimeException('Enter valid maintenance details.');
            }
            $stmt = $pdo->prepare('INSERT INTO maintenance (vehicle_id, description, start_date, end_date) VALUES (?, ?, ?, ?)');
            $stmt->execute([$vehicleId, $description, $start, $end ?: null]);
            $message = 'Maintenance record added.';
        } elseif ($action === 'delete') {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            if (!$id) {
                throw new RuntimeException('Invalid maintenance record.');
            }
            $stmt = $pdo->prepare('DELETE FROM maintenance WHERE id = ?');
            $stmt->execute([$id]);
            $message = 'Maintenance record removed.';
        } else {
            throw new RuntimeException('Invalid maintenance action.');
        }
    } catch (Throwable $exception) {
        error_log('Maintenance action failed: ' . $exception->getMessage());
        $error = 'The maintenance action could not be completed.';
    }
}
$vehicles = db()->query("SELECT id, brand, model FROM vehicles WHERE status <> 'retired' ORDER BY brand, model")->fetchAll();
$totalRecords = (int)db()->query('SELECT COUNT(*) FROM maintenance')->fetchColumn();
$totalPages = max(1, (int)ceil($totalRecords / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$records = db()->query(
    "SELECT m.*, v.brand, v.model FROM maintenance m INNER JOIN vehicles v ON v.id = m.vehicle_id ORDER BY m.start_date DESC LIMIT {$perPage} OFFSET {$offset}"
)->fetchAll();
include __DIR__ . '/../../includes/header.php';
?>
<main class="admin-page">
  <div class="admin-heading"><div><p class="eyebrow">Administration</p><h1>Maintenance</h1><p>Schedule downtime and keep availability accurate.</p></div><a class="btn btn-navy" href="/admin/admin-dashboard.php">Back to workspace</a></div>
  <?php if ($message): ?><p class="note request-success" role="status"><?= e($message) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="form-error" role="alert"><?= e($error) ?></p><?php endif; ?>
  <div class="admin-grid">
    <section class="admin-card"><p class="eyebrow">Schedule maintenance</p><form class="detail-form" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add"><label>Vehicle<select name="vehicle_id" required><option value="">Select vehicle</option><?php foreach ($vehicles as $vehicle): ?><option value="<?= (int)$vehicle['id'] ?>"><?= e($vehicle['brand'] . ' ' . $vehicle['model']) ?></option><?php endforeach; ?></select></label><label>Description<input name="description" required placeholder="e.g. Oil change and inspection"></label><label>Start date<input type="date" name="start_date" required></label><label>End date<input type="date" name="end_date"></label><button class="btn btn-coral" type="submit">Add maintenance</button></form></section>
    <section class="admin-card"><div class="section-head"><div><p class="eyebrow">Records</p><h2>Scheduled work</h2></div></div><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Vehicle</th><th>Work</th><th>Dates</th><th></th></tr></thead><tbody><?php foreach ($records as $record): ?><tr><td><strong><?= e($record['brand'] . ' ' . $record['model']) ?></strong></td><td><?= e($record['description']) ?></td><td><?= e($record['start_date']) ?> – <?= e($record['end_date'] ?: 'Open') ?></td>    <td><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$record['id'] ?>"><button class="table-action" type="submit">Remove</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php if ($totalPages > 1): ?><nav class="pagination" aria-label="Maintenance pages"><?php for ($number = 1; $number <= $totalPages; $number++): ?><a class="<?= $number === $page ? 'active' : '' ?>" href="?page=<?= $number ?>"><?= $number ?></a><?php endfor; ?></nav><?php endif; ?></section>
  </div>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
