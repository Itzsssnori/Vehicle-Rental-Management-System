<?php
$title = 'Admin Workspace';
require_once __DIR__ . '/../../config.php';
$user = require_role('admin');
include __DIR__ . '/../../includes/header.php';
$vehicles = (int)db()->query('SELECT COUNT(*) FROM vehicles')->fetchColumn();
$customers = (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'customer'")->fetchColumn();
$rentals = (int)db()->query("SELECT COUNT(*) FROM rentals WHERE status IN ('confirmed', 'ongoing')")->fetchColumn();
$pending = (int)db()->query("SELECT COUNT(*) FROM rentals WHERE status = 'pending'")->fetchColumn();
$pendingStaff = (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'staff' AND account_status = 'pending'")->fetchColumn();
$maintenance = (int)db()->query("SELECT COUNT(*) FROM vehicles WHERE status = 'maintenance'")->fetchColumn();
$outstanding = (float)db()->query(
    "SELECT COALESCE(SUM(r.total_amount), 0) - COALESCE((SELECT SUM(amount) FROM payments), 0)
     FROM rentals r"
)->fetchColumn();
?>
<main class="dashboard-page">
  <section class="dashboard-hero"><div><p class="eyebrow">Admin workspace</p><h1>System overview.</h1><p>Welcome, <?= e($user['name']) ?>. Monitor the fleet, users, and rental activity.</p></div><a class="btn btn-coral" href="/admin/admin-vehicles.php#vehicle-form">Create vehicle</a></section>
  <section class="dashboard-stats admin-stats">
    <article class="summary-card"><span class="summary-label">Total vehicles</span><strong><?= $vehicles ?></strong><span>Fleet records</span></article>
    <article class="summary-card"><span class="summary-label">Customers</span><strong><?= $customers ?></strong><span>Registered accounts</span></article>
    <article class="summary-card"><span class="summary-label">Active rentals</span><strong><?= $rentals ?></strong><span>Confirmed or ongoing</span></article>
    <article class="summary-card"><span class="summary-label">Outstanding</span><strong>₱<?= number_format(max(0, $outstanding)) ?></strong><span>Unpaid rental balance</span></article>
    <article class="summary-card"><span class="summary-label">Staff requests</span><strong><?= $pendingStaff ?></strong><span>Awaiting approval</span></article>
  </section>
  <section class="admin-link-grid">
    <a class="admin-link-card" href="/admin/admin-vehicles.php#vehicle-form"><strong>Fleet management</strong><span>Create, edit, retire, and price vehicles.</span></a>
    <a class="admin-link-card" href="/admin/admin-maintenance.php"><strong>Maintenance</strong><span>Schedule vehicle downtime and service records.</span></a>
    <a class="admin-link-card" href="/admin/admin-rentals.php"><strong>Rental oversight</strong><span>Review bookings, payments, and balances.</span></a>
    <a class="admin-link-card" href="/admin/admin-users.php"><strong>User management</strong><span>Review accounts and assign roles.</span></a>
    <a class="admin-link-card" href="/admin/admin-reports.php"><strong>Reports</strong><span>Review revenue and fleet performance.</span></a>
  </section>
</main>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
