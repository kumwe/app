<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Contribution;

use InvalidArgumentException;
use Kumwe\App\Extension\Contribution\TranslationSetItemAssociation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

#[CoversClass(TranslationSetItemAssociation::class)]
/**
 * Pins what an item association may claim, and that its group derivation is a stable function.
 *
 * The association is the versioned bridge between a signed translation-set declaration and one stored
 * content entry, so its two properties under test are exactly the two the contract stands on: the claim
 * is closed — an owner may only name a set inside its own namespace, under the one generation this core
 * implements — and the runtime group it resolves to is a pure function of generation, site, owner and
 * set, so the same association always finds the same logical item and nothing else's.
 *
 * @since  2.0.0
 */
final class TranslationSetItemAssociationTest extends TestCase
{
    /**
     * Prove a well-formed claim normalises its owner and carries the generation it was written against.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAWellFormedAssociationCarriesItsOwnerSetAndGeneration(): void
    {
        $association = new TranslationSetItemAssociation('  Acme/Blog ', 'acme.blog.articles');

        self::assertSame('acme/blog', $association->owner->identifier());
        self::assertSame('acme.blog.articles', $association->translationSet);
        self::assertSame(TranslationSetItemAssociation::GENERATION, $association->generation);
    }

    /**
     * Prove the derivation is deterministic and separates sites, owners and sets from each other.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheGroupDerivationIsAStableFunctionOfItsFourInputs(): void
    {
        $association = new TranslationSetItemAssociation('acme/blog', 'acme.blog.articles');
        $group = $association->groupIdForSite('default');

        self::assertTrue(Uuid::isValid($group));
        self::assertSame('5', $group[14], 'A derived group is a name-based version-five UUID.');
        self::assertSame($group, (new TranslationSetItemAssociation('acme/blog', 'acme.blog.articles'))
            ->groupIdForSite('default'));
        self::assertNotSame($group, $association->groupIdForSite('second'));
        self::assertNotSame($group, (new TranslationSetItemAssociation('acme/blog', 'acme.blog.pages'))
            ->groupIdForSite('default'));
        self::assertNotSame($group, (new TranslationSetItemAssociation('acme/news', 'acme.news.articles'))
            ->groupIdForSite('default'));
    }

    /**
     * Prove a generation this core does not implement is refused rather than resolved under other rules.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnsupportedGenerationIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('generation 2 is not supported');

        new TranslationSetItemAssociation('acme/blog', 'acme.blog.articles', 2);
    }

    /**
     * Prove core cannot be named as the owning package of a contributed set.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCoreIsNotAPackageAndCannotOwnAnAssociation(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TranslationSetItemAssociation('core', 'core.articles');
    }

    /**
     * Prove a package cannot claim a set spelled inside another package's namespace.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnotherOwnersSetIsRefusedAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot claim content translation group identifier');

        new TranslationSetItemAssociation('rival/pages', 'acme.blog.articles');
    }

    /**
     * Prove a set identifier outside the declaration grammar is refused before any lookup happens.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMalformedSetIdentifierIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be namespaced');

        new TranslationSetItemAssociation('acme/blog', 'acme.blog.Articles');
    }

    /**
     * Prove derivation refuses an empty site instead of quietly hashing one shared group for nowhere.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDerivationRefusesAnEmptySiteIdentifier(): void
    {
        $association = new TranslationSetItemAssociation('acme/blog', 'acme.blog.articles');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a site identifier');

        $association->groupIdForSite('');
    }
}
