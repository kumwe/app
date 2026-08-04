<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Site;

use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\CMS\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\CMS\Site\Application\SiteSettings;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class SiteSettingsApiHandler implements RequestHandlerInterface
{
    public function __construct(private SiteSettings $settings, private ProblemDetailsResponseFactory $problems)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (strtoupper($request->getMethod()) === 'GET') {
            return new JsonResponse($this->settings->current(), 200, ['Cache-Control' => 'no-store']);
        }

        try {
            $body = json_decode((string) $request->getBody(), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($body) || array_is_list($body)) {
                throw new InvalidArgumentException('Settings input must be a JSON object.');
            }
            /** @var array<string, mixed> $body */
            $principal = $request->getAttribute(AuthenticatedPrincipal::REQUEST_ATTRIBUTE);
            if (!$principal instanceof AuthenticatedPrincipal) {
                throw new \LogicException('Settings mutations require an authenticated principal.');
            }
            $this->settings->updateAll($principal->subject(), $body);

            return new JsonResponse($this->settings->current(), 200, ['Cache-Control' => 'no-store']);
        } catch (JsonException|InvalidArgumentException $exception) {
            return $this->problems->create(
                422,
                'Unprocessable Settings',
                $exception->getMessage(),
                'urn:kumwe:problem:validation-failed',
                (string) $request->getUri(),
            );
        }
    }
}
