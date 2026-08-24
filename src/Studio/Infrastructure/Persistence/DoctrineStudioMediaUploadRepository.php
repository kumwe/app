<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Studio\Application\Media\StudioMediaUploadRepository;
use Kumwe\App\Studio\Domain\Media\StudioMediaAcceptedAsset;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadPlan;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadRequest;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadSession;
use Kumwe\App\Studio\Domain\Media\StudioMediaUploadState;
use RuntimeException;

/**
 * Prefix-aware Doctrine persistence for scoped Studio upload-session snapshots.
 *
 * @since  2.0.0
 */
final readonly class DoctrineStudioMediaUploadRepository implements StudioMediaUploadRepository
{
    /**
     * Bind upload persistence to the configured connection and table compiler.
     *
     * @param  Connection  $connection  Installation connection.
     * @param  TableNames  $tables      Prefix-aware table names.
     *
     * @since  2.0.0
     */
    public function __construct(private Connection $connection, private TableNames $tables)
    {
    }

    /**
     * Insert one complete validated upload snapshot.
     *
     * @param   StudioMediaUploadSession  $session  New upload snapshot.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function add(StudioMediaUploadSession $session): void
    {
        $this->connection->insert(
            $this->tables->raw('studio_media_uploads'),
            self::values($session),
            self::types(),
        );
    }

    /**
     * Resolve one upload only under every trusted scope coordinate.
     *
     * @param   string  $id          Opaque upload identity.
     * @param   string  $actorId     Trusted actor identity.
     * @param   string  $siteId      Trusted site identity.
     * @param   string  $contextKey  Opaque Studio context.
     * @param   string  $generation  Current authority generation.
     *
     * @return  StudioMediaUploadSession|null  Revalidated snapshot or null.
     *
     * @since   2.0.0
     */
    public function find(
        string $id,
        string $actorId,
        string $siteId,
        string $contextKey,
        string $generation,
    ): ?StudioMediaUploadSession {
        $row = $this->connection->fetchAssociative(sprintf(
            'SELECT * FROM %s WHERE id = ? AND actor_id = ? AND site_identifier = ? '
                . 'AND resource_context_key = ? AND session_generation = ?',
            $this->tables->quoted('studio_media_uploads'),
        ), [$id, $actorId, $siteId, $contextKey, $generation], array_fill(0, 5, ParameterType::STRING));

        return $row === false ? null : self::hydrate($row);
    }

    /**
     * Replace mutable state only when the caller still owns the observed version.
     *
     * @param   StudioMediaUploadSession  $session          Replacement snapshot.
     * @param   int                       $expectedVersion  Previously observed version.
     *
     * @return  bool  True only when exactly one snapshot advanced.
     *
     * @since   2.0.0
     */
    public function save(StudioMediaUploadSession $session, int $expectedVersion): bool
    {
        $values = self::values($session);
        unset(
            $values['id'],
            $values['actor_id'],
            $values['site_identifier'],
            $values['resource_context_key'],
            $values['session_generation'],
            $values['request'],
            $values['plan'],
            $values['token_digest'],
            $values['expires_at'],
        );
        $types = self::types();
        foreach (array_keys($types) as $name) {
            if (!array_key_exists($name, $values)) {
                unset($types[$name]);
            }
        }

        return $this->connection->update(
            $this->tables->raw('studio_media_uploads'),
            $values,
            [
                'id' => $session->id,
                'actor_id' => $session->actorId,
                'site_identifier' => $session->siteId,
                'resource_context_key' => $session->contextKey,
                'session_generation' => $session->generation,
                'version' => $expectedVersion,
            ],
            $types,
        ) === 1;
    }

    /**
     * Serialize one immutable snapshot into DBAL values.
     *
     * @param   StudioMediaUploadSession  $session  Validated snapshot.
     *
     * @return  array<string, mixed>  Database values.
     *
     * @since   2.0.0
     */
    private static function values(StudioMediaUploadSession $session): array
    {
        return [
            'id' => $session->id,
            'actor_id' => $session->actorId,
            'site_identifier' => $session->siteId,
            'resource_context_key' => $session->contextKey,
            'session_generation' => $session->generation,
            'request' => get_object_vars($session->request->document()),
            'plan' => get_object_vars($session->plan->document()),
            'state' => $session->state->value,
            'transferred_bytes' => $session->transferred,
            'token_digest' => $session->tokenDigest,
            'expires_at' => $session->expiresAt,
            'asset_id' => $session->asset?->id,
            'asset_revision' => $session->asset?->revision,
            'asset_state' => $session->asset?->state,
            'failure_code' => $session->failureCode,
            'version' => $session->version,
        ];
    }

    /**
     * Return DBAL types for structured and temporal snapshot columns.
     *
     * @return  array<string, string>  Column type map.
     *
     * @since   2.0.0
     */
    private static function types(): array
    {
        return [
            'request' => Types::JSON,
            'plan' => Types::JSON,
            'expires_at' => Types::DATETIME_IMMUTABLE,
        ];
    }

    /**
     * Revalidate every stored scalar before returning it to the application boundary.
     *
     * @param   array<string, mixed>  $row  Fetched database row.
     *
     * @return  StudioMediaUploadSession  Validated immutable snapshot.
     *
     * @throws  RuntimeException  When persisted data violates the domain contract.
     *
     * @since   2.0.0
     */
    private static function hydrate(array $row): StudioMediaUploadSession
    {
        try {
            $request = self::json($row['request'] ?? null);
            $plan = self::json($row['plan'] ?? null);
            $assetId = self::nullableString($row, 'asset_id');
            $asset = $assetId === null ? null : new StudioMediaAcceptedAsset(
                $assetId,
                self::string($row, 'asset_revision'),
                self::string($row, 'asset_state'),
            );

            return new StudioMediaUploadSession(
                self::string($row, 'id'),
                self::string($row, 'actor_id'),
                self::string($row, 'site_identifier'),
                self::string($row, 'resource_context_key'),
                self::string($row, 'session_generation'),
                StudioMediaUploadRequest::fromDocument((object) $request),
                new StudioMediaUploadPlan(
                    self::integer($plan, 'maximumBytes'),
                    self::boolean($plan, 'resumable'),
                    isset($plan['chunkBytes']) ? self::integer($plan, 'chunkBytes') : null,
                ),
                StudioMediaUploadState::from(self::string($row, 'state')),
                self::integer($row, 'transferred_bytes'),
                self::string($row, 'token_digest'),
                self::instant($row['expires_at'] ?? null),
                $asset,
                self::nullableString($row, 'failure_code'),
                self::integer($row, 'version'),
            );
        } catch (InvalidArgumentException | \ValueError $failure) {
            throw new RuntimeException('Stored Studio media upload data is invalid.', 0, $failure);
        }
    }

    /**
     * Decode a required persisted JSON object.
     *
     * @param   mixed  $value  DBAL JSON value or encoded bytes.
     *
     * @return  array<string, mixed>  Decoded object members.
     *
     * @since   2.0.0
     */
    private static function json(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException('Stored Studio media JSON is invalid.');
        }
        $members = [];
        foreach ($value as $name => $member) {
            if (!is_string($name)) {
                throw new RuntimeException('Stored Studio media JSON member is invalid.');
            }
            $members[$name] = $member;
        }

        return $members;
    }

    /**
     * Read one required non-empty stored string.
     *
     * @param   array<string, mixed>  $row   Database row.
     * @param   string                $name  Column name.
     *
     * @return  string  Validated stored text.
     *
     * @since   2.0.0
     */
    private static function string(array $row, string $name): string
    {
        $value = $row[$name] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('Stored Studio media text is invalid.');
        }

        return $value;
    }

    /**
     * Read one optional stored string.
     *
     * @param   array<string, mixed>  $row   Database row.
     * @param   string                $name  Column name.
     *
     * @return  string|null  Validated stored text or null.
     *
     * @since   2.0.0
     */
    private static function nullableString(array $row, string $name): ?string
    {
        return ($row[$name] ?? null) === null ? null : self::string($row, $name);
    }

    /**
     * Read one required non-negative stored integer.
     *
     * @param   array<string, mixed>  $row   Database row.
     * @param   string                $name  Column name.
     *
     * @return  int  Validated stored integer.
     *
     * @since   2.0.0
     */
    private static function integer(array $row, string $name): int
    {
        $value = $row[$name] ?? null;
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 0) {
            throw new RuntimeException('Stored Studio media integer is invalid.');
        }

        return $value;
    }

    /**
     * Read one required stored boolean across portable driver forms.
     *
     * @param   array<string, mixed>  $row   Database row.
     * @param   string                $name  Column name.
     *
     * @return  bool  Validated boolean.
     *
     * @since   2.0.0
     */
    private static function boolean(array $row, string $name): bool
    {
        $value = $row[$name] ?? null;
        if (!in_array($value, [true, false, 0, 1, '0', '1'], true)) {
            throw new RuntimeException('Stored Studio media boolean is invalid.');
        }

        return (bool) $value;
    }

    /**
     * Read one required persisted instant.
     *
     * @param   mixed  $value  Driver-returned temporal value.
     *
     * @return  DateTimeImmutable  Immutable instant.
     *
     * @since   2.0.0
     */
    private static function instant(mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('Stored Studio media instant is invalid.');
        }

        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
