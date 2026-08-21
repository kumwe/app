<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Domain\Internal;

use InvalidArgumentException;

/**
 * Single executable description of the manifest grammar selected by each frozen schema generation.
 *
 * The parser and the retained generation verifier both consume this class. Keeping the key sets here
 * prevents a parser change from silently widening an already-frozen generation while its machine record
 * continues to describe the older shape. This is an internal contract-control type; extension packages
 * consume the validated manifest and contribution APIs, never the parser's key inventory.
 *
 * @internal
 * @since  2.0.0
 */
final class ExtensionManifestGrammar
{
    /**
     * Return the root keys a strict manifest schema accepts.
     *
     * Schema 1 is deliberately open to unknown keys and therefore has no closed accepted-key set.
     *
     * @param   int  $schema  Manifest schema generation.
     *
     * @return  list<string>  Closed root key set for schemas 2 through 5; empty for schema 1.
     *
     * @since   2.0.0
     */
    public static function manifestKeys(int $schema): array
    {
        self::assertSchema($schema);

        if ($schema === 1) {
            return [];
        }

        return [
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
    }

    /**
     * Return the keys accepted at the root of a strict contribution declaration.
     *
     * @param   int  $schema  Manifest schema generation.
     *
     * @return  list<string>  Closed contribution key set.
     *
     * @since   2.0.0
     */
    public static function contributionKeys(int $schema): array
    {
        self::assertStrictSchema($schema);

        $keys = ['version', 'capabilities', 'resource_policies', 'administrator', 'portal', 'business'];
        if ($schema >= 4) {
            $keys[] = 'integration';
            $keys[] = 'interface';
            $keys[] = 'content';
        }
        if ($schema >= 5) {
            $keys[] = 'composition';
        }

        return $keys;
    }

    /**
     * Return the keys accepted by the business contribution section.
     *
     * @param   int  $schema  Manifest schema generation.
     *
     * @return  list<string>  Closed business key set.
     *
     * @since   2.0.0
     */
    public static function businessKeys(int $schema): array
    {
        self::assertStrictSchema($schema);

        $keys = ['field_types', 'definitions'];
        if ($schema >= 3) {
            $keys[] = 'field_presentations';
            $keys[] = 'view_handlers';
            $keys[] = 'action_handlers';
        }

        return $keys;
    }

    /**
     * Return the keys accepted by the durable-integration contribution section.
     *
     * @param   int  $schema  Manifest schema generation.
     *
     * @return  list<string>  Closed integration key set, empty before schema 4.
     *
     * @since   2.0.0
     */
    public static function integrationKeys(int $schema): array
    {
        self::assertStrictSchema($schema);

        if ($schema < 4) {
            return [];
        }

        return [
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
        ];
    }

    /**
     * Return the keys accepted by the multilingual-content contribution section.
     *
     * @param   int  $schema  Manifest schema generation.
     *
     * @return  list<string>  Closed content key set, empty before schema 4.
     *
     * @since   2.0.0
     */
    public static function contentKeys(int $schema): array
    {
        self::assertStrictSchema($schema);

        return $schema >= 4 ? ['translation_groups'] : [];
    }

    /**
     * Return the keys accepted by the declarative-composition contribution section.
     *
     * @param   int  $schema  Manifest schema generation.
     *
     * @return  list<string>  Closed composition key set, empty before schema 5.
     *
     * @since   2.0.0
     */
    public static function compositionKeys(int $schema): array
    {
        self::assertStrictSchema($schema);

        return $schema >= 5
            ? ['blocks', 'patterns', 'field_controls', 'inspectors', 'design_vocabularies', 'migrations']
            : [];
    }

    /**
     * Refuse an unknown manifest schema before a caller derives a grammar from it.
     *
     * @param   int  $schema  Candidate schema generation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertSchema(int $schema): void
    {
        if (!in_array($schema, [1, 2, 3, 4, 5], true)) {
            throw new InvalidArgumentException('The extension manifest schema is unsupported.');
        }
    }

    /**
     * Refuse schema 1 where a typed-contribution grammar is required.
     *
     * @param   int  $schema  Candidate strict schema generation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertStrictSchema(int $schema): void
    {
        self::assertSchema($schema);
        if ($schema === 1) {
            throw new InvalidArgumentException('Typed extension contributions require manifest schema 2 or later.');
        }
    }
}
