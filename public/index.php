<?php
$title = 'VELO - Find Your Next Vehicle';
require_once __DIR__ . '/../config.php';
include __DIR__ . '/../includes/header.php';

$today = date('Y-m-d');
$catFilter = trim($_GET['category'] ?? '');

$sql = "SELECT v.*, c.name AS category
        FROM vehicles v
        INNER JOIN vehicle_categories c ON c.id = v.category_id
        WHERE v.status = 'operational'
          AND NOT EXISTS (
              SELECT 1
              FROM maintenance m
              WHERE m.vehicle_id = v.id
                AND m.start_date <= CURDATE()
                AND (m.end_date IS NULL OR m.end_date >= CURDATE())
          )
          AND NOT EXISTS (
              SELECT 1
              FROM reservations r
              WHERE r.vehicle_id = v.id
                AND r.status IN ('pending', 'confirmed')
                AND r.requested_pickup_at < CURRENT_DATE + INTERVAL 1 DAY
                AND r.requested_return_at >= CURRENT_DATE
          )";

$params = [];
$sql .= " ORDER BY v.id DESC LIMIT 12";

$featuredStmt = db()->prepare($sql);
$featuredStmt->execute($params);
$featured = $featuredStmt->fetchAll();

// Dynamic Hero Car Banners: rotates on every page landing / reset or manual switch
$heroBanners = [
    [
        'id' => 'hero-bmw',
        'image' => '/assets/hero/hero-bmw-blue.jpg',
        'badge' => 'BMW X5 xDrive M-Sport',
        'eyebrow' => 'Move with confidence',
        'headline' => 'Find the Right Vehicle for Your Next Journey',
        'tag' => 'Luxury Performance SUV'
    ],
    [
        'id' => 'hero-rangerover',
        'image' => '/assets/hero/hero-rangerover-silver.jpg',
        'badge' => 'Range Rover Sport HSE',
        'eyebrow' => 'All-Terrain Elegance',
        'headline' => 'Experience Premium Comfort & Unmatched Power',
        'tag' => 'Flagship Luxury 4x4'
    ],
    [
        'id' => 'hero-audi',
        'image' => '/assets/hero/hero-audi-black.jpg',
        'badge' => 'Audi RS7 Sportback Quattro',
        'eyebrow' => 'Dynamic Distinction',
        'headline' => 'Command the Open Road in Executive Style',
        'tag' => 'Executive Performance GT'
    ],
    [
        'id' => 'hero-mercedes',
        'image' => '/assets/hero/hero-mercedes-red.jpg',
        'badge' => 'Mercedes-AMG C63 S Coupe',
        'eyebrow' => 'Crafted Precision',
        'headline' => 'Arrive in Distinction with Certified Fleet',
        'tag' => 'Grand Tourer'
    ],
    [
        'id' => 'hero-porsche',
        'image' => '/assets/hero/hero-porsche-orange.jpg',
        'badge' => 'Porsche 911 Carrera GTS',
        'eyebrow' => 'Pure Performance',
        'headline' => 'Discover the Thrill of Dynamic Driving',
        'tag' => 'High-Performance Sports'
    ]
];

$activeHeroIndex = array_rand($heroBanners);
$activeHero = $heroBanners[$activeHeroIndex];
?>

