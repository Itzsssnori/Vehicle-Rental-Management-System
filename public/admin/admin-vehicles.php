<?php
$title = 'Fleet Management';
require_once __DIR__ . '/../../config.php';
$user = require_role('admin');
$perPage = 8;
$page = max(1, (int)($_GET['page'] ?? 1));
$message = '';
$error = '';
if (isset($_GET['seeded']) && ctype_digit((string)$_GET['seeded'])) {
    $inserted = (int)$_GET['seeded'];
    $updated = isset($_GET['updated']) && ctype_digit((string)$_GET['updated']) ? (int)$_GET['updated'] : 0;
    $message = "Vehicle seed completed: {$inserted} inserted, {$updated} updated. Records and image links are now available in the catalog.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    try {
        $pdo = db();
        if ($action === 'save') {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
            $brand = trim($_POST['brand'] ?? '');
            $model = trim($_POST['model'] ?? '');
            $category = $_POST['category'] ?? '';
            $transmission = $_POST['transmission'] ?? '';
            $seats = filter_input(INPUT_POST, 'seats', FILTER_VALIDATE_INT);
            $rate = filter_input(INPUT_POST, 'daily_rate', FILTER_VALIDATE_FLOAT);
            $status = $_POST['status'] ?? 'operational';
            $image = trim($_POST['image'] ?? '');

            if ($brand === '' || $model === '' || !in_array($category, ['Sedan', 'SUV', 'Premium'], true)
                || !in_array($transmission, ['Automatic', 'Manual'], true)
                || !$seats || $seats < 1 || $rate === false || $rate <= 0
                || !in_array($status, ['operational', 'maintenance', 'retired'], true)) {
                throw new RuntimeException('Enter valid vehicle details.');
            }

            if ($id > 0) {
                $stmt = $pdo->prepare(
                    'UPDATE vehicles SET brand = ?, model = ?, category = ?, transmission = ?, seats = ?, daily_rate = ?, status = ?, image = ? WHERE id = ?'
                );
                $stmt->execute([$brand, $model, $category, $transmission, $seats, $rate, $status, $image ?: null, $id]);
                $message = 'Vehicle updated.';
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO vehicles (brand, model, category, transmission, seats, daily_rate, status, image) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([$brand, $model, $category, $transmission, $seats, $rate, $status, $image ?: null]);
                $message = 'Vehicle added.';
            }
        } elseif ($action === 'retire') {
            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            if (!$id) {
                throw new RuntimeException('Invalid vehicle.');
            }
            $stmt = $pdo->prepare("UPDATE vehicles SET status = 'retired' WHERE id = ?");
            $stmt->execute([$id]);
            $message = 'Vehicle retired.';
        } else {
            throw new RuntimeException('Invalid fleet action.');
        }
    } catch (Throwable $exception) {
        error_log('Vehicle administration action failed: ' . $exception->getMessage());
        $error = 'The vehicle action could not be completed.';
    }
}

