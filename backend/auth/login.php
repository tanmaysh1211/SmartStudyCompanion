<?php
/**
 * backend/auth/login.php
 * ─────────────────────────────────────────────────────────────
 * POST /backend/auth/login.php
 *
 * Accepts JSON body:
 *   { "email": "...", "password": "..." }
 *
 * Returns JSON:
 *   Success → { "success": true,  "token": "...", "user": { id, name, email } }
 *   Failure → { "success": false, "message": "..." }
 * ─────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

// ── Load shared helpers ───────────────────────────────────────
require_once __DIR__ . '/../config/cors.php';       // Sets CORS headers
require_once __DIR__ . '/../config/db.php';         // $pdo — PDO instance
require_once __DIR__ . '/../config/jwt_helper.php'; // generateJWT(), decodeJWT()

// ── Only allow POST ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// ── Parse JSON body ───────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);

if (!$body || !is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body.']);
    exit;
}

// ── Extract & sanitise inputs ─────────────────────────────────
$email    = trim((string)($body['email']    ?? ''));
$password =       (string)($body['password'] ?? '');

// ── Validate required fields ──────────────────────────────────
if ($email === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email and password are required.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
    exit;
}

// ── Look up user in database ──────────────────────────────────
try {
    $stmt = $pdo->prepare(
        'SELECT id, name, email, password_hash, is_active
         FROM   users
         WHERE  email = :email
         LIMIT  1'
    );
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('[login.php] DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
    exit;
}

// ── Verify user exists ────────────────────────────────────────
if (!$user) {
    // Generic message — never reveal whether the email exists
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
    exit;
}

// ── Check account is active ───────────────────────────────────
if (!(bool)$user['is_active']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Your account has been deactivated. Please contact support.']);
    exit;
}

// ── Verify password hash ──────────────────────────────────────
if (!password_verify($password, $user['password_hash'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid email or password.']);
    exit;
}

// ── Rehash if algorithm/cost has changed ──────────────────────
if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
    try {
        $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        $upd = $pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id');
        $upd->execute([':h' => $newHash, ':id' => $user['id']]);
    } catch (PDOException $e) {
        error_log('[login.php] Rehash failed: ' . $e->getMessage());
        // Non-fatal — continue
    }
}

// ── Update last_login timestamp ───────────────────────────────
try {
    $upd = $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = :id');
    $upd->execute([':id' => $user['id']]);
} catch (PDOException $e) {
    error_log('[login.php] last_login update failed: ' . $e->getMessage());
    // Non-fatal
}

// ── Generate JWT ──────────────────────────────────────────────
$payload = [
    'sub'   => $user['id'],
    'name'  => $user['name'],
    'email' => $user['email'],
    'iat'   => time(),
    'exp'   => time() + (int)(getenv('JWT_EXPIRY_SECONDS') ?: 86400), // default 24 h
];

$token = generateJWT($payload);

// ── Send response ─────────────────────────────────────────────
http_response_code(200);
echo json_encode([
    'success' => true,
    'token'   => $token,
    'user'    => [
        'id'    => (int)$user['id'],
        'name'  => $user['name'],
        'email' => $user['email'],
    ],
]);