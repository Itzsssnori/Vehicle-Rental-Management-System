<?php
$title = 'Incidents & Escalations';
require_once __DIR__ . '/../../config.php';
$user = require_role('admin');
$isSuperAdmin = is_super_admin($user);
$branchHubId = !$isSuperAdmin ? current_user_location_id($user) : null;

$perPage = 10;
$page = max(1, (int)($_GET['page'] ?? 1));
$statusFilter = trim($_GET['status'] ?? 'open');
$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    try {
        $action = $_POST['action'] ?? '';
        $pdo = db();

        if ($action === 'resolve_incident') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            $resolution = trim($_POST['resolution'] ?? '');
            if (!$id || $resolution === '') {
                throw new RuntimeException('Incident ID and resolution text are required.');
            }
            $stmt = $pdo->prepare("UPDATE rental_incidents SET status='resolved', resolution=?, reviewed_by=?, updated_at=NOW() WHERE id=?");
            $stmt->execute([$resolution, $user['id'], $id]);
            write_audit($pdo, (int)$user['id'], 'incident_resolved', 'rental_incidents', $id, $resolution);
            $message = 'Incident marked as resolved.';

        } elseif ($action === 'close_incident') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            $resolution = trim($_POST['resolution'] ?? '');
            if (!$id || $resolution === '') {
                throw new RuntimeException('Incident ID and resolution text are required.');
            }
            $stmt = $pdo->prepare("UPDATE rental_incidents SET status='closed', resolution=?, reviewed_by=?, updated_at=NOW() WHERE id=?");
            $stmt->execute([$resolution, $user['id'], $id]);
            write_audit($pdo, (int)$user['id'], 'incident_closed', 'rental_incidents', $id, $resolution);
            $message = 'Incident closed.';

        } elseif ($action === 'add_escalation') {
            $incidentId = filter_var($_POST['incident_id'] ?? null, FILTER_VALIDATE_INT);
            $escalationType = trim($_POST['escalation_type'] ?? '');
            $reason = trim($_POST['reason'] ?? '');
            $allowed = ['institutional_review', 'security_review', 'legal_review'];
            if (!$incidentId || !in_array($escalationType, $allowed, true) || $reason === '') {
                throw new RuntimeException('Provide a valid escalation type and reason.');
            }
            $stmt = $pdo->prepare("INSERT INTO incident_escalations (incident_id, escalation_type, reason, referred_by) VALUES (?,?,?,?)");
            $stmt->execute([$incidentId, $escalationType, $reason, $user['id']]);
            // Update incident status to escalated
            $pdo->prepare("UPDATE rental_incidents SET status='escalated', updated_at=NOW() WHERE id=?")->execute([$incidentId]);
            write_audit($pdo, (int)$user['id'], 'incident_escalated', 'rental_incidents', $incidentId, $escalationType . ': ' . $reason);
            $message = 'Incident escalated successfully.';

        } elseif ($action === 'update_escalation') {
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            $status = trim($_POST['status'] ?? '');
            $allowedStatuses = ['referred', 'accepted', 'resolved', 'declined'];
            if (!$id || !in_array($status, $allowedStatuses, true)) {
                throw new RuntimeException('Invalid escalation update.');
            }
            $stmt = $pdo->prepare("UPDATE incident_escalations SET status=?, reviewed_by=? WHERE id=?");
            $stmt->execute([$status, $user['id'], $id]);
            write_audit($pdo, (int)$user['id'], 'escalation_updated', 'incident_escalations', $id, $status);
            $message = 'Escalation status updated.';

        } else {
            throw new RuntimeException('Invalid action.');
        }
    } catch (Throwable $ex) {
        error_log('Incident action failed: ' . $ex->getMessage());
        $error = $ex->getMessage();
    }
}

// Filter counts
$pdo = db();
$hubIncJoin = $branchHubId
    ? " INNER JOIN rentals hr ON hr.id = ri.rental_id INNER JOIN reservations hres ON hres.id = hr.reservation_id AND hres.pickup_location_id = {$branchHubId}"
    : '';
