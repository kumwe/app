<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Presentation;

use Kumwe\CMS\Presentation\Twig\AdministratorTwigEnvironment;
use Twig\Error\Error;

final readonly class AdministratorRenderer
{
    public function __construct(
        private AdministratorTwigEnvironment $twig,
        private RecoveryAdministratorRenderer $recovery,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        try {
            return $this->twig->render($template . '.twig', $data);
        } catch (Error) {
            return $this->recovery->render($template, $data);
        }
    }
}
