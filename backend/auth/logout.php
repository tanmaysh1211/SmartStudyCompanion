<?php
/**
 * backend/auth/logout.php
 * ─────────────────────────────────────────────────────────────
 * POST /backend/auth/logout.php
 * Authorization: Bearer <token>
 *
 * Blacklists the current JWT so it cannot be reused, even
 * before it expires naturally.
 *
 * Returns JSON:
 *   Success → { "success": true,  "message": "Logged out successfully." }
 *   Failure → { "success": false, "message": "..." }
 *
 * Note: Because JWTs are stateless, client-side logout
 * (deleting the token from localStorage) is already sufficient
 * for most use-cases. This endpoint adds a server-side
 * blacklist for higher-security deployments.
 * ─────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/jwt_helper.php';

// ── Only allow POST ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// ── Extract Bearer token from Authorization header ────────────
$authHeader = $_SERVER['HTTP_AUTHORIZATION']
           ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
           ?? '';

$token = '';
if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
    $token = trim($matches[1]);
}

// ── If no token, the client is already logged out ─────────────
if ($token === '') {
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Already logged out.']);
    exit;
}

// ── Decode token to get expiry (so we can auto-prune later) ──
$payload = decodeJWT($token);

// If token is already invalid/expired, treat as already logged out
if (!$payload) {
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Token already invalid or expired.']);
    exit;
}

$tokenExp = isset($payload['exp']) ? (int)$payload['exp'] : (time() + 86400);
$userId   = isset($payload['sub']) ? (int)$payload['sub'] : 0;

// ── Store token in blacklist table ─────────────────────────────
// The blacklist row is only needed until the token's natural
// expiry, after which it cannot be used anyway.
// A scheduled cron job (or the cleanup below) prunes old rows.
try {
    // ── Opportunistic cleanup: delete expired blacklist rows ───
    $pdo->exec('DELETE FROM token_blacklist WHERE expires_at < NOW()');

    // ── Insert current token ───────────────────────────────────
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO token_blacklist (token_hash, user_id, expires_at, revoked_at)
         VALUES (:hash, :uid, FROM_UNIXTIME(:exp), NOW())'
    );
    $stmt->execute([
        ':hash' => hash('sha256', $token), // store hash, not raw token
        ':uid'  => $userId,
        ':exp'  => $tokenExp,
    ]);

} catch (PDOException $e) {
    error_log('[logout.php] Blacklist insert error: ' . $e->getMessage());
    // Non-fatal: the client will still clear its local storage.
    // Log the error but return success so the UX is seamless.
}

// ── Respond ───────────────────────────────────────────────────
http_response_code(200);
echo json_encode([
    'success' => true,
    'message' => 'Logged out successfully.',
]);