<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Http\Api;

use InvalidArgumentException;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;

/**
 * Builds the RFC 9457 `application/problem+json` document every JSON API refusal is rendered as.
 *
 * Handlers and middleware across the API surface send their refusals through here instead of assembling
 * a body each, so a client sees one media type, one member set and one status convention whatever
 * rejected the request. The factory also refuses to emit a document that would not be a valid problem:
 * a status outside the failure ranges, a blank title or detail, a `type` that is neither `about:blank`
 * nor an absolute URI, and an extension member that would shadow a reserved member are all caller
 * mistakes, and it is better to fail the request loudly than to answer with a document a client cannot
 * interpret.
 *
 * @since  2.0.0
 */
final class ProblemDetailsResponseFactory
{
    /**
     * Render one refusal as a problem document, with the status repeated in the body.
     *
     * `instance` is written only when supplied, since RFC 9457 makes it optional and an absent instance
     * is more honest than an empty one. Extension members are merged over the reserved members last,
     * but cannot displace any of them: the reserved-name check runs first and rejects the attempt, so
     * `type`, `title`, `status`, `detail` and `instance` always mean what this factory wrote.
     *
     * @param   int                                                     $status      HTTP status the response
     *          carries and repeats in the body; must be 400 through 599.
     * @param   string                                                  $title       Short, stable summary of
     *          the problem type, the same wording for every occurrence of it.
     * @param   string                                                  $detail      Sentence describing this
     *          one occurrence, written for the operator reading it.
     * @param   string                                                  $type        Absolute URI naming the
     *          problem type, or `about:blank` when the status is the whole story.
     * @param   ?string                                                 $instance    URI of this occurrence,
     *          normally the request URI; null leaves the member out.
     * @param   array<string, bool|int|float|string|array<mixed>|null>  $extensions  Extra problem members to
     *          merge into the body; none may be named after a reserved member.
     *
     * @return  ResponseInterface  A JSON response with the `application/problem+json` content type and the
     *          supplied status.
     *
     * @throws  InvalidArgumentException  When the status is outside 400 to 599, the title or detail is blank
     *          once trimmed, the type is neither `about:blank` nor an absolute URI, or an extension member
     *          is named after an RFC 9457 reserved member.
     *
     * @since   2.0.0
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