$countOpen             = (int)$pdo->query("SELECT COUNT(*) FROM rental_incidents ri{$hubIncJoin} WHERE ri.status='open'")->fetchColumn();
$countContactAttempted = (int)$pdo->query("SELECT COUNT(*) FROM rental_incidents ri{$hubIncJoin} WHERE ri.status='contact_attempted'")->fetchColumn();
$countEscalated        = (int)$pdo->query("SELECT COUNT(*) FROM rental_incidents ri{$hubIncJoin} WHERE ri.status='escalated'")->fetchColumn();
$countResolved         = (int)$pdo->query("SELECT COUNT(*) FROM rental_incidents ri{$hubIncJoin} WHERE ri.status='resolved'")->fetchColumn();
$countClosed           = (int)$pdo->query("SELECT COUNT(*) FROM rental_incidents ri{$hubIncJoin} WHERE ri.status='closed'")->fetchColumn();
$countAll              = (int)$pdo->query("SELECT COUNT(*) FROM rental_incidents ri{$hubIncJoin}")->fetchColumn();

// Build WHERE clause
$validStatuses = ['open', 'contact_attempted', 'escalated', 'resolved', 'closed'];
$whereParts = [];
if (in_array($statusFilter, $validStatuses, true)) {
    $whereParts[] = "ri.status = " . $pdo->quote($statusFilter);
}
if ($branchHubId) {
    $whereParts[] = "res.pickup_location_id = {$branchHubId}";
}
$whereClause = !empty($whereParts) ? 'WHERE ' . implode(' AND ', $whereParts) : '';

$totalRecords = (int)$pdo->query("SELECT COUNT(*) FROM rental_incidents ri INNER JOIN rentals r ON r.id = ri.rental_id INNER JOIN reservations res ON res.id = r.reservation_id $whereClause")->fetchColumn();
$totalPages   = max(1, (int)ceil($totalRecords / $perPage));
$page         = min($page, $totalPages);
$offset       = ($page - 1) * $perPage;

$sql = "
    SELECT ri.id, ri.incident_type, ri.status, ri.description, ri.created_at, ri.updated_at, ri.resolution,
           r.id AS rental_id, res.reference_number,
           cu.name AS customer_name, cu.email AS customer_email,
           v.brand, v.model, v.plate_number,
           reporter.name AS reported_by_name,
           (SELECT COUNT(*) FROM incident_contact_attempts ica WHERE ica.incident_id = ri.id) AS contact_count,
           (SELECT COUNT(*) FROM incident_escalations ie WHERE ie.incident_id = ri.id) AS escalation_count
    FROM rental_incidents ri
    INNER JOIN rentals r ON r.id = ri.rental_id
    INNER JOIN reservations res ON res.id = r.reservation_id
    INNER JOIN users cu ON cu.id = r.customer_id
    INNER JOIN vehicles v ON v.id = res.vehicle_id
    LEFT JOIN users reporter ON reporter.id = ri.reported_by
    $whereClause
    ORDER BY ri.created_at DESC
    LIMIT $perPage OFFSET $offset
