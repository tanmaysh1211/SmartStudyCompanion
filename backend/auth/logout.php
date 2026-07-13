<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/cors.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/jwt_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$authHeader = $_SERVER['HTTP_AUTHORIZATION']
           ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
           ?? '';

$token = '';
if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
    $token = trim($matches[1]);
}

if ($token === '') {
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Already logged out.']);
    exit;
}

$payload = decodeJWT($token);

if (!$payload) {
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Token already invalid or expired.']);
    exit;
}

$tokenExp = isset($payload['exp']) ? (int)$payload['exp'] : (time() + 86400);
$userId   = isset($payload['sub']) ? (int)$payload['sub'] : 0;

try {
    $pdo->exec('DELETE FROM token_blacklist WHERE expires_at < NOW()');

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
}

http_response_code(200);
echo json_encode([
    'success' => true,
    'message' => 'Logged out successfully.',
]);
