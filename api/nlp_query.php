<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../config/helpers.php';
require_once '../config/auth.php';
require_once '../config/db.php';
require_once '../config/gemini.php';
require_once '../config/api_auth.php';

addSecurityHeaders();

requireLogin();

if (!isAdminUser()) {
    jsonResponse(false, 'Access denied', null, 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Invalid request method', null, 405);
}

$userQuery = trim($_POST['query'] ?? '');
if (empty($userQuery)) {
    jsonResponse(false, 'No query provided', null, 400);
}

$prompt = "Translate the following natural language request into a safe MySQL SELECT query only.

Database schema:

1. products
- id
- company_id
- branch_id
- barcode
- product_name
- category
- expiry_date
- status
- entered_by
- entered_on
- is_removed

2. branches
- id
- branch_name

3. users
- id
- full_name
- username
- role
- is_active
- created_at

Rules:
- Only generate a SELECT query
- No INSERT, UPDATE, DELETE, DROP, ALTER, CREATE, TRUNCATE, GRANT, REVOKE
- Only use the database schema above
- Return ONLY the SQL query
- No explanation, no comments, no markdown, no code blocks

User request:
\"$userQuery\"";

// API key sent as header — keeps it out of server access logs
$endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent';

$postFields = [
    'contents'         => [['parts' => [['text' => $prompt]]]],
    'generationConfig' => ['temperature' => 0.0, 'maxOutputTokens' => 512],
];

$ch = curl_init($endpoint);
if ($ch === false) {
    jsonResponse(false, 'Failed to initialize request', null, 500);
}

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-goog-api-key: ' . $geminiApiKey,
    ],
    CURLOPT_POSTFIELDS     => json_encode($postFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    CURLOPT_TIMEOUT        => 30,
]);

$response   = curl_exec($ch);
$curlError  = curl_error($ch);
$httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false) {
    jsonResponse(false, 'AI service request failed', null, 502);
}

$json = json_decode($response, true);
if (!is_array($json)) {
    jsonResponse(false, 'Failed to parse AI response', null, 502);
}

if (isset($json['error'])) {
    jsonResponse(false, 'AI service error: ' . ($json['error']['message'] ?? 'Unknown error'), null, 502);
}

$sql = trim($json['candidates'][0]['content']['parts'][0]['text'] ?? '');

// Strip any markdown code fences Gemini might add
$sql = preg_replace('/^```(?:sql)?\s*/i', '', $sql);
$sql = preg_replace('/\s*```$/i',         '', $sql);
$sql = trim($sql);

if (empty($sql)) {
    jsonResponse(false, 'AI did not return a valid query', null, 422);
}

// Security validation — only SELECT, no multi-statement
if (preg_match('/;/', $sql)) {
    jsonResponse(false, 'Only a single SELECT statement is allowed', null, 400);
}
if (!preg_match('/^\s*SELECT\b/i', $sql)) {
    jsonResponse(false, 'Only SELECT queries are allowed', null, 400);
}
if (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|TRUNCATE|GRANT|REVOKE|CALL|REPLACE|MERGE)\b/i', $sql)) {
    jsonResponse(false, 'Only SELECT queries are allowed', null, 400);
}
if (preg_match('/\b(information_schema|mysql|performance_schema|sys)\b/i', $sql)) {
    jsonResponse(false, 'Queries against system schemas are not allowed', null, 400);
}

$result = $conn->query($sql);
if ($result === false) {
    jsonResponse(false, 'Query execution failed', null, 422);
}

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

// SQL is NOT returned to the client — it stays server-side
jsonResponse(true, 'Query executed successfully', $data);