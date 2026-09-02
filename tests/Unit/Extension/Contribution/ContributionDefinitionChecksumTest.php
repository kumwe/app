<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Contribution;

use InvalidArgumentException;
use Kumwe\App\Application\Authorization\AuthorizationDefinitionLifecycle;
use Kumwe\App\Extension\Contribution\CapabilityDefinition;
use Kumwe\App\Extension\Contribution\ContributionDefinitionChecksum;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves a contribution checksum is a deterministic digest bound to the owner entitled to publish it.
 *
 * @since  2.0.0
 */
#[CoversClass(ContributionDefinitionChecksum::class)]
final class ContributionDefinitionChecksumTest extends TestCase
{
    /**
     * The checksum is a 64-character lowercase hex digest, stable across calls, and refused for a foreign owner.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testContributionChecksumsBindCanonicalDefinitionMetadataToItsOwner(): void
    {
        $owner = ContributionOwner::extension('acme/editor');
        $definition = new CapabilityDefinition(
            'acme.editor.manage',
            'Manage editor',
            'Manage records owned by the editor package.',
            ['site', 'global'],
            false,
            true,
            AuthorizationDefinitionLifecycle::Deprecated,
            3,
        );

        $checksum = ContributionDefinitionChecksum::calculate($owner, $definition);

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $checksum);
        self::assertSame($checksum, ContributionDefinitionChecksum::calculate($owner, $definition));
        $this->expectException(InvalidArgumentException::class);
        ContributionDefinitionChecksum::calculate(ContributionOwner::extension('acme/other'), $definition);
    }

    /**
     * Two declarations differing only in owner or in one metadata field never share a digest.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDistinctOwnersOrMetadataProduceDistinctChecksums(): void
    {
        $definition = new CapabilityDefinition('acme.editor.manage', 'Manage editor', 'Manage the editor.');
        $bumped = new CapabilityDefinition(
            'acme.editor.manage',
            'Manage editor',
            'Manage the editor.',
            version: 2,
        );
        $core = new CapabilityDefinition('content.read', 'Read content', 'Read content records.');

        $extensionDigest = ContributionDefinitionChecksum::calculate(
            ContributionOwner::extension('acme/editor'),
            $definition,
        );

        self::assertNotSame(
            $extensionDigest,
            ContributionDefinitionChecksum::calculate(ContributionOwner::extension('acme/editor'), $bumped),
        );
        self::assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/D',
            ContributionDefinitionChecksum::calculate(ContributionOwner::core(), $core),
        );
    }
}
