<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessIntegration\Application;

use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Application\Automation\JobHandler;
use Kumwe\CMS\BusinessIntegration\Domain\JobContributionDefinition;
use LogicException;

/**
 * Enforces a signed payload contract before invoking one active extension job implementation.
 *
 * @since  2.0.0
 */
final readonly class ValidatedContributedJobHandler implements JobHandler
{
    /** @since 2.0.0 */
    public function __construct(
        private JobContributionDefinition $definition,
        private JobHandler $handler,
        private PayloadSchemaValidator $payloads = new PayloadSchemaValidator(),
    ) {
        if ($handler->type() !== $definition->identifier()) {
            throw new LogicException('A contributed job handler contradicts its trusted declaration.');
        }
        $this->payloads->assertSchema($definition->payloadSchema());
    }

    /** @inheritDoc */
    public function type(): string
    {
        return $this->definition->identifier();
    }

    /** @inheritDoc */
    public function handle(array $payload, ExecutionContext $context): void
    {
        $this->payloads->assertPayload($this->definition->payloadSchema(), $payload);
        $this->handler->handle($payload, $context);
    }
}
