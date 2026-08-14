<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Trust;

use RuntimeException;

/**
 * Refusal raised when an upstream revocation list was served but must not be believed.
 *
 * It exists as its own type so the scheduled job can tell the one condition that is an incident from
 * every other way a run can fail. An unreachable origin never reaches here — that is recorded and
 * tolerated — and a database error is not this either. What is: a signature that does not verify
 * against the pinned key, a statement past its freshness window, a sequence at or below the one already
 * applied, and a malformed envelope. Each means the bytes an installation was handed are wrong in a way
 * only a misconfiguration or an attacker produces, so the job buries the occurrence rather than
 * retrying it.
 *
 * @since  2.0.0
 */
final class RevocationListRefused extends RuntimeException
{
}
