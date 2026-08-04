<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Install;

use InvalidArgumentException;
use Kumwe\CMS\Extension\Domain\ExtensionIdentifier;
use Kumwe\CMS\Extension\Domain\PackageChecksum;
use Kumwe\CMS\Extension\Domain\SemanticVersion;

final class AtomicInstallPlan
{
    /** @var list<InstallAction> */
    private const ACTIONS = [
        InstallAction::VerifyChecksum,
        InstallAction::InspectArchive,
        InstallAction::VerifyTrust,
        InstallAction::ResolveDependencies,
        InstallAction::StageFiles,
        InstallAction::ApplyMigrations,
        InstallAction::RegisterExtension,
        InstallAction::ActivateFiles,
        InstallAction::RebuildCaches,
    ];

    /** @var list<InstallAction> */
    private array $completedActions = [];

    private InstallState $state = InstallState::Planned;

    private ?string $failureCode = null;

    public function __construct(
        private readonly string $id,
        private readonly ExtensionIdentifier $extension,
        private readonly SemanticVersion $targetVersion,
        private readonly PackageChecksum $checksum,
        private readonly ?int $expectedRegistryVersion,
    ) {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $id) !== 1) {
            throw new InvalidArgumentException('An install plan ID must be a canonical UUID.');
        }

        if ($expectedRegistryVersion !== null && $expectedRegistryVersion < 0) {
            throw new InvalidArgumentException('The expected registry version cannot be negative.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function extension(): ExtensionIdentifier
    {
        return $this->extension;
    }

    public function targetVersion(): SemanticVersion
    {
        return $this->targetVersion;
    }

    public function checksum(): PackageChecksum
    {
        return $this->checksum;
    }

    public function expectedRegistryVersion(): ?int
    {
        return $this->expectedRegistryVersion;
    }

    public function state(): InstallState
    {
        return $this->state;
    }

    public function start(): void
    {
        $this->requireState(InstallState::Planned);
        $this->state = InstallState::Executing;
    }

    public function nextAction(): ?InstallAction
    {
        return self::ACTIONS[count($this->completedActions)] ?? null;
    }

    public function complete(InstallAction $action): void
    {
        $this->requireState(InstallState::Executing);

        if ($this->nextAction() !== $action) {
            throw new InvalidInstallTransition('Install actions must complete in their declared order.');
        }

        $this->completedActions[] = $action;
    }

    public function commit(): void
    {
        $this->requireState(InstallState::Executing);

        if ($this->nextAction() !== null) {
            throw new InvalidInstallTransition('An install cannot commit before every action has completed.');
        }

        $this->state = InstallState::Committed;
    }

    public function fail(string $failureCode): void
    {
        $this->requireState(InstallState::Executing);

        if (preg_match('/^[a-z][a-z0-9._-]{2,126}$/D', $failureCode) !== 1) {
            throw new InvalidArgumentException('An installation failure code must be a stable identifier.');
        }

        $this->failureCode = $failureCode;
        $this->state = InstallState::Failed;
    }

    public function beginRollback(): void
    {
        if (!in_array($this->state, [InstallState::Executing, InstallState::Failed], true)) {
            throw new InvalidInstallTransition('Only an executing or failed install can begin rollback.');
        }

        $this->state = InstallState::RollingBack;
    }

    public function finishRollback(): void
    {
        $this->requireState(InstallState::RollingBack);
        $this->state = InstallState::RolledBack;
    }

    /** @return list<InstallAction> */
    public function completedActions(): array
    {
        return $this->completedActions;
    }

    public function failureCode(): ?string
    {
        return $this->failureCode;
    }

    private function requireState(InstallState $required): void
    {
        if ($this->state !== $required) {
            throw new InvalidInstallTransition(sprintf(
                'The install must be %s, but it is %s.',
                $required->value,
                $this->state->value,
            ));
        }
    }
}
