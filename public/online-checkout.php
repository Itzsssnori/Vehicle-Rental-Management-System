<?php
$title = 'Secure Online Checkout - Car Rental Portal';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/payment.php';

$user = require_customer();
$attemptId = filter_input(INPUT_GET, 'attempt', FILTER_VALIDATE_INT);

$stmt = db()->prepare(
    "SELECT pa.*, r.reference_number, r.customer_id, r.requested_pickup_at, r.requested_return_at,
            r.base_amount, r.total_amount, r.payment_method AS chosen_method,
            v.brand, v.model, v.year, v.image, v.daily_rate, v.transmission, v.fuel_type, v.seats,
            vc.name AS category_name,
            pl.name AS pickup_location, pl.address AS pickup_address,
            rl.name AS return_location, rl.address AS return_address,
            u.name AS customer_name, u.email AS customer_email, u.phone AS customer_phone
     FROM payment_attempts pa
     INNER JOIN reservations r ON r.id = pa.reservation_id
     INNER JOIN vehicles v ON v.id = r.vehicle_id
     INNER JOIN vehicle_categories vc ON vc.id = v.category_id
     INNER JOIN locations pl ON pl.id = r.pickup_location_id
     INNER JOIN locations rl ON rl.id = r.return_location_id
     INNER JOIN users u ON u.id = r.customer_id
     WHERE pa.id = ? AND pa.provider = 'online' AND r.customer_id = ?"
);
$stmt->execute([$attemptId ?: 0, $user['id']]);
$attempt = $stmt->fetch();

if (!$attempt) {
    http_response_code(404);
    exit('Payment session not found or already settled.');
}

// If already paid, redirect straight to payment return / receipt page
if ($attempt['status'] === 'paid') {
    header('Location: /payment-return.php?provider=online&reservation=' . (int)$attempt['reservation_id'] . '&attempt=' . (int)$attempt['id']);
    exit;
}

$rentalDays = rental_days($attempt['requested_pickup_at'], $attempt['requested_return_at']);
$calculatedDailyTotal = (float)$attempt['daily_rate'] * max(1, $rentalDays);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'pay_now' || $action === 'success') {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            $providerPaymentId = 'ONL-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
            settle_online_payment($pdo, (int)$attempt['id'], $providerPaymentId);
            $pdo->commit();

            header('Location: /payment-return.php?provider=online&reservation=' . (int)$attempt['reservation_id'] . '&attempt=' . (int)$attempt['id']);
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Online payment settlement failed: ' . $exception->getMessage());
            $error = 'The online payment transaction could not be processed. Please try again.';
        }
    } elseif ($action === 'cancel') {
        $pdo = db();
        $pdo->prepare("UPDATE payment_attempts SET status = 'cancelled', failure_reason = 'Cancelled by customer' WHERE id = ? AND status <> 'paid'")
            ->execute([(int)$attempt['id']]);
        header('Location: /customer/rental.php?id=' . (int)$attempt['reservation_id']);
        exit;
    }
}

include __DIR__ . '/../includes/header.php';
?>

