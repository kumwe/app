<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Presentation;

use Twig\Environment;

final readonly class AdministratorRenderer
{
    public function __construct(private Environment $twig)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        return $this->twig->render('administrator/' . $template . '.twig', $data);
    }
}
