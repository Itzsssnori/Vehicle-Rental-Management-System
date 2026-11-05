<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('This utility is available from the command line only.');
}

require_once __DIR__ . '/../config.php';

$options = getopt('', ['name:', 'email:', 'password:', 'role:']);
$name = trim((string)($options['name'] ?? ''));
$email = trim((string)($options['email'] ?? ''));
$password = (string)($options['password'] ?? '');
$role = (string)($options['role'] ?? '');

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8 || !in_array($role, ['admin', 'staff', 'customer'], true)) {
    fwrite(STDERR, "Usage: php database/create-account.php --name=\"Full Name\" --email=\"user@example.com\" --password=\"at-least-8-chars\" --role=admin|staff\n");
    exit(1);
}

$pdo = db();
$existing = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$existing->execute([$email]);
if ($existing->fetch()) {
    fwrite(STDERR, "An account with that email already exists.\n");
    exit(1);
}

$pdo->beginTransaction();
try {
    $insert = $pdo->prepare(
        "INSERT INTO users (name, email, password_hash, email_verified_at, account_status)
         VALUES (?, ?, ?, NOW(), 'approved')"
    );
    $insert->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
    $userId = (int)$pdo->lastInsertId();

    $roleStmt = $pdo->prepare('SELECT id FROM roles WHERE name = ? LIMIT 1');
    $roleStmt->execute([$role]);
    $roleId = $roleStmt->fetchColumn();
    if (!$roleId) {
        throw new RuntimeException('The requested role is not configured in the database.');
    }

    $assignment = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
    $assignment->execute([$userId, $roleId]);
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, sprintf("Created approved %s account for %s.\n", $role === 'staff' ? 'faculty/staff' : 'admin', $email));
