<?php
function jsonResponse($success, $message, $data = null) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if ($data === null) {
        $data = new stdClass();
    }

    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit();
}

function sanitize($value) {
    return htmlspecialchars(trim($value ?? ''), ENT_QUOTES, 'UTF-8');
}