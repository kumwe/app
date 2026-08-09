<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Install;

use InvalidArgumentException;
use Kumwe\CMS\Extension\Domain\ExtensionIdentifier;
use Kumwe\CMS\Extension\Domain\PackageChecksum;
use Kumwe\CMS\Extension\Domain\SemanticVersion;

/**
 * Bookkeeping half of one extension installation: its declared step order and its legal state changes.
 *
 * An install touches the filesystem, the schema and the registry, which cannot be wrapped in a single
 * transaction, so the sequence itself has to be policed. This plan does exactly that and nothing else:
 * it names the actions in the order they must complete, rejects a step reported out of turn, refuses to
 * commit while any step is outstanding, and records a stable failure code alongside the steps already
 * done so a compensating rollback knows how far the install got. It performs no I/O and holds no
 * resources — whoever carries out an action reports it back with `complete()`.
 *
 * @since  2.0.0
 */
final class AtomicInstallPlan
{
    /**
     * The fixed sequence every install follows, from proving the package to republishing derived state.
     *
     * @var    list<InstallAction>
     * @since  2.0.0
     */
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

    /**
     * Actions reported complete so far; its length is the cursor into `ACTIONS`.
     *
     * @var    list<InstallAction>
     * @since  2.0.0
     */
    private array $completedActions = [];

    /**
     * Where the install currently stands in its lifecycle.
     *
     * @var    InstallState
     * @since  2.0.0
     */
    private InstallState $state = InstallState::Planned;

    /**
     * Stable identifier of the failure recorded by `fail()`, or null while the install has not failed.
     *
     * @var    ?string
     * @since  2.0.0
     */
    private ?string $failureCode = null;

    /**
     * Describe the installation this plan governs and validate the values it is pinned to.
     *
     * @param   string               $id                       Canonical UUID naming this installation attempt.
     * @param   ExtensionIdentifier  $extension                Extension the package installs.
     * @param   SemanticVersion      $targetVersion            Version the install leaves behind on success.
     * @param   PackageChecksum      $checksum                 Digest of the package the plan was built from.
     * @param   ?int                 $expectedRegistryVersion  Registry version the plan was built against, so a
     *          concurrent change to the extension's registry row can be told apart from a clean run; null when
     *          the install pins none. Must not be negative.
     *
     * @throws  InvalidArgumentException  When the ID is not a canonical UUID, or the expected registry version
     *          is negative.
     *
     * @since   2.0.0
     */
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

    /**
     * Return the identifier that names this installation attempt.
     *
     * @return  string  Canonical UUID, validated at construction.
     *
     * @since   2.0.0
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Return which extension the plan installs.
     *
     * @return  ExtensionIdentifier  The `vendor/name` identifier supplied at construction.
     *
     * @since   2.0.0
     */
    public function extension(): ExtensionIdentifier
    {
        return $this->extension;
    }

    /**
     * Return the version a successful run of this plan leaves installed.
     *
     * @return  SemanticVersion  The packaged release's version.
     *
     * @since   2.0.0
     */
    public function targetVersion(): SemanticVersion
    {
        return $this->targetVersion;
    }

    /**
     * Return the digest identifying the exact package this plan was built for.
     *
     * @return  PackageChecksum  The digest recorded at construction, unchanged by any state transition.
     *
     * @since   2.0.0
     */
    public function checksum(): PackageChecksum
    {
        return $this->checksum;
    }

    /**
     * Return the registry version this install was planned against.
     *
     * @return  ?int  The pinned version, or null when the plan does not constrain what it supersedes.
     *
     * @since   2.0.0
     */
    public function expectedRegistryVersion(): ?int
    {
        return $this->expectedRegistryVersion;
    }

    /**
     * Return where the install currently stands.
     *
     * @return  InstallState  The plan's state; `Planned` until `start()` has been called.
     *
     * @since   2.0.0
     */
    public function state(): InstallState
    {
        return $this->state;
    }

    /**
     * Move the plan from planning into execution.
     *
     * @return  void
     *
     * @throws  InvalidInstallTransition  When the plan is not `Planned`, which includes a second start.
     *
     * @since   2.0.0
     */
    public function start(): void
    {
        $this->requireState(InstallState::Planned);
        $this->state = InstallState::Executing;
    }

