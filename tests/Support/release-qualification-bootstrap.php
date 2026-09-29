<?php

/**
 * Keep qualification's test framework separate from the installed artifact's authoritative App loader.
 *
 * Used both by PHPUnit and child PHP processes through auto_prepend_file. No App source is mounted from
 * the checkout; only the test harness is supplied to the already-built distribution.
 *
 * @since  2.0.0-beta.1
 */

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Kumwe\App\Kernel\ContainerFactory;

$qualificationRoot = dirname(__DIR__, 2);
$qualificationLoader = require $qualificationRoot . '/vendor/autoload.php';
if (!$qualificationLoader instanceof ClassLoader || !$qualificationLoader->isClassMapAuthoritative()) {
    throw new RuntimeException('Qualification requires the artifact production authoritative classmap.');
}
$qualificationLoader->unregister();
$qualificationLoader->register(true);
require_once __DIR__ . '/deployment-drill-autoload.php';
$qualificationClass = new ReflectionClass(ContainerFactory::class);
if ($qualificationClass->getFileName() !== $qualificationRoot . '/src/Kernel/ContainerFactory.php') {
    throw new RuntimeException('Qualification attempted to load App from outside the installed artifact.');
}
