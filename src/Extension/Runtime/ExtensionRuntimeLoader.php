<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

use InvalidArgumentException;
use Kumwe\CMS\Extension\Application\ExtensionServiceProvider;
use Kumwe\CMS\Extension\Application\Trust\TrustStore;
use Kumwe\CMS\Extension\Domain\ExtensionIdentifier;
use Kumwe\CMS\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\CMS\Extension\Contribution\ManifestContributionSet;
use Kumwe\CMS\Extension\Domain\ThemeSurface;
use RuntimeException;

/**
 * Turns the signed runtime publication into the live extension set for this process.
 *
 * Loading means executing code that arrived in an installed package, so this is written as a gate rather
 * than as a reader. The publication's signature and digests are re-checked before anything in it is
 * believed; every entry is then validated field by field, its root resolved to a real directory inside
 * extension storage with no symbolic link on the way, its autoload prefixes bound to directories under
 * that root, and its provider instantiated against a container holding only the host services the caller
 * passed in. Anything that does not hold up raises instead of being skipped, because a half-loaded
 * runtime is worse than a boot that falls back to the recovery surfaces. Once every entry is in, the
 * loader drives the `contribute()` and `boot()` phases and hands back the finished set.
 *
 * @since  2.0.0
 */
final readonly class ExtensionRuntimeLoader
{
    /**
     * Bind the loader to the publication it will execute and the storage it may execute from.
     *
     * @param  VerifiedRuntimePublication  $publication    Compiled runtime map, re-verified on every load
     *         rather than trusted from whoever read it.
     * @param  string                      $extensionRoot  Absolute path of extension storage; no extension
     *         root may resolve outside it.
     * @param  RuntimePublicationKeyRing   $keys           Key ring the publication's own signature is
     *         checked against, including keys it was rotated from.
     * @param  TrustStore                  $trust          Trust boundary handed to the active set, its
     *         routes, and each extension's event listeners.
     *
     * @since  2.0.0
     */
    public function __construct(
        private VerifiedRuntimePublication $publication,
        private string $extensionRoot,
        private RuntimePublicationKeyRing $keys,
        private TrustStore $trust,
    ) {
    }

    /**
     * Execute the publication and return the extensions that are now live.
     *
     * Every entry registers its services before the next one is read, while the two cross-extension
     * phases run only once the whole map is in, so one extension's contribution may point at another
     * extension's and a booting extension sees registries nobody will add to any more. A provider reaches
     * nothing but the services named in `$allowedServices`, and the event registrar among them is wrapped
     * before it is handed over, so its listeners stop firing if the extension later loses trust. Template
     * entries additionally claim their surface or their sites, and any entry may add view directories.
     *
     * @param   array<string, object>             $allowedServices  Host services every provider may
     *          resolve, keyed by the identifier it resolves them under.
     * @param   ExtensionContributionRegistrySet  $contributions    Registries, already carrying the core
     *          contributions, that extension contributions are added to.
     *
     * @return  ActiveExtensionSet  The loaded extensions, contributed and booted, ready to declare routes.
     *
     * @throws  RuntimeException  When the publication fails verification, an entry is malformed or names
     *          a provider that cannot be loaded, or a path in it is missing, symbolic, or escapes storage.
     * @throws  InvalidArgumentException  When a compiled extension root is not a `vendor/name/version`
     *          path, an identifier in the map is not a valid extension identifier, or a provider's
     *          registrations do not match the contributions its manifest declared.
     * @throws  \LogicException  When two entries claim the same theme surface or site, or an entry's
     *          manifest schema and provider contract disagree.
     * @throws  \Kumwe\CMS\BusinessDefinition\Domain\InvalidBusinessDefinition  When the business
     *          definitions the loaded extensions contribute do not validate as one graph.
     *
     * @since   2.0.0
     */
    public function load(
        array $allowedServices,
        ExtensionContributionRegistrySet $contributions,
    ): ActiveExtensionSet {
        $active = new ActiveExtensionSet($contributions, $this->trust);
        $this->publication->assertIntegrity($this->keys);
        $map = $this->publication->document;

        if (!is_array($map['extensions'] ?? null) || !array_is_list($map['extensions'])) {
            throw new RuntimeException('The compiled extension runtime map has an invalid structure.');
        }

        foreach ($map['extensions'] as $extension) {
            if (!is_array($extension) || array_is_list($extension)) {
                throw new RuntimeException('A compiled extension entry is invalid.');
            }

            $providerClass = $extension['provider'] ?? null;
            $relativeRoot = $extension['root'] ?? null;
            $autoload = $extension['autoload'] ?? null;
            $type = $extension['type'] ?? null;
            $identifier = $extension['identifier'] ?? null;
            $version = $extension['version'] ?? null;
            $signingKeyId = $extension['signing_key_id'] ?? null;
            $artifactDigest = $extension['artifact_sha256'] ?? null;
            $treeDigest = $extension['deployed_tree_sha256'] ?? null;
            $themeSurfaces = $extension['theme_surfaces'] ?? [];
            $themeSites = $extension['theme_sites'] ?? [];
            $manifestSchema = $extension['manifest_schema'] ?? 1;
            $declaredContributions = $extension['contributions'] ?? null;

            if (
                !is_string($providerClass)
                || !is_string($relativeRoot)
                || !is_array($autoload)
                || !is_string($type)
                || !is_string($identifier)
                || !is_string($version)
                || ($signingKeyId !== null && !is_string($signingKeyId))
                || !is_string($artifactDigest)
                || preg_match('/^[a-f0-9]{64}$/D', $artifactDigest) !== 1
                || !is_string($treeDigest)
                || preg_match('/^[a-f0-9]{64}$/D', $treeDigest) !== 1
                || !is_array($themeSurfaces)
                || !array_is_list($themeSurfaces)
                || !is_array($themeSites)
                || !array_is_list($themeSites)
                || !is_int($manifestSchema)
                || !in_array($manifestSchema, [1, 2, 3, 4, 5], true)
                || ($manifestSchema >= 2 && !is_array($declaredContributions))
            ) {
                throw new RuntimeException('A compiled extension entry is incomplete.');
            }
            $extensionIdentifier = ExtensionIdentifier::fromString($identifier);
            $identifier = $extensionIdentifier->value();
            $declared = $manifestSchema >= 2
                ? ManifestContributionSet::fromManifest(
                    $extensionIdentifier,
                    is_array($declaredContributions)
                        ? $declaredContributions
                        : throw new RuntimeException('Strict runtime contributions are unavailable.'),
                    $manifestSchema,
                )
                : ManifestContributionSet::legacy($extensionIdentifier, []);
            $root = $this->safeRoot($relativeRoot);
            $this->registerAutoload($root, $autoload);

            if (!class_exists($providerClass)) {
                throw new RuntimeException(sprintf('Active extension provider %s cannot be loaded.', $providerClass));
            }

            $provider = new $providerClass();

            if (!$provider instanceof ExtensionServiceProvider) {
                throw new RuntimeException(sprintf(
                    'Active extension provider %s must implement %s.',
                    $providerClass,
                    ExtensionServiceProvider::class,
                ));
            }

            $services = $allowedServices;
            $events = $services[ExtensionEventRegistrar::class] ?? null;
            if ($events instanceof ExtensionEventRegistrar) {
                $services[ExtensionEventRegistrar::class] = new TrustEnforcingExtensionEventRegistrar(
                    $events,
                    $this->trust,
                    $identifier,
                );
            }
            $container = new RestrictedExtensionContainer($identifier, $services);
            $provider->register($container);
            $active->add($identifier, $provider, $container, $declared, $manifestSchema >= 2);
            $this->addPortalTemplates($active, $identifier, $root);
            $this->addMessageCatalogues($active, $identifier, $root);

            if ($type !== 'template') {
                foreach ([ThemeSurface::Site, ThemeSurface::Administrator] as $surface) {
                    $this->addExtensionViews($active, $surface, $identifier, $root);
                }

                continue;
            }

            foreach ($themeSurfaces as $surfaceValue) {
                if (!is_string($surfaceValue)) {
                    throw new RuntimeException('A compiled theme surface is invalid.');
                }

                $surface = ThemeSurface::tryFrom($surfaceValue)
                    ?? throw new RuntimeException('A compiled theme surface is unsupported.');
                $themePath = $root . '/templates/' . $surface->value;

                if (is_link($themePath) || !is_dir($themePath)) {
                    throw new RuntimeException(sprintf(
                        'The active %s theme %s has no templates/%s directory.',
                        $surface->value,
                        $identifier,
                        $surface->value,
                    ));
                }

                if ($surface === ThemeSurface::Site) {
                    foreach ($themeSites as $siteIdentifier) {
                        if (!is_string($siteIdentifier)) {
                            throw new RuntimeException('A compiled theme site is invalid.');
                        }
                        $active->setSiteThemePath($siteIdentifier, $themePath);
                    }
                } else {
                    $active->setThemePath($surface, $themePath);
                }
                $this->addExtensionViews($active, $surface, $identifier, $root);
            }
        }

        $active->contribute();
        $active->boot();

        return $active;
    }

    /**
     * Offer an extension's view directory for one surface to the active set, when it ships one.
     *
     * A missing directory is normal and is passed over silently; a symbolic link in its place is not,
     * because it would let a package published inside extension storage render templates from anywhere
     * on the filesystem.
     *
     * @param   ActiveExtensionSet  $active      Set collecting the runtime surfaces of this load.
     * @param   ThemeSurface        $surface     Surface whose view directory is being looked for.
     * @param   string              $identifier  Extension the views would be namespaced under.
     * @param   string              $root        Resolved root of that extension on disk.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the view path is a symbolic link rather than a real directory.
     *
     * @since   2.0.0
     */
    private function addExtensionViews(
        ActiveExtensionSet $active,
        ThemeSurface $surface,
        string $identifier,
        string $root,
    ): void {
        $extensionViews = $root . '/templates/views/' . $surface->value;
        if (is_link($extensionViews)) {
            throw new RuntimeException('An extension view root cannot be a symbolic link.');
        }
        if (is_dir($extensionViews)) {
            $active->addExtensionViewPath($surface, $identifier, $extensionViews);
        }
    }

    /**
     * Discover an extension's portal templates without permitting a linked root.
     *
     * @param   ActiveExtensionSet  $active      Runtime set receiving the template namespace.
     * @param   string              $identifier  Extension whose namespace owns the templates.
     * @param   string              $root        Canonical extension root on disk.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the portal template root is a symbolic link.
     *
     * @since   2.0.0
     */
    private function addPortalTemplates(ActiveExtensionSet $active, string $identifier, string $root): void
    {
        $templates = $root . '/templates/views/portal';
        if (is_link($templates)) {
            throw new RuntimeException('An extension portal template root cannot be a symbolic link.');
        }
        if (is_dir($templates)) {
            $active->addPortalTemplatePath($identifier, $templates);
        }
    }

    /**
     * Discover an extension's compiled message catalogues without permitting a linked root.
     *
     * The directory is the compiled half of the layout `docs/interface-translation.md` publishes:
     * XLIFF under `localization/messages/` for a translator, and the build's plain-PHP output under
     * `localization/compiled/` for the runtime. Only the compiled half is read here, because nothing
     * on the request path parses XML. An extension that ships no wording is the ordinary case and is
     * passed over silently.
     *
     * @param   ActiveExtensionSet  $active      Runtime set collecting the extension layer's directories.
     * @param   string              $identifier  Extension whose namespace owns the identifiers.
     * @param   string              $root        Canonical extension root on disk.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the catalogue root is a symbolic link, which would let a package
     *          published inside extension storage supply wording from anywhere on the filesystem.
     *
     * @since   2.0.0
     */
    private function addMessageCatalogues(ActiveExtensionSet $active, string $identifier, string $root): void
    {
        $catalogues = $root . '/localization/compiled';
        if (is_link($catalogues)) {
            throw new RuntimeException('An extension message catalogue root cannot be a symbolic link.');
        }
        if (is_dir($catalogues)) {
            $active->addCatalogueDirectory($identifier, $catalogues);
        }
    }

    /**
     * Resolve a map-supplied extension root to a real directory inside extension storage.
     *
     * The map is signed, but it is still generated from package metadata, so the path is treated as
     * untrusted input: it has to be a plain `vendor/name/version` triple, it has to resolve inside the
     * configured storage root, and no segment of it may be a symbolic link. The segment walk is the part
     * containment alone does not cover — `realpath()` resolves links before the containment test, so a
     * link pointing at another directory inside storage would otherwise pass as a legitimate root.
     *
     * @param   string  $relativeRoot  Extension root as written in the compiled map, relative to storage.
     *
     * @return  string  Canonical absolute path of the extension root, with no symbolic link on the way.
     *
     * @throws  InvalidArgumentException  When the value is not a `vendor/name/version` path or contains a
     *          parent-directory segment.
     * @throws  RuntimeException  When the directory is missing, resolves outside extension storage, or a
     *          segment of the path is a symbolic link.
     *
     * @since   2.0.0
     */
    private function safeRoot(string $relativeRoot): string
    {
        if (
            preg_match('#^[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*/[0-9A-Za-z.+-]+$#D', $relativeRoot) !== 1
            || str_contains($relativeRoot, '..')
        ) {
            throw new InvalidArgumentException('The compiled extension root is unsafe.');
        }

        $root = $this->extensionRoot . '/' . $relativeRoot;
        $resolvedRoot = realpath($root);
        $resolvedExtensions = realpath($this->extensionRoot);

        if (
            !is_string($resolvedRoot)
            || !is_string($resolvedExtensions)
            || !str_starts_with($resolvedRoot . '/', $resolvedExtensions . '/')
        ) {
            throw new RuntimeException('An active extension root is missing or escapes extension storage.');
        }

        $candidate = rtrim($resolvedExtensions, '/');
        foreach (explode('/', $relativeRoot) as $segment) {
            $candidate .= '/' . $segment;
            if (is_link($candidate)) {
                throw new RuntimeException('An active extension root contains a symbolic link.');
            }
        }

        return $resolvedRoot;
    }

    /**
     * Register one class autoloader per declared prefix, rooted inside the extension's own directory.
     *
     * A prefix's directory is proven before its autoloader is registered, so a malformed declaration
     * fails the load rather than a later class resolution. The registered closure re-checks the file it
     * is about to require every time it runs — a path that turned into a symbolic link or resolved
     * outside the base directory after boot raises `RuntimeException` at that point, from whichever
     * request first touched the class.
     *
     * @param   string        $root      Resolved root of the extension the prefixes belong to.
     * @param   array<mixed>  $autoload  Compiled autoload declarations, mapping a class-name prefix to a
     *          source directory relative to the extension root.
     *
     * @return  void
     *
     * @throws  RuntimeException  When an entry is not a string-to-string pair, or its directory is
     *          missing, is reached through a symbolic link, or escapes the extension root.
     *
     * @since   2.0.0
     */
    private function registerAutoload(string $root, array $autoload): void
    {
        foreach ($autoload as $prefix => $relativePath) {
            if (!is_string($prefix) || !is_string($relativePath)) {
                throw new RuntimeException('A compiled extension autoload entry is invalid.');
            }

            $base = $root . '/' . rtrim($relativePath, '/');
            $candidate = $root;
            foreach (explode('/', trim($relativePath, '/')) as $segment) {
                $candidate .= '/' . $segment;
                if (is_link($candidate)) {
                    throw new RuntimeException('A compiled extension autoload root contains a symbolic link.');
                }
            }
            if (is_link($base) || !is_dir($base)) {
                throw new RuntimeException('A compiled extension autoload root is not a regular directory.');
            }
            $resolvedBase = realpath($base);
            if (!is_string($resolvedBase) || !str_starts_with($resolvedBase . '/', $root . '/')) {
                throw new RuntimeException('A compiled extension autoload root escapes extension storage.');
            }

            spl_autoload_register(static function (string $class) use ($prefix, $resolvedBase): void {
                if (!str_starts_with($class, $prefix)) {
                    return;
                }

                $relativeClass = substr($class, strlen($prefix));
                $relativeFile = str_replace('\\', '/', $relativeClass) . '.php';
                $file = $resolvedBase . '/' . $relativeFile;

                if (!file_exists($file) && !is_link($file)) {
                    return;
                }
                $candidate = $resolvedBase;
                foreach (explode('/', $relativeFile) as $segment) {
                    $candidate .= '/' . $segment;
                    if (is_link($candidate)) {
                        throw new RuntimeException('An extension autoload path contains a symbolic link.');
                    }
                }
                $resolved = realpath($file);
                if (
                    is_link($file) || !is_file($file) || !is_string($resolved)
                    || !str_starts_with($resolved, $resolvedBase . '/')
                ) {
                    throw new RuntimeException('An extension autoload target is not a trusted regular file.');
                }
                require $resolved;
            });
        }
    }
}
