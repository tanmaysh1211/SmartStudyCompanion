<?php
/**
 * backend/config/cors.php
 * ═══════════════════════════════════════════════════════════════
 * Sets CORS headers, the JSON Content-Type, and handles the
 * browser's OPTIONS pre-flight request.
 *
 * Must be included at the very TOP of every PHP endpoint —
 * before any output and before require_once db.php — so that
 * headers are always sent even if db.php exits early.
 *
 * Features:
 *   • Configurable allowed origin via ALLOWED_ORIGIN env var
 *   • Supports credentials (cookies / Authorization headers)
 *   • Handles OPTIONS pre-flight automatically (returns 204)
 *   • Lists every HTTP method and header the app actually uses
 *   • 24-hour preflight cache (Access-Control-Max-Age)
 *   • Security note block explaining why wildcard '*' is
 *     replaced by a specific origin in production
 *
 * Usage:
 *   require_once __DIR__ . '/../config/cors.php';
 *   // headers are now set; PHP continues normally
 *
 * Environment variable:
 *   ALLOWED_ORIGIN   e.g. http://localhost or https://yourdomain.com
 *                    Defaults to '*' (open) — change in production!
 * ═══════════════════════════════════════════════════════════════
 */

// declare(strict_types=1);

// ── Guard: only set headers once per request ──────────────────
if (defined('CORS_HEADERS_SENT')) {
    return;
}
define('CORS_HEADERS_SENT', true);

// ════════════════════════════════════════════════════════════════
// 1.  Resolve allowed origin
//
//     Security note:
//       Using '*' allows ANY website to call your API.
//       This is acceptable in development but dangerous in
//       production — a malicious site could call your API using
//       a logged-in user's session cookies.
//
//       In production set ALLOWED_ORIGIN to your exact frontend
//       domain, e.g. https://studycompanion.example.com
//       That way only your frontend can make credentialed calls.
// ════════════════════════════════════════════════════════════════
$requestOrigin  = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigin  = (string)(getenv('ALLOWED_ORIGIN') ?: '*');

// If the env var is '*' we echo back whatever origin the browser
// sent (required when Access-Control-Allow-Credentials: true).
// If the env var is a specific domain, only echo it for matching
// origins; otherwise send no ACAO header (browser will block it).
if ($allowedOrigin === '*') {
    // Development mode — reflect the request origin so credentials work
    $acao = ($requestOrigin !== '') ? $requestOrigin : '*';
} else {
    // Production mode — strict origin matching
    $acao = ($requestOrigin === $allowedOrigin) ? $allowedOrigin : '';
}

// ════════════════════════════════════════════════════════════════
// 2.  Set response headers
// ════════════════════════════════════════════════════════════════
// header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
// Always set Content-Type first
header('Content-Type: application/json; charset=utf-8');

// Only emit CORS headers if we have a valid allowed origin
if ($acao !== '') {
    // Which origins may access this resource
    header('Access-Control-Allow-Origin: ' . $acao);

    // Allow the browser to include cookies and Authorization headers
    header('Access-Control-Allow-Credentials: true');

    // Which HTTP methods the API accepts
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

    // Which request headers the browser may send
    header(
        'Access-Control-Allow-Headers: ' .
        'Content-Type, Authorization, X-Requested-With, Accept, Origin'
    );

    // Which response headers the browser JS may read
    header('Access-Control-Expose-Headers: X-Total-Count, X-Page');

    // Cache the pre-flight result for 24 hours (86400 seconds)
    // so the browser does not send an OPTIONS request on every API call
    header('Access-Control-Max-Age: 86400');

    // Vary: Origin tells CDNs/proxies to cache separately per origin
    header('Vary: Origin');
}

// ════════════════════════════════════════════════════════════════
// 3.  Handle OPTIONS pre-flight request
//
//     Before making a "complex" cross-origin request (POST with
//     JSON body, or any request with Authorization header), the
//     browser sends an OPTIONS request to check permissions.
//     We reply immediately with 204 No Content — no DB hit needed.
// ════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); // No Content
    exit;
}

// ════════════════════════════════════════════════════════════════
// 4.  Security headers (bonus — applies to all API responses)
// ════════════════════════════════════════════════════════════════

// Prevent the response from being rendered in an iframe
header('X-Frame-Options: DENY');

// Stop browsers from MIME-sniffing the response type
header('X-Content-Type-Options: nosniff');

// Remove PHP version from responses (also set expose_php=Off in php.ini)
header_remove('X-Powered-By');

/*
 * After this file is included, the script continues normally.
 * All subsequent echo / json_encode output will be sent with
 * the correct CORS and Content-Type headers.
 *
 * Example endpoint structure:
 *
 *   <?php
 *   require_once __DIR__ . '/../config/cors.php';    // ← always first
 *   require_once __DIR__ . '/../config/db.php';
 *   require_once __DIR__ . '/../config/jwt_helper.php';
 *   require_once __DIR__ . '/../auth/verify_token.php';
 *
 *   $authUser = requireAuth();
 *   // ... endpoint logic ...
 *   echo json_encode(['success' => true, 'data' => $result]);
 */