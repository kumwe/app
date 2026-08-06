<?php

declare(strict_types=1);

$publicRoot = realpath(__DIR__ . '/../public');
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestPath = parse_url(is_string($requestUri) ? $requestUri : '/', PHP_URL_PATH);

if (is_string($publicRoot) && is_string($requestPath) && $requestPath !== '/') {
    $asset = realpath($publicRoot . '/' . ltrim($requestPath, '/'));
    if (is_string($asset) && str_starts_with($asset, $publicRoot . DIRECTORY_SEPARATOR) && is_file($asset)) {
        return false;
    }
}

require __DIR__ . '/../public/index.php';
