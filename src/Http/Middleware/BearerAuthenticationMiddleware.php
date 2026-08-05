<?php

declare(strict_types=1);

namespace Kumwe\CMS\Http\Middleware;

use InvalidArgumentException;
use Kumwe\CMS\Application\Authorization\AuthenticationStrength;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Identity\Application\Authentication\AccessTokenVerifier;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Identity\Domain\Capability;
use Laminas\Diactoros\Response\JsonResponse;
use LogicException;
use Mezzio\Router\Route;
use Mezzio\Router\RouteResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class BearerAuthenticationMiddleware implements MiddlewareInterface
{
    public const OPTION_AUTHENTICATION = 'authentication';
    public const OPTION_REQUIRED_CAPABILITIES = 'required_capabilities';

    private const AUTHENTICATION_BEARER = 'bearer';

    public function __construct(
        private AccessTokenVerifier $verifier,
        private string $realm = 'kumwe-api',
    ) {
        if (preg_match('/^[A-Za-z0-9._-]{1,64}$/D', $realm) !== 1) {
            throw new InvalidArgumentException('A bearer authentication realm must be a safe identifier.');
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $options = $this->routeOptions($request);

        if (($options[self::OPTION_AUTHENTICATION] ?? null) !== self::AUTHENTICATION_BEARER) {
            return $handler->handle($request);
        }

        $authorizationHeaders = $request->getHeader('Authorization');

        if ($authorizationHeaders === []) {
            return $this->unauthorized(null);
        }

        if (count($authorizationHeaders) !== 1) {
            return $this->unauthorized('invalid_request');
        }

        $token = $this->parseToken($authorizationHeaders[0]);

        if ($token === null) {
            return $this->unauthorized('invalid_request');
        }

        $principal = $this->verifier->verify($token);

        if ($principal === null) {
            return $this->unauthorized('invalid_token');
        }

        $required = $this->requiredCapabilities($options);

        foreach ($required as $capability) {
            if (!$principal->hasCapability($capability)) {
                return $this->forbidden($required);
            }
        }

        return $handler->handle(
            $request
                ->withAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE, $principal)
                ->withAttribute(ExecutionContext::REQUEST_ATTRIBUTE, $principal->context(
                    SiteContext::default(),
                    AuthenticationStrength::BearerToken,
                    $this->requestId($request),
                )),
        );
    }

    /** @return array<string, mixed> */
    private function routeOptions(ServerRequestInterface $request): array
    {
        $routeResult = $request->getAttribute(RouteResult::class);

        if (!$routeResult instanceof RouteResult || !$routeResult->isSuccess()) {
            return [];
        }

        $route = $routeResult->getMatchedRoute();

        if (!$route instanceof Route) {
            return [];
        }

        /** @var array<string, mixed> $options */
        $options = $route->getOptions();

        return $options;
    }

    private function parseToken(string $header): ?string
    {
        if (preg_match('/^Bearer ([A-Za-z0-9._~+\/-]+=*)$/iD', $header, $matches) !== 1) {
            return null;
        }

        $token = $matches[1];
        $length = strlen($token);

        return $length >= 32 && $length <= 512 ? $token : null;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return list<Capability>
     */
    private function requiredCapabilities(array $options): array
    {
        $configured = $options[self::OPTION_REQUIRED_CAPABILITIES] ?? [];

        if (!is_array($configured) || !array_is_list($configured)) {
            throw new LogicException('Required bearer capabilities must be configured as a list.');
        }

        $required = [];

        foreach ($configured as $capability) {
            if (!is_string($capability)) {
                throw new LogicException('Required bearer capabilities must contain strings.');
            }

            try {
                $value = Capability::fromString($capability);
            } catch (InvalidArgumentException $exception) {
                throw new LogicException('A configured bearer capability is invalid.', previous: $exception);
            }

            if (isset($required[$value->value()])) {
                throw new LogicException('Required bearer capabilities must be unique.');
            }

            $required[$value->value()] = $value;
        }

        ksort($required, SORT_STRING);

        return array_values($required);
    }

    private function unauthorized(?string $error): ResponseInterface
    {
        $challenge = sprintf('Bearer realm="%s"', $this->realm);

        if ($error !== null) {
            $challenge .= sprintf(', error="%s"', $error);
        }

        return new JsonResponse([
            'type' => 'about:blank',
            'title' => 'Unauthorized',
            'status' => 401,
            'detail' => 'A valid bearer access token is required.',
        ], 401, [
            'Content-Type' => 'application/problem+json',
            'WWW-Authenticate' => $challenge,
            'Cache-Control' => 'no-store',
        ]);
    }

    private function requestId(ServerRequestInterface $request): string
    {
        $requestId = $request->getAttribute(RequestIdMiddleware::ATTRIBUTE);

        return is_string($requestId) && $requestId !== ''
            ? $requestId
            : 'request-' . bin2hex(random_bytes(16));
    }

    /** @param list<Capability> $required */
    private function forbidden(array $required): ResponseInterface
    {
        $scope = implode(' ', array_map(
            static fn (Capability $capability): string => $capability->value(),
            $required,
        ));
        $challenge = sprintf(
            'Bearer realm="%s", error="insufficient_scope", scope="%s"',
            $this->realm,
            $scope,
        );

        return new JsonResponse([
            'type' => 'about:blank',
            'title' => 'Forbidden',
            'status' => 403,
            'detail' => 'The access token does not grant every required capability.',
        ], 403, [
            'Content-Type' => 'application/problem+json',
            'WWW-Authenticate' => $challenge,
            'Cache-Control' => 'no-store',
        ]);
    }
}
