<?php
$title = 'Fleet Management';
require_once __DIR__ . '/../../config.php';
$user = require_role('admin');

$perPage = 8;
$page = max(1, (int)($_GET['page'] ?? 1));
$statusFilter = trim($_GET['status'] ?? 'all');
$search = trim($_GET['search'] ?? '');
$message = '';
$error = '';

if (isset($_GET['seeded']) && ctype_digit((string)$_GET['seeded'])) {
    $inserted = (int)$_GET['seeded'];
    $updated = isset($_GET['updated']) && ctype_digit((string)$_GET['updated']) ? (int)$_GET['updated'] : 0;
    $message = "Vehicle seed completed: {$inserted} inserted, {$updated} updated. Records and image links are available in the fleet inventory.";
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    try {
        $pdo = db();
        $postIsSuperAdmin = is_super_admin($user);
        $postBranchHubId = !$postIsSuperAdmin ? current_user_location_id($user) : null;

        // Helper: verify vehicle belongs to this branch admin's hub
        $verifyHubOwnership = function(int $vehicleId) use ($pdo, $postBranchHubId, $postIsSuperAdmin): void {
            if ($postIsSuperAdmin || !$postBranchHubId) return;
            $check = $pdo->prepare('SELECT current_location_id FROM vehicles WHERE id = ?');
            $check->execute([$vehicleId]);
            $vehHub = (int)$check->fetchColumn();
            if ($vehHub !== $postBranchHubId) {
                throw new RuntimeException('You can only manage vehicles stationed at your assigned hub.');
            }
        };

        if ($action === 'save') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
            $brand = trim($_POST['brand'] ?? '');
            $model = trim($_POST['model'] ?? '');
            $category = $_POST['category'] ?? '';
            $transmission = $_POST['transmission'] ?? '';
            $seats = filter_var($_POST['seats'] ?? null, FILTER_VALIDATE_INT);
            $rate = filter_var($_POST['daily_rate'] ?? null, FILTER_VALIDATE_FLOAT);
            $status = $_POST['status'] ?? 'operational';
            $image = trim($_POST['image'] ?? '');
            $homeLocation = filter_var($_POST['home_location_id'] ?? 1, FILTER_VALIDATE_INT) ?: 1;
            $currentLocation = filter_var($_POST['current_location_id'] ?? $homeLocation, FILTER_VALIDATE_INT) ?: $homeLocation;
            // Branch admins: force their hub as location
            if ($postBranchHubId) {
                $homeLocation = $postBranchHubId;
                $currentLocation = $postBranchHubId;
            }
            $fuelCapacity = filter_var($_POST['fuel_capacity_liters'] ?? 45.0, FILTER_VALIDATE_FLOAT) ?: 45.0;
            $mileageLimit = filter_var($_POST['daily_mileage_limit'] ?? 200, FILTER_VALIDATE_INT) ?: 200;
            $excessRate = filter_var($_POST['excess_mileage_rate'] ?? 12.0, FILTER_VALIDATE_FLOAT) ?: 12.0;
            $nextServiceMileage = filter_var($_POST['next_service_mileage'] ?? 5000, FILTER_VALIDATE_INT) ?: 5000;

            if ($brand === '' || $model === '' || !in_array($category, ['Sedan', 'SUV', 'Premium'], true)
                || !in_array($transmission, ['Automatic', 'Manual'], true)
                || !$seats || $seats < 1 || $rate === false || $rate <= 0
                || !in_array($status, ['operational', 'maintenance', 'retired'], true)) {
                throw new RuntimeException('Please enter valid vehicle specifications.');
            }

            $categoryStmt = $pdo->prepare('SELECT id FROM vehicle_categories WHERE name = ? AND status = "active"');
            $categoryStmt->execute([$category]);
            $categoryId = $categoryStmt->fetchColumn();
            if (!$categoryId) {
                throw new RuntimeException('Invalid vehicle category selected.');
            }

            if ($id > 0) {
                $verifyHubOwnership($id);
                $stmt = $pdo->prepare(
                    'UPDATE vehicles 
                     SET brand = ?, model = ?, category_id = ?, transmission = ?, seats = ?, daily_rate = ?, status = ?, image = ?,
                         home_location_id = ?, current_location_id = ?, fuel_capacity_liters = ?, daily_mileage_limit = ?, excess_mileage_rate = ?, next_service_mileage = ?
                     WHERE id = ?'
                );
                $stmt->execute([
                    $brand, $model, $categoryId, $transmission, $seats, $rate, $status, $image ?: null,
                    $homeLocation, $currentLocation, $fuelCapacity, $mileageLimit, $excessRate, $nextServiceMileage, $id
                ]);
                write_audit($pdo, (int)$user['id'], 'vehicle_updated', 'vehicles', $id, $brand . ' ' . $model);
                entity_log_change($pdo, 'vehicles', $id, 'UPDATE', null, [
                    'brand' => $brand, 'model' => $model, 'daily_rate' => $rate,
                    'current_hub' => $currentLocation, 'home_hub' => $homeLocation
                ], (int)$user['id']);
                $message = 'Vehicle specifications and venue hub assignments updated successfully.';
            } else {
                $plateNumber = 'VELO-' . strtoupper(bin2hex(random_bytes(3)));
                $stmt = $pdo->prepare(
                    'INSERT INTO vehicles 
                     (category_id, brand, model, transmission, seats, daily_rate, status, image, plate_number, home_location_id, current_location_id, fuel_capacity_liters, daily_mileage_limit, excess_mileage_rate, next_service_mileage) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $categoryId, $brand, $model, $transmission, $seats, $rate, $status, $image ?: null, $plateNumber,
                    $homeLocation, $currentLocation, $fuelCapacity, $mileageLimit, $excessRate, $nextServiceMileage
                ]);
                $newId = (int)$pdo->lastInsertId();
                write_audit($pdo, (int)$user['id'], 'vehicle_created', 'vehicles', $newId, $brand . ' ' . $model);
                entity_log_change($pdo, 'vehicles', $newId, 'INSERT', null, [
                    'brand' => $brand, 'model' => $model, 'plate' => $plateNumber, 'hub' => $currentLocation
                ], (int)$user['id']);
                $message = 'New vehicle added to the fleet catalog and assigned to venue hub.';
            }
        } elseif ($action === 'soft_delete') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) {
                throw new RuntimeException('Invalid vehicle ID.');
            }
            $verifyHubOwnership($id);
            soft_delete_entity($pdo, 'vehicles', $id, (int)$user['id']);
            write_audit($pdo, (int)$user['id'], 'vehicle_soft_deleted', 'vehicles', $id);
            $message = 'Vehicle safely archived (soft-deleted). Historical rental records are preserved.';
        } elseif ($action === 'restore_soft_delete') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) {
                throw new RuntimeException('Invalid vehicle ID.');
            }
            $verifyHubOwnership($id);
            restore_entity($pdo, 'vehicles', $id, (int)$user['id']);
            write_audit($pdo, (int)$user['id'], 'vehicle_restored_from_archive', 'vehicles', $id);
            $message = 'Vehicle successfully restored to active fleet.';
        } elseif ($action === 'retire') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) {
                throw new RuntimeException('Invalid vehicle ID.');
            }
            $verifyHubOwnership($id);
            $stmt = $pdo->prepare("UPDATE vehicles SET status = 'retired' WHERE id = ?");
            $stmt->execute([$id]);
            write_audit($pdo, (int)$user['id'], 'vehicle_retired', 'vehicles', $id);
            $message = 'Vehicle status changed to retired.';
        } elseif ($action === 'restore') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) {
                throw new RuntimeException('Invalid vehicle ID.');
            }
            $verifyHubOwnership($id);
            $stmt = $pdo->prepare("UPDATE vehicles SET status = 'operational' WHERE id = ?");
            $stmt->execute([$id]);
            write_audit($pdo, (int)$user['id'], 'vehicle_restored', 'vehicles', $id);
            $message = 'Vehicle restored to operational fleet.';
        } else {
            throw new RuntimeException('Invalid fleet action.');
        }
    } catch (Throwable $exception) {
        error_log('Vehicle administration action failed: ' . $exception->getMessage());
        $error = $exception->getMessage();
    }
}

