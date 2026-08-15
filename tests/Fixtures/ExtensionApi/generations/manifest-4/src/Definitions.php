<?php

declare(strict_types=1);

namespace KumweContract\ManifestFour;

use Kumwe\CMS\BusinessIntegration\Domain\DomainListenerDefinition;
use Kumwe\CMS\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\CMS\BusinessIntegration\Domain\EventSchemaDefinition;
use Kumwe\CMS\BusinessIntegration\Domain\JobContributionDefinition;
use Kumwe\CMS\BusinessIntegration\Domain\QueueContributionDefinition;
use Kumwe\CMS\BusinessIntegration\Domain\ScheduleContributionDefinition;
use Kumwe\CMS\BusinessIntegration\Domain\WebhookContributionDefinition;
use Kumwe\CMS\BusinessReporting\Domain\ProjectionDefinition;
use Kumwe\CMS\BusinessReporting\Domain\ReportDefinition;

/**
 * The manifest-4 compatibility package's SPI-2 declarations, built through the public array contracts.
 *
 * These are deliberately written out in PHP rather than read back from `kumwe.json`. The registrar
 * reconciles what a provider registers against what the manifest declared, so writing both sides
 * independently is what makes the fixture fail when the two grammars drift apart.
 *
 * @since  2.0.0
 */
