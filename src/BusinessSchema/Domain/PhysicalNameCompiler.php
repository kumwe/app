<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSchema\Domain;

use Kumwe\App\Shared\Domain\DatabaseTablePrefix;
use Ramsey\Uuid\Uuid;

/**
 * Turns the logical metadata of a business definition into the physical identifiers its tables install under.
 *
 * Definition handles are long and human-chosen, while a physical identifier has to fit a 63-byte portable
 * budget and stay distinct from every other definition sharing the database. This compiler resolves that by
 * pairing a truncated readable stem with a digest over the full metadata, so a name is short enough to
 * install, recognisable enough to read in a console, and still distinct once the readable part has been cut.
 * Compilation is pure and deterministic, which is what lets `BusinessSchemaExecutor` recompile a definition's
 * blueprint at execution time and demand that it hash identically to the one the plan was approved against.
 * One instance is wired per runtime with the site's configured table prefix, and
 * `CanonicalDefinitionPhysicalSchemaCompiler` is its only caller outside that wiring.
 *
 * @since  2.0.0
 */
final readonly class PhysicalNameCompiler
{
    /**
     * Longest name emitted, being the identifier ceiling `SchemaDocument::assertPhysicalIdentifier()` enforces.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAXIMUM_BYTES = 63;
    /**
     * Hexadecimal characters of the SHA-256 digest every name ends with, leaving 42 bytes for the stem.
     *
     * @var    int
     * @since  2.0.0
     */
    private const DIGEST_HEX_CHARACTERS = 20;

    /**
     * Validated table prefix every compiled name begins with, keeping one site's tables clear of another's.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $prefix;

    /**
     * Bind the compiler to the table prefix its names will be installed under.
     *
     * The prefix is checked here rather than trusted, because it is the one part of a compiled identifier
     * that comes from configuration instead of from validated definition metadata.
     *
     * @param   string  $prefix  Configured table prefix, subject to `DatabaseTablePrefix::isValid()`.
     *
     * @throws  InvalidBusinessSchema  When the prefix is not a canonical, underscore-terminated prefix.
     *
     * @since   2.0.0
     */
    public function __construct(string $prefix = 'kb_')
    {
        if (!DatabaseTablePrefix::isValid($prefix)) {
            throw new InvalidBusinessSchema('The physical schema table prefix is invalid.');
        }
        $this->prefix = $prefix;
    }

    /**
     * Compile the table that stores the records of one entity type.
     *
     * @param   string  $definitionId  UUID of the business definition owning the table.
     * @param   string  $handle        Site-qualified definition handle, such as `site.default.invoice`.
     *
     * @return  string  Prefixed name in the `e` category, fixed by the definition ID and handle together.
     *
     * @throws  InvalidBusinessSchema  When the definition ID is not a UUID, the handle is not a metadata
     *          identifier, or the compiled name is not a portable identifier.
     *
     * @since   2.0.0
     */
    public function entityTable(string $definitionId, string $handle): string
    {
        SchemaDocument::assertUuid($definitionId, 'The entity-table definition ID');
        SchemaDocument::assertIdentifier($handle, 'The entity-table handle');

        return $this->compile('e', [$definitionId, $handle]);
    }

    /**
     * Compile the join table that links two entity types across one relationship.
     *
     * @param   string  $definitionId  UUID of the definition declaring the relationship.
     * @param   string  $handle        Relationship handle as the definition names it.
     *
     * @return  string  Prefixed name in the `r` category, distinct from the `e` name of the same metadata.
     *
     * @throws  InvalidBusinessSchema  When the definition ID is not a UUID, the handle is not a metadata
     *          identifier, or the compiled name is not a portable identifier.
     *
     * @since   2.0.0
     */
    public function relationTable(string $definitionId, string $handle): string
    {
        SchemaDocument::assertUuid($definitionId, 'The relation-table definition ID');
        SchemaDocument::assertIdentifier($handle, 'The relation-table handle');

        return $this->compile('r', [$definitionId, $handle]);
    }

    /**
     * Compile the table that holds the owned line items of one entity type.
     *
     * @param   string  $definitionId  UUID of the definition that owns the lines.
     * @param   string  $handle        Line-collection handle as the owning definition names it.
     *
     * @return  string  Prefixed name in the `l` category, distinct from the `e` name of the same metadata.
     *
     * @throws  InvalidBusinessSchema  When the definition ID is not a UUID, the handle is not a metadata
     *          identifier, or the compiled name is not a portable identifier.
     *
     * @since   2.0.0
     */
    public function lineTable(string $definitionId, string $handle): string
    {
        SchemaDocument::assertUuid($definitionId, 'The line-table definition ID');
        SchemaDocument::assertIdentifier($handle, 'The line-table handle');

        return $this->compile('l', [$definitionId, $handle]);
    }

    /**
     * Compile the column a logical field handle is stored in.
     *
     * Unlike the table methods this takes no definition ID, so the same handle yields the same column name
     * in every table. That is deliberate: a column only has to be unique inside its own table, and a shared
     * name keeps a field recognisable wherever the compiler reuses it.
     *
     * @param   string  $logicalName  Field or control-column handle, such as `field.title` or `record_id`.
     *
     * @return  string  Prefixed name in the `c` category.
     *
     * @throws  InvalidBusinessSchema  When the handle is not a metadata identifier, or the compiled name is
     *          not a portable identifier.
     *
     * @since   2.0.0
     */
    public function column(string $logicalName): string
    {
        SchemaDocument::assertIdentifier($logicalName, 'The column handle');

        return $this->compile('c', [$logicalName]);
    }

    /**
     * Compile the name of an index on a table.
     *
     * The covered columns take part in the digest, so changing which columns an index spans produces a
     * different name. That is what lets the planner treat a respanned index as a drop and a create instead
     * of silently leaving the installed one in place under a name that no longer describes it.
     *
     * @param   string        $tableLogicalName  Handle of the owning table; the schema compiler passes the
     *          compiled table name so the index inherits its definition scope.
     * @param   string        $logicalName       Handle the index is known by within that table.
     * @param   list<string>  $columns           Physical columns the index covers, in index order.
     *
     * @return  string  Prefixed name in the `i` category.
     *
     * @throws  InvalidBusinessSchema  When either handle is not a metadata identifier, a column is neither a
     *          UUID nor a metadata identifier, the two handles and the columns together
     *          exceed 64 parts, or the compiled name is not a portable identifier.
     *
     * @since   2.0.0
     */
    public function index(string $tableLogicalName, string $logicalName, array $columns = []): string
    {
        SchemaDocument::assertIdentifier($tableLogicalName, 'The index table handle');
        SchemaDocument::assertIdentifier($logicalName, 'The index handle');
        $this->assertParts($columns, 'index column');

        return $this->compile('i', [$tableLogicalName, $logicalName, ...$columns]);
    }

    /**
     * Compile the name of a foreign-key constraint on a table.
     *
     * @param   string        $tableLogicalName  Handle of the table the constraint leaves; the schema compiler
     *          passes the compiled table name.
     * @param   string        $logicalName       Handle the constraint is known by within that table.
     * @param   list<string>  $columns           Local physical columns carrying the reference, in constraint order.
     *
     * @return  string  Prefixed name in the `f` category, distinct from the `i` name of the same columns.
     *
     * @throws  InvalidBusinessSchema  When either handle is not a metadata identifier, a column is neither a
     *          UUID nor a metadata identifier, the two handles and the columns together
     *          exceed 64 parts, or the compiled name is not a portable identifier.
     *
     * @since   2.0.0
     */
    public function foreignKey(string $tableLogicalName, string $logicalName, array $columns = []): string
    {
        SchemaDocument::assertIdentifier($tableLogicalName, 'The foreign-key table handle');
        SchemaDocument::assertIdentifier($logicalName, 'The foreign-key handle');
        $this->assertParts($columns, 'foreign-key column');

        return $this->compile('f', [$tableLogicalName, $logicalName, ...$columns]);
    }

    /**
     * Compile any prefixed physical name from a category tag and the metadata that identifies the object.
     *
     * This is the single implementation the six named helpers delegate to, and it is public so a caller
     * naming an object those helpers do not cover can join the same namespace. The digest is taken over the
     * category and the lowercased parts joined by NUL bytes — a byte the accepted grammars cannot contain —
     * so no two part collections share a digest input, and the category keeps a table and an index built
     * from the same metadata apart. Only the readable stem is shortened to reach the budget; the digest is
     * appended whole, so truncation can never make two objects collide.
     *
     * @param   string        $category  Short lowercase tag separating one family of names from another,
     *          such as `e` for entity tables or `i` for indexes.
     * @param   list<string>  $parts     Metadata identifying the object, in the order it should read.
     *
     * @return  string  Prefix, category, a truncated readable stem, and the digest, at most 63 bytes.
     *
     * @throws  InvalidBusinessSchema  When the category is outside its 16-byte lowercase grammar, no parts
     *          are supplied, more than 64 are, a part is neither a UUID nor a metadata
     *          identifier, or the compiled name is not a portable identifier.
     *
     * @since   2.0.0
     */
    public function compile(string $category, array $parts): string
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,15}$/D', $category) !== 1 || $parts === []) {
            throw new InvalidBusinessSchema('A physical name category or metadata part collection is invalid.');
        }
        $this->assertParts($parts, 'physical-name metadata part');
        $canonical = $category . "\0" . implode("\0", array_map(strtolower(...), $parts));
        $digest = substr(hash('sha256', $canonical), 0, self::DIGEST_HEX_CHARACTERS);
        $readable = strtolower(implode('_', $parts));
        $readable = (string) preg_replace('/[^a-z0-9]+/', '_', $readable);
        $readable = trim($readable, '_');
        $stem = $this->prefix . $category . '_' . $readable;
        $maximumStem = self::MAXIMUM_BYTES - strlen($digest) - 1;
        $stem = rtrim(substr($stem, 0, $maximumStem), '_');
        $name = $stem . '_' . $digest;
        SchemaDocument::assertPhysicalIdentifier($name, 'The compiled physical name');

        return $name;
    }

    /**
     * Reject a metadata part collection that is too wide or holds a value outside the accepted grammar.
     *
     * A canonical UUID passes even though it is not a metadata identifier, because definition IDs are what
     * make table names definition scoped in the first place.
     *
     * @param   list<string>  $parts    Parts to inspect.
     * @param   string        $subject  Noun naming the part kind, placed after `The ` in the failure message.
     *
     * @return  void
     *
     * @throws  InvalidBusinessSchema  When more than 64 parts are supplied, or a part is neither a canonical
     *          UUID nor a metadata identifier.
     *
     * @since   2.0.0
     */
    private function assertParts(array $parts, string $subject): void
    {
        if (count($parts) > 64) {
            throw new InvalidBusinessSchema('A physical name has too many metadata parts.');
        }
        foreach ($parts as $part) {
            if (Uuid::isValid($part)) {
                continue;
            }
            SchemaDocument::assertIdentifier($part, 'The ' . $subject);
        }
    }
}
