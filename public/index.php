<?php
$title = 'Vehicle Rental Management System';
require_once __DIR__ . '/../config.php';
include __DIR__ . '/../includes/header.php';
$today = date('Y-m-d');
$featuredStmt = db()->query(
    "SELECT v.*
     FROM vehicles v
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
           FROM rentals r
           WHERE r.vehicle_id = v.id
             AND r.status IN ('pending', 'confirmed', 'ongoing')
             AND r.start_date <= CURDATE()
             AND r.end_date >= CURDATE()
       )
     ORDER BY v.id DESC
     LIMIT 3"
);
$featured = $featuredStmt->fetchAll();
?>
<main class="landing-page">
  <section class="hero">
    <div class="hero-copy">
      <p class="eyebrow">Vehicle rental made simple</p>
      <h1>Find the right vehicle for your next journey.</h1>
      <p>Browse the fleet, compare rates, and submit a request through one streamlined system built for practical travel and hassle-free planning.</p>

      <form class="search-box" action="/vehicles.php" method="get">
        <label class="full">
          Pickup location
          <input type="text" name="location" placeholder="e.g. Zamboanga City">
        </label>
        <label>
          Pickup date
          <input type="date" name="start" min="<?= e($today) ?>" required>
        </label>
        <label>
          Return date
          <input type="date" name="end" min="<?= e($today) ?>" required>
        </label>
        <button class="btn btn-coral" type="submit">Search Vehicles</button>
      </form>
    </div>

    <div class="hero-visual" role="img" aria-label="Vehicle on a scenic road"></div>
  </section>

  <section class="wrap intro" id="about">
    <div class="center">
      <p class="eyebrow">A smarter experience</p>
      <h2>A simpler way to rent a vehicle.</h2>
      <p class="lead">Browse the fleet, review rental rates, submit a request, track approval, and manage your rental information in one place.</p>
    </div>
  </section>

  <section class="wrap">
    <div class="section-head">
      <div>
        <p class="eyebrow">Vehicle categories</p>
        <h2>Choose the right fit.</h2>
      </div>
    </div>

    <div class="category-grid">
      <a href="/vehicles.php?category=Sedan" class="category-card category-sedan">
        <div class="content">
          <h3>Sedans</h3>
          <span>Efficient and easy for city travel</span>
        </div>
      </a>
      <a href="/vehicles.php?category=SUV" class="category-card category-suv">
        <div class="content">
          <h3>SUVs</h3>
          <span>Comfortable room for family and cargo</span>
        </div>
      </a>
      <a href="/vehicles.php?category=Premium" class="category-card category-premium">
        <div class="content">
          <h3>Premium Vehicles</h3>
          <span>Luxury comfort for special trips</span>
        </div>
      </a>
    </div>
  </section>

  <section class="wrap">
    <div class="section-head">
      <div>
        <p class="eyebrow">Fleet</p>
        <h2>Available Vehicles</h2>
      </div>
      <a class="text-link" href="/vehicles.php">View all vehicles</a>
    </div>

    <form class="filter-bar" action="/vehicles.php" method="get" aria-label="Vehicle filters">
      <label>
        Category
        <select name="category">
          <option value="">All</option>
          <option value="Sedan">Sedan</option>
          <option value="SUV">SUV</option>
          <option value="Premium">Premium</option>
        </select>
      </label>
      <label>
        Transmission
        <select name="transmission">
          <option value="">All</option>
          <option value="Automatic">Automatic</option>
          <option value="Manual">Manual</option>
        </select>
      </label>
      <label>
        Daily Price
        <select name="max_price">
          <option value="">Any</option>
          <option value="2000">Under ₱2,000</option>
          <option value="3500">Up to ₱3,500</option>
        </select>
      </label>
      <button type="submit" class="btn btn-navy">Explore Fleet</button>
    </form>

    <div class="vehicle-grid">
      <?php foreach ($featured as $vehicle): ?>
        <article class="vehicle-card">
          <div class="vehicle-image" style="background-image:url('<?= e(vehicle_image($vehicle['image'])) ?>')"></div>
          <div class="vehicle-body">
            <div class="vehicle-meta">
              <span class="tag"><?= e($vehicle['category']) ?></span>
              <span class="status-badge status-success">Available today</span>
            </div>
            <h3><?= e($vehicle['brand']) ?> <?= e($vehicle['model']) ?></h3>
            <p class="specs"><?= e($vehicle['transmission']) ?> · <?= (int)$vehicle['seats'] ?> seats</p>
            <div class="price-row">
              <strong>₱<?= number_format($vehicle['daily_rate']) ?></strong>
              <span>/ day</span>
            </div>
            <div class="vehicle-actions">
              <a href="/vehicle.php?id=<?= (int)$vehicle['id'] ?>" class="btn btn-navy">View Details</a>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="wrap" id="how">
    <div class="section-head">
      <div>
        <p class="eyebrow">How it works</p>
        <h2>Simple steps, smooth travel.</h2>
      </div>
    </div>

    <ol class="steps">
      <li>
        <span class="step-no">01</span>
        <h3>Browse Vehicles</h3>
        <p>Explore the available fleet, compare features, and choose a vehicle that matches your itinerary.</p>
      </li>
      <li>
        <span class="step-no">02</span>
        <h3>Submit a Rental Request</h3>
        <p>Choose your dates, review the estimated cost, and send your rental request through the system.</p>
      </li>
      <li>
        <span class="step-no">03</span>
        <h3>Track Your Booking</h3>
        <p>Monitor request status, pickup instructions, and return details after your booking is confirmed.</p>
      </li>
    </ol>
  </section>

  <section class="cta-band">
    <div class="cta-content">
      <p class="eyebrow" style="color: var(--sage);">Plan your next trip</p>
      <h2>Ready for your next trip?</h2>
      <p>See which vehicles are free for your dates and choose the best fit for your journey.</p>
      <a href="/vehicles.php" class="btn btn-coral">Browse Vehicles</a>
    </div>
  </section>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
