<?php

declare(strict_types=1);

namespace Kumwe\App\Extension\Contribution;

use Kumwe\Extension\Spi\Contribution\ContributionOwner;
use Kumwe\Extension\Spi\Contribution\ContributionDefinition;
use Kumwe\Extension\Spi\Contribution\CompositionHostBinding;
use Kumwe\Extension\Spi\Contribution\CanonicalCompositionKind;
use Kumwe\Extension\Spi\Contribution\CanonicalCompositionDocument;
use InvalidArgumentException;
use Kumwe\Producer\Render\BlockCoordinate;
use stdClass;

/**
 * Runtime renderer provenance derived from one reconciled canonical block and its signed host binding.
 *
 * @since  2.0.0
 */
final readonly class StudioPreviewRendererContribution implements ContributionDefinition
{
    /**
     * Exact canonical block type.
     *
     * @var    string
     * @since  2.0.0
     */
    public string $blockType;

    /**
     * Exact canonical block version.
     *
     * @var    string
     * @since  2.0.0
     */
    public string $blockVersion;

    /**
     * Exact canonical block revision.
     *
     * @var    string
     * @since  2.0.0
     */
    public string $blockRevision;

    /**
     * Canonical document owner identifier.
     *
     * @var    string
     * @since  2.0.0
     */
    public string $documentOwner;

    /**
     * Canonical document owner version.
     *
     * @var    string
     * @since  2.0.0
     */
    public string $documentOwnerVersion;

    /**
     * Signed owner-local renderer service binding.
     *
     * @var    string
     * @since  2.0.0
     */
    public string $renderer;

    /**
     * Signed preview renderer capability required by the canonical block.
     *
     * @var    string
     * @since  2.0.0
     */
    public string $previewCapability;

    /**
     * Signed version constraint attached to the preview renderer capability.
     *
     * @var    string
     * @since  2.0.0
     */
    public string $previewCapabilityVersions;

    /**
     * Optional signed authoring capability attached to the host binding.
     *
     * @var    string|null
     * @since  2.0.0
     */
    public ?string $authoringCapability;

    /**
     * Derive exact executable provenance without interpreting a class name from the manifest.
     *
     * @param   ContributionOwner             $owner           Installed package owner.
     * @param   string                        $runtimeVersion  Exact package version in the signed runtime map.
     * @param   CanonicalCompositionDocument  $document        Reconciled canonical block definition.
     * @param   CompositionHostBinding        $binding         Signed, separate host binding.
     *
     * @throws  InvalidArgumentException  When kind, identity, owner, version, revision, or binding drifts.
     *
     * @since   2.0.0
     */
    public function __construct(
        public ContributionOwner $owner,
        public string $runtimeVersion,
        CanonicalCompositionDocument $document,
        CompositionHostBinding $binding,
    ) {
        if (
            $runtimeVersion === ''
            || $document->kind !== CanonicalCompositionKind::BlockDefinition
            || $binding->kind !== CanonicalCompositionKind::BlockDefinition
            || $binding->documentId !== $document->identity()
            || $binding->renderer === null
        ) {
            throw new InvalidArgumentException('A Studio preview renderer binding is inconsistent.');
        }
        $canonicalDocument = $document->document();
        $ownerDocument = $canonicalDocument->owner ?? null;
        if (!$ownerDocument instanceof stdClass) {
            throw new InvalidArgumentException('A Studio preview block owner is unavailable.');
        }
        $this->blockType = self::member($canonicalDocument, 'type');
        $this->blockVersion = self::member($canonicalDocument, 'version');
        $this->blockRevision = self::member($canonicalDocument, 'revision');
        $this->documentOwner = self::member($ownerDocument, 'id');
        $this->documentOwnerVersion = self::member($ownerDocument, 'version');
        $this->renderer = $binding->renderer;
        [$this->previewCapability, $this->previewCapabilityVersions] = self::previewRequirement($canonicalDocument);
        $this->authoringCapability = $binding->capability;
        $studioPrefix = ($owner->identifier() === ContributionOwner::CORE ? 'core' : $owner->namespace()) . '/';
        if ($this->blockType !== $document->identity() || !str_starts_with($this->documentOwner, $studioPrefix)) {
            throw new InvalidArgumentException('A Studio preview block document contradicts its package owner.');
        }
        $owner->assertOwns($this->renderer, 'composition renderer');
        $owner->assertOwns($this->previewCapability, 'preview renderer capability');
    }

    /**
     * Return the exact block type used as the active registry key.
     *
     * @return  string  Exact block type.
     *
     * @since   2.0.0
     */
    public function identifier(): string
    {
        return $this->blockType;
    }

    /**
     * Compare one dependency-lock coordinate with this derived executable definition.
     *
     * @param   BlockCoordinate  $coordinate  Candidate dependency-lock coordinate.
     *
     * @return  bool  True only for the exact type, version, and revision.
     *
     * @since   2.0.0
     */
    public function matches(BlockCoordinate $coordinate): bool
    {
        return $coordinate->type === $this->blockType
            && $coordinate->version === $this->blockVersion
            && $coordinate->revision === $this->blockRevision;
    }

    /**
     * Return the exact canonical Producer coordinate derived from the signed block document.
     *
     * @return  BlockCoordinate  Immutable type, version and revision.
     *
     * @since   2.0.0
     */
    public function coordinate(): BlockCoordinate
    {
        return new BlockCoordinate($this->blockType, $this->blockVersion, $this->blockRevision);
    }

    /**
     * Export safe executable provenance without exposing the implementation object.
     *
     * @return  array<string, mixed>  Signed and runtime-derived provenance.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'type' => $this->blockType,
            'version' => $this->blockVersion,
            'revision' => $this->blockRevision,
            'owner' => $this->owner->identifier(),
            'runtime_version' => $this->runtimeVersion,
            'document_owner' => $this->documentOwner,
            'document_owner_version' => $this->documentOwnerVersion,
            'renderer' => $this->renderer,
            'preview_capability' => $this->previewCapability,
            'preview_capability_versions' => $this->previewCapabilityVersions,
            'authoring_capability' => $this->authoringCapability,
        ];
    }

    /**
     * Resolve exactly one signed preview renderer requirement from the canonical block.
     *
     * @param   stdClass  $document  Canonical block definition.
     *
     * @return  array{string, string}  Capability identifier and its declared version constraint.
     *
     * @throws  InvalidArgumentException  When the preview requirement is absent, repeated, or incomplete.
     *
     * @since   2.0.0
     */
    private static function previewRequirement(stdClass $document): array
    {
        $requirements = $document->rendererRequirements ?? null;
        if (!is_array($requirements)) {
            throw new InvalidArgumentException('A Studio preview renderer requirement is unavailable.');
        }
        $preview = [];
        foreach ($requirements as $requirement) {
            if ($requirement instanceof stdClass && ($requirement->surface ?? null) === 'preview') {
                $preview[] = $requirement;
            }
        }
        if (count($preview) !== 1) {
            throw new InvalidArgumentException('A Studio block requires exactly one preview renderer capability.');
        }

        return [self::member($preview[0], 'capability'), self::member($preview[0], 'versions')];
    }

    /**
     * Read one required non-empty canonical string member.
     *
     * @param   stdClass  $object  Canonical object.
     * @param   string    $name    Required member.
     *
     * @return  string  Canonical string value.
     *
     * @throws  InvalidArgumentException  When the member is absent or empty.
     *
     * @since   2.0.0
     */
    private static function member(stdClass $object, string $name): string
    {
        $value = $object->{$name} ?? null;
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException('A Studio preview renderer coordinate is incomplete.');
        }

        return $value;
    }
}
