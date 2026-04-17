<?php

header('Content-Type: application/json; charset=utf-8');

require_once '../config/auth.php';
require_once '../config/db.php';
require_once '../config/gemini.php';

/*
|--------------------------------------------------------------------------
| Security Check
|--------------------------------------------------------------------------
*/

requireLogin();

if (!isAdminUser()) {
    echo json_encode([
        'error' => 'Access denied. Admin only.'
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'error' => 'Invalid request method.'
    ]);
    exit;
}

$userQuery = trim($_POST['query'] ?? '');

if (empty($userQuery)) {
    echo json_encode([
        'error' => 'No query provided.'
    ]);
    exit;
}

if (empty($geminiApiKey)) {
    echo json_encode([
        'error' => 'Gemini API key is not configured.'
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| Prompt for Gemini
|--------------------------------------------------------------------------
*/

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
- password
- role
- is_active
- created_at

Rules:
- Only generate a SELECT query
- No INSERT
- No UPDATE
- No DELETE
- No DROP
- No ALTER
- No CREATE
- No external internet search
- Only use the database schema above
- Return ONLY the SQL query
- No explanation
- No comments
- No markdown
- No code blocks

User request:
\"$userQuery\"";

/*
|--------------------------------------------------------------------------
| Correct Gemini API Endpoint
|--------------------------------------------------------------------------
*/

$endpoint = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=" . urlencode($geminiApiKey);

/*
|--------------------------------------------------------------------------
| Correct Gemini Request Body
|--------------------------------------------------------------------------
*/

$postFields = [
    "contents" => [
        [
            "parts" => [
                [
                    "text" => $prompt
                ]
            ]
        ]
    ],
    "generationConfig" => [
        "temperature" => 0.0,
        "maxOutputTokens" => 512
    ]
];

/*
|--------------------------------------------------------------------------
| cURL Request
|--------------------------------------------------------------------------
*/

$ch = curl_init($endpoint);

if ($ch === false) {
    echo json_encode([
        'error' => 'Failed to initialize cURL.'
    ]);
    exit;
}

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json'
    ],
    CURLOPT_POSTFIELDS => json_encode(
        $postFields,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ),
    CURLOPT_TIMEOUT => 30,
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

/*
|--------------------------------------------------------------------------
| Handle cURL Failure
|--------------------------------------------------------------------------
*/

if ($response === false) {
    echo json_encode([
        'error' => 'Gemini request failed: ' . $curlError
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| Decode Gemini Response
|--------------------------------------------------------------------------
*/

$json = json_decode($response, true);

if (!is_array($json)) {
    echo json_encode([
        'error' => 'Failed to parse Gemini response.',
        'status' => $httpStatus,
        'raw_response' => mb_substr($response, 0, 1000)
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| Handle Gemini API Error
|--------------------------------------------------------------------------
*/

if (isset($json['error'])) {
    echo json_encode([
        'error' => 'Gemini API error: ' . ($json['error']['message'] ?? 'Unknown error'),
        'status' => $httpStatus,
        'response' => $json
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| Correct Gemini Response Parsing
|--------------------------------------------------------------------------
*/

$sql = '';

if (
    isset($json['candidates'][0]['content']['parts'][0]['text'])
) {
    $sql = trim(
        $json['candidates'][0]['content']['parts'][0]['text']
    );
}

/*
|--------------------------------------------------------------------------
| Cleanup SQL
|--------------------------------------------------------------------------
*/

$sql = preg_replace('/^```(?:sql)?\s*/i', '', $sql);
$sql = preg_replace('/\s*```$/i', '', $sql);
$sql = trim($sql);

/*
|--------------------------------------------------------------------------
| Security Validation
|--------------------------------------------------------------------------
*/

if (empty($sql)) {
    echo json_encode([
        'error' => 'Gemini did not return a valid SQL query.',
        'response' => $json
    ]);
    exit;
}

if (preg_match('/;/', $sql)) {
    echo json_encode([
        'error' => 'Only a single SELECT statement is allowed.'
    ]);
    exit;
}

if (!preg_match('/^\s*SELECT\b/i', $sql)) {
    echo json_encode([
        'error' => 'Only SELECT queries are allowed.',
        'sql' => $sql
    ]);
    exit;
}

if (
    preg_match(
        '/\b(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|TRUNCATE|GRANT|REVOKE|SET|USE|SHOW|DESCRIBE|EXPLAIN|LOCK|UNLOCK|CALL|REPLACE|MERGE|HANDLER)\b/i',
        $sql
    )
) {
    echo json_encode([
        'error' => 'Only SELECT queries are allowed.',
        'sql' => $sql
    ]);
    exit;
}

if (
    preg_match(
        '/\b(information_schema|mysql|performance_schema|sys)\b/i',
        $sql
    )
) {
    echo json_encode([
        'error' => 'Queries against system schemas are not allowed.'
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| Execute SQL
|--------------------------------------------------------------------------
*/

$result = $conn->query($sql);

if ($result === false) {
    echo json_encode([
        'error' => 'SQL execution failed: ' . $conn->error,
        'sql' => $sql
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| Fetch Data
|--------------------------------------------------------------------------
*/

$data = [];

while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

/*
|--------------------------------------------------------------------------
| Final Success Response
|--------------------------------------------------------------------------
*/

echo json_encode([
    'success' => true,
    'sql' => $sql,
    'data' => $data
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;