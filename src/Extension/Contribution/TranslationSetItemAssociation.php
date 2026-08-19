<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/**
 * The versioned claim that one stored content item belongs to a package's declared translation set.
 *
 * `TranslationGroupDeclaration` is admission metadata: it says which sets a package will publish and in
 * which languages, and it is signed before any of the package's code runs. What it deliberately does
 * not carry is a runtime item identifier, because content entries only come into existence after
 * install. This type is the other half — the additive generation-one contract a package hands to
 * `ContentService::translateContributed()` to place one of its stored entries into a declared set. It
 * is a new type beside the frozen declaration rather than a reinterpretation of it, so every package
 * admitted against the declaration-only contract keeps exactly the bytes and behaviour it was admitted
 * with.
 *
 * The association is deliberately closed at both ends. It names its owner and the declared set, and the
 * set identifier must sit inside that owner's namespace, so a package cannot claim another package's
 * set by spelling its identifier. Core then resolves the pair against the active contribution registry
 * before anything is stored, which is where an undeclared set, a withdrawn package or an undeclared
 * locale is refused.
 *
 * The runtime translation group is derived rather than allocated: one name-based UUID per generation,
 * site, owner and set. That derivation is part of the frozen generation-one promise — the same
 * association resolves to the same group across requests, restarts and reinstalls, which is what makes
 * the stored `translation_group_id` on each entry a durable link back to the declaring package without
 * a second storage surface. A future generation may derive differently; generation one may not change.
 *
 * @since  2.0.0
 */
final readonly class TranslationSetItemAssociation
{
    /**
     * The one association-contract generation this build of core implements.
     *
     * Carried explicitly on every association so a package compiled against a later generation is
     * refused outright instead of being resolved under rules it did not write against.
     *
     * @var    int
     * @since  2.0.0
     */
    public const int GENERATION = 1;

    /**
     * Name-based UUID namespace the generation-one group derivation hashes under.
     *
     * Fixed forever for generation one: changing it would silently detach every stored entry from the
     * set its package associated it with.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string GROUP_NAMESPACE = '018f22e2-7c8b-7ab0-8f3a-88e8026bc101';

    /**
     * Package the associated item belongs to, whose namespace bounds the set it may name.
     *
     * @var    ContributionOwner
     * @since  2.0.0
     */
    public ContributionOwner $owner;

    /**
     * Declared translation-set identifier the item is associated with, inside the owner's namespace.
     *
     * @var    string
     * @since  2.0.0
     */
    public string $translationSet;

    /**
     * Contract generation this association was written against.
     *
     * @var    int
     * @since  2.0.0
     */
    public int $generation;

    /**
     * Associate the declaring package with one of its declared translation sets.
     *
     * @param   string  $owner           Package identifier in `vendor/name` form; `core` is refused
     *          because core content declares no set and uses the plain translation path.
     * @param   string  $translationSet  Declared set identifier, inside that package's namespace and in
     *          the declaration's own identifier grammar.
     * @param   int     $generation      Association-contract generation; only `GENERATION` is implemented.
     *
     * @throws  InvalidArgumentException  When the generation is unsupported, the owner is not a package
     *          identifier, or the set identifier is malformed or outside the owner's namespace.
     *
     * @since   2.0.0
     */
    public function __construct(string $owner, string $translationSet, int $generation = self::GENERATION)
    {
        if ($generation !== self::GENERATION) {
            throw new InvalidArgumentException(sprintf(
                'Content translation association generation %d is not supported by this contract.',
                $generation,
            ));
        }
        $this->generation = $generation;
        $this->owner = ContributionOwner::extension($owner);
        if (preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+){1,15}$/D', $translationSet) !== 1) {
            throw new InvalidArgumentException('A content translation set identifier must be namespaced.');
        }
        $this->owner->assertOwns($translationSet, 'content translation group');
        $this->translationSet = $translationSet;
    }

    /**
     * Derive the runtime translation group this association resolves to within one site.
     *
     * @param   string  $siteIdentifier  Site whose content the associated entries belong to.
     *
     * @return  string  Deterministic UUID of the logical item's group in that site.
     *
     * @throws  InvalidArgumentException  When the site identifier is empty.
     *
     * @since   2.0.0
     */
    public function groupIdForSite(string $siteIdentifier): string
    {
        if ($siteIdentifier === '') {
            throw new InvalidArgumentException('A content translation association needs a site identifier.');
        }

        return Uuid::uuid5(self::GROUP_NAMESPACE, sprintf(
            '%d|%s|%s|%s',
            $this->generation,
            $siteIdentifier,
            $this->owner->identifier(),
            $this->translationSet,
        ))->toString();
    }

    /**
     * Export the association for the audit trail and diagnostics.
     *
     * @return  array{generation: int, owner: string, translation_set: string}  Canonical association.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'generation' => $this->generation,
            'owner' => $this->owner->identifier(),
            'translation_set' => $this->translationSet,
        ];
    }
}
