<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Authoring;

use Kumwe\Producer\Wire\OperationRegistry;

/**
 * The five Blueprint artifact operations a machine caller addresses in a composition session.
 *
 * Each case is one row of Producer's closed `artifact` port, the port the administrator composition screen's
 * Studio shell dispatches to: the REST operation, the console action and the MCP tool all carry the case's
 * backing value. Route, capability and mutation flag are read from the pinned registry rather than restated, so
 * the machine inventory cannot drift from the port Studio itself dispatches to.
 *
 * @since  2.0.0
 */
enum StudioMachineCompositionOperation: string
{
    /**
     * Load the current or an immutable historical revision of the session's Blueprint.
     *
     * @since  2.0.0
     */
    case Load = 'load';

    /**
     * List the exact dependencies the session's Blueprint revision locks.
     *
     * @since  2.0.0
     */
    case Dependencies = 'dependencies';

    /**
     * Append one schema-valid draft revision under the caller's expected revision.
     *
     * @since  2.0.0
     */
    case Save = 'save';

    /**
     * Publish the current draft revision under the caller's expected revision.
     *
     * @since  2.0.0
     */
    case Publish = 'publish';

    /**
     * Return the published Blueprint to draft under the caller's expected revision.
     *
     * @since  2.0.0
     */
    case Unpublish = 'unpublish';

    /**
     * The qualified Producer operation capability this case addresses.
     *
     * @return  string  Registry capability such as `studio.operation/artifact.save`.
     *
     * @since   2.0.0
     */
    public function capability(): string
    {
        return 'studio.operation/artifact.' . $this->value;
    }

    /**
     * The Producer transport route the dispatcher resolves this operation by.
     *
     * @return  string  Route such as `artifact/save`.
     *
     * @since   2.0.0
     */
    public function route(): string
    {
        return OperationRegistry::byCapability($this->capability())->route;
    }

    /**
     * Whether the pinned registry classifies this operation as mutating, and so as keyed and revision-fenced.
     *
     * @return  bool  True for save, publish and unpublish; false for load and dependencies.
     *
     * @since   2.0.0
     */
    public function mutating(): bool
    {
        return OperationRegistry::byCapability($this->capability())->mutating;
    }

    /**
     * The single argument member the App artifact port reads for this operation.
     *
     * @return  string  `document` for a save, `reference` for every other operation.
     *
     * @since   2.0.0
     */
    public function argumentMember(): string
    {
        return $this === self::Save ? 'document' : 'reference';
    }

    /**
     * Resolve a caller-supplied operation name, refusing anything outside the closed set.
     *
     * @param   string  $name  Operation name as the caller spelled it.
     *
     * @return  self  The named operation.
     *
     * @throws  StudioMachineAuthoringRefused  When the name is not one of the five operations.
     *
     * @since   2.0.0
     */
    public static function named(string $name): self
    {
        return self::tryFrom($name)
            ?? throw StudioMachineAuthoringRefused::of('invalid-request', 'studio.machine/operation-unknown');
    }

    /**
     * Every machine operation name in contract order.
     *
     * @return  list<string>  Backing values of all five cases.
     *
     * @since   2.0.0
     */
    public static function names(): array
    {
        return array_map(static fn (self $operation): string => $operation->value, self::cases());
    }
}
