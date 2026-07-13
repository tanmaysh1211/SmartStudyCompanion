<?php
declare(strict_types=1);
require_once __DIR__ . '/env.php';
if (isset($pdo)) {
    return; 
}

(static function (): void {
    $envFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';

    if (!is_readable($envFile)) {
        return; 
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }

        [$rawKey, $rawValue] = explode('=', $line, 2);

        $key   = trim($rawKey);
        $value = trim($rawValue);

        if (
            strlen($value) >= 2 &&
            (($value[0] === '"'  && $value[-1] === '"') ||
             ($value[0] === "'"  && $value[-1] === "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        if ($key !== '' && getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
        }
    }
})();

$dbHost     = (string)(getenv('DB_HOST')     ?: 'localhost');
$dbPort     = (string)(getenv('DB_PORT')     ?: '3306');
$dbName     = (string)(getenv('DB_NAME')     ?: 'smart_study_companion');
$dbUser     = (string)(getenv('DB_USER')     ?: 'root');
$dbPassword = (string)(getenv('DB_PASSWORD') ?: '');

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $dbHost,
    $dbPort,
    $dbName
);

$pdoOptions = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,

    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

    PDO::ATTR_EMULATE_PREPARES   => false,

    PDO::MYSQL_ATTR_INIT_COMMAND =>
        "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, " .
        "time_zone = '+00:00'",                 
];

try {
    $pdo = new PDO($dsn, $dbUser, $dbPassword, $pdoOptions);

} catch (PDOException $e) {
    error_log(
        '[db.php] Connection failed — ' .
        'DSN: ' . $dsn . ' — ' .
        'Error: ' . $e->getMessage()
    );

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(503); // Service Unavailable
    }

    echo json_encode([
        'success' => false,
        'message' => 'The database is temporarily unavailable. Please try again later.',
    ]);
    exit;
}

if (getenv('APP_ENV') !== 'production') {
    try {
        $pdo->query('SELECT 1');
    } catch (PDOException $e) {
        error_log('[db.php] Post-connect ping failed: ' . $e->getMessage());
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(503);
        }
        echo json_encode([
            'success' => false,
            'message' => 'Database ping failed. Check DB_NAME and user privileges.',
        ]);
        exit;
    }
}
