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

$body = json_decode(file_get_contents('php://input'), true);

if (!$body || !is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body.']);
    exit;
}

$name     = trim((string)($body['name']     ?? ''));
$email    = trim((string)($body['email']    ?? ''));
$password =       (string)($body['password'] ?? '');

if ($name === '' || $email === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Name, email, and password are required.']);
    exit;
}

if (mb_strlen($name) < 2) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Name must be at least 2 characters.']);
    exit;
}

if (mb_strlen($name) > 100) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Name must not exceed 100 characters.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
    exit;
}

if (mb_strlen($email) > 254) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email address is too long.']);
    exit;
}

if (strlen($password) < 6) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters.']);
    exit;
}

if (strlen($password) > 72) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Password must not exceed 72 characters.']);
    exit;
}

try {
    $check = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $check->execute([':email' => $email]);

    if ($check->fetch()) {
        http_response_code(409); // Conflict
        echo json_encode(['success' => false, 'message' => 'An account with this email already exists.']);
        exit;
    }

} catch (PDOException $e) {
    error_log('[signup.php] DB check error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
    exit;
}

$passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

if ($passwordHash === false) {
    error_log('[signup.php] password_hash() returned false.');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error while securing your password.']);
    exit;
}

try {
    $insert = $pdo->prepare(
        'INSERT INTO users (name, email, password_hash, is_active, created_at)
         VALUES (:name, :email, :hash, 1, NOW())'
    );
    $insert->execute([
        ':name'  => $name,
        ':email' => $email,
        ':hash'  => $passwordHash,
    ]);

    $newUserId = (int)$pdo->lastInsertId();

} catch (PDOException $e) {
    error_log('[signup.php] Insert error: ' . $e->getMessage());

    if ($e->getCode() === '23000') {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'An account with this email already exists.']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
    }
    exit;
}

$payload = [
    'sub'   => $newUserId,
    'name'  => $name,
    'email' => $email,
    'iat'   => time(),
    'exp'   => time() + (int)(getenv('JWT_EXPIRY_SECONDS') ?: 86400),
];

$token = generateJWT($payload);

http_response_code(201); 
echo json_encode([
    'success' => true,
    'token'   => $token,
    'user'    => [
        'id'    => $newUserId,
        'name'  => $name,
        'email' => $email,
    ],
]);
