<?php

/**
 * Router script for the PHP development server used by the browser test suite.
 *
 * `php -S` has no rewrite rules. This router serves an existing file under `public/` directly and
 * hands every other path to the front controller, which reproduces the production rewrite behaviour
 * closely enough for Playwright runs.
 *
 * @return bool False when the built-in server should serve a static file itself.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

$publicRoot = realpath(__DIR__ . '/../public');
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestPath = parse_url(is_string($requestUri) ? $requestUri : '/', PHP_URL_PATH);
$previewRedirect = $_SERVER['HTTP_X_KUMWE_BROWSER_PREVIEW_REDIRECT'] ?? null;

// The browser suite needs a real navigation redirect: WebKit deliberately refuses synthetic 3xx
// responses from Playwright route fulfilment. This seam exists only in the testing development
// router, accepts one fixed signal and has one fixed destination, so it cannot become an open redirect
// or enter the production front controller.
if (
    getenv('APP_ENV') === 'testing'
    && $requestPath === '/administrator/studio/preview'
    && $previewRedirect === 'different-path'
) {
    header('Location: /administrator/studio/wrong-preview', true, 302);

    return true;
}

if (is_string($publicRoot) && is_string($requestPath) && $requestPath !== '/') {
    $asset = realpath($publicRoot . '/' . ltrim($requestPath, '/'));
    if (is_string($asset) && str_starts_with($asset, $publicRoot . DIRECTORY_SEPARATOR) && is_file($asset)) {
        return false;
    }
}

require __DIR__ . '/../public/index.php';
