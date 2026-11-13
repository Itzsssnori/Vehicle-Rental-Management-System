<?php
// Edit credentials for your local XAMPP/Laragon setup.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Automatically load local .env (or fallback to .env.example) if present
$envFilePath = is_file(__DIR__ . '/.env') ? __DIR__ . '/.env' : (is_file(__DIR__ . '/.env.example') ? __DIR__ . '/.env.example' : null);
if ($envFilePath) {
    $envLines = file($envFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($envLines as $envLine) {
        $envLine = trim($envLine);
        if ($envLine === '' || str_starts_with($envLine, '#')) {
            continue;
        }
        if (str_contains($envLine, '=')) {
            [$envKey, $envVal] = explode('=', $envLine, 2);
            $envKey = trim($envKey);
            $envVal = trim($envVal);
            if ((str_starts_with($envVal, '"') && str_ends_with($envVal, '"')) || (str_starts_with($envVal, "'") && str_ends_with($envVal, "'"))) {
                $envVal = substr($envVal, 1, -1);
            }
            if (getenv($envKey) === false || getenv($envKey) === '') {
                putenv("{$envKey}={$envVal}");
                $_ENV[$envKey] = $envVal;
                $_SERVER[$envKey] = $envVal;
            }
        }
    }
}

require_once __DIR__ . '/includes/mailer.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        // Use TCP by default so native PHP and XAMPP PHP do not depend on different Unix sockets.
        $dbHost = getenv('DB_HOST') ?: '127.0.0.1';
        $dbPort = getenv('DB_PORT') ?: '3306';
        $dbName = getenv('DB_NAME') ?: 'vrms';
        $dbUser = getenv('DB_USER') ?: 'vrms';
        $dbPass = getenv('DB_PASS') ?: 'vrms123';

        $pdo = new PDO("mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}
function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function rental_days(string $start, string $end): int
{
    $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
    $endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $end);
    if (!$startDate || !$endDate || $endDate < $startDate) {
        return 0;
    }
    return $startDate->diff($endDate)->days + 1;
}

function late_fee_amount(string $expectedReturnAt, string $actualReturnAt, float $dailyRate, int $graceMinutes = 30): array
{
    $expected = new DateTimeImmutable($expectedReturnAt);
    $actual = new DateTimeImmutable($actualReturnAt);
    $billableFrom = $expected->modify("+{$graceMinutes} minutes");
    if ($actual <= $billableFrom || $dailyRate <= 0) {
        return ['hours' => 0, 'amount' => 0.0];
    }

    $minutesLate = (int)ceil(($actual->getTimestamp() - $billableFrom->getTimestamp()) / 60);
    $hoursLate = (int)ceil($minutesLate / 60);
    $hourlyRate = 250.00;
    $amount = min($hoursLate * $hourlyRate, $dailyRate);
    return ['hours' => $hoursLate, 'amount' => round($amount, 2)];
}

function rental_balance(PDO $pdo, int $reservationId, ?int $rentalId = null): array
{
    $chargeSql = 'SELECT COALESCE(SUM(amount), 0) FROM rental_charges WHERE reservation_id = ?';
    $chargeParams = [$reservationId];
    $paymentSql = 'SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.reservation_id = ?';
    $paymentParams = [$reservationId];
    if ($rentalId !== null) {
        $chargeSql .= ' OR rental_id = ?';
        $chargeParams[] = $rentalId;
        $paymentSql .= ' OR p.rental_id = ?';
        $paymentParams[] = $rentalId;
    }
    $charges = $pdo->prepare($chargeSql);
    $charges->execute($chargeParams);
    $payments = $pdo->prepare($paymentSql);
    $payments->execute($paymentParams);
    $charged = (float)$charges->fetchColumn();
    $paid = (float)$payments->fetchColumn();
    $refunds = $pdo->prepare(
        'SELECT COALESCE(SUM(rf.amount), 0)
         FROM refunds rf INNER JOIN payments p ON p.id = rf.payment_id
         WHERE p.reservation_id = ?' . ($rentalId !== null ? ' OR p.rental_id = ?' : '')
    );
    $refunds->execute($rentalId !== null ? [$reservationId, $rentalId] : [$reservationId]);
    $paid -= (float)$refunds->fetchColumn();
    $adjustments = $pdo->prepare(
        'SELECT COALESCE(SUM(CASE WHEN adjustment_type = "debit" THEN amount ELSE -amount END), 0)
         FROM payment_adjustments
         WHERE reservation_id = ?' . ($rentalId !== null ? ' OR rental_id = ?' : '')
    );
    $adjustments->execute($rentalId !== null ? [$reservationId, $rentalId] : [$reservationId]);
    $charged += (float)$adjustments->fetchColumn();
    return ['charged' => $charged, 'paid' => $paid, 'outstanding' => max(0.0, $charged - $paid)];
}

function active_penalty_points(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(points), 0)
         FROM penalty_events
         WHERE user_id = ? AND status = 'approved'
           AND (expires_at IS NULL OR expires_at >= CURRENT_DATE)"
    );
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}