    /**
     * Name the action that has to complete next.
     *
     * Reported from the count of completed actions alone, so it is answerable in any state — it does not
     * imply the plan is executing, and a committed plan reports null just as an exhausted one does.
     *
     * @return  ?InstallAction  The next action in declared order, or null once every action has completed.
     *
     * @since   2.0.0
     */
    public function nextAction(): ?InstallAction
    {
        return self::ACTIONS[count($this->completedActions)] ?? null;
    }

    /**
     * Record that an action finished, holding the install to its declared order.
     *
     * @param   InstallAction  $action  The action just carried out; it must be the one `nextAction()` names.
     *
     * @return  void
     *
     * @throws  InvalidInstallTransition  When the plan is not executing, or the action is not the next one due.
     *
     * @since   2.0.0
     */
    public function complete(InstallAction $action): void
    {
        $this->requireState(InstallState::Executing);

        if ($this->nextAction() !== $action) {
            throw new InvalidInstallTransition('Install actions must complete in their declared order.');
        }

        $this->completedActions[] = $action;
    }

    /**
     * Settle the install as successful.
     *
     * @return  void
     *
     * @throws  InvalidInstallTransition  When the plan is not executing, or any declared action is still due.
     *
     * @since   2.0.0
     */
    public function commit(): void
    {
        $this->requireState(InstallState::Executing);

        if ($this->nextAction() !== null) {
            throw new InvalidInstallTransition('An install cannot commit before every action has completed.');
        }

        $this->state = InstallState::Committed;
    }

    /**
     * Record why the install stopped and leave it awaiting compensation.
     *
     * Failing does not itself undo anything: the plan moves to `Failed` and waits for `beginRollback()`,
     * so the actions in `completedActions()` are still the authoritative list of what needs unwinding.
     *
     * @param   string  $failureCode  Stable machine-readable reason such as `migration.failed`: 3 to 127
     *          characters, opening with a lowercase letter, then lowercase alphanumerics, dots, underscores
     *          or hyphens. Operator-facing prose belongs in the exception, not here.
     *
     * @return  void
     *
     * @throws  InvalidInstallTransition  When the plan is not executing.
     * @throws  InvalidArgumentException  When the failure code is not a stable identifier.
     *
     * @since   2.0.0
     */
    public function fail(string $failureCode): void
    {
        $this->requireState(InstallState::Executing);

        if (preg_match('/^[a-z][a-z0-9._-]{2,126}$/D', $failureCode) !== 1) {
            throw new InvalidArgumentException('An installation failure code must be a stable identifier.');
        }

        $this->failureCode = $failureCode;
        $this->state = InstallState::Failed;
    }

    /**
     * Enter compensation for an install that will not commit.
     *
     * Accepted straight from `Executing` as well as from `Failed`, so an install abandoned without a
     * recorded failure code can still be unwound.
     *
     * @return  void
     *
     * @throws  InvalidInstallTransition  When the plan is neither executing nor failed.
     *
     * @since   2.0.0
     */
    public function beginRollback(): void
    {
        if (!in_array($this->state, [InstallState::Executing, InstallState::Failed], true)) {
            throw new InvalidInstallTransition('Only an executing or failed install can begin rollback.');
        }

        $this->state = InstallState::RollingBack;
    }

    /**
     * Settle the install as fully compensated.
     *
     * @return  void
     *
     * @throws  InvalidInstallTransition  When the plan is not rolling back.
     *
     * @since   2.0.0
     */
    public function finishRollback(): void
    {
        $this->requireState(InstallState::RollingBack);
        $this->state = InstallState::RolledBack;
    }

    /**
     * List the actions this install has already carried out.
     *
     * @return  list<InstallAction>  The completed actions in completion order, which is always a prefix of the
     *          declared sequence; empty before the first `complete()` call.
     *
     * @since   2.0.0
     */
    public function completedActions(): array
    {
        return $this->completedActions;
    }

    /**
     * Return the reason recorded for a failed install.
     *
     * @return  ?string  The code passed to `fail()`, or null when the install has not failed; it survives
     *          rollback, so a rolled-back plan still reports what went wrong.
     *
     * @since   2.0.0
     */
    public function failureCode(): ?string
    {
        return $this->failureCode;
    }

    /**
     * Assert the plan is in the state the caller's transition is only legal from.
     *
     * @param   InstallState  $required  State the transition demands.
     *
     * @return  void
     *
     * @throws  InvalidInstallTransition  When the plan is in any other state; the message names both.
     *
     * @since   2.0.0
     */
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
