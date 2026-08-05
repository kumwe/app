<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Extension\Application\ExtensionManager;
use Throwable;

final readonly class ActivateExtensionCommand implements Command
{
    public function __construct(private ExtensionManager $extensions, private ConsoleAuthorizer $authorization)
    {
    }

    public function name(): string
    {
        return 'extension:activate';
    }

    public function description(): string
    {
        return 'Activate an installed extension and rebuild the runtime map.';
    }

    /** @param list<string> $arguments */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $identifier = array_shift($arguments) ?? '';
            $options = CommandInput::options($arguments);
            $context = $this->authorization->require($options, 'extensions.manage');
            $extension = $this->extensions->activate($identifier, $context);
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