$editId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT) ?: 0;
$editing = null;
if ($editId) {
    $stmt = db()->prepare('SELECT v.*, c.name AS category FROM vehicles v LEFT JOIN vehicle_categories c ON c.id = v.category_id WHERE v.id = ?');
    $stmt->execute([$editId]);
    $editing = $stmt->fetch() ?: null;
}

$allLocations = get_active_locations();
$isSuperAdmin = is_super_admin($user);
$branchHubId = !$isSuperAdmin ? current_user_location_id($user) : null;
$locFilter = filter_input(INPUT_GET, 'location', FILTER_VALIDATE_INT);
// Branch admins are always scoped to their hub
if ($branchHubId && !$locFilter) {
    $locFilter = $branchHubId;
}
$archivedOnly = ($statusFilter === 'archived');

// Counts for filter pills -- scoped for branch admins
$hubCountSql = $branchHubId ? " AND current_location_id = {$branchHubId}" : "";
$totalVehicles = (int)db()->query("SELECT COUNT(*) FROM vehicles WHERE deleted_at IS NULL{$hubCountSql}")->fetchColumn();
$operationalCount = (int)db()->query("SELECT COUNT(*) FROM vehicles WHERE status = 'operational' AND deleted_at IS NULL{$hubCountSql}")->fetchColumn();
$maintenanceCount = (int)db()->query("SELECT COUNT(*) FROM vehicles WHERE status = 'maintenance' AND deleted_at IS NULL{$hubCountSql}")->fetchColumn();
$retiredCount = (int)db()->query("SELECT COUNT(*) FROM vehicles WHERE status = 'retired' AND deleted_at IS NULL{$hubCountSql}")->fetchColumn();
$archivedCount = (int)db()->query("SELECT COUNT(*) FROM vehicles WHERE deleted_at IS NOT NULL{$hubCountSql}")->fetchColumn();

