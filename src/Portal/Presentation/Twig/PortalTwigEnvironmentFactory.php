<?php

declare(strict_types=1);

namespace Kumwe\App\Portal\Presentation\Twig;

use InvalidArgumentException;
use Kumwe\App\Extension\Domain\ExtensionIdentifier;
use Kumwe\App\Localization\Presentation\TranslationTwigExtension;
use Kumwe\App\Presentation\Twig\IsolatedTwigEnvironmentFactory;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Builds the minimal, strict, namespace-isolated Twig environment for the portal surface.
 *
 * This factory is intentionally not referenced by recovery composition. The main runtime supplies only
 * trusted active extensions' explicit portal-template directories; no administrator theme or template
 * namespace is inherited.
 *
 * @since  2.0.0
 */
final readonly class PortalTwigEnvironmentFactory
{
    /**
     * Configure production caching for a portal environment.
     *
     * @param  bool                       $production   Whether compiled Twig templates are cached.
     * @param  ?TranslationTwigExtension  $translation  Extension publishing `t`, `locale_tag` and
     *         `text_direction` onto the portal environment; null leaves it without them.
     *
     * @since  2.0.0
     */
    public function __construct(
        private bool $production,
        private ?TranslationTwigExtension $translation = null,
    ) {
    }

    /**
     * Build a core loader plus injective per-extension namespaces.
     *
     * @param   string                 $coreTemplates  Absolute core templates directory containing `portal/`.
     * @param   array<string, string>  $extensions     Trusted `vendor/name` to absolute portal-template directory.
     * @param   string                 $cache          Absolute cache directory used only in production.
     *
     * @return  Environment  Autoescaping strict portal-only Twig environment.
     *
     * @throws  InvalidArgumentException  When a core or extension directory is missing or not absolute.
     *
     * @since   2.0.0
     */
    public function create(string $coreTemplates, array $extensions, string $cache): Environment
    {
        $core = self::directory($coreTemplates, 'core portal templates');
        $loader = new FilesystemLoader($core);
        $loader->addPath(
            self::directory($core . '/interface-standard', 'KIS templates'),
            'kis',
        );
        ksort($extensions, SORT_STRING);
        foreach ($extensions as $identifier => $path) {
            $owner = ExtensionIdentifier::fromString($identifier)->value();
            $loader->addPath(
                self::directory($path, 'extension portal templates'),
                IsolatedTwigEnvironmentFactory::extensionNamespace($owner),
            );
        }

        $environment = new Environment($loader, [
            'autoescape' => 'html',
            'strict_variables' => true,
            'cache' => $this->production ? $cache : false,
        ]);
        if ($this->translation instanceof TranslationTwigExtension) {
            $environment->addExtension($this->translation);
        }

        return $environment;
    }

    /**
     * Resolve and validate an existing absolute directory.
     *
     * @param   string  $path   Candidate path.
     * @param   string  $field  Description named in a rejection.
     *
     * @return  string  Canonical absolute directory.
     *
     * @throws  InvalidArgumentException  When the path is not absolute or is not a directory.
     *
     * @since   2.0.0
     */
    private static function directory(string $path, string $field): string
    {
        if (!str_starts_with($path, DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException(sprintf('The %s path must be absolute.', $field));
        }
        $resolved = realpath($path);
        if (!is_string($resolved) || !is_dir($resolved)) {
            throw new InvalidArgumentException(sprintf('The %s directory does not exist.', $field));
        }

        return $resolved;
    }
}
