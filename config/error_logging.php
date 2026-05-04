<?php

// ─── Log file path ────────────────────────────────────────────────────────────
$logPath = getenv('ERROR_LOG_PATH') ?: __DIR__ . '/../logs/app_errors.log';
$logDir  = dirname($logPath);

if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', $logPath);
error_reporting(E_ALL);

// ─── Structured logger ────────────────────────────────────────────────────────
/**
 * Write a structured log entry to both the log file and the PHP error stream
 * (Apache/NGINX error log or stderr on CLI), so you can check either place.
 *
 * @param string $message   Human-readable description of the problem.
 * @param string $level     'ERROR' | 'WARNING' | 'INFO'
 * @param array  $context   Any extra key→value pairs to append (e.g. file, line, query).
 */
function appLog(string $message, string $level = 'ERROR', array $context = []): void
{
    $userId = 'guest';
    if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id'])) {
        $userId = (string)$_SESSION['user_id'];
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
    $uri    = $_SERVER['REQUEST_URI']    ?? 'n/a';

    $parts = [
        '[' . date('Y-m-d H:i:s') . ']',
        '[' . strtoupper($level) . ']',
        '[user:' . $userId . ']',
        '[' . $method . ' ' . $uri . ']',
        $message,
    ];

    foreach ($context as $key => $value) {
        $parts[] = $key . '=' . (is_scalar($value) ? $value : json_encode($value));
    }

    $line = implode(' ', $parts);

    // Write to our log file (via PHP's error_log channel, which is set above)
    error_log($line);
}

// ─── Uncaught exceptions ──────────────────────────────────────────────────────
set_exception_handler(function (Throwable $e) {
    appLog(
        'Uncaught exception: ' . $e->getMessage(),
        'ERROR',
        [
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
            'trace' => str_replace("\n", ' | ', $e->getTraceAsString()),
        ]
    );

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
    }

    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
        'data'    => new stdClass(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
});

// ─── Fatal errors (parse, core, compile) ─────────────────────────────────────
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error === null) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($error['type'], $fatalTypes, true)) {
        return;
    }

    appLog(
        'Fatal error: ' . $error['message'],
        'ERROR',
        [
            'file' => $error['file'],
            'line' => $error['line'],
        ]
    );
});

// ─── Runtime warnings / notices ──────────────────────────────────────────────
set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
    // Honour the @ (error-suppression) operator
    if (!(error_reporting() & $errno)) {
        return false;
    }

    $levelMap = [
        E_WARNING         => 'WARNING',
        E_NOTICE          => 'INFO',
        E_USER_ERROR      => 'ERROR',
        E_USER_WARNING    => 'WARNING',
        E_USER_NOTICE     => 'INFO',
        E_DEPRECATED      => 'INFO',
        E_USER_DEPRECATED => 'INFO',
    ];

    $level = $levelMap[$errno] ?? 'WARNING';

    appLog($errstr, $level, ['file' => $errfile, 'line' => $errline]);

    // Return false so PHP also records it in its own error log (double safety)
    return false;
});
