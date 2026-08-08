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

final readonly class BusinessSchemaPlansHandler implements RequestHandlerInterface
{
    private const NOTICES = [
        'planned' => 'The immutable schema plan was persisted. No physical schema work has run.',
        'purge-planned' => 'The destructive purge plan was persisted for independent review and approval.',
        'approved' => 'The exact persisted plan was approved.',
        'executed' => 'The approved schema plan finished execution.',
        'recovered' => 'Recovery finished and the execution journal was reconciled.',
        'evidence-recorded' => 'Tested backup and recovery evidence was recorded for this approval.',
    ];

    public function __construct(
        private BusinessSchemaService $schemas,
        private BusinessSchemaEnvironment $environment,
        private AdministratorRenderer $renderer,
        private ClockInterface $clock,
    ) {
    }

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

    /** @return array<string, mixed> */
    private function planDocument(SchemaPlan $plan): array
    {
        return [
            ...$plan->toArray(),
            'requires_high_impact' => $plan->risk->requiresHighImpactAuthorization(),
            'requires_recovery_evidence' => $plan->risk->requiresRecoveryEvidence(),
        ];
    }
}
