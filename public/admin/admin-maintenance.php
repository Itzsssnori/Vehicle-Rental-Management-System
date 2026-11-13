<?php
$title = 'Maintenance Management';
require_once __DIR__ . '/../../config.php';
$user = require_role('admin');
$isSuperAdmin = is_super_admin($user);
$branchHubId = !$isSuperAdmin ? current_user_location_id($user) : null;
$isSuperAdmin = is_super_admin($user);
$branchHubId = !$isSuperAdmin ? current_user_location_id($user) : null;

$perPage = 8;
$page = max(1, (int)($_GET['page'] ?? 1));
$statusFilter = trim($_GET['status'] ?? 'active');
$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    try {
        $action = $_POST['action'] ?? '';
        $pdo = db();
        if ($action === 'add') {
            $vehicleId = filter_var($_POST['vehicle_id'] ?? null, FILTER_VALIDATE_INT);
            $description = trim($_POST['description'] ?? '');
            $start = $_POST['start_date'] ?? '';
            $end = $_POST['end_date'] ?? '';
            if (!$vehicleId || $description === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)
                || ($end !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) || ($end !== '' && $end < $start)) {
                throw new RuntimeException('Enter valid maintenance schedule details.');
            }
            $stmt = $pdo->prepare('INSERT INTO maintenance (vehicle_id, description, start_date, end_date, maintenance_status, created_by) VALUES (?, ?, ?, ?, "scheduled", ?)');
            $stmt->execute([$vehicleId, $description, $start, $end ?: null, $user['id']]);
            $newId = (int)$pdo->lastInsertId();
            
            // Optionally update vehicle status to maintenance if starting today or earlier
            if ($start <= date('Y-m-d') && (empty($end) || $end >= date('Y-m-d'))) {
                $pdo->prepare("UPDATE vehicles SET status = 'maintenance' WHERE id = ?")->execute([$vehicleId]);
            }
            
            write_audit($pdo, (int)$user['id'], 'maintenance_added', 'maintenance', $newId, $description);
            $message = 'Maintenance record scheduled successfully.';
        } elseif ($action === 'complete') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) {
                throw new RuntimeException('Invalid maintenance record ID.');
            }
            $stmt = $pdo->prepare("SELECT vehicle_id FROM maintenance WHERE id = ?");
            $stmt->execute([$id]);
            $vehicleId = $stmt->fetchColumn();

            $pdo->prepare("UPDATE maintenance SET maintenance_status = 'completed', end_date = COALESCE(end_date, CURRENT_DATE) WHERE id = ?")->execute([$id]);
            if ($vehicleId) {
                $pdo->prepare("UPDATE vehicles SET status = 'operational' WHERE id = ?")->execute([$vehicleId]);
            }
            write_audit($pdo, (int)$user['id'], 'maintenance_completed', 'maintenance', $id);
            $message = 'Maintenance marked as completed. Vehicle restored to operational.';
        } elseif ($action === 'delete') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) {
                throw new RuntimeException('Invalid maintenance record.');
            }
            $stmt = $pdo->prepare("SELECT vehicle_id FROM maintenance WHERE id = ?");
            $stmt->execute([$id]);
            $vehicleId = $stmt->fetchColumn();

            $stmt = $pdo->prepare("UPDATE maintenance SET maintenance_status = 'cancelled' WHERE id = ?");
            $stmt->execute([$id]);
            if ($vehicleId) {
                $pdo->prepare("UPDATE vehicles SET status = 'operational' WHERE id = ?")->execute([$vehicleId]);
            }
            write_audit($pdo, (int)$user['id'], 'maintenance_cancelled', 'maintenance', $id);
            $message = 'Maintenance record cancelled.';
        } else {
            throw new RuntimeException('Invalid maintenance action.');
        }
    } catch (Throwable $exception) {
        error_log('Maintenance action failed: ' . $exception->getMessage());
        $error = $exception->getMessage();
    }
}

// Counts for filter pills
$hubJoin = $branchHubId ? " INNER JOIN vehicles v ON v.id = m.vehicle_id AND v.current_location_id = {$branchHubId}" : '';
$countActive = (int)db()->query("SELECT COUNT(*) FROM maintenance m{$hubJoin} WHERE m.maintenance_status IN ('scheduled', 'in_progress')")->fetchColumn();
$countCompleted = (int)db()->query("SELECT COUNT(*) FROM maintenance m{$hubJoin} WHERE m.maintenance_status = 'completed'")->fetchColumn();
$countAll = (int)db()->query("SELECT COUNT(*) FROM maintenance m{$hubJoin}")->fetchColumn();

