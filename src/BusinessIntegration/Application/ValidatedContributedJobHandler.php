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
    /**
     * Create the validated contributed job handler.
     *
     * @param  JobContributionDefinition  $definition  Signed contribution definition governing the operation.
     * @param  JobHandler                 $handler     Runtime handler bound to the signed contribution.
     * @param  PayloadSchemaValidator     $payloads    Bounded payload-schema validator for contributed data.
     *
     * @since  2.0.0
     */
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

    /**
     * Return the contributed job type implemented by the wrapped handler.
     *
     * @return  string  Signed contributed job type implemented by the wrapped handler.
     *
     * @since   2.0.0
     */
    public function type(): string
    {
        return $this->definition->identifier();
    }

    /**
     * Process the supplied item under its authenticated execution context.
     *
     * @param   array<string, mixed>  $payload  Decoded job payload validated against the signed contribution schema.
     * @param   ExecutionContext      $context  Authenticated execution context for authorization and audit.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function handle(array $payload, ExecutionContext $context): void
    {
        $this->payloads->assertPayload($this->definition->payloadSchema(), $payload);
        $this->handler->handle($payload, $context);
    }
}
