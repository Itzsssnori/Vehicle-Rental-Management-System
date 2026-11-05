<?php
$title = 'Driving Standing & Penalty Log';
require_once __DIR__ . '/../../config.php';
$user = require_customer();
$pdo = db();

$standing = customer_standing($pdo, (int)$user['id']);
$standingType = $standing['standing'] ?? 'good';
$standingPoints = (int)($standing['points'] ?? 0);

// Fetch penalty events
$penaltyStmt = $pdo->prepare(
    "SELECT event_type, points, reason, status, expires_at, created_at
     FROM penalty_events WHERE user_id = ? ORDER BY created_at DESC"
);
$penaltyStmt->execute([$user['id']]);
$penaltyHistory = $penaltyStmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<main class="portal-customer-page">
  <div class="portal-customer-layout">
    <?php include __DIR__ . '/../../includes/customer-sidebar.php'; ?>

    <div class="customer-main-content">
      <!-- Hero Banner -->
      <section class="portal-hero-banner">
        <div class="hero-greeting-content">
          <span class="eyebrow">Customer Workspace</span>
          <h1>Driving Standing & Fleet Privileges</h1>
          <p>Review your account reliability rating, active penalty points, and institutional vehicle access privileges.</p>
        </div>
      </section>

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
    </div>
  </div>
</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
