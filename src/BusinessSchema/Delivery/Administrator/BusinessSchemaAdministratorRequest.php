<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Delivery\Administrator;

use DateTimeImmutable;
use InvalidArgumentException;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Request readers and the shared redirect the business-schema administrator handlers have in common.
 *
 * `AdministratorRequest` covers what every administrator screen needs; this covers the parts only the
 * schema-plan routes need — the `{id}` segment named as a plan rather than a content entry, fields that
 * are legitimately blank, a required timestamp, and the one redirect that returns to the plans screen
 * with a plan selected and a notice to show. Concentrating them here is what keeps the six schema
 * handlers free of duplicated parsing and guarantees they all land back on the same URL shape, which
 * `BusinessSchemaPlansHandler` is built to read.
 *
 * The class is a pure helper namespace: it carries no state and its private constructor blocks
 * instances.
 *
 * @since  2.0.0
 */
final class BusinessSchemaAdministratorRequest
{
    /**
     * Read the schema-plan identifier the router captured from the route path.
     *
     * @param   ServerRequestInterface  $request  Request the routing middleware has already matched.
     *
     * @return  string  The `{id}` segment, guaranteed non-empty; its shape is not checked here, so an
     *          identifier that names no plan surfaces later as a missing plan rather than a bad request.
     *
     * @throws  InvalidArgumentException  When the handler was reached through a route with no `{id}` segment.
     *
     * @since   2.0.0
     */
    public static function planId(ServerRequestInterface $request): string
    {
        $identifier = $request->getAttribute('id');
        if (!is_string($identifier) || $identifier === '') {
            throw new InvalidArgumentException('The schema-plan route identifier is missing.');
        }

        return $identifier;
    }

    /**
     * Read a field the operator may legitimately leave blank.
     *
     * A blank input becomes null rather than an empty string, which is the distinction the schema
     * services depend on: a null confirmation, password, or evidence reference means "not supplied",
     * whereas an empty string would be compared against a checksum and reported as a mismatch.
     *
     * @param   array<string, string>  $form   Flattened form as returned by `AdministratorRequest::form()`.
     * @param   string                 $field  Name of the optional field.
     *
     * @return  string|null  The trimmed value, or null when the field was absent or blank.
     *
     * @since   2.0.0
     */
    public static function optional(array $form, string $field): ?string
    {
        $value = trim($form[$field] ?? '');

        return $value === '' ? null : $value;
    }

    /**
     * Read a mandatory timestamp field, such as the backup and verification instants on recovery evidence.
     *
     * The parse failure is converted into the same `InvalidArgumentException` a missing field raises, so
     * a handler has one rejection to reason about and the underlying date error is kept as the previous
     * exception rather than surfacing to the operator.
     *
     * @param   array<string, string>  $form   Flattened form as returned by `AdministratorRequest::form()`.
     * @param   string                 $field  Name of the field holding the timestamp.
     *
     * @return  DateTimeImmutable  The instant as written, in whatever timezone the text carried.
     *
     * @throws  InvalidArgumentException  When the field is blank or is not a readable date and time.
     *
     * @since   2.0.0
     */
    public static function date(array $form, string $field): DateTimeImmutable
    {
        $value = trim($form[$field] ?? '');
        if ($value === '') {
            throw new InvalidArgumentException(sprintf('The %s field is required.', $field));
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Exception $exception) {
            throw new InvalidArgumentException(sprintf('The %s field is invalid.', $field), 0, $exception);
        }
    }

    /**
     * Build the redirect every schema-plan action finishes with: back to the plans screen, plan selected.
     *
     * Answering a POST with a redirect rather than a rendered page is what stops a refresh from
     * re-approving or re-executing, and carrying the plan in the query string is what leaves the
     * operator looking at the plan they just acted on. The notice is a key rather than a sentence;
     * `BusinessSchemaPlansHandler` resolves it and renders nothing when it does not recognise it.
     *
     * @param   string   $planId      Plan to preselect on the destination screen.
     * @param   string   $notice      Notice key naming the outcome, such as `approved` or `executed`.
     * @param   ?string  $evidenceId  Recovery evidence to preselect; null leaves the screen to fall back
     *          to whatever evidence the plan is bound to.
     *
     * @return  RedirectResponse  A 303 to `/administrator/business-schema-plans` carrying `plan`,
     *          `notice`, and `evidence` when one was given.
     *
     * @since   2.0.0
     */
    public static function redirect(string $planId, string $notice, ?string $evidenceId = null): RedirectResponse
    {
        $query = [
            'plan' => $planId,
            'notice' => $notice,
        ];
        if ($evidenceId !== null) {
            $query['evidence'] = $evidenceId;
        }

        return new RedirectResponse('/administrator/business-schema-plans?' . http_build_query($query), 303);
    }

    /**
     * Block instantiation; every member of this helper is static.
     *
     * @since  2.0.0
     */
    private function __construct()
    {
    }
}
