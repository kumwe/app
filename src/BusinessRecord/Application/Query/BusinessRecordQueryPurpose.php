<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessRecord\Application\Query;

/**
 * Security purpose under which a record collection is evaluated and disclosed.
 *
 * @since  2.0.0
 */
enum BusinessRecordQueryPurpose: string
{
    /** Ordinary interactive collection browsing. @since 2.0.0 */
    case Browse = 'browse';

    /** Reporting, including grouped or aggregate output. @since 2.0.0 */
    case Report = 'report';

    /** Export disclosure intended to leave the interactive surface. @since 2.0.0 */
    case Export = 'export';
}
