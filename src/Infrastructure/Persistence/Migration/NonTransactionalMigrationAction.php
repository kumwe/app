<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

/** Describes how the runner must continue after durable recovery preparation. */
enum NonTransactionalMigrationAction
{
    case Execute;
    case RecordRecovered;
}
