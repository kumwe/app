<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Domain;

/**
 * Who a business definition belongs to, and the handle namespace that ownership reserves.
 *
 * Every entity definition and field type is named under its owner's namespace, and that is what keeps one
 * supplier from declaring an identifier that belongs to another: a package's contributions are checked
 * against `DefinitionOwner::extension()` while its manifest is parsed, and the administrator may only
 * save or retire definitions whose owner is the current site. The type fixes both the shape a valid
 * identifier takes and how the namespace is spelled, so construction refuses a pair that disagrees and no
 * unvalidated owner can reach the catalog.
 *
 * @since  2.0.0
 */
final readonly class DefinitionOwner
{
    /**
     * Pair an ownership kind with the identifier that names the owner.
     *
     * @param   DefinitionOwnerType  $type        Kind of owner, which decides the shape required below.
     * @param   string               $identifier  `core` for the platform, a `vendor/package` name for an
     *          extension, or the site identifier for a site.
     *
     * @throws  InvalidBusinessDefinition  When the identifier does not match the shape its type requires.
     *
     * @since   2.0.0
     */
    public function __construct(public DefinitionOwnerType $type, public string $identifier)
    {
        $pattern = match ($type) {
            DefinitionOwnerType::Core => '/^core$/D',
            DefinitionOwnerType::Extension => '#^[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*$#D',
            DefinitionOwnerType::Site => '/^[a-z0-9][a-z0-9._-]{0,190}$/D',
        };
        if (preg_match($pattern, $identifier) !== 1) {
            throw new InvalidBusinessDefinition('The business-definition owner identifier is invalid.');
        }
    }

    /**
     * Owner that definitions shipped with the platform itself are declared under.
     *
     * @return  self  The core owner, whose namespace is `core`.
     *
     * @since   2.0.0
     */
    public static function core(): self
    {
        return new self(DefinitionOwnerType::Core, 'core');
    }

    /**
     * Owner for definitions an installed package contributes.
     *
     * @param   string  $identifier  Package name as `vendor/package`; trimmed and lowercased first, so a
     *          manifest's casing does not decide which namespace it reserves.
     *
     * @return  self  The extension owner, whose namespace is the package name with `/` written as `.`.
     *
     * @throws  InvalidBusinessDefinition  When the name is not two identifier segments joined by a slash.
     *
     * @since   2.0.0
     */
    public static function extension(string $identifier): self
    {
        return new self(DefinitionOwnerType::Extension, strtolower(trim($identifier)));
    }

    /**
     * Owner for definitions authored inside one site through the administrator.
     *
     * @param   string  $identifier  Site identifier, trimmed and lowercased first.
     *
     * @return  self  The site owner, whose namespace is `site.` followed by that identifier.
     *
     * @throws  InvalidBusinessDefinition  When the identifier does not open with a letter or digit, holds
     *          a character outside `a-z0-9._-`, or runs past 191 characters.
     *
     * @since   2.0.0
     */
    public static function site(string $identifier): self
    {
        return new self(DefinitionOwnerType::Site, strtolower(trim($identifier)));
    }

    /**
     * Prefix that every handle this owner declares has to sit under.
     *
     * @return  string  `core` for the platform, the package name with `/` replaced by `.` for an
     *          extension, and `site.` followed by the identifier for a site.
     *
     * @since   2.0.0
     */
    public function namespace(): string
    {
        return match ($this->type) {
            DefinitionOwnerType::Core => 'core',
            DefinitionOwnerType::Extension => str_replace('/', '.', $this->identifier),
            DefinitionOwnerType::Site => 'site.' . $this->identifier,
        };
    }

    /**
     * Refuse a handle that falls outside this owner's namespace.
     *
     * This is the check that stops a package from claiming an identifier it does not own, so it runs over
     * every contributed entity handle and field-type identifier before a contribution set is accepted,
     * and again inside `EntityTypeDefinition` so a definition can never be built with a foreign handle.
     *
     * @param   string  $handle  Namespaced definition or field-type handle to test.
     *
     * @return  void
     *
     * @throws  InvalidBusinessDefinition  When the handle does not open with this namespace and a dot.
     *
     * @since   2.0.0
     */
    public function assertOwns(string $handle): void
    {
        if (!str_starts_with($handle, $this->namespace() . '.')) {
            throw new InvalidBusinessDefinition(sprintf(
                'Definition %s is outside the %s owner namespace.',
                $handle,
                $this->namespace(),
            ));
        }
    }

    /**
     * Export the owner in the shape the catalog stores and ownership comparisons run over.
     *
     * Package synchronization decides whether a stored definition still belongs to the extension being
     * synchronized by comparing two of these arrays, so the shape is part of the persisted contract.
     *
     * @return  array{type: string, identifier: string}  The owner type's backing string and the
     *          identifier exactly as it was validated.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return ['type' => $this->type->value, 'identifier' => $this->identifier];
    }
}
