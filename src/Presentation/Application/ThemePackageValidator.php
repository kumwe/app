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

final readonly class ThemePackageValidator
{
    public function __construct(private string $coreTemplateRoot)
    {
    }

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

    /** @return list<string> */
    private function requiredEntries(ThemeSurface $surface): array
    {
        return $surface === ThemeSurface::Site ? ['home.twig', 'page.twig'] : ['layout.twig'];
    }

    /** @return list<string> */
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
