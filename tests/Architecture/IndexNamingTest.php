<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use Kumwe\App\Infrastructure\Persistence\Migration\ConstraintNameIsolationMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\IndexNameIsolationMigration;
use Kumwe\App\Infrastructure\Persistence\Migration\NumberSequenceIdentityMigration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Audits every non-primary index the shipped migrations declare, so the rename cannot fall behind them.
 *
 * A non-primary index name is schema-global on PostgreSQL, which is why `IndexNameIsolationMigration`
 * renames the literal ones at the end of the plan. That slot is also the audit's boundary: a migration
 * appended after it runs once the rename has already been recorded, so a literal index name it declared
 * would reintroduce the collision for every fresh pair of installations. The repair therefore remains
 * complete only while the literal inventory is frozen, and this reads the migration tree directly to
 * hold it frozen — a new index must derive its name, through `TableNames::raw()` or a digest.
 *
 * The source text really is the contract here — a name written into a released, self-checksumming
 * migration cannot be changed later — which is why this is a source-shape test rather than a
 * behavioural one. Runtime behavior is proved on real engines by the two index-isolation integration
 * suites.
 *
 * @since  2.0.0
 */
#[CoversClass(IndexNameIsolationMigration::class)]
final class IndexNamingTest extends TestCase
{
    /**
     * Derivations already unique to one installation, which the rename deliberately leaves alone.
     *
     * This is a list rather than a set: several migrations pass a `TableNames::raw()` name or a local
     * variable spelled the same way, and dropping one entry would let another such declaration ship
     * without ever being read.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array KNOWN_DERIVATIONS = [
        "\$this->tables->raw('uniq_audit_anchor_sequence')",
        "\$this->tables->raw('idx_audit_anchor_range')",
        "\$this->tables->raw('uniq_business_number_sequence')",
        '$name',
        "\$this->tables->raw('idx_extension_attestation_sbom')",
        "\$this->tables->raw('uq_message_override_identity')",
        "\$this->tables->raw('idx_message_override_scope')",
        "'idx_interface_preference_' . substr(hash('sha256', \$name), 0, 16)",
        "\$this->indexName('idx_runtime_materialization_generation', 'extension_runtime_materializations')",
        "\$this->indexName('uniq_runtime_retirement_path', 'extension_runtime_retirements')",
        "\$this->indexName('idx_runtime_retirement_ready', 'extension_runtime_retirements')",
        "\$this->indexName('uniq_extension_install_operation', 'extension_install_operations')",
        "\$this->indexName('idx_extension_install_reconcile', 'extension_install_operations')",
        "\$this->tables->raw('uq_posting_period_identity')",
        "\$this->tables->raw('idx_posting_period_range')",
        "\$this->tables->raw('idx_site_group_member_site')",
        "\$this->tables->raw('idx_resource_ownership_group')",
        "\$this->tables->raw('idx_extension_runtime_outbox')",
        '$unique',
    ];

    /**
     * The literal names in the migration tree are exactly the inventory the rename is proved against.
     *
     * A new literal index therefore fails here until it is added to the inventory, at which point the
     * unit suite re-checks it against the portable identifier budget — and because the isolation slot
     * has already run on installed sites, the right resolution is almost always to derive the name
     * instead of extending the inventory.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryLiteralIndexNameIsInTheProvedInventory(): void
    {
        $declarations = self::declarations();
        $literals = array_keys($declarations['literal']);
        sort($literals, SORT_STRING);
        $inventory = array_keys(self::inventory());
        sort($inventory, SORT_STRING);

        self::assertSame($inventory, $literals);
    }

    /**
     * Every declaration that is not a literal is one of the derivations already known to be unique.
     *
     * This is the other half of the audit: a name built by an expression nobody has reviewed would
     * slip past the literal check while still being able to collide.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryDerivedIndexNameIsAKnownUniqueDerivation(): void
    {
        $derived = self::declarations()['derived'];
        sort($derived, SORT_STRING);
        $known = self::KNOWN_DERIVATIONS;
        sort($known, SORT_STRING);

        self::assertSame($known, $derived);
    }

    /**
     * The rename is registered at the end of the plan, after every migration whose names it repairs.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheRenameIsRegisteredAfterEveryMigrationItRepairs(): void
    {
        $container = file_get_contents(dirname(__DIR__, 2) . '/src/Kernel/ContainerFactory.php');
        self::assertIsString($container);

        $isolation = strpos($container, 'new IndexNameIsolationMigration(');
        self::assertNotFalse($isolation);
        foreach (['CoreSchemaMigration', 'BusinessSecurityPortalMigration', 'NumberSequenceIdentityMigration'] as $e) {
            $position = strpos($container, 'new ' . $e . '(');
            self::assertNotFalse($position, $e);
            self::assertGreaterThan($position, $isolation, $e);
        }
        self::assertGreaterThan(NumberSequenceIdentityMigration::ID, IndexNameIsolationMigration::ID);
    }

    /**
     * The audit's own reading of the tree matches the counts the rename was designed against.
     *
     * Stated as a number so a change to the tree that alters the shape of the audit — a declaration
     * written across different lines, say — is visible rather than silently reducing what is checked.
     * The five raw `CREATE INDEX` statements are pinned alongside; each names its index through
     * `TableNames::raw()`, which the derivation list above already accounts for at its guard.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheAuditReadsTheWholeTree(): void
    {
        $declarations = self::declarations();

        self::assertCount(110, $declarations['literal']);
        self::assertCount(19, $declarations['derived']);
        self::assertSame(5, self::rawCreateIndexStatements());
        self::assertSame(
            ConstraintNameIsolationMigration::MAXIMUM_IDENTIFIER_BYTES,
            IndexNameIsolationMigration::MAXIMUM_IDENTIFIER_BYTES,
        );
    }

    /**
     * Read the inventory the unit suite proves the rename against.
     *
     * @return  array<string, string>  Literal index name to the logical table it sits on.
     *
     * @since   2.0.0
     */
    private static function inventory(): array
    {
        /** @var array<string, string> $inventory */
        $inventory = (new ReflectionClass(
            \Kumwe\App\Tests\Unit\Infrastructure\Persistence\Migration\IndexNameIsolationMigrationTest::class,
        ))->getConstant('SHIPPED_LITERALS');

        return $inventory;
    }

