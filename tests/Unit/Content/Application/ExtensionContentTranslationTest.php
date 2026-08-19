<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Content\Application;

use InvalidArgumentException;
use Kumwe\App\Content\Domain\TranslationGroup;
use Kumwe\App\Extension\Contribution\TranslationGroupDeclaration;
use Kumwe\App\Extension\Contribution\ContentTranslationRegistrar;
use Kumwe\App\Extension\Contribution\ContributionOwner;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Contribution\ManifestContributionSet;
use Kumwe\App\Extension\Domain\ExtensionIdentifier;
use Kumwe\App\Localization\Domain\LocaleTag;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TranslationGroupDeclaration::class)]
#[UsesClass(ExtensionContributionRegistrySet::class)]
#[UsesClass(ManifestContributionSet::class)]
#[UsesClass(LocaleTag::class)]
/**
 * Pins content translation as an extension contract, not a core-content feature.
 *
 * Decision D12 requires content translation to work for content contributed by extensions, and states
 * that this is the reason none of it can wait for Gate B: a package admitted against a contract with no
 * locale dimension would have to be migrated to gain one. These are the assertions that prove a package
 * reaches the model through the ordinary contribution path, with no core edit.
 *
 * @since  2.0.0
 */
final class ExtensionContentTranslationTest extends TestCase
{
    /**
     * Prove a package declares locale variants through the same registrar it declares everything else with.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnExtensionDeclaresLocaleVariantsThroughTheContributionRegistrar(): void
    {
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $owner = ContributionOwner::extension('acme/blog');
        $declaration = new TranslationGroupDeclaration('acme.blog.articles', ['en-GB', 'af', 'de'], 'en-GB');
        $registrar = $registries->registrar($owner, new ManifestContributionSet(
            $owner,
            contentTranslationGroups: [$declaration],
        ));
        self::assertInstanceOf(ContentTranslationRegistrar::class, $registrar);

        $registrar->contentTranslationGroup($declaration);
        $registrar->complete();

        self::assertSame(
            [['group_id' => 'acme.blog.articles', 'locales' => ['af', 'de', 'en-GB'], 'fallback_locale' => 'en-GB']],
            $registries->inventory($owner)['content']['translation_groups'],
        );
        self::assertTrue($declaration->publishes(LocaleTag::fromString('af')));
        self::assertFalse($declaration->publishes(LocaleTag::fromString('he')));
    }

    /**
     * Prove core ships no contributed content set, so the surface is empty until a package supplies one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCoreContributesNoContentTranslationGroup(): void
    {
        $registries = new ExtensionContributionRegistrySet();

        self::assertSame([], $registries->contentTranslationGroups()->definitions());
    }

    /**
     * Prove a declaration survives the manifest round trip a runtime publication depends on.
     *
     * The compiled runtime map carries the exported set rather than the manifest text, and is re-parsed
     * and compared against the installed manifest before any of the package's code runs, so a declaration
     * that did not round-trip would fail activation rather than misbehave quietly. The second half of the
     * case is the one that matters for existing packages: a manifest declaring no content set exports no
     * `content` section at all, so its bytes are the bytes it was admitted against.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testADeclaredContentSetRoundTripsThroughTheManifest(): void
    {
        $owner = ContributionOwner::extension('acme/blog');
        $declared = new ManifestContributionSet(
            $owner,
            spiVersion: ManifestContributionSet::CURRENT_SPI_VERSION,
            contentTranslationGroups: [
                new TranslationGroupDeclaration('acme.blog.articles', ['de', 'en-GB'], 'en-GB'),
            ],
        );

        $document = $declared->toArray();
        self::assertSame(
            [['group_id' => 'acme.blog.articles', 'locales' => ['de', 'en-GB'], 'fallback_locale' => 'en-GB']],
            $document['content']['translation_groups'] ?? null,
        );
        $parsed = ManifestContributionSet::fromManifest(
            ExtensionIdentifier::fromString('acme/blog'),
            $document,
            4,
        );
        self::assertSame($document, $parsed->toArray());
        self::assertSame('acme.blog.articles', $parsed->contentTranslationGroups()[0]->identifier());

        $bare = new ManifestContributionSet($owner, spiVersion: ManifestContributionSet::CURRENT_SPI_VERSION);
        self::assertArrayNotHasKey('content', $bare->toArray());
    }

    /**
     * Prove a package cannot publish a language it never declared, or claim another package's namespace.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAPackageCannotWidenItsLanguageClaimAfterAdmission(): void
    {
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $owner = ContributionOwner::extension('acme/blog');
        $registrar = $registries->registrar($owner, new ManifestContributionSet(
            $owner,
            contentTranslationGroups: [
                new TranslationGroupDeclaration('acme.blog.articles', ['en-GB', 'de'], 'en-GB'),
            ],
        ));

        try {
            $registrar->contentTranslationGroup(
                new TranslationGroupDeclaration('acme.blog.articles', ['en-GB', 'de', 'he'], 'en-GB'),
            );
            self::fail('A package registered a language set its manifest never declared.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('acme.blog.articles', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $registrar->contentTranslationGroup(
            new TranslationGroupDeclaration('zeta.shop.products', ['en-GB'], 'en-GB'),
        );
    }

    /**
     * Prove withdrawing the package withdraws its content sets in the same sweep as everything else.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRemovingThePackageWithdrawsItsContentTranslationGroups(): void
    {
        $registries = new ExtensionContributionRegistrySet(withCore: false);
        $owner = ContributionOwner::extension('acme/blog');
        $declaration = new TranslationGroupDeclaration('acme.blog.articles', ['en-GB', 'de'], 'en-GB');
        $registrar = $registries->registrar($owner, new ManifestContributionSet(
            $owner,
            contentTranslationGroups: [$declaration],
        ));
        $registrar->contentTranslationGroup($declaration);
        $registrar->complete();

        $registries->remove($owner);

        self::assertSame([], $registries->contentTranslationGroups()->definitions());
    }

    /**
     * Prove a declaration that would leave a reader without a page is refused where it is written.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testADeclarationRefusesAnUnreachableFallbackAndAMalformedTag(): void
    {
        try {
            new TranslationGroupDeclaration('acme.blog.articles', ['en-GB', 'de'], 'af');
            self::fail('A fallback naming a language the package never publishes was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('fallback must be one of its declared locales', $exception->getMessage());
        }

        try {
            new TranslationGroupDeclaration('acme.blog.articles', ['not a locale'], 'not a locale');
            self::fail('A malformed language tag was accepted as a declared locale.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('is not a language tag', $exception->getMessage());
        }

        $this->expectExceptionMessage('A content translation group identifier must be namespaced.');
        new TranslationGroupDeclaration('articles', ['en-GB'], 'en-GB');
    }

    /**
     * Prove a package cannot declare a content set in no language, or in more than the model carries.
     *
     * The locale list is a closed claim an operator reads before installing, and the group behind it
     * holds at most `TranslationGroup::MAXIMUM_MEMBERS` locales, so a claim the content model could
     * never honour is refused in the manifest rather than at the first attempt to store it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAPackageCannotDeclareNoLanguageOrMoreThanTheModelCarries(): void
    {
        try {
            new TranslationGroupDeclaration('acme.blog.articles', [], 'en-GB');
            self::fail('A content set promising no language at all was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('between one and 64 locales', $exception->getMessage());
        }

        $locales = [];
        for ($index = 0; $index <= TranslationGroup::MAXIMUM_MEMBERS; $index++) {
            $locales[] = chr(97 + intdiv($index, 26)) . chr(97 + $index % 26);
        }

        self::assertCount(TranslationGroup::MAXIMUM_MEMBERS + 1, $locales);
        $this->expectExceptionMessage('A content translation group must declare between one and 64 locales.');

        new TranslationGroupDeclaration('acme.blog.articles', $locales, 'aa');
    }

    /**
     * Prove a declaration read back out of a manifest is refused unless it is exactly what it claims.
     *
     * `fromArray()` is the boundary the signed manifest and the compiled runtime map both come back
     * through, so a member that is missing, extra, or of the wrong type fails here — before the
     * package's own code runs — rather than becoming a declaration nobody can reconcile.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAManifestDeclarationIsRefusedUnlessItsMembersAreExactlyWhatTheyClaim(): void
    {
        $declared = ['group_id' => 'acme.blog.articles', 'locales' => ['de', 'en-GB'], 'fallback_locale' => 'en-GB'];

        self::assertSame($declared, TranslationGroupDeclaration::fromArray($declared)->toArray());

        try {
            TranslationGroupDeclaration::fromArray(['group_id' => 'acme.blog.articles', 'locales' => ['en-GB']]);
            self::fail('A declaration missing its fallback locale was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('must carry exactly its members', $exception->getMessage());
        }

        try {
            TranslationGroupDeclaration::fromArray([...$declared, 'reader_default' => 'en-GB']);
            self::fail('A declaration carrying a member the contract does not define was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('must carry exactly its members', $exception->getMessage());
        }

        try {
            TranslationGroupDeclaration::fromArray([...$declared, 'locales' => ['first' => 'en-GB']]);
            self::fail('A declaration whose locales are not a list was accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('member has the wrong type', $exception->getMessage());
        }

        $this->expectExceptionMessage('A content translation group locale must be a string.');
        TranslationGroupDeclaration::fromArray([...$declared, 'locales' => ['en-GB', 42]]);
    }
}
