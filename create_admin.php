<?php
require_once __DIR__ . '/config/database.php';

// This bootstrap is intentionally CLI-only: never expose a predictable admin credential on the web.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$username = getenv('DORMITORY_ADMIN_USERNAME') ?: 'admin';
$password = getenv('DORMITORY_ADMIN_PASSWORD') ?: '';
$fullName = getenv('DORMITORY_ADMIN_NAME') ?: 'System Administrator';
if (strlen($password) < 12) {
    fwrite(STDERR, "Set DORMITORY_ADMIN_PASSWORD to a unique password of at least 12 characters before running this script.\n");
    exit(1);
}
try {
    $pdo = db();
    $check = $pdo->prepare('SELECT user_id FROM users WHERE username = ?');
    $check->execute([$username]);
    if ($check->fetch()) {
        fwrite(STDOUT, "That username already exists; no account was changed.\n");
        exit(0);
    }
    $stmt = $pdo->prepare("INSERT INTO users (username,password_hash,full_name,role,status) VALUES (?,?,?,'Admin','Active')");
    $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName]);
    fwrite(STDOUT, "Administrator account created. Delete this script after use.\n");
} catch (Throwable $e) {
    error_log('Admin bootstrap failed: ' . $e->getMessage());
    fwrite(STDERR, "Could not create the administrator. Check the database configuration and PHP error log.\n");
    exit(1);
}