    /**
     * Read every index declaration out of the migration tree, split by how it is named.
     *
     * @return  array{literal: array<string, string>, derived: list<string>}  Literal names keyed to where
     *          they are declared, and the naming expressions of the derived declarations.
     *
     * @since   2.0.0
     */
    private static function declarations(): array
    {
        $literal = [];
        $derived = [];
        foreach (self::sources() as $file => $source) {
            $offset = 0;
            while (preg_match('/->add(?:Unique)?Index\(/', $source, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
                $open = $match[0][1] + strlen($match[0][0]) - 1;
                $arguments = self::topLevelArguments(self::callText($source, $open));
                $offset = $open + 1;
                $name = preg_replace('/\s+/', ' ', $arguments[1] ?? '');
                self::assertIsString($name, $file);
                if (preg_match("/^'([a-z0-9_]+)'$/D", $name, $spelled) === 1) {
                    $literal[$spelled[1]] = $file;
                    continue;
                }
                $derived[] = $name;
            }
        }

        return ['literal' => $literal, 'derived' => $derived];
    }

    /**
     * Count the raw SQL statements in the tree that create an index outside a table definition.
     *
     * @return  int  Occurrences of a literal `CREATE INDEX` or `CREATE UNIQUE INDEX` statement.
     *
     * @since   2.0.0
     */
    private static function rawCreateIndexStatements(): int
    {
        $statements = 0;
        foreach (self::sources() as $source) {
            $statements += (int) preg_match_all('/CREATE (?:UNIQUE )?INDEX %s/', $source);
        }

        return $statements;
    }

    /**
     * Read every migration source in the tree, keyed by its file name.
     *
     * @return  array<string, string>  File base name to its source text.
     *
     * @since   2.0.0
     */
    private static function sources(): array
    {
        $directory = dirname(__DIR__, 2) . '/src/Infrastructure/Persistence/Migration';
        $sources = [];
        foreach (glob($directory . '/*.php') ?: [] as $file) {
            $source = file_get_contents($file);
            self::assertIsString($source, $file);
            $sources[basename($file)] = $source;
        }

        return $sources;
    }

    /**
     * Read the balanced argument text of one call, from its opening parenthesis.
     *
     * The scan respects single- and double-quoted strings, including escapes, so a parenthesis inside
     * a name or a message never unbalances the call being read.
     *
     * @param   string  $source  Whole source file text.
     * @param   int     $open    Offset of the call's opening parenthesis.
     *
     * @return  string  Text between the call's outer parentheses.
     *
     * @since   2.0.0
     */
    private static function callText(string $source, int $open): string
    {
        $depth = 0;
        $quote = null;
        $length = strlen($source);
        for ($cursor = $open; $cursor < $length; $cursor++) {
            $character = $source[$cursor];
            if ($quote !== null) {
                if ($character === '\\') {
                    $cursor++;
                    continue;
                }
                if ($character === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($character === "'" || $character === '"') {
                $quote = $character;
                continue;
            }
            if ($character === '(') {
                $depth++;
                continue;
            }
            if ($character === ')' && --$depth === 0) {
                return substr($source, $open + 1, $cursor - $open - 1);
            }
        }

        self::fail('An index declaration closes no parenthesis.');
    }

    /**
     * Split one call's argument text at its top-level commas.
     *
     * @param   string  $text  Argument text between a call's outer parentheses.
     *
     * @return  list<string>  Trimmed top-level arguments, in order.
     *
     * @since   2.0.0
     */
    private static function topLevelArguments(string $text): array
    {
        $arguments = [];
        $current = '';
        $depth = 0;
        $quote = null;
        $length = strlen($text);
        for ($cursor = 0; $cursor < $length; $cursor++) {
            $character = $text[$cursor];
            if ($quote !== null) {
                $current .= $character;
                if ($character === '\\') {
                    $current .= $text[$cursor + 1] ?? '';
                    $cursor++;
                } elseif ($character === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($character === "'" || $character === '"') {
                $quote = $character;
                $current .= $character;
                continue;
            }
            if (str_contains('([{', $character)) {
                $depth++;
            } elseif (str_contains(')]}', $character)) {
                $depth--;
            } elseif ($character === ',' && $depth === 0) {
                $arguments[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $character;
        }
        if (trim($current) !== '') {
            $arguments[] = trim($current);
        }

        return $arguments;
    }
}
