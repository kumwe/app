<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use DateTimeImmutable;
use Kumwe\App\Localization\Application\MessageOverrideService;
use Kumwe\Localization\Application\MessageCatalogueRepository;
use Kumwe\Localization\Application\MessageOverrideRecord;
use Kumwe\Localization\Application\MessageOverrideStore;
use Kumwe\Localization\Application\SupportedLocales;
use Kumwe\Localization\Domain\LocaleTag;
use Kumwe\Localization\Domain\MessageCatalogue;
use Kumwe\Localization\Domain\MessageCatalogueLayer;
use Kumwe\Localization\Infrastructure\IntlMessagePatternFormatter;
use Kumwe\Transaction\Testing\ImmediateTransactionManager;

/**
 * Real wording service over an in-memory override store and a one-message core catalogue.
 *
 * Machine-surface unit tests drive `MessageOverrideService` itself through this fixture, so the capability,
 * administered-layer, carried-locale, identifier grammar, ICU validation and audit rules are the service's
 * own and an adapter test proves only how its surface turns a request into arguments and a refusal into a
 * response.
 *
 * @since  2.0.0
 */
final class InMemoryWording implements MessageOverrideStore
{
    /**
     * Shipped core message the catalogue search finds.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string IDENTIFIER = 'core.business.client.label';

    /**
     * Stored overrides keyed by layer, site, organization, locale and identifier.
     *
     * @var    array<string, MessageOverrideRecord>
     * @since  2.0.0
     */
    private array $records = [];

    /**
     * Build the real wording service over this store.
     *
     * @param   RecordingAuditRecorder  $audit  Recorder the service writes its events to.
     *
     * @return  MessageOverrideService  Service under test.
     *
     * @since   2.0.0
     */
    public function service(RecordingAuditRecorder $audit): MessageOverrideService
    {
        return new MessageOverrideService(
            $this,
            new class implements MessageCatalogueRepository {
                /**
                 * Answer the en-GB core layer with one message and every other layer with nothing.
                 *
                 * @param   MessageCatalogueLayer  $layer   Layer being read.
                 * @param   LocaleTag              $locale  Locale being read.
                 *
                 * @return  MessageCatalogue  Stand-in catalogue.
                 *
                 * @since   2.0.0
                 */
                public function catalogue(MessageCatalogueLayer $layer, LocaleTag $locale): MessageCatalogue
                {
                    if ($layer !== MessageCatalogueLayer::Core || $locale->toString() !== 'en-GB') {
                        return MessageCatalogue::empty($locale, $layer);
                    }

                    return new MessageCatalogue($locale, $layer, [InMemoryWording::IDENTIFIER => 'Client']);
                }
            },
            new SupportedLocales(),
            AuthorizationContext::gateway(),
            new ImmediateTransactionManager(),
            new IntlMessagePatternFormatter(),
            InterfaceTranslation::translator(),
            $audit,
            new MovableAuditClock(new DateTimeImmutable('2026-09-24T10:00:00+00:00')),
        );
    }

    /**
     * Serialize nothing; one in-memory test thread already runs serially.
     *
     * @param   string  $site  Site the mutation belongs to.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function lockSite(string $site): void
    {
    }

    /**
     * List one scope's overrides.
     *
     * @param   MessageCatalogueLayer  $layer         Administered layer to list.
     * @param   string                 $site          Site the scope belongs to.
     * @param   ?string                $organization  Organization within that site, or null.
     * @param   ?LocaleTag             $locale        Locale to restrict to, or null for all.
     *
     * @return  list<MessageOverrideRecord>  Matching overrides.
     *
     * @since   2.0.0
     */
    public function overrides(
        MessageCatalogueLayer $layer,
        string $site,
        ?string $organization = null,
        ?LocaleTag $locale = null,
    ): array {
        $matches = [];
        foreach ($this->records as $record) {
            if (
                $record->layer === $layer
                && $record->site === $site
                && $record->organization === $organization
                && (!$locale instanceof LocaleTag || $record->locale === $locale->toString())
            ) {
                $matches[] = $record;
            }
        }

        return $matches;
    }

    /**
     * Store or replace one override.
     *
     * @param   MessageOverrideRecord  $override  Override to write.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function put(MessageOverrideRecord $override): void
    {
        $this->records[self::key(
            $override->layer,
            $override->site,
            $override->organization,
            $override->locale,
            $override->identifier,
        )] = $override;
    }

    /**
     * Remove one override.
     *
     * @param   MessageCatalogueLayer  $layer         Administered layer the override sits in.
     * @param   string                 $site          Site the scope belongs to.
     * @param   ?string                $organization  Organization within that site, or null.
     * @param   LocaleTag              $locale        Locale the override applies to.
     * @param   string                 $identifier    Identifier to stop overriding.
     *
     * @return  bool  True when a record was removed.
     *
     * @since   2.0.0
     */
    public function remove(
        MessageCatalogueLayer $layer,
        string $site,
        ?string $organization,
        LocaleTag $locale,
        string $identifier,
    ): bool {
        $key = self::key($layer, $site, $organization, $locale->toString(), $identifier);
        $present = isset($this->records[$key]);
        unset($this->records[$key]);

        return $present;
    }

    /**
     * Build the uniqueness key of one override.
     *
     * @param   MessageCatalogueLayer  $layer         Layer.
     * @param   string                 $site          Site.
     * @param   ?string                $organization  Organization, or null.
     * @param   string                 $locale        Locale tag.
     * @param   string                 $identifier    Message identifier.
     *
     * @return  string  Stable key.
     *
     * @since   2.0.0
     */
    private static function key(
        MessageCatalogueLayer $layer,
        string $site,
        ?string $organization,
        string $locale,
        string $identifier,
    ): string {
        return implode('|', [$layer->value, $site, $organization ?? '', $locale, $identifier]);
    }
}
