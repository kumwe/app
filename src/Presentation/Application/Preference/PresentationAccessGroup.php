<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Application\Preference;

use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/**
 * Read-only presentation identity projected from one canonical access-control role.
 *
 * The prefixed identifier is the value a KIS `role-workspace` preference stores. Keeping the role UUID
 * beside that identifier lets infrastructure join the existing identity tables without creating a
 * second access-group registry, while the role code and name provide stable machine and display labels.
 *
 * @since  2.0.0
 */
final readonly class PresentationAccessGroup
{
    /**
     * Hold a role projection after `fromRole()` has validated every stored field.
     *
     * @param  string  $id      Stable `role:<uuid>` presentation identity.
     * @param  string  $roleId  Canonical lowercase UUID stored by the identity runtime.
     * @param  string  $code    Stable lowercase role code.
     * @param  string  $name    Human-readable role name.
     *
     * @since  2.0.0
     */
    private function __construct(
        public string $id,
        public string $roleId,
        public string $code,
        public string $name,
    ) {
    }

    /**
     * Build a presentation access group from one role row.
     *
     * @param   string  $roleId  Canonical lowercase UUID stored by the identity runtime.
     * @param   string  $code    Stable lowercase role code.
     * @param   string  $name    Human-readable role name.
     *
     * @return  self  Validated role projection with a stable prefixed identifier.
     *
     * @throws  InvalidArgumentException  When the role identity, code, or name violates the identity schema.
     *
     * @since   2.0.0
     */
    public static function fromRole(string $roleId, string $code, string $name): self
    {
        if (self::roleIdFromIdentifier('role:' . $roleId) !== $roleId) {
            throw new InvalidArgumentException('A presentation access-group role ID must be a canonical UUID.');
        }
        if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/D', $code) !== 1) {
            throw new InvalidArgumentException('A presentation access-group role code is invalid.');
        }
        if (trim($name) === '' || mb_strlen($name) > 191) {
            throw new InvalidArgumentException(
                'A presentation access-group role name must contain 1 to 191 characters.',
            );
        }

        return new self('role:' . $roleId, $roleId, $code, $name);
    }

    /**
     * Decode a stable presentation identity into the role UUID it names.
     *
     * This is deliberately a nullable parser so authorization callers can reject request or stored
     * identities without converting ordinary non-membership into an exception.
     *
     * @param   string  $identifier  Candidate `role:<uuid>` presentation access-group identity.
     *
     * @return  ?string  Canonical role UUID, or null when the identifier is not a role access group.
     *
     * @since   2.0.0
     */
    public static function roleIdFromIdentifier(string $identifier): ?string
    {
        if (!str_starts_with($identifier, 'role:')) {
            return null;
        }
        $roleId = substr($identifier, strlen('role:'));
        if (!Uuid::isValid($roleId) || Uuid::fromString($roleId)->toString() !== $roleId) {
            return null;
        }

        return $roleId;
    }
}
