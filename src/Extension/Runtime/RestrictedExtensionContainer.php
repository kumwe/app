<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Runtime;

use Kumwe\Extension\Spi\Runtime\ExtensionContainer;
use InvalidArgumentException;
use Kumwe\Extension\Manifest\ExtensionIdentifier;
use RuntimeException;

/**
 * Curated service surface for trusted in-process extension code.
 *
 * This is an API compatibility boundary, not a security sandbox, and the distinction is load-bearing
 * rather than a caveat. What it bounds is which host *services* an extension may resolve:
 * `ExtensionRuntimeLoader` builds one of these per active extension and hands it to the provider's
 * `register()` call, so an extension reaches only the host services the loader chose to pass in — the
 * application container is never visible to it. Anything the extension shares itself has to sit under
 * its own `extension.<vendor>.<name>.` prefix, which keeps two extensions from colliding on an
 * identifier and keeps either of them from replacing a host service by re-registering its name.
 *
 * What it does not bound is what admitted PHP can do once it is running. That code executes inside the
 * request, worker and scheduler processes with the ambient filesystem, network, environment, database
 * and process authority of the runtime user, none of which passes through here. Untrusted and
 * marketplace PHP is therefore unsupported until an isolated runtime exists, and belongs out of process
 * behind an authenticated adapter contract. `docs/architecture/extensions.md` inventories that ambient
 * authority beside the deployment control that bounds each part of it.
 *
 * @since  2.0.0
 */
final class RestrictedExtensionContainer implements ExtensionContainer
{
    /**
     * Host services the loader allowlisted for this extension, keyed by service identifier.
     *
     * @var    array<string, object>
     * @since  2.0.0
     */
    private array $services;

    /**
     * Factories the extension registered for its own namespaced services, keyed by identifier.
     *
     * @var    array<string, callable(ExtensionContainer): object>
     * @since  2.0.0
     */
    private array $factories = [];

    /**
     * Services already built from a factory, memoised so each shared identifier is constructed once.
     *
     * @var    array<string, object>
     * @since  2.0.0
     */
    private array $instances = [];

    /**
     * Canonical identifier of the extension this container serves.
     *
     * @var    string
     * @since  2.0.0
     */
    private readonly string $extension;

    /**
     * Build the container for one extension from the host services it is allowed to see.
     *
     * @param   string                 $extension        Owning extension identifier, normalised to
     *          lowercase `vendor/name`.
     * @param   array<string, object>  $allowedServices  Host services this extension may resolve, keyed
     *          by the identifier it resolves them under.
     *
     * @throws  InvalidArgumentException  When the identifier is not lowercase `vendor/name`.
     *
     * @since   2.0.0
     */
    public function __construct(string $extension, array $allowedServices)
    {
        $this->extension = ExtensionIdentifier::fromString($extension)->value();
        $this->services = $allowedServices;
    }

    /**
     * Resolve a service this extension is allowed to see.
     *
     * A memoised instance is preferred, then an allowlisted host service, then the extension's own
     * factory — so registering a factory can never shadow a host service, and a factory that has
     * already run is not run again.
     *
     * @param   string  $id  Identifier of an allowlisted host service or of one this extension shared.
     *
     * @return  object  The resolved service.
     *
     * @throws  RuntimeException  When the identifier is neither allowlisted nor registered here, which
     *          is how an extension reaching for a service it was not granted fails.
     *
     * @since   2.0.0
     */
    public function get(string $id): object
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        if (isset($this->services[$id])) {
            return $this->services[$id];
        }
        if (!isset($this->factories[$id])) {
            throw new RuntimeException(sprintf('Extension service %s is not allowlisted.', $id));
        }

        return $this->instances[$id] = ($this->factories[$id])($this);
    }

    /**
     * Register a lazily built service of this extension's own under its namespaced identifier.
     *
     * @param   string                                $id       Service identifier; must start with
     *          `extension.<vendor>.<name>.` and be unused.
     * @param   callable(ExtensionContainer): object  $factory  Built on first resolution and then memoised;
     *          receives this container.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the identifier leaves the extension's namespace, or an
     *          allowlisted host service or earlier factory already holds it.
     *
     * @since   2.0.0
     */
    public function share(string $id, callable $factory): void
    {
        $prefix = 'extension.' . str_replace('/', '.', $this->extension) . '.';
        if (!str_starts_with($id, $prefix) || isset($this->services[$id]) || isset($this->factories[$id])) {
            throw new InvalidArgumentException('Extension-local services require a unique namespaced identifier.');
        }
        $this->factories[$id] = $factory;
    }
}
