<footer class="site-footer" id="contact">
  <div class="wrap footer-main-row">
    <div class="footer-col footer-col-brand">
      <div class="footer-brand-title">
        <span class="footer-brand-icon" aria-hidden="true">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/>
            <circle cx="7" cy="17" r="2"/>
            <path d="M9 17h6"/>
            <circle cx="17" cy="17" r="2"/>
          </svg>
        </span>
        <strong>VELO</strong>
      </div>
      <p class="footer-tagline">
        Practical and dependable vehicle rentals for business, family, and leisure trips. Compare rates, reserve easily, and travel with peace of mind.
      </p>
      <div class="footer-trust-badges">
        <span class="trust-pill">✓ Multi-point Inspected</span>
        <span class="trust-pill">✓ Flexible Terms</span>
      </div>
    </div>

    <div class="footer-col">
      <h4 class="footer-heading">Quick Links</h4>
      <ul class="footer-nav-list">
        <li><a href="/index.php">Home</a></li>
        <li><a href="/index.php#about">About Us</a></li>
        <li><a href="/vehicles.php">Car Listing</a></li>
        <li><a href="/index.php#how">How It Works</a></li>
        <li><a href="/vehicles.php?category=Sedan">Sedans</a></li>
        <li><a href="/vehicles.php?category=SUV">SUVs</a></li>
        <li><a href="/vehicles.php?category=Premium">Premium Fleet</a></li>
      </ul>
    </div>

    <div class="footer-col">
      <h4 class="footer-heading">Customer Portal</h4>
      <ul class="footer-nav-list">
        <?php if (current_user()): ?>
          <li><a href="<?= current_user()['role'] === 'customer' ? '/customer/dashboard.php' : (current_user()['role'] === 'staff' ? '/staff/staff-dashboard.php' : '/admin/admin-dashboard.php') ?>">Dashboard</a></li>
          <?php if (current_user()['role'] === 'customer'): ?>
            <li><a href="/customer/my-rentals.php">My Bookings & Rentals</a></li>
            <li><a href="/customer/account-settings.php">Account Settings</a></li>
          <?php endif; ?>
          <li><a href="/logout.php">Log Out</a></li>
        <?php else: ?>
          <li><a href="/login.php">Log In</a></li>
          <li><a href="/register.php">Create Account</a></li>
          <li><a href="/forgot-password.php">Forgot Password</a></li>
        <?php endif; ?>
      </ul>
    </div>

    <div class="footer-col footer-col-support">
      <h4 class="footer-heading">Helpline & Support</h4>
      <div class="footer-contact-block">
        <div class="contact-entry">
          <span class="contact-lbl">Support Email</span>
          <a href="mailto:info@velo.com" class="contact-val">info@velo.com</a>
        </div>
        <div class="contact-entry">
          <span class="contact-lbl">Helpline Call Us</span>
          <a href="tel:+638974561236" class="contact-val">+63 897 456 1236</a>
        </div>
        <div class="contact-entry">
          <span class="contact-lbl">Service Hours</span>
          <span class="contact-val text-muted">Mon - Sun: 8:00 AM - 8:00 PM</span>
        </div>
        <div class="contact-entry">
          <span class="contact-lbl">Location</span>
          <span class="contact-val text-muted">Zamboanga City, Philippines</span>
        </div>
      </div>
    </div>
  </div>

  <div class="footer-bottom-bar">
    <div class="wrap footer-bottom-inner">
      <span>&copy; <?= date('Y') ?> VELO. All rights reserved.</span>
      <div class="footer-legal-links">
        <a href="#about">About</a>
        <span>&bull;</span>
        <a href="#privacy">Privacy</a>
        <span>&bull;</span>
        <a href="#terms">Terms of Service</a>
      </div>
    </div>
  </div>
</footer>
<script src="/assets/app.js?v=<?= filemtime(__DIR__ . '/../public/assets/app.js') ?>"></script>
</body>
</html>
