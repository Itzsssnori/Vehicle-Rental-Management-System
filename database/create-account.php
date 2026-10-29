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

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8 || !in_array($role, ['admin', 'staff'], true)) {
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

$insert = $pdo->prepare(
    "INSERT INTO users (name, email, password_hash, role, account_status)
     VALUES (?, ?, ?, ?, 'approved')"
);
$insert->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);

fwrite(STDOUT, sprintf("Created approved %s account for %s.\n", $role === 'staff' ? 'faculty/staff' : 'admin', $email));
