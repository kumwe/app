<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Studio\Application\Projection\ContentProjectionBindingRepository;
use Kumwe\App\Studio\Application\Composition\ContentBlueprintBindingStore;
use Kumwe\App\Studio\Application\Composition\EntryCompositionOverrideStore;
use Kumwe\App\Studio\Application\Host\StudioPersistenceRace;
use Kumwe\App\Studio\Domain\Projection\ContentBlueprintBinding;
use Kumwe\App\Studio\Domain\Projection\EntryCompositionOverrides;
use Kumwe\Producer\Canonical\CanonicalEncodingException;
use RuntimeException;
use stdClass;

/**
 * Doctrine reader for host-owned Content-to-Blueprint bindings and per-entry overrides.
 *
 * Both queries carry the server-resolved site in their predicate, so a UUID learned from another site
 * cannot cross the model-port boundary. Canonical override bytes are decoded as objects and handed to
 * the domain value, which revalidates the member and byte limits before anything reaches Studio. The
 * separate write ports insert initial type-version bindings and move an entry's item layout pointer.
 *
 * @since  2.0.0
 */
final readonly class DoctrineContentProjectionBindingRepository implements
    ContentProjectionBindingRepository,
    ContentBlueprintBindingStore,
    EntryCompositionOverrideStore
{
    /**
     * Bind reads to the configured connection and prefix-aware table compiler.
     *
     * @param  Connection  $connection  Installation database.
     * @param  TableNames  $tables      Prefix-aware physical table names.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $connection, private TableNames $tables)
    {
    }

    /**
     * Read one exact Content type version's Blueprint binding.
     *
     * @param   SiteContext  $site                Server-resolved site.
     * @param   string       $contentTypeId       Canonical Content type UUID.
     * @param   int          $contentTypeVersion  Exact published definition version.
     *
     * @return  ?ContentBlueprintBinding  Revalidated binding, or null when none is configured.
     *
     * @throws  \Doctrine\DBAL\Exception  When the database read fails.
     * @throws  RuntimeException  When stored binding data is malformed.
     *
     * @since   2.0.0
     */
    public function blueprint(
        SiteContext $site,
        string $contentTypeId,
        int $contentTypeVersion,
    ): ?ContentBlueprintBinding {
        $row = $this->connection->fetchAssociative(sprintf(
            'SELECT blueprint_id, blueprint_version, blueprint_revision, binding_revision, field_ids '
            . 'FROM %s WHERE site_identifier = ? AND content_type_id = ? AND content_type_version = ?',
            $this->tables->quoted('studio_content_blueprint_bindings'),
        ), [$site->identifier(), $contentTypeId, $contentTypeVersion], [
            ParameterType::STRING,
            Types::GUID,
            ParameterType::INTEGER,
        ]);
        if ($row === false) {
            return null;
        }

        try {
            return new ContentBlueprintBinding(
                $site,
                $contentTypeId,
                $contentTypeVersion,
                self::string($row, 'blueprint_id'),
                self::string($row, 'blueprint_version'),
                self::nullableString($row, 'blueprint_revision'),
                self::integer($row, 'binding_revision'),
                self::fieldIds($row['field_ids'] ?? null),
            );
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException('Stored Studio Blueprint binding metadata is invalid.', 0, $exception);
        }
    }

    /**
     * Insert one initial type-version binding inside the caller's transaction.
     *
     * @param   ContentBlueprintBinding  $binding  Exact immutable Content-to-Blueprint binding.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function add(ContentBlueprintBinding $binding): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A Studio Content binding write requires an active transaction.');
        }
        try {
            $this->connection->insert($this->tables->raw('studio_content_blueprint_bindings'), [
                'site_identifier' => $binding->site->identifier(),
                'content_type_id' => $binding->contentTypeId,
                'content_type_version' => $binding->contentTypeVersion,
                'blueprint_id' => $binding->blueprintId,
                'blueprint_version' => $binding->blueprintVersion,
                'blueprint_revision' => $binding->blueprintRevision,
                'binding_revision' => $binding->revision,
                'field_ids' => $binding->fieldIds === null ? null : json_encode(
                    (object) $binding->fieldIds,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
                ),
            ], ['content_type_id' => Types::GUID]);
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $exception) {
            throw new StudioPersistenceRace('A Studio Content binding was concurrently inserted.', 0, $exception);
        }
    }

    /**
     * Insert an entry's override record or move its item layout pointer inside the caller's transaction.
     *
     * The update is a compare-and-set on the override revision and never rewrites stored override
     * values. The next revision must exceed the expected one, so a successful move always changes a
     * row and every engine reports exactly one affected row.
     *
     * @param   EntryCompositionOverrides  $next              Record carrying the next pointer and revision.
     * @param   ?int                       $expectedRevision  Stored override revision, or null to insert.
     *
     * @return  void
     *
     * @throws  StudioPersistenceRace  When the record was inserted or moved concurrently.
     * @throws  \Doctrine\DBAL\Exception  When the database refuses the write for another reason.
     *
     * @since   2.0.0
     */
    public function pin(EntryCompositionOverrides $next, ?int $expectedRevision): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A Studio entry override write requires an active transaction.');
        }
        if ($expectedRevision === null) {
            try {
                $this->connection->insert($this->tables->raw('studio_entry_composition_overrides'), [
                    'site_identifier' => $next->site->identifier(),
                    'content_entry_id' => $next->entryId,
                    'override_values' => $next->values(),
                    'override_revision' => $next->revision,
                    'item_blueprint_revision' => $next->itemBlueprintRevision,
                ], [
                    'site_identifier' => ParameterType::STRING,
                    'content_entry_id' => Types::GUID,
                    'override_values' => Types::JSON,
                    'override_revision' => ParameterType::INTEGER,
                    'item_blueprint_revision' => ParameterType::STRING,
                ]);
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $exception) {
                throw new StudioPersistenceRace('A Studio entry override was concurrently inserted.', 0, $exception);
            }

            return;
        }
        if ($expectedRevision < 1 || $next->revision <= $expectedRevision) {
            throw new \LogicException('A Studio entry override pointer move must advance its revision.');
        }
        $updated = $this->connection->executeStatement(sprintf(
            'UPDATE %s SET item_blueprint_revision = ?, override_revision = ? '
            . 'WHERE site_identifier = ? AND content_entry_id = ? AND override_revision = ?',
            $this->tables->quoted('studio_entry_composition_overrides'),
        ), [
            $next->itemBlueprintRevision,
            $next->revision,
            $next->site->identifier(),
            $next->entryId,
            $expectedRevision,
        ], [
            ParameterType::STRING,
            ParameterType::INTEGER,
            ParameterType::STRING,
            Types::GUID,
            ParameterType::INTEGER,
        ]);
        if ($updated !== 1) {
            throw new StudioPersistenceRace('A Studio entry override pointer was concurrently moved.');
        }
    }

    /**
     * Read one Content entry's canonical composition override object.
     *
     * @param   SiteContext  $site     Server-resolved site.
     * @param   string       $entryId  Canonical Content entry UUID.
     *
     * @return  ?EntryCompositionOverrides  Revalidated overrides, or null when the entry inherits completely.
     *
     * @throws  \Doctrine\DBAL\Exception  When the database read fails.
     * @throws  RuntimeException  When stored JSON is not an object or metadata is malformed.
     *
     * @since   2.0.0
     */
    public function overrides(SiteContext $site, string $entryId): ?EntryCompositionOverrides
    {
        $row = $this->connection->fetchAssociative(sprintf(
            'SELECT override_values, override_revision, item_blueprint_revision FROM %s '
            . 'WHERE site_identifier = ? AND content_entry_id = ?',
            $this->tables->quoted('studio_entry_composition_overrides'),
        ), [$site->identifier(), $entryId], [ParameterType::STRING, Types::GUID]);
        if ($row === false) {
            return null;
        }
        $raw = $row['override_values'] ?? null;
        if ($raw instanceof stdClass) {
            $values = $raw;
        } elseif (is_string($raw)) {
            $values = json_decode($raw, false);
        } else {
            $values = null;
        }
        if (!$values instanceof stdClass) {
            throw new RuntimeException('Stored Studio entry overrides are not a JSON object.');
        }

        try {
            return new EntryCompositionOverrides(
                $site,
                $entryId,
                $values,
                self::integer($row, 'override_revision'),
                self::nullableString($row, 'item_blueprint_revision'),
            );
        } catch (CanonicalEncodingException | InvalidArgumentException $exception) {
            throw new RuntimeException('Stored Studio entry override metadata is invalid.', 0, $exception);
        }
    }

    /**
     * Decode the persisted host field identity map; its value constructor validates every pair.
     *
     * @param   mixed  $value  Nullable database text.
     *
     * @return  array<string, string>|null  Exact field identities, or the native Content projection profile.
     *
     * @throws  RuntimeException  When stored JSON is not an object of strings.
     *
     * @since   2.0.0
     */
    private static function fieldIds(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        $decoded = is_string($value) ? json_decode($value) : null;
        if (!$decoded instanceof stdClass) {
            throw new RuntimeException('The stored Studio Content field map is invalid.');
        }
        $map = [];
        foreach (get_object_vars($decoded) as $key => $id) {
            if (!is_string($key) || !is_string($id)) {
                throw new RuntimeException('The stored Studio Content field map is invalid.');
            }
            $map[$key] = $id;
        }

        return $map;
    }

    /**
     * Read one required non-empty string column.
     *
     * @param   array<string, mixed>  $row  Database row.
     * @param   string                $key  Column name.
     *
     * @return  string  Stored non-empty string.
     *
     * @throws  RuntimeException  When the value is absent or not a non-empty string.
     *
     * @since   2.0.0
     */
    private static function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Stored Studio binding column %s is invalid.', $key));
        }

        return $value;
    }

    /**
     * Read one optional non-empty string column.
     *
     * @param   array<string, mixed>  $row  Database row.
     * @param   string                $key  Column name.
     *
     * @return  ?string  Null or the stored non-empty string.
     *
     * @throws  RuntimeException  When a present value is not a non-empty string.
     *
     * @since   2.0.0
     */
    private static function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Stored Studio binding column %s is invalid.', $key));
        }

        return $value;
    }

    /**
     * Read one positive integer column regardless of the driver's scalar representation.
     *
     * @param   array<string, mixed>  $row  Database row.
     * @param   string                $key  Column name.
     *
     * @return  int  Stored positive integer.
     *
     * @throws  RuntimeException  When the value is not a canonical positive integer.
     *
     * @since   2.0.0
     */
    private static function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (is_int($integer)) {
                return $integer;
            }
        }

        throw new RuntimeException(sprintf('Stored Studio binding column %s is invalid.', $key));
    }
}
