<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Extension\Application\ExtensionManager;
use Kumwe\CMS\Presentation\ThemeSurface;
use Throwable;

final readonly class ActivateExtensionCommand implements Command
{
    public function __construct(
        private ExtensionManager $extensions,
        private ConsoleAuthorizer $authorization,
    ) {
    }

    public function name(): string
    {
        return 'extension:activate';
    }

    public function description(): string
    {
        return 'Activate an extension or a site theme selected with --surface=site.';
    }

    /** @param list<string> $arguments */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $identifier = $arguments[0] ?? '';
            $options = CommandInput::options(array_slice($arguments, 1));
            $surface = ThemeSurface::optional($options['surface'] ?? null);
            $context = $this->authorization->require($options, 'extensions.manage');

            if ($surface === ThemeSurface::Administrator) {
                throw new \InvalidArgumentException(
                    'Administrator themes require step-up authentication in the administrator application.',
                );
            }

            $extension = $this->extensions->activate(
                $identifier,
                $context,
                $surface,
            );
            $installedIdentifier = $extension['identifier'] ?? $identifier;

            if (!is_string($installedIdentifier)) {
                throw new \RuntimeException('The extension manager returned an invalid identifier.');
            }

            $output->line(sprintf('Activated %s.', $installedIdentifier));

            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return 1;
        }
    }
}
