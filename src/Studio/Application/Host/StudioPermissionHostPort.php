<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Host;

use Kumwe\Producer\Wire\HostResult;
use Kumwe\Producer\Wire\Port\PermissionPortInterface;
use Kumwe\Producer\Wire\RequestContext;
use stdClass;

/**
 * Direct Producer permission port backed exclusively by the App's live Studio session authority.
 *
 * @since  2.0.0
 */
final readonly class StudioPermissionHostPort implements PermissionPortInterface
{
    /**
     * Bind the port to the authority for one successfully authorized Producer request.
     *
     * @param  StudioProducerRequestAuthority  $authority  Trusted evidence for one exact dispatch.
     *
     * @since  2.0.0
     */
    public function __construct(private StudioProducerRequestAuthority $authority)
    {
    }

    /**
     * Explain one canonical Studio permission without disclosing App policy internals.
     *
     * @param   mixed           $arguments  Validated Producer operation arguments.
     * @param   RequestContext  $context    Validated Producer request context.
     *
     * @return  HostResult  Canonical permission decision.
     *
     * @since   2.0.0
     */
    public function explain(mixed $arguments, RequestContext $context): HostResult
    {
        self::assertReadContext($context);
        if (
            !$arguments instanceof stdClass
            || self::members($arguments) !== ['operation']
            || !is_string($arguments->operation)
        ) {
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-arguments');
        }
        $allowed = $this->authority->permits($arguments->operation);
        $value = (object) ['allowed' => $allowed];
        if (!$allowed) {
            $value->reason = (object) [
                'key' => 'studio.permission/withheld',
                'defaultMessage' => 'This action is not available in the current Studio session.',
            ];
        }

        return new HostResult($value);
    }

    /**
     * Return the complete live permission snapshot for the current authority generation.
     *
     * @param   mixed           $arguments  Validated Producer operation arguments.
     * @param   RequestContext  $context    Validated Producer request context.
     *
     * @return  HostResult  Sorted permissions and current session generation.
     *
     * @since   2.0.0
     */
    public function refresh(mixed $arguments, RequestContext $context): HostResult
    {
        self::assertReadContext($context);
        if ($arguments !== null && (!$arguments instanceof stdClass || get_object_vars($arguments) !== [])) {
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-arguments');
        }
        $snapshot = $this->authority->snapshot();

        return new HostResult((object) [
            'permissions' => $snapshot->permissions,
            'sessionGeneration' => $snapshot->generation,
        ]);
    }

    /**
     * Refuse mutation-only context on this read-only port.
     *
     * @param   RequestContext  $context  Validated Producer request context.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertReadContext(RequestContext $context): void
    {
        if ($context->expectedRevision !== null || $context->idempotencyKey !== null) {
            StudioProducerError::refuse('invalid-request', 'studio.host/invalid-context');
        }
    }

    /**
     * Return deterministic object member names for exact protocol validation.
     *
     * @param   stdClass  $document  Candidate protocol object.
     *
     * @return  list<string>  Deterministically sorted member names.
     *
     * @since   2.0.0
     */
    private static function members(stdClass $document): array
    {
        $members = array_keys(get_object_vars($document));
        sort($members, SORT_STRING);

        return $members;
    }
}