// Build filter query
$whereConditions = [];
$queryParams = [];

if ($archivedOnly) {
    $whereConditions[] = 'v.deleted_at IS NOT NULL';
} else {
    $whereConditions[] = 'v.deleted_at IS NULL';
    if (in_array($statusFilter, ['operational', 'maintenance', 'retired'], true)) {
        $whereConditions[] = 'v.status = ?';
        $queryParams[] = $statusFilter;
    }
}

if ($locFilter) {
    $whereConditions[] = 'v.current_location_id = ?';
    $queryParams[] = $locFilter;
}

if ($search !== '') {
    $whereConditions[] = '(v.brand LIKE ? OR v.model LIKE ? OR v.plate_number LIKE ?)';
    $queryParams[] = '%' . $search . '%';
    $queryParams[] = '%' . $search . '%';
    $queryParams[] = '%' . $search . '%';
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

$countSql = "SELECT COUNT(*) FROM vehicles v {$whereClause}";
$countStmt = db()->prepare($countSql);
$countStmt->execute($queryParams);
$filteredTotal = (int)$countStmt->fetchColumn();

$totalPages = max(1, (int)ceil($filteredTotal / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$vehicleSql = "SELECT v.*, c.name AS category,
                      cl.name AS current_location_name, cl.city AS current_location_city,
                      hl.name AS home_location_name
               FROM vehicles v
               INNER JOIN vehicle_categories c ON c.id = v.category_id
               LEFT JOIN locations cl ON cl.id = v.current_location_id
               LEFT JOIN locations hl ON hl.id = v.home_location_id
               {$whereClause}
               ORDER BY v.status = 'operational' DESC, v.brand ASC, v.model ASC
               LIMIT {$perPage} OFFSET {$offset}";
$vehicleStmt = db()->prepare($vehicleSql);
$vehicleStmt->execute($queryParams);
$vehicles = $vehicleStmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<main class="portal-customer-page">
  <div class="portal-customer-layout">

    <!-- Unified Sidebar Navigation -->
    <?php include __DIR__ . '/../../includes/workspace-sidebar.php'; ?>

    <!-- Main Workspace Content -->
    <div class="customer-main-content">

      <!-- Hero Greeting Banner -->
      <section class="portal-hero-banner" aria-label="Fleet management heading">
        <div class="portal-hero-text">
          <div class="portal-hero-eyebrow">
            <span class="pulse-indicator" aria-hidden="true"></span>
            <span>ADMINISTRATION · FLEET INVENTORY</span>
          </div>
          <h1 class="portal-hero-title">Fleet Asset Management</h1>
          <p class="portal-hero-subtitle">
            Configure vehicle listings, modify daily rental rates, control maintenance status, and update catalog specifications.
          </p>
        </div>
        <div class="portal-hero-actions">
          <a href="#vehicle-form" class="btn btn-coral">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <span><?= $editing ? 'Editing Vehicle #' . $editing['id'] : 'New Vehicle' ?></span>
          </a>
          <a href="/admin/seed_vehicles.php" class="btn btn-outline-white" title="Seed vehicles and fleet inventory from NHTSA vPIC">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
            <span>Seed Fleet</span>
          </a>
          <a href="/admin/admin-dashboard.php" class="btn btn-outline-white">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
            <span>Workspace</span>
          </a>
        </div>
      </section>

      <!-- Feedback notifications -->
      <?php if ($message): ?>
        <div class="portal-card" style="border-left: 4px solid #10b981; background: #ecfdf5; padding: 14px 18px; margin-bottom: 20px;" role="status">
          <div style="display:flex; align-items:center; gap:10px; color:#065f46; font-size:0.88rem; font-weight:600;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            <span><?= e($message) ?></span>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="portal-card" style="border-left: 4px solid #ef4444; background: #fef2f2; padding: 14px 18px; margin-bottom: 20px;" role="alert">
          <div style="display:flex; align-items:center; gap:10px; color:#991b1b; font-size:0.88rem; font-weight:600;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span><?= e($error) ?></span>
          </div>
        </div>
      <?php endif; ?>

      <!-- 2-Column Split: Vehicle Form & Fleet Table -->
      <div style="display: grid; grid-template-columns: 340px minmax(0, 1fr); gap: 24px; align-items: start;">

        <!-- Vehicle Add/Edit Form Card -->
        <section class="portal-card" id="vehicle-form" style="position: sticky; top: 24px;">
          <div class="portal-card-header" style="margin-bottom: 14px; padding-bottom: 10px;">
            <div class="portal-card-title-group">
              <span style="font-size: 0.72rem; font-weight: 800; text-transform: uppercase; color: #e53e3e; letter-spacing: 0.05em;">
                <?= $editing ? 'MODIFY SPECIFICATIONS' : 'NEW FLEET ASSET' ?>
              </span>
              <h2 style="font-size: 1.1rem; margin-top: 2px;">
                <?= $editing ? 'Edit ' . e($editing['brand'] . ' ' . $editing['model']) : 'Add Vehicle' ?>
              </h2>
            </div>
          </div>

          <form method="post" style="display:flex; flex-direction:column; gap:12px;">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">

            <div>
              <label for="brand">Brand / Make</label>
              <input type="text" id="brand" name="brand" required value="<?= e($editing['brand'] ?? '') ?>" placeholder="e.g. Toyota, Honda, Ford">
            </div>

            <div>
              <label for="model">Model Name</label>
              <input type="text" id="model" name="model" required value="<?= e($editing['model'] ?? '') ?>" placeholder="e.g. Vios, Fortuner, Camry">
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
              <div>
                <label for="category">Category</label>
                <select id="category" name="category" required>
                  <?php foreach (['Sedan', 'SUV', 'Premium'] as $val): ?>
                    <option value="<?= $val ?>" <?= ($editing['category'] ?? '') === $val ? 'selected' : '' ?>><?= $val ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label for="transmission">Transmission</label>
                <select id="transmission" name="transmission" required>
                  <?php foreach (['Automatic', 'Manual'] as $val): ?>
                    <option value="<?= $val ?>" <?= ($editing['transmission'] ?? '') === $val ? 'selected' : '' ?>><?= $val ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
              <div>
                <label for="seats">Seats</label>
                <input type="number" id="seats" name="seats" min="1" max="20" required value="<?= e($editing['seats'] ?? 5) ?>">
              </div>
              <div>
                <label for="daily_rate">Daily Rate (₱)</label>
                <input type="number" id="daily_rate" name="daily_rate" min="1" step="0.01" required value="<?= e($editing['daily_rate'] ?? '2500.00') ?>">
              </div>
            </div>

            <div>
              <label for="status">Status</label>
              <select id="status" name="status">
                <?php foreach (['operational' => 'Operational (Available)', 'maintenance' => 'Maintenance (In Service)', 'retired' => 'Retired (Archived)'] as $sKey => $sVal): ?>
                  <option value="<?= $sKey ?>" <?= ($editing['status'] ?? 'operational') === $sKey ? 'selected' : '' ?>><?= $sVal ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label for="current_location_id">Stationed Venue / Hub</label>
              <?php if ($branchHubId): ?>
                <input type="hidden" name="current_location_id" value="<?= $branchHubId ?>">
                <?php foreach ($allLocations as $loc): ?>
                  <?php if ((int)$loc['id'] === $branchHubId): ?>
                    <input type="text" value="<?= e($loc['name']) ?> (<?= e($loc['city']) ?>)" disabled style="background:#f8fafc; color:#64748b;">
                  <?php endif; ?>
                <?php endforeach; ?>
              <?php else: ?>
                <select id="current_location_id" name="current_location_id">
                  <?php foreach ($allLocations as $loc): ?>
                    <option value="<?= (int)$loc['id'] ?>" <?= ((int)($editing['current_location_id'] ?? 1) === (int)$loc['id']) ? 'selected' : '' ?>>
                      <?= e($loc['name']) ?> (<?= e($loc['city']) ?>)
                    </option>
                  <?php endforeach; ?>
                </select>
              <?php endif; ?>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
              <div>
                <label for="fuel_capacity_liters">Fuel Tank (L)</label>
                <input type="number" id="fuel_capacity_liters" name="fuel_capacity_liters" min="20" max="150" step="0.5" value="<?= e($editing['fuel_capacity_liters'] ?? 45.0) ?>">
              </div>
              <div>
                <label for="daily_mileage_limit">Daily Limit (km)</label>
                <input type="number" id="daily_mileage_limit" name="daily_mileage_limit" min="50" max="1000" step="10" value="<?= e($editing['daily_mileage_limit'] ?? 200) ?>">
              </div>
            </div>

            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
              <div>
                <label for="excess_mileage_rate">Excess (₱/km)</label>
                <input type="number" id="excess_mileage_rate" name="excess_mileage_rate" min="1" max="100" step="0.5" value="<?= e($editing['excess_mileage_rate'] ?? 12.0) ?>">
              </div>
              <div>
                <label for="next_service_mileage">Service Due (km)</label>
                <input type="number" id="next_service_mileage" name="next_service_mileage" min="100" step="500" value="<?= e($editing['next_service_mileage'] ?? 5000) ?>">
              </div>
            </div>

            <div>
              <label for="image">Image URL or Filename</label>
              <input type="text" id="image" name="image" value="<?= e($editing['image'] ?? '') ?>" placeholder="e.g. toyota-vios.jpg or https://...">
            </div>

            <div style="display:flex; gap:8px; margin-top:8px;">
              <button class="btn btn-coral" type="submit" style="flex:1; justify-content:center; padding:10px;">
                <?= $editing ? 'Save Changes' : 'Add to Fleet' ?>
              </button>
              <?php if ($editing): ?>
                <a class="btn btn-secondary" href="/admin/admin-vehicles.php" style="padding:10px 14px;">Cancel</a>
              <?php endif; ?>
            </div>
          </form>
        </section>

        <!-- Fleet Inventory Table Card -->
        <section class="portal-card" aria-label="Fleet catalog">
          <div class="portal-card-header" style="align-items: center; justify-content: space-between;">
            <div class="portal-card-title-group">
              <h2>Fleet Inventory</h2>
              <p>Showing <?= count($vehicles) ?> of <?= $filteredTotal ?> vehicles.</p>
            </div>

            <!-- Search -->
            <form method="get" style="display:flex; gap:6px; align-items:center;">
              <?php if ($statusFilter !== 'all'): ?><input type="hidden" name="status" value="<?= e($statusFilter) ?>"><?php endif; ?>
              <input type="search" name="search" value="<?= e($search) ?>" placeholder="Search make, model, plate..." style="height: 34px; padding: 0 12px; border: 1.5px solid #cbd5e1; border-radius: 6px; font-size: 0.82rem; min-width: 200px;">
              <button type="submit" class="btn btn-secondary" style="height: 34px; min-height: 34px; padding: 0 14px; font-size: 0.80rem; border-radius: 6px;">Search</button>
            </form>
          </div>

          <!-- Status Filters -->
          <div class="admin-filter-bar" style="display:flex; flex-wrap:wrap; align-items:center; gap:8px;">
            <a href="?status=all<?= $search !== '' ? '&search=' . urlencode($search) : '' ?>" class="admin-filter-pill <?= $statusFilter === 'all' ? 'active' : '' ?>">
              <span>All Fleet</span>
              <span class="pill-count"><?= $totalVehicles ?></span>
            </a>
            <a href="?status=operational<?= $search !== '' ? '&search=' . urlencode($search) : '' ?>" class="admin-filter-pill <?= $statusFilter === 'operational' ? 'active' : '' ?>">
              <span>Operational</span>
              <span class="pill-count"><?= $operationalCount ?></span>
            </a>
            <a href="?status=maintenance<?= $search !== '' ? '&search=' . urlencode($search) : '' ?>" class="admin-filter-pill <?= $statusFilter === 'maintenance' ? 'active' : '' ?>">
              <span>Maintenance</span>
              <span class="pill-count"><?= $maintenanceCount ?></span>
            </a>
            <a href="?status=retired<?= $search !== '' ? '&search=' . urlencode($search) : '' ?>" class="admin-filter-pill <?= $statusFilter === 'retired' ? 'active' : '' ?>">
              <span>Retired</span>
              <span class="pill-count"><?= $retiredCount ?></span>
            </a>
            <a href="?status=archived<?= $search !== '' ? '&search=' . urlencode($search) : '' ?>" class="admin-filter-pill <?= $statusFilter === 'archived' ? 'active' : '' ?>" style="border-color:#fca5a5;">
              <span>Archived (Soft-Deleted)</span>
              <span class="pill-count"><?= $archivedCount ?></span>
            </a>

            <!-- Venue Hub Filter Dropdown -->
            <form method="get" style="margin-left:auto; display:flex; align-items:center; gap:6px;">
              <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
              <?php if ($search !== ''): ?><input type="hidden" name="search" value="<?= e($search) ?>"><?php endif; ?>
              <label for="hub-filter" style="font-size:0.78rem; font-weight:700; color:#475569; margin:0;">Hub:</label>
              <select id="hub-filter" name="location" onchange="this.form.submit()" style="font-size:0.8rem; padding:4px 8px; border:1px solid #cbd5e1; border-radius:6px;" <?= $branchHubId ? 'disabled' : '' ?>>
                <?php if (!$branchHubId): ?>
                  <option value="">All Venues &amp; Hubs</option>
                <?php endif; ?>
                <?php foreach ($allLocations as $loc): ?>
                  <option value="<?= (int)$loc['id'] ?>" <?= $locFilter === (int)$loc['id'] ? 'selected' : '' ?>>
                    <?= e($loc['name']) ?> (<?= e($loc['city']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </form>
          </div>

          <!-- Table -->
          <div class="portal-table-container">
            <table class="portal-table">
              <thead>
                <tr>
                  <th>Vehicle Details</th>
                  <th>Category & Specs</th>
                  <th>Daily Rate</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($vehicles)): ?>
                  <tr>
                    <td colspan="5" style="text-align:center; padding: 36px 16px; color:#64748b;">
                      No vehicles found matching the selected filter or search query.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($vehicles as $vehicle): ?>
                    <tr>
                      <td>
                        <div class="table-vehicle-cell">
                          <div class="table-vehicle-thumb" style="background-image:url('<?= e(vehicle_image($vehicle['image'])) ?>');"></div>
                          <div class="table-vehicle-info">
                            <strong><?= e($vehicle['brand'] . ' ' . $vehicle['model']) ?></strong>
                            <span>Plate: <?= e($vehicle['plate_number'] ?: 'VELO-TBD') ?></span>
                          </div>
                        </div>
                      </td>

                      <td>
                        <div style="font-size:0.84rem; font-weight:700; color:#0f172a;"><?= e($vehicle['category']) ?></div>
                        <span style="font-size:0.75rem; color:#64748b; display:block;"><?= e($vehicle['transmission']) ?> · <?= (int)$vehicle['seats'] ?> Seats</span>
                        <span style="font-size:0.73rem; color:#0369a1; font-weight:600; display:inline-block; margin-top:2px;">
                          <?= e($vehicle['current_location_name'] ?? 'Main Campus Hub') ?>
                        </span>
                      </td>

                      <td>
                        <strong style="font-size:0.92rem; color:#0f172a;">₱<?= number_format((float)$vehicle['daily_rate'], 2) ?></strong>
                        <span style="font-size:0.72rem; color:#64748b; display:block;">per day</span>
                      </td>

                      <td>
                        <?php if (!empty($vehicle['deleted_at'])): ?>
                          <span class="portal-badge badge-danger">Archived</span>
                        <?php elseif ($vehicle['status'] === 'operational'): ?>
                          <span class="portal-badge badge-success">Operational</span>
                        <?php elseif ($vehicle['status'] === 'maintenance'): ?>
                          <span class="portal-badge badge-warning">Maintenance</span>
                        <?php else: ?>
                          <span class="portal-badge badge-neutral">Retired</span>
                        <?php endif; ?>
                      </td>

                      <td>
                        <?php $canManageThisVehicle = $isSuperAdmin || !$branchHubId || (int)($vehicle['current_location_id'] ?? 0) === $branchHubId; ?>
                        <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                          <?php if ($canManageThisVehicle): ?>
                            <?php if (empty($vehicle['deleted_at'])): ?>
                              <a href="?edit=<?= (int)$vehicle['id'] ?>#vehicle-form" class="btn btn-secondary" style="padding: 4px 8px; font-size:0.74rem;">Edit</a>
                              <?php if ($vehicle['status'] !== 'retired'): ?>
                                <form method="post" style="display:inline;">
                                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                  <input type="hidden" name="action" value="retire">
                                  <input type="hidden" name="id" value="<?= (int)$vehicle['id'] ?>">
                                  <button type="submit" class="btn btn-outline" style="padding: 4px 6px; font-size:0.74rem; color:#991b1b; border-color:#fecaca;" onclick="return confirm('Retire this vehicle?');">Retire</button>
                                </form>
                              <?php else: ?>
                                <form method="post" style="display:inline;">
                                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                  <input type="hidden" name="action" value="restore">
                                  <input type="hidden" name="id" value="<?= (int)$vehicle['id'] ?>">
                                  <button type="submit" class="btn btn-secondary" style="padding: 4px 6px; font-size:0.74rem; color:#065f46;">Unretire</button>
                                </form>
                              <?php endif; ?>
                              <form method="post" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="soft_delete">
                                <input type="hidden" name="id" value="<?= (int)$vehicle['id'] ?>">
                                <button type="submit" class="btn btn-outline" style="padding: 4px 6px; font-size:0.74rem; color:#dc2626; border-color:#fca5a5;" onclick="return confirm('Archive this vehicle? It will be safely soft-deleted while preserving all past rental records.');">Archive</button>
                              </form>
                            <?php else: ?>
                              <form method="post" style="display:inline;">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="restore_soft_delete">
                                <input type="hidden" name="id" value="<?= (int)$vehicle['id'] ?>">
                                <button type="submit" class="btn btn-secondary" style="padding: 4px 8px; font-size:0.74rem; color:#065f46;" onclick="return confirm('Restore this vehicle back to the active fleet?');">Restore Asset</button>
                              </form>
                            <?php endif; ?>
                          <?php else: ?>
                            <span style="font-size:0.72rem; color:#94a3b8; font-style:italic;">Managed by another hub</span>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <!-- Pagination -->
          <?php if ($totalPages > 1): ?>
            <div style="display:flex; justify-content:center; gap:6px; margin-top:20px;">
              <?php for ($num = 1; $num <= $totalPages; $num++): ?>
                <a href="?page=<?= $num ?>&status=<?= e($statusFilter) ?><?= $search !== '' ? '&search=' . urlencode($search) : '' ?>" class="btn <?= $num === $page ? 'btn-coral' : 'btn-secondary' ?>" style="padding: 6px 12px; font-size: 0.8rem;">
                  <?= $num ?>
                </a>
              <?php endfor; ?>
            </div>
          <?php endif; ?>

        </section>

      </div>

    </div>
  </div>
</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