$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT) ?: 0;
$editing = null;
if ($editId) {
    $stmt = db()->prepare('SELECT * FROM vehicles WHERE id = ?');
    $stmt->execute([$editId]);
    $editing = $stmt->fetch() ?: null;
}
$totalVehicles = (int)db()->query('SELECT COUNT(*) FROM vehicles')->fetchColumn();
$totalPages = max(1, (int)ceil($totalVehicles / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;
$vehicleStmt = db()->query("SELECT * FROM vehicles ORDER BY status, brand, model LIMIT {$perPage} OFFSET {$offset}");
$vehicles = $vehicleStmt->fetchAll();
include __DIR__ . '/../../includes/header.php';
?>
<main class="admin-page">
  <div class="admin-heading">
    <div><p class="eyebrow">Administration</p><h1>Fleet management</h1><p>Add vehicles, adjust pricing, and manage operational status.</p></div>
    <a class="btn btn-navy" href="/admin/admin-dashboard.php">Back to workspace</a>
  </div>
  <?php if ($message): ?><p class="note request-success" role="status"><?= e($message) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="form-error" role="alert"><?= e($error) ?></p><?php endif; ?>

  <div class="admin-grid">
    <section class="admin-card" id="vehicle-form">
      <p class="eyebrow"><?= $editing ? 'Edit vehicle' : 'Add vehicle' ?></p>
      <?php if (!$editing): ?><p class="admin-form-help">New vehicles marked operational will appear immediately in the customer catalog and can receive rental requests.</p><?php endif; ?>
      <form class="detail-form" method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
        <label>Brand<input name="brand" required value="<?= e($editing['brand'] ?? '') ?>"></label>
        <label>Model<input name="model" required value="<?= e($editing['model'] ?? '') ?>"></label>
        <label>Category<select name="category" required><?php foreach (['Sedan','SUV','Premium'] as $value): ?><option value="<?= $value ?>" <?= ($editing['category'] ?? '') === $value ? 'selected' : '' ?>><?= $value ?></option><?php endforeach; ?></select></label>
        <label>Transmission<select name="transmission" required><?php foreach (['Automatic','Manual'] as $value): ?><option value="<?= $value ?>" <?= ($editing['transmission'] ?? '') === $value ? 'selected' : '' ?>><?= $value ?></option><?php endforeach; ?></select></label>
        <label>Seats<input type="number" name="seats" min="1" max="20" required value="<?= e($editing['seats'] ?? 5) ?>"></label>
        <label>Daily rate (₱)<input type="number" name="daily_rate" min="1" step="0.01" required value="<?= e($editing['daily_rate'] ?? '') ?>"></label>
        <label>Status<select name="status"><?php foreach (['operational','maintenance','retired'] as $value): ?><option value="<?= $value ?>" <?= ($editing['status'] ?? 'operational') === $value ? 'selected' : '' ?>><?= ucfirst($value) ?></option><?php endforeach; ?></select></label>
        <label>Image URL or filename<input name="image" value="<?= e($editing['image'] ?? '') ?>"></label>
        <button class="btn btn-coral" type="submit"><?= $editing ? 'Save changes' : 'Add vehicle' ?></button>
        <?php if ($editing): ?><a class="text-link" href="/admin/admin-vehicles.php">Cancel editing</a><?php endif; ?>
      </form>
    </section>

    <section class="admin-card">
      <div class="section-head"><div><p class="eyebrow">Fleet records</p><h2>Vehicles</h2></div><a class="text-link" href="/admin/seed_vehicles.php">Seed from vPIC</a></div>
      <div class="admin-table-wrap">
        <table class="admin-table"><thead><tr><th>Vehicle</th><th>Rate</th><th>Status</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($vehicles as $vehicle): ?>
          <tr><td><strong><?= e($vehicle['brand'] . ' ' . $vehicle['model']) ?></strong><small><?= e($vehicle['category']) ?> · <?= e($vehicle['transmission']) ?> · <?= (int)$vehicle['seats'] ?> seats</small></td><td>₱<?= number_format((float)$vehicle['daily_rate']) ?></td><td><span class="status-badge <?= $vehicle['status'] === 'operational' ? 'status-success' : 'status-neutral' ?>"><?= e(ucfirst($vehicle['status'])) ?></span></td><td><a class="text-link" href="/admin/admin-vehicles.php?edit=<?= (int)$vehicle['id'] ?>">Edit</a><?php if ($vehicle['status'] !== 'retired'): ?><form class="inline-form" method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="retire"><input type="hidden" name="id" value="<?= (int)$vehicle['id'] ?>"><button class="table-action" type="submit" onclick="return confirm('Retire this vehicle?');">Retire</button></form><?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
      </div>
      <?php if ($totalPages > 1): ?><nav class="pagination" aria-label="Fleet pages"><?php for ($number = 1; $number <= $totalPages; $number++): ?><a class="<?= $number === $page ? 'active' : '' ?>" href="?<?= e(http_build_query(['page' => $number, 'edit' => $editId ?: null])) ?>"><?= $number ?></a><?php endfor; ?></nav><?php endif; ?>
    </section>
  </div>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
