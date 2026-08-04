<?php

declare(strict_types=1);

namespace Kumwe\CMS\Site\Infrastructure\Persistence;

use InvalidArgumentException;
use Joomla\Database\DatabaseInterface;
use JsonException;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Domain\AuditEvent;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Kumwe\CMS\Site\Application\SiteSettings;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final readonly class PostgreSqlSiteSettings implements SiteSettings
{
    public function __construct(
        private DatabaseInterface $database,
        private TransactionManager $transactions,
        private AuditRecorder $audit,
        private ClockInterface $clock,
        private string $schema,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $schema) !== 1) {
            throw new InvalidArgumentException('The PostgreSQL schema name is invalid.');
        }
    }

    public function current(): array
    {
        $rows = $this->database->setQuery(sprintf(
            "SELECT setting_key, setting_value FROM %s WHERE setting_key IN ('site.name', 'site.homepage_slug')",
            $this->table(),
        ))->loadAssocList();

        if (!is_array($rows)) {
            throw new RuntimeException('The site settings query returned an invalid result set.');
        }

        $settings = ['site_name' => 'Kumwe', 'homepage_slug' => 'home'];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $value = $this->decode($row['setting_value'] ?? null);

            if (($row['setting_key'] ?? null) === 'site.name' && $value !== '') {
                $settings['site_name'] = $value;
            } elseif (($row['setting_key'] ?? null) === 'site.homepage_slug' && $value !== '') {
                $settings['homepage_slug'] = $value;
            }
        }

        return $settings;
    }

    public function update(string $actorId, string $siteName, string $homepageSlug): void
    {
        $siteName = trim($siteName);
        $homepageSlug = trim($homepageSlug);

        if ($siteName === '' || mb_strlen($siteName) > 160) {
            throw new InvalidArgumentException('The site name must contain 1 to 160 characters.');
        }

        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $homepageSlug) !== 1) {
            throw new InvalidArgumentException('The homepage slug is invalid.');
        }

        $this->transactions->transactional(function () use ($actorId, $siteName, $homepageSlug): void {
            $this->upsert('site.name', $siteName, $actorId);
            $this->upsert('site.homepage_slug', $homepageSlug, $actorId);
            $this->audit->record(new AuditEvent(
                Uuid::uuid7()->toString(),
                $this->clock->now(),
                $actorId,
                'site.settings.update',
                'site',
                'global',
                'success',
                ['homepage_slug' => $homepageSlug],
            ));
        });
    }

    private function upsert(string $key, string $value, string $actorId): void
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $target = $this->database->quoteName('site_settings');

        if (!is_string($target)) {
            throw new RuntimeException('Joomla Database returned an invalid quoted table name.');
        }

        $sql = sprintf(
            'INSERT INTO %s (setting_key, setting_value, version, updated_by, updated_at) '
            . 'VALUES (%s, %s::jsonb, 1, %s, CURRENT_TIMESTAMP) ON CONFLICT (setting_key) DO UPDATE SET '
            . 'setting_value = EXCLUDED.setting_value, version = %s.version + 1, '
            . 'updated_by = EXCLUDED.updated_by, updated_at = CURRENT_TIMESTAMP',
            $this->table(),
            $this->quote($key),
            $this->quote($json),
            $this->quote($actorId),
            $target,
        );
        $this->database->setQuery($sql)->execute();
    }

    private function decode(mixed $stored): string
    {
        try {
            $decoded = is_string($stored) ? json_decode($stored, true, 4, JSON_THROW_ON_ERROR) : $stored;
        } catch (JsonException $exception) {
            throw new RuntimeException('A site setting contains invalid JSON.', 0, $exception);
        }

        return is_string($decoded) ? $decoded : '';
    }

    private function table(): string
    {
        $quoted = $this->database->quoteName($this->schema . '.site_settings');

        if (!is_string($quoted)) {
            throw new RuntimeException('Joomla Database returned an invalid quoted table.');
        }

        return $quoted;
    }

    private function quote(string $value): string
    {
        $quoted = $this->database->quote($value);

        if (!is_string($quoted)) {
            throw new RuntimeException('Joomla Database returned an invalid quoted value.');
        }

        return $quoted;
    }
}
