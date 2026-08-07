<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Runtime;

use InvalidArgumentException;
use Kumwe\CMS\Extension\Application\ExtensionServiceProvider;
use Kumwe\CMS\Extension\Application\Trust\TrustStore;
use Kumwe\CMS\Extension\Domain\ExtensionIdentifier;
use Kumwe\CMS\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\CMS\Extension\Contribution\ManifestContributionSet;
use Kumwe\CMS\Presentation\ThemeSurface;
use RuntimeException;

final readonly class ExtensionRuntimeLoader
{
    public function __construct(
        private VerifiedRuntimePublication $publication,
        private string $extensionRoot,
        private RuntimePublicationKeyRing $keys,
        private TrustStore $trust,
    ) {
    }

    /** @param array<string, object> $allowedServices */
    public function load(
        array $allowedServices,
        ExtensionContributionRegistrySet $contributions,
    ): ActiveExtensionSet
    {
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
                || !in_array($manifestSchema, [1, 2], true)
                || ($manifestSchema === 2 && !is_array($declaredContributions))
            ) {
                throw new RuntimeException('A compiled extension entry is incomplete.');
            }
            $extensionIdentifier = ExtensionIdentifier::fromString($identifier);
            $identifier = $extensionIdentifier->value();
            $declared = $manifestSchema === 2
                ? ManifestContributionSet::fromManifest(
                    $extensionIdentifier,
                    is_array($declaredContributions)
                        ? $declaredContributions
                        : throw new RuntimeException('Schema-2 runtime contributions are unavailable.'),
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
            $active->add($identifier, $provider, $container, $declared, $manifestSchema === 2);

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

    /** @param array<mixed> $autoload */
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
