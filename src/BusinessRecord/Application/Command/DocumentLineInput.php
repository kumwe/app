<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application\Command;

use InvalidArgumentException;
use Kumwe\CMS\BusinessRecord\Application\RecordRequestGuard;

/**
 * One submitted line of a document, in the order the caller wants it stored.
 *
 * A line has no position of its own here, and that is deliberate: its slot is where it sits in the
 * command's list, so two lines can never claim the same position and a caller can never leave a hole.
 * Identity is optional in the same spirit — a line the caller has never seen before simply carries none
 * and is given one, while a line being amended names the identity the document already knows it by.
 *
 * @since  2.0.0
 */
final readonly class DocumentLineInput
{
    /**
     * Values submitted for this line, keyed by field handle.
     *
     * @var    array<string, mixed>
     * @since  2.0.0
     */
    public array $values;

    /**
     * Validate one submitted line and freeze it.
     *
     * @param   array<string, mixed>  $values    Line values keyed by field handle, judged later against
     *          the line entity's own field rules rather than the document header's.
     * @param   ?string               $recordId  Identity the line is known by inside this document, or
     *          null for a line the caller is adding now; a reference-identity line type may equally carry
     *          its identity among the values.
     *
     * @throws  InvalidArgumentException  When the identity is empty, over 191 bytes or carries a control
     *          character, or the values are unbounded, keyed by something that is not a field handle, or
     *          carry a value the record layer refuses to store.
     *
     * @since   2.0.0
     */
    public function __construct(array $values, public ?string $recordId = null)
    {
        if ($recordId !== null) {
            RecordRequestGuard::record($recordId);
        }
        RecordRequestGuard::values($values, true);
        $this->values = $values;
    }
}
