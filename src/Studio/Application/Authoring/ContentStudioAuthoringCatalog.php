<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Authoring;

use Kumwe\App\Studio\Application\Composition\StudioCompositionContributionCatalog;
use Kumwe\App\Studio\Application\Release\StudioCoreCatalog;
use Kumwe\App\Studio\Application\Rendering\StudioBlockRendererRuntime;
use Kumwe\Localization\Application\Translator;
use Kumwe\Producer\Canonical\CanonicalJson;
use LogicException;
use stdClass;

/**
 * The one block catalog a contextual Content authoring session, its deployment and its saves share.
 *
 * Studio's hosted runtime admits a block only when the resolved session locks it and the lock is
 * either compiled into the pinned browser module or admitted by the resolved authoring target from
 * the deployment's contribution bundle. The App therefore locks the union of the first-party
 * catalog the pinned release compiles in and its own Content-field blocks, ships those field blocks
 * (and its patterns) as the bundle, and declares them as the target's contribution dependencies.
 * Every member is derived deterministically from the same inputs, so the deployment document, the
 * `authoring/start` snapshot and each accepted save agree on the catalog and on its generation. One
 * operation reads several members, and each read projects the live contributions again, so the
 * operation runs inside `consistently()`: the renderer registry is decided once for it, and every member
 * it emits comes from that one projection even if trust authority changes while it runs.
 *
 * @since  2.0.0
 */
