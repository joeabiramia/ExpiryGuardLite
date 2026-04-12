<?php
$host = getenv('DB_HOST') ?: 'localhost';
$dbname = getenv('DB_NAME') ?: 'expiryguard_lite';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: '';

$conn = new mysqli($host, $username, $password, $dbname);

if ($conn->connect_error) {
    die('Database connection failed: ' . $conn->connect_error);
}

$conn->set_charset('utf8mb4');