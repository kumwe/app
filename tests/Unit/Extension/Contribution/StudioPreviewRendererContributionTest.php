<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Contribution;

use Kumwe\App\Extension\Contribution\StudioPreviewRendererContribution;
use Kumwe\Extension\Manifest\ExtensionIdentifier;
use Kumwe\Extension\Manifest\ManifestContributions;
use Kumwe\Extension\Spi\Contribution\CanonicalCompositionDocument;
use Kumwe\Extension\Spi\Contribution\CanonicalCompositionKind;
use Kumwe\Extension\Spi\Contribution\CompositionHostBinding;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\Producer\Render\BlockCoordinate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StudioPreviewRendererContribution::class)]
/**
 * Proves renderer provenance derives exactly from one signed canonical block and matches only itself.
 *
 * @since  2.0.0
 */
final class StudioPreviewRendererContributionTest extends TestCase
{
    /**
     * Prove one dependency-lock coordinate matches only the exact type, version and revision.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOnlyTheExactBlockCoordinateMatches(): void
    {
        $contribution = self::contribution();

        self::assertSame('kumwe.contract-manifest-six/grid', $contribution->blockType);
        self::assertTrue($contribution->matches($contribution->coordinate()));
        self::assertTrue($contribution->matches(new BlockCoordinate(
            $contribution->blockType,
            $contribution->blockVersion,
            $contribution->blockRevision,
        )));
        self::assertFalse($contribution->matches(new BlockCoordinate(
            $contribution->blockType,
            $contribution->blockVersion,
            'foreign-revision-r9',
        )));
        self::assertFalse($contribution->matches(new BlockCoordinate(
            $contribution->blockType,
            '9.9.9',
            $contribution->blockRevision,
        )));
        self::assertFalse($contribution->matches(new BlockCoordinate(
            'kumwe.contract-manifest-six/other',
            $contribution->blockVersion,
            $contribution->blockRevision,
        )));
    }

    /**
     * Prove the safe export names the signed coordinate and renderer without executable payloads.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheSafeExportCarriesTheSignedProvenance(): void
    {
        $export = self::contribution()->toArray();

        self::assertSame('kumwe.contract-manifest-six/grid', $export['type']);
        self::assertSame('kumwe/contract-manifest-six', $export['owner']);
        self::assertSame('1.0.0', $export['runtime_version']);
        self::assertSame('kumwe.contract-manifest-six/grid-preview', $export['renderer']);
        self::assertNull($export['authoring_capability']);
    }

    /**
     * Derive one renderer contribution from the frozen schema-six generation fixture.
     *
     * @return  StudioPreviewRendererContribution  Exact derived executable provenance.
     *
     * @since   2.0.0
     */
    private static function contribution(): StudioPreviewRendererContribution
    {
        $fixture = json_decode(
            (string) file_get_contents(
                dirname(__DIR__, 4)
                . '/vendor/kumwe/extension-sdk/resources/fixtures/generations/manifest-6/kumwe.json',
            ),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($fixture);
        $manifest = ManifestContributions::fromManifest(
            ExtensionIdentifier::fromString('kumwe/contract-manifest-six'),
            $fixture['contributions'],
            6,
        );
        $document = null;
        foreach ($manifest->canonicalCompositionDocuments() as $candidate) {
            if ($candidate->kind === CanonicalCompositionKind::BlockDefinition) {
                $document = $candidate;
            }
        }
        self::assertInstanceOf(CanonicalCompositionDocument::class, $document);
        $binding = $manifest->compositionHostBinding($document->identifier());
        self::assertInstanceOf(CompositionHostBinding::class, $binding);

        return new StudioPreviewRendererContribution(
            ContributionOwner::extension('kumwe/contract-manifest-six'),
            '1.0.0',
            $document,
            $binding,
        );
    }
}