final readonly class ContentStudioAuthoringCatalog
{
    /**
     * Compose the pinned first-party catalog with the App's live contribution projection.
     *
     * @param  StudioCompositionContributionCatalog  $contributions  Live App contribution projection.
     * @param  StudioCoreCatalog                     $core           Exact first-party coordinates.
     * @param  StudioBlockRendererRuntime            $runtime        The renderer registry authority the
     *         contribution projection reads; the same shared instance, so one decision pins both.
     * @param  Translator                            $translator     Interface-locale text for the App's own
     *         palette labels.
     *
     * @since  2.0.0
     */
    public function __construct(
        private StudioCompositionContributionCatalog $contributions,
        private StudioCoreCatalog $core,
        private StudioBlockRendererRuntime $runtime,
        private Translator $translator,
    ) {
    }

    /**
     * Run one authoring operation against a single contribution projection.
     *
     * The deployment document, a target resolution, a started session and a save each read the locks,
     * payloads, dependencies and generation more than once; inside this call they all derive from one
     * renderer registry decision, so a document can never pair payloads from one projection with the
     * generation of another.
     *
     * @template T
     *
     * @param   callable(): T  $operation  The authoring operation to run.
     *
     * @return  T  Whatever the operation returned, passed back unchanged.
     *
     * @since   2.0.0
     */
    public function consistently(callable $operation): mixed
    {
        return $this->runtime->consistently($operation);
    }

    /**
     * Every exact block lock a contextual session offers, in type order.
     *
     * @return  list<stdClass>  `{type, version, revision}` locks.
     *
     * @throws  LogicException  When an App block reuses a first-party type at another coordinate.
     *
     * @since   2.0.0
     */
    public function blockLocks(): array
    {
        $locks = [];
        foreach ($this->core->blockCoordinates() as $coordinate) {
            $locks[$coordinate->type] = (object) [
                'type' => $coordinate->type,
                'version' => $coordinate->version,
                'revision' => $coordinate->revision,
            ];
        }
        foreach ($this->projection()->blockLocks as $lock) {
            $type = $lock->type;
            if (!is_string($type)) {
                continue;
            }
            $existing = $locks[$type] ?? null;
            if ($existing === null) {
                $locks[$type] = $lock;
                continue;
            }
            if ($existing->version !== $lock->version || $existing->revision !== $lock->revision) {
                throw new LogicException(sprintf(
                    'The App block %s is declared at a coordinate the pinned Studio release does not compile in.',
                    $type,
                ));
            }
        }
        ksort($locks, SORT_STRING);

        return array_values($locks);
    }

    /**
     * The block locks this deployment can render in preview and on the public site.
     *
     * The session locks the whole compiled first-party catalog so every block can be authored, but a
     * reusable type's stored Blueprint may only compose blocks the App's trusted renderer runtime renders:
     * a published composition is refused rather than silently degraded when a block has no renderer.
     *
     * @return  list<stdClass>  Exact `{type, version, revision}` locks with a live renderer.
     *
     * @since   2.0.0
     */
    public function renderableBlockLocks(): array
    {
        return $this->projection()->blockLocks;
    }

    /**
     * The App-owned canonical documents the browser must admit for its target: field blocks and patterns.
     *
     * Documents whose coordinates the pinned module already compiles in are never shipped twice, and
     * executable-adjacent kinds (field adapters, inspectors, design vocabularies, migrations) stay on
     * the server: the contextual surface binds fields through Studio's first-party controls. Studio renders a
     * contribution's labels by their default text, so the App's own field blocks and pattern are shipped with
     * that text in the interface locale; extension documents keep the text their owner declared.
     *
     * @return  list<stdClass>  Canonical `block-definition` and `pattern` documents in identity order.
     *
     * @since   2.0.0
     */
    public function contributionPayloads(): array
    {
        $payloads = [];
        foreach ($this->projection()->documents as $document) {
            $kind = $document->kind ?? null;
            if ($kind === 'block-definition') {
                $type = $document->type ?? null;
                if (is_string($type) && !$this->core->hasBlock($type)) {
                    $payloads['block-definition' . "\0" . $type] = $this->localized($document);
                }
                continue;
            }
            if ($kind === 'pattern') {
                $id = $document->id ?? null;
                if (is_string($id) && !$this->core->hasPattern($id)) {
                    $payloads['pattern' . "\0" . $id] = $this->localized($document);
                }
            }
        }
        ksort($payloads, SORT_STRING);

        return array_values($payloads);
    }

    /**
     * The exact contribution dependencies the Content authoring target declares.
     *
     * @return  list<stdClass>  Schema-valid `contributionDependency` members, all required.
     *
     * @since   2.0.0
     */
    public function contributionDependencies(): array
    {
        $dependencies = [];
        foreach ($this->contributionPayloads() as $payload) {
            $kind = $payload->kind;
            $id = $kind === 'block-definition' ? $payload->type : $payload->id;
            $version = $payload->version ?? null;
            if (!is_string($kind) || !is_string($id) || !is_string($version)) {
                continue;
            }
            $dependencies[] = (object) [
                'kind' => $kind,
                'id' => $id,
                'versions' => $version,
                'required' => true,
            ];
        }

        return $dependencies;
    }

    /**
     * The immutable generation label binding the bundle and locks the session was opened with.
     *
     * @return  string  Stable label that changes whenever a lock or payload changes.
     *
     * @since   2.0.0
     */
    public function contributionGeneration(): string
    {
        return 'contributions-' . substr(hash('sha256', CanonicalJson::stringify((object) [
            'blocks' => $this->blockLocks(),
            'payloads' => $this->contributionPayloads(),
            'release' => $this->core->release,
        ])), 0, 24);
    }

    /**
     * The Content authoring target declaration bound to this catalog.
     *
     * @return  stdClass  Schema-valid `authoring-target` declaration.
     *
     * @since   2.0.0
     */
    public function declaration(): stdClass
    {
        return ContentStudioAuthoringDocuments::declaration($this->contributionDependencies());
    }

    /**
     * Copy one canonical document with the App's own message references in the interface locale.
     *
     * @param   stdClass  $document  Canonical contribution document, or one object inside it.
     *
     * @return  stdClass  Deep copy whose App-owned labels carry interface-locale default text.
     *
     * @since   2.0.0
     */
    private function localized(stdClass $document): stdClass
    {
        $copy = new stdClass();
        foreach (get_object_vars($document) as $name => $value) {
            $copy->{$name} = $this->localizedValue($value);
        }
        $key = $copy->key ?? null;
        $text = $copy->defaultMessage ?? null;
        if (is_string($key) && is_string($text)) {
            $copy->defaultMessage = $this->paletteText($key) ?? $text;
        }

        return $copy;
    }

    /**
     * Copy one member of a canonical document, localizing the App-owned message references inside it.
     *
     * @param   mixed  $value  Member value.
     *
     * @return  mixed  Deep copy.
     *
     * @since   2.0.0
     */
    private function localizedValue(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            return $this->localized($value);
        }

        return is_array($value) ? array_map(fn (mixed $item): mixed => $this->localizedValue($item), $value) : $value;
    }

    /**
     * The interface-locale text of one App-owned contextual palette message, or null for any other key.
     *
     * @param   string  $key  Canonical message key.
     *
     * @return  string|null  Translated default text.
     *
     * @since   2.0.0
     */
    private function paletteText(string $key): ?string
    {
        return match ($key) {
            'core.composition/field-text' => $this->translator->translate(
                'core.administrator.content_form.studio_block_text',
            ),
            'core.composition/field-rich-text' => $this->translator->translate(
                'core.administrator.content_form.studio_block_rich_text',
            ),
            'core.composition/field-integer' => $this->translator->translate(
                'core.administrator.content_form.studio_block_integer',
            ),
            'core.composition/field-decimal' => $this->translator->translate(
                'core.administrator.content_form.studio_block_decimal',
            ),
            'core.composition/field-boolean' => $this->translator->translate(
                'core.administrator.content_form.studio_block_yes_or_no',
            ),
            'core.composition/field-date' => $this->translator->translate(
                'core.administrator.content_form.studio_block_date',
            ),
            'core.composition/field-date-time' => $this->translator->translate(
                'core.administrator.content_form.studio_block_date_and_time',
            ),
            'core.composition/field-media' => $this->translator->translate(
                'core.administrator.content_form.studio_block_media',
            ),
            'core.composition/field-resource' => $this->translator->translate(
                'core.administrator.content_form.studio_block_resource',
            ),
            'core.composition/value' => $this->translator->translate(
                'core.administrator.content_form.studio_block_value',
            ),
            'core.composition/keyboard' => $this->translator->translate(
                'core.administrator.content_form.studio_block_keyboard',
            ),
            'core.composition/pattern' => $this->translator->translate(
                'core.administrator.content_form.studio_pattern_empty_section',
            ),
            default => null,
        };
    }

    /**
     * Project the App's live contributions for the contextual renderers without an actor filter.
     *
     * @return  \Kumwe\App\Studio\Application\Composition\StudioCompositionContributionProjection  Snapshot.
     *
     * @since   2.0.0
     */
    private function projection(): \Kumwe\App\Studio\Application\Composition\StudioCompositionContributionProjection
    {
        return $this->contributions->project([], ContentStudioAuthoringService::RENDERERS);
    }
}
