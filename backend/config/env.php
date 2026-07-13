<?php

$envPath = __DIR__ . '/../../.env';

if (!file_exists($envPath)) {
    return;
}

$lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

foreach ($lines as $line) {

    $line = trim($line);

    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }

    if (!str_contains($line, '=')) {
        continue;
    }

    [$key, $value] = explode('=', $line, 2);

    $key = trim($key);
    $value = trim($value);

    if (getenv($key) !== false) {
        continue;
    }

    putenv("$key=$value");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
