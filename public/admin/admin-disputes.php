<?php
$title = 'Disputes & Appeals';
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
        $disputeId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$disputeId) throw new RuntimeException('Invalid dispute record.');

        if ($action === 'update_dispute') {
            $newStatus = $_POST['dispute_status'] ?? '';
            $resolution = trim($_POST['resolution'] ?? '');
            $allowed = ['under_review', 'resolved', 'rejected'];
            if (!in_array($newStatus, $allowed, true)) throw new RuntimeException('Invalid status.');
            if (in_array($newStatus, ['resolved', 'rejected'], true) && $resolution === '') {
                throw new RuntimeException('Resolution notes are required when resolving or rejecting a dispute.');
            }
            $resolvedAt = in_array($newStatus, ['resolved', 'rejected'], true) ? date('Y-m-d H:i:s') : null;
            $resolvedBy = in_array($newStatus, ['resolved', 'rejected'], true) ? (int)$user['id'] : null;
            db()->prepare('UPDATE disputes SET status = ?, resolution = ?, resolved_by = ?, resolved_at = ? WHERE id = ?')
                ->execute([$newStatus, $resolution ?: null, $resolvedBy, $resolvedAt, $disputeId]);
            write_audit(db(), (int)$user['id'], 'dispute_' . $newStatus, 'disputes', $disputeId, $resolution ?: 'Status changed.');
            $message = 'Dispute status updated to ' . str_replace('_', ' ', $newStatus) . '.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Status counts
$hubDisputeJoin = $branchHubId ? " INNER JOIN users du ON du.id = d.opened_by AND du.location_id = {$branchHubId}" : '';
$disputeCounts = db()->query("SELECT d.status, COUNT(*) AS cnt FROM disputes d{$hubDisputeJoin} GROUP BY d.status")->fetchAll(PDO::FETCH_KEY_PAIR);

// Filter
$whereParts = [];
$queryParams = [];
$validStatuses = ['open', 'under_review', 'resolved', 'rejected'];
if (in_array($statusFilter, $validStatuses, true)) {
    $whereParts[] = 'd.status = ?';
    $queryParams[] = $statusFilter;
}
if ($branchHubId) {
    $whereParts[] = "u.location_id = {$branchHubId}";
}
$whereClause = !empty($whereParts) ? 'WHERE ' . implode(' AND ', $whereParts) : '';

$countSql = "SELECT COUNT(*) FROM disputes d INNER JOIN users u ON u.id = d.opened_by {$whereClause}";
$countStmt = db()->prepare($countSql);
$countStmt->execute($queryParams);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = "SELECT d.id, d.reason, d.status, d.resolution, d.opened_at, d.resolved_at,
               u.name AS customer_name, u.email AS customer_email,
               res.reference_number,
               resolver.name AS resolved_by_name
        FROM disputes d
        INNER JOIN users u ON u.id = d.opened_by
        LEFT JOIN reservations res ON res.id = d.reservation_id
        LEFT JOIN users resolver ON resolver.id = d.resolved_by
        {$whereClause}
        ORDER BY d.opened_at DESC
        LIMIT {$perPage} OFFSET {$offset}";
$stmt = db()->prepare($sql);
$stmt->execute($queryParams);
$disputes = $stmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<main class="portal-customer-page">
  <div class="portal-customer-layout">
    <?php include __DIR__ . '/../../includes/workspace-sidebar.php'; ?>
    <div class="customer-main-content">

      <section class="portal-hero-banner" aria-label="Disputes heading">
        <div class="portal-hero-text">
          <div class="portal-hero-eyebrow">
            <span class="pulse-indicator" aria-hidden="true"></span>
            <span>ADMINISTRATION - DISPUTES &amp; APPEALS</span>
          </div>
          <h1 class="portal-hero-title">Disputes & Appeals</h1>
          <p class="portal-hero-subtitle">Review customer-submitted appeals and standing disputes. Update status and record formal resolutions.</p>
        </div>
        <div class="portal-hero-actions">
          <a href="/admin/admin-rentals.php" class="btn btn-outline-white">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <span>Rental Oversight</span>
          </a>
          <a href="/admin/admin-dashboard.php" class="btn btn-outline-white">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
            <span>Workspace Overview</span>
          </a>
        </div>
      </section>

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
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            <span><?= e($error) ?></span>
          </div>
        </div>
      <?php endif; ?>

      <section class="portal-card" aria-label="Disputes list">
        <div class="portal-card-header">
          <div class="portal-card-title-group">
            <h2>Customer Disputes</h2>
            <p>Showing <?= count($disputes) ?> of <?= $total ?> dispute records.</p>
          </div>
        </div>

        <div class="admin-filter-bar" style="margin-bottom:16px;">
          <a href="?status=open" class="admin-filter-pill <?= $statusFilter === 'open' ? 'active' : '' ?>">
            <span>Open</span>
            <span class="pill-count"><?= (int)($disputeCounts['open'] ?? 0) ?></span>
          </a>
          <a href="?status=under_review" class="admin-filter-pill <?= $statusFilter === 'under_review' ? 'active' : '' ?>">
            <span>Under Review</span>
            <span class="pill-count"><?= (int)($disputeCounts['under_review'] ?? 0) ?></span>
          </a>
          <a href="?status=resolved" class="admin-filter-pill <?= $statusFilter === 'resolved' ? 'active' : '' ?>">
            <span>Resolved</span>
            <span class="pill-count"><?= (int)($disputeCounts['resolved'] ?? 0) ?></span>
          </a>
          <a href="?status=rejected" class="admin-filter-pill <?= $statusFilter === 'rejected' ? 'active' : '' ?>">
            <span>Rejected</span>
            <span class="pill-count"><?= (int)($disputeCounts['rejected'] ?? 0) ?></span>
          </a>
          <a href="?status=all" class="admin-filter-pill <?= $statusFilter === 'all' ? 'active' : '' ?>">
            <span>All</span>
            <span class="pill-count"><?= array_sum($disputeCounts) ?></span>
          </a>
        </div>

        <div class="portal-table-container">
          <table class="portal-table">
            <thead>
              <tr>
                <th>ID</th>
                <th>Customer</th>
                <th>Ref #</th>
                <th>Reason</th>
                <th>Status</th>
                <th>Submitted</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($disputes)): ?>
                <tr><td colspan="7" style="text-align:center; padding:36px 16px; color:#64748b;">No disputes found for the selected filter.</td></tr>
              <?php else: ?>
                <?php foreach ($disputes as $d): ?>
                  <tr>
                    <td style="font-weight:700; font-size:0.82rem; color:#0f172a;">#<?= (int)$d['id'] ?></td>
                    <td>
                      <strong style="display:block; font-size:0.84rem; color:#0f172a;"><?= e($d['customer_name']) ?></strong>
                      <span style="font-size:0.72rem; color:#64748b;"><?= e($d['customer_email']) ?></span>
                    </td>
                    <td style="font-size:0.80rem; color:#64748b;"><?= e($d['reference_number'] ?? '--') ?></td>
                    <td style="font-size:0.80rem; max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= e($d['reason']) ?>"><?= e(mb_strimwidth($d['reason'], 0, 80, '...')) ?></td>
                    <td>
                      <?php
                      $dStatusClass = ['open'=>'badge-warning','under_review'=>'badge-info','resolved'=>'badge-success','rejected'=>'badge-neutral'];
                      $dClass = $dStatusClass[$d['status']] ?? 'badge-neutral';
                      if ($dClass === 'badge-info') {
                          $badgeStyle = 'style="background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; border-radius:6px; padding:3px 8px; font-size:0.72rem; font-weight:700;"';
                      } else {
                          $badgeStyle = '';
                      }
                      ?>
                      <?php if ($dClass === 'badge-info'): ?>
                        <span <?= $badgeStyle ?>><?= e(ucfirst(str_replace('_', ' ', $d['status']))) ?></span>
                      <?php else: ?>
                        <span class="portal-badge <?= $dClass ?>"><?= e(ucfirst(str_replace('_', ' ', $d['status']))) ?></span>
                      <?php endif; ?>
                    </td>
                    <td style="font-size:0.80rem; color:#64748b;"><?= e(date('M j, Y', strtotime($d['opened_at']))) ?></td>
                    <td>
                      <?php if (in_array($d['status'], ['open', 'under_review'], true)): ?>
                        <button type="button" class="portal-btn-action btn-action-edit" onclick="openDisputeModal(<?= htmlspecialchars(json_encode([
                            'id' => (int)$d['id'],
                            'customer' => $d['customer_name'],
                            'reason' => $d['reason'],
                            'status' => $d['status'],
                            'resolution' => $d['resolution'] ?? '',
                        ]), ENT_QUOTES, 'UTF-8') ?>)">
                          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg>
                          <span>Review</span>
                        </button>
                      <?php else: ?>
                        <span style="font-size:0.72rem; color:#94a3b8;">
                          <?= $d['resolved_by_name'] ? 'By ' . e($d['resolved_by_name']) : '' ?>
                          <?= $d['resolved_at'] ? ' on ' . e(date('M j', strtotime($d['resolved_at']))) : '' ?>
                        </span>
                      <?php endif; ?>
                    </td>
                  </tr>
                  <?php if ($d['resolution']): ?>
                    <tr>
                      <td></td>
                      <td colspan="6" style="padding-top:0; font-size:0.78rem; color:#475569; font-style:italic;">
                        Resolution: <?= e($d['resolution']) ?>
                      </td>
                    </tr>
                  <?php endif; ?>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <?php if ($totalPages > 1): ?>
          <div style="display:flex; justify-content:center; gap:6px; margin-top:16px;">
            <?php for ($num = 1; $num <= $totalPages; $num++): ?>
              <a href="?page=<?= $num ?>&status=<?= e($statusFilter) ?>" class="btn <?= $num === $page ? 'btn-coral' : 'btn-secondary' ?>" style="min-height:28px; height:28px; padding:0 10px; font-size:0.76rem; border-radius:6px;">
                <?= $num ?>
              </a>
            <?php endfor; ?>
          </div>
        <?php endif; ?>
      </section>

    </div>
  </div>
