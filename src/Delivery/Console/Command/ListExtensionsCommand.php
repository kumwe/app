<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Extension\Application\ExtensionManager;

final readonly class ListExtensionsCommand implements Command
{
    public function __construct(private ExtensionManager $extensions)
    {
    }

    public function name(): string
    {
        return 'extension:list';
    }

    public function description(): string
    {
        return 'List installed extensions, versions and runtime status.';
    }

    /** @param list<string> $arguments */
    public function execute(array $arguments, Output $output): int
    {
        foreach ($this->extensions->installed() as $extension) {
            $output->line(sprintf(
                '%-40s %-12s %-12s %s',
                $this->value($extension, 'identifier'),
                $this->value($extension, 'extension_type'),
                $this->value($extension, 'installed_version'),
                $this->value($extension, 'status'),
            ));
        }

        return 0;
    }

    /** @param array<string, mixed> $extension */
    private function value(array $extension, string $field): string
    {
        $value = $extension[$field] ?? null;

        return is_string($value) ? $value : '';
    }
}
