<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Extension\Application\ExtensionManager;
use Throwable;

final readonly class UninstallExtensionCommand implements Command
{
    public function __construct(private ExtensionManager $extensions)
    {
    }

    public function name(): string
    {
        return 'extension:uninstall';
    }

    public function description(): string
    {
        return 'Remove an extension registry entry and its active files.';
    }

    public function execute(array $arguments, Output $output): int
    {
        try {
            $identifier = $arguments[0] ?? '';
            $this->extensions->uninstall($identifier, 'system:cli');
            $output->line(sprintf('Uninstalled %s.', $identifier));

            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return 1;
        }
    }
}
