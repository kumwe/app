<?php

declare(strict_types=1);

namespace Kumwe\App\Localization\Infrastructure;

/**
 * One authored XLIFF document, read into the shape the catalogue compiler works from.
 *
 * It is the interchange format made addressable, nothing more: the declared source and target
 * languages, and every translation unit keyed by the message identifier its `id` attribute carries.
 * A unit keeps both halves because the compiler prefers a translated target and falls back to the
 * source, which is what lets the source catalogue and a translated catalogue be compiled by exactly
 * the same code.
 *
 * @since  2.0.0
 */
final readonly class XliffCatalogue
{
    /**
     * Hold a parsed document.
     *
     * @param  string                                                 $sourceLanguage  Value of the
     *         document's `srcLang` attribute.
     * @param  ?string                                                $targetLanguage  Value of the
     *         document's `trgLang` attribute, or null when the document declares none.
     * @param  array<string, array{source: string, target: ?string}>  $units           Source and target
     *         text keyed by message identifier, in document order.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $sourceLanguage,
        public ?string $targetLanguage,
        public array $units,
    ) {
    }

    /**
     * The text each unit contributes to a compiled catalogue.
     *
     * The target wins when the document carries one, because a translated catalogue is authored as
     * targets against untouched sources. The source is used otherwise, which is what makes the
     * `en-GB` document — where source and target are the same language — compile without every unit
     * having to repeat itself.
     *
     * @return  array<string, string>  ICU patterns keyed by message identifier, in document order.
     *
     * @since   2.0.0
     */
    public function patterns(): array
    {
        $patterns = [];
        foreach ($this->units as $identifier => $unit) {
            $target = $unit['target'];
            $patterns[$identifier] = $target === null || $target === '' ? $unit['source'] : $target;
        }

        return $patterns;
    }
}
