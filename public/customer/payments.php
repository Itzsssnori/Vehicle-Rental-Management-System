<?php
$title = 'Payments & Official Receipts';
require_once __DIR__ . '/../../config.php';
$user = require_customer();
$pdo = db();

// Total paid lifetime
$paidStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(p.amount), 0)
     FROM payments p
     INNER JOIN reservations r ON r.id = p.reservation_id
     WHERE r.customer_id = ?"
);
$paidStmt->execute([$user['id']]);
$lifetimePaid = (float)$paidStmt->fetchColumn();

// Active reservations and balance
$activeStmt = $pdo->prepare(
    "SELECT r.id, rt.id AS rental_id
     FROM reservations r
     LEFT JOIN rentals rt ON rt.reservation_id = r.id
     WHERE r.customer_id = ? AND r.status IN ('pending', 'confirmed', 'ongoing')"
);
$activeStmt->execute([$user['id']]);
$activeRows = $activeStmt->fetchAll();

$totalOutstanding = 0.0;
foreach ($activeRows as $ar) {
    $bal = rental_balance($pdo, (int)$ar['id'], $ar['rental_id'] ? (int)$ar['rental_id'] : null);
    $totalOutstanding += $bal['outstanding'];
}

// Payment receipts query
$paymentStmt = $pdo->prepare(
    "SELECT p.id, p.amount, p.method, p.reference_number, p.paid_at, pr.receipt_number,
            r.id AS reservation_id, r.reference_number AS reservation_reference,
            v.brand, v.model, v.image,
            COALESCE(SUM(rf.amount), 0) AS refunded_amount
     FROM payments p
     INNER JOIN reservations r ON r.id = p.reservation_id
     INNER JOIN vehicles v ON v.id = r.vehicle_id
     LEFT JOIN payment_receipts pr ON pr.payment_id = p.id
     LEFT JOIN refunds rf ON rf.payment_id = p.id
     WHERE r.customer_id = ?
     GROUP BY p.id, r.id, pr.id, v.id
     ORDER BY p.paid_at DESC"
);
$paymentStmt->execute([$user['id']]);
$paymentsList = $paymentStmt->fetchAll();

