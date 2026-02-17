<?php
// Load environment variables from .env file (if it exists)
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            // Only set if not already set by system environment
            if (!isset($_ENV[$key])) {
                $_ENV[$key] = $value;
            }
        }
    }
}

// Database configuration for PostgreSQL
// Supports both .env file and system environment variables (Replit style)
define('DB_HOST', $_ENV['DB_HOST'] ?? getenv('PGHOST') ?: (getenv('DB_HOST') ?: 'localhost'));
define('DB_PORT', $_ENV['DB_PORT'] ?? getenv('PGPORT') ?: (getenv('DB_PORT') ?: '5432'));
define('DB_USER', $_ENV['DB_USER'] ?? getenv('PGUSER') ?: (getenv('DB_USER') ?: 'postgres'));
define('DB_PASS', $_ENV['DB_PASS'] ?? getenv('PGPASSWORD') ?: (getenv('DB_PASS') ?: ''));
define('DB_NAME', $_ENV['DB_NAME'] ?? getenv('PGDATABASE') ?: (getenv('DB_NAME') ?: 'nsreekan'));

// Create PostgreSQL connection using PDO
try {
    $dsn = 'pgsql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME;
    $conn = new PDO($dsn, DB_USER, DB_PASS, array(
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ));
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>
