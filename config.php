<?php
// Edit credentials for your local XAMPP/Laragon setup.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function db(): PDO {
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
        $columns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rentals'")->fetchAll(PDO::FETCH_COLUMN);
        $rentalColumns = [
            'purpose' => "ALTER TABLE rentals ADD COLUMN purpose VARCHAR(255) NULL AFTER payment_method",
            'rejection_reason' => "ALTER TABLE rentals ADD COLUMN rejection_reason VARCHAR(255) NULL AFTER purpose",
            'cancellation_reason' => "ALTER TABLE rentals ADD COLUMN cancellation_reason VARCHAR(255) NULL AFTER rejection_reason",
            'cancellation_requested' => "ALTER TABLE rentals ADD COLUMN cancellation_requested TINYINT(1) NOT NULL DEFAULT 0 AFTER cancellation_reason",
            'cancellation_requested_at' => "ALTER TABLE rentals ADD COLUMN cancellation_requested_at TIMESTAMP NULL AFTER cancellation_requested",
        ];
        foreach ($rentalColumns as $column => $statement) {
            if (!in_array($column, $columns, true)) {
                $pdo->exec($statement);
            }
        }
    }
    return $pdo;
}
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function rental_days(string $start, string $end): int {
    $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
    $endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $end);
    if (!$startDate || !$endDate || $endDate < $startDate) {
        return 0;
    }
    return $startDate->diff($endDate)->days + 1;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(400);
        exit('Invalid form token. Please reload the page and try again.');
    }
}

function vehicle_image(?string $image, string $fallback = '/assets/img/vehicle-placeholder.svg'): string {
    $image = trim((string)$image);
    if ($image === '') {
        return $fallback;
    }
    if (preg_match('#^https?://#i', $image)) {
        return $image;
    }
    $filename = basename($image);
    if (!is_file(__DIR__ . '/public/assets/img/' . $filename)) {
        return $fallback;
    }
    return '/assets/img/' . rawurlencode($filename);
}

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function require_customer(): array {
    $user = current_user();
    if (!$user || ($user['role'] ?? '') !== 'customer') {
        header('Location: /login.php?redirect=/customer/dashboard.php');
        exit;
    }
    return $user;
}

function require_role(string $role): array {
    $user = current_user();
    if (!$user || ($user['role'] ?? '') !== $role) {
        header('Location: /login.php');
        exit;
    }
    return $user;
}
