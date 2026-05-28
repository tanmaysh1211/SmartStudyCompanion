<?php
// backend/config/env.php
// Must be included FIRST in every PHP file that needs env vars





// backend/config/env.php

$envPath = __DIR__ . '/../../.env';

if (!file_exists($envPath)) {
    die('.env file not found');
}

$lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

foreach ($lines as $line) {

    if (trim($line) === '' || str_starts_with(trim($line), '#')) {
        continue;
    }

    if (!str_contains($line, '=')) {
        continue;
    }

    list($key, $value) = explode('=', $line, 2);

    $key = trim($key);
    $value = trim($value);

    putenv("$key=$value");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

