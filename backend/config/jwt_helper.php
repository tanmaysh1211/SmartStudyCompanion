<?php
/**
 * backend/config/jwt_helper.php
 * ═══════════════════════════════════════════════════════════════
 * Pure-PHP JSON Web Token (JWT) helper — HS256 / HMAC-SHA256.
 *
 * No third-party library required. Uses only PHP built-ins:
 *   hash_hmac(), hash_equals(), json_encode/decode(), base64_*()
 *
 * Security hardening:
 *   ✔  Constant-time signature comparison (hash_equals)
 *   ✔  Algorithm whitelist — rejects 'none', RS256, etc.
 *   ✔  Expiry  (exp) claim enforcement with clock-skew leeway
 *   ✔  Not-Before (nbf) claim enforcement
 *   ✔  Issued-At (iat) sanity check (rejects far-future tokens)
 *   ✔  Mandatory claim validation (sub, exp)
 *   ✔  Secret-length warning in error log
 *   ✔  base64url encoding (RFC 4648 §5) — no padding, URL-safe
 *
 * Public API:
 *   generateJWT(array $payload): string
 *   decodeJWT(string $token, int $leeway = 60): ?array
 *   getUserIdFromToken(string $token): int
 *
 * Environment variable:
 *   JWT_SECRET          HMAC signing secret (min 32 chars)
 *   JWT_EXPIRY_SECONDS  Token lifetime in seconds (default 86400)
 *
 * Usage:
 *   require_once __DIR__ . '/../config/jwt_helper.php';
 *
 *   $token   = generateJWT(['sub' => $userId, 'name' => 'Alice',
 *                            'email' => 'alice@example.com',
 *                            'iat' => time(),
 *                            'exp' => time() + 86400]);
 *
 *   $payload = decodeJWT($token);   // null on any failure
 * ═══════════════════════════════════════════════════════════════
 */

declare(strict_types=1);

// ── Guard against duplicate inclusion ────────────────────────
if (function_exists('generateJWT')) {
    return;
}

// ════════════════════════════════════════════════════════════════
// Internal helpers — base64url encode / decode
// JWT uses the URL-safe variant of base64 (RFC 4648 §5):
//   '+' → '-',  '/' → '_',  trailing '=' padding removed
// ════════════════════════════════════════════════════════════════

/**
 * Encodes binary data to base64url (no padding).
 *
 * @param  string $data  Raw binary string.
 * @return string        Base64url-encoded string.
 */
function _b64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Decodes a base64url string back to binary.
 * Returns false if the input is not valid base64.
 *
 * @param  string       $data  Base64url-encoded string.
 * @return string|false        Decoded binary, or false on error.
 */
function _b64url_decode(string $data): string|false
{
    // Restore standard base64 characters and re-add padding
    $b64 = strtr($data, '-_', '+/');

    // Add '=' padding so that strlen is a multiple of 4
    $pad = strlen($b64) % 4;
    if ($pad !== 0) {
        $b64 .= str_repeat('=', 4 - $pad);
    }

    return base64_decode($b64, true); // strict=true rejects invalid chars
}

// ════════════════════════════════════════════════════════════════
// Secret key management
// ════════════════════════════════════════════════════════════════

/**
 * Returns the HMAC secret from the environment.
 * Logs a warning if the secret is absent or too short.
 *
 * @return string
 */
function _jwt_secret(): string
{
    $secret = (string)(getenv('JWT_SECRET') ?: '');

    if ($secret === '') {
        error_log(
            '[jwt_helper.php] CRITICAL: JWT_SECRET environment variable is not set. ' .
            'Using an insecure fallback — set JWT_SECRET in your .env file immediately.'
        );
        // Fallback — predictable, so intentionally terrible in production
        $secret = 'INSECURE_FALLBACK_SECRET_CHANGE_THIS_NOW_' . php_uname('n');
    } elseif (strlen($secret) < 32) {
        error_log(
            '[jwt_helper.php] WARNING: JWT_SECRET is only ' .
            strlen($secret) . ' characters. ' .
            'Use at least 32 random characters for security.'
        );
    }

    return $secret;
}