// Charges ledger query
$financialStmt = $pdo->prepare(
    "SELECT r.id AS reservation_id, r.reference_number, rc.charge_type, rc.description, rc.amount, rc.created_at
     FROM reservations r
     LEFT JOIN rentals rt ON rt.reservation_id = r.id
     INNER JOIN rental_charges rc ON rc.reservation_id = r.id OR rc.rental_id = rt.id
     WHERE r.customer_id = ?
     ORDER BY rc.created_at DESC"
);
$financialStmt->execute([$user['id']]);
$chargesList = $financialStmt->fetchAll();

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
          <h1>Payments & Official Receipts</h1>
          <p>Access your complete billing records, settled electronic transaction slips, and itemized rental fee ledgers.</p>
        </div>
      </section>

      <!-- Financial Metric Strip -->
      <section class="portal-kpi-grid" style="grid-template-columns:repeat(3, 1fr);" aria-label="Payments summary metrics">
        <div class="portal-kpi-card">
          <div class="kpi-header">
            <span class="kpi-label">Total Settled Payments</span>
            <span class="kpi-icon-pill" aria-hidden="true">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            </span>
          </div>
          <div class="kpi-value" style="color:#065f46;">₱<?= number_format($lifetimePaid, 2) ?></div>
          <div class="kpi-subtext">Verified lifetime transactions</div>
        </div>

        <div class="portal-kpi-card">
          <div class="kpi-header">
            <span class="kpi-label">Outstanding Balance</span>
            <span class="kpi-icon-pill" aria-hidden="true">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </span>
          </div>
          <div class="kpi-value" style="<?= $totalOutstanding > 0 ? 'color:#e53e3e;' : 'color:#065f46;' ?>">
            ₱<?= number_format($totalOutstanding, 2) ?>
          </div>
          <div class="kpi-subtext"><?= $totalOutstanding > 0 ? 'Pre-turnover payment required' : 'All active rentals paid in full' ?></div>
        </div>

        <div class="portal-kpi-card">
          <div class="kpi-header">
            <span class="kpi-label">Receipts Issued</span>
            <span class="kpi-icon-pill" aria-hidden="true">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
            </span>
          </div>
          <div class="kpi-value"><?= count($paymentsList) ?></div>
          <div class="kpi-subtext">Official tax-exempt slips</div>
        </div>
      </section>

      <!-- Section 1: Verified Electronic Payments & Receipts -->
      <section class="portal-card" id="receipts-ledger">
        <div class="portal-card-header">
          <div class="portal-card-title-group">
            <h2>Electronic Payments & Receipts</h2>
            <p>Download, view, and print verified official receipts for your reservations.</p>
          </div>
        </div>

        <?php if (empty($paymentsList)): ?>
          <div class="portal-empty-card">
            <span class="portal-empty-icon" aria-hidden="true">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
            </span>
            <h3>No Payments Recorded Yet</h3>
            <p>When staff approves your reservation, you can complete payment online or at vehicle pickup to generate official receipts.</p>
            <a href="/vehicles.php" class="portal-btn-primary" style="margin-top:6px;">Browse Vehicle Fleet</a>
          </div>
        <?php else: ?>
          <div class="portal-table-container">
            <table class="portal-table">
              <thead>
                <tr>
                  <th>Receipt Number</th>
                  <th>Reservation Ref</th>
                  <th>Vehicle</th>
                  <th>Payment Method</th>
                  <th>Settled Date</th>
                  <th>Amount Paid</th>
                  <th style="text-align:right;">Official Receipt</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($paymentsList as $pay): ?>
                  <tr>
                    <td>
                      <strong style="font-family:monospace;color:#0f172a;font-size:0.9rem;"><?= e($pay['receipt_number'] ?: 'RCP-VERIFIED') ?></strong>
                    </td>
                    <td>
                      <a href="/customer/rental.php?id=<?= (int)$pay['reservation_id'] ?>" style="color:#0f172a;font-weight:700;text-decoration:underline;">
                        <?= e($pay['reservation_reference']) ?>
                      </a>
                    </td>
                    <td>
                      <div class="table-vehicle-cell">
                        <div class="table-vehicle-thumb" style="background-image:url('<?= e(vehicle_image($pay['image'])) ?>');"></div>
                        <div class="table-vehicle-info">
                          <strong><?= e($pay['brand'] . ' ' . $pay['model']) ?></strong>
                        </div>
                      </div>
                    </td>
                    <td>
                      <span class="portal-badge badge-neutral">
                        <?= e(ucwords(str_replace('_', ' ', $pay['method']))) ?>
                      </span>
                    </td>
                    <td><?= e(date('M j, Y · g:i A', strtotime($pay['paid_at']))) ?></td>
                    <td>
                      <strong style="color:#065f46;font-size:0.95rem;">₱<?= number_format((float)$pay['amount'], 2) ?></strong>
                      <?php if ((float)$pay['refunded_amount'] > 0): ?>
                        <span style="display:block;font-size:0.72rem;color:#b91c1c;">Refunded: ₱<?= number_format((float)$pay['refunded_amount'], 2) ?></span>
                      <?php endif; ?>
                    </td>
                    <td style="text-align:right;">
                      <a href="/payment-return.php?provider=<?= e($pay['method']) ?>&reservation=<?= (int)$pay['reservation_id'] ?>" class="portal-btn-outline" style="height:32px;padding:0 12px;font-size:0.78rem;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        <span>Print Receipt</span>
                      </a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

      <!-- Section 2: Charges & Assessments Ledger -->
      <?php if (!empty($chargesList)): ?>
        <section class="portal-card" id="charges-breakdown">
          <div class="portal-card-header">
            <div class="portal-card-title-group">
              <h2>Itemized Rental Charges Ledger</h2>
              <p>Itemized base daily rental charges, damage inspections, and late return fees assessed by operations staff.</p>
            </div>
          </div>
          <div class="portal-table-container">
            <table class="portal-table">
              <thead>
                <tr>
                  <th>Reservation Ref</th>
                  <th>Category</th>
                  <th>Description</th>
                  <th>Assessment Date</th>
                  <th style="text-align:right;">Amount</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($chargesList as $charge): ?>
                  <tr>
                    <td><span style="font-family:monospace;font-weight:700;"><?= e($charge['reference_number']) ?></span></td>
                    <td>
                      <span class="portal-badge badge-neutral">
                        <?= e(ucfirst(str_replace('_', ' ', $charge['charge_type']))) ?>
                      </span>
                    </td>
                    <td><?= e($charge['description'] ?: 'Daily vehicle rental charge') ?></td>
                    <td><?= e(date('M j, Y', strtotime($charge['created_at']))) ?></td>
                    <td style="text-align:right;">
                      <strong>₱<?= number_format((float)$charge['amount'], 2) ?></strong>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php endif; ?>
    </div>
  </div>
</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
