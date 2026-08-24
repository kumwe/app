<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Preview;

use DateInterval;
use InvalidArgumentException;
use Kumwe\App\Application\Authorization\ExecutionContext;
use Kumwe\App\Studio\Application\Composition\StudioCompositionThemeMismatch;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Domain\Host\StudioHostRequest;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewGrant;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewRenderRequest;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewTransport;
use Psr\Clock\ClockInterface;
use stdClass;
use Throwable;

/**
 * Complete `preview.render` and `preview.cancel` host-port implementation.
 *
 * @since  2.0.0
 */
final readonly class StudioPreviewHostPort implements StudioPreviewDocumentClaimer
{
    /**
     * Maximum lifetime of an unpublished rendered document grant.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string GRANT_LIFETIME = 'PT60S';

    /**
     * Compose canonical draft resolution, rendering, replay persistence and browser transport checks.
     *
     * @param  StudioPreviewDraftSource       $drafts    Narrow AP-4 draft reader.
     * @param  StudioPreviewBindingSource     $bindings  Content-authority binding resolver.
     * @param  StudioPreviewRenderer          $renderer  Shared canonical site rendering path.
     * @param  StudioPreviewGrantRepository   $grants    Pending/cancel/single-use grant ledger.
     * @param  StudioPreviewTransportGuard    $guard     Origin/channel/source/sequence fence.
     * @param  StudioPreviewActivityRecorder  $activity  Bounded ephemeral security activity trail.
     * @param  ClockInterface                 $clock     Trusted expiry clock.
     *
     * @since  2.0.0
     */
    public function __construct(
        private StudioPreviewDraftSource $drafts,
        private StudioPreviewBindingSource $bindings,
        private StudioPreviewRenderer $renderer,
        private StudioPreviewGrantRepository $grants,
        private StudioPreviewTransportGuard $guard,
        private StudioPreviewActivityRecorder $activity,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Dispatch one preview operation after the common host-session fence.
     *
     * @param   ExecutionContext           $context    Authenticated App request authority.
     * @param   string                     $operation  Operation route segment.
     * @param   StudioHostRequest          $request    Canonical host request.
     * @param   StudioHostSessionSnapshot  $snapshot   Live trusted host authority.
     * @param   StudioPreviewTransport     $transport  Browser transport evidence outside the payload.
     *
     * @return  mixed  Exact rendered payload or null for cancellation.
     *
     * @throws  StudioPreviewRefused  When shape, authority, identity, replay or rendering is refused.
     *
     * @since   2.0.0
     */
    public function dispatch(
        ExecutionContext $context,
        string $operation,
        StudioHostRequest $request,
        StudioHostSessionSnapshot $snapshot,
        StudioPreviewTransport $transport,
    ): mixed {
        $action = match ($operation) {
            'cancel', 'render' => $operation,
            default => throw new StudioPreviewRefused('incompatible', 'studio.host/operation-unavailable'),
        };
        try {
            $this->guard->authorize($snapshot, $transport, 'port');
            $this->record($context, $snapshot, $action, 'accepted', 'studio.preview/transport-accepted');
            $result = match ($operation) {
                'render' => $this->render($context, $request, $snapshot, $transport),
                'cancel' => $this->cancel($request, $snapshot, $transport),
            };
            $this->record($context, $snapshot, $action, 'completed', 'studio.preview/' . $action . '-completed');

            return $result;
        } catch (StudioPreviewRefused $refused) {
            $this->record($context, $snapshot, $action, 'refused', $refused->diagnosticCode);
            throw $refused;
        }
    }

    /**
     * Claim and render one exact unpublished draft, discarding late results after cancellation.
     *
     * @param   ExecutionContext           $context      Authenticated App request authority.
     * @param   StudioHostRequest          $hostRequest  Canonical host request carrying exact `{payload}`.
     * @param   StudioHostSessionSnapshot  $snapshot     Live trusted host authority.
     * @param   StudioPreviewTransport     $transport    Accepted browser transport evidence.
     *
     * @return  stdClass  Exact `preview-message#/$defs/rendered` payload.
     *
     * @throws  StudioPreviewRefused  When the render cannot safely complete.
     *
     * @since   2.0.0
     */
    private function render(
        ExecutionContext $context,
        StudioHostRequest $hostRequest,
        StudioHostSessionSnapshot $snapshot,
        StudioPreviewTransport $transport,
    ): stdClass {
        $arguments = self::exactArguments($hostRequest->arguments, ['payload']);
        try {
            $request = StudioPreviewRenderRequest::fromPayload($arguments->payload);
        } catch (InvalidArgumentException) {
            throw new StudioPreviewRefused('invalid-request', 'studio.preview/invalid-render-payload');
        }
        if (
            !in_array('studio.permission/read', $snapshot->permissions, true)
        ) {
            throw new StudioPreviewRefused('forbidden', 'studio.preview/resource-refused');
        }
        $draft = $this->drafts->find($snapshot, $request);
        if ($draft === null) {
            throw new StudioPreviewRefused('not-found', 'studio.preview/draft-not-found');
        }
        if (
            !hash_equals($request->artifactId, $draft->artifactId())
            || !hash_equals($request->draftRevision, $draft->revision())
            || !hash_equals($request->draftDigest, $draft->digest())
        ) {
            throw new StudioPreviewRefused('conflict', 'studio.preview/draft-identity-mismatch');
        }
        $values = $this->bindings->resolve($context, $snapshot, $draft);
        $expiresAt = $this->clock->now()->add(new DateInterval(self::GRANT_LIFETIME));
        $admission = $this->grants->begin($snapshot, $request, $transport, $expiresAt);
        if ($admission === StudioPreviewRenderAdmission::Replayed) {
            throw new StudioPreviewRefused('invalid-request', 'studio.preview/request-replayed');
        }
        if ($admission === StudioPreviewRenderAdmission::Cancelled) {
            throw new StudioPreviewRefused('cancelled', 'studio.preview/render-cancelled');
        }
        try {
            $rendered = $this->renderer->render($snapshot, $draft, $request, $values);
        } catch (StudioCompositionThemeMismatch) {
            $this->grants->abandon($snapshot->session->resourceContextKey, $request->requestId);
            throw new StudioPreviewRefused('conflict', 'studio.preview/theme-lock-mismatch');
        } catch (Throwable) {
            $this->grants->abandon($snapshot->session->resourceContextKey, $request->requestId);
            throw new StudioPreviewRefused('unavailable', 'studio.preview/render-failed');
        }
        if (!$this->grants->complete($snapshot->session->resourceContextKey, $request, $rendered)) {
            throw new StudioPreviewRefused('cancelled', 'studio.preview/render-cancelled');
        }

        return (object) [
            'draftDigest' => $request->draftDigest,
            'markers' => $rendered->markers,
            'markerMap' => (object) $rendered->markerMap,
            'diagnostics' => $rendered->diagnostics,
            'requestId' => $request->requestId,
        ];
    }

    /**
     * Cancel only the matching digest inside this trusted resource context.
     *
     * @param   StudioHostRequest          $request    Canonical host request carrying exact `{draftDigest}`.
     * @param   StudioHostSessionSnapshot  $snapshot   Live trusted host authority.
     * @param   StudioPreviewTransport     $transport  Accepted cancellation transport evidence.
     *
     * @return  null  Exact PreviewPort cancellation result.
     *
     * @throws  StudioPreviewRefused  When the exact `{draftDigest}` wrapper is malformed.
     *
     * @since   2.0.0
     */
    private function cancel(
        StudioHostRequest $request,
        StudioHostSessionSnapshot $snapshot,
        StudioPreviewTransport $transport,
    ): null {
        $arguments = self::exactArguments($request->arguments, ['draftDigest']);
        if (!is_string($arguments->draftDigest) || preg_match('/^[a-f0-9]{64}$/D', $arguments->draftDigest) !== 1) {
            throw new StudioPreviewRefused('invalid-request', 'studio.preview/invalid-cancel-payload');
        }
        $this->grants->cancel(
            $snapshot->session->resourceContextKey,
            $arguments->draftDigest,
            $transport->sequence,
        );

        return null;
    }

    /**
     * Require an exact HTTP wrapper without conflating it with the raw testkit vector argument.
     *
     * @param   mixed         $candidate  Candidate wrapper.
     * @param   list<string>  $expected   Exact member set.
     *
     * @return  stdClass  Closed wrapper.
     *
     * @throws  StudioPreviewRefused  When the wrapper is absent, aliased or extended.
     *
     * @since   2.0.0
     */
    private static function exactArguments(mixed $candidate, array $expected): stdClass
    {
        $members = $candidate instanceof stdClass ? array_keys(get_object_vars($candidate)) : [];
        sort($members, SORT_STRING);
        $sorted = $expected;
        sort($sorted, SORT_STRING);
        if (!$candidate instanceof stdClass || $members !== $sorted) {
            throw new StudioPreviewRefused('invalid-request', 'studio.host/invalid-arguments');
        }

        return $candidate;
    }

    /**
     * Claim a completed preview document for the authenticated GET endpoint.
     *
     * @param   ExecutionContext           $context    Authenticated App request authority.
     * @param   StudioHostSessionSnapshot  $snapshot   Live trusted host authority.
     * @param   string                     $requestId  Session-unique render attempt.
     * @param   StudioPreviewTransport     $transport  Browser navigation evidence.
     *
     * @return  StudioPreviewGrant|null  Single-use live grant or null after replay/cancellation/expiry.
     *
     * @throws  StudioPreviewRefused  When origin, channel, source or sequence is invalid.
     *
     * @since   2.0.0
     */
    public function claimDocument(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        string $requestId,
        StudioPreviewTransport $transport,
    ): ?StudioPreviewGrant {
        try {
            $this->guard->authorize($snapshot, $transport, 'document');
            $this->record(
                $context,
                $snapshot,
                'document-claim',
                'accepted',
                'studio.preview/transport-accepted',
            );
            $grant = $this->grants->claim($snapshot, $requestId, $transport, $this->clock->now());
            $this->record(
                $context,
                $snapshot,
                'document-claim',
                $grant === null ? 'refused' : 'completed',
                $grant === null ? 'studio.preview/grant-unavailable' : 'studio.preview/document-claim-completed',
            );

            return $grant;
        } catch (StudioPreviewRefused $refused) {
            $this->record($context, $snapshot, 'document-claim', 'refused', $refused->diagnosticCode);
            throw $refused;
        }
    }

    /**
     * Read the closed theme stylesheet of one live, already-claimed preview document.
     *
     * @param   ExecutionContext           $context    Authenticated App request authority.
     * @param   StudioHostSessionSnapshot  $snapshot   Live trusted host authority.
     * @param   string                     $requestId  Session-unique render attempt.
     * @param   StudioPreviewTransport     $transport  Same-origin grant identity evidence.
     *
     * @return  string|null  Exact generated CSS, or null after absence, mismatch or expiry.
     *
     * @throws  StudioPreviewRefused  When origin, channel or source is invalid.
     *
     * @since   2.0.0
     */
    public function themeStylesheet(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        string $requestId,
        StudioPreviewTransport $transport,
    ): ?string {
        try {
            $this->guard->authorizeIdentity($snapshot, $transport);
            $this->record(
                $context,
                $snapshot,
                'theme-stylesheet',
                'accepted',
                'studio.preview/transport-accepted',
            );
            $grant = $this->grants->claimed($snapshot, $requestId, $transport, $this->clock->now());
            $stylesheet = $grant?->document->themeStylesheet;
            $this->record(
                $context,
                $snapshot,
                'theme-stylesheet',
                $stylesheet === null ? 'refused' : 'completed',
                $stylesheet === null
                    ? 'studio.preview/theme-stylesheet-unavailable'
                    : 'studio.preview/theme-stylesheet-completed',
            );

            return $stylesheet;
        } catch (StudioPreviewRefused $refused) {
            $this->record($context, $snapshot, 'theme-stylesheet', 'refused', $refused->diagnosticCode);
            throw $refused;
        }
    }

    /**
     * Record bounded preview activity and fail closed if the security trail is unavailable.
     *
     * @param   ExecutionContext           $context   Authenticated actor and request correlation.
     * @param   StudioHostSessionSnapshot  $snapshot  Trusted site and resource family.
     * @param   string                     $action    Closed preview action.
     * @param   string                     $outcome   Closed activity outcome.
     * @param   string                     $reason    Stable non-disclosing diagnostic code.
     *
     * @return  void
     *
     * @throws  StudioPreviewRefused  When the structured record does not reach its sink.
     *
     * @since   2.0.0
     */
    private function record(
        ExecutionContext $context,
        StudioHostSessionSnapshot $snapshot,
        string $action,
        string $outcome,
        string $reason,
    ): void {
        try {
            $this->activity->record($context, $snapshot, $action, $outcome, $reason);
        } catch (Throwable) {
            throw new StudioPreviewRefused('unavailable', 'studio.preview/activity-record-unavailable');
        }
    }
}
