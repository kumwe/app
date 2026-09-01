<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Runtime;

use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Extension\Runtime\ExtensionRuntimeLoader;
use Kumwe\App\Extension\Runtime\RuntimeCanonicalJson;
use Kumwe\App\Extension\Runtime\RuntimePublicationKeyRing;
use Kumwe\App\Extension\Runtime\VerifiedRuntimePublication;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

#[CoversClass(ExtensionRuntimeLoader::class)]
/**
 * Proves the loader refuses a signed runtime entry whose contribution object lost its member names.
 *
 * @since  2.0.0
 */
final class ExtensionRuntimeLoaderContributionShapeTest extends TestCase
{
    /**
     * Prove a strict entry whose contributions decode without string members never loads.
     *
     * The publication itself verifies — checksums and trust HMAC are genuine — so the refusal under
     * test is the loader's own contribution-shape gate, not the signature check.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAStrictEntryWithMalformedContributionMembersIsRefused(): void
    {
        $keys = new RuntimePublicationKeyRing('runtime-key-1', str_repeat('k', 40));
        $extensions = [[
            'provider' => 'Acme\\Probe\\Provider',
            'root' => 'acme/probe/1.0.0',
            'autoload' => [],
            'type' => 'component',
            'identifier' => 'acme/probe',
            'version' => '1.0.0',
            'artifact_sha256' => str_repeat('a', 64),
            'deployed_tree_sha256' => str_repeat('b', 64),
            'manifest_schema' => 4,
            'contributions' => [['version' => 2]],
        ]];
        $stateChecksum = hash('sha256', RuntimeCanonicalJson::encode($extensions));
        $base = [
            'format' => 'kumwe-extension-map-v3',
            'generation' => 1,
            'state_sha256' => $stateChecksum,
            'action' => 'publish',
            'signing_key_id' => 'runtime-key-1',
            'extensions' => $extensions,
        ];
        $checksum = hash('sha256', RuntimeCanonicalJson::encode($base));
        $loader = new ExtensionRuntimeLoader(
            new VerifiedRuntimePublication($base + [
                'publication_sha256' => $checksum,
                'trust_hmac' => $keys->sign('1:' . $checksum),
            ]),
            '/nonexistent/extension-storage',
            $keys,
            (new ReflectionClass(TrustStore::class))->newInstanceWithoutConstructor(),
            self::executionGate(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Strict runtime contributions are unavailable.');

        $loader->load([], new ExtensionContributionRegistrySet(withCore: false));
    }

    /**
     * Build an always-current execution gate probe.
     *
     * @return  ExtensionExecutionGate  Gate that never fences.
     *
     * @since   2.0.0
     */
    private static function executionGate(): ExtensionExecutionGate
    {
        return new class implements ExtensionExecutionGate {
            /**
             * Report the probe runtime generation as current.
             *
             * @return  bool  Always true.
             *
             * @since   2.0.0
             */
            public function isCurrent(): bool
            {
                return true;
            }

            /**
             * Accept execution under the probe runtime generation.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function assertCurrent(): void
            {
            }
        };
    }
}
