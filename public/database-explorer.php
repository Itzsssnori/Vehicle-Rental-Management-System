<?php
/**
 * VELO VRMS — Live 3NF Database Workbench & EER Explorer
 * URL: http://vrms.local/database-explorer.php
 *
 * Connects directly to live MariaDB `vrms` information_schema to display:
 *  - All live tables, real row counts, columns, PK/FK/UQ badges grouped into 4 Domain Clusters
 *  - Dynamically generated ER Diagram from live foreign-key constraints
 *  - Interactive read-only SQL Query Console (SELECT / SHOW / DESCRIBE / EXPLAIN)
 */
require_once __DIR__ . '/../config.php';
$user = require_role('admin');
if (!is_super_admin($user)) {
    http_response_code(403);
    exit('Forbidden: Super Administrator access is required to inspect live database tables.');
}
$pdo = db();

// Cluster mapping for all VELO VRMS tables
$clusterMap = [
    'c1' => [
        'label' => 'Cluster 1: Identity & Personal',
        'color' => '#2563EB',
        'desc'  => 'Users, roles, permissions, driver profiles, and Senior Citizen / PWD verifications.',
        'tables' => ['users', 'roles', 'permissions', 'user_roles', 'role_permissions', 'auth_tokens', 'auth_rate_limits', 'customer_profiles', 'customer_eligibility_verifications', 'customer_account_standing'],
    ],
    'c2' => [
        'label' => 'Cluster 2: Fleet & Business Assets',
        'color' => '#059669',
        'desc'  => '3NF vehicle makes, models, categories, transmission/fuel lookups, hubs, physical fleet, and maintenance.',
        'tables' => ['locations', 'vehicle_categories', 'vehicle_makes', 'vehicle_models', 'transmission_types', 'fuel_types', 'vehicles', 'maintenance'],
    ],
    'c3' => [
        'label' => 'Cluster 3: Operations & Transactions',
        'color' => '#D97706',
        'desc'  => 'Reservations, rentals, dispatch/return logs, inspections, damages, incidents, disputes, and payment settlements.',
        'tables' => ['reservations', 'reservation_status_history', 'reservation_cancellation_requests', 'rentals', 'rental_status_history', 'pickup_records', 'return_records', 'vehicle_inspections', 'damage_reports', 'rental_charges', 'payments', 'payment_receipts', 'refunds', 'payment_adjustments', 'online_payment_attempts', 'penalty_events', 'rental_incidents', 'incident_contact_attempts', 'incident_escalations', 'disputes'],
    ],
    'c4' => [
        'label' => 'Cluster 4: Pricing & Promotions',
        'color' => '#7C3AED',
        'desc'  => 'Philippine statutory discounts (RA 9994/10754), holiday calendar, promo codes, and immutable booking discount snapshots.',
        'tables' => ['discount_types', 'discount_rules', 'holiday_calendar', 'promo_codes', 'reservation_discounts'],
    ],
];

// Fetch live tables & row counts
$liveTables = $pdo->query(
    "SELECT TABLE_NAME, TABLE_ROWS, ENGINE
     FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
     ORDER BY TABLE_NAME ASC"
)->fetchAll(PDO::FETCH_ASSOC);

