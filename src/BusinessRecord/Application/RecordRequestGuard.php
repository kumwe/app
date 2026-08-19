<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application;

use InvalidArgumentException;
use Kumwe\App\BusinessRecord\Domain\RecordValueGuard;
use Ramsey\Uuid\Uuid;

/**
 * Shape checks every business-record command and query runs before it is allowed to exist.
 *
 * The record layer takes identifiers, field handles and value maps straight from callers, and by the
 * time one reaches a repository it is already being resolved against an installed definition or bound
 * into a statement. Holding the checks here lets each command and query object validate its own
 * arguments inside its constructor, so an unconstructible request never reaches the definition
 * resolver, the mutation fence or the database, and a rejection reads the same whichever entry point
 * produced it. Every method asserts and returns nothing; none of them trim, lowercase or otherwise
 * repair what they are given.
 *
 * @since  2.0.0
 */
final class RecordRequestGuard
{
    /**
     * Assert a business-definition reference is a form the resolver can look up.
     *
     * @param   string  $identifier  Definition reference as the caller spelled it: either a UUID, or a
     *          lowercase handle whose alphanumeric segments are joined by dots, underscores or dashes,
     *          such as `crm.contact`.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the reference is neither a valid UUID nor a well-formed
     *          multi-segment handle.
     *
     * @since   2.0.0
     */
    public static function definition(string $identifier): void
    {
        if (
            !Uuid::isValid($identifier)
            && preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)+$/D', $identifier) !== 1
        ) {
            throw new InvalidArgumentException('A business-definition identifier is invalid.');
        }
    }

    /**
     * Assert a caller-facing record identity is bounded and carries no control characters.
     *
     * The identity is whatever the definition's identity strategy produced, so its content is open by
     * design; what is fixed is that it is non-empty, fits the column it is stored in, and holds nothing
     * that could break a log line or a header it is later written into.
     *
     * @param   string  $recordId  Caller-facing record identity to check.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the identity is empty, longer than 191 bytes, or contains a
     *          C0 control character or DEL.
     *
     * @since   2.0.0
     */
    public static function record(string $recordId): void
    {
        if ($recordId === '' || strlen($recordId) > 191 || preg_match('/[\x00-\x1F\x7F]/', $recordId) === 1) {
            throw new InvalidArgumentException('A business-record ID must be a bounded identity without controls.');
        }
    }

    /**
     * Assert an optimistic-concurrency expectation names a version a record could actually hold.
     *
     * @param   int  $version  Record version the caller believes it is writing against.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the version is below 1, which no stored record ever is.
     *
     * @since   2.0.0
     */
    public static function expectedVersion(int $version): void
    {
        if ($version < 1) {
            throw new InvalidArgumentException('A business-record expected version must be positive.');
        }
    }

    /**
     * Assert an organization scope identifier is well formed, accepting its absence.
     *
     * Null passes: whether a given definition may or must be scoped to an organization is `RecordScope`'s
     * decision once the definition is resolved, not something that can be settled from the request alone.
     *
     * @param   ?string  $organization  Organization the request confines itself to, or null for none.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a non-null identifier does not open with a letter or digit,
     *          runs past 191 characters, or holds anything but letters, digits, dot, underscore, colon or
     *          dash.
     *
     * @since   2.0.0
     */
    public static function organization(?string $organization): void
    {
        if (
            $organization !== null
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D', $organization) !== 1
        ) {
            throw new InvalidArgumentException('A business-record organization identifier is invalid.');
        }
    }

    /**
     * Assert a field-value map is bounded and that every key and value is one the record layer accepts.
     *
     * The values are checked as runtime shapes only — `RecordValueGuard` refuses floats, resources and
     * unsupported objects and enforces the depth and node budget. Whether a handle exists on the pinned
     * definition, and whether its value fits that field, is decided later against the definition.
     *
     * @param   array<string, mixed>  $values      Field values keyed by field handle.
     * @param   bool                  $allowEmpty  Whether an empty map is a legitimate request, as it is
     *          for an action that takes no input.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the map is empty and empty is not allowed, holds more than
     *          256 entries, is keyed by something that is not a field handle, or carries a value
     *          `RecordValueGuard` refuses.
     *
     * @since   2.0.0
     */
    public static function values(array $values, bool $allowEmpty = false): void
    {
        if ((!$allowEmpty && $values === []) || count($values) > 256) {
            throw new InvalidArgumentException('A business-record value set is empty or unbounded.');
        }
        foreach ($values as $handle => $value) {
            if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $handle) !== 1) {
                throw new InvalidArgumentException('A business-record value set contains an invalid field handle.');
            }
            RecordValueGuard::assertValue($value);
        }
    }

    /**
     * Assert a handle naming part of a definition matches the single handle shape the record layer uses.
     *
     * Fields, relationships, actions and projection entries all share one spelling rule, so callers pass
     * the kind purely to make the rejection name what was being addressed.
     *
     * @param   string  $handle  Handle to check: lowercase, opening with a letter, then letters, digits and
     *          underscores, at most 63 characters.
     * @param   string  $kind    What the handle names — `relationship`, `action` or `projection` — as it
     *          should read inside the failure message.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the handle does not match that shape.
     *
     * @since   2.0.0
     */
    public static function handle(string $handle, string $kind): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $handle) !== 1) {
            throw new InvalidArgumentException(sprintf('A business-record %s handle is invalid.', $kind));
        }
    }

    /**
     * Block instantiation; every member of this guard is static.
     *
     * @since  2.0.0
     */
    private function __construct()
    {
    }
}
