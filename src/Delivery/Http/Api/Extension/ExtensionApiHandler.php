<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Extension;

use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\Application\Authorization\AuthorizationDenied;
use Kumwe\CMS\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\CMS\Delivery\Http\Api\ApiExecutionContext;
use Kumwe\CMS\Extension\Application\ExtensionManager;
use Kumwe\CMS\Identity\Application\Administration\AuthenticationThrottled;
use Kumwe\CMS\Presentation\Application\StepUpAuthenticationRequired;
use Kumwe\CMS\Presentation\ThemeSurface;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class ExtensionApiHandler implements RequestHandlerInterface
{
    public function __construct(
        private ExtensionManager $extensions,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            if (strtoupper($request->getMethod()) === 'GET') {
                return new JsonResponse(
                    ['items' => $this->extensions->installed(ApiExecutionContext::fromRequest($request))],
                    200,
                    ['Cache-Control' => 'no-store'],
                );
            }
            $identifier = $this->identifier($request);
            $context = ApiExecutionContext::fromRequest($request);
            $path = $request->getUri()->getPath();
            if (str_ends_with($path, '/activate')) {
                [$surface, $stepUpCredential] = $this->mutationInput($request, true);
                return new JsonResponse($this->extensions->activate(
                    $identifier,
                    $context,
                    $surface,
                    $stepUpCredential,
                ));
            }
            if (str_ends_with($path, '/disable')) {
                [, $stepUpCredential] = $this->mutationInput($request, false);
                return new JsonResponse($this->extensions->disable($identifier, $context, $stepUpCredential));
            }
            if (strtoupper($request->getMethod()) === 'DELETE') {
                [, $stepUpCredential] = $this->mutationInput($request, false);
                $this->extensions->uninstall($identifier, $context, $stepUpCredential);
                return new EmptyResponse(204);
            }
            throw new InvalidArgumentException('The extension operation is not supported.');
        } catch (AuthenticationThrottled $exception) {
            return $this->problems->create(
                429,
                'Too Many Authentication Attempts',
                $exception->getMessage(),
                'urn:kumwe:problem:authentication-throttled',
                (string) $request->getUri(),
            )->withHeader('Retry-After', '900');
        } catch (StepUpAuthenticationRequired $exception) {
            return $this->problems->create(
                403,
                'Step-up Authentication Required',
                $exception->getMessage(),
                'urn:kumwe:problem:step-up-required',
                (string) $request->getUri(),
            );
        } catch (AuthorizationDenied $exception) {
            return $this->problems->create(
                403,
                'Forbidden',
                $exception->getMessage(),
                'urn:kumwe:problem:authorization-denied',
                (string) $request->getUri(),
            );
        } catch (InvalidArgumentException $exception) {
            return $this->problems->create(
                422,
                'Unprocessable Extension Operation',
                $exception->getMessage(),
                'urn:kumwe:problem:validation-failed',
                (string) $request->getUri(),
            );
        }
    }

    /** @return array{?ThemeSurface, ?string} */
    private function mutationInput(ServerRequestInterface $request, bool $allowsSurface): array
    {
        $encoded = trim((string) $request->getBody());
        try {
            $body = $encoded === '' ? [] : json_decode($encoded, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The extension activation body must be valid JSON.', 0, $exception);
        }
        $allowed = $allowsSurface ? ['surface', 'current_password'] : ['current_password'];
        if (!is_array($body) || array_is_list($body) || array_diff(array_keys($body), $allowed) !== []) {
            throw new InvalidArgumentException('The extension operation body contains unsupported fields.');
        }
        $surface = $allowsSurface ? ($body['surface'] ?? ($request->getQueryParams()['surface'] ?? null)) : null;
        if ($surface !== null && !is_string($surface)) {
            throw new InvalidArgumentException('The extension activation surface must be a string.');
        }
        $credential = $body['current_password'] ?? null;
        if (
            $credential !== null
            && (!is_string($credential) || $credential === '' || strlen($credential) > 4_096)
        ) {
            throw new InvalidArgumentException(
                'The current password must be a non-empty string of at most 4096 bytes.',
            );
        }

        return [ThemeSurface::optional($surface), $credential];
    }

    private function identifier(ServerRequestInterface $request): string
    {
        $vendor = $request->getAttribute('vendor');
        $name = $request->getAttribute('name');
        if (!is_string($vendor) || !is_string($name)) {
            throw new InvalidArgumentException('An extension identifier is required.');
        }
        return $vendor . '/' . $name;
    }
}
