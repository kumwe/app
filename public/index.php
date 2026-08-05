<?php

declare(strict_types=1);

use Mezzio\Application;
use Kumwe\CMS\Kernel\ContainerFactory;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';

if (!is_file($autoload)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Kumwe dependencies are not installed.';
    return;
}

require $autoload;

/** @var Joomla\DI\Container $container */
$requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$recoverySurface = is_string($requestPath)
    && (in_array($requestPath, ['/health/live', '/health/ready'], true)
        || $requestPath === '/api/v1/extension-trust-keys'
        || str_starts_with($requestPath, '/api/v1/extension-trust-keys/'));
$container = $recoverySurface
    ? (new ContainerFactory())->createRecovery(Environment::fromGlobals())
    : require $root . '/bootstrap/container.php';
/** @var Application $application */
$application = $container->get(Application::class);
$application->run();