function customer_standing(PDO $pdo, int $userId): array
{
    $points = active_penalty_points($pdo, $userId);
    $standing = $points >= 8 ? 'suspended' : ($points >= 5 ? 'restricted' : ($points >= 3 ? 'warning' : 'good'));
    return ['points' => $points, 'standing' => $standing];
}

function customer_can_reserve(PDO $pdo, int $userId): bool
{
    $account = $pdo->prepare('SELECT account_status, email_verified_at FROM users WHERE id = ?');
    $account->execute([$userId]);
    $accountRecord = $account->fetch();
    if (!$accountRecord || $accountRecord['account_status'] !== 'approved' || empty($accountRecord['email_verified_at'])) {
        return false;
    }

    $standing = customer_standing($pdo, $userId);
    return !in_array($standing['standing'], ['restricted', 'suspended'], true);
}

function mark_customer_suspended(PDO $pdo, int $userId, int $reviewedBy, string $reason): void
{
    $account = $pdo->prepare("UPDATE users SET account_status = 'suspended' WHERE id = ?");
    $account->execute([$userId]);
    $standing = $pdo->prepare(
        "INSERT INTO customer_account_standing (user_id, standing, reviewed_by, reason)
         VALUES (?, 'suspended', ?, ?)
         ON DUPLICATE KEY UPDATE standing = 'suspended', reviewed_by = VALUES(reviewed_by), reason = VALUES(reason)"
    );
    $standing->execute([$userId, $reviewedBy, $reason]);
}

function mark_customer_archived(PDO $pdo, int $userId, int $reviewedBy, string $reason): void
{
    $account = $pdo->prepare("UPDATE users SET account_status = 'archived' WHERE id = ?");
    $account->execute([$userId]);
    $standing = $pdo->prepare(
        "INSERT INTO customer_account_standing (user_id, standing, reviewed_by, reason)
         VALUES (?, 'suspended', ?, ?)
         ON DUPLICATE KEY UPDATE standing = 'suspended', reviewed_by = VALUES(reviewed_by), reason = VALUES(reason)"
    );
    $standing->execute([$userId, $reviewedBy, $reason]);
}

function restore_customer_account(PDO $pdo, int $userId, int $reviewedBy): void
{
    $account = $pdo->prepare("UPDATE users SET account_status = 'approved' WHERE id = ?");
    $account->execute([$userId]);
    $standing = customer_standing($pdo, $userId)['standing'];
    $stmt = $pdo->prepare(
        "INSERT INTO customer_account_standing (user_id, standing, reviewed_by, reason)
         VALUES (?, ?, ?, 'Account restored by administrator')
         ON DUPLICATE KEY UPDATE standing = VALUES(standing), reviewed_by = VALUES(reviewed_by), reason = VALUES(reason)"
    );
    $stmt->execute([$userId, $standing, $reviewedBy]);
}

function record_rental_status(PDO $pdo, int $rentalId, ?int $changedBy, string $newStatus, ?string $oldStatus = null, ?string $reason = null): void
{
    $history = $pdo->prepare(
        'INSERT INTO rental_status_history (rental_id, changed_by, old_status, new_status, reason) VALUES (?, ?, ?, ?, ?)'
    );
    $history->execute([$rentalId, $changedBy, $oldStatus, $newStatus, $reason]);
}

