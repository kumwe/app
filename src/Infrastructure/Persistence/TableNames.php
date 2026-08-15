<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Kumwe\CMS\Shared\Domain\DatabaseTablePrefix;

/**
 * Compiler that turns a logical table name into the physical identifier a statement may carry.
 *
 * Every installation shares one schema layout but chooses its own table prefix, so nothing in the tree
 * spells a physical name; queries ask here instead. The name that comes back has been checked three
 * ways — the configured prefix satisfied `DatabaseTablePrefix` when this object was built, the logical
 * name matched a strict lowercase pattern, and the concatenation stayed inside the portable 63-byte
 * identifier limit. Because both halves are validated rather than escaped, `raw()` is
 * safe to interpolate into SQL directly, and `quoted()` adds the platform's quoting for the statements
 * that want a quoted identifier. Operator configuration therefore never reaches the SQL grammar.
 *
 * @since  2.0.0
 */
final readonly class TableNames
{
    /**
     * Bind the compiler to a connection and validate the configured prefix once, up front.
     *
     * @param   Connection  $connection  Connection whose platform supplies identifier quoting.
     * @param   string      $prefix      Prefix from database configuration, prepended to every logical
     *          name; must satisfy `DatabaseTablePrefix::isValid()`.
     *
     * @throws  InvalidArgumentException  When the configured prefix is not a valid table prefix.
     *
     * @since   2.0.0
     */
    public function __construct(private Connection $connection, private string $prefix)
    {
        if (!DatabaseTablePrefix::isValid($prefix)) {
            throw new InvalidArgumentException('The database table prefix is invalid.');
        }
    }

    /**
     * Return the validated prefix every physical name this compiler produces begins with.
     *
     * Reach for this only where the prefix is the subject rather than part of a name — deciding which
     * of the tables in a shared schema belong to this installation, for instance, which is how a
     * parent-schema install keeps its schema repairs off a neighbour's tables. Composing a name by
     * concatenating this yourself defeats the point of the compiler; call `raw()` or `quoted()` instead.
     *
     * @return  string  Prefix as configured, already proven valid by `DatabaseTablePrefix`.
     *
     * @since   2.0.0
     */
    public function prefix(): string
    {
        return $this->prefix;
    }

    /**
     * Compile the unquoted physical table name for a logical name.
     *
     * @param   string  $name  Logical table name as the codebase spells it, such as `api_tokens`:
     *          lowercase letters, digits and underscores, starting with a letter, 63 bytes at most.
     *
     * @return  non-empty-string  Prefix and name concatenated, safe to interpolate into SQL unquoted
     *          and usable where DBAL expects an unquoted name, as `Connection::insert()` does.
     *
     * @throws  InvalidArgumentException  When the logical name breaks the pattern, or the prefixed name
     *          exceeds the portable 63-byte identifier limit.
     *
     * @since   2.0.0
     */
    public function raw(string $name): string
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $name) !== 1) {
            throw new InvalidArgumentException('The database table name is invalid.');
        }

        $physicalName = $this->prefix . $name;
        if (strlen($physicalName) > 63) {
            throw new InvalidArgumentException('The prefixed database table name exceeds the portable 63-byte limit.');
        }

        return $physicalName;
    }

    /**
     * Compile the physical table name and wrap it in this platform's identifier quoting.
     *
     * Reach for this over `raw()` wherever the name is written into SQL text the platform parses, so a
     * prefix that collides with a reserved word still yields a valid statement.
     *
     * @param   string  $name  Logical table name as the codebase spells it, such as `api_tokens`.
     *
     * @return  non-empty-string  Quoted identifier ready to drop into a statement for this connection.
     *
     * @throws  InvalidArgumentException  When the logical name breaks the pattern, or the prefixed name
     *          exceeds the portable 63-byte identifier limit.
     *
     * @since   2.0.0
     */
    public function quoted(string $name): string
    {
        /** @var non-empty-string $quoted */
        $quoted = $this->connection->quoteSingleIdentifier($this->raw($name));

        return $quoted;
    }
}
