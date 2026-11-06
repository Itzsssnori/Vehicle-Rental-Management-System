<?php
$title = 'Driving Standing & Penalty Log';
require_once __DIR__ . '/../../config.php';
$user = require_customer();
$pdo = db();

$standing = customer_standing($pdo, (int)$user['id']);
$standingType = $standing['standing'] ?? 'good';
$standingPoints = (int)($standing['points'] ?? 0);

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'submit_appeal') {
        $reason = trim($_POST['appeal_reason'] ?? '');
        if ($reason === '') {
            $error = 'Please provide the grounds or justification for your standing appeal.';
        } else {
            $ins = $pdo->prepare("INSERT INTO disputes (opened_by, reason, status) VALUES (?, ?, 'open')");
            $ins->execute([$user['id'], $reason]);
            write_audit($pdo, (int)$user['id'], 'appeal_submitted', 'users', $user['id'], $reason);
            $message = 'Your penalty/standing appeal has been submitted for administrative review.';
        }
    }
}

// Fetch penalty events
$penaltyStmt = $pdo->prepare(
    "SELECT event_type, points, reason, status, expires_at, created_at
     FROM penalty_events WHERE user_id = ? ORDER BY created_at DESC"
);
$penaltyStmt->execute([$user['id']]);
$penaltyHistory = $penaltyStmt->fetchAll();

