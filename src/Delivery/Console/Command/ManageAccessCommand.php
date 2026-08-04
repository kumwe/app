<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

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
            $actor = $this->authorization->require($options, 'users.manage')->subject();
            $result = match ($action) {
                'users' => ['items' => $this->access->users()],
                'roles' => ['items' => $this->access->roles(), 'capabilities' => $this->access->capabilities()],
                'tokens' => ['items' => $this->access->tokens()],
                'create-user' => ['id' => $this->access->createUser(
                    $actor,
                    CommandInput::required($options, 'email'),
                    CommandInput::required($options, 'display-name'),
                    CommandInput::secretFile(CommandInput::required($options, 'password-file')),
                    UserStatus::from($options['status'] ?? 'active'),
                )],
                'update-user' => $this->updateUser($options, $actor),
                'create-role' => ['id' => $this->access->createRole(
                    $actor,
                    CommandInput::required($options, 'code'),
                    CommandInput::required($options, 'name'),
                )],
                'assign-role' => $this->assignRole($options, $actor, false),
                'revoke-role' => $this->assignRole($options, $actor, true),
                'grant' => ['id' => $this->access->grant(
                    $actor,
                    CommandInput::required($options, 'role'),
                    CommandInput::required($options, 'capability'),
                    $options['scope-type'] ?? 'global',
                    $this->optional($options, 'scope'),
                )],
                'revoke-grant' => $this->revokeGrant($options, $actor),
                'revoke-token' => $this->revokeToken($options, $actor),
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
    private function updateUser(array $options, string $actor): array
    {
        $this->access->updateUser(
            $actor,
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
    private function assignRole(array $options, string $actor, bool $revoke): array
    {
        $arguments = [
            $actor, CommandInput::required($options, 'user'),
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
    private function revokeGrant(array $options, string $actor): array
    {
        $this->access->revokeGrant($actor, CommandInput::required($options, 'grant'));
        return ['updated' => true];
    }

    /**
     * @param array<string, string> $options
     * @return array{updated: bool}
     */
    private function revokeToken(array $options, string $actor): array
    {
        $this->access->revokeToken($actor, CommandInput::required($options, 'token'));
        return ['updated' => true];
    }

    /** @param array<string, string> $options */
    private function optional(array $options, string $name): ?string
    {
        $value = trim($options[$name] ?? '');
        return $value === '' ? null : $value;
    }
}
