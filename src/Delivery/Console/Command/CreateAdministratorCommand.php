<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Application\Authorization\SystemPrincipal;
use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Identity\Application\Administration\AdministratorIdentityGateway;
use Throwable;

final readonly class CreateAdministratorCommand implements Command
{
    public function __construct(
        private AdministratorIdentityGateway $identities,
        private SystemPrincipal $system,
    ) {
    }

    public function name(): string
    {
        return 'user:create-admin';
    }

    public function description(): string
    {
        return 'Create the first administrator from a protected password file.';
    }

    /** @param list<string> $arguments */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $options = $this->options($arguments);
            $password = $this->passwordFromFile($this->required($options, 'password-file'));
            $id = $this->identities->createInitialAdministrator(
                $this->system->context(
                    SiteContext::default(),
                    'bootstrap-' . bin2hex(random_bytes(16)),
                ),
                $this->required($options, 'email'),
                $this->required($options, 'name'),
                $password,
            );
            $output->line(sprintf('Created initial administrator %s.', $id));

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
                throw new InvalidArgumentException('Options must use --name=value syntax.');
            }

            $options[$matches[1]] = $matches[2];
        }

        return $options;
    }

    /** @param array<string, string> $options */
    private function required(array $options, string $name): string
    {
        $value = trim($options[$name] ?? '');

        if ($value === '') {
            throw new InvalidArgumentException(sprintf('The --%s option is required.', $name));
        }

        return $value;
    }

    private function passwordFromFile(string $path): string
    {
        if (!str_starts_with($path, '/') || is_link($path) || !is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('The password file must be an absolute, readable regular file.');
        }

        $permissions = fileperms($path);

        if (!is_int($permissions) || ($permissions & 0o077) !== 0) {
            throw new InvalidArgumentException(
                'The password file must not be readable or writable by group or others.',
            );
        }

        $password = file_get_contents($path);

        if (!is_string($password)) {
            throw new InvalidArgumentException('The password file could not be read.');
        }

        $password = rtrim($password, "\r\n");

        if (strlen($password) < 12) {
            throw new InvalidArgumentException(
                'The initial administrator password must contain at least 12 characters.',
            );
        }

        return $password;
    }
}
