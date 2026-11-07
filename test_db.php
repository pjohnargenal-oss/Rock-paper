
<?php

require_once __DIR__ . '/config/config.php';

echo "<h2>Dormitory Database Connection Test</h2>";

try {
    $dsn = "mysql:host=" . DB_HOST .
           ";dbname=" . DB_NAME .
           ";charset=" . DB_CHARSET;

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    echo "<p style='color:green'>SUCCESS: Connected to MySQL!</p>";

    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    $count = $stmt->fetchColumn();

    echo "<p>Users found: " . $count . "</p>";

} catch (PDOException $e) {
    echo "<p style='color:red'>CONNECTION ERROR:</p>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
