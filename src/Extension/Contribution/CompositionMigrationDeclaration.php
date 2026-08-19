<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

use InvalidArgumentException;

/**
 * A migration a package declares for documents one of its composition blocks appears in.
 *
 * A composition document outlives the block revision it was authored against, so a block that changes
 * shape owes the documents that use it a declared path forward. The migration names the owned block, the
 * revision it steps from and the higher revision it steps to, and a bounded list of operations from a
 * closed vocabulary — rename a property, remove one, or clear one back to absent. The vocabulary is
 * closed for the same reason a property schema is bounded: a migration is data a signed manifest
 * carries, never code, so what it can do to a stored document is fixed at admission rather than
 * discovered at upgrade.
 *
 * Nothing is executed at Gate A. The Gate B runtime replays these declarations over stored documents;
 * declaring them now is what lets a block evolve without stranding the documents it already appears in.
 *
 * @since  2.0.0
 */
final readonly class CompositionMigrationDeclaration implements ContributionDefinition
{
    /**
     * Operations one migration may declare.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int MAXIMUM_OPERATIONS = 32;

    /**
     * The closed vocabulary of operations a declared migration may apply to a stored document.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const array ACTIONS = ['clear', 'remove', 'rename'];

    /**
     * Canonical declared operations, in declaration order.
     *
     * @var    non-empty-list<array<string, string>>
     * @since  2.0.0
     */
    public array $operations;

    /**
     * Declare one migration: the owned block, the revisions it steps between, and its operations.
     *
     * @param   string                       $migrationId  Namespaced identifier inside the declaring
     *          package's namespace.
     * @param   string                       $block        Namespaced identifier of the declared block
     *          whose documents it migrates.
     * @param   int                          $fromVersion  Block revision the migration steps from.
     * @param   int                          $toVersion    Higher block revision it steps to.
     * @param   list<array<string, string>>  $operations   Ordered operations from the closed vocabulary.
     *
     * @throws  InvalidArgumentException  When an identifier is not namespaced, the revisions are not an
     *          ascending positive pair, the operation list is empty or over its bound, or an operation
     *          falls outside the closed vocabulary or carries the wrong members.
     *
     * @since   2.0.0
     */
    public function __construct(
        private string $migrationId,
        private string $block,
        private int $fromVersion,
        private int $toVersion,
        array $operations,
    ) {
        if (preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+){1,15}$/D', $migrationId) !== 1) {
            throw new InvalidArgumentException('A composition migration identifier must be namespaced.');
        }
        if (preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+){1,15}$/D', $block) !== 1) {
            throw new InvalidArgumentException(
                'A composition migration block reference must be a namespaced identifier.',
            );
        }
        if ($fromVersion < 1 || $toVersion <= $fromVersion) {
            throw new InvalidArgumentException(
                'A composition migration must step from a positive revision to a higher one.',
            );
        }
        if ($operations === [] || count($operations) > self::MAXIMUM_OPERATIONS) {
            throw new InvalidArgumentException(
                'A composition migration must declare between one and 32 operations.',
            );
        }
        $canonical = [];
        foreach ($operations as $operation) {
            $canonical[] = $this->operation($operation);
        }
        $this->operations = $canonical;
    }

    /**
     * The identifier this migration is registered and replayed under.
     *
     * @return  string  Namespaced migration identity, inside the declaring package's namespace.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return $this->migrationId;
    }

    /**
     * The declared block whose documents this migration steps forward.
     *
     * @return  string  Namespaced block identifier, declared in the same manifest.
     *
     * @since   2.0.0
     */
    public function block(): string
    {
        return $this->block;
    }

    /**
     * The block revision this migration steps from.
     *
     * @return  int  Positive revision lower than the target.
     *
     * @since   2.0.0
     */
    public function fromVersion(): int
    {
        return $this->fromVersion;
    }

    /**
     * The block revision this migration steps to.
     *
     * @return  int  Revision higher than the source and never past the block's declared version.
     *
     * @since   2.0.0
     */
    public function toVersion(): int
    {
        return $this->toVersion;
    }

    /**
     * Serialize the declaration for the signed manifest, the runtime publication, and inventory.
     *
     * @return  array{
     *              migration_id: string,
     *              block: string,
     *              from_version: int,
     *              to_version: int,
     *              operations: non-empty-list<array<string, string>>
     *          }  Canonical declaration.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'migration_id' => $this->migrationId,
            'block' => $this->block,
            'from_version' => $this->fromVersion,
            'to_version' => $this->toVersion,
            'operations' => $this->operations,
        ];
    }

    /**
     * Reconstitute the declaration from validated manifest data.
     *
     * @param   array<string, mixed>  $data  Declaration as `toArray()` produced it.
     *
     * @return  self  Validated composition migration declaration.
     *
     * @throws  InvalidArgumentException  When a member is missing, extra, or mistyped, or an operation is
     *          not an object of strings.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $data): self
    {
        $expected = ['block', 'from_version', 'migration_id', 'operations', 'to_version'];
        $declared = array_keys($data);
        sort($declared, SORT_STRING);
        if ($declared !== $expected) {
            throw new InvalidArgumentException(
                'A composition migration declaration must carry exactly its members.',
            );
        }
        $migrationId = $data['migration_id'];
        $block = $data['block'];
        $fromVersion = $data['from_version'];
        $toVersion = $data['to_version'];
        $operations = $data['operations'];
        if (
            !is_string($migrationId)
            || !is_string($block)
            || !is_int($fromVersion)
            || !is_int($toVersion)
            || !is_array($operations)
            || !array_is_list($operations)
        ) {
            throw new InvalidArgumentException('A composition migration declaration member has the wrong type.');
        }
        $entries = [];
        foreach ($operations as $operation) {
            if (!is_array($operation) || array_is_list($operation)) {
                throw new InvalidArgumentException('A composition migration operation must be an object.');
            }
            $entry = [];
            foreach ($operation as $member => $value) {
                if (!is_string($value)) {
                    throw new InvalidArgumentException(
                        'A composition migration operation member must be a string.',
                    );
                }
                $entry[(string) $member] = $value;
            }
            $entries[] = $entry;
        }

        return new self($migrationId, $block, $fromVersion, $toVersion, $entries);
    }

    /**
     * Hold one operation to the closed vocabulary and the exact members its action requires.
     *
     * @param   array<string, string>  $operation  Declared operation object.
     *
     * @return  array<string, string>  The operation with its members in canonical order.
     *
     * @throws  InvalidArgumentException  When the action is unknown, the member set is wrong, a property
     *          name is malformed, or a rename does not change the name.
     *
     * @since   2.0.0
     */
    private function operation(array $operation): array
    {
        $action = $operation['action'] ?? null;
        if (!is_string($action) || !in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException(
                'A composition migration operation must use the closed action vocabulary.',
            );
        }
        $expected = $action === 'rename' ? ['action', 'property', 'to'] : ['action', 'property'];
        $declared = array_keys($operation);
        sort($declared, SORT_STRING);
        if ($declared !== $expected) {
            throw new InvalidArgumentException(
                'A composition migration operation must carry exactly the members its action requires.',
            );
        }
        $property = $operation['property'] ?? '';
        if (preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/D', $property) !== 1 || strlen($property) > 64) {
            throw new InvalidArgumentException(
                'A composition migration operation property must be a bounded lowercase identifier.',
            );
        }
        if ($action !== 'rename') {
            return ['action' => $action, 'property' => $property];
        }
        $to = $operation['to'] ?? '';
        if (preg_match('/^[a-z][a-z0-9]*(?:_[a-z0-9]+)*$/D', $to) !== 1 || strlen($to) > 64 || $to === $property) {
            throw new InvalidArgumentException(
                'A composition migration rename must move a property to a different bounded name.',
            );
        }

        return ['action' => $action, 'property' => $property, 'to' => $to];
    }
}
