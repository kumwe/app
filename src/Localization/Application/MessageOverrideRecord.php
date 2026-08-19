<?php

declare(strict_types=1);

namespace Kumwe\App\Localization\Application;

use DateTimeImmutable;
use Kumwe\App\Localization\Domain\MessageCatalogueLayer;

/**
 * One stored override, with the bookkeeping an administration screen needs and the render path does not.
 *
 * The render path reads a bare identifier-to-pattern map, because that is all a lookup needs and
 * anything more would be paid for on every page. An operator deciding whether to keep a change needs
 * more than that: which layer carries it, which locale it applies to, and when it was last written.
 * This is that view, and it exists separately for exactly that reason rather than widening the map
 * the chain is assembled from.
 *
 * @since  2.0.0
 */
final readonly class MessageOverrideRecord
{
    /**
     * Describe one stored override.
     *
     * @param  MessageCatalogueLayer  $layer         Which administered layer stores it; only `Site` and
     *         `Organization` are storable, because core and extension wording ships in files.
     * @param  string                 $site          Site the override belongs to.
     * @param  ?string                $organization  Organization within that site, or null at site level.
     * @param  string                 $locale        Canonical tag of the locale the override applies to.
     * @param  string                 $identifier    Message identifier the override replaces.
     * @param  string                 $pattern       ICU pattern rendered in place of the layer below.
     * @param  DateTimeImmutable      $updatedAt     Instant the override was last written.
     *
     * @since  2.0.0
     */
    public function __construct(
        public MessageCatalogueLayer $layer,
        public string $site,
        public ?string $organization,
        public string $locale,
        public string $identifier,
        public string $pattern,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * Flatten the record for a template or a JSON response.
     *
     * @return  array{
     *              layer: string,
     *              site: string,
     *              organization: ?string,
     *              locale: string,
     *              identifier: string,
     *              pattern: string,
     *              updated_at: string
     *          } The record with its layer as its stored value and its instant in RFC 3339 form.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'layer' => $this->layer->value,
            'site' => $this->site,
            'organization' => $this->organization,
            'locale' => $this->locale,
            'identifier' => $this->identifier,
            'pattern' => $this->pattern,
            'updated_at' => $this->updatedAt->format(DATE_RFC3339),
        ];
    }
}