// Fetch appeals and disputes
$disputesStmt = $pdo->prepare(
    "SELECT * FROM disputes WHERE opened_by = ? ORDER BY opened_at DESC"
);
$disputesStmt->execute([$user['id']]);
$customerDisputes = $disputesStmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<main class="portal-customer-page">
  <div class="portal-customer-layout">
    <?php include __DIR__ . '/../../includes/customer-sidebar.php'; ?>

    <div class="customer-main-content">
      <!-- Hero Banner (Unified 38px actions) -->
      <section class="portal-hero-banner">
        <div class="portal-hero-text">
          <div class="portal-hero-eyebrow">Customer Workspace</div>
          <h2>Driving Standing &amp; Privileges</h2>
          <p>Review your account reliability rating, active penalty points, and institutional vehicle access privileges.</p>
        </div>
        <div class="portal-hero-actions">
          <a class="btn btn-coral" href="/vehicles.php">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <span>Browse Fleet</span>
          </a>
          <a class="btn btn-outline-white" href="/customer/my-rentals.php">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="18" x="3" y="4" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            <span>My Bookings</span>
          </a>
          <a class="btn btn-outline-white" href="/customer/payments.php">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
            <span>Payments</span>
          </a>
        </div>
      </section>

      <!-- Flash Notifications -->
      <?php if ($message): ?>
        <div class="portal-alert-success">
          <span class="alert-icon" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          </span>
          <span><?= e($message) ?></span>
        </div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="portal-alert-danger">
          <span class="alert-icon" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          </span>
          <span><?= e($error) ?></span>
        </div>
      <?php endif; ?>

      <!-- Large Standing Spotlight Card -->
      <section class="portal-card" style="padding:26px 28px;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:20px;flex-wrap:wrap;">
          <div>
            <span style="font-size:0.75rem;font-weight:800;letter-spacing:0.06em;text-transform:uppercase;color:#94a3b8;display:block;margin-bottom:6px;">Current Account Standing</span>
            <div style="display:flex;align-items:center;gap:12px;">
              <h2 style="font-size:1.8rem;font-weight:800;color:#0f172a;margin:0;">
                <?= e(ucfirst($standingType)) ?> Standing
              </h2>
              <span class="portal-badge <?= $standingType === 'good' ? 'badge-success' : ($standingType === 'warning' ? 'badge-warning' : 'badge-danger') ?>" style="padding:5px 12px;font-size:0.82rem;">
                <?= $standingPoints ?> Active Point<?= $standingPoints === 1 ? '' : 's' ?>
              </span>
            </div>
            <p style="margin:8px 0 0;font-size:0.88rem;color:#475569;max-width:620px;">
              <?= match($standingType) {
                'good' => 'Your account maintains excellent standing. You are permitted uninhibited reservation privileges across all vehicle categories.',
                'warning' => 'Your account has accumulated warning points. Prepayment is required prior to vehicle turnover at the operations hub.',
                'restricted', 'suspended' => 'Your reservation privileges are temporarily restricted due to accumulated points. Contact support or administrative operations for assistance.',
                default => 'Your vehicle access privileges depend on punctual vehicle return and vehicle inspection compliance.'
              } ?>
            </p>
          </div>

          <div style="background:#f8fafc;padding:16px 20px;border-radius:12px;border:1px solid #e2e8f0;min-width:240px;">
            <strong style="display:block;font-size:0.84rem;color:#0f172a;margin-bottom:8px;">Privilege Rules</strong>
            <div style="display:flex;flex-direction:column;gap:6px;font-size:0.78rem;color:#64748b;">
              <div style="display:flex;align-items:center;gap:6px;">
                <span class="standing-tier-dot tier-good"></span>
                <span><strong>0–2 Pts:</strong> Standard fleet access</span>
              </div>
              <div style="display:flex;align-items:center;gap:6px;">
                <span class="standing-tier-dot tier-warning"></span>
                <span><strong>3–4 Pts:</strong> Prepayment required</span>
              </div>
              <div style="display:flex;align-items:center;gap:6px;">
                <span class="standing-tier-dot tier-restricted"></span>
                <span><strong>5+ Pts:</strong> Reservations suspended</span>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- Penalty Log Section -->
      <section class="portal-card" id="penalty-log">
        <div class="portal-card-header">
          <div class="portal-card-title-group">
            <h2>Penalty Events & Infraction History</h2>
            <p>Complete record of assessed vehicle return infractions, damage reports, and point expirations.</p>
          </div>
        </div>

        <?php if (empty($penaltyHistory)): ?>
          <div class="portal-empty-card" style="padding:40px 24px;">
            <span class="portal-empty-icon" style="background:#ecfdf5;color:#059669;" aria-hidden="true">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </span>
            <h3>Clean Driving Record</h3>
            <p>You have zero penalty points on file. Vehicles returned punctually, with fuel verified and no damage, maintain good standing.</p>
          </div>
        <?php else: ?>
          <div class="portal-table-container">
            <table class="portal-table">
              <thead>
                <tr>
                  <th>Event Category</th>
                  <th>Points Assessed</th>
                  <th>Reason / Particulars</th>
                  <th>Status</th>
                  <th>Incident Date</th>
                  <th>Point Expiry Date</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($penaltyHistory as $penalty): ?>
                  <tr>
                    <td>
                      <strong><?= e(ucfirst(str_replace('_', ' ', $penalty['event_type']))) ?></strong>
                    </td>
                    <td>
                      <span class="portal-badge badge-danger">+<?= (int)$penalty['points'] ?> pt<?= (int)$penalty['points'] === 1 ? '' : 's' ?></span>
                    </td>
                    <td><?= e($penalty['reason']) ?></td>
                    <td>
                      <span class="portal-badge <?= $penalty['status'] === 'approved' ? 'badge-warning' : 'badge-neutral' ?>">
                        <?= e(ucfirst($penalty['status'])) ?>
                      </span>
                    </td>
                    <td><?= e(date('M j, Y · g:i A', strtotime($penalty['created_at']))) ?></td>
                    <td><?= $penalty['expires_at'] ? e(date('M j, Y', strtotime($penalty['expires_at']))) : 'No Expiry' ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

      <!-- Customer Penalty Appeals & Disputes -->
      <section class="portal-card" id="appeals-section" style="margin-top:24px;">
        <div class="portal-card-header">
          <div class="portal-card-title-group">
            <h2>Penalty Appeals &amp; Dispute Inquiries</h2>
            <p>If a demerit penalty was recorded in error, under verified emergency, or with dispute grounds, file a review request.</p>
          </div>
        </div>

        <?php if (!empty($customerDisputes)): ?>
          <div style="margin-bottom:20px;">
            <h3 style="font-size:0.92rem;font-weight:800;color:#0f172a;margin:0 0 10px;">Submitted Appeals History</h3>
            <div style="display:flex;flex-direction:column;gap:10px;">
              <?php foreach ($customerDisputes as $disp): ?>
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px;">
                  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:6px;">
                    <div>
                      <strong style="color:#0f172a;font-size:0.86rem;">Dispute #<?= (int)$disp['id'] ?></strong>
                      <span style="font-size:0.75rem;color:#64748b;margin-left:8px;"><?= date('M j, Y g:i A', strtotime($disp['opened_at'])) ?></span>
                    </div>
                    <span class="portal-badge <?= match($disp['status']) {
                      'resolved' => 'badge-success',
                      'rejected' => 'badge-danger',
                      'under_review' => 'badge-brand',
                      default => 'badge-warning'
                    } ?>">
                      <?= e(ucwords(str_replace('_', ' ', $disp['status']))) ?>
                    </span>
                  </div>
                  <p style="margin:0 0 6px;font-size:0.84rem;color:#334155;"><?= e($disp['reason']) ?></p>
                  <?php if ($disp['resolution']): ?>
                    <div style="background:#ffffff;border-left:3px solid #10b981;padding:8px 12px;border-radius:6px;font-size:0.8rem;color:#065f46;margin-top:6px;">
                      <strong>Administrative Resolution:</strong> <?= e($disp['resolution']) ?>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:18px;">
          <h3 style="font-size:0.92rem;font-weight:800;color:#0f172a;margin:0 0 6px;">Submit a Standing or Demerit Appeal</h3>
          <p style="font-size:0.82rem;color:#64748b;margin:0 0 14px;">Provide clear factual details regarding your vehicle return, scheduling discrepancy, or emergency documentation.</p>

          <form method="post" action="standing.php">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="submit_appeal">
            <div class="settings-field-group">
              <label for="appeal_reason">Grounds for Penalty Appeal / Dispute</label>
              <textarea id="appeal_reason" name="appeal_reason" rows="3" required placeholder="Describe the circumstances regarding the penalty points, return verification, or incident..."></textarea>
            </div>
            <button type="submit" class="btn btn-coral" style="margin-top:12px;height:38px;padding:0 18px;">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
              <span>Submit Appeal for Review</span>
            </button>
          </form>
        </div>
      </section>
    </div>
  </div>
</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
