<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Identity\Application\Administration\AccessControlService;
use Kumwe\CMS\Identity\Domain\UserStatus;
use Throwable;

final readonly class ManageAccessCommand implements Command
{
    public function __construct(private AccessControlService $access, private ConsoleAuthorizer $authorization)
    {
    }

    public function name(): string
    {
        return 'access';
    }

    public function description(): string
    {
        return 'List and manage users, roles, and capability grants.';
    }

    public function execute(array $arguments, Output $output): int
    {
        try {
            $action = array_shift($arguments) ?? 'users';
            $options = CommandInput::options($arguments);
            $context = $this->authorization->require($options, 'users.manage');
            $result = match ($action) {
                'users' => ['items' => $this->access->users($context)],
                'roles' => [
                    'items' => $this->access->roles($context),
                    'capabilities' => $this->access->capabilities($context),
                ],
                'tokens' => ['items' => $this->access->tokens($context)],
                'create-user' => ['id' => $this->access->createUser(
                    $context,
                    CommandInput::required($options, 'email'),
                    CommandInput::required($options, 'display-name'),
                    CommandInput::secretFile(CommandInput::required($options, 'password-file')),
                    UserStatus::from($options['status'] ?? 'active'),
                )],
                'update-user' => $this->updateUser($options, $context),
                'create-role' => ['id' => $this->access->createRole(
                    $context,
                    CommandInput::required($options, 'code'),
                    CommandInput::required($options, 'name'),
                )],
                'assign-role' => $this->assignRole($options, $context, false),
                'revoke-role' => $this->assignRole($options, $context, true),
                'grant' => ['id' => $this->access->grant(
                    $context,
                    CommandInput::required($options, 'role'),
                    CommandInput::required($options, 'capability'),
                    $options['scope-type'] ?? 'global',
                    $this->optional($options, 'scope'),
                )],
                'revoke-grant' => $this->revokeGrant($options, $context),
                'revoke-token' => $this->revokeToken($options, $context),
                default => throw new \InvalidArgumentException('Unsupported access action.'),
            };
            $output->line(CommandInput::render($result));
            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());
            return 1;
        }
    }

    /**
     * @param array<string, string> $options
     * @return array{updated: bool}
     */
    private function updateUser(array $options, ExecutionContext $context): array
    {
        $this->access->updateUser(
            $context,
            CommandInput::required($options, 'id'),
            CommandInput::required($options, 'email'),
            CommandInput::required($options, 'display-name'),
            UserStatus::from(CommandInput::required($options, 'status')),
            CommandInput::positiveInteger($options, 'version'),
        );
        return ['updated' => true];
    }

    /**
     * @param array<string, string> $options
     * @return array{updated: bool}
     */
    private function assignRole(array $options, ExecutionContext $context, bool $revoke): array
    {
        $arguments = [
            $context, CommandInput::required($options, 'user'),
            CommandInput::required($options, 'role'),
        ];
        if ($revoke) {
            $this->access->revokeRole(...$arguments);
        } else {
            $this->access->assignRole(...$arguments);
        }
        return ['updated' => true];
    }

    /**
     * @param array<string, string> $options
     * @return array{updated: bool}
     */
    private function revokeGrant(array $options, ExecutionContext $context): array
    {
        $this->access->revokeGrant($context, CommandInput::required($options, 'grant'));
        return ['updated' => true];
    }

    /**
     * @param array<string, string> $options
     * @return array{updated: bool}
     */
    private function revokeToken(array $options, ExecutionContext $context): array
    {
        $this->access->revokeToken($context, CommandInput::required($options, 'token'));
        return ['updated' => true];
    }

    /** @param array<string, string> $options */
    private function optional(array $options, string $name): ?string
    {
        $value = trim($options[$name] ?? '');
        return $value === '' ? null : $value;
    }
}
