<?php

/*
 * Class loading for the drills that run inside the production image.
 *
 * The image installs with `--no-dev` and dumps an authoritative classmap, so Composer resolves
 * `Kumwe\App\` and nothing else: no class under `Kumwe\App\Tests\` is loadable there, even though
 * the deployment acceptance bind-mounts `tests/Support` into the container. The entry points used
 * to work around that by naming each collaborator in a `require` list, which held only until a
 * drill grew one — a harness that gained a class and not a matching line died with "class not
 * found" after the deployment was already up, in the one environment no cheaper job covers.
 *
 * Registering the mapping Composer's `autoload-dev` section would have registered retires the
 * list, so a drill can acquire a collaborator without acquiring a way to fail.
 */

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Kumwe\\App\\Tests\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = dirname(__DIR__) . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
