<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Migration;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Kumwe\CMS\Extension\Domain\ExtensionIdentifier;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;

/**
 * Compiles the physical names of the tables one extension owns, so a migration never spells one itself.
 *
 * The caller supplies only the bare name; this class prepends an `ext_` marker and a namespace derived
 * from the extension identifier, and `TableNames` prepends the site's configured prefix. Because the
 * migration never supplies those leading segments, it cannot reach a core table or another package's,
 * whatever it asks for. The bare name is checked as a lowercase identifier here and the composed name is
 * checked again by `TableNames`, so neither package nor operator input reaches the SQL grammar.
 *
 * `ExtensionMigrationRunner` constructs one per extension and hands it to `up()` and `down()`, which is
 * where migrations get their names from.
 *
 * @since  2.0.0
 */
final readonly class ExtensionTableNames
{
    /**
     * Extension identifier flattened into an identifier-safe segment that separates one package's tables.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $namespace;

    /**
     * Bind the compiler to one extension on one site.
     *
     * @param  Connection           $database   Connection whose platform supplies the identifier quoting
     *         rules `quoted()` applies.
     * @param  TableNames           $tables     Core name compiler that adds the site's table prefix and
     *         enforces the portable 63-byte limit on the finished name.
     * @param  ExtensionIdentifier  $extension  Owning extension; its `vendor/name` value becomes the
     *         namespace segment, with `/`, `.` and `-` folded to underscores.
     *
     * @since  2.0.0
     */
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        ExtensionIdentifier $extension,
    ) {
        $this->namespace = str_replace(['/', '.', '-'], '_', $extension->value());
    }

    /**
     * Compile the unquoted physical name of a table this extension owns.
     *
     * This is the form the Doctrine schema manager expects, so it is what a migration passes to `Table`
     * or `dropTable`.
     *
     * @param   string  $name  Table name as the extension thinks of it — `announcements`, not a prefixed or
     *          namespaced name. Lowercase, opening with a letter, at most 63 characters.
     *
     * @return  string  Site prefix, `ext_`, extension namespace and the given name, concatenated.
     *
     * @throws  InvalidArgumentException  When the given name is not a safe lowercase identifier, when the
     *          name composed with the extension namespace is not one either, or when the prefixed result
     *          exceeds the portable 63-byte identifier limit.
     *
     * @since   2.0.0
     */
    public function raw(string $name): string
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $name) !== 1) {
            throw new InvalidArgumentException('An extension table name must be a safe lowercase identifier.');
        }

        return $this->tables->raw('ext_' . $this->namespace . '_' . $name);
    }

    /**
     * Compile the same name quoted for the platform, for a migration that writes SQL text by hand rather
     * than going through the schema manager.
     *
     * Quoting is applied to each dot-separated part rather than to the whole string, so a qualified name
     * would stay a qualified reference instead of collapsing into one identifier containing a dot.
     *
     * @param   string  $name  Table name as the extension thinks of it, under the same rules as `raw()`.
     *
     * @return  string  The name `raw()` builds, with each dot-separated part quoted for the connection's
     *          platform.
     *
     * @throws  InvalidArgumentException  Under the same conditions as `raw()`, which this delegates to.
     *
     * @since   2.0.0
     */
    public function quoted(string $name): string
    {
        return implode('.', array_map(
            $this->database->getDatabasePlatform()->quoteSingleIdentifier(...),
            explode('.', $this->raw($name)),
        ));
    }
}
