<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Contribution;

use InvalidArgumentException;
use Kumwe\CMS\Extension\Domain\ExtensionIdentifier;

/**
 * Whoever a contribution belongs to, and the namespace that owner is allowed to claim identifiers in.
 *
 * Core and installed extensions fill the contribution registries through the same registrar, so the
 * owner is the only thing separating them: it decides which identifiers a contributor may claim, and
 * it is the key every surface matches on when contributions are withdrawn on disable or uninstall.
 * The constructor is private, so an extension owner can only come from `extension()` or
 * `fromString()`, which validate the `vendor/name` package identifier before it reaches a registry.
 *
 * @since  2.0.0
 */
final readonly class ContributionOwner
{
    /**
     * Identifier of the one owner that is the CMS itself rather than an installed package.
     *
     * @var    string
     * @since  2.0.0
     */
    public const CORE = 'core';

    /**
     * Hold an owner identifier that a factory has already validated.
     *
     * @param  string  $identifier  Either `core` or a validated `vendor/name` package identifier.
     *
     * @since  2.0.0
     */
    private function __construct(private string $identifier)
    {
    }

    /**
     * The owner of everything the CMS ships itself.
     *
     * @return  self  Owner claiming the `core` namespace.
     *
     * @since   2.0.0
     */
    public static function core(): self
    {
        return new self(self::CORE);
    }

    /**
     * The owner of everything one installed extension contributes.
     *
     * @param   string  $identifier  Package identifier in `vendor/name` form; trimmed and lowercased.
     *
     * @return  self  Owner claiming the dotted namespace derived from that package identifier.
     *
     * @throws  InvalidArgumentException  When the value is not a lowercase `vendor/name` pair.
     *
     * @since   2.0.0
     */
    public static function extension(string $identifier): self
    {
        return new self(ExtensionIdentifier::fromString($identifier)->value());
    }

    /**
     * Resolve an owner from its stored string form, without the caller branching on the core case.
     *
     * @param   string  $identifier  Owner string as `identifier()` produced it.
     *
     * @return  self  The core owner for `core`, otherwise the extension owner of that package.
     *
     * @throws  InvalidArgumentException  When a value other than `core` is not a valid package identifier.
     *
     * @since   2.0.0
     */
    public static function fromString(string $identifier): self
    {
        return $identifier === self::CORE ? self::core() : self::extension($identifier);
    }

    /**
     * The owner's canonical string form, which registries store beside each contribution.
     *
     * @return  string  `core`, or the extension's `vendor/name` package identifier.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return $this->identifier;
    }

    /**
     * The dotted prefix that identifiers claimed by this owner have to sit under.
     *
     * @return  string  `core`, or the package identifier with its slash replaced by a dot.
     *
     * @since   2.0.0
     */
    public function namespace(): string
    {
        return $this->identifier === self::CORE
            ? self::CORE
            : str_replace('/', '.', $this->identifier);
    }

    /**
     * Refuse an identifier this owner is not entitled to claim.
     *
     * The registrar calls this before accepting any contribution, so an extension cannot register
     * under another package's prefix. Core follows the same rule with one exception: capabilities are
     * the site-wide permission vocabulary, so `content.read` and its like need no `core.` prefix.
     *
     * @param   string  $identifier  Identifier the contribution wants to register under.
     * @param   string  $kind        Contribution kind, which drives the capability exemption and the message.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the identifier falls outside this owner's namespace.
     *
     * @since   2.0.0
     */
    public function assertOwns(string $identifier, string $kind): void
    {
        if ($this->identifier === self::CORE) {
            if ($kind !== 'capability' && !str_starts_with($identifier, self::CORE . '.')) {
                throw new InvalidArgumentException(sprintf('Core %s identifiers must use the core namespace.', $kind));
            }
            return;
        }

        if (!str_starts_with($identifier, $this->namespace() . '.')) {
            throw new InvalidArgumentException(sprintf(
                'Extension %s cannot claim %s identifier %s.',
                $this->identifier,
                $kind,
                $identifier,
            ));
        }
    }
}
