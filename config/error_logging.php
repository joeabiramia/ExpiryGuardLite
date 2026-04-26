<?php

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

set_exception_handler(function (Throwable $exception) {
    $message = sprintf(
        "[%s] Uncaught exception: %s in %s on line %d\nStack trace:\n%s\n",
        date('Y-m-d H:i:s'),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
        $exception->getTraceAsString()
    );

    error_log($message);

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

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        $message = sprintf(
            "[%s] Fatal error: %s in %s on line %d\n",
            date('Y-m-d H:i:s'),
            $error['message'],
            $error['file'],
            $error['line']
        );
        error_log($message);
    }
});