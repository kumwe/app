<?php

declare(strict_types=1);

namespace Kumwe\App\Administrator\Http\Handler;

use InvalidArgumentException;
use Kumwe\App\Administrator\Http\AdministratorRequest;
use Kumwe\App\Administrator\Http\StudioPreviewHttpTransport;
use Kumwe\App\Studio\Application\Host\StudioHostAccessRefused;
use Kumwe\App\Studio\Application\Host\StudioProducerError;
use Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority;
use Kumwe\App\Studio\Application\Preview\StudioPreviewDocumentClaimer;
use Kumwe\App\Studio\Application\Preview\StudioPreviewRefused;
use Kumwe\App\Studio\Application\Preview\StudioPreviewStylesheet;
use Laminas\Diactoros\Response\HtmlResponse;
use Kumwe\Producer\Wire\StrictResponder;
use Laminas\Diactoros\Response\TextResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Authenticated single-use delivery endpoint for rendered unpublished compositions.
 *
 * @since  2.0.0
 */
final readonly class AdministratorStudioPreviewDocumentHandler implements RequestHandlerInterface
{
    /**
     * Bind document claims to live Studio authority and the preview grant service.
     *
     * @param  StudioHostSessionAuthority    $authority  Trusted host-session resolver.
     * @param  StudioPreviewDocumentClaimer  $preview    Single-use preview grant service.
     *
     * @since  2.0.0
     */
    public function __construct(
        private StudioHostSessionAuthority $authority,
        private StudioPreviewDocumentClaimer $preview,
    ) {
    }

    /**
     * Re-resolve authorization, pin generation and transport, then atomically claim one document.
     *
     * @param   ServerRequestInterface  $request  Authenticated same-origin iframe navigation.
     *
     * @return  ResponseInterface  Hardened no-store HTML or a non-disclosing canonical refusal.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();
        $contextKey = $query['context'] ?? null;
        $requestId = $query['render'] ?? null;
        $generation = $query['generation'] ?? null;
        if (!is_string($contextKey) || !is_string($requestId) || !is_string($generation)) {
            return self::refusal('invalid-request', 'studio.preview/invalid-document-request');
        }
        try {
            $context = AdministratorRequest::context($request);
            $snapshot = $this->authority->resolve($context, $contextKey);
            if (
                !$snapshot->modeAllowed
                || !hash_equals($snapshot->generation, $generation)
                || !hash_equals($snapshot->session->sessionGeneration, $generation)
            ) {
                return self::refusal('invalid-request', 'studio.host/stale-session-generation');
            }
            $transport = StudioPreviewHttpTransport::fromDocument($request);
            $grant = $this->preview->claimDocument(
                $context,
                $snapshot,
                $requestId,
                $transport,
            );
        } catch (StudioHostAccessRefused $refused) {
            return self::refusal($refused->category, $refused->diagnosticCode);
        } catch (StudioPreviewRefused $refused) {
            return self::refusal($refused->category, $refused->diagnosticCode);
        } catch (InvalidArgumentException) {
            return self::refusal('invalid-request', 'studio.preview/invalid-transport');
        }
        if ($grant === null) {
            return self::refusal('not-found', 'studio.preview/grant-unavailable');
        }

        try {
            $stylesheetHref = '/administrator/studio/preview/styles.css?' . http_build_query([
                'context' => $contextKey,
                'render' => $requestId,
                'generation' => $generation,
                'channel' => $transport->channelId,
                'source' => $transport->sourceId,
            ], '', '&', PHP_QUERY_RFC3986);
            $html = StudioPreviewStylesheet::activate(
                $grant->document->html,
                $stylesheetHref,
                $grant->document->stylesheet !== null,
            );
        } catch (InvalidArgumentException) {
            return self::refusal('invalid-request', 'studio.preview/invalid-rendered-document');
        }

        return new HtmlResponse($html, 200, [
            'Cache-Control' => 'no-store, private',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'SAMEORIGIN',
        ]);
    }

    /**
     * Return one canonical host error without resource or grant details.
     *
     * @param   string  $category  Closed host-error category.
     * @param   string  $code      Stable non-disclosing diagnostic code.
     *
     * @return  TextResponse  Exact canonical Producer refusal.
     *
     * @since   2.0.0
     */
    private static function refusal(string $category, string $code): TextResponse
    {
        $response = (new StrictResponder())->refusal(StudioProducerError::error($category, $code));

        return new TextResponse($response->body, StudioProducerError::status($category), [
            'Cache-Control' => 'no-store, private',
            'Content-Length' => $response->headers['content-length'],
            'Content-Type' => $response->headers['content-type'],
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => $response->headers['x-content-type-options'],
        ]);
    }
}
