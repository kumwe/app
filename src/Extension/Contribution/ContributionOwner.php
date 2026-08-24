<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

use InvalidArgumentException;
use Kumwe\App\Extension\Domain\ExtensionIdentifier;

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
     * Kinds whose identifiers follow the Studio identity grammar rather than the dotted App rule.
     *
     * A canonical composition identity lives inside the portable Studio document as
     * `<namespace>/<local-name>`, and its kind-scoped index form prefixes the document kind and one
     * space. Ownership therefore means the slash-form namespace matches the owner, not the dotted
     * prefix every App-side identifier carries.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const STUDIO_KINDS = [
        'canonical composition document',
        'canonical_composition_document',
        'composition_host_binding',
        'preview renderer capability',
        'studio preview renderer',
    ];

    /**
     * Contribution kinds whose identifiers use the shared graphical dotted grammar.
     *
     * Other typed integration identifiers retain their own established suffix syntax, including
     * version markers such as `@1` and capability separators such as `:`.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const GRAPHICAL_KINDS = [
        'interface surface',
        'workspace',
        'navigation',
        'route',
        'view',
        'template',
        'portal workspace',
        'portal navigation',
        'portal route',
        'portal template',
    ];

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
        if (in_array($kind, self::STUDIO_KINDS, true)) {
            $identity = ($space = strpos($identifier, ' ')) === false
                ? $identifier
                : substr($identifier, $space + 1);
            $studioNamespaces = $this->identifier === self::CORE
                ? ['core/', 'studio.core/']
                : [$this->namespace() . '/'];
            $ownsStudioIdentity = array_any(
                $studioNamespaces,
                static fn (string $prefix): bool => str_starts_with($identity, $prefix),
            );
            if (!$ownsStudioIdentity) {
                throw new InvalidArgumentException(sprintf(
                    '%s cannot claim %s identifier %s.',
                    $this->identifier === self::CORE ? 'Core' : 'Extension ' . $this->identifier,
                    $kind,
                    $identifier,
                ));
            }

            return;
        }
        $requiresGraphicalSuffix = in_array($kind, self::GRAPHICAL_KINDS, true);
        if ($this->identifier === self::CORE) {
            if (
                $kind !== 'capability'
                && (
                    !str_starts_with($identifier, self::CORE . '.')
                    || ($requiresGraphicalSuffix && !self::hasSafeOwnedSuffix($identifier, self::CORE))
                )
            ) {
                throw new InvalidArgumentException(sprintf('Core %s identifiers must use the core namespace.', $kind));
            }
            return;
        }

        if (
            !str_starts_with($identifier, $this->namespace() . '.')
            || ($requiresGraphicalSuffix && !self::hasSafeOwnedSuffix($identifier, $this->namespace()))
        ) {
            throw new InvalidArgumentException(sprintf(
                'Extension %s cannot claim %s identifier %s.',
                $this->identifier,
                $kind,
                $identifier,
            ));
        }
    }

    /**
     * Require non-empty graphical segments after the exact owner namespace boundary.
     *
     * Repeated or trailing dots inside an extension package segment are retained in `namespace()` for
     * compatibility with already-valid package identifiers. Only that exact owner prefix may contain
     * them; the contribution-specific suffix remains unambiguous and cannot start, end, or repeat a dot.
     *
     * @param   string  $identifier  Complete contribution identifier under evaluation.
     * @param   string  $namespace   Exact dotted namespace reserved by the expected owner.
     *
     * @return  bool  True when the identifier has a non-empty, unambiguous suffix.
     *
     * @since   2.0.0
     */
    private static function hasSafeOwnedSuffix(string $identifier, string $namespace): bool
    {
        $suffix = substr($identifier, strlen($namespace) + 1);

        return preg_match('/^[a-z0-9][a-z0-9_-]*(?:\.[a-z0-9][a-z0-9_-]*)*$/D', $suffix) === 1;
    }
}
