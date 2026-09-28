<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Authoring;

use Kumwe\Producer\Wire\RequestEnvelope;
use stdClass;

/**
 * What a machine caller learns when the host opens a Blueprint composition session for it.
 *
 * The value carries the coordinates a later artifact operation must echo — the opaque session key and its
 * generation — beside the facts the administrator composition screen boots with: the Content type version the
 * composition belongs to, the exact Blueprint artifact reference and head revision, the mode, the Studio
 * permissions in force and the separate publish and unpublish authority the session proved. It discloses no
 * actor, grant or credential.
 *
 * @since  2.0.0
 */
final readonly class StudioMachineCompositionSession
{
    /**
     * The `kind` member every emitted session document carries.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string KIND = 'studio-machine-composition-session';

    /**
     * Capture one opened machine composition session.
     *
     * @param  string        $sessionKey          Opaque host session key later operations address.
     * @param  string        $sessionGeneration   Live generation the permissions were proven under.
     * @param  string        $mode                `blueprint`, or `read-only` for a session that only reads.
     * @param  list<string>  $permissions         Sorted canonical Studio permissions in force.
     * @param  bool          $canPublish          Whether the session may publish the Blueprint.
     * @param  bool          $canUnpublish        Whether the session may return it to draft.
     * @param  string        $contentTypeId       Content type the composition belongs to.
     * @param  int           $contentTypeVersion  Exact Content type version.
     * @param  stdClass      $artifact            `{id, version, revision}` of the bound Blueprint head.
     *
     * @since  2.0.0
     */
    public function __construct(
        public string $sessionKey,
        public string $sessionGeneration,
        public string $mode,
        public array $permissions,
        public bool $canPublish,
        public bool $canUnpublish,
        public string $contentTypeId,
        public int $contentTypeVersion,
        public stdClass $artifact,
    ) {
    }

    /**
     * The document every machine surface returns for an opened composition session.
     *
     * @return  stdClass  Session document in the member order the machine contracts publish.
     *
     * @since   2.0.0
     */
    public function toDocument(): stdClass
    {
        return (object) [
            'kind' => self::KIND,
            'session' => $this->sessionKey,
            'sessionGeneration' => $this->sessionGeneration,
            'protocolVersion' => RequestEnvelope::WIRE_PROTOCOL_VERSION,
            'mode' => $this->mode,
            'contentTypeId' => $this->contentTypeId,
            'contentTypeVersion' => $this->contentTypeVersion,
            'artifact' => $this->artifact,
            'permissions' => $this->permissions,
            'lifecycle' => (object) ['canPublish' => $this->canPublish, 'canUnpublish' => $this->canUnpublish],
            'operations' => StudioMachineCompositionOperation::names(),
        ];
    }
}
