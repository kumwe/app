<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Extension\Application\ExtensionManager;
use Throwable;

final readonly class InstallExtensionCommand implements Command
{
    public function __construct(private ExtensionManager $extensions)
    {
    }

    public function name(): string
    {
        return 'extension:install';
    }

    public function description(): string
    {
        return 'Verify, install and activate a Kumwe extension ZIP.';
    }

    /** @param list<string> $arguments */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $archive = $arguments[0] ?? '';

            if ($archive === '' || str_starts_with($archive, '--')) {
                throw new InvalidArgumentException(
                    'Usage: extension:install /absolute/package.zip [--key-id=ID --signature=BASE64]',
                );
            }

            $options = $this->options(array_slice($arguments, 1));
            $installed = $this->extensions->install(
                $archive,
                'system:cli',
                $options['key-id'] ?? null,
                $options['signature'] ?? null,
            );
            $identifier = $this->resultString($installed, 'identifier');
            $version = $this->resultString($installed, 'installed_version');
            $status = $this->resultString($installed, 'status');
            $output->line(sprintf(
                'Installed %s %s (%s).',
                $identifier,
                $version,
                $status,
            ));

            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return 1;
        }
    }

    /**
     * @param list<string> $arguments
     * @return array<string, string>
     */
    private function options(array $arguments): array
    {
        $options = [];

        foreach ($arguments as $argument) {
            if (preg_match('/^--([a-z][a-z-]*)=(.+)$/D', $argument, $matches) !== 1) {
                throw new InvalidArgumentException('Extension options must use --name=value syntax.');
            }

            $options[$matches[1]] = $matches[2];
        }

        return $options;
    }

    /** @param array<string, mixed> $result */
    private function resultString(array $result, string $field): string
    {
        $value = $result[$field] ?? null;

        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException(sprintf('The installed extension %s is invalid.', $field));
        }

        return $value;
    }
}
