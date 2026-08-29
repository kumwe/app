<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Domain\Preview;

use InvalidArgumentException;
use stdClass;

/**
 * One fully rendered, protocol-indexed preview document awaiting a bounded claim.
 *
 * @since  2.0.0
 */
final readonly class StudioPreviewRenderedDocument
{
    /**
     * Retain trusted rendered HTML and the exact protocol inventory it contains.
     *
     * @param   string                 $html             Complete same-origin HTML document.
     * @param   list<string>           $markers          Canonical preorder markers.
     * @param   array<string, string>  $markerMap        Marker to stable Blueprint node identity.
     * @param   list<stdClass>         $diagnostics      Safe canonical rendering diagnostics.
     * @param   string|null            $stylesheet       Exact combined Producer and generated theme CSS.
     *
     * @throws  InvalidArgumentException  When the document or inventory is incoherent.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $html,
        public array $markers,
        public array $markerMap,
        public array $diagnostics = [],
        public ?string $stylesheet = null,
    ) {
        if ($html === '' || strlen($html) > 16_777_215 || count($markers) !== count($markerMap)) {
            throw new InvalidArgumentException('The rendered Studio preview document is invalid.');
        }
        foreach ($markers as $marker) {
            if (!isset($markerMap[$marker])) {
                throw new InvalidArgumentException('The Studio preview marker inventory is inconsistent.');
            }
        }
        if (
            $stylesheet !== null
            && (
                $stylesheet === ''
                || strlen($stylesheet) > 16_777_215
                || !mb_check_encoding($stylesheet, 'UTF-8')
            )
        ) {
            throw new InvalidArgumentException('The rendered Studio preview stylesheet is invalid.');
        }
    }
}
