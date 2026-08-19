<?php

declare(strict_types=1);

namespace Kumwe\App\InterfaceStandard;

use InvalidArgumentException;

/**
 * Durable identity of one presentation slot at one exact customization layer.
 *
 * The nullable external scope identifier is paired with a non-null storage key so database uniqueness
 * remains portable across PostgreSQL, MySQL, MariaDB, and SQLite despite their different null handling.
 *
 * @since  2.0.0
 */
final readonly class PresentationPreferenceKey
{
    /**
     * Validate the surface, slot, scope, and optional layer identity that form a preference key.
     *
     * @param   SurfaceId           $surface  Active semantic surface the slot belongs to.
     * @param   CustomizationSlot   $slot     Closed presentation choice stored at this key.
     * @param   CustomizationScope  $scope    Hierarchy layer whose value this key addresses.
     * @param   ?string             $scopeId  Site, workspace, or actor identity; null for a global layer.
     *
     * @throws  InvalidArgumentException  When a non-null scope identity violates the portable schema.
     *
     * @since   2.0.0
     */
    public function __construct(
        public SurfaceId $surface,
        public CustomizationSlot $slot,
        public CustomizationScope $scope,
        public ?string $scopeId,
    ) {
        self::assertScopeId($scopeId);
        self::assertScopeIdentity($scope, $scopeId);
    }

    /**
     * Build the key carried by an admitted preference record.
     *
     * @param   PresentationPreference  $preference  Record whose durable identity is required.
     *
     * @return  self  Key selecting exactly that record.
     *
     * @since   2.0.0
     */
    public static function fromPreference(PresentationPreference $preference): self
    {
        return new self(
            $preference->surface(),
            $preference->slot(),
            $preference->scope(),
            $preference->scopeId(),
        );
    }

    /**
     * Return a portable non-null key for persistence indexes.
     *
     * Empty non-null scope identifiers are rejected at construction, so the empty string unambiguously
     * represents the schema's null value and cannot collide with a real identifier.
     *
     * @return  string  Scope identity, or the empty sentinel for a global layer.
     *
     * @since   2.0.0
     */
    public function storageScopeKey(): string
    {
        return $this->scopeId ?? '';
    }

    /**
     * Compare two keys by their canonical scalar representation.
     *
     * @param   self  $other  Candidate key.
     *
     * @return  bool  True when every identity field is equal.
     *
     * @since   2.0.0
     */
    public function equals(self $other): bool
    {
        return $this->surface->value() === $other->surface->value()
            && $this->slot === $other->slot
            && $this->scope === $other->scope
            && $this->scopeId === $other->scopeId;
    }

    /**
     * Produce a bounded opaque subject identifier for the audit trail.
     *
     * @return  string  SHA-256 digest of the complete unambiguous key.
     *
     * @since   2.0.0
     */
    public function auditSubjectId(): string
    {
        return hash('sha256', implode("\n", [
            $this->surface->value(),
            $this->slot->value,
            $this->scope->value,
            $this->scopeId ?? "\0",
        ]));
    }

    /**
     * Validate a scope identity against the portable preference schema.
     *
     * @param   ?string  $scopeId  Candidate nullable site, workspace, or actor identity.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the identifier is empty, overlong, or markup/control-bearing.
     *
     * @since   2.0.0
     */
    public static function assertScopeId(?string $scopeId): void
    {
        $unsafe = $scopeId === null ? 0 : preg_match('/[<>{}\x00-\x1F\x7F]/u', $scopeId);
        if (
            $scopeId !== null
            && (
                mb_strlen($scopeId) < 1
                || mb_strlen($scopeId) > 191
                || $unsafe !== 0
            )
        ) {
            throw new InvalidArgumentException('A KIS presentation preference scope identity is invalid.');
        }
    }

    /**
     * Require global administrator identity and named identities for every contextual layer.
     *
     * @param   CustomizationScope  $scope    Candidate hierarchy layer.
     * @param   ?string             $scopeId  Candidate nullable site, workspace, or actor identity.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the identity presence does not match the selected scope.
     *
     * @since   2.0.0
     */
    public static function assertScopeIdentity(CustomizationScope $scope, ?string $scopeId): void
    {
        if ($scope === CustomizationScope::Administrator && $scopeId !== null) {
            throw new InvalidArgumentException('A KIS administrator preference must use the global identity.');
        }
        if ($scope !== CustomizationScope::Administrator && $scopeId === null) {
            throw new InvalidArgumentException(sprintf(
                'A KIS %s preference requires a named scope identity.',
                $scope->value,
            ));
        }
    }
}