";
$records = $pdo->query($sql)->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<main class="portal-customer-page">
  <div class="portal-customer-layout">

    <?php include __DIR__ . '/../../includes/workspace-sidebar.php'; ?>

    <div class="customer-main-content">

      <!-- Hero Banner -->
      <section class="portal-hero-banner" aria-label="Incidents heading">
        <div class="portal-hero-text">
          <div class="portal-hero-eyebrow">
            <span class="pulse-indicator" aria-hidden="true"></span>
            <span>ADMINISTRATION &middot; INCIDENT MANAGEMENT</span>
          </div>
          <h1 class="portal-hero-title">Incidents &amp; Escalations</h1>
          <p class="portal-hero-subtitle">
            Review open rental incidents, record resolution outcomes, escalate to institutional or legal review, and manage the full incident lifecycle.
          </p>
        </div>
        <div class="portal-hero-actions">
          <a href="/admin/admin-rentals.php" class="btn btn-outline-white">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <span>Rentals</span>
          </a>
          <a href="/admin/admin-dashboard.php" class="btn btn-outline-white">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
            <span>Dashboard</span>
          </a>
        </div>
      </section>

      <!-- Feedback -->
      <?php if ($message): ?>
        <div class="portal-card" style="border-left:4px solid #10b981; background:#ecfdf5; padding:14px 18px; margin-bottom:20px;" role="status">
          <div style="display:flex; align-items:center; gap:10px; color:#065f46; font-size:0.88rem; font-weight:600;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            <span><?= e($message) ?></span>
          </div>
        </div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="portal-card" style="border-left:4px solid #ef4444; background:#fef2f2; padding:14px 18px; margin-bottom:20px;" role="alert">
          <div style="display:flex; align-items:center; gap:10px; color:#991b1b; font-size:0.88rem; font-weight:600;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span><?= e($error) ?></span>
          </div>
        </div>
      <?php endif; ?>

      <!-- Incidents Table Card -->
      <section class="portal-card" aria-label="Rental incidents">
        <div class="portal-card-header" style="align-items:center; justify-content:space-between;">
          <div class="portal-card-title-group">
            <h2>Rental Incidents</h2>
            <p>Showing <?= count($records) ?> of <?= $totalRecords ?> incident records.</p>
          </div>
        </div>

        <!-- Filter Pills -->
        <div class="admin-filter-bar">
          <a href="?status=open" class="admin-filter-pill <?= $statusFilter === 'open' ? 'active' : '' ?>">
            <span>Open</span>
            <span class="pill-count"><?= $countOpen ?></span>
          </a>
          <a href="?status=contact_attempted" class="admin-filter-pill <?= $statusFilter === 'contact_attempted' ? 'active' : '' ?>">
            <span>Contact Attempted</span>
            <span class="pill-count"><?= $countContactAttempted ?></span>
          </a>
          <a href="?status=escalated" class="admin-filter-pill <?= $statusFilter === 'escalated' ? 'active' : '' ?>">
            <span>Escalated</span>
            <span class="pill-count"><?= $countEscalated ?></span>
          </a>
          <a href="?status=resolved" class="admin-filter-pill <?= $statusFilter === 'resolved' ? 'active' : '' ?>">
            <span>Resolved</span>
            <span class="pill-count"><?= $countResolved ?></span>
          </a>
          <a href="?status=closed" class="admin-filter-pill <?= $statusFilter === 'closed' ? 'active' : '' ?>">
            <span>Closed</span>
            <span class="pill-count"><?= $countClosed ?></span>
          </a>
          <a href="?status=all" class="admin-filter-pill <?= $statusFilter === 'all' ? 'active' : '' ?>">
            <span>All</span>
            <span class="pill-count"><?= $countAll ?></span>
          </a>
        </div>

        <!-- Table -->
        <div class="portal-table-container">
          <table class="portal-table">
            <thead>
              <tr>
                <th>Ref #</th>
                <th>Customer</th>
                <th>Vehicle</th>
                <th>Type</th>
                <th>Status</th>
                <th>Reported</th>
                <th style="text-align:center;">Contacts</th>
                <th style="text-align:center;">Escalations</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($records)): ?>
                <tr>
                  <td colspan="9" style="text-align:center; padding:36px 16px; color:#64748b;">
                    No incident records found for the selected filter.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($records as $rec): ?>
                  <tr>
                    <!-- Ref # -->
                    <td>
                      <span style="font-size:0.82rem; font-weight:700; color:#0f172a; font-family:monospace;"><?= e($rec['reference_number']) ?></span>
                      <span style="display:block; font-size:0.72rem; color:#94a3b8;">Rental #<?= (int)$rec['rental_id'] ?></span>
                    </td>

                    <!-- Customer -->
                    <td>
                      <strong style="display:block; font-size:0.84rem; color:#0f172a;"><?= e($rec['customer_name']) ?></strong>
                      <span style="font-size:0.74rem; color:#64748b;"><?= e($rec['customer_email']) ?></span>
                    </td>

                    <!-- Vehicle -->
                    <td>
                      <span style="font-size:0.84rem; font-weight:600; color:#1e293b;"><?= e($rec['brand'] . ' ' . $rec['model']) ?></span>
                      <span style="display:block; font-size:0.74rem; color:#64748b;"><?= e($rec['plate_number'] ?: 'N/A') ?></span>
                    </td>

                    <!-- Type -->
                    <td>
                      <span style="font-size:0.80rem; color:#334155;"><?= e(ucwords(str_replace('_', ' ', $rec['incident_type']))) ?></span>
                    </td>

                    <!-- Status Badge -->
                    <td>
                      <?php if ($rec['status'] === 'open'): ?>
                        <span class="portal-badge badge-warning">Open</span>
                      <?php elseif ($rec['status'] === 'contact_attempted'): ?>
                        <span class="portal-badge badge-info">Contact Attempted</span>
                      <?php elseif ($rec['status'] === 'escalated'): ?>
                        <span class="portal-badge badge-coral">Escalated</span>
                      <?php elseif ($rec['status'] === 'resolved'): ?>
                        <span class="portal-badge badge-success">Resolved</span>
                      <?php else: ?>
                        <span class="portal-badge badge-neutral">Closed</span>
                      <?php endif; ?>
                    </td>

                    <!-- Reported Date -->
                    <td>
                      <span style="font-size:0.80rem; color:#475569;"><?= e(date('M j, Y', strtotime($rec['created_at']))) ?></span>
                      <?php if ($rec['reported_by_name']): ?>
                        <span style="display:block; font-size:0.72rem; color:#94a3b8;">by <?= e($rec['reported_by_name']) ?></span>
                      <?php endif; ?>
                    </td>

                    <!-- Contact Attempts -->
                    <td style="text-align:center;">
                      <?php if ((int)$rec['contact_count'] > 0): ?>
                        <span class="portal-badge badge-info" style="font-size:0.76rem;"><?= (int)$rec['contact_count'] ?></span>
                      <?php else: ?>
                        <span style="font-size:0.78rem; color:#cbd5e1;">0</span>
                      <?php endif; ?>
                    </td>

                    <!-- Escalations -->
                    <td style="text-align:center;">
                      <?php if ((int)$rec['escalation_count'] > 0): ?>
                        <span class="portal-badge badge-coral" style="font-size:0.76rem;"><?= (int)$rec['escalation_count'] ?></span>
                      <?php else: ?>
                        <span style="font-size:0.78rem; color:#cbd5e1;">0</span>
                      <?php endif; ?>
                    </td>

                    <!-- Actions -->
                    <td>
                      <div style="display:flex; align-items:flex-start; flex-direction:column; gap:6px;">

                        <?php if (in_array($rec['status'], ['open', 'contact_attempted'], true)): ?>
                          <!-- Resolve inline expand -->
                          <button type="button"
                            class="btn btn-secondary"
                            style="padding:4px 10px; font-size:0.75rem; color:#065f46;"
                            onclick="toggleResolveForm(<?= (int)$rec['id'] ?>)">
                            Resolve
                          </button>
                          <!-- Escalate modal trigger -->
                          <button type="button"
                            class="btn btn-outline"
                            style="padding:4px 10px; font-size:0.75rem; color:#991b1b; border-color:#fecaca;"
                            onclick="openEscalateModal(<?= (int)$rec['id'] ?>, '<?= e(addslashes($rec['reference_number'])) ?>')">
                            Escalate
                          </button>
                        <?php endif; ?>

                        <?php if (in_array($rec['status'], ['escalated', 'resolved'], true)): ?>
                          <!-- Close inline expand -->
                          <button type="button"
                            class="btn btn-secondary"
                            style="padding:4px 10px; font-size:0.75rem; color:#334155;"
                            onclick="toggleCloseForm(<?= (int)$rec['id'] ?>)">
                            Close
                          </button>
                        <?php endif; ?>

                        <?php if (in_array($rec['status'], ['closed'], true)): ?>
                          <span style="font-size:0.74rem; color:#94a3b8;">Archived</span>
                        <?php endif; ?>

                      </div>

                      <!-- Inline Resolve Form -->
                      <?php if (in_array($rec['status'], ['open', 'contact_attempted'], true)): ?>
                        <div id="resolve-form-<?= (int)$rec['id'] ?>" style="display:none; margin-top:8px;">
                          <form method="post" style="display:flex; flex-direction:column; gap:6px;">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="resolve_incident">
                            <input type="hidden" name="id" value="<?= (int)$rec['id'] ?>">
                            <textarea name="resolution" rows="3" required placeholder="Resolution notes..." style="width:200px; padding:6px 8px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:0.78rem; resize:vertical; font-family:inherit; box-sizing:border-box;"></textarea>
                            <div style="display:flex; gap:4px;">
                              <button type="submit" class="btn btn-secondary" style="padding:4px 10px; font-size:0.74rem; color:#065f46;">Save</button>
                              <button type="button" class="btn btn-outline" style="padding:4px 8px; font-size:0.74rem;" onclick="toggleResolveForm(<?= (int)$rec['id'] ?>)">Cancel</button>
                            </div>
                          </form>
                        </div>
                      <?php endif; ?>

                      <!-- Inline Close Form -->
                      <?php if (in_array($rec['status'], ['escalated', 'resolved'], true)): ?>
                        <div id="close-form-<?= (int)$rec['id'] ?>" style="display:none; margin-top:8px;">
                          <form method="post" style="display:flex; flex-direction:column; gap:6px;">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="close_incident">
                            <input type="hidden" name="id" value="<?= (int)$rec['id'] ?>">
                            <textarea name="resolution" rows="3" required placeholder="Closing notes..." style="width:200px; padding:6px 8px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:0.78rem; resize:vertical; font-family:inherit; box-sizing:border-box;"><?= $rec['resolution'] ? e($rec['resolution']) : '' ?></textarea>
                            <div style="display:flex; gap:4px;">
                              <button type="submit" class="btn btn-secondary" style="padding:4px 10px; font-size:0.74rem; color:#334155;">Close Incident</button>
                              <button type="button" class="btn btn-outline" style="padding:4px 8px; font-size:0.74rem;" onclick="toggleCloseForm(<?= (int)$rec['id'] ?>)">Cancel</button>
                            </div>
                          </form>
                        </div>
                      <?php endif; ?>

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
              <a href="?page=<?= $num ?>&status=<?= e($statusFilter) ?>" class="btn <?= $num === $page ? 'btn-coral' : 'btn-secondary' ?>" style="padding:6px 12px; font-size:0.8rem;">
                <?= $num ?>
              </a>
            <?php endfor; ?>
          </div>
        <?php endif; ?>

      </section>

    </div>
  </div>
