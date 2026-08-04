<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Extension\Application\ExtensionManager;
use Throwable;

final readonly class DisableExtensionCommand implements Command
{
    public function __construct(private ExtensionManager $extensions)
    {
    }

    public function name(): string
    {
        return 'extension:disable';
    }

    public function description(): string
    {
        return 'Disable an extension and remove it from the runtime map.';
    }

    public function execute(array $arguments, Output $output): int
    {
        try {
            $identifier = $arguments[0] ?? '';
            $extension = $this->extensions->disable($identifier, 'system:cli');
            $output->line(sprintf('Disabled %s.', (string) ($extension['identifier'] ?? $identifier)));

            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return 1;
        }
    }
}
