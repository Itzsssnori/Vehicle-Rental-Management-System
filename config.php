<?php
// Edit credentials for your local XAMPP/Laragon setup.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

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
    $base = rtrim((string)(getenv('APP_URL') ?: 'http://vrms.local'), '/');
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

function deliver_auth_link(string $kind, string $email, string $url): void
    {
        error_log("VELO {$kind} link for {$email}: {$url}");
        $_SESSION['demo_auth_link'] = $url;
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
