<?php
$title = 'Reports & Analytics';
require_once __DIR__ . '/../../config.php';
$user = require_role('admin');

$perPage = 8;
$page = max(1, (int)($_GET['page'] ?? 1));

// Financial Overview
$overview = db()->query(
    "SELECT
        (SELECT COUNT(*) FROM reservations) AS reservations,
        (SELECT COUNT(*) FROM rentals WHERE status IN ('ongoing','returned')) AS active_rentals,
        (SELECT COALESCE(SUM(amount), 0) FROM rental_charges) AS charge_value"
)->fetch();

$paid = (float)db()->query('SELECT COALESCE(SUM(amount), 0) FROM payments')->fetchColumn();
$refunded = (float)db()->query('SELECT COALESCE(SUM(amount), 0) FROM refunds')->fetchColumn();
$netRevenue = $paid - $refunded;

$reportCounts = db()->query(
    "SELECT
      (SELECT COUNT(*) FROM reservations WHERE status = 'no_show') AS no_shows,
      (SELECT COUNT(*) FROM penalty_events WHERE status = 'approved' AND (expires_at IS NULL OR expires_at >= CURRENT_DATE)) AS active_penalties,
      (SELECT COUNT(*) FROM rental_incidents WHERE status IN ('open','contact_attempted','escalated')) AS open_incidents,
      (SELECT COUNT(*) FROM rentals WHERE status = 'completed') AS completed_rentals"
)->fetch();

