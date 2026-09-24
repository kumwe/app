<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Authoring;

use Kumwe\Producer\Wire\OperationRegistry;

/**
 * The seven durable Studio authoring operations a machine caller addresses, in machine-contract spelling.
 *
 * Each case is one row of Producer's closed operation registry seen through the machine surfaces: the
 * REST operation, the console action and the MCP tool all carry the case's backing value, so the parity
 * gate can enumerate the browser port's operations and prove every one has a machine name. Route,
 * capability and mutation flag are read from the pinned registry rather than restated, so the machine
 * inventory cannot drift from the port Studio itself dispatches to.
 *
 * @since  2.0.0
 */
enum StudioMachineAuthoringOperation: string
{
    /**
     * Resolve the declared target and the start sources the session admits.
     *
     * @since  2.0.0
     */
    case ResolveTarget = 'resolve-target';

    /**
     * Page through the reusable Content types a new item may start from.
     *
     * @since  2.0.0
     */
    case ListTypes = 'list-types';

    /**
     * Open the coordinated authoring session from one exact start source.
     *
     * @since  2.0.0
     */
    case Start = 'start';

    /**
     * Plan one save outcome against live state and disclose its consequences.
     *
     * @since  2.0.0
     */
    case PlanSave = 'plan-save';

    /**
     * Commit the item an accepted plan authorizes.
     *
     * @since  2.0.0
     */
    case SaveItem = 'save-item';

    /**
     * Create a new reusable type from the session's design.
     *
     * @since  2.0.0
     */
    case SaveAsNewType = 'save-as-new-type';

    /**
     * Publish an immutable successor version of the session's reusable type and adopt it.
     *
     * @since  2.0.0
     */
    case SaveNewTypeVersion = 'save-new-type-version';

    /**
     * The qualified Producer operation capability this case addresses.
     *
     * @return  string  Registry capability such as `studio.operation/authoring.save-item`.
     *
     * @since   2.0.0
     */
    public function capability(): string
    {
        return 'studio.operation/authoring.' . $this->value;
    }

    /**
     * The Producer transport route the dispatcher resolves this operation by.
     *
     * @return  string  Route such as `authoring/save-item`.
     *
     * @since   2.0.0
     */
    public function route(): string
    {
        return OperationRegistry::byCapability($this->capability())->route;
    }

    /**
     * Whether the pinned registry classifies this operation as mutating, and so as keyed for replay.
     *
     * @return  bool  True for start and the three saves; false for the three reads.
     *
     * @since   2.0.0
     */
    public function mutating(): bool
    {
        return OperationRegistry::byCapability($this->capability())->mutating;
    }

    /**
     * The single argument member the App authoring port reads for this operation.
     *
     * @return  string  `request`, `query` or `intent`, exactly as `StudioAuthoringHostPort` expects it.
     *
     * @since   2.0.0
     */
    public function argumentMember(): string
    {
        return match ($this) {
            self::ListTypes => 'query',
            self::PlanSave => 'intent',
            default => 'request',
        };
    }

    /**
     * Every machine operation name in contract order.
     *
     * @return  list<string>  Backing values of all seven cases.
     *
     * @since   2.0.0
     */
    public static function names(): array
    {
        return array_map(static fn (self $operation): string => $operation->value, self::cases());
    }
}