<main class="portal-checkout-page">
  <div class="wrap checkout-container-wrap">
    <!-- Back to Reservation Link -->
    <div class="checkout-breadcrumb-row">
      <a href="/customer/rental.php?id=<?= (int)$attempt['reservation_id'] ?>" class="checkout-back-link">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        <span>Return to Reservation #<?= e($attempt['reference_number']) ?></span>
      </a>
      <div class="checkout-secure-badge">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        <span>256-Bit SSL Encrypted Checkout</span>
      </div>
    </div>

    <?php if ($error): ?>
      <div class="portal-alert-danger" role="alert" style="margin-bottom:24px;">
        <span class="alert-icon" aria-hidden="true">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        </span>
        <span><?= e($error) ?></span>
      </div>
    <?php endif; ?>

    <div class="checkout-main-grid">
      <!-- Left Column: Payment Method & Details Form -->
      <div class="checkout-payment-panel">
        <div class="checkout-panel-card">
          <div class="checkout-panel-head">
            <div>
              <span class="panel-eyebrow">Payment Gateway</span>
              <h1 class="panel-title">Complete Your Payment</h1>
            </div>
            <div class="gateway-logo-badge" aria-hidden="true">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
            </div>
          </div>
          <p class="panel-intro">Choose your preferred online settlement method. Payments are processed securely with instant reservation guarantee.</p>

          <!-- Payment Method Tabs -->
          <div class="checkout-method-tabs" role="tablist" aria-label="Payment method choices">
            <button type="button" class="method-tab active" data-tab-target="tab-card" role="tab" aria-selected="true">
              <span class="tab-icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
              </span>
              <span class="tab-label">Credit / Debit Card</span>
            </button>
            <button type="button" class="method-tab" data-tab-target="tab-ewallet" role="tab" aria-selected="false">
              <span class="tab-icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
              </span>
              <span class="tab-label">GCash / Maya</span>
            </button>
            <button type="button" class="method-tab" data-tab-target="tab-bank" role="tab" aria-selected="false">
              <span class="tab-icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="21" x2="21" y2="21"/><line x1="3" y1="10" x2="21" y2="10"/><polyline points="5 6 12 3 19 6"/><line x1="4" y1="10" x2="4" y2="21"/><line x1="20" y1="10" x2="20" y2="21"/></svg>
              </span>
              <span class="tab-label">Online Banking</span>
            </button>
          </div>

          <form method="post" class="checkout-form" id="checkout-payment-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="pay_now">

            <!-- Tab 1: Credit / Debit Card -->
            <div class="checkout-tab-pane active" id="tab-card" role="tabpanel">
              <div class="card-brand-pills-row">
                <span class="brand-pill">VISA</span>
                <span class="brand-pill">Mastercard</span>
                <span class="brand-pill">JCB</span>
                <span class="brand-pill">American Express</span>
              </div>

              <div class="form-row">
                <div class="form-group full-width">
                  <label for="card-holder-name">
                    <span>Cardholder Name</span>
                  </label>
                  <input id="card-holder-name" type="text" name="card_name" placeholder="Full name as printed on card" value="<?= e($attempt['customer_name']) ?>" required>
                </div>
              </div>

              <div class="form-row">
                <div class="form-group full-width">
                  <label for="card-number">
                    <span>Card Number</span>
                    <span class="label-hint">Encrypted</span>
                  </label>
                  <div class="input-with-icon">
                    <span class="input-icon" aria-hidden="true">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                    </span>
                    <input id="card-number" type="text" name="card_number" inputmode="numeric" placeholder="4532 •••• •••• 8821" maxlength="19" value="4532 8821 9904 7712" required>
                  </div>
                </div>
              </div>

              <div class="form-row two-cols">
                <div class="form-group">
                  <label for="card-expiry">
                    <span>Expiration Date</span>
                  </label>
                  <input id="card-expiry" type="text" name="card_expiry" placeholder="MM / YY" maxlength="7" value="12/28" required>
                </div>
                <div class="form-group">
                  <label for="card-cvv">
                    <span>Security Code (CVV)</span>
                    <span class="label-hint">3 or 4 digits</span>
                  </label>
                  <div class="input-with-icon">
                    <span class="input-icon" aria-hidden="true">
                      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    </span>
                    <input id="card-cvv" type="password" name="card_cvv" placeholder="•••" maxlength="4" value="882" required>
                  </div>
                </div>
              </div>

              <div class="checkout-checkbox-row">
                <label class="custom-checkbox-label">
                  <input type="checkbox" name="save_card" value="1" checked>
                  <span>Save payment credentials securely for future vehicle reservations</span>
                </label>
              </div>
            </div>

            <!-- Tab 2: GCash / Maya E-Wallet -->
            <div class="checkout-tab-pane" id="tab-ewallet" role="tabpanel" style="display:none;">
              <div class="ewallet-info-box">
                <div class="ewallet-logo-row">
                  <span class="wallet-badge gcash">GCash</span>
                  <span class="wallet-badge maya">Maya</span>
                </div>
                <p>Instant mobile payment. You will be prompted to authorize this transaction using your registered mobile e-wallet number.</p>
              </div>

              <div class="form-row">
                <div class="form-group full-width">
                  <label for="ewallet-mobile">
                    <span>Registered Mobile Number</span>
                  </label>
                  <div class="input-with-icon">
                    <span class="input-icon" aria-hidden="true">
                      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                    </span>
                    <input id="ewallet-mobile" type="tel" name="ewallet_phone" placeholder="0917 ••• ••••" value="<?= e($attempt['customer_phone'] ?: '0917 123 4567') ?>">
                  </div>
                </div>
              </div>
            </div>

            <!-- Tab 3: Online Bank Transfer -->
            <div class="checkout-tab-pane" id="tab-bank" role="tabpanel" style="display:none;">
              <div class="bank-info-box">
                <p>Select your Philippine banking partner for instant online bank settlement:</p>
                <div class="form-group full-width" style="margin-top:12px;">
                  <label for="bank-selector"><span>Bank Institution</span></label>
                  <select id="bank-selector" name="bank_name">
                    <option value="bdo">BDO Unibank Online</option>
                    <option value="bpi">BPI Online / Mobile</option>
                    <option value="metrobank">Metrobank Direct</option>
                    <option value="unionbank">UnionBank Online Banking</option>
                    <option value="landbank">Landbank Link.Biz</option>
                  </select>
                </div>
              </div>
            </div>

            <!-- Pay Now Action -->
            <div class="checkout-actions-block">
              <button type="submit" class="portal-btn-primary checkout-pay-btn" id="pay-submit-button">
                <span class="btn-lock-icon" aria-hidden="true">
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                </span>
                <span>Pay ₱<?= number_format((float)$attempt['amount'], 2) ?> Now</span>
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
              </button>
            </div>
          </form>

          <!-- Cancel & Return Button (Separate Form to prevent submission validation) -->
          <form method="post" style="margin-top:12px;text-align:center;">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <button type="submit" name="action" value="cancel" class="checkout-cancel-link">
              Cancel and return to reservation details
            </button>
          </form>

          <!-- Trust Badges Row -->
          <div class="checkout-trust-row">
            <div class="trust-item">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
              <span>Bank-grade 256-bit encryption</span>
            </div>
            <div class="trust-item">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
              <span>Instant tax receipt generated</span>
            </div>
            <div class="trust-item">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
              <span>Guaranteed vehicle dispatch</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Right Column: Order Summary & Vehicle Visual -->
      <aside class="checkout-summary-panel">
        <div class="checkout-summary-card">
          <div class="summary-card-head">
            <h2 class="summary-title">Booking Summary</h2>
            <span class="summary-ref-pill"><?= e($attempt['reference_number']) ?></span>
          </div>

          <!-- Vehicle Thumbnail Card -->
          <div class="summary-vehicle-row">
            <div class="summary-vehicle-thumb" style="background-image: url('<?= e(vehicle_image($attempt['image'])) ?>');" role="img" aria-label="<?= e($attempt['brand'] . ' ' . $attempt['model']) ?>"></div>
            <div class="summary-vehicle-info">
              <span class="vehicle-cat-tag"><?= e($attempt['category_name']) ?></span>
              <h3 class="vehicle-name"><?= e($attempt['brand']) ?> <?= e($attempt['model']) ?></h3>
              <p class="vehicle-specs-meta"><?= (int)$attempt['year'] ?> Model · <?= e($attempt['transmission']) ?> · <?= (int)$attempt['seats'] ?> Seats</p>
            </div>
          </div>

          <!-- Schedule Matrix -->
          <div class="summary-schedule-box">
            <div class="schedule-step">
              <div class="step-indicator pickup"></div>
              <div class="step-detail">
                <span class="step-label">Pickup Schedule</span>
                <strong><?= date('D, M j, Y · g:i A', strtotime($attempt['requested_pickup_at'])) ?></strong>
                <span class="step-location"><?= e($attempt['pickup_location']) ?></span>
              </div>
            </div>

            <div class="schedule-connector"></div>

            <div class="schedule-step">
              <div class="step-indicator return"></div>
              <div class="step-detail">
                <span class="step-label">Return Schedule</span>
                <strong><?= date('D, M j, Y · g:i A', strtotime($attempt['requested_return_at'])) ?></strong>
                <span class="step-location"><?= e($attempt['return_location']) ?></span>
              </div>
            </div>
          </div>

          <div class="summary-duration-pill">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            <span>Rental Duration: <strong><?= $rentalDays ?> Day<?= $rentalDays === 1 ? '' : 's' ?></strong></span>
          </div>

          <!-- Line Items Table -->
          <div class="summary-line-items">
            <div class="line-item">
              <span>Daily Rate (₱<?= number_format((float)$attempt['daily_rate'], 2) ?> × <?= $rentalDays ?> day<?= $rentalDays === 1 ? '' : 's' ?>)</span>
              <strong>₱<?= number_format($calculatedDailyTotal, 2) ?></strong>
            </div>
            <div class="line-item">
              <span>Comprehensive Vehicle Insurance</span>
              <strong class="item-included">Included</strong>
            </div>
            <div class="line-item">
              <span>24/7 Roadside Assistance</span>
              <strong class="item-included">Included</strong>
            </div>
            <div class="line-item">
              <span>Local VAT & Government Surcharges</span>
              <strong class="item-included">Included</strong>
            </div>
            
            <div class="line-items-divider"></div>

            <div class="line-item total-due-item">
              <div>
                <span class="total-label">Total Amount Due</span>
                <span class="total-currency">Philippine Peso (PHP)</span>
              </div>
              <strong class="total-value">₱<?= number_format((float)$attempt['amount'], 2) ?></strong>
            </div>
          </div>

          <!-- Customer Notice -->
          <div class="summary-security-note">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            <span>Once payment is confirmed, your receipt and booking confirmation will be ready instantly.</span>
          </div>
        </div>
      </aside>
    </div>
  </div>