final readonly class Definitions
{
    /**
     * The one versioned event contract this package owns.
     *
     * @return  EventSchemaDefinition  Public-sensitivity schema-one observation contract.
     *
     * @since   2.0.0
     */
    public static function eventSchema(): EventSchemaDefinition
    {
        return EventSchemaDefinition::fromArray([
            'event_type' => 'kumwe.contract-manifest-four.observed',
            'schema_version' => 1,
            'sensitivity' => 'public',
            'payload_schema' => [
                'type' => 'object',
                'properties' => ['message' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100]],
                'required' => ['message'],
                'additionalProperties' => false,
            ],
            'maximum_bytes' => 4096,
        ]);
    }

    /**
     * The synchronous, transaction-local listener declaration.
     *
     * @return  DomainListenerDefinition  Listener bound to the package's own event contract.
     *
     * @since   2.0.0
     */
    public static function domainListener(): DomainListenerDefinition
    {
        return DomainListenerDefinition::fromArray([
            'listener_id' => 'kumwe.contract-manifest-four.observe-now',
            'event_type' => 'kumwe.contract-manifest-four.observed',
            'schema_versions' => [1],
            'handler_version' => '1.0.0',
            'priority' => 20,
            'sensitivity_ceiling' => 'public',
        ]);
    }

    /**
     * The durable, queue-backed consumer declaration.
     *
     * @return  EventConsumerDefinition  Aggregate-ordered consumer with version idempotency.
     *
     * @since   2.0.0
     */
    public static function consumer(): EventConsumerDefinition
    {
        return EventConsumerDefinition::fromArray([
            'consumer_id' => 'kumwe.contract-manifest-four.observe-later',
            'event_type' => 'kumwe.contract-manifest-four.observed',
            'schema_versions' => [1],
            'handler_version' => '1.0.0',
            'queue' => 'kumwe.contract-manifest-four.integration',
            'aggregate_ordered' => true,
            'idempotency' => 'aggregate_version',
            'maximum_attempts' => 7,
            'sensitivity_ceiling' => 'public',
        ]);
    }

    /**
     * The background job declaration and its closed payload schema.
     *
     * @return  JobContributionDefinition  Site-scoped summarisation job.
     *
     * @since   2.0.0
     */
    public static function job(): JobContributionDefinition
    {
        return JobContributionDefinition::fromArray([
            'job_type' => 'kumwe.contract-manifest-four.summarize',
            'schema_version' => 1,
            'handler_version' => '1.0.0',
            'payload_schema' => [
                'type' => 'object',
                'properties' => [
                    'site_identifier' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 191],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                ],
                'required' => ['site_identifier', 'limit'],
                'additionalProperties' => false,
            ],
            'queue' => 'kumwe.contract-manifest-four.integration',
            'maximum_attempts' => 5,
            'installation_wide' => false,
        ]);
    }

    /**
     * The logical queue the package's consumer and job both run on.
     *
     * @return  QueueContributionDefinition  Bounded lease, attempt and retention policy.
     *
     * @since   2.0.0
     */
    public static function queue(): QueueContributionDefinition
    {
        return QueueContributionDefinition::fromArray([
            'queue_id' => 'kumwe.contract-manifest-four.integration',
            'lease_seconds' => 90,
            'maximum_attempts' => 7,
            'maximum_in_flight' => 4,
            'retention_days' => 14,
        ]);
    }

    /**
     * The recurring schedule that enqueues the package's job.
     *
     * @return  ScheduleContributionDefinition  Daily site-scoped schedule.
     *
     * @since   2.0.0
     */
    public static function schedule(): ScheduleContributionDefinition
    {
        return ScheduleContributionDefinition::fromArray([
            'schedule_id' => 'kumwe.contract-manifest-four.summarize-daily',
            'job_type' => 'kumwe.contract-manifest-four.summarize',
            'cron_expression' => '30 3 * * *',
            'timezone' => 'Africa/Windhoek',
            'payload' => ['site_identifier' => 'default', 'limit' => 25],
            'queue' => 'kumwe.contract-manifest-four.integration',
            'site_identifier' => 'default',
            'enabled' => true,
        ]);
    }

    /**
     * The rebuildable projection fed from the package's own event contract.
     *
     * @return  ProjectionDefinition  Keyed activity projection.
     *
     * @since   2.0.0
     */
    public static function projection(): ProjectionDefinition
    {
        return ProjectionDefinition::fromArray([
            'identifier' => 'kumwe.contract-manifest-four.activity',
            'version' => 1,
            'handler_version' => '1.0.0',
            'rebuildable' => true,
            'sensitivity_ceiling' => 'public',
            'sources' => [
                ['event_type' => 'kumwe.contract-manifest-four.observed', 'schema_versions' => [1]],
            ],
            'fields' => [
                ['name' => 'aggregate_id', 'type' => 'identifier', 'nullable' => false],
                ['name' => 'message', 'type' => 'string', 'nullable' => false],
            ],
            'key_fields' => ['aggregate_id'],
            'rebuild_batch_size' => 250,
        ]);
    }

    /**
     * The read-only report declaration the package's capability guards.
     *
     * @return  ReportDefinition  Administrator-visible compatibility summary.
     *
     * @since   2.0.0
     */
    public static function report(): ReportDefinition
    {
        return ReportDefinition::fromArray([
            'identifier' => 'kumwe.contract-manifest-four.summary',
            'version' => 1,
            'title' => 'Manifest-four compatibility summary',
            'source_definition' => 'kumwe.contract-manifest-four.entity',
            'required_capability' => 'kumwe.contract-manifest-four.view',
            'administrator_visible' => true,
            'portal_visible' => false,
            'parameters' => [
                [
                    'name' => 'minimum_count',
                    'type' => 'integer',
                    'required' => false,
                    'multiple' => false,
                    'default' => 0,
                ],
            ],
            'filters' => [
                ['field' => 'count', 'operator' => 'gte', 'parameter' => 'minimum_count', 'quantifier' => 'any'],
            ],
            'columns' => [
                ['alias' => 'entity_id', 'label' => 'Entity ID', 'source' => 'id', 'type' => 'identifier'],
                ['alias' => 'count', 'label' => 'Count', 'source' => 'count', 'type' => 'integer'],
            ],
            'groups' => [],
            'aggregates' => [],
            'formulas' => [],
            'sorts' => [['output' => 'count', 'direction' => 'desc', 'nulls_last' => true]],
            'drill_downs' => [],
            'synchronous_row_cap' => 100,
        ]);
    }

    /**
     * The outbound webhook adapter declaration.
     *
     * @return  WebhookContributionDefinition  Event-identifier idempotent outbound adapter.
     *
     * @since   2.0.0
     */
    public static function webhook(): WebhookContributionDefinition
    {
        return WebhookContributionDefinition::fromArray([
            'adapter_id' => 'kumwe.contract-manifest-four.observed-webhook',
            'event_types' => ['kumwe.contract-manifest-four.observed'],
            'schema_versions' => [1],
            'handler_version' => '1.0.0',
            'queue' => 'kumwe.contract-manifest-four.integration',
            'idempotency' => 'event_id',
            'maximum_attempts' => 6,
            'sensitivity_ceiling' => 'public',
        ]);
    }
}
