<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation;

use Kumwe\CMS\Presentation\Twig\SiteTwigEnvironment;

final readonly class SiteRenderer
{
    public function __construct(private SiteTwigEnvironment $twig)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        return $this->twig->render($template . '.twig', $data);
    }
}
