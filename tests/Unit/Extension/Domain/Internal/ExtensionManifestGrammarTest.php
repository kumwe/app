<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Extension\Domain\Internal;

use InvalidArgumentException;
use Kumwe\App\Extension\Domain\Internal\ExtensionManifestGrammar;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExtensionManifestGrammar::class)]
/**
 * Proves each manifest generation resolves to the one closed grammar used by parser and retained record.
 *
 * @since  2.0.0
 */
final class ExtensionManifestGrammarTest extends TestCase
{
    /**
     * Require strict root keys to stay constant while nested contribution sets grow only by generation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStrictGenerationKeySetsAreExplicitAndAdditive(): void
    {
        $root = [
            'schema',
            'name',
            'type',
            'version',
            'provider',
            'requires',
            'dependencies',
            'autoload',
            'migrations',
            'configuration',
            'permissions',
            'routes',
            'events',
            'assets',
            'contributions',
            'template',
        ];
        self::assertSame($root, ExtensionManifestGrammar::manifestKeys(2));
        self::assertSame($root, ExtensionManifestGrammar::manifestKeys(5));

        self::assertSame(
            ['version', 'capabilities', 'resource_policies', 'administrator', 'portal', 'business'],
            ExtensionManifestGrammar::contributionKeys(2),
        );
        self::assertSame(
            [
                'version',
                'capabilities',
                'resource_policies',
                'administrator',
                'portal',
                'business',
                'integration',
                'interface',
                'content',
            ],
            ExtensionManifestGrammar::contributionKeys(4),
        );
        self::assertSame(
            [
                'version',
                'capabilities',
                'resource_policies',
                'administrator',
                'portal',
                'business',
                'integration',
                'interface',
                'content',
                'composition',
            ],
            ExtensionManifestGrammar::contributionKeys(5),
        );
        self::assertSame(['field_types', 'definitions'], ExtensionManifestGrammar::businessKeys(2));
        self::assertSame(
            ['field_types', 'definitions', 'field_presentations', 'view_handlers', 'action_handlers'],
            ExtensionManifestGrammar::businessKeys(3),
        );
        self::assertSame([], ExtensionManifestGrammar::integrationKeys(3));
        self::assertSame([], ExtensionManifestGrammar::contentKeys(3));
        self::assertSame([], ExtensionManifestGrammar::compositionKeys(4));
        self::assertSame(
            [
                'event_schemas',
                'domain_listeners',
                'consumers',
                'jobs',
                'queues',
                'schedules',
                'projections',
                'reports',
                'webhooks',
                'rate_providers',
                'unit_converters',
            ],
            ExtensionManifestGrammar::integrationKeys(4),
        );
        self::assertSame(['translation_groups'], ExtensionManifestGrammar::contentKeys(4));
        self::assertSame(
            ['blocks', 'patterns', 'field_controls', 'inspectors', 'design_vocabularies', 'migrations'],
            ExtensionManifestGrammar::compositionKeys(5),
        );
    }

    /**
     * Require legacy schema 1 to retain its deliberately open root grammar.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSchemaOneHasNoClosedRootKeySet(): void
    {
        self::assertSame([], ExtensionManifestGrammar::manifestKeys(1));
    }

    /**
     * Require schema 1 to be refused where typed contribution keys are requested.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSchemaOneCannotPretendToHaveTypedContributions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/manifest schema 2 or later/');

        ExtensionManifestGrammar::contributionKeys(1);
    }

    /**
     * Require an undeclared future schema to fail before any grammar is inferred for it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnknownSchemaIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/schema is unsupported/');

        ExtensionManifestGrammar::manifestKeys(7);
    }
}
