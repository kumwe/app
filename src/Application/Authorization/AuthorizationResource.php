<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Authorization;

use InvalidArgumentException;

/**
 * Subject of an authorization decision: a resource family paired with the identity being acted on.
 *
 * Every call into `AuthorizationGateway` names its target with one of these, so the policy registry, the
 * site-ownership resolver and the decision audit all speak one vocabulary. Both halves are validated once,
 * at construction, which lets consumers concatenate them into log lines and query parameters without
 * re-checking them. Reach for `collection()` when the operation covers a whole family — a listing, or a
 * create where no identifier exists yet — and `item()` when it targets one addressable resource.
 *
 * @since  2.0.0
 */
final readonly class AuthorizationResource
{
    /**
     * Validate the pair that names the resource.
     *
     * @param   string  $type        Resource family the policy registry keys on, such as `content` or `extension`.
     * @param   string  $identifier  Identity of the single resource, or `*` for a whole collection.
     *
     * @throws  InvalidArgumentException  When the type is not a short lowercase identifier, or when the
     *          identifier is empty, longer than 191 bytes, or carries a control character.
     *
     * @since   2.0.0
     */
    private function __construct(private string $type, private string $identifier)
    {
        if (preg_match('/^[a-z][a-z0-9._-]{0,62}$/D', $type) !== 1) {
            throw new InvalidArgumentException('An authorization resource type must be a lowercase identifier.');
        }

        if ($identifier === '' || strlen($identifier) > 191 || preg_match('/[\x00-\x1F\x7F]/', $identifier) === 1) {
            throw new InvalidArgumentException('An authorization resource identifier is invalid.');
        }
    }

    /**
     * Name a whole family of resources rather than one member of it.
     *
     * A collection carries `*` as its identifier, which `DenyByDefaultAuthorizationGateway` treats as owned
     * by the calling site instead of asking the ownership registry — there is no single row to own.
     *
     * @param   string  $type  Resource family being listed or added to.
     *
     * @return  self  Target whose identifier is `*`.
     *
     * @throws  InvalidArgumentException  When the type is not a short lowercase identifier.
     *
     * @since   2.0.0
     */
    public static function collection(string $type): self
    {
        return new self($type, '*');
    }

    /**
     * Name one addressable resource within a family.
     *
     * Surrounding whitespace is stripped before validation, so a raw route segment or request field can be
     * handed over as it arrived.
     *
     * @param   string  $type        Resource family the identifier belongs to.
     * @param   string  $identifier  Identity of the resource, usually its primary key or slug.
     *
     * @return  self  Target carrying the trimmed identifier.
     *
     * @throws  InvalidArgumentException  When the type or the trimmed identifier fails validation.
     *
     * @since   2.0.0
     */
    public static function item(string $type, string $identifier): self
    {
        return new self($type, trim($identifier));
    }

    /**
     * Report which resource family the target belongs to.
     *
     * @return  string  Lowercase identifier the policy and ownership registries key on, such as `content`.
     *
     * @since   2.0.0
     */
    public function type(): string
    {
        return $this->type;
    }

    /**
     * Report which resource within the family is being acted on.
     *
     * @return  string  The trimmed identifier, or `*` when the target stands for the whole collection.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return $this->identifier;
    }
}
