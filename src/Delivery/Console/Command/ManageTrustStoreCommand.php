<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use DateTimeImmutable;
use InvalidArgumentException;
use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Kumwe\CMS\Extension\Application\Trust\TrustStore;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Throwable;

final readonly class ManageTrustStoreCommand implements Command
{
    public function __construct(private TrustStore $trust, private ConsoleAuthorizer $authorization)
    {
    }

    public function name(): string
    {
        return 'extension:trust';
    }

    public function description(): string
    {
        return 'List, add, rotate, or emergency-revoke extension signing keys.';
    }

    public function execute(array $arguments, Output $output): int
    {
        try {
            $action = array_shift($arguments) ?? 'list';
            $options = CommandInput::options($arguments);
            $actor = $this->authorization->require($options, 'extensions.manage');
            $result = match ($action) {
                'list' => ['items' => $this->trust->keys($actor)],
                'add' => $this->add($actor, $options),
                'rotate' => $this->rotate($actor, $options),
                'revoke' => $this->revoke($actor, $options),
                'finalize-rotation' => $this->finalizeRotation($actor, $options),
                'emergency-revoke' => $this->emergencyRevoke($actor, $options),
                default => throw new InvalidArgumentException('Unsupported extension trust action.'),
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
    private function add(ExecutionContext $actor, array $options): array
    {
        $this->trust->add(
            $actor,
            CommandInput::required($options, 'key'),
            CommandInput::secretFile(CommandInput::required($options, 'public-key-file')),
            $options['vendor'] ?? '*',
            $options['extension'] ?? '*',
            new DateTimeImmutable(CommandInput::required($options, 'expires-at')),
        );
        return ['updated' => true];
    }

    /**
     * @param array<string, string> $options
     * @return array{updated: bool}
     */
    private function rotate(ExecutionContext $actor, array $options): array
    {
        $this->trust->rotate(
            $actor,
            CommandInput::required($options, 'old-key'),
            CommandInput::required($options, 'new-key'),
            CommandInput::secretFile(CommandInput::required($options, 'public-key-file')),
            $options['vendor'] ?? '*',
            $options['extension'] ?? '*',
            new DateTimeImmutable(CommandInput::required($options, 'expires-at')),
        );
        return ['updated' => true];
    }

    /**
     * @param array<string, string> $options
     * @return array{updated: bool}
     */
    private function revoke(ExecutionContext $actor, array $options): array
    {
        $this->trust->revoke(
            $actor,
            CommandInput::required($options, 'key'),
            CommandInput::required($options, 'reason'),
        );
        return ['updated' => true];
    }

    /**
     * @param array<string, string> $options
     * @return array{updated: bool}
     */
    private function finalizeRotation(ExecutionContext $actor, array $options): array
    {
        $this->trust->finalizeRotation(
            $actor,
            CommandInput::required($options, 'key'),
            CommandInput::required($options, 'reason'),
        );
        return ['updated' => true];
    }

    /**
     * @param array<string, string> $options
     * @return array{quarantined: list<string>}
     */
    private function emergencyRevoke(ExecutionContext $actor, array $options): array
    {
        return ['quarantined' => $this->trust->emergencyRevoke(
            $actor,
            CommandInput::required($options, 'key'),
            CommandInput::required($options, 'reason'),
        )];
    }
}