</main>

<!-- Dispute Review Modal -->
<div class="ops-modal" id="dispute-modal" role="dialog" aria-modal="true" aria-labelledby="dispute-modal-title">
  <div class="ops-modal-backdrop" onclick="closeDisputeModal();"></div>
  <div class="ops-modal-card" style="width: min(100%, 520px);">
    <div class="ops-modal-header" style="padding:14px 18px;">
      <div>
        <h3 id="dispute-modal-title" style="margin:0; font-size:1.05rem; color:#0f172a;">Review Dispute</h3>
        <p id="dispute-modal-subtitle" style="margin:2px 0 0; font-size:0.78rem; color:#64748b;">Evaluate and respond to customer appeal</p>
      </div>
      <button type="button" class="ops-modal-close" onclick="closeDisputeModal();" aria-label="Close modal">&times;</button>
    </div>

    <form method="post" id="dispute-modal-form" style="display:flex; flex-direction:column; gap:12px; padding:14px 18px;">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="update_dispute">
      <input type="hidden" name="id" id="dispute-modal-id" value="">

      <div>
        <label style="display:block; font-size:0.74rem; font-weight:700; color:#334155; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.04em;">Customer Appeal Reason</label>
        <textarea id="dispute-modal-reason" readonly rows="4" style="width:100%; padding:10px; border:1.5px solid #e2e8f0; border-radius:6px; font-size:0.84rem; background:#f8fafc; resize:none; box-sizing:border-box; font-family:inherit; color:#334155;"></textarea>
      </div>

      <div>
        <label for="dispute-modal-status" style="display:block; font-size:0.74rem; font-weight:700; color:#334155; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.04em;">Decision</label>
        <select name="dispute_status" id="dispute-modal-status" required style="width:100%; height:34px; padding:0 10px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:0.84rem; background:#ffffff;">
          <option value="under_review">Under Review -- Mark for further investigation</option>
          <option value="resolved">Resolved -- Accept appeal and adjust standing</option>
          <option value="rejected">Rejected -- Deny appeal with reasoning</option>
        </select>
      </div>

      <div>
        <label for="dispute-modal-resolution" style="display:block; font-size:0.74rem; font-weight:700; color:#334155; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.04em;">Resolution Notes <span style="font-weight:400; color:#94a3b8;">(required for resolve/reject)</span></label>
        <textarea name="resolution" id="dispute-modal-resolution" rows="3" placeholder="Administrative notes, findings, or justification for the decision..." style="width:100%; padding:10px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:0.84rem; resize:vertical; box-sizing:border-box; font-family:inherit;"></textarea>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:4px; padding-top:12px; border-top:1px solid #e2e8f0;">
        <button type="button" class="btn btn-secondary" onclick="closeDisputeModal();" style="height:34px; min-height:34px; padding:0 14px; font-size:0.80rem; border-radius:6px;">Cancel</button>
        <button type="submit" class="btn btn-coral" style="height:34px; min-height:34px; padding:0 16px; font-size:0.80rem; border-radius:6px;">Submit Decision</button>
      </div>
    </form>
  </div>
</div>

<script>
function openDisputeModal(data) {
  var modal = document.getElementById('dispute-modal');
  if (!modal) return;
  document.getElementById('dispute-modal-id').value = data.id;
  document.getElementById('dispute-modal-subtitle').textContent = data.customer + ' -- Dispute #' + data.id;
  document.getElementById('dispute-modal-reason').value = data.reason || '';
  document.getElementById('dispute-modal-status').value = data.status === 'open' ? 'under_review' : data.status;
  document.getElementById('dispute-modal-resolution').value = data.resolution || '';
  modal.classList.add('is-open');
}
function closeDisputeModal() {
  var modal = document.getElementById('dispute-modal');
  if (modal) modal.classList.remove('is-open');
}
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeDisputeModal();
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
