<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Http\Handler;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\Administrator\Http\AdministratorRequest;
use Kumwe\CMS\Administrator\Presentation\AdministratorRenderer;
use Kumwe\CMS\Application\Automation\AutomationManagementService;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class AdministratorAutomationHandler implements RequestHandlerInterface
{
    public function __construct(
        private AutomationManagementService $automation,
        private AdministratorRenderer $renderer,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = AdministratorRequest::session($request);

        if (strtoupper($request->getMethod()) === 'POST') {
            $this->mutate(AdministratorRequest::context($request), AdministratorRequest::form($request));

            return new RedirectResponse('/administrator/automation?saved=1', 303);
        }

        $context = AdministratorRequest::context($request);
        return new HtmlResponse($this->renderer->render('automation', [
            'csrf' => $session->csrfToken,
            'capabilities' => AdministratorRequest::capabilityMap($request),
            'schedules' => $this->automation->schedules($context),
            'jobs' => $this->automation->jobs($context, 200),
            'job_types' => $this->automation->jobTypes($context),
            'saved' => ($request->getQueryParams()['saved'] ?? null) === '1',
        ]), 200, ['Cache-Control' => 'no-store']);
    }

    /** @param array<string, string> $form */
    private function mutate(ExecutionContext $context, array $form): void
    {
        $action = AdministratorRequest::required($form, 'action');

        switch ($action) {
            case 'schedule.create':
                $this->automation->createSchedule(
                    $context,
                    AdministratorRequest::required($form, 'name'),
                    AdministratorRequest::required($form, 'cron_expression'),
                    AdministratorRequest::required($form, 'timezone'),
                    AdministratorRequest::required($form, 'job_type'),
                    $this->payload($form),
                    AdministratorRequest::required($form, 'queue'),
                    $this->firstRun(AdministratorRequest::required($form, 'first_run')),
                );
                return;
            case 'schedule.enable':
            case 'schedule.disable':
                $this->automation->setScheduleEnabled(
                    $context,
                    AdministratorRequest::required($form, 'id'),
                    AdministratorRequest::positiveInteger($form, 'version'),
                    $action === 'schedule.enable',
                );
                return;
            case 'schedule.delete':
                $this->automation->deleteSchedule(
                    $context,
                    AdministratorRequest::required($form, 'id'),
                    AdministratorRequest::positiveInteger($form, 'version'),
                );
                return;
            case 'job.retry':
                $this->automation->retryJob($context, AdministratorRequest::required($form, 'id'));
                return;
            case 'job.cancel':
                $this->automation->cancelJob($context, AdministratorRequest::required($form, 'id'));
                return;
            default:
                throw new InvalidArgumentException('The automation action is not supported.');
        }
    }

    /**
     * @param array<string, string> $form
     * @return array<string, mixed>
     */
    private function payload(array $form): array
    {
        try {
            $payload = json_decode($form['payload'] ?? '{}', true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('The job payload must be valid JSON.', 0, $exception);
        }

        if (!is_array($payload) || array_is_list($payload)) {
            throw new InvalidArgumentException('The job payload must be a JSON object.');
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    private function firstRun(string $value): DateTimeImmutable
    {
        $firstRun = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        $invalid = $firstRun === false
            || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0));

        if ($invalid) {
            throw new InvalidArgumentException('The first run must be a valid UTC date and time.');
        }

        return $firstRun;
    }
}
