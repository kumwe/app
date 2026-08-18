<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Architecture;

use Kumwe\CMS\Infrastructure\Persistence\Migration\ConstraintNameIsolationMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\ConstraintNameIsolationCompatibilityMigration;
use Kumwe\CMS\Infrastructure\Persistence\Migration\ConstraintNameIsolationPortabilityMigration;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Audits every foreign key the shipped migrations declare, so the rename cannot fall behind them.
 *
 * A foreign-key constraint name is schema-global on MySQL and MariaDB, which is why
 * The same-ID compatibility migration renames the literal ones safely for pending databases, while the
 * append-only portability follow-up repairs databases that already recorded the published checksum. The
 * repair remains complete only while its proved inventory matches the source, so this reads the migration
 * tree directly.
 *
 * The source text really is the contract here — a name written into a released, self-checksumming
 * migration cannot be changed later — which is why this is a source-shape test rather than a behavioural
 * one. Runtime behavior is proved on real engines by the two constraint-isolation integration suites.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class ForeignKeyConstraintNamingTest extends TestCase
{
    /**
     * Derivations already unique to one installation, which the rename deliberately leaves alone.
     *
     * This is a list rather than a set, and `$foreignKey` appearing four times is not a duplicate to
     * tidy away: four migrations hash their own prefixed table into a variable spelled that way, and
     * dropping one entry would let another such declaration ship without ever being read.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array KNOWN_DERIVATIONS = [
        "'fk_resource_site_' . substr(hash('sha256', \$ownershipName), 0, 16)",
        '$foreignKey',
        "'fk_theme_activation_' . substr(hash('sha256', \$this->tables->raw('theme_activations')), 0, 16)",
        "'fk_site_theme_activation_' . substr(hash('sha256', "
            . "\$this->tables->raw('site_theme_activations')), 0, 16)",
        '$target',
        '$foreignKey',
        '$foreignKey',
        '$foreignKey',
        "\$this->tables->raw('fk_site_group_member_group')",
        "\$this->tables->raw('fk_site_group_member_site')",
        "\$this->tables->raw('fk_resource_ownership_group')",
        "\$this->tables->raw('fk_message_override_site')",
    ];

    /**
     * The literal names in the migration tree are exactly the inventory the rename is proved against.
     *
     * A new literal constraint therefore fails here until it is added to the inventory, at which point
     * the unit suite re-checks it against the portable identifier budget. That is the loop that keeps
     * a fifty-fifth literal from shipping unproved.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryLiteralConstraintNameIsInTheProvedInventory(): void
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
     * This is the other half of the audit: a name built by an expression nobody has reviewed would slip
     * past the literal check while still being able to collide.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryDerivedConstraintNameIsAKnownUniqueDerivation(): void
    {
        $derived = self::declarations()['derived'];
        sort($derived, SORT_STRING);
        $known = self::KNOWN_DERIVATIONS;
        sort($known, SORT_STRING);

        self::assertSame($known, $derived);
    }

    /**
     * The safe compatibility slot and its append-only follow-up are registered in protocol order.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheRenameAndFollowUpAreRegisteredInProtocolOrder(): void
    {
        $container = file_get_contents(dirname(__DIR__, 2) . '/src/Kernel/ContainerFactory.php');
        self::assertIsString($container);

        $isolation = strpos($container, 'new ConstraintNameIsolationCompatibilityMigration(');
        self::assertNotFalse($isolation);
        self::assertStringNotContainsString('new ConstraintNameIsolationMigration(', $container);
        $repaired = ['CoreSchemaMigration', 'BusinessSecurityPortalMigration', 'ResourceOwnershipScopeMigration'];
        foreach ($repaired as $earlier) {
            $position = strpos($container, 'new ' . $earlier . '(');
            self::assertNotFalse($position, $earlier);
            self::assertGreaterThan($position, $isolation, $earlier);
        }

        $portability = strpos($container, 'new ConstraintNameIsolationPortabilityMigration(');
        self::assertNotFalse($portability);
        self::assertGreaterThan($isolation, $portability);
        $translationOwnership = strpos($container, 'new TranslationGroupSiteOwnershipMigration(');
        self::assertNotFalse($translationOwnership);
        self::assertGreaterThan($translationOwnership, $portability);
    }

    /**
     * Read the inventory the unit suite proves the rename against.
     *
     * @return  array<string, string>  Literal constraint name to the logical table it sits on.
     *
     * @since   2.0.0
     */
    private static function inventory(): array
    {
        /** @var array<string, string> $inventory */
        $inventory = (new ReflectionClass(
            \Kumwe\CMS\Tests\Unit\Infrastructure\Persistence\Migration\ConstraintNameIsolationMigrationTest::class,
        ))->getConstant('SHIPPED_LITERALS');

        return $inventory;
    }

    /**
     * Read every foreign-key declaration out of the migration tree, split by how it is named.
     *
     * @return  array{literal: array<string, string>, derived: list<string>}  Literal names keyed to where
     *          they are declared, and the naming expressions of the derived declarations.
     *
     * @since   2.0.0
     */
    private static function declarations(): array
    {
        $directory = dirname(__DIR__, 2) . '/src/Infrastructure/Persistence/Migration';
        $literal = [];
        $derived = [];
        foreach (glob($directory . '/*.php') ?: [] as $file) {
            $source = file_get_contents($file);
            self::assertIsString($source, $file);
            $lines = explode("\n", $source);
            foreach ($lines as $index => $line) {
                if (!str_contains($line, 'addForeignKeyConstraint')) {
                    continue;
                }
                $name = self::declaredName($lines, $index);
                if (preg_match("/^'([a-z0-9_]+)'$/D", $name, $matches) === 1) {
                    $literal[$matches[1]] = basename($file);
                    continue;
                }
                $derived[] = $name;
            }
        }

        return ['literal' => $literal, 'derived' => $derived];
    }

    /**
     * Read the name argument of one `addForeignKeyConstraint()` call, which is always its last.
     *
     * @param   list<string>  $lines  Whole source file, split into lines.
     * @param   int           $start  Index of the line the call opens on.
     *
     * @return  string  Source text of the name argument, with its trailing comma removed.
     *
     * @since   2.0.0
     */
    private static function declaredName(array $lines, int $start): string
    {
        $depth = 0;
        $block = [];
        for ($cursor = $start; $cursor < count($lines); $cursor++) {
            $block[] = trim($lines[$cursor]);
            $depth += substr_count($lines[$cursor], '(') - substr_count($lines[$cursor], ')');
            if ($cursor > $start && $depth <= 0) {
                break;
            }
        }
        $block = array_values(array_filter($block, static fn (string $line): bool => $line !== ''));

        return rtrim($block[count($block) - 2] ?? '', ',');
    }

    /**
     * The audit's own reading of the tree matches the counts the rename was designed against.
     *
     * Stated as a number so a change to the tree that alters the shape of the audit — a declaration
     * written across different lines, say — is visible rather than silently reducing what is checked.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheAuditReadsTheWholeTree(): void
    {
        $declarations = self::declarations();

        self::assertCount(54, $declarations['literal']);
        self::assertCount(12, $declarations['derived']);
        self::assertSame(63, ConstraintNameIsolationMigration::MAXIMUM_IDENTIFIER_BYTES);
        self::assertSame(ConstraintNameIsolationMigration::ID, ConstraintNameIsolationCompatibilityMigration::ID);
        self::assertSame(
            ConstraintNameIsolationMigration::MAXIMUM_IDENTIFIER_BYTES,
            ConstraintNameIsolationPortabilityMigration::MAXIMUM_IDENTIFIER_BYTES,
        );
    }
}