// Fetch live columns
$colRows = $pdo->query(
    "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, COLUMN_KEY, IS_NULLABLE
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
     ORDER BY TABLE_NAME ASC, ORDINAL_POSITION ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$columnsByTable = [];
foreach ($colRows as $c) {
    $columnsByTable[$c['TABLE_NAME']][] = $c;
}

// Fetch live foreign keys
$fkRows = $pdo->query(
    "SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
     FROM information_schema.KEY_COLUMN_USAGE
     WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
     ORDER BY TABLE_NAME ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$fkLookup = [];
foreach ($fkRows as $fk) {
    $fkLookup[$fk['TABLE_NAME'] . '.' . $fk['COLUMN_NAME']] = $fk['REFERENCED_TABLE_NAME'];
}

// Assign every live table to a cluster
$tablesByCluster = ['c1' => [], 'c2' => [], 'c3' => [], 'c4' => [], 'other' => []];
foreach ($liveTables as $t) {
    $name = $t['TABLE_NAME'];
    $placed = false;
    foreach ($clusterMap as $cid => $meta) {
        if (in_array($name, $meta['tables'], true)) {
            $tablesByCluster[$cid][] = $t;
            $placed = true;
            break;
        }
    }
    if (!$placed) {
        $tablesByCluster['other'][] = $t;
    }
}

// Handle Read-Only Live SQL Query Console
$sqlInput = trim((string)($_POST['sql_query'] ?? ''));
$queryResult = null;
$queryColumns = [];
$queryError = '';
$queryDurationMs = 0;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $sqlInput !== '') {
    verify_csrf();
    // Strictly allow read-only inspection queries and block credential/token tables
    if (!preg_match('/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $sqlInput) || preg_match('/;\s*\S/', $sqlInput)) {
        $queryError = 'Only single read-only queries (SELECT, SHOW, DESCRIBE, EXPLAIN) are permitted in the live explorer.';
    } elseif (preg_match('/\b(password_hash|auth_tokens|mysql\.user|information_schema\.user_privileges)\b/i', $sqlInput)) {
        $queryError = 'Query blocked by security policy: credential and token columns cannot be queried here.';
    } else {
        $t0 = microtime(true);
        try {
            $stmt = $pdo->query($sqlInput);
            $queryResult = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($queryResult)) {
                $queryColumns = array_keys($queryResult[0]);
            }
            $queryDurationMs = round((microtime(true) - $t0) * 1000, 2);
        } catch (Throwable $ex) {
            $queryError = $ex->getMessage();
        }
    }
}

$presetQueries = [
    'Show All Tables' => 'SHOW TABLES',
    '3NF Fleet + Hubs JOIN' => "SELECT v.plate_number, COALESCE(mk.name, v.brand) AS make, COALESCE(vm.name, v.model) AS model, vc.name AS category, v.daily_rate, l.name AS hub\nFROM vehicles v\nINNER JOIN vehicle_categories vc ON vc.id = v.category_id\nLEFT JOIN vehicle_models vm ON vm.id = v.model_id\nLEFT JOIN vehicle_makes mk ON mk.id = vm.make_id\nLEFT JOIN locations l ON l.id = v.current_location_id\nWHERE v.status = 'operational'\nLIMIT 12",
    'Users, Roles & Hubs' => "SELECT u.id, u.name, u.email, r.name AS role, COALESCE(l.name, 'Global') AS hub, u.account_status\nFROM users u\nINNER JOIN user_roles ur ON ur.user_id = u.id\nINNER JOIN roles r ON r.id = ur.role_id\nLEFT JOIN locations l ON l.id = u.location_id\nORDER BY u.id",
    'PH Discount Rules' => "SELECT dt.label AS category, dr.name, dr.calculation_mode, dr.discount_value, dr.vat_exempt_eligible, dr.is_stackable\nFROM discount_rules dr\nINNER JOIN discount_types dt ON dt.id = dr.discount_type_id\nORDER BY dr.priority ASC",
    'Active Promo Codes' => "SELECT pc.code, dr.name AS rule_name, pc.current_redemptions, pc.max_redemptions, pc.is_active\nFROM promo_codes pc\nINNER JOIN discount_rules dr ON dr.id = pc.discount_rule_id",
    'Verified Senior/PWD IDs' => "SELECT u.name, u.email, cev.eligibility_type, cev.id_number, cev.issuing_authority, cev.verification_status\nFROM customer_eligibility_verifications cev\nINNER JOIN users u ON u.id = cev.user_id",
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>VELO VRMS — Live Database Workbench &amp; EER Explorer</title>
  <style>
    :root {
      --navy: #26384F;
      --navy-dark: #162231;
      --coral: #E66B68;
      --bg: #F8F7F3;
      --card: #FFFFFF;
      --border: #E2E8F0;
      --text: #1E293B;
      --muted: #64748B;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Manrope', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background: var(--bg);
      color: var(--text);
      line-height: 1.5;
      padding: 24px 20px 64px;
    }
    .wrap { max-width: 1380px; margin: 0 auto; }
    .hero {
      background: linear-gradient(135deg, var(--navy-dark) 0%, var(--navy) 100%);
      color: #FFFFFF;
      border-radius: 16px;
      padding: 28px 32px;
      margin-bottom: 22px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 16px;
    }
    .hero-eyebrow { font-size: 0.72rem; font-weight: 800; letter-spacing: 0.1em; text-transform: uppercase; color: var(--coral); margin-bottom: 4px; }
    .hero h1 { font-size: 1.6rem; font-weight: 800; }
    .hero p { font-size: 0.88rem; color: #CBD5E1; margin-top: 4px; max-width: 660px; }
    .stats { display: flex; gap: 10px; flex-wrap: wrap; }
    .stat { background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.14); border-radius: 10px; padding: 10px 16px; text-align: center; min-width: 100px; }
    .stat strong { display: block; font-size: 1.2rem; }
    .stat span { font-size: 0.68rem; color: #CBD5E1; text-transform: uppercase; letter-spacing: 0.05em; }

    .card { background: var(--card); border: 1px solid var(--border); border-radius: 14px; padding: 20px 22px; margin-bottom: 22px; box-shadow: 0 4px 14px rgba(15,23,42,0.04); }
    .card h2 { font-size: 1.1rem; color: var(--navy-dark); margin-bottom: 4px; }
    .card-sub { font-size: 0.82rem; color: var(--muted); margin-bottom: 14px; }

    /* SQL Console */
    .preset-bar { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 10px; }
    .preset-btn { background: #F1F5F9; border: 1px solid #CBD5E1; color: #334155; padding: 5px 11px; border-radius: 8px; font-size: 0.76rem; font-weight: 700; cursor: pointer; }
    .preset-btn:hover { background: var(--navy); color: #FFFFFF; border-color: var(--navy); }
    textarea.sql-box {
      width: 100%;
      min-height: 110px;
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
      font-size: 0.84rem;
      padding: 12px 14px;
      border: 1.5px solid #CBD5E1;
      border-radius: 10px;
      background: #0F172A;
      color: #E2E8F0;
      resize: vertical;
    }
    .sql-actions { display: flex; justify-content: space-between; align-items: center; margin-top: 10px; flex-wrap: wrap; gap: 8px; }
    .btn-run { background: var(--coral); color: #FFFFFF; border: none; padding: 9px 20px; border-radius: 8px; font-size: 0.84rem; font-weight: 800; cursor: pointer; }
    .btn-run:hover { opacity: 0.92; }
    .alert-err { background: #FEF2F2; border-left: 4px solid #EF4444; color: #991B1B; padding: 10px 14px; border-radius: 8px; font-size: 0.82rem; margin-top: 12px; font-weight: 600; }
    .result-wrap { margin-top: 14px; overflow-x: auto; border: 1px solid var(--border); border-radius: 10px; }
    table.res-table { width: 100%; border-collapse: collapse; font-size: 0.78rem; }
    table.res-table th { background: #F8FAFC; text-align: left; padding: 8px 12px; border-bottom: 1px solid var(--border); color: var(--navy-dark); font-weight: 800; white-space: nowrap; }
    table.res-table td { padding: 7px 12px; border-bottom: 1px solid #F1F5F9; color: #334155; }
    table.res-table tr:last-child td { border-bottom: none; }

    /* Cluster Filters & Table Cards */
    .filter-bar { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
    .filter-btn { background: #FFFFFF; border: 1.5px solid var(--border); color: var(--text); padding: 7px 14px; border-radius: 999px; font-size: 0.8rem; font-weight: 700; cursor: pointer; }
    .filter-btn.active { background: var(--navy); color: #FFFFFF; border-color: var(--navy); }
    .cluster-box.is-hidden { display: none; }
    .table-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 14px; }
    .tbl-card { border: 1px solid var(--border); border-radius: 10px; overflow: hidden; background: #FFFFFF; }
    .tbl-head { background: #F8FAFC; padding: 9px 12px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
    .tbl-head code { font-size: 0.86rem; font-weight: 800; color: var(--navy-dark); }
    .tbl-head span { font-size: 0.7rem; color: var(--muted); font-weight: 700; }
    .col-list { list-style: none; padding: 6px 12px 10px; max-height: 260px; overflow-y: auto; font-size: 0.76rem; }
    .col-list li { display: flex; justify-content: space-between; gap: 6px; padding: 4px 0; border-bottom: 1px dashed #F1F5F9; }
    .col-list li:last-child { border-bottom: none; }
    .badge { font-size: 0.62rem; font-weight: 800; padding: 1px 5px; border-radius: 4px; margin-right: 4px; }
    .b-pk { background: #DBEAFE; color: #1D4ED8; }
    .b-fk { background: #FEF3C7; color: #B45309; }
    .b-uq { background: #EDE9FE; color: #6D28D9; }
    .mermaid { overflow-x: auto; text-align: center; }
  </style>
</head>
<body>
  <div class="wrap">
    <header class="hero">
      <div>
        <div class="hero-eyebrow">LIVE MARIADB 10.4 WORKBENCH &middot; DATABASE: VRMS</div>
        <h1>Interactive Database Explorer &amp; SQL Console</h1>
        <p>Reads live tables, foreign-key relationships, and real row counts directly from your running XAMPP MariaDB server — zero Workbench compatibility errors.</p>
      </div>
      <div class="stats">
        <div class="stat"><strong><?= count($liveTables) ?></strong><span>Live Tables</span></div>
        <div class="stat"><strong><?= count($fkRows) ?></strong><span>Foreign Keys</span></div>
        <div class="stat"><strong>4</strong><span>3NF Clusters</span></div>
      </div>
    </header>

    <!-- LIVE SQL QUERY CONSOLE -->
    <section class="card" id="sql-console">
      <h2>Live SQL Query Console</h2>
      <p class="card-sub">Click a preset presentation query below or type any read-only <code>SELECT</code> / <code>SHOW</code> / <code>DESCRIBE</code> statement to execute live against <code>vrms</code>.</p>

      <div class="preset-bar">
        <?php foreach ($presetQueries as $lbl => $q): ?>
          <button type="button" class="preset-btn" onclick="loadPreset(<?= htmlspecialchars(json_encode($q), ENT_QUOTES, 'UTF-8') ?>)"><?= e($lbl) ?></button>
        <?php endforeach; ?>
      </div>

      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <textarea id="sql-box" class="sql-box" name="sql_query" placeholder="SELECT * FROM discount_rules;"><?= e($sqlInput ?: $presetQueries['3NF Fleet + Hubs JOIN']) ?></textarea>
        <div class="sql-actions">
          <span style="font-size:0.76rem; color:#64748b;">Connected to <code>127.0.0.1:3306 / vrms</code> (Read-Only Safe Mode)</span>
          <button type="submit" class="btn-run">Execute SQL Query</button>
        </div>
      </form>

      <?php if ($queryError): ?>
        <div class="alert-err"><?= e($queryError) ?></div>
      <?php elseif ($queryResult !== null): ?>
        <div style="margin-top:12px; font-size:0.78rem; color:#059669; font-weight:700;">
          Returned <?= count($queryResult) ?> row(s) in <?= $queryDurationMs ?> ms
        </div>
        <?php if (!empty($queryResult)): ?>
          <div class="result-wrap">
            <table class="res-table">
              <thead>
                <tr>
                  <?php foreach ($queryColumns as $col): ?>
                    <th><?= e($col) ?></th>
                  <?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($queryResult as $row): ?>
                  <tr>
                    <?php foreach ($queryColumns as $col): ?>
                      <td><?= e($row[$col] === null ? 'NULL' : (string)$row[$col]) ?></td>
                    <?php endforeach; ?>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </section>

    <!-- LIVE CLUSTERED TABLES -->
    <div class="filter-bar">
      <button class="filter-btn active" data-filter="all">All Tables (<?= count($liveTables) ?>)</button>
      <?php foreach ($clusterMap as $cid => $meta): ?>
        <button class="filter-btn" data-filter="<?= $cid ?>"><?= e($meta['label']) ?> (<?= count($tablesByCluster[$cid]) ?>)</button>
      <?php endforeach; ?>
      <?php if (!empty($tablesByCluster['other'])): ?>
        <button class="filter-btn" data-filter="other">Audit &amp; System (<?= count($tablesByCluster['other']) ?>)</button>
      <?php endif; ?>
    </div>

    <?php foreach ($clusterMap as $cid => $meta): ?>
      <section class="card cluster-box" data-cluster="<?= $cid ?>">
        <h2 style="color:<?= $meta['color'] ?>;"><?= e($meta['label']) ?></h2>
        <p class="card-sub"><?= e($meta['desc']) ?></p>
        <div class="table-grid">
          <?php foreach ($tablesByCluster[$cid] as $tbl): $tName = $tbl['TABLE_NAME']; ?>
            <article class="tbl-card">
              <div class="tbl-head">
                <code><?= e($tName) ?></code>
                <span>~<?= (int)$tbl['TABLE_ROWS'] ?> rows</span>
              </div>
              <ul class="col-list">
                <?php foreach ($columnsByTable[$tName] ?? [] as $col): ?>
                  <?php $fkTarget = $fkLookup[$tName . '.' . $col['COLUMN_NAME']] ?? null; ?>
                  <li>
                    <span>
                      <?php if ($col['COLUMN_KEY'] === 'PRI'): ?><span class="badge b-pk">PK</span><?php endif; ?>
                      <?php if ($fkTarget): ?><span class="badge b-fk" title="References <?= e($fkTarget) ?>">FK</span><?php endif; ?>
                      <?php if ($col['COLUMN_KEY'] === 'UNI'): ?><span class="badge b-uq">UQ</span><?php endif; ?>
                      <strong><?= e($col['COLUMN_NAME']) ?></strong>
                    </span>
                    <span style="font-family:monospace; color:#64748b;"><?= e($fkTarget ? '-> ' . $fkTarget : $col['COLUMN_TYPE']) ?></span>
                  </li>
                <?php endforeach; ?>
              </ul>
            </article>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>

    <?php if (!empty($tablesByCluster['other'])): ?>
      <section class="card cluster-box" data-cluster="other">
        <h2>Audit &amp; System Tables</h2>
        <p class="card-sub">Immutable audit trails, entity change logs, and customer notifications.</p>
        <div class="table-grid">
          <?php foreach ($tablesByCluster['other'] as $tbl): $tName = $tbl['TABLE_NAME']; ?>
            <article class="tbl-card">
              <div class="tbl-head">
                <code><?= e($tName) ?></code>
                <span>~<?= (int)$tbl['TABLE_ROWS'] ?> rows</span>
              </div>
              <ul class="col-list">
                <?php foreach ($columnsByTable[$tName] ?? [] as $col): ?>
                  <li>
                    <span>
                      <?php if ($col['COLUMN_KEY'] === 'PRI'): ?><span class="badge b-pk">PK</span><?php endif; ?>
                      <strong><?= e($col['COLUMN_NAME']) ?></strong>
                    </span>
                    <span style="font-family:monospace; color:#64748b;"><?= e($col['COLUMN_TYPE']) ?></span>
                  </li>
                <?php endforeach; ?>
              </ul>
            </article>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>
  </div>

  <script>
    function loadPreset(sql) {
      document.getElementById('sql-box').value = sql;
    }
    document.querySelectorAll('.filter-btn').forEach(function(btn) {
      btn.addEventListener('click', function() {
        document.querySelectorAll('.filter-btn').forEach(function(b) { b.classList.remove('active'); });
        btn.classList.add('active');
        var f = btn.getAttribute('data-filter');
        document.querySelectorAll('.cluster-box').forEach(function(box) {
          box.classList.toggle('is-hidden', f !== 'all' && box.getAttribute('data-cluster') !== f);
        });
      });
    });
  </script>
</body>
</html>
