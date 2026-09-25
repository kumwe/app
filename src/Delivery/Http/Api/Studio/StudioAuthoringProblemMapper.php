<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Http\Api\Studio;

use Kumwe\App\Delivery\Http\Api\ProblemDetailsResponseFactory;
use Kumwe\App\Studio\Application\Authoring\StudioMachineAuthoringRefused;
use Psr\Http\Message\ResponseInterface;

/**
 * Maps one canonical Studio authoring refusal onto its registered RFC 9457 problem type.
 *
 * The refusal already carries Producer's category, diagnostics and safe revision — the same `host-error`
 * the browser receives. This mapper chooses the registered problem type from that category, keeps the
 * HTTP status Producer pairs with it, and publishes the category, diagnostic codes and revision as the
 * type's closed extension members, so a REST caller branches on exactly the facts a browser caller sees.
 *
 * @since  2.0.0
 */
final readonly class StudioAuthoringProblemMapper
{
    /**
     * Problem type suffix and title for each Producer category, keyed by category.
     *
     * @var    array<string, array{string, string}>
     * @since  2.0.0
     */
    private const array TYPES = [
        'invalid-request' => ['studio-authoring-invalid-request', 'Invalid Studio Authoring Request'],
        'incompatible' => ['studio-authoring-invalid-request', 'Invalid Studio Authoring Request'],
        'cancelled' => ['studio-authoring-invalid-request', 'Invalid Studio Authoring Request'],
        'unauthenticated' => ['studio-authoring-unauthenticated', 'Studio Authoring Unauthenticated'],
        'forbidden' => ['studio-authoring-forbidden', 'Studio Authoring Forbidden'],
        'not-found' => ['studio-authoring-not-found', 'Studio Authoring Target Not Found'],
        'conflict' => ['studio-authoring-conflict', 'Studio Authoring Conflict'],
        'limit-exceeded' => ['studio-authoring-limit-exceeded', 'Studio Authoring Limit Exceeded'],
        'validation-failed' => ['studio-authoring-validation-failed', 'Studio Authoring Validation Failed'],
        'rate-limited' => ['studio-authoring-rate-limited', 'Studio Authoring Rate Limited'],
        'internal' => ['studio-authoring-internal', 'Studio Authoring Failed'],
        'unavailable' => ['studio-authoring-unavailable', 'Studio Authoring Unavailable'],
    ];

    /**
     * Bind the mapper to the validating problem-document factory.
     *
     * @param  ProblemDetailsResponseFactory  $problems  Registry-validated problem document builder.
     *
     * @since  2.0.0
     */
    public function __construct(private ProblemDetailsResponseFactory $problems)
    {
    }

    /**
     * Render one refusal as its registered problem document.
     *
     * @param   StudioMachineAuthoringRefused  $refused   Canonical Studio refusal.
     * @param   string                         $instance  Request URI recorded as the problem instance.
     *
     * @return  ResponseInterface  `application/problem+json` response with the category's status.
     *
     * @since   2.0.0
     */
    public function problem(StudioMachineAuthoringRefused $refused, string $instance): ResponseInterface
    {
        [$suffix, $title] = self::TYPES[$refused->category()] ?? self::TYPES['internal'];
        $status = $refused->status();
        if ($refused->keyReused()) {
            [$suffix, $title, $status] = [
                'studio-authoring-idempotency-key-reused',
                'Studio Authoring Idempotency Key Reused',
                422,
            ];
        } elseif ($refused->inProgress()) {
            [$suffix, $title, $status] = [
                'studio-authoring-idempotency-in-progress',
                'Studio Authoring Operation In Progress',
                409,
            ];
        } elseif (!isset(self::TYPES[$refused->category()])) {
            $status = 500;
        }
        $extensions = [
            'studio_category' => $refused->category(),
            'studio_diagnostics' => $refused->diagnosticCodes(),
        ];
        $revision = $refused->revision();
        if ($revision !== null) {
            $extensions['studio_revision'] = $revision;
        }

        return $this->problems->create(
            $status,
            $title,
            'The Studio authoring request was refused.',
            'urn:kumwe:problem:' . $suffix,
            $instance,
            $extensions,
        )->withHeader('Cache-Control', 'no-store');
    }
}
