<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Extension\Application\ExtensionManager;
use Throwable;

final readonly class ActivateExtensionCommand implements Command
{
    public function __construct(private ExtensionManager $extensions)
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

    public function execute(array $arguments, Output $output): int
    {
        try {
            $identifier = $arguments[0] ?? '';
            $extension = $this->extensions->activate($identifier, 'system:cli');
            $output->line(sprintf('Activated %s.', (string) ($extension['identifier'] ?? $identifier)));

            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return 1;
        }
    }
}
