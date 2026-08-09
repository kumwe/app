<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

use InvalidArgumentException;
use Kumwe\CMS\Extension\Application\Trust\TrustStore;
use Kumwe\CMS\Extension\Domain\ExtensionIdentifier;
use Mezzio\Application;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Extension route registrar that declares an extension's routes on the host Mezzio application.
 *
 * Routes are declared once while the application is being composed and are never withdrawn, which
 * makes this the place where two guarantees have to be established. First, containment: a route must
 * live under `/extensions/<vendor>/<name>` and be named `extension.<vendor>.<name>.…`, so one
 * extension can neither shadow a core path nor claim another extension's route name. Second,
 * revocability: the handler is wrapped in `TrustEnforcingRequestHandler` before it reaches the router,
 * so the route starts refusing requests the moment the extension is disabled or its trust is revoked,
 * without the router being rebuilt.
 *
 * @since  2.0.0
 */
final readonly class MezzioExtensionRouteRegistrar implements ExtensionRouteRegistrar
{
    /**
     * Canonical identifier of the extension every route declared here is confined to.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $extension;

    /**
     * Bind the registrar to one extension's slice of the application router.
     *
     * @param   Application  $application  Mezzio application the routes are declared on.
     * @param   string       $extension    Owning extension identifier, normalised to lowercase
     *          `vendor/name` before the namespace prefixes are derived.
     * @param   TrustStore   $trust        Trust store each declared route consults per request.
     *
     * @throws  InvalidArgumentException  When the identifier is not lowercase `vendor/name`.
     *
     * @since   2.0.0
     */
    public function __construct(
        private Application $application,
        string $extension,
        private TrustStore $trust,
    ) {
        $this->extension = ExtensionIdentifier::fromString($extension)->value();
    }

    /**
     * Declare one route for the owning extension, wrapped in a per-request trust check.
     *
     * @param   string                   $path     Request path; must begin with `/extensions/<vendor>/<name>/`.
     * @param   RequestHandlerInterface  $handler  Handler run once the extension's trust is confirmed.
     * @param   non-empty-list<string>   $methods  Upper-case HTTP methods the route answers.
     * @param   string                   $name     Route name; must begin with `extension.<vendor>.<name>.`.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the path or name escapes the extension's namespace, or the
     *          method list is empty, not a list, or holds a value that is not an
     *          upper-case HTTP method.
     *
     * @since   2.0.0
     */
    public function route(
        string $path,
        RequestHandlerInterface $handler,
        array $methods,
        string $name,
    ): void {
        $pathPrefix = '/extensions/' . $this->extension;
        $namePrefix = 'extension.' . str_replace('/', '.', $this->extension) . '.';
        if (!str_starts_with($path, $pathPrefix . '/') || !str_starts_with($name, $namePrefix)) {
            throw new InvalidArgumentException('Extension routes must remain inside their path and name namespace.');
        }
        if ($methods === [] || !array_is_list($methods)) {
            throw new InvalidArgumentException('Extension route methods must be a non-empty list.');
        }
        foreach ($methods as $method) {
            if (!is_string($method) || preg_match('/^[A-Z]+$/D', $method) !== 1) {
                throw new InvalidArgumentException('Extension route methods must be uppercase HTTP methods.');
            }
        }

        $this->application->route(
            $path,
            new TrustEnforcingRequestHandler($handler, $this->trust, $this->extension),
            $methods,
            $name,
        );
    }
}