$totalVehicles = (int)db()->query('SELECT COUNT(*) FROM vehicles')->fetchColumn();
$totalPages = max(1, (int)ceil($totalVehicles / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$popular = db()->query(
    "SELECT v.id, v.brand, v.model, v.image, vc.name AS category,
            COUNT(res.id) AS rentals,
            COALESCE(SUM(res.total_amount), 0) AS revenue
     FROM vehicles v
     LEFT JOIN vehicle_categories vc ON vc.id = v.category_id
     LEFT JOIN reservations res ON res.vehicle_id = v.id
     GROUP BY v.id, v.brand, v.model, v.image, vc.name
     ORDER BY rentals DESC, revenue DESC
     LIMIT {$perPage} OFFSET {$offset}"
)->fetchAll();

// Recent payment settlements
$recentSettlements = db()->query(
    "SELECT p.id, p.amount, p.method, p.paid_at, p.reference_number,
            pr.receipt_number, res.reference_number AS reservation_reference,
            u.name AS customer_name
     FROM payments p
     INNER JOIN reservations res ON res.id = p.reservation_id
     INNER JOIN users u ON u.id = res.customer_id
     LEFT JOIN payment_receipts pr ON pr.payment_id = p.id
     ORDER BY p.paid_at DESC
     LIMIT 5"
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
      <section class="portal-hero-banner" aria-label="Reports heading">
        <div class="portal-hero-text">
          <div class="portal-hero-eyebrow">
            <span class="pulse-indicator" aria-hidden="true"></span>
            <span>ADMINISTRATION · FINANCIAL & FLEET ANALYTICS</span>
          </div>
          <h1 class="portal-hero-title">Reports & Fleet Intelligence</h1>
          <p class="portal-hero-subtitle">
            Audit rental ledger balances, monitor customer booking trends, review vehicle yield, and inspect settlement records.
          </p>
        </div>
        <div class="portal-hero-actions">
          <button type="button" class="btn btn-coral" onclick="window.print();">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            <span>Print Report</span>
          </button>
          <a href="/admin/admin-rentals.php" class="btn btn-outline-white">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
            <span>Rentals</span>
          </a>
          <a href="/admin/admin-dashboard.php" class="btn btn-outline-white">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
            <span>Executive Dashboard</span>
          </a>
        </div>
      </section>

      <!-- 4 Financial KPI Cards -->
      <section class="portal-kpi-grid" aria-label="Financial indicators">
        
        <div class="portal-kpi-card">
          <div class="kpi-header">
            <span class="kpi-label">Gross Billed</span>
            <div class="kpi-icon-pill" aria-hidden="true">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            </div>
          </div>
          <div class="kpi-value">₱<?= number_format((float)$overview['charge_value'], 0) ?></div>
          <div class="kpi-subtext"><span>Total rental charge lines</span></div>
        </div>

        <div class="portal-kpi-card">
          <div class="kpi-header">
            <span class="kpi-label">Payments Received</span>
            <div class="kpi-icon-pill" aria-hidden="true" style="background:#ecfdf5; color:#065f46;">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
          </div>
          <div class="kpi-value" style="color:#065f46;">₱<?= number_format($paid, 0) ?></div>
          <div class="kpi-subtext"><span>Gross settled collections</span></div>
        </div>

        <div class="portal-kpi-card">
          <div class="kpi-header">
            <span class="kpi-label">Refunds Issued</span>
            <div class="kpi-icon-pill" aria-hidden="true" style="background:#fef2f2; color:#991b1b;">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
            </div>
          </div>
          <div class="kpi-value" style="color:#991b1b;">₱<?= number_format($refunded, 0) ?></div>
          <div class="kpi-subtext"><span>Issued charge returns</span></div>
        </div>

        <div class="portal-kpi-card" style="border-color:#cbd5e1; background:#f8fafc;">
          <div class="kpi-header">
            <span class="kpi-label">Net Realized</span>
            <div class="kpi-icon-pill" aria-hidden="true" style="background:#0f172a; color:#ffffff;">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            </div>
          </div>
          <div class="kpi-value">₱<?= number_format($netRevenue, 0) ?></div>
          <div class="kpi-subtext"><span>Net revenue after refunds</span></div>
        </div>

      </section>

      <!-- Operational Volume Counters -->
      <section class="portal-card" style="margin-top:24px;" aria-label="Operational throughput">
        <div class="portal-card-header" style="margin-bottom:14px; padding-bottom:10px;">
          <div class="portal-card-title-group">
            <h2>Operational Throughput & Integrity</h2>
            <p>Summary of reservations processed, completions, infractions, and safety escalations.</p>
          </div>
        </div>

        <div style="display:grid; grid-template-columns: repeat(4, 1fr); gap:16px;">
          
          <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:16px;">
            <span style="font-size:0.72rem; font-weight:800; text-transform:uppercase; color:#64748b;">Completed Trips</span>
            <strong style="display:block; font-size:1.4rem; font-weight:800; color:#0f172a; margin-top:4px;"><?= (int)$reportCounts['completed_rentals'] ?></strong>
            <span style="font-size:0.75rem; color:#64748b;">Turnovers closed out</span>
          </div>

          <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:16px;">
            <span style="font-size:0.72rem; font-weight:800; text-transform:uppercase; color:#64748b;">No-Show Cancellations</span>
            <strong style="display:block; font-size:1.4rem; font-weight:800; color:#0f172a; margin-top:4px;"><?= (int)$reportCounts['no_shows'] ?></strong>
            <span style="font-size:0.75rem; color:#64748b;">Unclaimed reservations</span>
          </div>

          <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:16px;">
            <span style="font-size:0.72rem; font-weight:800; text-transform:uppercase; color:#64748b;">Active Demerit Points</span>
            <strong style="display:block; font-size:1.4rem; font-weight:800; color:#0f172a; margin-top:4px;"><?= (int)$reportCounts['active_penalties'] ?></strong>
            <span style="font-size:0.75rem; color:#64748b;">Enforced policy penalties</span>
          </div>

          <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:16px;">
            <span style="font-size:0.72rem; font-weight:800; text-transform:uppercase; color:#64748b;">Open Incidents</span>
            <strong style="display:block; font-size:1.4rem; font-weight:800; color:#0f172a; margin-top:4px;"><?= (int)$reportCounts['open_incidents'] ?></strong>
            <span style="font-size:0.75rem; color:#64748b;">Pending non-return review</span>
          </div>

        </div>
      </section>

      <!-- Fleet Performance & Yield Table -->
      <section class="portal-card" style="margin-top:24px;" aria-label="Vehicle performance">
        <div class="portal-card-header">
          <div class="portal-card-title-group">
            <h2>Fleet Asset Yield & Utilization</h2>
            <p>Vehicles ranked by rental booking volume and cumulative gross revenue generated.</p>
          </div>
        </div>

        <div class="portal-table-container">
          <table class="portal-table">
            <thead>
              <tr>
                <th>Vehicle Asset</th>
                <th>Category</th>
                <th>Total Bookings</th>
                <th>Gross Yield</th>
                <th>Performance Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($popular)): ?>
                <tr>
                  <td colspan="5" style="text-align:center; padding: 36px 16px; color:#64748b;">
                    No vehicle booking history recorded yet.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($popular as $v): ?>
                  <tr>
                    <td>
                      <div class="table-vehicle-cell">
                        <div class="table-vehicle-thumb" style="background-image:url('<?= e(vehicle_image($v['image'])) ?>');"></div>
                        <div class="table-vehicle-info">
                          <strong><?= e($v['brand'] . ' ' . $v['model']) ?></strong>
                          <span>Vehicle Asset #<?= (int)$v['id'] ?></span>
                        </div>
                      </div>
                    </td>

                    <td>
                      <span class="portal-badge badge-neutral"><?= e($v['category']) ?></span>
                    </td>

                    <td>
                      <strong style="font-size:0.9rem; color:#0f172a;"><?= (int)$v['rentals'] ?></strong>
                      <span style="font-size:0.74rem; color:#64748b; margin-left:4px;">reservations</span>
                    </td>

                    <td>
                      <strong style="font-size:0.92rem; color:#0f172a;">₱<?= number_format((float)$v['revenue'], 2) ?></strong>
                    </td>

                    <td>
                      <?php if ((int)$v['rentals'] >= 5): ?>
                        <span class="portal-badge badge-success">High Demand</span>
                      <?php elseif ((int)$v['rentals'] > 0): ?>
                        <span class="portal-badge badge-neutral">Standard</span>
                      <?php else: ?>
                        <span class="portal-badge badge-warning">Zero Bookings</span>
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
              <a href="?page=<?= $num ?>" class="btn <?= $num === $page ? 'btn-coral' : 'btn-secondary' ?>" style="padding: 6px 12px; font-size: 0.8rem;">
                <?= $num ?>
              </a>
            <?php endfor; ?>
          </div>
        <?php endif; ?>
      </section>

      <!-- Recent Payments & Settlements -->
      <section class="portal-card" style="margin-top:24px;" aria-label="Settlement records">
        <div class="portal-card-header">
          <div class="portal-card-title-group">
            <h2>Recent Settlement Ledgers</h2>
            <p>Latest payment collections and registered receipt records.</p>
          </div>
          <a href="/admin/admin-rentals.php#payments-tab" class="text-link" style="font-size:0.8rem; font-weight:700;">View All in Oversight &rarr;</a>
        </div>

        <div class="portal-table-container">
          <table class="portal-table">
            <thead>
              <tr>
                <th>Receipt / Reference</th>
                <th>Customer</th>
                <th>Booking Ref</th>
                <th>Method</th>
                <th>Amount Settled</th>
                <th>Paid Date</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($recentSettlements)): ?>
                <tr>
                  <td colspan="6" style="text-align:center; padding: 36px 16px; color:#64748b;">
                    No payment records logged in the system.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($recentSettlements as $p): ?>
                  <tr>
                    <td>
                      <strong style="font-size:0.84rem; color:#0f172a;"><?= e($p['receipt_number'] ?: 'RCP-TBD') ?></strong>
                      <span style="font-size:0.72rem; color:#64748b; display:block;"><?= e($p['reference_number']) ?></span>
                    </td>

                    <td>
                      <span style="font-size:0.84rem; font-weight:600; color:#1e293b;"><?= e($p['customer_name']) ?></span>
                    </td>

                    <td>
                      <span style="font-size:0.82rem; font-family:monospace; color:#475569;"><?= e($p['reservation_reference']) ?></span>
                    </td>

                    <td>
                      <span class="portal-badge badge-neutral"><?= e(ucwords(str_replace('_', ' ', $p['method']))) ?></span>
                    </td>

                    <td>
                      <strong style="font-size:0.92rem; color:#065f46;">₱<?= number_format((float)$p['amount'], 2) ?></strong>
                    </td>

                    <td>
                      <span style="font-size:0.8rem; color:#64748b;"><?= e(date('M j, Y · g:i A', strtotime($p['paid_at']))) ?></span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </section>

    </div>
  </div>
</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
