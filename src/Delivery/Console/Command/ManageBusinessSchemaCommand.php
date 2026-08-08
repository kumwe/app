<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Console\Command;

use InvalidArgumentException;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaService;
use Kumwe\CMS\BusinessSchema\Domain\SchemaPlan;
use Kumwe\CMS\BusinessSchema\Domain\SchemaPlanStep;
use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Output;
use Throwable;

/**
 * Drives schema-plan inspection, approval and execution from a host-authorized shell.
 *
 * Every stage names the capability it needs rather than sharing one, because plan,
 * approve, execute, recover and destructive are independently grantable. Approval binds
 * to the plan checksum the operator inspected, so a plan that changed underneath fails
 * instead of being applied.
 */
final readonly class ManageBusinessSchemaCommand implements Command
{
    /** @var array<string, string> */
    private const CAPABILITIES = [
        'definitions' => 'business.schema.read',
        'plans' => 'business.schema.read',
        'get' => 'business.schema.read',
        'plan' => 'business.schema.plan',
        'purge-plan' => 'business.schema.destructive',
        'approve' => 'business.schema.approve',
        'execute' => 'business.schema.execute',
        'recover' => 'business.schema.recover',
    ];

    public function __construct(
        private BusinessSchemaService $schema,
        private ConsoleAuthorizer $authorization,
    ) {
    }

    public function name(): string
    {
        return 'business-schema';
    }

    public function description(): string
    {
        return 'Inspect, approve, execute, and recover business schema plans.';
    }

    public function execute(array $arguments, Output $output): int
    {
        try {
            $action = array_shift($arguments) ?? 'plans';
            $options = CommandInput::options($arguments);
            $capability = self::CAPABILITIES[$action]
                ?? throw new InvalidArgumentException('Unsupported business-schema action.');
            $context = $this->authorization->require($options, $capability);

            $result = match ($action) {
                'definitions' => ['items' => $this->schema->definitions($context)],
                'plans' => ['items' => array_map($this->plan(...), $this->schema->plans($context))],
                'get' => $this->planWithJournal($context, CommandInput::required($options, 'plan')),
                'plan' => $this->plan($this->schema->createPlan(
                    $context,
                    CommandInput::required($options, 'definition'),
                )),
                'purge-plan' => $this->plan($this->schema->createPurgePlan(
                    $context,
                    CommandInput::required($options, 'definition'),
                )),
                'approve' => $this->plan($this->schema->approve(
                    $context,
                    CommandInput::required($options, 'plan'),
                    CommandInput::required($options, 'expected-checksum'),
                    isset($options['confirmation']) ? CommandInput::required($options, 'confirmation') : null,
                    isset($options['evidence']) ? CommandInput::required($options, 'evidence') : null,
                )),
                'execute' => $this->schema->execute(
                    $context,
                    CommandInput::required($options, 'plan'),
                )->toArray(),
                default => $this->schema->recover(
                    $context,
                    CommandInput::required($options, 'plan'),
                )->toArray(),
            };
            $output->line(CommandInput::render($result));

            return 0;
        } catch (Throwable $exception) {
            $output->error($exception->getMessage());

            return 1;
        }
    }

    /** @return array<string, mixed> */
    private function planWithJournal(
        \Kumwe\CMS\Application\Authorization\ExecutionContext $context,
        string $planId,
    ): array {
        return [
            ...$this->plan($this->schema->plan($context, $planId)),
            'steps' => array_map(
                static fn (SchemaPlanStep $step): array => $step->toArray(),
                $this->schema->steps($context, $planId),
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function plan(SchemaPlan $plan): array
    {
        return [...$plan->toArray(), 'checksum' => $plan->checksum()];
    }
}
