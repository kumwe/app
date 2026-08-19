<?php

declare(strict_types=1);

namespace Kumwe\App\Presentation;

use Kumwe\App\Content\Application\ContentRecord;

/**
 * Builds the escaped, template-ready public representation of a content record.
 *
 * Site templates print rendered bodies through Twig's `raw` filter, so the escaping has to have already
 * happened by the time the data reaches them. This presenter is where it happens: it takes the record
 * snapshot, walks the whole editor payload, and adds a `body_html` sibling wherever a `body` string is
 * stored. Public handlers therefore hand `SiteRenderer` a presented entry rather than a raw record, and
 * a template can trust every `*_html` key it emits.
 *
 * @since  2.0.0
 */
final readonly class ContentPresenter
{
    /**
     * Bind the presenter to the formatter that turns stored bodies into HTML.
     *
     * @param  RichTextFormatter  $richText  Formatter applied to every stored `body` string.
     *
     * @since  2.0.0
     */
    public function __construct(private RichTextFormatter $richText)
    {
    }

    /**
     * Presents one record as the `entry` array a site template renders.
     *
     * The record's own payload is only presented when it is a map; anything else is replaced with an
     * empty payload rather than passed through to the template.
     *
     * @param   ContentRecord  $record  Stored record to expose publicly, with its editor payload.
     *
     * @return  array<string, mixed>  The record snapshot whose `data` carries rendered `body_html`
     *          siblings, with the payload's top-level `body_html` promoted beside it as `''` when absent.
     *
     * @since   2.0.0
     */
    public function present(ContentRecord $record): array
    {
        $entry = $record->toArray();
        $data = $entry['data'] ?? [];
        $presentedData = is_array($data) ? $this->presentArray($data) : [];
        $entry['data'] = $presentedData;
        $bodyHtml = $presentedData['body_html'] ?? '';
        $entry['body_html'] = is_string($bodyHtml) ? $bodyHtml : '';

        return $entry;
    }

    /**
     * Walks a payload depth first, adding a rendered `body_html` beside every stored `body` string.
     *
     * Only maps gain the rendered sibling; a list is recursed into but never given keys of its own, so
     * repeated sections and nested blocks each get their own `body_html` while list shape is preserved.
     *
     * @param   array<array-key, mixed>  $values  Payload fragment in stored shape.
     *
     * @return  array<array-key, mixed>  The same fragment with rendered siblings added in place.
     *
     * @since   2.0.0
     */
    private function presentArray(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->presentArray($value);
            }
        }
        if (!array_is_list($values) && is_string($values['body'] ?? null)) {
            $values['body_html'] = $this->richText->format($values['body']);
        }

        return $values;
    }
}
