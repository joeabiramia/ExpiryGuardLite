<?php
require_once __DIR__ . '/error_logging.php';

$host = getenv('DB_HOST') ?: 'localhost';
$port = getenv('DB_PORT') ?: 3306;
$dbname = getenv('DB_NAME') ?: 'expiryguard_lite';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: '';

$conn = new mysqli($host, $username, $password, $dbname, $port);

if ($conn->connect_error) {
    appLog('Database connection failed', 'ERROR', [
        'host'  => $host,
        'db'    => $dbname,
        'error' => $conn->connect_error,
    ]);

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed',
        'data'    => new stdClass(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$conn->set_charset('utf8mb4');