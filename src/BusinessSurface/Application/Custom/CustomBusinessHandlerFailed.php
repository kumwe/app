<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSurface\Application\Custom;

use RuntimeException;
use Throwable;

/**
 * Hides an extension handler's uncontrolled failure while retaining its operator-visible cause.
 *
 * Delivery adapters may serialize modeled application exception messages. Extension code is therefore
 * invoked behind this fixed failure category: callers receive no arbitrary handler text, while logs and
 * transaction rollback retain the original throwable through the exception chain.
 *
 * @since  2.0.0
 */
final class CustomBusinessHandlerFailed extends RuntimeException
{
    /**
     * Replace one handler failure with stable caller-safe text.
     *
     * @param  Throwable  $previous  Original extension failure retained for diagnostics and rollback.
     *
     * @since  2.0.0
     */
    public function __construct(Throwable $previous)
    {
        parent::__construct('The custom business handler could not complete the request.', 0, $previous);
    }
}
