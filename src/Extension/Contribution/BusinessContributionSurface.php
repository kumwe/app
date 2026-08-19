<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

use Closure;
use Kumwe\App\BusinessDefinition\Application\BusinessDefinitionContributionRegistry;
use Kumwe\App\BusinessDefinition\Application\FieldTypeRegistry;
use Kumwe\App\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldTypeDefinition;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessActionContract;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessActionHandlerRegistry;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessViewContract;
use Kumwe\App\BusinessSurface\Application\Custom\CustomBusinessViewHandlerRegistry;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldPresentationContribution;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldPresentationRegistry;

/**
 * Presents a business-definition registry as a contribution surface.
 *
 * The business context owns definitions by DefinitionOwner and must not learn the extension
 * contribution vocabulary, so the translation lives here rather than inverting the dependency
 * between the two contexts.
 *
 * @since  2.0.0
 */
final readonly class BusinessContributionSurface implements ContributionSurface
{
    /**
     * Bind the two operations a contribution surface owes to one business registry.
     *
     * @param  Closure(DefinitionOwner): list<array<string, mixed>>  $read    Lists one owner's export documents.
     * @param  Closure(DefinitionOwner): void                        $delete  Withdraws one owner's entries.
     *
     * @since  2.0.0
     */
    private function __construct(private Closure $read, private Closure $delete)
    {
    }

    /**
     * Expose the field-type registry as a contribution surface.
     *
     * @param   FieldTypeRegistry  $registry  Registry of the field types definitions may reference.
     *
     * @return  self  Surface reading and removing field types for the translated owner.
     *
     * @since   2.0.0
     */
    public static function forFieldTypes(FieldTypeRegistry $registry): self
    {
        return new self(
            static fn (DefinitionOwner $owner): array => array_map(
                static fn (FieldTypeDefinition $definition): array => $definition->toArray(),
                $registry->ownedBy($owner),
            ),
            static function (DefinitionOwner $owner) use ($registry): void {
                $registry->remove($owner);
            },
        );
    }

    /**
     * Expose the contributed business-definition registry as a contribution surface.
     *
     * @param   BusinessDefinitionContributionRegistry  $registry  Registry of contributed entity types.
     *
     * @return  self  Surface reading and removing entity types for the translated owner.
     *
     * @since   2.0.0
     */
    public static function forDefinitions(BusinessDefinitionContributionRegistry $registry): self
    {
        return new self(
            static fn (DefinitionOwner $owner): array => array_map(
                static fn (EntityTypeDefinition $definition): array => $definition->toArray(),
                $registry->ownedBy($owner),
            ),
            static function (DefinitionOwner $owner) use ($registry): void {
                $registry->remove($owner);
            },
        );
    }

    /**
     * Expose safe field presenters as an inventoried and lifecycle-removable contribution surface.
     *
     * Executable presenter objects remain private to the registry; inventory contains only the signed field
     * type and context declaration that was reconciled when the provider registered the implementation.
     *
     * @param   FieldPresentationRegistry  $registry  Owner-aware semantic presenter registry.
     *
     * @return  self  Surface exporting declarations and withdrawing presenter objects by owner.
     *
     * @since   2.0.0
     */
    public static function forFieldPresentations(FieldPresentationRegistry $registry): self
    {
        return new self(
            static fn (DefinitionOwner $owner): array => array_map(
                static fn (FieldPresentationContribution $contribution): array => $contribution->toArray(),
                $registry->ownedBy($owner),
            ),
            static function (DefinitionOwner $owner) use ($registry): void {
                $registry->removeOwner($owner);
            },
        );
    }

    /**
     * Expose custom business view handlers as an inventoried and removable contribution surface.
     *
     * Executable handler objects never enter inventory; only their signed schema contracts do.
     *
     * @param   CustomBusinessViewHandlerRegistry  $registry  Owner-aware validating handler registry.
     *
     * @return  self  Surface exporting signed contracts and withdrawing their handlers by owner.
     *
     * @since   2.0.0
     */
    public static function forCustomViewHandlers(CustomBusinessViewHandlerRegistry $registry): self
    {
        return new self(
            static fn (DefinitionOwner $owner): array => array_map(
                static fn (CustomBusinessViewContract $contract): array => $contract->toArray(),
                $registry->ownedBy($owner),
            ),
            static function (DefinitionOwner $owner) use ($registry): void {
                $registry->remove($owner);
            },
        );
    }

    /**
     * Expose custom business action handlers as an inventoried and removable contribution surface.
     *
     * @param   CustomBusinessActionHandlerRegistry  $registry  Owner-aware validating handler registry.
     *
     * @return  self  Surface exporting signed contracts and withdrawing their handlers by owner.
     *
     * @since   2.0.0
     */
    public static function forCustomActionHandlers(CustomBusinessActionHandlerRegistry $registry): self
    {
        return new self(
            static fn (DefinitionOwner $owner): array => array_map(
                static fn (CustomBusinessActionContract $contract): array => $contract->toArray(),
                $registry->ownedBy($owner),
            ),
            static function (DefinitionOwner $owner) use ($registry): void {
                $registry->remove($owner);
            },
        );
    }

    /**
     * List the wrapped registry's entries for one contribution owner.
     *
     * @param   ContributionOwner  $owner  Contributor asking, named in extension vocabulary.
     *
     * @return  list<array<string, mixed>>  Canonical declaration documents, never live domain or handler objects.
     *
     * @since   2.0.0
     */
    public function ownedBy(ContributionOwner $owner): array
    {
        return ($this->read)(self::translate($owner));
    }

    /**
     * Withdraw the owner's entries from the wrapped registry.
     *
     * @param   ContributionOwner  $owner  Contributor being disabled, uninstalled, or untrusted.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function remove(ContributionOwner $owner): void
    {
        ($this->delete)(self::translate($owner));
    }

    /**
     * Restate an extension contribution owner in the business context's own owner vocabulary.
     *
     * @param   ContributionOwner  $owner  Owner as the extension registries name it.
     *
     * @return  DefinitionOwner  The core definition owner for `core`, otherwise the matching extension owner.
     *
     * @since   2.0.0
     */
    private static function translate(ContributionOwner $owner): DefinitionOwner
    {
        return $owner->identifier() === ContributionOwner::CORE
            ? DefinitionOwner::core()
            : DefinitionOwner::extension($owner->identifier());
    }
}
