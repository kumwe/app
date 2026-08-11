<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Domain;

use InvalidArgumentException;

/**
 * Versioned KIS compatibility envelope declared by an installable template package.
 *
 * A template names the KIS standard it requires and inclusive version bounds for the public component
 * and token contracts it consumes. Parsing closes the declaration to known keys and proves both ranges
 * are ordered, while activation compares those ranges with the versions supplied by the running host.
 *
 * @since  2.0.0
 */
final readonly class TemplateKisCompatibility
{
    /**
     * Supply the exact KIS 1.0 contract historical schema-one templates implicitly targeted.
     *
     * This narrow default preserves manifests accepted before the compatibility envelope existed. It
     * does not create an open range: legacy packages are admitted only against the original KIS 1.0
     * standard and the exact 1.0.0 component and token contracts.
     *
     * @return  self  Exact KIS 1.0 compatibility used only for undeclared schema-one templates.
     *
     * @since   2.0.0
     */
    public static function legacyKisOne(): self
    {
        return self::fromArray([
            'contract' => 1,
            'standard' => 'kis-1.0',
            'components' => ['minimum' => '1.0.0', 'maximum' => '1.0.0'],
            'tokens' => ['minimum' => '1.0.0', 'maximum' => '1.0.0'],
        ]);
    }

    /**
     * Parse and validate a template's closed KIS compatibility object.
     *
     * @param   array<string, mixed>  $declaration  Value of the manifest's top-level `template` field.
     *
     * @return  self  Validated contract and inclusive component/token bounds.
     *
     * @throws  InvalidArgumentException  When a key, contract version, standard identifier, range, or
     *          version value is malformed.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $declaration): self
    {
        self::assertKnownKeys(
            $declaration,
            ['contract', 'standard', 'components', 'tokens'],
            'The template compatibility declaration',
        );

        $contract = $declaration['contract'] ?? null;
        if (!is_int($contract) || $contract !== 1) {
            throw new InvalidArgumentException('The template compatibility contract must be version 1.');
        }

        $standard = $declaration['standard'] ?? null;
        if (!is_string($standard) || preg_match('/^kis-(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)$/D', $standard) !== 1) {
            throw new InvalidArgumentException(
                'The template compatibility standard must use the kis-major.minor identifier form.',
            );
        }

        [$minimumComponents, $maximumComponents] = self::range($declaration, 'components');
        [$minimumTokens, $maximumTokens] = self::range($declaration, 'tokens');

        return new self(
            $contract,
            $standard,
            $minimumComponents,
            $maximumComponents,
            $minimumTokens,
            $maximumTokens,
        );
    }

    /**
     * Store a compatibility declaration whose structure and bounds are already valid.
     *
     * @param  int              $contract           Compatibility declaration format version.
     * @param  string           $standard           Required KIS major/minor identifier.
     * @param  SemanticVersion  $minimumComponents  Oldest public component contract accepted.
     * @param  SemanticVersion  $maximumComponents  Newest public component contract accepted.
     * @param  SemanticVersion  $minimumTokens      Oldest public token contract accepted.
     * @param  SemanticVersion  $maximumTokens      Newest public token contract accepted.
     *
     * @since  2.0.0
     */
    private function __construct(
        private int $contract,
        private string $standard,
        private SemanticVersion $minimumComponents,
        private SemanticVersion $maximumComponents,
        private SemanticVersion $minimumTokens,
        private SemanticVersion $maximumTokens,
    ) {
    }

    /**
     * Report the compatibility declaration format the package uses.
     *
     * @return  int  Version of the closed `template` manifest object.
     *
     * @since   2.0.0
     */
    public function contract(): int
    {
        return $this->contract;
    }

    /**
     * Name the KIS major/minor standard the package requires.
     *
     * @return  string  Identifier such as `kis-1.0`.
     *
     * @since   2.0.0
     */
    public function standard(): string
    {
        return $this->standard;
    }

    /**
     * Decide whether a host component contract lies inside the declared inclusive range.
     *
     * @param   SemanticVersion  $version  Public KIS component contract supplied by the host.
     *
     * @return  bool  True when the version is no older than the minimum and no newer than the maximum.
     *
     * @since   2.0.0
     */
    public function supportsComponents(SemanticVersion $version): bool
    {
        return $this->minimumComponents->compare($version) <= 0
            && $this->maximumComponents->compare($version) >= 0;
    }

    /**
     * Decide whether a host token contract lies inside the declared inclusive range.
     *
     * @param   SemanticVersion  $version  Public KIS token contract supplied by the host.
     *
     * @return  bool  True when the version is no older than the minimum and no newer than the maximum.
     *
     * @since   2.0.0
     */
    public function supportsTokens(SemanticVersion $version): bool
    {
        return $this->minimumTokens->compare($version) <= 0
            && $this->maximumTokens->compare($version) >= 0;
    }

    /**
     * Parse one closed inclusive version range from the declaration.
     *
     * @param   array<string, mixed>  $declaration  Validated parent compatibility object.
     * @param   string                $field        `components` or `tokens`, used in failures.
     *
     * @return  array{SemanticVersion, SemanticVersion}  Ordered minimum and maximum versions.
     *
     * @throws  InvalidArgumentException  When the field is not an object, has unknown keys, contains
     *          malformed semantic versions, or places its maximum before its minimum.
     *
     * @since   2.0.0
     */
    private static function range(array $declaration, string $field): array
    {
        $range = $declaration[$field] ?? null;
        if (!is_array($range) || array_is_list($range)) {
            throw new InvalidArgumentException(sprintf(
                'The template compatibility %s range must be a JSON object.',
                $field,
            ));
        }
        /** @var array<string, mixed> $range */
        self::assertKnownKeys($range, ['minimum', 'maximum'], sprintf(
            'The template compatibility %s range',
            $field,
        ));

        $minimum = $range['minimum'] ?? null;
        $maximum = $range['maximum'] ?? null;
        if (!is_string($minimum) || !is_string($maximum)) {
            throw new InvalidArgumentException(sprintf(
                'The template compatibility %s range requires string minimum and maximum versions.',
                $field,
            ));
        }

        $minimumVersion = SemanticVersion::fromString($minimum);
        $maximumVersion = SemanticVersion::fromString($maximum);
        if ($minimumVersion->compare($maximumVersion) > 0) {
            throw new InvalidArgumentException(sprintf(
                'The template compatibility %s maximum cannot precede its minimum.',
                $field,
            ));
        }

        return [$minimumVersion, $maximumVersion];
    }

    /**
     * Reject keys outside a closed compatibility object.
     *
     * @param   array<string, mixed>  $values   Object whose keys are being inspected.
     * @param   list<string>          $allowed  Complete allowed-key set for the object.
     * @param   string                $field    Human-readable object name for the failure.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the object contains an unrecognized key.
     *
     * @since   2.0.0
     */
    private static function assertKnownKeys(array $values, array $allowed, string $field): void
    {
        $unknown = array_diff(array_keys($values), $allowed);
        if ($unknown !== []) {
            sort($unknown, SORT_STRING);
            throw new InvalidArgumentException(sprintf('%s contains unknown key %s.', $field, $unknown[0]));
        }
    }
}
