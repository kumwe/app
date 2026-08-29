<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Domain\Preview;

use InvalidArgumentException;
use Kumwe\Producer\Canonical\CanonicalEncodingException;
use Kumwe\Producer\Canonical\CanonicalJson;
use stdClass;

/**
 * Pinned preview digest and deterministic Blueprint preorder marker inventory.
 *
 * @since  2.0.0
 */
final class StudioPreviewIdentity
{
    /**
     * Compute the exact preview identity required by `studio.profile/preview-identity-v1`.
     *
     * Roots retain array order; every node is visited before its descendants; slot member names are
     * sorted by UTF-16 code unit and each slot's child array retains order.
     *
     * @param   stdClass  $draft  Complete Blueprint draft.
     *
     * @return  array{draftDigest: string, markers: list<string>, markerMap: array<string, string>}
     *          Exact digest and marker inventory.
     *
     * @throws  InvalidArgumentException  When a node, slot object, or node identity is malformed.
     * @throws  CanonicalEncodingException  When the draft is not representable as canonical JSON.
     *
     * @since   2.0.0
     */
    public static function forDraft(stdClass $draft): array
    {
        $roots = $draft->roots ?? null;
        if (!is_array($roots)) {
            throw new InvalidArgumentException('A Studio preview Blueprint requires a roots list.');
        }
        $digest = hash('sha256', CanonicalJson::stringify($draft));
        $nodeIds = [];
        foreach ($roots as $node) {
            self::walk($node, $nodeIds);
        }
        if (count($nodeIds) > 100_000) {
            throw new InvalidArgumentException('A Studio preview exceeds the marker inventory limit.');
        }
        $markers = [];
        $markerMap = [];
        foreach ($nodeIds as $ordinal => $nodeId) {
            $marker = sprintf('studio.preview/node/%s/%d', $digest, $ordinal);
            $markers[] = $marker;
            $markerMap[$marker] = $nodeId;
        }

        return ['draftDigest' => $digest, 'markers' => $markers, 'markerMap' => $markerMap];
    }

    /**
     * Append one node and descend through its slots in canonical order.
     *
     * @param   mixed         $candidate  Candidate Blueprint node.
     * @param   list<string>  $nodeIds    Accumulated preorder identifiers.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When node structure is malformed or an identifier repeats.
     *
     * @since   2.0.0
     */
    private static function walk(mixed $candidate, array &$nodeIds): void
    {
        if (!$candidate instanceof stdClass || !is_string($candidate->id ?? null)) {
            throw new InvalidArgumentException('A Studio preview node identity is invalid.');
        }
        if (in_array($candidate->id, $nodeIds, true)) {
            throw new InvalidArgumentException('A Studio preview node identity is duplicated.');
        }
        $nodeIds[] = $candidate->id;
        $slots = $candidate->slots ?? null;
        if (!$slots instanceof stdClass) {
            throw new InvalidArgumentException('A Studio preview node requires a slot object.');
        }
        $names = array_keys(get_object_vars($slots));
        usort($names, CanonicalJson::compareCodeUnits(...));
        foreach ($names as $name) {
            $children = $slots->{$name};
            if (!is_array($children)) {
                throw new InvalidArgumentException('A Studio preview slot requires a child list.');
            }
            foreach ($children as $child) {
                self::walk($child, $nodeIds);
            }
        }
    }
}
