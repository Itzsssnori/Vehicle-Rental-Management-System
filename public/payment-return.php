<?php
$title = 'Payment Confirmed - Car Rental Portal';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/payment.php';

$user = require_customer();
$reservationId = filter_input(INPUT_GET, 'reservation', FILTER_VALIDATE_INT);
if (!$reservationId) {
    http_response_code(400);
    exit('Invalid payment return request.');
}

// Handle PayPal capture if applicable
if (($_GET['provider'] ?? '') === 'paypal' && !empty($_GET['token'])) {
    $attempt = db()->prepare("SELECT * FROM payment_attempts WHERE reservation_id = ? AND provider = 'paypal' AND provider_session_id = ? AND status = 'pending' LIMIT 1");
    $attempt->execute([$reservationId, $_GET['token']]);
    $attemptRow = $attempt->fetch();
    if ($attemptRow && payment_provider_configured('paypal')) {
        $base = getenv('PAYPAL_ENVIRONMENT') === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
        try {
            payment_json_request($base . '/v2/checkout/orders/' . rawurlencode($_GET['token']) . '/capture', [
                'Authorization: Bearer ' . paypal_access_token(),
                'PayPal-Request-Id: capture-' . $attemptRow['id'],
            ], []);
        } catch (Throwable $exception) {
            error_log('PayPal capture request failed: ' . $exception->getMessage());
        }
    }
}

// Fetch reservation details
$stmt = db()->prepare(
    "SELECT r.*, v.brand, v.model, v.year, v.image, v.plate_number, vc.name AS category_name,
            pl.name AS pickup_location, pl.address AS pickup_address,
            rl.name AS return_location, rl.address AS return_address,
            u.name AS customer_name, u.email AS customer_email, u.phone AS customer_phone
     FROM reservations r
     INNER JOIN vehicles v ON v.id = r.vehicle_id
     INNER JOIN vehicle_categories vc ON vc.id = v.category_id
     INNER JOIN locations pl ON pl.id = r.pickup_location_id
     INNER JOIN locations rl ON rl.id = r.return_location_id
     INNER JOIN users u ON u.id = r.customer_id
     WHERE r.id = ? AND r.customer_id = ?"
);
$stmt->execute([$reservationId, $user['id']]);
$reservation = $stmt->fetch();

if (!$reservation) {
    http_response_code(404);
    exit('Reservation record not found.');
}

// Fetch latest payment record and official receipt
$paymentStmt = db()->prepare(
    "SELECT p.*, pr.receipt_number, pr.issued_at AS receipt_issued_at
     FROM payments p
     LEFT JOIN payment_receipts pr ON pr.payment_id = p.id
     WHERE p.reservation_id = ?
     ORDER BY p.id DESC LIMIT 1"
);
$paymentStmt->execute([$reservationId]);
$payment = $paymentStmt->fetch();

$balance = rental_balance(db(), $reservationId);
$rentalDays = rental_days($reservation['requested_pickup_at'], $reservation['requested_return_at']);
$isPaid = $balance['outstanding'] <= 0.01;

include __DIR__ . '/../includes/header.php';
?>