// Vehicles dropdown list
$vehWhere = $branchHubId ? " AND current_location_id = {$branchHubId}" : '';
$vehicles = db()->query("SELECT id, brand, model, plate_number FROM vehicles WHERE status <> 'retired'{$vehWhere} ORDER BY brand, model")->fetchAll();

// Build filter query
$whereConditions = [];
if ($statusFilter === 'active') {
    $whereConditions[] = "m.maintenance_status IN ('scheduled', 'in_progress')";
} elseif ($statusFilter === 'completed') {
    $whereConditions[] = "m.maintenance_status = 'completed'";
}
if ($branchHubId) {
    $whereConditions[] = "v.current_location_id = {$branchHubId}";
}
$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

$countSql = "SELECT COUNT(*) FROM maintenance m INNER JOIN vehicles v ON v.id = m.vehicle_id {$whereClause}";
$totalRecords = (int)db()->query($countSql)->fetchColumn();

$totalPages = max(1, (int)ceil($totalRecords / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$records = db()->query(
    "SELECT m.*, v.brand, v.model, v.plate_number
     FROM maintenance m
     INNER JOIN vehicles v ON v.id = m.vehicle_id
     {$whereClause}
     ORDER BY m.start_date DESC
     LIMIT {$perPage} OFFSET {$offset}"
)->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<main class="portal-customer-page">
  <div class="portal-customer-layout">

    <!-- Unified Sidebar Navigation -->
    <?php include __DIR__ . '/../../includes/workspace-sidebar.php'; ?>

    <!-- Main Workspace Content -->
    <div class="customer-main-content">

      <!-- Hero Greeting Banner -->
      <section class="portal-hero-banner" aria-label="Maintenance heading">
        <div class="portal-hero-text">
          <div class="portal-hero-eyebrow">
            <span class="pulse-indicator" aria-hidden="true"></span>
            <span>ADMINISTRATION · FLEET SERVICING</span>
          </div>
          <h1 class="portal-hero-title">Vehicle Maintenance & Servicing</h1>
          <p class="portal-hero-subtitle">
            Schedule routine vehicle checkups, record mechanical servicing, manage vehicle downtime, and ensure vehicle safety compliance.
          </p>
        </div>
        <div class="portal-hero-actions">
          <a href="#service-form" class="btn btn-coral">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <span>Log Service</span>
          </a>
          <a href="/admin/admin-vehicles.php" class="btn btn-outline-white">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/></svg>
            <span>Fleet Inventory</span>
          </a>
          <a href="/admin/admin-dashboard.php" class="btn btn-outline-white">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
            <span>Dashboard</span>
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

      <!-- 2-Column Split: Schedule Form & Records Table -->
      <div style="display: grid; grid-template-columns: 340px minmax(0, 1fr); gap: 24px; align-items: start;">

        <!-- Schedule Maintenance Form Card -->
        <section class="portal-card" style="position: sticky; top: 24px;">
          <div class="portal-card-header" style="margin-bottom: 14px; padding-bottom: 10px;">
            <div class="portal-card-title-group">
              <span style="font-size: 0.72rem; font-weight: 800; text-transform: uppercase; color: #e53e3e; letter-spacing: 0.05em;">
                FLEET SERVICING
              </span>
              <h2 style="font-size: 1.1rem; margin-top: 2px;">Schedule Maintenance</h2>
            </div>
          </div>

          <form method="post" style="display:flex; flex-direction:column; gap:12px;">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add">

            <div>
              <label for="vehicle_id">Vehicle Asset</label>
              <select id="vehicle_id" name="vehicle_id" required>
                <option value="">Select vehicle to service...</option>
                <?php foreach ($vehicles as $v): ?>
                  <option value="<?= (int)$v['id'] ?>"><?= e($v['brand'] . ' ' . $v['model']) ?> (<?= e($v['plate_number'] ?: 'No plate') ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>

            <div>
              <label for="description">Service Description</label>
              <input type="text" id="description" name="description" required placeholder="e.g. Brake pad change, 10k km oil service">
            </div>

            <div>
              <label for="start_date">Start Date</label>
              <input type="date" id="start_date" name="start_date" required value="<?= date('Y-m-d') ?>">
            </div>

            <div>
              <label for="end_date">Expected Return Date (Optional)</label>
              <input type="date" id="end_date" name="end_date">
            </div>

            <button class="btn btn-coral" type="submit" style="margin-top:8px; justify-content:center; padding:10px;">
              Schedule Servicing
            </button>
          </form>
        </section>

        <!-- Maintenance Records Table Card -->
        <section class="portal-card" aria-label="Maintenance records">
          <div class="portal-card-header" style="align-items: center; justify-content: space-between;">
            <div class="portal-card-title-group">
              <h2>Servicing Schedules & History</h2>
              <p>Showing <?= count($records) ?> of <?= $totalRecords ?> maintenance records.</p>
            </div>
          </div>

          <!-- Status Filters -->
          <div class="admin-filter-bar">
            <a href="?status=active" class="admin-filter-pill <?= $statusFilter === 'active' ? 'active' : '' ?>">
              <span>Active Servicing</span>
              <span class="pill-count"><?= $countActive ?></span>
            </a>
            <a href="?status=completed" class="admin-filter-pill <?= $statusFilter === 'completed' ? 'active' : '' ?>">
              <span>Completed History</span>
              <span class="pill-count"><?= $countCompleted ?></span>
            </a>
            <a href="?status=all" class="admin-filter-pill <?= $statusFilter === 'all' ? 'active' : '' ?>">
              <span>All Records</span>
              <span class="pill-count"><?= $countAll ?></span>
            </a>
          </div>

          <!-- Table -->
          <div class="portal-table-container">
            <table class="portal-table">
              <thead>
                <tr>
                  <th>Vehicle</th>
                  <th>Maintenance Work</th>
                  <th>Schedule Window</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($records)): ?>
                  <tr>
                    <td colspan="5" style="text-align:center; padding: 36px 16px; color:#64748b;">
                      No maintenance records found for the selected view.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($records as $record): ?>
                    <tr>
                      <td>
                        <strong style="display:block; font-size:0.88rem; color:#0f172a;"><?= e($record['brand'] . ' ' . $record['model']) ?></strong>
                        <span style="font-size:0.75rem; color:#64748b;"><?= e($record['plate_number'] ?: 'VELO-TBD') ?></span>
                      </td>

                      <td>
                        <span style="font-size:0.84rem; color:#1e293b;"><?= e($record['description']) ?></span>
                      </td>

                      <td>
                        <span style="font-size:0.82rem; font-weight:600; color:#0f172a;">
                          <?= e(date('M j, Y', strtotime($record['start_date']))) ?>
                        </span>
                        <span style="font-size:0.75rem; color:#64748b; display:block;">
                          to <?= $record['end_date'] ? e(date('M j, Y', strtotime($record['end_date']))) : 'Open-ended' ?>
                        </span>
                      </td>

                      <td>
                        <?php if (in_array($record['maintenance_status'], ['scheduled', 'in_progress'], true)): ?>
                          <span class="portal-badge badge-warning"><?= e(ucfirst(str_replace('_', ' ', $record['maintenance_status']))) ?></span>
                        <?php elseif ($record['maintenance_status'] === 'completed'): ?>
                          <span class="portal-badge badge-success">Completed</span>
                        <?php else: ?>
                          <span class="portal-badge badge-neutral"><?= e(ucfirst($record['maintenance_status'])) ?></span>
                        <?php endif; ?>
                      </td>

                      <td>
                        <div style="display:flex; align-items:center; gap:6px;">
                          <?php if (in_array($record['maintenance_status'], ['scheduled', 'in_progress'], true)): ?>
                            <form method="post" style="display:inline;">
                              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                              <input type="hidden" name="action" value="complete">
                              <input type="hidden" name="id" value="<?= (int)$record['id'] ?>">
                              <button type="submit" class="btn btn-secondary" style="padding: 5px 10px; font-size:0.76rem; color:#065f46;" title="Mark finished and restore vehicle to operational">Complete</button>
                            </form>
                            <form method="post" style="display:inline;">
                              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                              <input type="hidden" name="action" value="delete">
                              <input type="hidden" name="id" value="<?= (int)$record['id'] ?>">
                              <button type="submit" class="btn btn-outline" style="padding: 5px 8px; font-size:0.76rem; color:#991b1b; border-color:#fecaca;" onclick="return confirm('Cancel this maintenance record?');">Cancel</button>
                            </form>
                          <?php else: ?>
                            <span style="font-size:0.75rem; color:#94a3b8;">Archived</span>
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
                <a href="?page=<?= $num ?>&status=<?= e($statusFilter) ?>" class="btn <?= $num === $page ? 'btn-coral' : 'btn-secondary' ?>" style="padding: 6px 12px; font-size: 0.8rem;">
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
