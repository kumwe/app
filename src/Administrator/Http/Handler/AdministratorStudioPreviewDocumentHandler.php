<?php

declare(strict_types=1);

namespace Kumwe\App\Administrator\Http\Handler;

use InvalidArgumentException;
use Kumwe\App\Administrator\Http\AdministratorRequest;
use Kumwe\App\Administrator\Http\StudioPreviewHttpTransport;
use Kumwe\App\Studio\Application\Host\StudioHostAccessRefused;
use Kumwe\App\Studio\Application\Host\StudioHostDispatcher;
use Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority;
use Kumwe\App\Studio\Application\Preview\StudioPreviewDocumentClaimer;
use Kumwe\App\Studio\Application\Preview\StudioPreviewRefused;
use Kumwe\App\Studio\Application\Preview\StudioPreviewThemeStylesheet;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\JsonResponse;
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
            $stylesheetHref = '/administrator/studio/preview/theme.css?' . http_build_query([
                'context' => $contextKey,
                'render' => $requestId,
                'generation' => $generation,
                'channel' => $transport->channelId,
                'source' => $transport->sourceId,
            ], '', '&', PHP_QUERY_RFC3986);
            $html = StudioPreviewThemeStylesheet::activate(
                $grant->document->html,
                $stylesheetHref,
                $grant->document->themeStylesheet !== null,
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
     * @return  JsonResponse  No-store refusal.
     *
     * @since   2.0.0
     */
    private static function refusal(string $category, string $code): JsonResponse
    {
        $outcome = StudioHostDispatcher::refusal($category, $code);

        return new JsonResponse($outcome->document, $outcome->status, [
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