<main class="portal-payment-return-page">
  <div class="wrap return-container-wrap">
    <!-- Success Headline Banner -->
    <div class="payment-success-header">
      <div class="success-icon-badge" aria-hidden="true">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
          <polyline points="22 4 12 14.01 9 11.01"/>
        </svg>
      </div>
      <span class="success-eyebrow">TRANSACTION CONFIRMED</span>
      <h1 class="success-title">Payment Successful & Confirmed</h1>
      <p class="success-subtext">
        Thank you, <?= e($reservation['customer_name']) ?>! Your payment for reservation <strong>#<?= e($reservation['reference_number']) ?></strong> has been received and verified. Your vehicle is guaranteed for dispatch.
      </p>
    </div>

    <!-- Official Electronic Receipt Card (Printable) -->
    <section class="portal-receipt-card" id="printable-receipt">
      <div class="receipt-header-row">
        <div class="receipt-brand">
          <span class="receipt-brand-badge" aria-hidden="true">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/>
              <circle cx="7" cy="17" r="2"/>
              <path d="M9 17h6"/>
              <circle cx="17" cy="17" r="2"/>
            </svg>
          </span>
          <div>
            <strong class="receipt-brand-title">Car Rental <em>Portal</em></strong>
            <span class="receipt-brand-sub">VELO VEHICLE RENTAL MANAGEMENT SYSTEM</span>
          </div>
        </div>

        <div class="receipt-title-block">
          <span class="receipt-status-stamp <?= $isPaid ? 'stamp-paid' : 'stamp-pending' ?>">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"/></svg>
            <span><?= $isPaid ? 'PAID IN FULL' : 'PAYMENT PENDING' ?></span>
          </span>
          <span class="receipt-doc-label">OFFICIAL ELECTRONIC RECEIPT</span>
        </div>
      </div>

      <div class="receipt-divider"></div>

      <!-- Receipt Metadata Grid -->
      <div class="receipt-meta-grid">
        <div class="meta-cell">
          <span class="meta-label">Receipt Number</span>
          <strong class="meta-value"><?= e($payment['receipt_number'] ?? ('RCPT-' . date('Ymd') . '-ONLINE')) ?></strong>
        </div>
        <div class="meta-cell">
          <span class="meta-label">Payment Reference</span>
          <strong class="meta-value"><?= e($payment['reference_number'] ?? ('ONL-' . strtoupper(substr(md5((string)$reservationId), 0, 8)))) ?></strong>
        </div>
        <div class="meta-cell">
          <span class="meta-label">Date & Time Issued</span>
          <strong class="meta-value"><?= date('F j, Y · g:i A', strtotime($payment['paid_at'] ?? 'now')) ?></strong>
        </div>
        <div class="meta-cell">
          <span class="meta-label">Payment Method</span>
          <strong class="meta-value">Online Payment (Direct Settle)</strong>
        </div>
      </div>

      <div class="receipt-divider"></div>

      <!-- Customer & Rental Details Two Columns -->
      <div class="receipt-details-row">
        <!-- Customer Details -->
        <div class="receipt-col">
          <span class="col-heading">Billed Customer</span>
          <div class="receipt-info-box">
            <strong class="customer-name"><?= e($reservation['customer_name']) ?></strong>
            <p class="customer-contact"><?= e($reservation['customer_email']) ?></p>
            <?php if ($reservation['customer_phone']): ?>
              <p class="customer-contact"><?= e($reservation['customer_phone']) ?></p>
            <?php endif; ?>
            <span class="booking-ref-badge">Reservation: #<?= e($reservation['reference_number']) ?></span>
          </div>
        </div>

        <!-- Vehicle Details -->
        <div class="receipt-col">
          <span class="col-heading">Vehicle Reserved</span>
          <div class="receipt-vehicle-card">
            <div class="receipt-vehicle-thumb" style="background-image: url('<?= e(vehicle_image($reservation['image'])) ?>');" role="img" aria-label="<?= e($reservation['brand'] . ' ' . $reservation['model']) ?>"></div>
            <div class="receipt-vehicle-meta">
              <strong class="vehicle-title"><?= e($reservation['brand']) ?> <?= e($reservation['model']) ?></strong>
              <span class="vehicle-sub"><?= (int)$reservation['year'] ?> Model · <?= e($reservation['category_name']) ?></span>
              <span class="plate-badge">Plate: <?= e($reservation['plate_number']) ?></span>
            </div>
          </div>
        </div>
      </div>

      <!-- Rental Itinerary -->
      <div class="receipt-schedule-box">
        <div class="schedule-item">
          <span class="schedule-lbl">Pickup Location & Date</span>
          <strong><?= date('l, F j, Y · g:i A', strtotime($reservation['requested_pickup_at'])) ?></strong>
          <span class="hub-name"><?= e($reservation['pickup_location']) ?> (<?= e($reservation['pickup_address']) ?>)</span>
        </div>
        <div class="schedule-arrow" aria-hidden="true">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
        </div>
        <div class="schedule-item">
          <span class="schedule-lbl">Return Location & Date</span>
          <strong><?= date('l, F j, Y · g:i A', strtotime($reservation['requested_return_at'])) ?></strong>
          <span class="hub-name"><?= e($reservation['return_location']) ?> (<?= e($reservation['return_address']) ?>)</span>
        </div>
      </div>

      <!-- Financial Table -->
      <div class="receipt-table-wrap">
        <table class="receipt-table">
          <thead>
            <tr>
              <th>Description</th>
              <th class="text-center">Duration</th>
              <th class="text-right">Rate</th>
              <th class="text-right">Subtotal</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>
                <strong>Vehicle Rental - <?= e($reservation['brand'] . ' ' . $reservation['model']) ?></strong>
                <span class="item-sub">Standard rental rate including unlimited mileage and basic maintenance</span>
              </td>
              <td class="text-center"><?= $rentalDays ?> Day<?= $rentalDays === 1 ? '' : 's' ?></td>
              <td class="text-right">₱<?= number_format((float)$reservation['base_amount'] / max(1, $rentalDays), 2) ?></td>
              <td class="text-right">₱<?= number_format((float)$reservation['base_amount'], 2) ?></td>
            </tr>
            <tr>
              <td>
                <strong>Comprehensive Roadside & Safety Coverage</strong>
                <span class="item-sub">24/7 on-call roadside assistance throughout rental duration</span>
              </td>
              <td class="text-center"><?= $rentalDays ?> Days</td>
              <td class="text-right">Included</td>
              <td class="text-right">₱0.00</td>
            </tr>
            <tr>
              <td>
                <strong>Government Value Added Tax (VAT) & Local Surcharges</strong>
                <span class="item-sub">Inclusive of standard government regulatory fees</span>
              </td>
              <td class="text-center">Flat</td>
              <td class="text-right">Included</td>
              <td class="text-right">₱0.00</td>
            </tr>
          </tbody>
          <tfoot>
            <tr class="tfoot-subtotal">
              <td colspan="3" class="text-right">Total Charges:</td>
              <td class="text-right">₱<?= number_format((float)$reservation['total_amount'], 2) ?></td>
            </tr>
            <tr class="tfoot-paid">
              <td colspan="3" class="text-right"><strong>Total Amount Settled:</strong></td>
              <td class="text-right"><strong>₱<?= number_format((float)($payment['amount'] ?? $reservation['total_amount']), 2) ?></strong></td>
            </tr>
            <tr class="tfoot-balance">
              <td colspan="3" class="text-right">Remaining Balance:</td>
              <td class="text-right">
                <span class="balance-zero-pill">₱<?= number_format((float)$balance['outstanding'], 2) ?></span>
              </td>
            </tr>
          </tfoot>
        </table>
      </div>

      <div class="receipt-footer-notes">
        <div class="footer-note-item">
          <strong>Pickup Reminder:</strong> Please present a valid government-issued Driver's License and this electronic receipt at the hub counter.
        </div>
        <div class="footer-note-item">
          <strong>Questions or Assistance?</strong> Contact our 24/7 service helpline at <strong>+63 897 456 1236</strong> or email <strong>info@velo.com</strong>.
        </div>
      </div>
    </section>

    <!-- Post-Checkout Actions Toolbar -->
    <div class="receipt-actions-toolbar">
      <button type="button" class="portal-btn-primary" onclick="window.print()">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
        <span>Print Official Receipt</span>
      </button>

      <a href="/customer/rental.php?id=<?= (int)$reservationId ?>" class="portal-btn-secondary">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
        <span>View Reservation Details</span>
      </a>

      <a href="/customer/dashboard.php" class="portal-btn-outline">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
        <span>Return to Dashboard</span>
      </a>
    </div>
  </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
