<?php
header('Content-Type: application/json');
require_once '../config/auth.php';
require_once '../config/db.php';
require_once '../config/openai.php';

requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Invalid request method.']);
    exit;
}

$userQuery = trim($_POST['query'] ?? '');
if (!$userQuery) {
    echo json_encode(['error' => 'No query provided.']);
    exit;
}

if (empty($openaiApiKey)) {
    echo json_encode(['error' => 'OpenAI API key is not configured.']);
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
    . "SQL should only be a SELECT query (no INSERT/UPDATE/DELETE/DDL) and should return only the SQL statement without any additional comments or explanation.";

$postFields = [
    'model' => 'gpt-3.5-turbo',
    'messages' => [
        ['role' => 'system', 'content' => 'You are an expert SQL generator.'],
        ['role' => 'user', 'content' => $prompt]
    ],
    'max_tokens' => 300,
    'temperature' => 0.0
];

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $openaiApiKey
    ],
    CURLOPT_POSTFIELDS => json_encode($postFields),
    CURLOPT_TIMEOUT => 30,
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    echo json_encode(['error' => 'OpenAI request failed: ' . $curlError]);
    exit;
}

$json = json_decode($response, true);
if (!is_array($json) || empty($json['choices'][0]['message']['content'])) {
    echo json_encode(['error' => 'Failed to parse OpenAI response.']);
    exit;
}

$sql = trim($json['choices'][0]['message']['content']);
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
