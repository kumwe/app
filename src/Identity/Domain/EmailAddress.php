<?php

declare(strict_types=1);

namespace Kumwe\CMS\Identity\Domain;

use InvalidArgumentException;
use Stringable;

/**
 * A user's sign-in address, trimmed and lowercased at the point it enters the domain.
 *
 * Addresses arrive from administrator forms, API payloads and console arguments in whatever casing and
 * padding they were typed with, and they are the key an account is looked up by. Folding them here is
 * what lets `DoctrineAdministratorIdentityGateway` and `AccessControlService` compare an incoming
 * address to a stored one directly, and what lets the store's uniqueness constraint do its job rather
 * than admitting `Owner@Example.com` beside `owner@example.com`. `fromString()` is the only
 * constructor, so an unvalidated address cannot reach the store through this type.
 *
 * @since  2.0.0
 */
final readonly class EmailAddress implements Stringable
{
    /**
     * Longest address accepted, the practical ceiling an SMTP forward path allows.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAX_LENGTH = 254;

    /**
     * Wrap a value that `fromString()` has already normalised and validated.
     *
     * @param  string  $value  Lowercase, trimmed, syntactically valid address.
     *
     * @since  2.0.0
     */
    private function __construct(private string $value)
    {
    }

    /**
     * Normalise and validate an address as it enters the domain.
     *
     * Trimming and lowercasing happen before validation, so a pasted address carrying stray whitespace
     * or mixed casing is corrected rather than refused. Syntax is judged by PHP's
     * `FILTER_VALIDATE_EMAIL`, which is a grammar check only: it says nothing about whether the mailbox
     * exists or can receive anything.
     *
     * @param   string  $value  Address as typed, in any casing and with any surrounding whitespace.
     *
     * @return  self  The normalised address.
     *
     * @throws  InvalidArgumentException  When the trimmed value is empty, longer than 254 characters,
     *          or not a syntactically valid address.
     *
     * @since   2.0.0
     */
    public static function fromString(string $value): self
    {
        $value = strtolower(trim($value));

        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('An email address must contain between 1 and 254 characters.');
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('The email address is invalid.');
        }

        return new self($value);
    }

    /**
     * The normalised address, as it is written to a row and as it is looked up by.
     *
     * @return  string  Lowercase and trimmed.
     *
     * @since   2.0.0
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * Whether another address identifies the same account.
     *
     * Both sides were folded on construction, so this compares the stored forms exactly; no further
     * case folding, dot stripping or provider-specific alias resolution is attempted.
     *
     * @param   self  $other  Address to compare against.
     *
     * @return  bool  True when the two normalised addresses are identical.
     *
     * @since   2.0.0
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Render the address as its normalised string for interpolation into messages and log lines.
     *
     * @return  string  The same value `value()` returns.
     *
     * @since   2.0.0
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
