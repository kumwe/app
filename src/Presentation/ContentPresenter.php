<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation;

use Kumwe\CMS\Content\Application\ContentRecord;

/** Builds the escaped, template-ready public representation of a content record. */
final readonly class ContentPresenter
{
    public function __construct(private RichTextFormatter $richText)
    {
    }

    /** @return array<string, mixed> */
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
     * @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
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
