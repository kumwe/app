<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSchema\Delivery\Administrator;

use DateInterval;
use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaEnvironment;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaService;
use Kumwe\CMS\BusinessSchema\Domain\SchemaPlan;
use Kumwe\CMS\BusinessSchema\Domain\SchemaPlanStep;
use Laminas\Diactoros\Response\HtmlResponse;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves the one administrator screen the whole schema-plan lifecycle is driven from.
 *
 * Planning, approval, execution, purging, and recovery are separate authorized actions, and every one
 * of them redirects back here, so this handler is what makes them feel like a single workspace: it
 * renders the catalog of persisted plans, the selected plan with its journal steps, the installation
 * that plan would change, and the recovery evidence bound to it. Its least obvious job is the
 * `evidence_qualifies` flag: it re-runs the same `qualifies()` check the approval path will run, so an
 * operator sees that their drill is stale, or was taken against another source schema or database
 * environment, while reading the screen rather than when the approval is refused.
 *
 * It is mounted read-only on `GET /administrator/business-schema-plans` behind the
 * `business.schema.read` capability.
 *
 * @since  2.0.0
 */
final readonly class BusinessSchemaPlansHandler implements RequestHandlerInterface
{
    /**
     * Sentences shown for the `notice` key each schema action redirects with.
     *
     * The keys are the vocabulary the sibling handlers pass to
     * `BusinessSchemaAdministratorRequest::redirect()`. Resolving through a fixed map rather than
     * echoing the query string is what keeps an arbitrary URL from putting text on the screen; an
     * unrecognised key renders no notice at all.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const NOTICES = [
        'planned' => 'The immutable schema plan was persisted. No physical schema work has run.',
        'purge-planned' => 'The destructive purge plan was persisted for independent review and approval.',
        'approved' => 'The exact persisted plan was approved.',
        'executed' => 'The approved schema plan finished execution.',
        'recovered' => 'Recovery finished and the execution journal was reconciled.',
        'evidence-recorded' => 'Tested backup and recovery evidence was recorded for this approval.',
    ];

    /**
     * Wire the screen to the schema facade, the environment identity, the renderer, and the clock.
     *
     * @param  BusinessSchemaService      $schemas      Answers every read the screen makes, under
     *         `business.schema.read`.
     * @param  BusinessSchemaEnvironment  $environment  Driver, server version, and application release
     *         recovery evidence must have been drilled against.
     * @param  AdministratorRenderer      $renderer     Renders the `business-schema-plans` template.
     * @param  ClockInterface             $clock        Supplies now, from which the evidence freshness
     *         floor is measured.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessSchemaService $schemas,
        private BusinessSchemaEnvironment $environment,
        private AdministratorRenderer $renderer,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Render the plans workspace for the plan, evidence, and notice named in the query string.
     *
     * With no `plan` parameter the newest persisted plan is selected, so the screen is useful straight
     * after a redirect from an action. Evidence falls back to whatever the selected plan is already
     * bound to, and the freshness floor is seven days back or the plan's own creation instant, whichever
     * is later, matching the rule the approval path applies. A qualifying flag is still only part of the
     * story: approval additionally demands the recorded clean-drill proofs, which this screen does not
     * re-check.
     *
     * @param   ServerRequestInterface  $request  Administrator GET whose query string may carry `plan`,
     *          `evidence`, and `notice`.
     *
     * @return  ResponseInterface  The rendered workspace, marked `no-store` because it carries a CSRF
     *          token and plan checksums.
     *
     * @throws  \InvalidArgumentException  When the route was mounted without administrator authentication
     *          or authorization, so no session or execution context is attached.
     * @throws  \Kumwe\CMS\Application\Authorization\AuthorizationDenied  When `business.schema.read` is
     *          refused.
     * @throws  \Kumwe\CMS\BusinessSchema\Application\BusinessSchemaNotFound  When the `plan` parameter
     *          names a plan outside this site.
     *
     * @since   2.0.0
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $context = AdministratorRequest::context($request);
        $plans = $this->schemas->plans($context);
        $capabilities = AdministratorRequest::capabilityMap($request);
        $query = $request->getQueryParams();
        $selected = is_string($query['plan'] ?? null) ? trim($query['plan']) : '';
        if ($selected === '' && $plans !== []) {
            $selected = $plans[0]->id;
        }

        $plan = $selected === '' ? null : $this->schemas->plan($context, $selected);
        $steps = $plan === null ? [] : $this->schemas->steps($context, $plan->id);
        $installation = $plan === null
            ? null
            : $this->schemas->installation($context, $plan->definitionId);

        $evidenceId = is_string($query['evidence'] ?? null) ? trim($query['evidence']) : '';
        if ($evidenceId === '' && $plan?->recoveryEvidenceId !== null) {
            $evidenceId = $plan->recoveryEvidenceId;
        }
        $evidence = $evidenceId === '' ? null : $this->schemas->recoveryEvidence($context, $evidenceId);
        $freshnessFloor = $this->clock->now()->sub(new DateInterval('P7D'));
        if ($plan !== null && $plan->createdAt > $freshnessFloor) {
            $freshnessFloor = $plan->createdAt;
        }
        $evidenceQualifies = $plan !== null
            && $evidence !== null
            && $plan->fromSchemaChecksum !== null
            && $evidence->qualifies(
                $context->site()->identifier(),
                $this->environment->databaseDriver(),
                $this->environment->databaseServerVersion(),
                $this->environment->applicationRelease(),
                $plan->fromSchemaChecksum,
                $freshnessFloor,
            );
        $noticeKey = is_string($query['notice'] ?? null) ? $query['notice'] : '';
        $notice = self::NOTICES[$noticeKey] ?? null;

        return new HtmlResponse($this->renderer->render('business-schema-plans', [
            'csrf' => AdministratorRequest::session($request)->csrfToken,
            'capabilities' => $capabilities,
            'plans' => array_map($this->planDocument(...), $plans),
            'plan' => $plan === null ? null : $this->planDocument($plan),
            'steps' => array_map(static fn (SchemaPlanStep $step): array => $step->toArray(), $steps),
            'installation' => $installation?->toArray(),
            'evidence' => $evidence?->toArray(),
            'evidence_qualifies' => $evidenceQualifies,
            'schema_environment' => [
                'database_driver' => $this->environment->databaseDriver(),
                'database_server_version' => $this->environment->databaseServerVersion(),
                'application_release' => $this->environment->applicationRelease(),
            ],
            'definitions' => $this->schemas->definitions($context),
            'notice' => $notice,
        ]), 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Flatten one plan into the document the catalog and detail panels read.
     *
     * The two added flags are derived from the plan's risk band rather than stored on it. The template
     * reads them to decide whether the approval form shows the checksum-confirmation and password
     * fields, and whether the approve button stays disabled until qualifying recovery evidence is
     * selected.
     *
     * @param   SchemaPlan  $plan  Plan to present, whether as a catalog row or the selected detail.
     *
     * @return  array<string, mixed>  The plan's own fields plus `requires_high_impact` and
     *          `requires_recovery_evidence`.
     *
     * @since   2.0.0
     */
    private function planDocument(SchemaPlan $plan): array
    {
        return [
            ...$plan->toArray(),
            'requires_high_impact' => $plan->risk->requiresHighImpactAuthorization(),
            'requires_recovery_evidence' => $plan->risk->requiresRecoveryEvidence(),
        ];
    }
}
