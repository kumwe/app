<?php

declare(strict_types=1);

namespace Kumwe\App\Administrator\Http\Handler;

use Kumwe\App\Administrator\Http\AdministratorRequest;
use Kumwe\App\Studio\Application\Host\StudioHostAccessRefused;
use Kumwe\App\Studio\Application\Host\StudioHostSessionAuthority;
use Kumwe\App\Studio\Application\Media\StudioMediaPortRejected;
use Kumwe\App\Studio\Application\Media\StudioMediaOperations;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Authenticated direct-binary destination issued by Studio media upload grants.
 *
 * @since  2.0.0
 */
final readonly class AdministratorStudioMediaUploadHandler implements RequestHandlerInterface
{
    /**
     * Bind the transfer route to the canonical media upload-session use case.
     *
     * @param  StudioMediaOperations       $media      Scoped grant and staging boundary.
     * @param  StudioHostSessionAuthority  $authority  Fresh AP-3 session and permission fence.
     *
     * @since  2.0.0
     */
    public function __construct(
        private StudioMediaOperations $media,
        private StudioHostSessionAuthority $authority,
    ) {
    }

    /**
     * Stream one PUT into private host custody under every grant header and authenticated App identity.
     *
     * @param   ServerRequestInterface  $request  Authenticated administrator request.
     *
     * @return  ResponseInterface  Empty no-store response; failures never echo identifiers or tokens.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $identifier = $request->getAttribute('upload');
        if (!is_string($identifier) || preg_match('/^[a-f0-9]{32}$/D', $identifier) !== 1) {
            return new EmptyResponse(404, ['Cache-Control' => 'no-store']);
        }
        try {
            $context = AdministratorRequest::context($request);
            $contextKey = $request->getHeaderLine('X-Studio-Resource-Context');
            $generation = $request->getHeaderLine('X-Studio-Session-Generation');
            $snapshot = $this->authority->resolve($context, $contextKey);
            if (
                !$snapshot->modeAllowed
                || !$this->authority->permits($snapshot, 'studio.permission/upload-media')
                || !hash_equals($snapshot->session->sessionGeneration, $generation)
                || !hash_equals($snapshot->generation, $generation)
            ) {
                return new EmptyResponse(404, ['Cache-Control' => 'no-store']);
            }
            $this->media->receive(
                $context,
                'uploads/' . $identifier,
                $contextKey,
                $generation,
                $request->getHeaderLine('X-Studio-Upload-Token'),
                $request->getHeaderLine('Content-Type'),
                $request->getBody(),
            );
        } catch (StudioHostAccessRefused) {
            return new EmptyResponse(404, ['Cache-Control' => 'no-store']);
        } catch (StudioMediaPortRejected $failure) {
            return new EmptyResponse(match ($failure->category) {
                'conflict' => 409,
                'limit-exceeded' => 413,
                'validation-failed' => 422,
                'unavailable' => 503,
                default => 404,
            }, ['Cache-Control' => 'no-store']);
        }

        return new EmptyResponse(204, ['Cache-Control' => 'no-store']);
    }
}
