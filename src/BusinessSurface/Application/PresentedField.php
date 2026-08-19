<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Application;

/**
 * Fully typed view model one presented business field hands back across the rendering contract.
 *
 * The facade consumes exactly three things from a presented field: the escaped display text and the
 * conversion provenance, which it composes into selector labels and document-line cells, and the
 * complete exported semantic model, which it forwards untouched inside form and detail responses. This
 * value carries those three and nothing else, so the application layer receives presentation-ready data
 * without importing the presentation type that produced it, and a presenter cannot smuggle behaviour
 * back across the seam — there is no markup, no template path and no callable in here.
 *
 * @since  2.0.0
 */
final readonly class PresentedField
{
    /**
     * Capture one presented field as the rendering contract returns it.
     *
     * @param  string                 $display     Escaped text for read contexts, already composed by the
     *         presenter; for a converted amount this is the self-describing portable form.
     * @param  ?array<string, mixed>  $provenance  Conversion evidence exactly as the presenter attached
     *         it, or null when the value is not a converted amount.
     * @param  array<string, mixed>   $model       Complete markup-free semantic field model in the shape
     *         shared templates receive, including the display and provenance members again.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $display,
        public ?array $provenance,
        public array $model,
    ) {
    }
}
