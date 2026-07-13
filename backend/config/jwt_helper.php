<?php
declare(strict_types=1);

if (function_exists('generateJWT')) {
    return;
}

function _b64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function _b64url_decode(string $data): string|false
{
    $b64 = strtr($data, '-_', '+/');

    $pad = strlen($b64) % 4;
    if ($pad !== 0) {
        $b64 .= str_repeat('=', 4 - $pad);
    }

    return base64_decode($b64, true); // strict=true rejects invalid chars
}

function _jwt_secret(): string
{
    $secret = (string)(getenv('JWT_SECRET') ?: '');

    if ($secret === '') {
        error_log(
            '[jwt_helper.php] CRITICAL: JWT_SECRET environment variable is not set. ' .
            'Using an insecure fallback — set JWT_SECRET in your .env file immediately.'
        );
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

function generateJWT(array $payload): string
{
    $now = time();

    if (!isset($payload['iat'])) {
        $payload['iat'] = $now;
    }

    if (!isset($payload['exp'])) {
        $expiry         = (int)(getenv('JWT_EXPIRY_SECONDS') ?: 86400);
        $payload['exp'] = $now + $expiry;
    }

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

    $signingInput = $encodedHeader . '.' . $encodedPayload;

    $rawSignature = hash_hmac(
        'sha256',
        $signingInput,
        _jwt_secret(),
        true  
    );

    $encodedSignature = _b64url_encode($rawSignature);

    return $signingInput . '.' . $encodedSignature;
}

function decodeJWT(string $token, int $leeway = 60): ?array
{
    $parts = explode('.', $token);

    if (count($parts) !== 3) {
        return null; 
    }

    [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

    $headerJson = _b64url_decode($encodedHeader);

    if ($headerJson === false) {
        return null;
    }

    $header = json_decode($headerJson, true);

    if (!is_array($header)) {
        return null;
    }

    if (($header['alg'] ?? '') !== 'HS256') {
        return null;
    }

    if (($header['typ'] ?? '') !== 'JWT') {
        return null;
    }

    $payloadJson = _b64url_decode($encodedPayload);

    if ($payloadJson === false) {
        return null;
    }

    $payload = json_decode($payloadJson, true);

    if (!is_array($payload)) {
        return null;
    }

    $signingInput    = $encodedHeader . '.' . $encodedPayload;
    $expectedRawSig  = hash_hmac('sha256', $signingInput, _jwt_secret(), true);
    $expectedEncoded = _b64url_encode($expectedRawSig);

    if (!hash_equals($expectedEncoded, $encodedSignature)) {
        return null; 
    }

    $now = time();

    if (isset($payload['exp'])) {
        $exp = (int)$payload['exp'];
        if (($now - $leeway) > $exp) {
            return null; // token has expired
        }
    }

    if (isset($payload['nbf'])) {
        $nbf = (int)$payload['nbf'];
        if ($now < ($nbf - $leeway)) {
            return null; 
        }
    }

    if (isset($payload['iat'])) {
        $iat = (int)$payload['iat'];
        if ($iat > ($now + 300)) {
            return null; 
        }
    }

    if (empty($payload['sub'])) {
        return null; 
    }
    return $payload; 
}

function getUserIdFromToken(string $token): int
{
    $payload = decodeJWT($token);
    return $payload !== null ? (int)($payload['sub'] ?? 0) : 0;
}

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