</main>

<script>
// Tab Switching in Online Checkout
document.querySelectorAll('.method-tab').forEach(tab => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('.method-tab').forEach(t => {
      t.classList.remove('active');
      t.setAttribute('aria-selected', 'false');
    });
    tab.classList.add('active');
    tab.setAttribute('aria-selected', 'true');

    const targetId = tab.dataset.tabTarget;
    document.querySelectorAll('.checkout-tab-pane').forEach(pane => {
      if (pane.id === targetId) {
        pane.classList.add('active');
        pane.style.display = 'block';
      } else {
        pane.classList.remove('active');
        pane.style.display = 'none';
      }
    });
  });
});

// Format Card Number Spacing
const cardInput = document.getElementById('card-number');
if (cardInput) {
  cardInput.addEventListener('input', (e) => {
    let val = e.target.value.replace(/\D/g, '');
    let formatted = val.match(/.{1,4}/g)?.join(' ') || '';
    e.target.value = formatted.substring(0, 19);
  });
}

// Format Expiry MM / YY
const expiryInput = document.getElementById('card-expiry');
if (expiryInput) {
  expiryInput.addEventListener('input', (e) => {
    let val = e.target.value.replace(/\D/g, '');
    if (val.length >= 2) {
      e.target.value = val.substring(0, 2) + '/' + val.substring(2, 4);
    } else {
      e.target.value = val;
    }
  });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
