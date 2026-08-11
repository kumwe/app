<?php

declare(strict_types=1);

namespace Kumwe\CMS\Demo\Application;

use Kumwe\CMS\Application\Authorization\SiteContext;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * Projects the immutable default-site VDM template into one validated site namespace.
 *
 * The released fixture stays byte-addressable and environment-independent. Installation derives a
 * site-owned document in memory, replacing only declared definition handles, globally stored definition
 * and record identities, and explicit site ownership fields. Business values such as field defaults are
 * never interpreted as site identifiers.
 *
 * @since  2.0.0
 */
final readonly class VdmBusinessManifestProjector
{
    /**
     * Handle namespace used by the immutable source template.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string TEMPLATE_HANDLE_PREFIX = 'site.default.vdm_';

    /**
     * Derive a site-owned aggregate without mutating the catalog's source manifest.
     *
     * @param   array<string, mixed>  $manifest  Validated aggregate VDM template.
     * @param   SiteContext           $site      Installation site receiving the example.
     *
     * @return  array<string, mixed>  Site-scoped aggregate ready for canonical application services.
     *
     * @throws  RuntimeException  When the source contradicts the template contract or the target site
     *          cannot form a valid business-definition namespace.
     *
     * @since   2.0.0
     */
    public function forSite(array $manifest, SiteContext $site): array
    {
        $siteIdentifier = $site->identifier();
        $this->assertTemplate($manifest, $siteIdentifier);
        $replacements = $this->identityReplacements($manifest, $siteIdentifier);
        $projected = $this->map(
            $this->projectValues($manifest, $replacements),
            'aggregate manifest',
        );
        $documents = $this->map($projected['definition_documents'] ?? null, 'definition documents');
        foreach ($documents as $fixtureKey => $candidate) {
            $document = $this->map($candidate, sprintf('definition %s', $fixtureKey));
            $owner = $this->map($document['owner'] ?? null, sprintf('definition %s owner', $fixtureKey));
            if (($owner['type'] ?? null) !== 'site') {
                throw new RuntimeException(sprintf('VDM definition %s is not site owned.', $fixtureKey));
            }
            $owner['identifier'] = $siteIdentifier;
            $document['owner'] = $owner;
            $document['site'] = $siteIdentifier;
            $documents[$fixtureKey] = $document;
        }
        $records = $this->map($projected['records_document'] ?? null, 'records document');
        $records['site'] = $siteIdentifier;
        $projected['site_template'] = $siteIdentifier;
        $projected['definition_documents'] = $documents;
        $projected['records_document'] = $records;

        return $projected;
    }

    /**
     * Refuse contradictory template ownership and target namespaces before projection hides them.
     *
     * @param   array<string, mixed>  $manifest        Immutable aggregate VDM template.
     * @param   string                $siteIdentifier  Normalized target site identifier.
     *
     * @return  void
     *
     * @throws  RuntimeException  When source ownership is contradictory or a projected handle is invalid.
     *
     * @since   2.0.0
     */
    private function assertTemplate(array $manifest, string $siteIdentifier): void
    {
        if (($manifest['site_template'] ?? null) !== SiteContext::DEFAULT) {
            throw new RuntimeException('The VDM aggregate must declare the default site template.');
        }
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,190}$/D', $siteIdentifier) !== 1) {
            throw new RuntimeException(sprintf(
                'Site %s cannot own VDM business definitions.',
                $siteIdentifier,
            ));
        }

        $documents = $this->map($manifest['definition_documents'] ?? null, 'definition documents');
        foreach ($documents as $fixtureKey => $candidate) {
            $document = $this->map($candidate, sprintf('definition %s', $fixtureKey));
            $owner = $this->map($document['owner'] ?? null, sprintf('definition %s owner', $fixtureKey));
            $handle = $document['handle'] ?? null;
            if (
                ($document['site'] ?? null) !== SiteContext::DEFAULT
                || count($owner) !== 2
                || ($owner['type'] ?? null) !== 'site'
                || ($owner['identifier'] ?? null) !== SiteContext::DEFAULT
                || !is_string($handle)
                || !str_starts_with($handle, self::TEMPLATE_HANDLE_PREFIX)
                || $handle === self::TEMPLATE_HANDLE_PREFIX
            ) {
                throw new RuntimeException(sprintf(
                    'VDM definition %s contradicts the default site template.',
                    $fixtureKey,
                ));
            }

            $projectedHandle = 'site.' . $siteIdentifier . '.vdm_'
                . substr($handle, strlen(self::TEMPLATE_HANDLE_PREFIX));
            if (
                strlen($projectedHandle) > 191
                || preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)+$/D', $projectedHandle) !== 1
            ) {
                throw new RuntimeException(sprintf(
                    'VDM definition %s cannot form a portable handle for site %s.',
                    $fixtureKey,
                    $siteIdentifier,
                ));
            }
        }

        $records = $this->map($manifest['records_document'] ?? null, 'records document');
        if (($records['site'] ?? null) !== SiteContext::DEFAULT) {
            throw new RuntimeException('The VDM records document contradicts the default site template.');
        }
    }

    /**
     * Build exact replacements for definition handles and globally stored definition or record UUIDs.
     *
     * @param   array<string, mixed>  $manifest        Validated default-site aggregate.
     * @param   string                $siteIdentifier  Validated target site identifier.
     *
     * @return  array<string, string>  Exact source value to site-owned value map.
     *
     * @throws  RuntimeException  When a definition or record identity is absent, malformed, or duplicated.
     *
     * @since   2.0.0
     */
    private function identityReplacements(array $manifest, string $siteIdentifier): array
    {
        $replacements = [];
        $documents = $this->map($manifest['definition_documents'] ?? null, 'definition documents');
        foreach ($documents as $fixtureKey => $candidate) {
            $document = $this->map($candidate, sprintf('definition %s', $fixtureKey));
            $id = $document['id'] ?? null;
            $handle = $document['handle'] ?? null;
            if (!is_string($id) || !Uuid::isValid($id) || !is_string($handle)) {
                throw new RuntimeException(sprintf('VDM definition %s has an invalid identity.', $fixtureKey));
            }
            $this->addReplacement(
                $replacements,
                $handle,
                'site.' . $siteIdentifier . '.vdm_' . substr($handle, strlen(self::TEMPLATE_HANDLE_PREFIX)),
            );
            $this->addReplacement($replacements, $id, $this->siteUuid($id, $siteIdentifier));
        }

        $records = $this->map($manifest['records_document'] ?? null, 'records document');
        $declarations = $records['records'] ?? null;
        if (!is_array($declarations) || !array_is_list($declarations)) {
            throw new RuntimeException('The VDM record declarations are invalid.');
        }
        foreach ($declarations as $offset => $candidate) {
            $record = $this->map($candidate, sprintf('record declaration %d', $offset));
            $id = $record['record_id'] ?? null;
            if (!is_string($id) || !Uuid::isValid($id)) {
                throw new RuntimeException(sprintf('VDM record declaration %d has an invalid identity.', $offset));
            }
            $this->addReplacement($replacements, $id, $this->siteUuid($id, $siteIdentifier));
        }

        return $replacements;
    }

    /**
     * Add one unambiguous source identity to the projection map.
     *
     * @param   array<string, string>  &$replacements  Projection map under construction.
     * @param   string  $source  Exact source identity.
     * @param   string  $target  Site-owned target identity.
     *
     * @return  void
     *
     * @throws  RuntimeException  When one source identity is declared more than once.
     *
     * @since   2.0.0
     */
    private function addReplacement(array &$replacements, string $source, string $target): void
    {
        if (isset($replacements[$source])) {
            throw new RuntimeException(sprintf('The VDM source identity %s is duplicated.', $source));
        }
        $replacements[$source] = $target;
    }

    /**
     * Derive one stable site-owned UUID while retaining released default-site identities byte for byte.
     *
     * @param   string  $source          Released default-site UUID.
     * @param   string  $siteIdentifier  Validated target site identifier.
     *
     * @return  string  Original UUID for default or deterministic UUIDv5 for another site.
     *
     * @since   2.0.0
     */
    private function siteUuid(string $source, string $siteIdentifier): string
    {
        if ($siteIdentifier === SiteContext::DEFAULT) {
            return $source;
        }

        return Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            sprintf('https://kumwe.dev/demo/vdm/sites/%s/fixtures/%s', $siteIdentifier, $source),
        )->toString();
    }

    /**
     * Replace only declared identity values throughout the aggregate, leaving business values untouched.
     *
     * @param   mixed                  $value         Scalar or nested manifest value.
     * @param   array<string, string>  $replacements  Exact released identities and their target values.
     *
     * @return  mixed  Recursively projected value with list and object keys preserved.
     *
     * @since   2.0.0
     */
    private function projectValues(mixed $value, array $replacements): mixed
    {
        if (is_string($value) && isset($replacements[$value])) {
            return $replacements[$value];
        }
        if (!is_array($value)) {
            return $value;
        }

        $projected = [];
        foreach ($value as $key => $item) {
            $projected[$key] = $this->projectValues($item, $replacements);
        }

        return $projected;
    }

    /**
     * Require one projected value to remain an object with string keys.
     *
     * @param   mixed   $value  Candidate projected value.
     * @param   string  $name   Diagnostic noun identifying the value on failure.
     *
     * @return  array<string, mixed>  Validated object-shaped value.
     *
     * @throws  RuntimeException  When the value is not an object or contains a non-string key.
     *
     * @since   2.0.0
     */
    private function map(mixed $value, string $name): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new RuntimeException(sprintf('The VDM %s is invalid.', $name));
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new RuntimeException(sprintf('The VDM %s has a non-string object key.', $name));
            }
            $result[$key] = $item;
        }

        return $result;
    }
}
