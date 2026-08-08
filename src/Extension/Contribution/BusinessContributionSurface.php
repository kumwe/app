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
 */
final readonly class BusinessContributionSurface implements ContributionSurface
{
    /**
     * @param Closure(DefinitionOwner): list<mixed> $read
     * @param Closure(DefinitionOwner): void $delete
     */
    private function __construct(private Closure $read, private Closure $delete)
    {
    }

    public static function forFieldTypes(FieldTypeRegistry $registry): self
    {
        return new self(
            static fn (DefinitionOwner $owner): array => $registry->ownedBy($owner),
            static function (DefinitionOwner $owner) use ($registry): void {
                $registry->remove($owner);
            },
        );
    }

    public static function forDefinitions(BusinessDefinitionContributionRegistry $registry): self
    {
        return new self(
            static fn (DefinitionOwner $owner): array => $registry->ownedBy($owner),
            static function (DefinitionOwner $owner) use ($registry): void {
                $registry->remove($owner);
            },
        );
    }

    /** @return list<mixed> */
    public function ownedBy(ContributionOwner $owner): array
    {
        return ($this->read)(self::translate($owner));
    }

    public function remove(ContributionOwner $owner): void
    {
        ($this->delete)(self::translate($owner));
    }

    private static function translate(ContributionOwner $owner): DefinitionOwner
    {
        return $owner->identifier() === ContributionOwner::CORE
            ? DefinitionOwner::core()
            : DefinitionOwner::extension($owner->identifier());
    }
}
