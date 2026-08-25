<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Release;

use JsonException;
use UnexpectedValueException;

/**
 * Immutable runtime projection of the canonical vendored Studio release record.
 *
 * The repository verifies the complete record and its package artifacts before deployment. This
 * value keeps delivery code bound to that same file instead of copying its release coordinate into
 * PHP source.
 *
 * @since  2.0.0
 */
final readonly class StudioReleaseRecord
{
    /**
     * Complete semantic-version grammar accepted by the release record.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string VERSION_PATTERN = '/^(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)'
        . '(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?'
        . '(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D';

    /**
     * Hold the coordinated release name and digest of the exact canonical record bytes.
     *
     * @param  string  $release       Exact coordinated Studio release.
     * @param  string  $recordSha256  Lower-case SHA-256 digest of the canonical record bytes.
     *
     * @since  2.0.0
     */
    private function __construct(
        public string $release,
        public string $recordSha256,
    ) {
    }

    /**
     * Decode the canonical record and reject a malformed or staggered package family.
     *
     * @param   string  $json  Exact canonical release-record bytes.
     *
     * @return  self  Validated immutable runtime projection.
     *
     * @throws  JsonException            When the record is not valid JSON.
     * @throws  UnexpectedValueException  When the record does not name one coordinated family.
     *
     * @since   2.0.0
     */
    public static function fromJson(string $json): self
    {
        $record = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        $release = is_array($record) ? ($record['release'] ?? null) : null;
        $packages = is_array($record) ? ($record['packages'] ?? null) : null;
        if (
            !is_array($record)
            || ($record['kind'] ?? null) !== 'studio-release'
            || !is_string($release)
            || preg_match(self::VERSION_PATTERN, $release) !== 1
            || !is_array($packages)
            || $packages === []
        ) {
            throw new UnexpectedValueException('The canonical Studio release record is malformed.');
        }
        foreach ($packages as $package => $version) {
            if (!is_string($package) || !str_starts_with($package, '@kumwe/studio') || $version !== $release) {
                throw new UnexpectedValueException('The canonical Studio release record is not coordinated.');
            }
        }

        return new self($release, hash('sha256', $json));
    }
}