</main>

<!-- Escalation Modal -->
<div id="escalate-modal" role="dialog" aria-modal="true" aria-labelledby="escalate-modal-title" style="display:none; position:fixed; inset:0; z-index:9000; background:rgba(15,23,42,0.45); align-items:center; justify-content:center;">
  <div style="background:#fff; border-radius:12px; width:100%; max-width:440px; margin:auto; box-shadow:0 20px 60px rgba(0,0,0,0.18); padding:28px 28px 24px;">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;">
      <div>
        <p style="font-size:0.70rem; font-weight:800; text-transform:uppercase; letter-spacing:0.06em; color:#E66B68; margin:0 0 2px;">INCIDENT ESCALATION</p>
        <h2 id="escalate-modal-title" style="font-size:1.1rem; margin:0; color:#0f172a;">Escalate Incident</h2>
        <p id="escalate-modal-subtitle" style="font-size:0.80rem; color:#64748b; margin:4px 0 0;"></p>
      </div>
      <button type="button" onclick="closeEscalateModal()" aria-label="Close" style="background:none; border:none; cursor:pointer; color:#94a3b8; padding:4px;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </button>
    </div>

    <form method="post" style="display:flex; flex-direction:column; gap:14px;">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="add_escalation">
      <input type="hidden" name="incident_id" id="escalate-incident-id" value="">

      <div>
        <label for="escalation-type" style="display:block; font-size:0.74rem; font-weight:700; color:#334155; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.04em;">Escalation Type</label>
        <select id="escalation-type" name="escalation_type" required style="width:100%; height:36px; padding:0 10px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:0.84rem; background:#fff; font-family:inherit;">
          <option value="">Select review type...</option>
          <option value="institutional_review">Institutional Review</option>
          <option value="security_review">Security Review</option>
          <option value="legal_review">Legal Review</option>
        </select>
      </div>

      <div>
        <label for="escalation-reason" style="display:block; font-size:0.74rem; font-weight:700; color:#334155; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.04em;">Reason for Escalation</label>
        <textarea id="escalation-reason" name="reason" rows="4" required placeholder="Describe why this incident requires escalation..." style="width:100%; padding:8px 10px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:0.84rem; resize:vertical; font-family:inherit; box-sizing:border-box;"></textarea>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:8px; padding-top:8px; border-top:1px solid #e2e8f0;">
        <button type="button" class="btn btn-secondary" onclick="closeEscalateModal()" style="height:34px; padding:0 14px; font-size:0.80rem;">Cancel</button>
        <button type="submit" class="btn btn-coral" style="height:34px; padding:0 16px; font-size:0.80rem;">Submit Escalation</button>
      </div>
    </form>
  </div>
</div>

<script>
function toggleResolveForm(id) {
  var el = document.getElementById('resolve-form-' + id);
  if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
function toggleCloseForm(id) {
  var el = document.getElementById('close-form-' + id);
  if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
function openEscalateModal(incidentId, refNumber) {
  document.getElementById('escalate-incident-id').value = incidentId;
  document.getElementById('escalate-modal-subtitle').textContent = 'Ref: ' + refNumber;
  document.getElementById('escalation-type').value = '';
  document.getElementById('escalation-reason').value = '';
  var modal = document.getElementById('escalate-modal');
  modal.style.display = 'flex';
}
function closeEscalateModal() {
  document.getElementById('escalate-modal').style.display = 'none';
}
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeEscalateModal();
});
document.getElementById('escalate-modal').addEventListener('click', function(e) {
  if (e.target === this) closeEscalateModal();
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
