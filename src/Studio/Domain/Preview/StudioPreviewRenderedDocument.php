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
     * @param   string|null            $themeStylesheet  Closed generated theme CSS, or null when absent.
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
        public ?string $themeStylesheet = null,
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
            $themeStylesheet !== null
            && (
                strlen($themeStylesheet) > 8_192
                || preg_match('/^body\{(?:--[a-z0-9-]+:#[a-f0-9]{6};)+\}$/D', $themeStylesheet) !== 1
            )
        ) {
            throw new InvalidArgumentException('The rendered Studio preview theme stylesheet is invalid.');
        }
    }
}
