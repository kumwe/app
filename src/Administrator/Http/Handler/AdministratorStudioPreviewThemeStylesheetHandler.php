<?php

declare(strict_types=1);

namespace Kumwe\App\Administrator\Http\Handler;

use InvalidArgumentException;
use Kumwe\App\Administrator\Http\AdministratorRequest;
use Kumwe\App\Administrator\Http\StudioPreviewHttpTransport;
use Kumwe\App\Studio\Application\Host\StudioHostAccessRefused;
use Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority;
use Kumwe\App\Studio\Application\Preview\StudioPreviewDocumentClaimer;
use Kumwe\App\Studio\Application\Preview\StudioPreviewRefused;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\TextResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Authenticated no-store delivery of the exact theme bytes bound to a claimed preview grant.
 *
 * @since  2.0.0
 */
final readonly class AdministratorStudioPreviewThemeStylesheetHandler implements RequestHandlerInterface
{
    /**
     * Bind stylesheet delivery to live host-session authority and claimed preview grants.
     *
     * @param  StudioHostSessionAuthority    $authority  Trusted host-session resolver.
     * @param  StudioPreviewDocumentClaimer  $preview    Claimed preview subresource service.
     *
     * @since  2.0.0
     */
    public function __construct(
        private StudioHostSessionAuthority $authority,
        private StudioPreviewDocumentClaimer $preview,
    ) {
    }

    /**
     * Revalidate live authority and exact grant coordinates before returning closed generated CSS.
     *
     * @param   ServerRequestInterface  $request  Authenticated same-origin stylesheet request.
     *
     * @return  ResponseInterface  Private no-store CSS or one non-disclosing 404.
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
            return self::unavailable();
        }
        try {
            $context = AdministratorRequest::context($request);
            $snapshot = $this->authority->resolve($context, $contextKey);
            if (
                !$snapshot->modeAllowed
                || !hash_equals($snapshot->generation, $generation)
                || !hash_equals($snapshot->session->sessionGeneration, $generation)
            ) {
                return self::unavailable();
            }
            $stylesheet = $this->preview->themeStylesheet(
                $context,
                $snapshot,
                $requestId,
                StudioPreviewHttpTransport::fromStylesheet($request),
            );
        } catch (StudioHostAccessRefused | StudioPreviewRefused | InvalidArgumentException) {
            return self::unavailable();
        }
        if ($stylesheet === null) {
            return self::unavailable();
        }

        return new TextResponse($stylesheet, 200, [
            'Cache-Control' => 'private, no-store',
            'Content-Type' => 'text/css; charset=utf-8',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Collapse every malformed, unauthorized, absent and expired stylesheet into the same response.
     *
     * @return  EmptyResponse  Non-disclosing no-store refusal.
     *
     * @since   2.0.0
     */
    private static function unavailable(): EmptyResponse
    {
        return new EmptyResponse(404, ['Cache-Control' => 'private, no-store']);
    }
}
