<?php

declare(strict_types=1);

namespace Kumwe\App\Administrator\Http\Handler;

use JsonException;
use Kumwe\App\Administrator\Http\AdministratorRequest;
use Kumwe\App\Studio\Application\Host\StudioHostAccessRefused;
use Kumwe\App\Studio\Application\Host\StudioHostDispatcher;
use Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority;
use Kumwe\App\Studio\Application\Preview\StudioPreviewTransportGuard;
use Kumwe\App\Studio\Domain\Host\StudioResourceKind;
use Kumwe\App\Studio\Domain\Host\StudioSessionMode;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use stdClass;
use ValueError;

/**
 * Opens a Studio host session from authenticated administrator identity and server-side policy.
 *
 * @since  2.0.0
 */
final readonly class AdministratorStudioSessionHandler implements RequestHandlerInterface
{
    /**
     * Bind session opening to the production host authority boundary.
     *
     * @param  StudioHostSessionAuthority   $authority  Trusted identity, policy and generation service.
     * @param  StudioPreviewTransportGuard  $preview    Server-derived preview channel metadata.
     *
     * @since  2.0.0
     */
    public function __construct(
        private StudioHostSessionAuthority $authority,
        private StudioPreviewTransportGuard $preview,
    ) {
    }

    /**
     * Validate the closed open request and return only the negotiated host authority projection.
     *
     * @param   ServerRequestInterface  $request  Authenticated, authorized and CSRF-checked request.
     *
     * @return  ResponseInterface  No-store session projection or canonical non-disclosing host error.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $body = json_decode((string) $request->getBody(), false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::response(StudioHostDispatcher::refusal('invalid-request', 'studio.host/invalid-request'));
        }
        $members = $body instanceof stdClass ? array_keys(get_object_vars($body)) : [];
        sort($members, SORT_STRING);
        if (
            !$body instanceof stdClass
            || $members !== ['mode', 'resourceId', 'resourceKind']
            || !is_string($body->mode)
            || !is_string($body->resourceId)
            || !is_string($body->resourceKind)
        ) {
            return self::response(StudioHostDispatcher::refusal('invalid-request', 'studio.host/invalid-request'));
        }

        try {
            $snapshot = $this->authority->open(
                AdministratorRequest::context($request),
                StudioSessionMode::from($body->mode),
                StudioResourceKind::from($body->resourceKind),
                $body->resourceId,
            );
        } catch (StudioHostAccessRefused $refused) {
            return self::response(StudioHostDispatcher::refusal(
                $refused->category,
                $refused->diagnosticCode,
            ));
        } catch (ValueError | \InvalidArgumentException) {
            return self::response(StudioHostDispatcher::refusal('invalid-request', 'studio.host/invalid-request'));
        }

        return new JsonResponse([
            'hostCapabilities' => StudioHostSessionAuthority::HOST_CAPABILITIES,
            'lifecycle' => [
                'canPublish' => $snapshot->canPublish,
                'canUnpublish' => $snapshot->canUnpublish,
            ],
            'mode' => $snapshot->session->mode->value,
            'permissions' => $snapshot->permissions,
            'protocolVersion' => StudioHostDispatcher::PROTOCOL_VERSION,
            'preview' => [
                'channelId' => $this->preview->channelId($snapshot->session),
                'documentPath' => '/administrator/studio/preview',
                'origin' => $this->preview->origin(),
                'sourceId' => $this->preview->sourceId($snapshot->session),
            ],
            'resourceContextKey' => $snapshot->session->resourceContextKey,
            'resourceKind' => $snapshot->session->resourceKind->value,
            'sessionGeneration' => $snapshot->generation,
        ], 201, ['Cache-Control' => 'no-store']);
    }

    /**
     * Preserve a canonical application outcome at the administrator transport boundary.
     *
     * @param   \Kumwe\App\Studio\Application\Host\StudioHostOutcome  $outcome  Application host outcome.
     *
     * @return  JsonResponse  No-store JSON response with the canonical status.
     *
     * @since   2.0.0
     */
    private static function response(\Kumwe\App\Studio\Application\Host\StudioHostOutcome $outcome): JsonResponse
    {
        return new JsonResponse($outcome->document, $outcome->status, ['Cache-Control' => 'no-store']);
    }
}
