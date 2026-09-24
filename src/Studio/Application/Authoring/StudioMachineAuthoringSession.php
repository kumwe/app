<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Authoring;

use Kumwe\App\Studio\Domain\Authoring\StudioAuthoringIntent;
use Kumwe\Producer\Wire\RequestEnvelope;
use stdClass;

/**
 * What a machine caller learns when the host opens a Studio authoring context for it.
 *
 * The value carries exactly the coordinates a later operation must echo — the opaque session key, the
 * target identity, the resource context — beside the facts the browser mount would have rendered into
 * its deployment: the intent, the reusable type a typed create starts from, the permissions in force and
 * the generation those permissions were proven under. It discloses no actor, grant, credential or stored
 * target coordinate beyond what the Studio session snapshot itself already publishes.
 *
 * @since  2.0.0
 */
final readonly class StudioMachineAuthoringSession
{
    /**
     * The `kind` member every emitted session document carries.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string KIND = 'studio-machine-authoring-session';

    /**
     * Capture one opened machine authoring session.
     *
     * @param  string                 $sessionKey         Opaque host session key later operations address.
     * @param  string                 $sessionId          Deterministic Studio session identifier.
     * @param  string                 $sessionGeneration  Live generation the permissions were proven under.
     * @param  StudioAuthoringIntent  $intent             Create or edit decision the context binds.
     * @param  stdClass               $resourceContext    Canonical resource context every document repeats.
     * @param  list<string>           $permissions        Sorted canonical Studio permissions in force.
     * @param  list<string>           $availableStarts    Start sources the target admits.
     * @param  ?stdClass              $type               Reusable-type reference of a typed target, or null.
     * @param  string                 $returnPath         Server-derived administrator path back to the editor.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $sessionKey,
        public string $sessionId,
        public string $sessionGeneration,
        public StudioAuthoringIntent $intent,
        public stdClass $resourceContext,
        public array $permissions,
        public array $availableStarts,
        public ?stdClass $type,
        public string $returnPath,
    ) {
    }

    /**
     * The document every machine surface returns for an opened session.
     *
     * @return  stdClass  Session document in the member order the machine contracts publish.
     *
     * @since   2.0.0
     */
    public function toDocument(): stdClass
    {
        $document = (object) [
            'kind' => self::KIND,
            'session' => $this->sessionKey,
            'sessionId' => $this->sessionId,
            'sessionGeneration' => $this->sessionGeneration,
            'protocolVersion' => RequestEnvelope::WIRE_PROTOCOL_VERSION,
            'targetId' => ContentStudioAuthoringTarget::TARGET_ID,
            'intent' => $this->intent->value,
            'resourceContext' => $this->resourceContext,
            'permissions' => $this->permissions,
            'availableStarts' => $this->availableStarts,
            'operations' => StudioMachineAuthoringOperation::names(),
        ];
        if ($this->type !== null) {
            $document->type = $this->type;
        }
        $document->returnPath = $this->returnPath;

        return $document;
    }
}
