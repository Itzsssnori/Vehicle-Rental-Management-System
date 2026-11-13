<?php
$title = 'Notifications';
require_once __DIR__ . '/../../config.php';
$user = require_customer();
$pdo = db();

function time_ago(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return floor($diff / 86400) . 'd ago';
}

$notifStmt = $pdo->prepare('SELECT id, title, message, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50');
$notifStmt->execute([$user['id']]);
$notifications = $notifStmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<main class="portal-customer-page">
  <div class="portal-customer-layout">
    <?php include __DIR__ . '/../../includes/customer-sidebar.php'; ?>
    <div class="customer-main-content">

      <section class="portal-hero-banner" aria-label="Notifications heading">
        <div class="portal-hero-text">
          <div class="portal-hero-eyebrow">
            <span class="pulse-indicator" aria-hidden="true"></span>
            <span>MY ACCOUNT - NOTIFICATIONS</span>
          </div>
          <h1 class="portal-hero-title">Notifications</h1>
          <p class="portal-hero-subtitle">Your recent system notifications and alerts.</p>
        </div>
        <div class="portal-hero-actions">
          <a href="/customer/dashboard.php" class="btn btn-outline-white">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
            <span>Dashboard</span>
          </a>
        </div>
      </section>

      <section class="portal-card" aria-label="Notification list">
        <div class="portal-card-header">
          <div class="portal-card-title-group">
            <h2>All Notifications</h2>
            <p><?= count($notifications) ?> notification<?= count($notifications) !== 1 ? 's' : '' ?></p>
          </div>
        </div>

        <?php if (empty($notifications)): ?>
          <div style="text-align:center; padding:40px 20px; color:#94a3b8;">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="margin:0 auto 12px; opacity:0.4; display:block;"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            <p style="font-size:0.90rem; font-weight:600; color:#64748b; margin:0 0 4px;">No notifications yet</p>
            <p style="font-size:0.82rem; color:#94a3b8; margin:0;">System alerts, booking updates, and account notices will appear here.</p>
          </div>
        <?php else: ?>
          <div style="display:flex; flex-direction:column;">
            <?php foreach ($notifications as $i => $notif): ?>
              <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:16px; padding:14px 0;<?= $i < count($notifications) - 1 ? ' border-bottom:1px solid #f1f5f9;' : '' ?>">
                <div style="flex:1; min-width:0;">
                  <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                    <strong style="font-size:0.90rem; color:#0f172a; font-weight:700;"><?= e($notif['title']) ?></strong>
                  </div>
                  <p style="font-size:0.84rem; color:#334155; margin:0; line-height:1.5;"><?= e($notif['message']) ?></p>
                </div>
                <span style="font-size:0.74rem; color:#94a3b8; white-space:nowrap; flex-shrink:0; padding-top:2px;"><?= time_ago($notif['created_at']) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

    </div>
  </div>
</main>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
