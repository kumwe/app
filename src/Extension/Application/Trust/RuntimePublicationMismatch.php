<?php

declare(strict_types=1);

namespace Kumwe\CMS\Extension\Application\Trust;

use RuntimeException;

/** Replica-local publication drift; authoritative extension state remains valid. */
final class RuntimePublicationMismatch extends RuntimeException
{
}
