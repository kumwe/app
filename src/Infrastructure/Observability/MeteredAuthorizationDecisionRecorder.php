<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Observability;

use Kumwe\Access\AuthorizationDecision;
use Kumwe\Access\AuthorizationDecisionRecorder;
use Kumwe\Access\AuthorizationResource;
use Kumwe\Access\Capability;
use Kumwe\Context\Value\ExecutionContext;

/**
 * Counts every denied authorization decision as a `permission_denied` security event, then records it.
 *
 * The gateway already hands each decision to a recorder that writes the structured audit line; this
 * decorator adds the one number an alert can threshold on without reading logs. It never inspects the
 * actor, resource or site — those stay in the log line — so the counter carries a single bounded label.
 * Denials that filter a listing row by row are counted too: they are rare in normal operation, and a burst
 * of them is exactly the enumeration attempt the alert exists for.
 *
 * @since  2.0.0
 */
final readonly class MeteredAuthorizationDecisionRecorder implements AuthorizationDecisionRecorder
{
    /**
     * Bind the decorator to the recorder it wraps and the counter it increments.
     *
     * @param  AuthorizationDecisionRecorder  $inner    Recorder that writes the structured decision line.
     * @param  MetricRecorder                 $metrics  Recorder of `kumwe_security_events_total`.
     *
     * @since  2.0.0
     */
    public function __construct(
        private AuthorizationDecisionRecorder $inner,
        private MetricRecorder $metrics,
    ) {
    }

    /**
     * Count a denial, then record the decision through the wrapped recorder.
     *
     * @param   ExecutionContext       $context   Actor, site, and request correlation the decision was made for.
     * @param   Capability             $action    Capability that was being exercised.
     * @param   AuthorizationResource  $resource  Resource the action was aimed at.
     * @param   AuthorizationDecision  $decision  Outcome, with the policy and reason that produced it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function record(
        ExecutionContext $context,
        Capability $action,
        AuthorizationResource $resource,
        AuthorizationDecision $decision,
    ): void {
        if (!$decision->allowed) {
            $this->metrics->increment(MetricCatalog::SECURITY_EVENTS, ['event' => 'permission_denied']);
        }
        $this->inner->record($context, $action, $resource, $decision);
    }
}
