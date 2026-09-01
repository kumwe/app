<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Contribution;

use Kumwe\App\Extension\Contribution\CoreExtensionContributions;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CoreExtensionContributions::class)]
/**
 * Proves the shipped core contribution set registers Studio documents beside its classic surfaces.
 *
 * @since  2.0.0
 */
final class CoreContributionActivationTest extends TestCase
{
    /**
     * Prove core activation contributes canonical Studio documents, bindings and capabilities.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCoreActivationContributesCanonicalStudioDocuments(): void
    {
        $registries = new ExtensionContributionRegistrySet();
        $core = ContributionOwner::core();

        $documents = $registries->canonicalCompositionDocuments()->ownedBy($core);
        self::assertNotSame([], $documents);
        $identities = array_column($documents, 'kind');
        self::assertContains('block-definition', $identities);
        self::assertNotSame([], $registries->compositionHostBindings()->ownedBy($core));
        self::assertNotSame([], $registries->fieldTypes()->all());
        self::assertTrue($registries->capabilities()->isOwnedBy('extensions.manage', $core));
    }
}
