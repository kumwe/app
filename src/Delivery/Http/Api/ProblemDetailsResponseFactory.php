<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api;

use InvalidArgumentException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;

final class ProblemDetailsResponseFactory
{
    /**
     * @param array<string, bool|int|float|string|array<mixed>|null> $extensions
     */
    public function create(
        int $status,
        string $title,
        string $detail,
        string $type = 'about:blank',
        ?string $instance = null,
        array $extensions = [],
    ): ResponseInterface {
        if ($status < 400 || $status > 599) {
            throw new InvalidArgumentException('A problem response requires a 4xx or 5xx status.');
        }

        if (trim($title) === '' || trim($detail) === '') {
            throw new InvalidArgumentException('A problem response requires a title and detail.');
        }

        if ($type !== 'about:blank' && preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:[^\s]+$/D', $type) !== 1) {
            throw new InvalidArgumentException('A problem type must be about:blank or an absolute URI.');
        }

        foreach (['type', 'title', 'status', 'detail', 'instance'] as $reserved) {
            if (array_key_exists($reserved, $extensions)) {
                throw new InvalidArgumentException('Problem extensions cannot replace RFC 9457 members.');
            }
        }

        $body = [
            'type' => $type,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
        ];

        if ($instance !== null) {
            $body['instance'] = $instance;
        }

        return new JsonResponse(
            [...$body, ...$extensions],
            $status,
            ['Content-Type' => 'application/problem+json'],
        );
    }
}
