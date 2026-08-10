<?php

declare(strict_types=1);

namespace KumweExample\AssetInspection;

use LogicException;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessIntegration\Domain\DomainListenerDefinition;
use Kumwe\CMS\BusinessIntegration\Domain\EventConsumerDefinition;
use Kumwe\CMS\BusinessIntegration\Domain\JobContributionDefinition;
use Kumwe\CMS\BusinessIntegration\Domain\QueueContributionDefinition;
use Kumwe\CMS\BusinessIntegration\Domain\ScheduleContributionDefinition;
use Kumwe\CMS\BusinessReporting\Domain\ProjectionDefinition;
use Kumwe\CMS\BusinessReporting\Domain\ReportDefinition;
use Kumwe\CMS\Extension\Contribution\ManifestContributionSet;
use Kumwe\CMS\Extension\Domain\ExtensionManifest;

/**
 * Resolves the exact immutable declarations already carried by this package's signed manifest.
 *
 * Reading the installed manifest again cannot widen authority: the owner-bound registrar compares every
 * returned object with the generation compiled from the trusted manifest and fails closed on any mismatch.
 * Keeping one declaration source also prevents the proof component from drifting as SDK contracts evolve.
 *
 * @since  2.0.0
 */
final class Definitions
{
    /**
     * Stable package identifier used for ownership and renderer isolation.
     *
     * @var    string
     * @since  2.0.0
     */
    public const OWNER = 'kumwe/asset-inspection-example';

    /**
     * Stable UUID of the inspection definition observed by the integration handlers.
     *
     * @var    string
     * @since  2.0.0
     */
    public const INSPECTION_DEFINITION_ID = '019bc200-0000-7000-8000-000000000003';

    /**
     * Parsed declaration set, retained because all contribution objects are immutable.
     *
     * @var    ?ManifestContributionSet
     * @since  2.0.0
     */
    private static ?ManifestContributionSet $declarations = null;

    /**
     * Return the complete manifest declaration set after checking package identity and schema revision.
     *
     * @return  ManifestContributionSet  Exact schema-4/SPI-2 declarations.
     *
     * @throws  LogicException  When the packaged manifest is missing, unreadable, or identifies another package.
     *
     * @since   2.0.0
     */
    public static function all(): ManifestContributionSet
    {
        if (self::$declarations instanceof ManifestContributionSet) {
            return self::$declarations;
        }
        $path = dirname(__DIR__) . '/kumwe.json';
        $json = file_get_contents($path);
        if (!is_string($json)) {
            throw new LogicException('The asset-inspection example manifest is unavailable.');
        }
        $manifest = ExtensionManifest::fromJson($json);
        if ($manifest->schemaVersion() !== 4 || $manifest->identifier()->value() !== self::OWNER) {
            throw new LogicException('The asset-inspection example manifest identity is invalid.');
        }
        self::$declarations = $manifest->contributions();

        return self::$declarations;
    }

    /**
     * Return all five related neutral business definitions.
     *
     * @return  list<EntityTypeDefinition>  Location, asset, inspection, finding, and measurement definitions.
     *
     * @since   2.0.0
     */
    public static function businessDefinitions(): array
    {
        return self::all()->businessDefinitions();
    }

    /**
     * Return the transaction-local listener declaration for core record mutation events.
     *
     * @return  DomainListenerDefinition  Exact manifest listener contract.
     *
     * @since   2.0.0
     */
    public static function listener(): DomainListenerDefinition
    {
        return self::only(self::all()->domainListeners(), DomainListenerDefinition::class, 'domain listener');
    }

    /**
     * Return the durable aggregate-ordered consumer declaration.
     *
     * @return  EventConsumerDefinition  Exact manifest consumer contract.
     *
     * @since   2.0.0
     */
    public static function consumer(): EventConsumerDefinition
    {
        return self::only(self::all()->eventConsumers(), EventConsumerDefinition::class, 'event consumer');
    }

    /**
     * Return the site-scoped overdue-review job declaration.
     *
     * @return  JobContributionDefinition  Exact manifest job contract.
     *
     * @since   2.0.0
     */
    public static function job(): JobContributionDefinition
    {
        return self::only(self::all()->jobs(), JobContributionDefinition::class, 'job');
    }

    /**
     * Return the bounded integration queue declaration.
     *
     * @return  QueueContributionDefinition  Exact manifest queue contract.
     *
     * @since   2.0.0
     */
    public static function queue(): QueueContributionDefinition
    {
        return self::only(self::all()->queues(), QueueContributionDefinition::class, 'queue');
    }

    /**
     * Return the daily site schedule declaration.
     *
     * @return  ScheduleContributionDefinition  Exact manifest schedule contract.
     *
     * @since   2.0.0
     */
    public static function schedule(): ScheduleContributionDefinition
    {
        return self::only(self::all()->schedules(), ScheduleContributionDefinition::class, 'schedule');
    }

    /**
     * Return the rebuildable record-mutation projection declaration.
     *
     * @return  ProjectionDefinition  Exact manifest projection contract.
     *
     * @since   2.0.0
     */
    public static function projection(): ProjectionDefinition
    {
        return self::only(self::all()->projections(), ProjectionDefinition::class, 'projection');
    }

    /**
     * Return the policy-aware inspection summary report declaration.
     *
     * @return  ReportDefinition  Exact manifest report contract.
     *
     * @since   2.0.0
     */
    public static function report(): ReportDefinition
    {
        return self::only(self::all()->reports(), ReportDefinition::class, 'report');
    }

    /**
     * Require one and only one declaration for a proof-component integration surface.
     *
     * @template T of object
     *
     * @param   list<T>          $definitions  Declarations returned by the strict manifest parser.
     * @param   class-string<T>  $type         Required declaration class.
     * @param   string           $label        Safe diagnostic surface label.
     *
     * @return  T  The sole typed declaration.
     *
     * @throws  LogicException  When the manifest does not carry exactly one declaration of the required type.
     *
     * @since   2.0.0
     */
    private static function only(array $definitions, string $type, string $label): object
    {
        $definition = count($definitions) === 1 ? $definitions[0] : null;
        if (!$definition instanceof $type) {
            throw new LogicException(sprintf('The asset-inspection example requires one %s.', $label));
        }

        return $definition;
    }

    /**
     * Prevent construction of the static declaration catalog.
     *
     * @since  2.0.0
     */
    private function __construct()
    {
    }
}
