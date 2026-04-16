<?php
// Enable full error reporting and log everything to the specified file.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', '/path/to/your/error_log');
error_reporting(E_ALL);

// Optional: make sure error log directory exists and is writable.
$logDir = dirname('/path/to/your/error_log');
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}

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
        'error' => 'An unexpected exception occurred.'
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

/**
 * Example helper for logging a database connection failure.
 */
function logDatabaseConnectionFailure(string $host, string $username, string $password, string $dbname, int $port = 3306): void
{
    $mysqli = new mysqli($host, $username, $password, $dbname, $port);

    if ($mysqli->connect_error) {
        $message = sprintf(
            "[%s] Database connection failed: %s (host=%s, db=%s, port=%d)\n",
            date('Y-m-d H:i:s'),
            $mysqli->connect_error,
            $host,
            $dbname,
            $port
        );
        error_log($message);
        throw new RuntimeException('Database connection failed.');
    }

    $mysqli->close();
}

/**
 * Example of triggering and logging a generic exception.
 */
function logExampleException(): void
{
    try {
        throw new RuntimeException('Example exception for error logging.');
    } catch (Throwable $exception) {
        $message = sprintf(
            "[%s] Caught exception: %s in %s on line %d\nStack trace:\n%s\n",
            date('Y-m-d H:i:s'),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString()
        );
        error_log($message);

        throw $exception;
    }
}
