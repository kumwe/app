<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\AuthenticationStrength;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\CMS\Identity\Application\Authorization\InsufficientCapability;
use Kumwe\CMS\Identity\Domain\Capability;
use Throwable;

final readonly class CreateAccessTokenCommand implements Command
{
    public function __construct(
        private AdministratorIdentityGateway $identities,
        private ConsoleAuthorizer $authorization,
    ) {
    }

    public function name(): string
    {
        return 'token:create';
    }

    public function description(): string
    {
        return 'Create a scoped API/MCP access token and print it once.';
    }

    /** @param list<string> $arguments */
    public function execute(array $arguments, Output $output): int
    {
        try {
            $options = $this->options($arguments);
            $capabilities = array_values(array_filter(array_map(
                'trim',
                explode(',', $this->required($options, 'capabilities')),
            ), static fn (string $value): bool => $value !== ''));
            $expiresAt = isset($options['expires-at']) ? new DateTimeImmutable($options['expires-at']) : null;
            $context = $this->authorizationContext($options);
            $created = $this->identities->issueAccessToken(
                $context,
                $this->required($options, 'email'),
                $this->required($options, 'name'),
                $capabilities,
                $expiresAt,
            );
            $output->line('Store this token now; Kumwe will not display it again:');
            $output->line($created['token']);
            $output->line(sprintf('Token ID: %s', $created['token_id']));

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

    /** @param array<string, string> $options */
    private function authorizationContext(array $options): ExecutionContext
    {
        if (isset($options['token-file'])) {
            return $this->authorization->require($options, 'users.manage');
        }

        $principal = $this->identities->authenticate(
            $this->required($options, 'email'),
            CommandInput::secretFile($this->required($options, 'password-file')),
            'cli-token-create',
        );
        if ($principal === null || !$principal->hasCapability(Capability::fromString('users.manage'))) {
            throw new InsufficientCapability('users.manage');
        }

        return $principal->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'cli-' . bin2hex(random_bytes(16)),
        );
    }
}
