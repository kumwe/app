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
use stdClass;

/**
 * Serves the extension REST resource: what is installed, and the lifecycle moves an operator can make.
 *
 * The route path and method choose the operation — activate, disable, uninstall — rather than a body
 * field, so an unrecognised combination is refused instead of guessed at. What this class really owns
 * is the translation of lifecycle refusals into RFC 9457 documents an operator can act on, each under
 * its own `urn:kumwe:problem:` type: a throttled step-up answers 429 with a fixed `Retry-After`, a
 * demanded step-up and a denied capability answer 403 under separate types, and a malformed body
 * answers 422. Serialising the mutation against concurrent lifecycle work is not this handler's job;
 * `TrustLifecycleMiddleware` holds that lock around the whole pipeline.
 *
 * @since  2.0.0
 */
final readonly class ExtensionApiHandler implements RequestHandlerInterface
{
    /**
     * Wire the route to the lifecycle service and the factory that renders its refusals.
     *
     * @param  ExtensionManager               $extensions  Lifecycle service performing the registry work.
     * @param  ProblemDetailsResponseFactory  $problems    Builds the `application/problem+json` bodies sent back.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ExtensionManager $extensions,
        private ProblemDetailsResponseFactory $problems,
    ) {
    }

    /**
     * List the installed extensions, or apply the activation, disable or uninstall the route names.
     *
     * A `GET` answers the installed set and reads no body. Every other verb resolves `vendor/name` from
     * the route attributes, validates the mutation body, and delegates. An uninstall answers an empty
     * 204 because there is no longer a resource to represent; activate and disable answer whatever
     * record the manager reports. Failures outside the four translated here — a release that fails its
     * trust check, an unreachable registry — propagate to the pipeline's problem-details boundary.
     *
     * @param   ServerRequestInterface  $request  Request whose `vendor` and `name` route attributes address
     *          the extension and whose path suffix and method select the operation.
     *
     * @return  ResponseInterface  The manager's JSON result, an empty 204 after an uninstall, or a problem
     *          document saying why the operation was refused.
     *
     * @since   2.0.0
     */
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

    /**
     * Validate the body of a lifecycle mutation and take the surface and step-up credential out of it.
     *
     * An empty body is read as `{}`, so a mutation needing neither value can be sent without one.
     * Unknown members are refused rather than ignored, which is what stops a `surface` sent to
     * `disable` — where it would do nothing — from looking as though it took effect. The surface may
     * arrive as a `surface` query parameter instead, and the credential is length bounded here so an
     * oversized value never reaches the password verifier.
     *
     * @param   ServerRequestInterface  $request        Request carrying the mutation body and query string.
     * @param   bool                    $allowsSurface  Whether the operation takes a surface; activation alone does.
     *
     * @return  array{?ThemeSurface, ?string}  The requested surface and the step-up password, each null
     *          when the caller supplied none.
     *
     * @throws  InvalidArgumentException  When the body is not valid JSON, is not an object, carries an
     *          unsupported member, or names an unusable surface or credential.
     *
     * @since   2.0.0
     */
    private function mutationInput(ServerRequestInterface $request, bool $allowsSurface): array
    {
        $encoded = trim((string) $request->getBody());
        try {
            $decoded = json_decode($encoded === '' ? '{}' : $encoded, false, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The extension activation body must be valid JSON.', 0, $exception);
        }
        if (!$decoded instanceof stdClass) {
            throw new InvalidArgumentException('The extension operation body must be a JSON object.');
        }
        /** @var array<string, mixed> $body */
        $body = get_object_vars($decoded);
        $allowed = $allowsSurface ? ['surface', 'current_password'] : ['current_password'];
        if (array_diff(array_keys($body), $allowed) !== []) {
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

    /**
     * Assemble the identifier the extension manager keys extensions by from the route attributes.
     *
     * The two segments are joined as they arrive; whether an extension of that name is installed is the
     * manager's question, not this one's.
     *
     * @param   ServerRequestInterface  $request  Request whose `vendor` and `name` attributes the router set.
     *
     * @return  string  The `vendor/name` identifier the lifecycle calls are made against.
     *
     * @throws  InvalidArgumentException  When either route attribute is absent or is not a string.
     *
     * @since   2.0.0
     */
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
