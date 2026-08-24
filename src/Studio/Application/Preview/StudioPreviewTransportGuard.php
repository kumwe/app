<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Preview;

use InvalidArgumentException;
use Kumwe\App\Studio\Application\Host\StudioHostSessionSnapshot;
use Kumwe\App\Studio\Domain\Host\StudioHostSession;
use Kumwe\App\Studio\Domain\Preview\StudioPreviewTransport;

/**
 * Same-origin, exact channel/source and monotonic-sequence boundary for both preview endpoints.
 *
 * @since  2.0.0
 */
final readonly class StudioPreviewTransportGuard
{
    /**
     * Maximum number of ten-millisecond yields for one immediate predecessor.
     *
     * The one-second ceiling absorbs saturated multi-worker HTTP scheduling without allowing
     * arbitrary gaps to retain a worker or turning transport ordering into an unbounded server queue.
     *
     * @var    int
     * @since  2.0.0
     */
    private const int PREDECESSOR_WAIT_LIMIT = 100;

    /**
     * Canonical expected browser origin without a trailing slash.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $origin;

    /**
     * Bind the guard to the configured deployment origin and portable sequence ledger.
     *
     * @param   string                           $baseUrl    Validated application base URL.
     * @param   StudioPreviewSequenceRepository  $sequences  Atomic per-direction sequence store.
     * @param   StudioPreviewSequenceWaiter      $waiter     Portable bounded scheduling yield.
     *
     * @throws  InvalidArgumentException  When the base URL cannot produce a strict origin.
     *
     * @since   2.0.0
     */
    public function __construct(
        string $baseUrl,
        private StudioPreviewSequenceRepository $sequences,
        private StudioPreviewSequenceWaiter $waiter,
    ) {
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
        $host = parse_url($baseUrl, PHP_URL_HOST);
        $port = parse_url($baseUrl, PHP_URL_PORT);
        if (!is_string($scheme) || !is_string($host) || $scheme === '' || $host === '') {
            throw new InvalidArgumentException('The Studio preview base origin is invalid.');
        }
        $this->origin = strtolower($scheme) . '://' . strtolower($host)
            . (is_int($port) ? ':' . $port : '');
    }

    /**
     * Check all browser evidence and atomically claim its sequence.
     *
     * @param   StudioHostSessionSnapshot  $snapshot   Live trusted host session.
     * @param   StudioPreviewTransport     $transport  Browser transport evidence.
     * @param   string                     $lane       Closed `port` or `document` direction.
     *
     * @return  void
     *
     * @throws  StudioPreviewRefused  With a distinct stable code for each refusal class.
     *
     * @since   2.0.0
     */
    public function authorize(
        StudioHostSessionSnapshot $snapshot,
        StudioPreviewTransport $transport,
        string $lane,
    ): void {
        $this->authorizeIdentity($snapshot, $transport);
        if (!in_array($lane, ['port', 'document'], true)) {
            throw new StudioPreviewRefused('invalid-request', 'studio.preview/invalid-lane');
        }
        for ($waits = 0; $waits <= self::PREDECESSOR_WAIT_LIMIT; $waits++) {
            $claim = $this->sequences->advance(
                $snapshot->session->resourceContextKey,
                $lane,
                $transport->sequence,
            );
            if ($claim === StudioPreviewSequenceClaim::Accepted) {
                return;
            }
            if ($claim === StudioPreviewSequenceClaim::Refused || $waits === self::PREDECESSOR_WAIT_LIMIT) {
                throw new StudioPreviewRefused('invalid-request', 'studio.preview/sequence-replayed');
            }
            $this->waiter->pause();
        }
    }

    /**
     * Check same-origin channel and source evidence without consuming an operation sequence.
     *
     * Authenticated preview subresources are coupled to an already-claimed document rather than a new
     * protocol operation, so they reuse its opaque grant coordinates but never advance either wire lane.
     *
     * @param   StudioHostSessionSnapshot  $snapshot   Live trusted host session.
     * @param   StudioPreviewTransport     $transport  Browser transport evidence.
     *
     * @return  void
     *
     * @throws  StudioPreviewRefused  With a distinct stable code for each identity refusal class.
     *
     * @since   2.0.0
     */
    public function authorizeIdentity(
        StudioHostSessionSnapshot $snapshot,
        StudioPreviewTransport $transport,
    ): void {
        if (!hash_equals($this->origin, strtolower($transport->origin))) {
            throw new StudioPreviewRefused('forbidden', 'studio.preview/foreign-origin');
        }
        if (!hash_equals($this->channelId($snapshot->session), $transport->channelId)) {
            throw new StudioPreviewRefused('forbidden', 'studio.preview/wrong-channel');
        }
        if (!hash_equals($this->sourceId($snapshot->session), $transport->sourceId)) {
            throw new StudioPreviewRefused('forbidden', 'studio.preview/wrong-source');
        }
    }

    /**
     * Derive the non-secret per-session channel identifier exposed at session open.
     *
     * @param   StudioHostSession  $session  Trusted persisted host-session binding.
     *
     * @return  string  Stable channel ID that changes with the authority generation.
     *
     * @since   2.0.0
     */
    public function channelId(StudioHostSession $session): string
    {
        return 'channels/preview-' . substr(hash('sha256', implode("\n", [
            'kumwe-studio-preview-channel-v1',
            $session->resourceContextKey,
            $session->sessionGeneration,
        ])), 0, 32);
    }

    /**
     * Derive the expected browser source identity without exposing the session binding.
     *
     * @param   StudioHostSession  $session  Trusted persisted host-session binding.
     *
     * @return  string  Stable source ID bound to the authenticated browser session.
     *
     * @since   2.0.0
     */
    public function sourceId(StudioHostSession $session): string
    {
        return 'sources/preview-' . substr(hash('sha256', implode("\n", [
            'kumwe-studio-preview-source-v1',
            $session->resourceContextKey,
            $session->sessionBinding,
        ])), 0, 32);
    }

    /**
     * Return the exact deployment origin expected from both endpoints.
     *
     * @return  string  Lowercase scheme and host with an explicit non-default port when configured.
     *
     * @since   2.0.0
     */
    public function origin(): string
    {
        return $this->origin;
    }
}
