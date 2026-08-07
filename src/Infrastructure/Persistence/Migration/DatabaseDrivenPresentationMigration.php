<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\CMS\Content\Application\ContentService;
use Kumwe\CMS\Content\Domain\ContentEntry;
use Kumwe\CMS\Content\Domain\ContentRevision;
use Kumwe\CMS\Content\Domain\PublicationWindow;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Presentation\Application\SitePresentation;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/** Seeds the managed corporate presentation without replacing an existing site configuration. */
final readonly class DatabaseDrivenPresentationMigration implements Migration
{
    public const string ID = '20260807170000_database_driven_presentation';

    public function __construct(private TableNames $tables)
    {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function checksum(): string
    {
        $checksum = hash_file('sha256', __FILE__);
        if (!is_string($checksum)) {
            throw new RuntimeException('The database-driven presentation migration checksum could not be calculated.');
        }

        return hash('sha256', self::ID . ':' . $checksum);
    }

    public function up(Connection $database): void
    {
        $exists = $database->fetchOne(sprintf(
            'SELECT setting_key FROM %s WHERE setting_key = ?',
            $this->tables->quoted('site_settings'),
        ), ['site.presentation']);
        if ($exists === false) {
            $presentation = SitePresentation::defaults();
            $legacyLogo = $this->legacyLogo($database);
            if ($legacyLogo !== null) {
                $presentation['logo'] = $legacyLogo;
            }

            $database->insert($this->tables->raw('site_settings'), [
                'setting_key' => 'site.presentation',
                'setting_value' => SitePresentation::from($presentation)->toArray(),
                'version' => 1,
                'updated_by' => null,
                'updated_at' => new DateTimeImmutable('now', new DateTimeZone('UTC')),
            ], [
                'setting_value' => Types::JSON,
                'updated_at' => Types::DATETIME_IMMUTABLE,
            ]);
        }

        $this->removeLegacyHeaderLogoField($database);
    }

    private function legacyLogo(Connection $database): ?string
    {
        $homepage = $database->fetchOne(sprintf(
            'SELECT setting_value FROM %s WHERE setting_key = ?',
            $this->tables->quoted('site_settings'),
        ), ['site.homepage_content_id']);
        $homepage = $this->decode($homepage);
        if (!is_string($homepage) || $homepage === '') {
            return null;
        }
        $data = $database->fetchOne(sprintf(
            'SELECT data FROM %s WHERE id = ?',
            $this->tables->quoted('content_entries'),
        ), [$homepage]);
        $data = $this->decode($data);
        $logo = is_array($data) ? ($data['brand_logo'] ?? null) : null;

        if (!is_string($logo) || $logo === '') {
            return null;
        }

        $candidate = SitePresentation::defaults();
        $candidate['logo'] = $logo;
        try {
            SitePresentation::from($candidate);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $logo;
    }

    private function removeLegacyHeaderLogoField(Connection $database): void
    {
        $type = $database->fetchAssociative(sprintf(
            'SELECT * FROM %s WHERE id = ?',
            $this->tables->quoted('content_types'),
        ), [ContentService::CORE_PAGE_TYPE_ID]);
        if ($type === false) {
            throw new RuntimeException('The core Page content type is missing.');
        }
        $schema = $this->decode($type['field_schema'] ?? null);
        if (!is_array($schema) || !is_array($schema['properties'] ?? null)) {
            throw new RuntimeException('The core Page content schema is invalid.');
        }
        if (!array_key_exists('brand_logo', $schema['properties'])) {
            $this->moveEntriesWithLegacyLogo($database, $this->integer($type, 'version'));
            return;
        }

        unset($schema['properties']['brand_logo']);
        $version = $this->integer($type, 'version') + 1;
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $database->executeStatement(sprintf(
            'UPDATE %s SET field_schema = ?, version = ?, updated_at = ? WHERE id = ?',
            $this->tables->quoted('content_types'),
        ), [$schema, $version, $now, ContentService::CORE_PAGE_TYPE_ID], [
            Types::JSON,
            Types::INTEGER,
            Types::DATETIME_IMMUTABLE,
            Types::GUID,
        ]);

        $workflowId = $this->string($type, 'workflow_id');
        $workflowVersion = $database->fetchOne(sprintf(
            'SELECT version FROM %s WHERE id = ?',
            $this->tables->quoted('workflows'),
        ), [$workflowId]);
        if (!is_numeric($workflowVersion)) {
            throw new RuntimeException('The core Page workflow version is invalid.');
        }
        $database->insert($this->tables->raw('content_type_definition_versions'), [
            'content_type_id' => ContentService::CORE_PAGE_TYPE_ID,
            'version' => $version,
            'site_identifier' => $this->string($type, 'site_identifier'),
            'handle' => $this->string($type, 'handle'),
            'name' => $this->string($type, 'name'),
            'workflow_id' => $workflowId,
            'workflow_version' => (int) $workflowVersion,
            'validation_schema' => $schema,
            'created_at' => $this->date($type['created_at'] ?? null) ?? $now,
            'published_at' => $now,
        ], [
            'validation_schema' => Types::JSON,
            'created_at' => Types::DATETIME_IMMUTABLE,
            'published_at' => Types::DATETIME_IMMUTABLE,
        ]);

        $entries = $database->fetchAllAssociative(sprintf(
            'SELECT * FROM %s WHERE content_type_id = ?',
            $this->tables->quoted('content_entries'),
        ), [ContentService::CORE_PAGE_TYPE_ID]);
        foreach ($entries as $entry) {
            $this->moveEntryToDefinition($database, $entry, $version, $now, true);
        }
    }

    private function moveEntriesWithLegacyLogo(Connection $database, int $definitionVersion): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $entries = $database->fetchAllAssociative(sprintf(
            'SELECT * FROM %s WHERE content_type_id = ?',
            $this->tables->quoted('content_entries'),
        ), [ContentService::CORE_PAGE_TYPE_ID]);
        foreach ($entries as $entry) {
            $this->moveEntryToDefinition($database, $entry, $definitionVersion, $now, false);
        }
    }

    /** @param array<string, mixed> $row */
    private function moveEntryToDefinition(
        Connection $database,
        array $row,
        int $definitionVersion,
        DateTimeImmutable $now,
        bool $moveUnchanged,
    ): void {
        $data = $this->decode($row['data'] ?? null);
        if (!is_array($data)) {
            throw new RuntimeException('A Page contains invalid structured data.');
        }
        $id = $this->string($row, 'id');
        if (!array_key_exists('brand_logo', $data)) {
            if ($moveUnchanged) {
                $database->executeStatement(sprintf(
                    'UPDATE %s SET content_type_version = ? WHERE id = ?',
                    $this->tables->quoted('content_entries'),
                ), [$definitionVersion, $id]);
            }
            return;
        }

        unset($data['brand_logo']);
        $entryVersion = $this->integer($row, 'version') + 1;
        $entry = ContentEntry::reconstitute(
            $id,
            $this->string($row, 'title'),
            $this->string($row, 'slug'),
            $data,
            $this->string($row, 'workflow_state_key'),
            new PublicationWindow(
                $this->date($row['publish_at'] ?? null),
                $this->date($row['unpublish_at'] ?? null),
            ),
            $entryVersion,
        );
        $database->executeStatement(sprintf(
            'UPDATE %s SET data = ?, content_type_version = ?, version = ?, updated_at = ? WHERE id = ?',
            $this->tables->quoted('content_entries'),
        ), [$data, $definitionVersion, $entryVersion, $now, $id], [
            Types::JSON,
            Types::INTEGER,
            Types::INTEGER,
            Types::DATETIME_IMMUTABLE,
            Types::GUID,
        ]);
        $revisionNumber = $database->fetchOne(sprintf(
            'SELECT COALESCE(MAX(revision_number), 0) + 1 FROM %s WHERE content_entry_id = ?',
            $this->tables->quoted('content_revisions'),
        ), [$id]);
        if (!is_numeric($revisionNumber) || (int) $revisionNumber < 1) {
            throw new RuntimeException('The next Page revision number is invalid.');
        }
        $revision = ContentRevision::capture(Uuid::uuid7()->toString(), $entry, (int) $revisionNumber, $now);
        $database->insert($this->tables->raw('content_revisions'), [
            'id' => $revision->id(),
            'content_entry_id' => $revision->contentEntryId(),
            'revision_number' => $revision->revisionNumber(),
            'snapshot' => $revision->snapshot(),
            'checksum' => $revision->checksum(),
            'created_at' => $revision->createdAt(),
        ], ['snapshot' => Types::JSON, 'created_at' => Types::DATETIME_IMMUTABLE]);
    }

    private function decode(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        return json_decode($value, true, 32, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Migration field %s is invalid.', $key));
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (!is_numeric($value) || (int) $value < 1) {
            throw new RuntimeException(sprintf('Migration field %s is invalid.', $key));
        }

        return (int) $value;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            throw new RuntimeException('A migration date is invalid.');
        }

        return new DateTimeImmutable($value);
    }
}
