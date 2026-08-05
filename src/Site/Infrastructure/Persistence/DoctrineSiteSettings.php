<?php

declare(strict_types=1);

namespace Kumwe\CMS\Site\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\AuthorizationGateway;
use Kumwe\CMS\Application\Authorization\AuthorizationResource;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Audit\Application\AuditRecorder;
use Kumwe\CMS\Audit\Domain\AuditEvent;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Infrastructure\Persistence\TransactionManager;
use Kumwe\CMS\Identity\Domain\Capability;
use Kumwe\CMS\Site\Application\SiteSettings;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final readonly class DoctrineSiteSettings implements SiteSettings
{
    public function __construct(
        private Connection $database,
        private TableNames $tables,
        private TransactionManager $transactions,
        private AuditRecorder $audit,
        private ClockInterface $clock,
        private AuthorizationGateway $authorization,
    ) {
    }

    public function current(): array
    {
        $rows = $this->database->fetchAllAssociative(sprintf(
            'SELECT setting_key, setting_value FROM %s',
            $this->tables->quoted('site_settings'),
        ));
        $settings = [
            'site_name' => 'Kumwe',
            'homepage_slug' => 'home',
            'default_locale' => 'en',
            'timezone' => 'UTC',
            'search_indexing_enabled' => true,
        ];

        foreach ($rows as $row) {
            $key = $row['setting_key'] ?? null;
            $value = $this->decode($row['setting_value'] ?? null);

            if (!is_string($key) || !array_key_exists($key, self::keyMap())) {
                continue;
            }

            $settings[self::keyMap()[$key]] = $value;
        }

        return $settings;
    }

    public function managed(ExecutionContext $context): array
    {
        $this->authorize($context);

        return $this->current();
    }

    public function update(ExecutionContext $context, string $siteName, string $homepageSlug): void
    {
        $this->authorize($context);
        $current = $this->current();
        $this->updateAll($context, [
            'site_name' => $siteName,
            'homepage_slug' => $homepageSlug,
            'default_locale' => $current['default_locale'],
            'timezone' => $current['timezone'],
            'search_indexing_enabled' => $current['search_indexing_enabled'],
        ]);
    }

    /** @param array<string, mixed> $settings */
    public function updateAll(ExecutionContext $context, array $settings): void
    {
        $this->authorize($context);
        $actorId = $context->actorId();
        $normalized = $this->validate($settings);

        $this->transactions->transactional(function () use ($actorId, $normalized): void {
            foreach (self::keyMap() as $storageKey => $publicKey) {
                $this->upsert($storageKey, $normalized[$publicKey], $actorId);
            }

            $this->audit->record(new AuditEvent(
                Uuid::uuid7()->toString(),
                $this->clock->now(),
                $actorId,
                'site.settings.update',
                'site',
                'global',
                'success',
                ['changed_keys' => array_keys($normalized)],
            ));
        });
    }

    private function authorize(ExecutionContext $context): void
    {
        $this->authorization->assertAllowed(
            $context,
            Capability::fromString('settings.manage'),
            AuthorizationResource::item('site', $context->site()->identifier()),
        );
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function validate(array $settings): array
    {
        $siteName = $this->stringSetting($settings, 'site_name');
        $homepageSlug = $this->stringSetting($settings, 'homepage_slug');
        $locale = $this->stringSetting($settings, 'default_locale');
        $timezone = $this->stringSetting($settings, 'timezone');

        if ($siteName === '' || mb_strlen($siteName) > 160) {
            throw new InvalidArgumentException('The site name must contain 1 to 160 characters.');
        }

        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $homepageSlug) !== 1) {
            throw new InvalidArgumentException('The homepage slug is invalid.');
        }

        if (preg_match('/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*$/D', $locale) !== 1) {
            throw new InvalidArgumentException('The default locale is invalid.');
        }

        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('The timezone is invalid.');
        }

        return [
            'site_name' => $siteName,
            'homepage_slug' => $homepageSlug,
            'default_locale' => str_replace('_', '-', $locale),
            'timezone' => $timezone,
            'search_indexing_enabled' => filter_var(
                $settings['search_indexing_enabled'] ?? true,
                FILTER_VALIDATE_BOOL,
            ),
        ];
    }

    /** @param array<string, mixed> $settings */
    private function stringSetting(array $settings, string $key): string
    {
        $value = $settings[$key] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('The %s setting must be a string.', $key));
        }

        return trim($value);
    }

    private function upsert(string $key, mixed $value, string $actorId): void
    {
        $table = $this->tables->raw('site_settings');
        $exists = $this->database->fetchOne(
            sprintf('SELECT setting_key FROM %s WHERE setting_key = ?', $this->tables->quoted('site_settings')),
            [$key],
        );
        $values = [
            'setting_value' => $value,
            'updated_by' => $actorId,
            'updated_at' => $this->clock->now(),
        ];
        $types = [
            'setting_value' => Types::JSON,
            'updated_at' => Types::DATETIME_IMMUTABLE,
        ];

        if ($exists === false) {
            $this->database->insert($table, ['setting_key' => $key, 'version' => 1] + $values, $types);
            return;
        }

        $this->database->executeStatement(sprintf(
            'UPDATE %s SET setting_value = ?, updated_by = ?, updated_at = ?, version = version + 1 '
            . 'WHERE setting_key = ?',
            $this->tables->quoted('site_settings'),
        ), [$value, $actorId, $this->clock->now(), $key], [
            Types::JSON,
            Types::GUID,
            Types::DATETIME_IMMUTABLE,
            Types::STRING,
        ]);
    }

    private function decode(mixed $stored): mixed
    {
        if (!is_string($stored)) {
            return $stored;
        }

        try {
            return json_decode($stored, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('A site setting contains invalid JSON.', 0, $exception);
        }
    }

    /** @return array<string, string> */
    private static function keyMap(): array
    {
        return [
            'site.name' => 'site_name',
            'site.homepage_slug' => 'homepage_slug',
            'site.default_locale' => 'default_locale',
            'site.timezone' => 'timezone',
            'search.indexing_enabled' => 'search_indexing_enabled',
        ];
    }
}
