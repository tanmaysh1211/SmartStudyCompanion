<?php
/**
 * backend/config/db.php
 * ═══════════════════════════════════════════════════════════════
 * Establishes a single PDO connection to MySQL and exposes it
 * as the global variable $pdo.
 *
 * Features:
 *   • Lightweight .env loader (no third-party library needed)
 *   • Singleton guard — safe to require_once multiple times
 *   • utf8mb4 + utf8mb4_unicode_ci charset (full Unicode / emoji)
 *   • PDO::ERRMODE_EXCEPTION — all DB errors throw PDOException
 *   • Native prepared statements (EMULATE_PREPARES = false)
 *   • Friendly JSON error response if the connection fails
 *
 * Usage in any PHP endpoint:
 *   require_once __DIR__ . '/../config/db.php';
 *   // $pdo is now a ready-to-use PDO instance
 *
 * Environment variables (set in .env or server config):
 *   DB_HOST       MySQL hostname       (default: localhost)
 *   DB_PORT       MySQL port           (default: 3306)
 *   DB_NAME       Database name        (default: smart_study_companion)
 *   DB_USER       MySQL username       (default: root)
 *   DB_PASSWORD   MySQL password       (default: "")
 * ═══════════════════════════════════════════════════════════════
 */

declare(strict_types=1);
require_once __DIR__ . '/env.php';

// ── Guard: only create $pdo once per request lifecycle ────────
if (isset($pdo)) {
    return; // already connected — skip re-initialisation
}

// ════════════════════════════════════════════════════════════════
// 1.  Lightweight .env loader
//     Reads KEY=VALUE pairs from the project-root .env file and
//     injects them into PHP's environment (getenv / $_ENV).
//     Skips blank lines and comments (#).
//     Respects values already set by the server environment so
//     Docker / cPanel overrides always win.
// ════════════════════════════════════════════════════════════════
(static function (): void {
    // .env lives two directories above backend/config/
    $envFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';

    if (!is_readable($envFile)) {
        return; // .env not present — rely on server environment variables
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        // Skip comments and lines without an '=' sign
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }

        // Split on the FIRST '=' only (values may contain '=')
        [$rawKey, $rawValue] = explode('=', $line, 2);

        $key   = trim($rawKey);
        $value = trim($rawValue);

        // Strip surrounding single or double quotes from the value
        if (
            strlen($value) >= 2 &&
            (($value[0] === '"'  && $value[-1] === '"') ||
             ($value[0] === "'"  && $value[-1] === "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        // Only set if the key is not already defined in the environment
        if ($key !== '' && getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
        }
    }
})();

// ════════════════════════════════════════════════════════════════
// 2.  Read connection parameters
// ════════════════════════════════════════════════════════════════
$dbHost     = (string)(getenv('DB_HOST')     ?: 'localhost');
$dbPort     = (string)(getenv('DB_PORT')     ?: '3306');
$dbName     = (string)(getenv('DB_NAME')     ?: 'smart_study_companion');
$dbUser     = (string)(getenv('DB_USER')     ?: 'root');
$dbPassword = (string)(getenv('DB_PASSWORD') ?: '');

// ════════════════════════════════════════════════════════════════
// 3.  Build DSN
//     Always use utf8mb4 so the database can store emoji and the
//     full Unicode range. Without this, certain characters are
//     silently truncated by MySQL's 3-byte 'utf8' charset.
// ════════════════════════════════════════════════════════════════
$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $dbHost,
    $dbPort,
    $dbName
);

// ════════════════════════════════════════════════════════════════
// 4.  PDO driver options
// ════════════════════════════════════════════════════════════════
$pdoOptions = [
    // Throw PDOException on every DB error — never return false silently
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,

    // fetchAll() / fetch() return associative arrays by default
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

    // Use real prepared statements, not emulated ones.
    // This prevents a class of SQL injection via type confusion.
    PDO::ATTR_EMULATE_PREPARES   => false,

    // Persistent connections (optional — comment out if using
    // a connection pooler like ProxySQL or PgBouncer)
    // PDO::ATTR_PERSISTENT => true,

    // Force charset + collation on every new connection.
    // belt-and-suspenders alongside the DSN charset= param.
    PDO::MYSQL_ATTR_INIT_COMMAND =>
        "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, " .
        "time_zone = '+00:00'",                 // store dates in UTC
];

// ════════════════════════════════════════════════════════════════
// 5.  Create PDO connection
// ════════════════════════════════════════════════════════════════
try {
    $pdo = new PDO($dsn, $dbUser, $dbPassword, $pdoOptions);

} catch (PDOException $e) {
    // Log the real error message (includes host / credentials hints)
    // but NEVER expose it to the HTTP client.
    error_log(
        '[db.php] Connection failed — ' .
        'DSN: ' . $dsn . ' — ' .
        'Error: ' . $e->getMessage()
    );

    // If cors.php has not been loaded yet, set the content-type
    // header ourselves so the client receives valid JSON.
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

// ════════════════════════════════════════════════════════════════
// 6.  Verify the target database exists (dev-friendly check)
//     Comment this block out in production for a tiny perf gain.
// ════════════════════════════════════════════════════════════════
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

/*
 * $pdo is now available to any file that require_once'd this one.
 *
 * Quick usage reference:
 *
 *   // Prepared SELECT
 *   $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
 *   $stmt->execute([':id' => 1]);
 *   $user = $stmt->fetch();               // assoc array or false
 *
 *   // Prepared INSERT
 *   $stmt = $pdo->prepare('INSERT INTO notes (name, content) VALUES (:n, :c)');
 *   $stmt->execute([':n' => 'My Note', ':c' => 'Hello world']);
 *   $newId = (int)$pdo->lastInsertId();
 *
 *   // Transaction
 *   $pdo->beginTransaction();
 *   try {
 *       // ... multiple statements ...
 *       $pdo->commit();
 *   } catch (PDOException $e) {
 *       $pdo->rollBack();
 *       throw $e;
 *   }
 */