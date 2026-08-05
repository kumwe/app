<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Presentation;

use Kumwe\CMS\Presentation\Twig\RecoveryAdministratorTwigEnvironment;

final readonly class RecoveryAdministratorRenderer
{
    public function __construct(private RecoveryAdministratorTwigEnvironment $twig)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        return $this->twig->render($template . '.twig', $data);
    }
}
