<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Application;

use InvalidArgumentException;
use Kumwe\CMS\Presentation\ThemeSurface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Compiles a candidate theme ahead of activation so a broken package can never reach a visitor.
 *
 * Activating a theme that does not compile would turn every page into a render error — including the
 * administrator console an operator would use to undo the change. This validator runs before the
 * registry write: it insists the surface's entry templates are ordinary files rather than symlinks that
 * could reach outside the package, compiles every Twig file the package ships against the same loader
 * chain the renderer will use, and for the administrator surface renders the layout to prove it still
 * exposes the title and content blocks and a main landmark. Every failure is reported as an
 * `InvalidArgumentException`, which `DoctrineExtensionManager` lets abort the activation.
 *
 * @since  2.0.0
 */
final readonly class ThemePackageValidator
{
    /**
     * Bind the validator to the core template tree candidate themes inherit from.
     *
     * @param  string  $coreTemplateRoot  Directory holding the per-surface built-in template trees.
     *
     * @since  2.0.0
     */
    public function __construct(private string $coreTemplateRoot)
    {
    }

    /**
     * Assert that a theme directory compiles for the surface it is about to be activated on.
     *
     * The candidate directory is registered both anonymously and under a surface namespace, with the
     * core tree behind it, so a package that overrides only some templates still resolves. Twig errors
     * are re-thrown as `InvalidArgumentException` carrying the underlying message, so the caller has one
     * failure type to handle and the operator still sees which template broke. On the administrator
     * surface the layout is additionally rendered and inspected for its title and content blocks and a
     * main landmark, since a theme that compiles can still leave the console unusable.
     *
     * @param   string        $themePath  Directory holding this surface's templates inside the package.
     * @param   ThemeSurface  $surface    Surface the theme is being activated on.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the directory, an entry template, or any Twig file is bad.
     *
     * @since   2.0.0
     */
    public function validate(string $themePath, ThemeSurface $surface): void
    {
        $resolved = realpath($themePath);
        if (!is_string($resolved) || !is_dir($resolved) || is_link($themePath)) {
            throw new InvalidArgumentException('The selected theme surface directory is invalid.');
        }

        foreach ($this->requiredEntries($surface) as $entry) {
            $file = $resolved . '/' . $entry;
            if (!is_file($file) || is_link($file)) {
                throw new InvalidArgumentException(sprintf(
                    'The %s theme requires a regular %s entry template.',
                    $surface->value,
                    $entry,
                ));
            }
        }

        $loader = new FilesystemLoader();
        $loader->addPath($resolved);
        $loader->addPath($resolved, $surface === ThemeSurface::Site ? 'site-theme' : 'admin-theme');
        $corePath = $this->coreTemplateRoot . '/' . $surface->value;
        $loader->addPath($corePath);
        $loader->addPath($corePath, $surface === ThemeSurface::Site ? 'core-site' : 'core-admin');
        $twig = new Environment($loader, ['autoescape' => 'html', 'cache' => false, 'strict_variables' => true]);
        $templates = $this->templates($resolved);

        if ($templates === []) {
            throw new InvalidArgumentException('The selected theme surface contains no Twig templates.');
        }

        try {
            foreach ($templates as $template) {
                $twig->load($template);
            }
            if ($surface === ThemeSurface::Administrator) {
                $rendered = $twig->createTemplate(
                    '{% extends "@admin-theme/layout.twig" %}'
                    . '{% block title %}KUMWE_TITLE_SENTINEL{% endblock %}'
                    . '{% block content %}<p>KUMWE_CONTENT_SENTINEL</p>{% endblock %}',
                )->render();
                if (
                    !str_contains($rendered, 'KUMWE_TITLE_SENTINEL')
                    || !str_contains($rendered, 'KUMWE_CONTENT_SENTINEL')
                    || preg_match('/<(?:main)\b|\brole=["\']main["\']/i', $rendered) !== 1
                ) {
                    throw new InvalidArgumentException(
                        'The administrator layout must expose title/content blocks and a main landmark.',
                    );
                }
            }
        } catch (Throwable $exception) {
            if ($exception instanceof InvalidArgumentException) {
                throw $exception;
            }
            throw new InvalidArgumentException(sprintf(
                'The %s theme could not be compiled: %s',
                $surface->value,
                $exception->getMessage(),
            ), 0, $exception);
        }
    }

    /**
     * List the templates a surface cannot render without.
     *
     * @param   ThemeSurface  $surface  Surface the theme is being activated on.
     *
     * @return  list<string>  Package-relative template names that must exist as regular files.
     *
     * @since   2.0.0
     */
    private function requiredEntries(ThemeSurface $surface): array
    {
        return $surface === ThemeSurface::Site ? ['home.twig', 'page.twig'] : ['layout.twig'];
    }

    /**
     * Collect every Twig template the package ships, so validation compiles all of them.
     *
     * Symlinked files are skipped instead of followed, which keeps a package from pulling templates in
     * from outside its own directory. The list is sorted so two runs over the same package report the
     * same failure first.
     *
     * @param   string  $root  Resolved theme directory to walk.
     *
     * @return  list<string>  Template paths relative to the root, slash separated and sorted.
     *
     * @since   2.0.0
     */
    private function templates(string $root): array
    {
        $templates = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile() || $item->isLink()) {
                continue;
            }
            if (strtolower($item->getExtension()) === 'twig') {
                $templates[] = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
            }
        }

        sort($templates, SORT_STRING);

        return $templates;
    }
}
