<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use InvalidArgumentException;
use LogicException;

/**
 * Owner-aware registry for one declarative integration surface and its optional runtime implementation.
 *
 * The signed definition and executable object are stored as one entry. Inventory exposes only the
 * definition, while lookup may return the implementation after the caller has resolved the same
 * owner. Removing an extension therefore withdraws data and code together without persisting an
 * extension object or exposing another contributor's implementation.
 *
 * @since  2.0.0
 */
final class OwnedRuntimeContributionRegistry implements ContributionSurface
{
    /**
     * Registered definitions and implementations, keyed by globally namespaced identifier.
     *
     * @var    array<string, array{
     *             owner: ContributionOwner,
     *             definition: ContributionDefinition,
     *             implementation: ?object
     *         }>
     * @since  2.0.0
     */
    private array $entries = [];

    /**
     * Describe and optionally type-restrict one contribution surface.
     *
     * @param   string         $kind                Human-readable kind used in ownership failures.
     * @param   ?class-string  $implementationType  Required implementation contract, or null for data-only entries.
     *
     * @throws  InvalidArgumentException  When the kind or implementation contract is invalid.
     *
     * @since   2.0.0
     */
    public function __construct(
        private readonly string $kind,
        private readonly ?string $implementationType = null,
    ) {
        if ($kind === '' || strlen($kind) > 80) {
            throw new InvalidArgumentException('A runtime contribution registry kind is required.');
        }
        if ($implementationType !== null && !interface_exists($implementationType) && !class_exists($implementationType)) {
            throw new InvalidArgumentException('A runtime contribution implementation contract must exist.');
        }
    }

    /**
     * Register one manifest-reconciled definition and its executable implementation.
     *
     * @param   ContributionOwner        $owner           Contributor claiming the definition.
     * @param   ContributionDefinition   $definition      Signed declaration accepted by the owner registrar.
     * @param   ?object                  $implementation  Runtime object, required for executable surfaces.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When ownership, uniqueness, or implementation type is invalid.
     *
     * @since   2.0.0
     */
    public function register(
        ContributionOwner $owner,
        ContributionDefinition $definition,
        ?object $implementation = null,
    ): void {
        $identifier = $definition->identifier();
        $owner->assertOwns($identifier, $this->kind);
        if (isset($this->entries[$identifier])) {
            throw new InvalidArgumentException(sprintf(
                '%s contribution %s is already registered.',
                ucfirst($this->kind),
                $identifier,
            ));
        }
        if ($this->implementationType !== null && !$implementation instanceof $this->implementationType) {
            throw new InvalidArgumentException(sprintf(
                '%s contribution %s requires an implementation of %s.',
                ucfirst($this->kind),
                $identifier,
                $this->implementationType,
            ));
        }
        if ($this->implementationType === null && $implementation !== null) {
            throw new InvalidArgumentException(sprintf(
                '%s contribution %s is declarative and cannot carry an implementation.',
                ucfirst($this->kind),
                $identifier,
            ));
        }

        $this->entries[$identifier] = [
            'owner' => $owner,
            'definition' => $definition,
            'implementation' => $implementation,
        ];
        ksort($this->entries, SORT_STRING);
    }

    /**
     * Resolve an active definition only when the expected owner still holds it.
     *
     * @param   ContributionOwner  $owner       Expected contributor.
     * @param   string             $identifier  Namespaced contribution identifier.
     *
     * @return  ?ContributionDefinition  Definition, or null for an absent or foreign entry.
     *
     * @since   2.0.0
     */
    public function definition(ContributionOwner $owner, string $identifier): ?ContributionDefinition
    {
        $entry = $this->entries[$identifier] ?? null;
        if ($entry === null || $entry['owner']->identifier() !== $owner->identifier()) {
            return null;
        }

        return $entry['definition'];
    }

    /**
     * Resolve executable code only through the exact active owner and definition identifier.
     *
     * @param   ContributionOwner  $owner       Expected contributor.
     * @param   string             $identifier  Namespaced contribution identifier.
     *
     * @return  object  Registered runtime implementation.
     *
     * @throws  LogicException  When the contribution is absent, foreign, or declarative only.
     *
     * @since   2.0.0
     */
    public function implementation(ContributionOwner $owner, string $identifier): object
    {
        $entry = $this->entries[$identifier] ?? null;
        if (
            $entry === null
            || $entry['owner']->identifier() !== $owner->identifier()
            || $entry['implementation'] === null
        ) {
            throw new LogicException(sprintf('%s contribution %s is not active.', ucfirst($this->kind), $identifier));
        }

        return $entry['implementation'];
    }

    /**
     * List every active definition in deterministic identifier order.
     *
     * @return  list<ContributionDefinition>  Active signed declarations.
     *
     * @since   2.0.0
     */
    public function definitions(): array
    {
        return array_values(array_map(
            static fn (array $entry): ContributionDefinition => $entry['definition'],
            $this->entries,
        ));
    }

    /**
     * List every executable entry with its definition and owner in deterministic order.
     *
     * @return  list<array{
     *              owner: ContributionOwner,
     *              definition: ContributionDefinition,
     *              implementation: object
     *          }>  Executable active entries.
     *
     * @since   2.0.0
     */
    public function executableEntries(): array
    {
        $result = [];
        foreach ($this->entries as $entry) {
            if ($entry['implementation'] === null) {
                continue;
            }
            $result[] = [
                'owner' => $entry['owner'],
                'definition' => $entry['definition'],
                'implementation' => $entry['implementation'],
            ];
        }

        return $result;
    }

    /**
     * Export one owner's signed declarations for diagnostics without exposing executable objects.
     *
     * @param   ContributionOwner  $owner  Contributor whose declarations are requested.
     *
     * @return  list<array<string, mixed>>  Canonical definition documents.
     *
     * @since   2.0.0
     */
    public function ownedBy(ContributionOwner $owner): array
    {
        $result = [];
        foreach ($this->entries as $entry) {
            if ($entry['owner']->identifier() === $owner->identifier()) {
                $result[] = $entry['definition']->toArray();
            }
        }

        return $result;
    }

    /**
     * Withdraw every declaration and executable object owned by one contributor.
     *
     * @param   ContributionOwner  $owner  Contributor being disabled, removed, or distrusted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function remove(ContributionOwner $owner): void
    {
        foreach ($this->entries as $identifier => $entry) {
            if ($entry['owner']->identifier() === $owner->identifier()) {
                unset($this->entries[$identifier]);
            }
        }
    }
}
