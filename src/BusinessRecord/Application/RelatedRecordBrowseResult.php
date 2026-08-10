<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessRecord\Application;

use InvalidArgumentException;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;

/**
 * One policy-filtered target page paired with the definition needed for semantic presentation.
 *
 * @since  2.0.0
 */
final readonly class RelatedRecordBrowseResult
{
    /**
     * Policy-visible target fields the selector may use for bounded text search.
     *
     * @var    list<array{handle: string, label: string}>
     * @since  2.0.0
     */
    public array $searchFields;

    /**
     * Capture a target definition and the bounded page projected through its related-access plan.
     *
     * @param   EntityTypeDefinition                        $definition    Active target definition.
     * @param   RecordBrowseResult                          $page          Policy-filtered target page.
     * @param   list<array{handle: string, label: string}>  $searchFields  At most sixteen safe search controls.
     *
     * @throws  InvalidArgumentException  When search metadata is malformed, duplicated, or over its bound.
     *
     * @since   2.0.0
     */
    public function __construct(
        public EntityTypeDefinition $definition,
        public RecordBrowseResult $page,
        array $searchFields = [],
    ) {
        if (!array_is_list($searchFields) || count($searchFields) > 16) {
            throw new InvalidArgumentException('Related-record search metadata is invalid or unbounded.');
        }
        $seen = [];
        foreach ($searchFields as $field) {
            if (
                !is_array($field) || array_is_list($field)
                || array_diff(array_keys($field), ['handle', 'label']) !== []
                || count($field) !== 2
                || !is_string($field['handle'] ?? null)
                || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $field['handle']) !== 1
                || !is_string($field['label'] ?? null)
                || $field['label'] === '' || strlen($field['label']) > 120
                || isset($seen[$field['handle']])
            ) {
                throw new InvalidArgumentException('Related-record search metadata is invalid or duplicated.');
            }
            $seen[$field['handle']] = true;
        }
        $this->searchFields = $searchFields;
    }
}
