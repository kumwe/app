<?php

declare(strict_types=1);

namespace Kumwe\CMS\Application\Presentation\Preference;

/**
 * Read-only boundary projecting access-control roles as KIS presentation access groups.
 *
 * Implementations read the canonical role and assignment tables rather than maintaining a parallel
 * registry. Optional pessimistic locks belong to the caller's existing transaction and keep a group
 * selection stable while an authorization-sensitive preference decision is made.
 *
 * @since  2.0.0
 */
interface PresentationAccessGroupRepository
{
    /**
     * List the presentation access groups assigned directly to one user.
     *
     * @param   string  $userId  Canonical user UUID whose direct role assignments are projected.
     * @param   bool    $lock    Whether supported databases should hold the selected rows for the transaction.
     *
     * @return  list<PresentationAccessGroup>  Assigned groups in deterministic display order.
     *
     * @since   2.0.0
     */
    public function listForUser(string $userId, bool $lock = false): array;

    /**
     * List every role available as a presentation access group.
     *
     * @param   bool  $lock  Whether supported databases should hold the selected rows for the transaction.
     *
     * @return  list<PresentationAccessGroup>  All groups in deterministic display order.
     *
     * @since   2.0.0
     */
    public function listAll(bool $lock = false): array;

    /**
     * Determine whether one stable presentation access-group identity still names a live role.
     *
     * @param   string  $identifier  Candidate `role:<uuid>` presentation identity.
     * @param   bool    $lock        Whether supported databases should hold the matching row for the transaction.
     *
     * @return  bool  True only when the identifier is canonical and its role exists.
     *
     * @since   2.0.0
     */
    public function exists(string $identifier, bool $lock = false): bool;
}
