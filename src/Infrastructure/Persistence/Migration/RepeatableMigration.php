<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Persistence\Migration;

/**
 * Opt-in contract for new migrations whose complete up() operation may be repeated after a crash.
 *
 * Existing immutable migrations are classified by the recovery service without changing their
 * already-distributed source bytes or checksums.
 */
interface RepeatableMigration extends Migration
{
}
