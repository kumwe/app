<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Projection;

use Kumwe\App\Studio\Domain\Projection\StudioProjectionRejection;
use RuntimeException;
use stdClass;

/**
 * Typed, non-disclosing refusal returned when a Studio model projection cannot be exact.
 *
 * Source values and authorization details never enter the exception message. Callers may serialize
 * {@see diagnostic()} into the canonical host error envelope while logs retain the stable rejection
 * and JSON Pointer needed to identify which mapping rule requires attention.
 *
 * @since  2.0.0
 */
final class StudioProjectionRejected extends RuntimeException
{
    /**
     * Record the stable refusal and safe source path.
     *
     * @param  StudioProjectionRejection  $rejection  Closed reason the projection stopped.
     * @param  string                     $path       JSON Pointer into the source shape, or empty at its root.
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly StudioProjectionRejection $rejection,
        public readonly string $path = '',
    ) {
        parent::__construct('The requested content projection is unavailable.');
    }

    /**
     * Build the pinned Studio diagnostic shape without exposing source values or denied identifiers.
     *
     * @return  stdClass  Blocking diagnostic carrying the stable reason and optional JSON Pointer.
     *
     * @since   2.0.0
     */
    public function diagnostic(): stdClass
    {
        $diagnostic = new stdClass();
        $diagnostic->code = 'kumwe.app/projection-' . $this->rejection->value;
        $diagnostic->severity = 'blocking';
        $diagnostic->message = (object) [
            'key' => 'kumwe.studio/projection-unavailable',
            'defaultMessage' => 'This content cannot be projected safely.',
        ];
        if ($this->path !== '' && mb_strlen($this->path, 'UTF-8') <= 1000) {
            $diagnostic->location = (object) ['jsonPointer' => $this->path];
        }
        $diagnostic->parameters = (object) ['reason' => $this->rejection->value];

        return $diagnostic;
    }
}
