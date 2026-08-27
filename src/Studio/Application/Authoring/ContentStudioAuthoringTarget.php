<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Authoring;

use Kumwe\App\Studio\Domain\Authoring\StudioAuthoringIntent;

/**
 * Trusted App coordinates for the Content surface that will open contextual Studio authoring.
 *
 * The value contains no actor, site, grant, credential, URL supplied by JavaScript, or unpublished
 * Studio wire shape. PHP resolves it from authorized Content records and definitions, and a later
 * release-bound adapter may translate it into the canonical Studio authoring contract.
 *
 * @since  2.0.0
 */
final readonly class ContentStudioAuthoringTarget
{
    /**
     * Stable owner-qualified identity of the core Content authoring target.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string TARGET_ID = 'kumwe.app/content-authoring';

    /**
     * Stable surface from which contextual authoring originates.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string SURFACE = 'kumwe.app/administrator-content-editor';

    /**
     * Hold only the exact coordinates PHP resolved for this launch decision.
     *
     * A create target may omit Model coordinates when no reusable type was explicitly requested;
     * that is the blank-start path and prevents the structured-form default from becoming a Studio
     * prerequisite. An edit target always carries exact Model and Entry coordinates.
     *
     * @param  StudioAuthoringIntent  $intent         Create or edit decision.
     * @param  ?string                $modelId        Exact projected Content Model ID, when selected.
     * @param  ?string                $modelVersion   Exact projected Content Model version, when selected.
     * @param  ?string                $modelRevision  Exact projected Content Model revision, when selected.
     * @param  ?string                $entryId        Exact projected Entry ID while editing.
     * @param  ?string                $entryRevision  Exact projected Entry revision while editing.
     * @param  string                 $returnPath     Server-derived path back to the originating editor.
     *
     * @since  2.0.0
     */
    public function __construct(
        public StudioAuthoringIntent $intent,
        public ?string $modelId,
        public ?string $modelVersion,
        public ?string $modelRevision,
        public ?string $entryId,
        public ?string $entryRevision,
        public string $returnPath,
    ) {
    }

    /**
     * Present the closed host-owned view needed by the Content template and boundary tests.
     *
     * This is not sent to Studio. Keeping the view App-native makes it impossible for the current
     * withdrawn RC package to be mistaken for the unpublished contextual protocol.
     *
     * @return  array{
     *          target_id: string,
     *          surface: string,
     *          intent: string,
     *          model_id: ?string,
     *          model_version: ?string,
     *          model_revision: ?string,
     *          entry_id: ?string,
     *          entry_revision: ?string,
     *          return_path: string
     *          }  Trusted launch facts.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'target_id' => self::TARGET_ID,
            'surface' => self::SURFACE,
            'intent' => $this->intent->value,
            'model_id' => $this->modelId,
            'model_version' => $this->modelVersion,
            'model_revision' => $this->modelRevision,
            'entry_id' => $this->entryId,
            'entry_revision' => $this->entryRevision,
            'return_path' => $this->returnPath,
        ];
    }
}
