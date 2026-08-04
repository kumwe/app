<?php

declare(strict_types=1);

use Mezzio\Application;

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
$container = require $root . '/bootstrap/container.php';
/** @var Application $application */
$application = $container->get(Application::class);
$application->run();
