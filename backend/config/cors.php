<?php
if (defined('CORS_HEADERS_SENT')) {
    return;
}
define('CORS_HEADERS_SENT', true);
$requestOrigin  = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigin  = (string)(getenv('ALLOWED_ORIGIN') ?: '*');

if ($allowedOrigin === '*') {
    $acao = ($requestOrigin !== '') ? $requestOrigin : '*';
} else {
    $acao = ($requestOrigin === $allowedOrigin) ? $allowedOrigin : '';
}

header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header('Content-Type: application/json; charset=utf-8');

if ($acao !== '') {
    header('Access-Control-Allow-Origin: ' . $acao);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header(
        'Access-Control-Allow-Headers: ' .
        'Content-Type, Authorization, X-Requested-With, Accept, Origin'
    );

    header('Access-Control-Expose-Headers: X-Total-Count, X-Page');

    header('Access-Control-Max-Age: 86400');

    header('Vary: Origin');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); // No Content
    exit;
}

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header_remove('X-Powered-By');
