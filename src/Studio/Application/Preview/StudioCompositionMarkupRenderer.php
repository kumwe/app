<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Preview;

use InvalidArgumentException;
use Kumwe\App\Studio\Domain\Contract\CanonicalJson;
use stdClass;

/**
 * Safe structural HTML projection of Blueprint nodes with deterministic preview markers.
 *
 * @since  2.0.0
 */
final readonly class StudioCompositionMarkupRenderer
{
    /**
     * Bind safe binding evaluation to the owner-aware block renderer registry.
     *
     * @param  StudioPreviewBindingResolver        $bindings  Closed value-source evaluator.
     * @param  StudioPreviewBlockRendererRegistry  $blocks    Owner-safe fixed presentation registry.
     *
     * @since  2.0.0
     */
    public function __construct(
        private StudioPreviewBindingResolver $bindings,
        private StudioPreviewBlockRendererRegistry $blocks,
    ) {
    }

    /**
     * Render every root and descendant in canonical preview preorder.
     *
     * Only fixed element/class names are emitted. Stored strings are HTML-escaped and no script, style,
     * URL or arbitrary attribute from the draft is interpreted.
     *
     * @param   stdClass                    $draft      Schema-admitted Blueprint.
     * @param   list<string>                $markers    Exact preorder marker list.
     * @param   array<string, string>       $markerMap  Marker-to-node inventory.
     * @param   StudioPreviewBindingValues  $values     Authorized Content and host context values.
     * @param   string                      $viewport   Active semantic viewport for responsive layout intent.
     *
     * @return  string  Safe body fragment for the canonical site page template.
     *
     * @throws  InvalidArgumentException  When the marker inventory and tree diverge.
     *
     * @since   2.0.0
     */
    public function render(
        stdClass $draft,
        array $markers,
        array $markerMap,
        StudioPreviewBindingValues $values,
        string $viewport,
    ): string {
        $roots = $draft->roots ?? null;
        if (!is_array($roots)) {
            throw new InvalidArgumentException('A Studio composition requires Blueprint roots.');
        }
        $locks = self::blockReferences($draft);
        $ordinal = 0;
        $html = '';
        foreach ($roots as $root) {
            $html .= $this->node($root, $markers, $markerMap, $values, $viewport, $locks, $ordinal, false);
        }
        if ($ordinal !== count($markers)) {
            throw new InvalidArgumentException('The Studio preview marker inventory does not match the tree.');
        }

        return $html;
    }

    /**
     * Render an immutable published Blueprint without authoring-only marker attributes.
     *
     * Every lock and every node must resolve to an exact live renderer before any markup is returned.
     * This makes extension withdrawal, lock ambiguity, and node-to-lock drift hard publication failures
     * instead of silently omitting content from a public page.
     *
     * @param   stdClass                    $blueprint  Schema-admitted published Blueprint.
     * @param   StudioPreviewBindingValues  $values     Complete schema-governed public Content values.
     * @param   string                      $viewport   Semantic fallback passed to owner renderers; core public
     *          layout retains every bounded width.
     *
     * @return  string  Safe marker-free body fragment for the canonical site page template.
     *
     * @throws  InvalidArgumentException  When the Blueprint tree is malformed.
     * @throws  StudioPublishedBlockRendererUnavailable  When an exact live renderer is unavailable.
     *
     * @since   2.0.0
     */
    public function renderPublished(
        stdClass $blueprint,
        StudioPreviewBindingValues $values,
        string $viewport = 'expanded',
    ): string {
        $roots = $blueprint->roots ?? null;
        if (!is_array($roots)) {
            throw new InvalidArgumentException('A Studio composition requires Blueprint roots.');
        }
        $locks = self::blockReferences($blueprint);
        foreach ($locks as $reference) {
            if (!$reference instanceof StudioPreviewBlockReference) {
                throw new StudioPublishedBlockRendererUnavailable(
                    'ambiguous',
                    'unknown',
                    null,
                );
            }
            if (!$this->blocks->supports($reference)) {
                throw new StudioPublishedBlockRendererUnavailable(
                    $reference->type,
                    $reference->version,
                    $reference->revision,
                );
            }
        }
        $ordinal = 0;
        $html = '';
        foreach ($roots as $root) {
            $html .= $this->node($root, null, null, $values, $viewport, $locks, $ordinal, true);
        }

        return $html;
    }

    /**
     * Render one fixed structural node and descend through canonical slot order.
     *
     * @param   mixed                                            $candidate  Candidate Blueprint node.
     * @param   ?list<string>                                    $markers    Exact preorder markers, null publicly.
     * @param   ?array<string, string>                           $markerMap  Marker inventory, null publicly.
     * @param   StudioPreviewBindingValues                       $values     Authorized binding value namespaces.
     * @param string $viewport Active semantic viewport for responsive layout intent.
     * @param   array<string, StudioPreviewBlockReference|null>  $locks      Exact unambiguous dependency locks.
     * @param   int                                              $ordinal    Next marker ordinal, advanced by reference.
     * @param   bool                                             $published  Whether exact live locks are mandatory.
     *
     * @return  string  Safe node fragment.
     *
     * @throws  InvalidArgumentException  When the tree or inventory is malformed.
     *
     * @since   2.0.0
     */
    private function node(
        mixed $candidate,
        ?array $markers,
        ?array $markerMap,
        StudioPreviewBindingValues $values,
        string $viewport,
        array $locks,
        int &$ordinal,
        bool $published,
    ): string {
        if (
            !$candidate instanceof stdClass
            || !is_string($candidate->id ?? null)
            || !is_string($candidate->type ?? null)
            || !($candidate->slots ?? null) instanceof stdClass
        ) {
            throw new InvalidArgumentException('A Studio composition node is invalid.');
        }
        $type = $candidate->type;
        $marker = null;
        if (!$published) {
            $marker = $markers[$ordinal] ?? null;
            if (!is_string($marker) || ($markerMap[$marker] ?? null) !== $candidate->id) {
                throw new InvalidArgumentException('A Studio composition marker does not identify its node.');
            }
        }
        $ordinal++;
        $slots = $candidate->slots;
        $names = array_keys(get_object_vars($slots));
        usort($names, CanonicalJson::compareCodeUnits(...));
        $children = '';
        foreach ($names as $name) {
            $members = $slots->{$name};
            if (!is_array($members)) {
                throw new InvalidArgumentException('A Studio composition slot is invalid.');
            }
            foreach ($members as $member) {
                $children .= $this->node(
                    $member,
                    $markers,
                    $markerMap,
                    $values,
                    $viewport,
                    $locks,
                    $ordinal,
                    $published,
                );
            }
        }
        $nodeVersion = $candidate->version ?? null;
        $key = $type . "\0" . (is_string($nodeVersion) ? $nodeVersion : '');
        $version = is_string($nodeVersion)
            ? $nodeVersion
            : (in_array($type, CoreStudioPreviewBlockRendererRegistry::BLOCK_TYPES, true)
                ? CoreStudioPreviewBlockRendererRegistry::BLOCK_VERSION
                : 'unversioned');
        $reference = $locks[$key] ?? null;
        if (
            $published
            && (
                !$reference instanceof StudioPreviewBlockReference
                || !$reference->matchesNode($candidate)
                || !$this->blocks->supports($reference)
            )
        ) {
            throw new StudioPublishedBlockRendererUnavailable(
                $type,
                $version,
                $reference?->revision,
            );
        }
        $reference ??= new StudioPreviewBlockReference(
            $type,
            $version,
            CoreStudioPreviewBlockRendererRegistry::revisionFor($type),
        );
        $fragment = $this->blocks->render(
            $candidate,
            $reference,
            $this->bindings->resolve($candidate, $values),
            $viewport,
        );
        if ($published && $fragment->className === 'studio-preview-unresolved') {
            throw new StudioPublishedBlockRendererUnavailable($type, $version, $reference->revision);
        }
        $publicLayout = $published
            ? CoreStudioPreviewBlockRendererRegistry::publicLayoutAttributes($candidate, $type)
            : [];
        if ($publicLayout !== []) {
            $fragment = new StudioPreviewBlockFragment(
                $fragment->element,
                $fragment->className,
                $fragment->text,
                $fragment->hidden,
                $publicLayout,
            );
        }
        $content = $fragment->text === ''
            ? $children
            : '<p>' . self::escape($fragment->text) . '</p>' . $children;
        $hidden = $fragment->hidden ? ' hidden' : '';
        $layoutAttributes = '';
        foreach ($fragment->layoutAttributes as $name => $value) {
            $layoutAttributes .= sprintf(' %s="%s"', $name, self::escape($value));
        }

        $markerAttribute = $marker === null
            ? ''
            : ' data-studio-preview-marker="' . self::escape($marker) . '"';

        return sprintf(
            '<%1$s class="%2$s"%3$s%4$s%5$s>%6$s</%1$s>',
            $fragment->element,
            $fragment->className,
            $markerAttribute,
            $layoutAttributes,
            $hidden,
            $content,
        );
    }

    /**
     * Index exact block locks and make duplicate type/version coordinates unresolved.
     *
     * @param   stdClass  $draft  Schema-admitted Blueprint.
     *
     * @return  array<string, StudioPreviewBlockReference|null>  Type/version keys to exact locks.
     *
     * @throws  InvalidArgumentException  When the dependency-lock shape is unavailable.
     *
     * @since   2.0.0
     */
    private static function blockReferences(stdClass $draft): array
    {
        $dependencyLock = $draft->dependencyLock ?? null;
        if ($dependencyLock === null) {
            return [];
        }
        $blocks = $dependencyLock instanceof stdClass ? $dependencyLock->blocks ?? null : null;
        if (!is_array($blocks)) {
            throw new InvalidArgumentException('A Studio composition block dependency lock is invalid.');
        }
        $result = [];
        foreach ($blocks as $block) {
            if (
                !$block instanceof stdClass
                || !is_string($block->type ?? null)
                || !is_string($block->version ?? null)
                || !is_string($block->revision ?? null)
            ) {
                throw new InvalidArgumentException('A Studio composition block lock is invalid.');
            }
            $key = $block->type . "\0" . $block->version;
            $result[$key] = array_key_exists($key, $result)
                ? null
                : new StudioPreviewBlockReference($block->type, $block->version, $block->revision);
        }

        return $result;
    }

    /**
     * Escape one stored string for an HTML text or quoted-attribute position.
     *
     * @param   string  $value  Plain stored or projected text.
     *
     * @return  string  UTF-8 HTML-safe text.
     *
     * @since   2.0.0
     */
    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
