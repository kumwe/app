<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessReporting\Domain;

use InvalidArgumentException;

/**
 * Shared identifier and path validation for immutable reporting definitions.
 *
 * @since  2.0.0
 */
final class ReportDefinitionGuard
{
    /**
     * Assert a lowercase definition-local handle.
     *
     * @param   string  $value  Candidate handle.
     * @param   string  $label  Safe diagnostic label.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the value is not a bounded lowercase handle.
     *
     * @since   2.0.0
     */
    public static function handle(string $value, string $label): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('A report %s is invalid.', $label));
        }
    }

    /**
     * Assert a globally namespaced contribution or definition identifier.
     *
     * @param   string  $value  Candidate dotted identifier.
     * @param   string  $label  Safe diagnostic label.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the value is not a bounded dotted identifier.
     *
     * @since   2.0.0
     */
    public static function identifier(string $value, string $label): void
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,62}(?:\.[a-z][a-z0-9_-]{0,62})+$/D', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('A report %s is invalid.', $label));
        }
    }

    /**
     * Assert a root field or a path crossing exactly one declared relationship.
     *
     * @param   string  $value  Candidate source path.
     * @param   string  $label  Safe diagnostic label.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the path contains an unsupported segment or hop count.
     *
     * @since   2.0.0
     */
    public static function path(string $value, string $label): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}(?:\.[a-z][a-z0-9_]{0,62})?$/D', $value) !== 1) {
            throw new InvalidArgumentException(sprintf('A report %s path is invalid.', $label));
        }
    }

    /** Block instantiation. @since 2.0.0 */
    private function __construct()
    {
    }
}