function record_reservation_status(PDO $pdo, int $reservationId, ?int $changedBy, string $newStatus, ?string $oldStatus = null, ?string $reason = null): void
{
    $history = $pdo->prepare(
        'INSERT INTO reservation_status_history (reservation_id, changed_by, old_status, new_status, reason) VALUES (?, ?, ?, ?, ?)'
    );
    $history->execute([$reservationId, $changedBy, $oldStatus, $newStatus, $reason]);
}

function write_audit(PDO $pdo, ?int $userId, string $action, ?string $tableName = null, ?int $recordId = null, ?string $description = null): void
{
    $audit = $pdo->prepare(
        'INSERT INTO audit_logs (user_id, action, table_name, record_id, description) VALUES (?, ?, ?, ?, ?)'
    );
    $audit->execute([$userId, $action, $tableName, $recordId, $description]);
}

function notify_user(PDO $pdo, int $userId, string $title, string $message, ?int $rentalId = null): void
{
    $notification = $pdo->prepare(
        'INSERT INTO notifications (user_id, rental_id, title, message) VALUES (?, ?, ?, ?)'
    );
    $notification->execute([$userId, $rentalId, $title, $message]);
}

function notify_reservation(PDO $pdo, int $userId, string $title, string $message, int $reservationId): void
{
    $notification = $pdo->prepare(
        'INSERT INTO notifications (user_id, reservation_id, title, message) VALUES (?, ?, ?, ?)'
    );
    $notification->execute([$userId, $reservationId, $title, $message]);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(400);
        exit('Invalid form token. Please reload the page and try again.');
    }
}

function auth_token(): string
{
    return bin2hex(random_bytes(32));
}

function auth_token_hash(string $token): string
{
    return hash('sha256', $token);
}

function app_url(string $path): string
{
    $env = getenv('APP_URL');
    if ($env) {
        $base = rtrim($env, '/');
    } elseif (!empty($_SERVER['HTTP_HOST'])) {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
        $scheme = $isHttps ? 'https' : 'http';
        $base = $scheme . '://' . $_SERVER['HTTP_HOST'];
    } else {
        $base = 'http://vrms.local';
    }
    return $base . '/' . ltrim($path, '/');
}

function auth_rate_limited(string $action, string $identity, int $maxAttempts = 5, int $windowMinutes = 15): bool
    {
        $pdo = db();
        $identityHash = hash('sha256', strtolower(trim($identity)));
        $windowStart = (new DateTimeImmutable('now'))->modify("-{$windowMinutes} minutes")->format('Y-m-d H:i:s');
        $cleanup = $pdo->prepare('DELETE FROM auth_rate_limits WHERE window_started_at < ?');
        $cleanup->execute([$windowStart]);
        $find = $pdo->prepare(
            'SELECT id, attempts FROM auth_rate_limits WHERE action = ? AND identity_hash = ? AND window_started_at >= ? ORDER BY id DESC LIMIT 1'
        );
        $find->execute([$action, $identityHash, $windowStart]);
        $row = $find->fetch();
        if ($row && (int)$row['attempts'] >= $maxAttempts) {
            return true;
        }
        if ($row) {
            $update = $pdo->prepare('UPDATE auth_rate_limits SET attempts = attempts + 1 WHERE id = ?');
            $update->execute([(int)$row['id']]);
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO auth_rate_limits (action, identity_hash, window_started_at, attempts) VALUES (?, ?, NOW(), 1)'
            );
            $insert->execute([$action, $identityHash]);
        }
        return false;
}

function issue_auth_token(PDO $pdo, int $userId, string $type, int $ttlHours): string
    {
        $pdo->prepare('UPDATE auth_tokens SET used_at = NOW() WHERE user_id = ? AND token_type = ? AND used_at IS NULL')
            ->execute([$userId, $type]);
        $token = auth_token();
        $expiresAt = $pdo->query("SELECT DATE_ADD(NOW(), INTERVAL {$ttlHours} HOUR)")->fetchColumn();
        $stmt = $pdo->prepare(
            'INSERT INTO auth_tokens (user_id, token_type, token_hash, expires_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $type, auth_token_hash($token), $expiresAt]);
        return $token;
}

function find_auth_token(PDO $pdo, string $token, string $type): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT t.id AS token_id, t.user_id, u.email, u.name
             FROM auth_tokens t INNER JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ? AND t.token_type = ? AND t.used_at IS NULL AND t.expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([auth_token_hash($token), $type]);
        return $stmt->fetch() ?: null;
}