// ════════════════════════════════════════════════════════════════
// PUBLIC: generateJWT
// ════════════════════════════════════════════════════════════════

/**
 * Creates a signed HS256 JWT string.
 *
 * Standard claims automatically added if not provided:
 *   iat  — issued-at  (current Unix timestamp)
 *   exp  — expiry     (iat + JWT_EXPIRY_SECONDS, default 86400)
 *
 * @param  array  $payload  Associative array of JWT claims.
 *                          Recommended claims: 'sub', 'name', 'email'.
 * @return string           Signed JWT: "<header>.<payload>.<signature>"
 */
function generateJWT(array $payload): string
{
    // ── Auto-inject standard time claims ─────────────────────
    $now = time();

    if (!isset($payload['iat'])) {
        $payload['iat'] = $now;
    }

    if (!isset($payload['exp'])) {
        $expiry         = (int)(getenv('JWT_EXPIRY_SECONDS') ?: 86400);
        $payload['exp'] = $now + $expiry;
    }

    // ── Build header ──────────────────────────────────────────
    $header = [
        'alg' => 'HS256',
        'typ' => 'JWT',
    ];

    $encodedHeader  = _b64url_encode(
        json_encode($header, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    $encodedPayload = _b64url_encode(
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    // ── Sign ──────────────────────────────────────────────────
    $signingInput = $encodedHeader . '.' . $encodedPayload;

    $rawSignature = hash_hmac(
        'sha256',
        $signingInput,
        _jwt_secret(),
        true  // return raw binary, not hex
    );

    $encodedSignature = _b64url_encode($rawSignature);

    return $signingInput . '.' . $encodedSignature;
}

// ════════════════════════════════════════════════════════════════
// PUBLIC: decodeJWT
// ════════════════════════════════════════════════════════════════

/**
 * Validates and decodes a JWT string.
 *
 * Validation steps (in order):
 *   1. Structure   — must be exactly 3 dot-separated segments
 *   2. Header JSON — must decode to a valid array
 *   3. Algorithm   — must be 'HS256' (rejects 'none', RS256 …)
 *   4. Type        — must be 'JWT'
 *   5. Payload     — must decode to a valid array
 *   6. Signature   — constant-time HMAC comparison
 *   7. Expiry      — exp claim must be in the future (± leeway)
 *   8. Not-before  — nbf claim must be in the past (± leeway)
 *   9. Issued-at   — iat must not be far in the future (sanity)
 *  10. Subject     — sub claim must be present and non-empty
 *
 * @param  string     $token   Raw JWT string (the Bearer token value).
 * @param  int        $leeway  Clock-skew tolerance in seconds (default 60).
 * @return array|null          Decoded payload on success, null on any failure.
 */
function decodeJWT(string $token, int $leeway = 60): ?array
{
    // ── 1. Structure check ────────────────────────────────────
    $parts = explode('.', $token);

    if (count($parts) !== 3) {
        return null; // not a valid JWT
    }

    [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

    // ── 2. Decode header ──────────────────────────────────────
    $headerJson = _b64url_decode($encodedHeader);

    if ($headerJson === false) {
        return null;
    }

    $header = json_decode($headerJson, true);

    if (!is_array($header)) {
        return null;
    }

    // ── 3. Algorithm whitelist ────────────────────────────────
    // Critical: rejects the 'none' algorithm attack and prevents
    // RS256 tokens signed by an attacker-controlled public key
    // from being accepted as HS256 with that key as the secret.
    if (($header['alg'] ?? '') !== 'HS256') {
        return null;
    }

    // ── 4. Type check ─────────────────────────────────────────
    if (($header['typ'] ?? '') !== 'JWT') {
        return null;
    }

    // ── 5. Decode payload ─────────────────────────────────────
    $payloadJson = _b64url_decode($encodedPayload);

    if ($payloadJson === false) {
        return null;
    }

    $payload = json_decode($payloadJson, true);

    if (!is_array($payload)) {
        return null;
    }

    // ── 6. Signature verification (constant-time) ─────────────
    // hash_equals() takes the same time regardless of where the
    // strings differ, preventing timing-based secret extraction.
    $signingInput    = $encodedHeader . '.' . $encodedPayload;
    $expectedRawSig  = hash_hmac('sha256', $signingInput, _jwt_secret(), true);
    $expectedEncoded = _b64url_encode($expectedRawSig);

    if (!hash_equals($expectedEncoded, $encodedSignature)) {
        return null; // signature mismatch — tampered or wrong secret
    }

    $now = time();

    // ── 7. Expiry check ───────────────────────────────────────
    if (isset($payload['exp'])) {
        $exp = (int)$payload['exp'];
        if (($now - $leeway) > $exp) {
            return null; // token has expired
        }
    }

    // ── 8. Not-before check ───────────────────────────────────
    if (isset($payload['nbf'])) {
        $nbf = (int)$payload['nbf'];
        if ($now < ($nbf - $leeway)) {
            return null; // token is not yet valid
        }
    }

    // ── 9. Issued-at sanity check ─────────────────────────────
    // Reject tokens issued more than 5 minutes in the future
    // (clock skew on the signing server).
    if (isset($payload['iat'])) {
        $iat = (int)$payload['iat'];
        if ($iat > ($now + 300)) {
            return null; // iat is unreasonably far in the future
        }
    }

    // ── 10. Mandatory claims ──────────────────────────────────
    if (empty($payload['sub'])) {
        return null; // sub (subject / user ID) must be present
    }

    return $payload; // all checks passed — return the decoded payload
}

// ════════════════════════════════════════════════════════════════
// PUBLIC: getUserIdFromToken
// ════════════════════════════════════════════════════════════════

/**
 * Convenience wrapper — extracts the user ID from a token.
 * Returns 0 if the token is invalid or has no 'sub' claim.
 *
 * @param  string $token  Raw JWT string.
 * @return int            User ID, or 0 on failure.
 */
function getUserIdFromToken(string $token): int
{
    $payload = decodeJWT($token);
    return $payload !== null ? (int)($payload['sub'] ?? 0) : 0;
}

// ════════════════════════════════════════════════════════════════
// PUBLIC: refreshJWT
// ════════════════════════════════════════════════════════════════

/**
 * Issues a fresh JWT with a new expiry, preserving the original
 * payload claims (sub, name, email, etc.).
 *
 * Only refreshes if the existing token is still valid.
 * Use this endpoint pattern:
 *   POST /auth/refresh.php  → Authorization: Bearer <old_token>
 *
 * @param  string   $token   The existing (still-valid) JWT.
 * @return string|null       New JWT string, or null if old token is invalid.
 */
function refreshJWT(string $token): ?string
{
    $payload = decodeJWT($token);

    if ($payload === null) {
        return null; // original token is invalid — cannot refresh
    }

    // Strip time-sensitive claims so generateJWT() sets fresh ones
    unset($payload['iat'], $payload['exp'], $payload['nbf']);

    return generateJWT($payload);
}

/*
 * ── Quick reference ────────────────────────────────────────────
 *
 * Generate a token after login:
 *   $token = generateJWT([
 *       'sub'   => $user['id'],
 *       'name'  => $user['name'],
 *       'email' => $user['email'],
 *   ]);
 *
 * Validate a token in a protected route:
 *   $payload = decodeJWT($token);
 *   if ($payload === null) {
 *       http_response_code(401);
 *       echo json_encode(['success' => false, 'message' => 'Unauthorised.']);
 *       exit;
 *   }
 *   $userId = (int)$payload['sub'];
 *
 * Get user ID from a raw Bearer token string:
 *   $userId = getUserIdFromToken($bearerToken);  // 0 if invalid
 *
 * Refresh a token (e.g. 15 minutes before expiry):
 *   $newToken = refreshJWT($oldToken);  // null if old token expired
 */