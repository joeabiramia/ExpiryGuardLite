<?php
header('Content-Type: application/json');
require_once '../config/auth.php';
require_once '../config/db.php';
require_once '../config/gemini.php';

requireLogin();
if (!isAdminUser()) {
    echo json_encode(['error' => 'Access denied. Admin only.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Invalid request method.']);
    exit;
}

$userQuery = trim($_POST['query'] ?? '');
if (!$userQuery) {
    echo json_encode(['error' => 'No query provided.']);
    exit;
}

if (empty($geminiApiKey)) {
    echo json_encode(['error' => 'Gemini API key is not configured.']);
    exit;
}

$prompt = "Translate the following natural language request into a SQL query for MySQL, considering only the database schema and data provided below:\n\n"
    . "Database schema:\n\n"
    . "Tables:\n"
    . "1. products: id, company_id, branch_id, barcode, product_name, category, expiry_date, status, entered_by, entered_on, is_removed\n"
    . "2. branches: id, branch_name\n"
    . "3. users: id, full_name, username, password, role, is_active, created_at\n\n"
    . "Please only use the database to generate a query. Do not perform any external search, and do not retrieve data from the internet. The model should only use the tables listed above to form the SQL query.\n\n"
    . "User request:\n\"$userQuery\"\n\n"
    . "SQL should only be a SELECT query (no INSERT/UPDATE/DELETE/DDL) and should return only the SQL statement without any additional comments, explanation, or code fences.";

$postFields = [
    'model' => 'gemini-1.5-pro',
    'prompt' => [
        'messages' => [
            ['role' => 'system', 'content' => 'You are an expert SQL generator.'],
            ['role' => 'user', 'content' => $prompt]
        ]
    ],
    'temperature' => 0.0,
    'maxOutputTokens' => 512
];

$ch = curl_init('https://gemini.googleapis.com/v1/models/gemini-1.5-pro:generate');
if ($ch === false) {
    echo json_encode(['error' => 'Failed to initialize cURL.']);
    exit;
}

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $geminiApiKey
    ],
    CURLOPT_POSTFIELDS => json_encode($postFields, JSON_UNESCAPED_SLASHES),
    CURLOPT_TIMEOUT => 30,
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ch = null;

if ($response === false) {
    echo json_encode(['error' => 'Gemini request failed: ' . $curlError]);
    exit;
}

$json = json_decode($response, true);
if (!is_array($json)) {
    $preview = mb_substr($response, 0, 500);
    echo json_encode([
        'error' => 'Failed to parse Gemini response.',
        'status' => $httpStatus,
        'response' => $preview
    ]);
    exit;
}

if (isset($json['error'])) {
    echo json_encode([
        'error' => 'Gemini API error: ' . ($json['error']['message'] ?? json_encode($json['error'])),
        'status' => $httpStatus,
        'response' => $json
    ]);
    exit;
}

$sql = '';
if (!empty($json['output'][0]['content'])) {
    foreach ($json['output'][0]['content'] as $chunk) {
        if (isset($chunk['text'])) {
            $sql .= $chunk['text'];
        }
    }
}

if (!$sql && !empty($json['candidates'][0]['content'])) {
    foreach ($json['candidates'][0]['content'] as $chunk) {
        if (isset($chunk['text'])) {
            $sql .= $chunk['text'];
        }
    }
}

if (!$sql && isset($json['output_text'])) {
    $sql = $json['output_text'];
}

if (!$sql && isset($json['response'])) {
    $sql = $json['response'];
}

$sql = trim($sql);
$sql = preg_replace('/^```(?:sql)?\s*/i', '', $sql);
$sql = preg_replace('/\s*```$/i', '', $sql);
$sql = trim($sql);

if (preg_match('/;/s', $sql)) {
    echo json_encode(['error' => 'Only a single SELECT statement is allowed.']);
    exit;
}

if (!preg_match('/^\s*SELECT\b/i', $sql)) {
    echo json_encode(['error' => 'Only SELECT queries are allowed.']);
    exit;
}

if (preg_match('/\b(?:INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|TRUNCATE|GRANT|REVOKE|SET|USE|SHOW|DESCRIBE|EXPLAIN|LOCK|UNLOCK|CALL|REPLACE|MERGE|HANDLER)\b/i', $sql)) {
    echo json_encode(['error' => 'Only SELECT queries are allowed.']);
    exit;
}

if (preg_match('/\b(?:information_schema|mysql|performance_schema|sys)\b/i', $sql)) {
    echo json_encode(['error' => 'Queries against system schemas are not allowed.']);
    exit;
}

$result = $conn->query($sql);
if ($result === false) {
    echo json_encode(['error' => 'SQL execution failed: ' . $conn->error, 'sql' => $sql]);
    exit;
}

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

echo json_encode([
    'sql' => $sql,
    'data' => $data
]);