function consume_auth_token(PDO $pdo, int $tokenId): void
    {
        $stmt = $pdo->prepare('UPDATE auth_tokens SET used_at = NOW() WHERE id = ? AND used_at IS NULL');
        $stmt->execute([$tokenId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('This authentication link has already been used.');
        }
}

function vehicle_image(?string $image, string $fallback = '/assets/img/vehicle-placeholder.svg'): string
{
    $image = trim((string)$image);
    if ($image === '') {
        return $fallback;
    }
    if (preg_match('#^https?://#i', $image)) {
        return $image;
    }
    if (str_starts_with($image, '/') && is_file(__DIR__ . '/public' . $image)) {
        return $image;
    }
    $filename = basename($image);
    if (is_file(__DIR__ . '/public/assets/vehicles/' . $filename)) {
        return '/assets/vehicles/' . rawurlencode($filename);
    }
    if (is_file(__DIR__ . '/public/assets/img/' . $filename)) {
        return '/assets/img/' . rawurlencode($filename);
    }
    return $fallback;
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function user_has_role(int $userId, string $role): bool
{
    if (in_array($role, ['admin', 'staff'], true)) {
        $stmt = db()->prepare(
            "SELECT 1 FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.name IN (?, 'super_admin') LIMIT 1"
        );
        $stmt->execute([$userId, $role]);
        return (bool)$stmt->fetchColumn();
    }
    $stmt = db()->prepare(
        'SELECT 1 FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.name = ? LIMIT 1'
    );
    $stmt->execute([$userId, $role]);
    return (bool)$stmt->fetchColumn();
}

function user_has_permission(int $userId, string $permission): bool
{
    $stmt = db()->prepare(
        'SELECT 1
         FROM user_roles ur
         INNER JOIN role_permissions rp ON rp.role_id = ur.role_id
         INNER JOIN permissions p ON p.id = rp.permission_id
         WHERE ur.user_id = ? AND p.name = ?
         LIMIT 1'
    );
    $stmt->execute([$userId, $permission]);
    return (bool)$stmt->fetchColumn();
}

function require_customer(): array
{
    $user = current_user();
    if (!$user || !user_has_role((int)$user['id'], 'customer')) {
        header('Location: /login.php?redirect=/customer/dashboard.php');
        exit;
    }
    return $user;
}

function require_role(string $role): array
{
    $user = current_user();
    if (!$user || !user_has_role((int)$user['id'], $role)) {
        header('Location: /login.php');
        exit;
    }
    return $user;
}

function is_super_admin($userOrId = null): bool
{
    if (is_array($userOrId)) {
        $userId = (int)($userOrId['id'] ?? 0);
    } elseif (is_numeric($userOrId)) {
        $userId = (int)$userOrId;
    } else {
        $user = current_user();
        if (!$user) return false;
        $userId = (int)$user['id'];
    }
    return $userId > 0 && user_has_role($userId, 'super_admin');
}

function current_user_location_id($userOrId = null): ?int
{
    if (is_array($userOrId) && array_key_exists('location_id', $userOrId)) {
        return !empty($userOrId['location_id']) ? (int)$userOrId['location_id'] : null;
    }
    $user = current_user();
    return !empty($user['location_id']) ? (int)$user['location_id'] : null;
}

function get_active_locations(?PDO $pdo = null): array
{
    $pdo = $pdo ?? db();
    $stmt = $pdo->query('SELECT id, name, address, city, phone, opening_time, closing_time, instructions FROM locations WHERE status = "active" AND deleted_at IS NULL ORDER BY id ASC');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_protection_plan_info(string $tier): array
{
    return match ($tier) {
        'medium' => [
            'tier' => 'medium',
            'name' => 'Standard CDW Protection',
            'daily_fee' => 350.00,
            'deductible' => 5000.00,
            'description' => 'Collision Damage Waiver with reduced excess. Covers body dent and paint repairs up to ₱5,000 deductible.',
        ],
        'premium' => [
            'tier' => 'premium',
            'name' => 'Comprehensive Zero-Excess',
            'daily_fee' => 600.00,
            'deductible' => 0.00,
            'description' => 'Zero out-of-pocket deductible for accidents, theft protection, windshield glass, and tire damage.',
        ],
        default => [
            'tier' => 'basic',
            'name' => 'Basic Third-Party Cover',
            'daily_fee' => 0.00,
            'deductible' => 15000.00,
            'description' => 'Compulsory third-party liability cover. Customer liable up to ₱15,000 for collision damages.',
        ],
    };
}

function calculate_security_deposit(int $categoryId): float
{
    return match ($categoryId) {
        2 => 8000.00,   // SUV
        3 => 12000.00,  // Premium
        default => 5000.00, // Sedan / Standard
    };
}

function is_vehicle_available_with_buffer(PDO $pdo, int $vehicleId, string $pickupAt, string $returnAt, ?int $excludeReservationId = null, int $bufferHours = 2): bool
{
    $sql = "SELECT COUNT(*) FROM reservations 
            WHERE vehicle_id = ? 
              AND status IN ('confirmed', 'pending')
              AND deleted_at IS NULL
              AND requested_pickup_at < DATE_ADD(?, INTERVAL ? HOUR)
              AND DATE_ADD(requested_return_at, INTERVAL ? HOUR) > ?";
    $params = [$vehicleId, $returnAt, $bufferHours, $bufferHours, $pickupAt];

    if ($excludeReservationId !== null) {
        $sql .= " AND id <> ?";
        $params[] = $excludeReservationId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn() === 0;
}

function entity_log_change(PDO $pdo, string $table, int $recordId, string $action, ?array $oldValues = null, ?array $newValues = null, ?int $userId = null): void
{
    try {
        if ($userId === null && current_user()) {
            $userId = (int)current_user()['id'];
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $stmt = $pdo->prepare(
            "INSERT INTO entity_change_logs (table_name, record_id, action, old_values, new_values, changed_by, ip_address)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $table,
            $recordId,
            $action,
            $oldValues !== null ? json_encode($oldValues, JSON_THROW_ON_ERROR) : null,
            $newValues !== null ? json_encode($newValues, JSON_THROW_ON_ERROR) : null,
            $userId,
            $ip,
        ]);
    } catch (Throwable $e) {
        error_log('Failed to write entity change log: ' . $e->getMessage());
    }
}

function soft_delete_entity(PDO $pdo, string $table, int $id, int $userId): bool
{
    $allowed = ['vehicles', 'locations', 'users', 'reservations'];
    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException("Table $table does not support soft deletion.");
    }
    $fetch = $pdo->prepare("SELECT * FROM `$table` WHERE id = ? AND deleted_at IS NULL");
    $fetch->execute([$id]);
    $oldData = $fetch->fetch(PDO::FETCH_ASSOC);
    if (!$oldData) {
        return false;
    }
    $update = $pdo->prepare("UPDATE `$table` SET deleted_at = NOW(), deleted_by = ? WHERE id = ?");
    $res = $update->execute([$userId, $id]);
    if ($res) {
        entity_log_change($pdo, $table, $id, 'SOFT_DELETE', $oldData, ['deleted_at' => date('Y-m-d H:i:s'), 'deleted_by' => $userId], $userId);
    }
    return $res;
}

function restore_entity(PDO $pdo, string $table, int $id, int $userId): bool
{
    $allowed = ['vehicles', 'locations', 'users', 'reservations'];
    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException("Table $table does not support restoration.");
    }
    $fetch = $pdo->prepare("SELECT * FROM `$table` WHERE id = ? AND deleted_at IS NOT NULL");
    $fetch->execute([$id]);
    $oldData = $fetch->fetch(PDO::FETCH_ASSOC);
    if (!$oldData) {
        return false;
    }
    $update = $pdo->prepare("UPDATE `$table` SET deleted_at = NULL, deleted_by = NULL WHERE id = ?");
    $res = $update->execute([$id]);
    if ($res) {
        entity_log_change($pdo, $table, $id, 'RESTORE', $oldData, ['deleted_at' => null, 'deleted_by' => null], $userId);
    }
    return $res;
}