<main class="portal-landing-page">
  <!-- Hero Section with Scenic Car Banner & Quick Booking Form -->
  <section class="portal-hero" id="portal-hero" style="background-image: url('<?= e($activeHero['image']) ?>');" data-hero-banners='<?= htmlspecialchars(json_encode($heroBanners), ENT_QUOTES, 'UTF-8') ?>' data-active-index="<?= (int)$activeHeroIndex ?>">
    <div class="hero-overlay-backdrop"></div>
    <div class="wrap portal-hero-container">
      <div class="portal-hero-content">
        <span class="hero-badge-eyebrow" id="hero-badge-eyebrow"><span class="eyebrow-line"></span><span class="eyebrow-text"><?= e($activeHero['eyebrow']) ?></span></span>
        <h1 class="hero-headline" id="hero-headline"><?= e($activeHero['headline']) ?></h1>
        <p class="hero-subtext">
          Browse our certified fleet of luxury, family, and economical vehicles with transparent daily rates and guaranteed reliability.
        </p>

        <form class="hero-search-card" action="/vehicles.php" method="get">
          <div class="search-field full-field">
            <label for="hero-location">
              <span class="field-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="2.5"/></svg></span>
              <span>Pickup & Return Location</span>
            </label>
            <input id="hero-location" type="text" name="location" placeholder="e.g. Zamboanga City Center">
          </div>

          <div class="search-field">
            <label for="hero-start">
              <span class="field-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg></span>
              <span>Pickup Date</span>
            </label>
            <input id="hero-start" type="date" name="start" min="<?= e($today) ?>" required>
          </div>

          <div class="search-field">
            <label for="hero-end">
              <span class="field-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg></span>
              <span>Return Date</span>
            </label>
            <input id="hero-end" type="date" name="end" min="<?= e($today) ?>" required>
          </div>

          <div class="search-field full-field search-submit-col">
            <button class="hero-submit-btn" type="submit">
              <span>Search Available Vehicles</span>
            </button>
          </div>
        </form>

        <!-- Hero Vehicle Spotlight Indicator & Controls -->
        <div class="hero-spotlight-bar">
          <div class="hero-spotlight-pill">
            <span class="spotlight-car-icon" aria-hidden="true">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/></svg>
            </span>
            <span class="spotlight-prefix">Spotlight:</span>
            <strong class="spotlight-name" id="hero-spotlight-name"><?= e($activeHero['badge']) ?></strong>
            <span class="spotlight-tag" id="hero-spotlight-tag"><?= e($activeHero['tag']) ?></span>
          </div>

          <div class="hero-switcher-dots" role="tablist" aria-label="Hero background car switcher">
            <?php foreach ($heroBanners as $idx => $banner): ?>
              <button type="button" class="hero-dot-btn <?= $idx === $activeHeroIndex ? 'active' : '' ?>" data-hero-switch="<?= (int)$idx ?>" aria-label="Switch background to <?= e($banner['badge']) ?>" title="<?= e($banner['badge']) ?>"></button>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Showcase Section: "Find the Best CarForYou" -->
  <section class="wrap portal-showcase-section" id="fleet">
    <div class="section-center-header">
      <h2 class="find-best-title">Find the Best <span>CarForYou</span></h2>
      <p class="find-best-subtitle">
        There are many variations of vehicles available in our fleet, rigorously maintained and inspected for comfort, safety, and peak performance. Discover the ideal vehicle tailored for your trip.
      </p>

      <!-- Category Filter Pills (like "New Car" pill in reference image) -->
      <div class="category-pills-bar" role="tablist" aria-label="Vehicle categories">
        <a href="#fleet" class="cat-pill <?= empty($catFilter) ? 'active' : '' ?>" data-fleet-filter="all">
          All Cars
        </a>
        <a href="#fleet" class="cat-pill <?= $catFilter === 'Sedan' ? 'active' : '' ?>" data-fleet-filter="Sedan">
          Sedan
        </a>
        <a href="#fleet" class="cat-pill <?= $catFilter === 'SUV' ? 'active' : '' ?>" data-fleet-filter="SUV">
          SUV
        </a>
        <a href="#fleet" class="cat-pill <?= $catFilter === 'Premium' ? 'active' : '' ?>" data-fleet-filter="Premium">
          Premium
        </a>
      </div>
    </div>

    <!-- 3-Column Vehicle Cards Grid -->
    <div class="car-portal-grid" id="landing-fleet-grid">
      <?php if (empty($featured)): ?>
        <div class="portal-empty-grid">
          <p>No operational vehicles found for this category at the moment.</p>
          <a href="/vehicles.php" class="btn btn-navy">Browse All Vehicles</a>
        </div>
      <?php else: ?>
        <?php foreach ($featured as $vehicle): ?>
          <?php
            $fuel = $vehicle['fuel_type'] ?: 'Petrol';
            $year = $vehicle['year'] ?: 2022;
            $seats = (int)($vehicle['seats'] ?: 5);
            $imgUrl = vehicle_image($vehicle['image']);
            $desc = $vehicle['description'] ?: ($vehicle['category'] . ' with ' . strtolower($vehicle['transmission']) . ' transmission, well-maintained for comfort and fuel economy.');
          ?>
          <article class="car-listing-card" data-fleet-category="<?= e($vehicle['category']) ?>">
            <div class="card-media-wrapper">
              <a href="/vehicle.php?id=<?= (int)$vehicle['id'] ?>" class="card-img-link" tabindex="-1" aria-hidden="true">
                <div class="card-img" style="background-image: url('<?= e($imgUrl) ?>');" role="img" aria-label="<?= e($vehicle['brand'] . ' ' . $vehicle['model']) ?>"></div>
              </a>

              <!-- Dark Specification Ribbon Underneath Image -->
              <div class="car-spec-ribbon">
                <div class="spec-col">
                  <span class="spec-glyph" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 20V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v15M4 20h14M6 8h10M18 7l2 2v8a2 2 0 0 1-4 0v-3h2"/></svg></span>
                  <span class="spec-lbl"><?= e($fuel) ?></span>
                </div>
                <div class="spec-col">
                  <span class="spec-glyph" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/></svg></span>
                  <span class="spec-lbl"><?= (int)$year ?> Model</span>
                </div>
                <div class="spec-col">
                  <span class="spec-glyph" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="8" r="3"/><path d="M3 20v-1a6 6 0 0 1 12 0v1M16 11a3 3 0 1 0 0-6M17 14a5 5 0 0 1 4 5v1"/></svg></span>
                  <span class="spec-lbl"><?= $seats ?> seats</span>
                </div>
              </div>
            </div>

            <div class="card-info-content">
              <div class="card-title-price-row">
                <h3 class="card-vehicle-title">
                  <a href="/vehicle.php?id=<?= (int)$vehicle['id'] ?>">
                    <?= e($vehicle['brand']) ?> <?= e($vehicle['model']) ?>
                  </a>
                </h3>
                <div class="card-daily-price">
                  <strong>₱<?= number_format((float)$vehicle['daily_rate']) ?></strong>
                  <span class="price-cycle">/Day</span>
                </div>
              </div>

              <p class="card-snippet-desc">
                <?= e(strlen($desc) > 95 ? substr($desc, 0, 92) . '...' : $desc) ?>
              </p>

              <div class="card-action-bar">
                <span class="card-status-pill status-ready">Available</span>
                <a href="/vehicle.php?id=<?= (int)$vehicle['id'] ?>" class="card-action-btn">
                  View Details &rarr;
                </a>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="portal-grid-cta">
      <a href="/vehicles.php" class="view-all-fleet-btn">
        <span>View Full Fleet Listing</span>
        <span aria-hidden="true">&rarr;</span>
      </a>
    </div>
  </section>

  <!-- About Us / Why Choose Us Section -->
  <section class="portal-features-band" id="about">
    <div class="wrap">
      <div class="section-center-header">
        <span class="section-eyebrow">Why Choose VELO</span>
        <h2 class="section-heading">We Deliver Comfort, Safety & Value</h2>
        <p class="section-lead">Experience hassle-free mobility with our customer-focused rental services.</p>
      </div>

      <div class="features-4col-grid">
        <div class="feature-item-box">
          <div class="feature-icon-badge" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3 20 6v5c0 5-3.4 8.5-8 10-4.6-1.5-8-5-8-10V6l8-3Z"/><path d="m8 12 2.5 2.5L16 9"/></svg></div>
          <h3>Multi-Point Inspected</h3>
          <p>Every vehicle undergoes rigorous mechanical checks and deep sanitation prior to each handover.</p>
        </div>

        <div class="feature-item-box">
          <div class="feature-icon-badge" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/></svg></div>
          <h3>Transparent Pricing</h3>
          <p>Clear daily pricing with zero hidden fees. Multiple payment methods accepted: Cash, Bank, GCash & Card.</p>
        </div>

        <div class="feature-item-box">
          <div class="feature-icon-badge" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 4h3l2 5-2 2a14 14 0 0 0 5 5l2-2 5 2v3c0 1-1 2-2 2C10 21 3 14 3 6c0-1 1-2 2-2Z"/></svg></div>
          <h3>Dedicated Helpline</h3>
          <p>24/7 helpline and responsive customer service team ready to support you on every leg of your journey.</p>
        </div>

        <div class="feature-item-box">
          <div class="feature-icon-badge" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m13 2-9 12h7l-1 8 9-12h-7l1-8Z"/></svg></div>
          <h3>Quick Online Request</h3>
          <p>Submit your booking request in under 2 minutes. Track approval status and receipts in real time.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- How It Works Section -->
  <section class="wrap portal-workflow-section" id="how">
    <div class="section-center-header">
      <span class="section-eyebrow">Streamlined Process</span>
      <h2 class="section-heading">How It Works in 3 Simple Steps</h2>
      <p class="section-lead">From choosing your dream car to returning the keys, we make every step seamless.</p>
    </div>

    <div class="steps-flow-grid">
      <div class="flow-step-card">
        <span class="flow-step-num">01</span>
        <div class="flow-step-content">
          <h3>Browse & Choose</h3>
          <p>Explore our wide collection of sedans, SUVs, and luxury vehicles to find the ideal match for your trip.</p>
        </div>
      </div>

      <div class="flow-step-card">
        <span class="flow-step-num">02</span>
        <div class="flow-step-content">
          <h3>Submit Request</h3>
          <p>Select your dates, preferred payment method, and submit. Receive prompt review from our operations staff.</p>
        </div>
      </div>

      <div class="flow-step-card">
        <span class="flow-step-num">03</span>
        <div class="flow-step-content">
          <h3>Pickup & Drive</h3>
          <p>Inspect the vehicle with staff at our pickup hub, receive your digital receipt, and enjoy your trip.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- CTA Band -->
  <section class="portal-cta-band">
    <div class="wrap portal-cta-inner">
      <div class="cta-text-side">
        <h2>Ready to Hit the Road?</h2>
        <p>Book your vehicle today and enjoy flexible daily rates, certified safety, and top-tier support.</p>
      </div>
      <div class="cta-btn-side">
        <a href="/vehicles.php" class="btn-cta-primary">Browse All Vehicles</a>
        <?php if (!current_user()): ?>
          <a href="/register.php" class="btn-cta-secondary">Create Free Account</a>
        <?php endif; ?>
      </div>
    </div>
  </section>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
