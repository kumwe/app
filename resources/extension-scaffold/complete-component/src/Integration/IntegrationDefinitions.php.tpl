<?php

declare(strict_types=1);

namespace @@PHP_NAMESPACE@@\Integration;

use Kumwe\App\BusinessIntegration\Domain\DomainListenerDefinition;
use Kumwe\App\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\App\BusinessIntegration\Domain\EventSchemaDefinition;
use Kumwe\App\BusinessIntegration\Domain\JobContributionDefinition;
use Kumwe\App\BusinessIntegration\Domain\QueueContributionDefinition;
use Kumwe\App\BusinessIntegration\Domain\ScheduleContributionDefinition;
use Kumwe\App\BusinessReporting\Domain\ProjectionDefinition;
use Kumwe\App\BusinessReporting\Domain\ReportDefinition;

/**
 * Builds the exact schema-4 integration contracts declared by the signed manifest.
 *
 * @since  2.0.0
 */
final class IntegrationDefinitions
{
    /**
     * Build the owned item-observed event schema.
     *
     * @return  EventSchemaDefinition  Immutable version-one payload contract.
     *
     * @since   2.0.0
     */
    public static function eventSchema(): EventSchemaDefinition
    {
        return EventSchemaDefinition::fromArray([
            'event_type' => '@@EXTENSION_DOTTED@@.item_observed',
            'schema_version' => 1,
            'sensitivity' => 'internal',
            'payload_schema' => [
                'type' => 'object',
                'properties' => [
                    'item_id' => ['type' => 'string', 'maxLength' => 191],
                    'title' => ['type' => 'string', 'maxLength' => 191],
                ],
                'required' => ['item_id', 'title'],
                'additionalProperties' => false,
            ],
            'maximum_bytes' => 4096,
        ]);
    }

    /**
     * Build the transaction-local listener declaration.
     *
     * @return  DomainListenerDefinition  Versioned synchronous listener contract.
     *
     * @since   2.0.0
     */
    public static function domainListener(): DomainListenerDefinition
    {
        return DomainListenerDefinition::fromArray([
            'listener_id' => '@@EXTENSION_DOTTED@@.item_listener',
            'event_type' => '@@EXTENSION_DOTTED@@.item_observed',
            'schema_versions' => [1],
            'handler_version' => '1.0.0',
            'priority' => 0,
            'sensitivity_ceiling' => 'internal',
        ]);
    }

    /**
     * Build the durable event-consumer declaration.
     *
     * @return  EventConsumerDefinition  Inbox-deduplicated consumer contract.
     *
     * @since   2.0.0
     */
    public static function consumer(): EventConsumerDefinition
    {
        return EventConsumerDefinition::fromArray([
            'consumer_id' => '@@EXTENSION_DOTTED@@.item_consumer',
            'event_type' => '@@EXTENSION_DOTTED@@.item_observed',
            'schema_versions' => [1],
            'handler_version' => '1.0.0',
            'queue' => '@@EXTENSION_DOTTED@@.work',
            'aggregate_ordered' => true,
            'idempotency' => 'event_id',
            'maximum_attempts' => 5,
            'sensitivity_ceiling' => 'internal',
        ]);
    }

    /**
     * Build the component digest job contract.
     *
     * @return  JobContributionDefinition  Versioned payload and retry declaration.
     *
     * @since   2.0.0
     */
    public static function job(): JobContributionDefinition
    {
        return JobContributionDefinition::fromArray([
            'job_type' => '@@EXTENSION_DOTTED@@.digest',
            'schema_version' => 1,
            'handler_version' => '1.0.0',
            'payload_schema' => [
                'type' => 'object',
                'properties' => ['message' => ['type' => 'string', 'maxLength' => 191]],
                'required' => ['message'],
                'additionalProperties' => false,
            ],
            'queue' => '@@EXTENSION_DOTTED@@.work',
            'maximum_attempts' => 5,
            'installation_wide' => false,
        ]);
    }

    /**
     * Build the bounded logical work queue.
     *
     * @return  QueueContributionDefinition  Portable queue processing limits.
     *
     * @since   2.0.0
     */
    public static function queue(): QueueContributionDefinition
    {
        return QueueContributionDefinition::fromArray([
            'queue_id' => '@@EXTENSION_DOTTED@@.work',
            'lease_seconds' => 60,
            'maximum_attempts' => 5,
            'maximum_in_flight' => 8,
            'retention_days' => 30,
        ]);
    }

    /**
     * Build the enabled hourly digest schedule.
     *
     * @return  ScheduleContributionDefinition  Recurring site-scoped job declaration.
     *
     * @since   2.0.0
     */
    public static function schedule(): ScheduleContributionDefinition
    {
        return ScheduleContributionDefinition::fromArray([
            'schedule_id' => '@@EXTENSION_DOTTED@@.hourly_digest',
            'job_type' => '@@EXTENSION_DOTTED@@.digest',
            'cron_expression' => '0 * * * *',
            'timezone' => 'UTC',
            'payload' => ['message' => 'scheduled-health'],
            'queue' => '@@EXTENSION_DOTTED@@.work',
            'site_identifier' => 'default',
            'enabled' => true,
        ]);
    }

    /**
     * Build the disposable event-derived item projection.
     *
     * @return  ProjectionDefinition  Rebuildable projection contract.
     *
     * @since   2.0.0
     */
    public static function projection(): ProjectionDefinition
    {
        return ProjectionDefinition::fromArray([
            'identifier' => '@@EXTENSION_DOTTED@@.item_projection',
            'version' => 1,
            'handler_version' => '1.0.0',
            'rebuildable' => true,
            'sensitivity_ceiling' => 'internal',
            'sources' => [[
                'event_type' => '@@EXTENSION_DOTTED@@.item_observed',
                'schema_versions' => [1],
            ]],
            'fields' => [
                ['name' => 'item_id', 'type' => 'string', 'nullable' => false],
                ['name' => 'title', 'type' => 'string', 'nullable' => false],
            ],
            'key_fields' => ['item_id'],
            'rebuild_batch_size' => 200,
        ]);
    }

    /**
     * Build the permission-aware item report.
     *
     * @return  ReportDefinition  Administrator-and-portal-visible report contract.
     *
     * @since   2.0.0
     */
    public static function report(): ReportDefinition
    {
        return ReportDefinition::fromArray([
            'identifier' => '@@EXTENSION_DOTTED@@.item_report',
            'version' => 1,
            'title' => '@@LABEL_PHP@@ items',
            'source_definition' => '@@EXTENSION_DOTTED@@.item',
            'required_capability' => '@@EXTENSION_DOTTED@@.access',
            'administrator_visible' => true,
            'portal_visible' => true,
            'parameters' => [],
            'filters' => [],
            'columns' => [
                ['alias' => 'item_id', 'label' => 'ID', 'source' => 'id', 'type' => 'string'],
                ['alias' => 'title', 'label' => 'Title', 'source' => 'title', 'type' => 'string'],
            ],
            'groups' => [],
            'aggregates' => [],
            'formulas' => [],
            'sorts' => [],
            'drill_downs' => [],
            'synchronous_row_cap' => 100,
        ]);
    }
}
