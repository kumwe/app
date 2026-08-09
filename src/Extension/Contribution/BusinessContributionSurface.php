<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use Closure;
use Kumwe\CMS\BusinessDefinition\Application\BusinessDefinitionContributionRegistry;
use Kumwe\CMS\BusinessDefinition\Application\FieldTypeRegistry;
use Kumwe\CMS\BusinessDefinition\Domain\DefinitionOwner;

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
     * @param  Closure(DefinitionOwner): list<mixed>  $read    Lists that registry's entries for one owner.
     * @param  Closure(DefinitionOwner): void         $delete  Withdraws that registry's entries for one owner.
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
            static fn (DefinitionOwner $owner): array => $registry->ownedBy($owner),
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
            static fn (DefinitionOwner $owner): array => $registry->ownedBy($owner),
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
     * @return  list<mixed>  Definition objects exactly as the wrapped registry returns them, not array exports.
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
