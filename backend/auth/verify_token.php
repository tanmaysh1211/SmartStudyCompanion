<?php
declare(strict_types=1);
if (!function_exists('decodeJWT')) {
    require_once __DIR__ . '/../config/jwt_helper.php';
}

if (!isset($pdo)) {
    require_once __DIR__ . '/../config/db.php';
}


function extractBearerToken(): string
{
    $header =
        $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';

    if ($header === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();

        if (isset($headers['Authorization'])) {
            $header = $headers['Authorization'];
        } elseif (isset($headers['authorization'])) {
            $header = $headers['authorization'];
        }
    }

    if ($header !== '' && preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        return trim($m[1]);
    }

    if (!empty($_GET['token']) && is_string($_GET['token'])) {
        return trim($_GET['token']);
    }

    return '';
}

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
        return false;
    }
}

function getTokenPayload(): ?array
{
    $token = extractBearerToken();

    if ($token === '') {
        return null;
    }

    $payload = decodeJWT($token);

    if (!$payload) {
        return null;
    }

    if (isTokenBlacklisted($token)) {
        return null;
    }

    if (empty($payload['sub']) || empty($payload['exp'])) {
        return null;
    }

    return $payload;
}

function requireAuth(): array
{
    $payload = getTokenPayload();

    if ($payload === null) {
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

function getAuthUserId(): int
{
    $payload = getTokenPayload();
    return $payload ? (int)$payload['sub'] : 0;
}

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

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
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
