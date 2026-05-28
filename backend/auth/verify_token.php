<?php
/**
 * backend/auth/verify_token.php
 * ─────────────────────────────────────────────────────────────
 * Reusable middleware helper — NOT a standalone endpoint.
 *
 * Usage in any protected PHP file:
 *
 *   require_once __DIR__ . '/../auth/verify_token.php';
 *   $authUser = requireAuth();   // returns decoded payload array
 *                                 // or sends 401 and exits
 *
 * The returned $authUser array contains:
 *   [
 *     'sub'   => int,    // user ID
 *     'name'  => string,
 *     'email' => string,
 *     'iat'   => int,    // issued-at timestamp
 *     'exp'   => int,    // expiry timestamp
 *   ]
 *
 * ─────────────────────────────────────────────────────────────
 * Also exposes a lighter helper:
 *
 *   $payload = getTokenPayload();   // returns payload or null
 *                                    // (does NOT abort)
 * ─────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

// jwt_helper.php must already be loaded by the including file,
// but we guard against double-inclusion here for safety.
if (!function_exists('decodeJWT')) {
    require_once __DIR__ . '/../config/jwt_helper.php';
}

// db.php must also be available for blacklist checks.
if (!isset($pdo)) {
    require_once __DIR__ . '/../config/db.php';
}

// ─────────────────────────────────────────────────────────────
/**
 * Extracts the raw Bearer token string from the current request.
 *
 * Checks:
 *  1. Standard Authorization header
 *  2. REDIRECT_HTTP_AUTHORIZATION (some Apache setups)
 *  3. Fallback query-string ?token= (use only for GET endpoints
 *     where headers are hard to set, e.g. file downloads)
 *
 * @return string  Raw token, or '' if not found.
 */
// function extractBearerToken(): string
// {
//     // 1. Standard header
//     $header = $_SERVER['HTTP_AUTHORIZATION']
//            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
//            ?? '';

//     if ($header !== '' && preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
//         return trim($m[1]);
//     }

//     // 2. Fallback: query string (only use for trusted GET endpoints)
//     if (!empty($_GET['token']) && is_string($_GET['token'])) {
//         return trim($_GET['token']);
//     }

//     return '';
// }



function extractBearerToken(): string
{
    // 1. Try all Apache/XAMPP header methods
    $header =
        $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';

    // Apache fallback
    if ($header === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();

        if (isset($headers['Authorization'])) {
            $header = $headers['Authorization'];
        } elseif (isset($headers['authorization'])) {
            $header = $headers['authorization'];
        }
    }

    // Extract Bearer token
    if ($header !== '' && preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        return trim($m[1]);
    }

    // Optional fallback query-string token
    if (!empty($_GET['token']) && is_string($_GET['token'])) {
        return trim($_GET['token']);
    }

    return '';
}


// ─────────────────────────────────────────────────────────────
/**
 * Checks whether a token hash exists in the blacklist table.
 *
 * @param  string $token  Raw JWT string.
 * @return bool           true  = token is blacklisted (revoked).
 */
function isTokenBlacklisted(string $token): bool
{
    global $pdo;

    try {
        $stmt = $pdo->prepare(
            'SELECT 1
             FROM   token_blacklist
             WHERE  token_hash = :hash
               AND  expires_at > NOW()
             LIMIT  1'
        );
        $stmt->execute([':hash' => hash('sha256', $token)]);
        return (bool)$stmt->fetchColumn();

    } catch (PDOException $e) {
        error_log('[verify_token.php] Blacklist check error: ' . $e->getMessage());
        // Fail open here — if the DB is down we still validate
        // the cryptographic signature in decodeJWT().
        return false;
    }
}

// ─────────────────────────────────────────────────────────────
/**
 * Decodes and validates the JWT from the current request.
 * Returns the payload on success, or null on any failure.
 *
 * Does NOT abort the request — use requireAuth() for that.
 *
 * @return array|null  Decoded payload, or null.
 */
function getTokenPayload(): ?array
{
    $token = extractBearerToken();

    if ($token === '') {
        return null;
    }

    // Cryptographic validation (signature + expiry)
    $payload = decodeJWT($token);

    if (!$payload) {
        return null;
    }

    // Blacklist check (server-side revocation)
    if (isTokenBlacklisted($token)) {
        return null;
    }

    // Ensure mandatory claims are present
    if (empty($payload['sub']) || empty($payload['exp'])) {
        return null;
    }

    return $payload;
}

// ─────────────────────────────────────────────────────────────
/**
 * Enforces authentication on a protected endpoint.
 *
 * If the token is missing, invalid, expired, or revoked:
 *   → sends HTTP 401 JSON response and exits.
 *
 * If valid:
 *   → returns the decoded payload array.
 *
 * @return array  Decoded JWT payload (guaranteed non-null).
 */
function requireAuth(): array
{
    $payload = getTokenPayload();

    if ($payload === null) {
        // Send 401 and halt execution
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
        }
        echo json_encode([
            'success' => false,
            'message' => 'Unauthorised. Please log in again.',
        ]);
        exit;
    }

    return $payload;
}

// ─────────────────────────────────────────────────────────────
/**
 * Returns the authenticated user's ID, or 0 if not authenticated.
 * Convenience wrapper used by endpoints that only need the ID.
 *
 * @return int
 */
function getAuthUserId(): int
{
    $payload = getTokenPayload();
    return $payload ? (int)$payload['sub'] : 0;
}

// ─────────────────────────────────────────────────────────────
/**
 * Returns a sanitised user-info array from the token payload.
 * Useful for endpoints that echo back the current user profile.
 *
 * @return array|null  ['id' => int, 'name' => string, 'email' => string]
 */
function getAuthUserInfo(): ?array
{
    $payload = getTokenPayload();
    if (!$payload) {
        return null;
    }

    return [
        'id'    => (int)$payload['sub'],
        'name'  => (string)($payload['name']  ?? ''),
        'email' => (string)($payload['email'] ?? ''),
    ];
}

// ─────────────────────────────────────────────────────────────
/**
 * Standalone endpoint mode:
 *
 * If this file is requested DIRECTLY (e.g. GET /auth/verify_token.php)
 * rather than included as middleware, respond with the token status.
 *
 * Useful for the frontend to check "am I still logged in?" on page load.
 *
 * GET /backend/auth/verify_token.php
 * Authorization: Bearer <token>
 *
 * → 200 { "success": true,  "valid": true,  "user": { id, name, email } }
 * → 401 { "success": false, "valid": false, "message": "..." }
 */
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {

    // This file was hit directly — act as a REST endpoint
    require_once __DIR__ . '/../config/cors.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed. Use GET.']);
        exit;
    }

    $payload = getTokenPayload();

    if (!$payload) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'valid'   => false,
            'message' => 'Token is missing, invalid, expired, or revoked.',
        ]);
        exit;
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'valid'   => true,
        'user'    => [
            'id'    => (int)$payload['sub'],
            'name'  => $payload['name']  ?? '',
            'email' => $payload['email'] ?? '',
        ],
        'expires_at' => $payload['exp'],
    ]);
    exit;
}