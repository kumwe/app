<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use Kumwe\App\Localization\Application\ActiveLocale;
use Kumwe\App\Localization\Application\MessageCatalogueRepository;
use Kumwe\App\Localization\Application\MessageOverrideRepository;
use Kumwe\App\Localization\Application\SupportedLocales;
use Kumwe\App\Localization\Domain\InvalidLocaleTag;
use Kumwe\App\Localization\Domain\LocaleTag;
use Kumwe\App\Localization\Domain\MessageCatalogue;
use Kumwe\App\Localization\Domain\MessageCatalogueChain;
use Kumwe\App\Localization\Domain\MessageCatalogueLayer;
use Kumwe\App\Studio\Domain\Host\StudioHostRequest;
use stdClass;

/**
 * Studio localization port over App's compiled catalogue and site/organization override chain.
 *
 * @since  2.0.0
 */
final readonly class StudioLocalizationHostPort
{
    /**
     * Bind compiled catalogues, effective overrides, locale scope, and carried locale inventory.
     *
     * @param  MessageCatalogueRepository  $catalogues  Compiled core and extension layers.
     * @param  MessageOverrideRepository   $overrides   Site and organization wording.
     * @param  ActiveLocale                $active      Trusted request override scope.
     * @param  SupportedLocales            $supported   Exact carried-locale registry.
     *
     * @since  2.0.0
     */
    public function __construct(
        private MessageCatalogueRepository $catalogues,
        private MessageOverrideRepository $overrides,
        private ActiveLocale $active,
        private SupportedLocales $supported,
    ) {
    }

    /**
     * Resolve a closed namespace request without formatting unresolved ICU parameters.
     *
     * @param   string                     $operation  Canonical localization operation.
     * @param   StudioHostRequest          $request    Validated host envelope.
     * @param   StudioHostSessionSnapshot  $snapshot   Live trusted session snapshot.
     *
     * @return  StudioHostResult  Canonical localized message map.
     *
     * @since   2.0.0
     */
    public function dispatch(
        string $operation,
        StudioHostRequest $request,
        StudioHostSessionSnapshot $snapshot,
    ): StudioHostResult {
        unset($snapshot);
        if ($operation !== 'messages') {
            throw new StudioHostOperationRefused('incompatible', 'studio.host/operation-unavailable');
        }
        if ($request->expectedRevision !== null || $request->idempotencyKey !== null) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.host/invalid-context');
        }
        $arguments = $request->arguments;
        if (!$arguments instanceof stdClass || self::members($arguments) !== ['locale', 'namespaces']) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.host/invalid-arguments');
        }
        $localeValue = $arguments->locale;
        $namespaces = $arguments->namespaces;
        if (
            !is_string($localeValue)
            || !is_array($namespaces)
            || !array_is_list($namespaces)
            || $namespaces === []
            || count($namespaces) > 16
        ) {
            throw new StudioHostOperationRefused('invalid-request', 'studio.host/invalid-arguments');
        }
        foreach ($namespaces as $namespace) {
            if (
                !is_string($namespace)
                || strlen($namespace) > 120
                || preg_match('/^[a-z][a-z0-9.-]*$/D', $namespace) !== 1
            ) {
                throw new StudioHostOperationRefused('invalid-request', 'studio.host/invalid-arguments');
            }
        }
        try {
            $locale = LocaleTag::fromString($localeValue);
        } catch (InvalidLocaleTag) {
            throw new StudioHostOperationRefused('not-found', 'studio.localization/locale-not-found');
        }
        if (!$this->supported->carries($locale)) {
            throw new StudioHostOperationRefused('not-found', 'studio.localization/locale-not-found');
        }

        $messages = new stdClass();
        if (in_array('studio.shell', $namespaces, true)) {
            foreach ($this->studioMessageIdentifiers() as $identifier) {
                $pattern = $this->pattern($identifier, $locale);
                if ($pattern !== null) {
                    $messages->{self::wireIdentifier($identifier)} = $pattern;
                }
            }
        }

        return new StudioHostResult($messages);
    }

    /**
     * Discover the exact Studio message keys carried by compiled core and extension layers.
     *
     * @return  list<string>
     *
     * @since   2.0.0
     */
    private function studioMessageIdentifiers(): array
    {
        $identifiers = [];
        foreach ($this->supported->all() as $locale) {
            foreach ([MessageCatalogueLayer::Core, MessageCatalogueLayer::Extension] as $layer) {
                foreach ($this->catalogues->catalogue($layer, $locale)->identifiers() as $identifier) {
                    if (str_starts_with($identifier, 'core.studio.shell.')) {
                        $identifiers[$identifier] = true;
                    }
                }
            }
        }
        ksort($identifiers, SORT_STRING);

        return array_keys($identifiers);
    }

    /**
     * Resolve one Studio message through locale fallbacks and effective override layers.
     *
     * @param   string     $identifier  Internal compiled message identifier.
     * @param   LocaleTag  $locale      Requested carried locale.
     *
     * @return  ?string  Effective message pattern, or null when unavailable.
     *
     * @since   2.0.0
     */
    private function pattern(string $identifier, LocaleTag $locale): ?string
    {
        foreach (
            array_values(array_unique(array_merge(
                $locale->fallbacks(),
                $this->supported->source()->fallbacks(),
            ))) as $tag
        ) {
            $resolved = $this->chain(LocaleTag::fromString($tag))->resolve($identifier);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * Build the organization, site, extension, and core catalogue chain for one locale.
     *
     * @param   LocaleTag  $locale  Carried locale to resolve.
     *
     * @return  MessageCatalogueChain  Effective ordered message layers.
     *
     * @since   2.0.0
     */
    private function chain(LocaleTag $locale): MessageCatalogueChain
    {
        $scope = $this->active->scope();
        $organization = $scope->organization;

        return new MessageCatalogueChain($locale, [
            new MessageCatalogue(
                $locale,
                MessageCatalogueLayer::Organization,
                $organization === null ? [] : $this->overrides->organizationOverrides(
                    $scope->site,
                    $organization,
                    $locale,
                ),
            ),
            new MessageCatalogue(
                $locale,
                MessageCatalogueLayer::Site,
                $this->overrides->siteOverrides($scope->site, $locale),
            ),
            $this->catalogues->catalogue(MessageCatalogueLayer::Extension, $locale),
            $this->catalogues->catalogue(MessageCatalogueLayer::Core, $locale),
        ]);
    }

    /**
     * Convert an internal App Studio key to the alpha.10 shell wire identifier.
     *
     * @param   string  $identifier  Internal compiled message identifier.
     *
     * @return  string  Studio shell wire identifier.
     *
     * @since   2.0.0
     */
    private static function wireIdentifier(string $identifier): string
    {
        return 'studio.shell/' . substr($identifier, strlen('core.studio.shell.'));
    }

    /**
     * Return deterministic object member names for exact envelope validation.
     *
     * @param   stdClass  $document  Candidate protocol object.
     *
     * @return  list<string>
     *
     * @since   2.0.0
     */
    private static function members(stdClass $document): array
    {
        $members = array_keys(get_object_vars($document));
        sort($members, SORT_STRING);

        return $members;
    }
}
