<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Delivery\Console\Command;

use Kumwe\CMS\Delivery\Console\Command\ManageBusinessDefinitionsCommand;
use Kumwe\CMS\Delivery\Console\Command\ManageBusinessSchemaCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The CLI surface's own contract: naming, capability separation, and failure behaviour.
 *
 * Operations themselves are proved end to end against a real database elsewhere; what
 * matters here is that each schema stage demands its own capability, because sharing one
 * would silently widen a grant an operator believed was narrow.
 */
#[CoversClass(ManageBusinessDefinitionsCommand::class)]
#[CoversClass(ManageBusinessSchemaCommand::class)]
final class BusinessCommandTest extends TestCase
{
    public function testEverySchemaStageDemandsItsOwnCapability(): void
    {
        $capabilities = $this->schemaCapabilities();

        self::assertSame([
            'definitions' => 'business.schema.read',
            'plans' => 'business.schema.read',
            'get' => 'business.schema.read',
            'plan' => 'business.schema.plan',
            'purge-plan' => 'business.schema.destructive',
            'approve' => 'business.schema.approve',
            'execute' => 'business.schema.execute',
            'recover' => 'business.schema.recover',
        ], $capabilities);
    }

    public function testMutatingSchemaStagesNeverShareAReadCapability(): void
    {
        $capabilities = $this->schemaCapabilities();

        foreach (['plan', 'purge-plan', 'approve', 'execute', 'recover'] as $stage) {
            self::assertNotSame(
                'business.schema.read',
                $capabilities[$stage] ?? null,
                sprintf('The %s stage must not be reachable with only read authority.', $stage),
            );
        }
    }

    public function testCommandsAreNamedForTheirBoundedContext(): void
    {
        $definitions = (new ReflectionClass(ManageBusinessDefinitionsCommand::class))
            ->newInstanceWithoutConstructor();
        $schema = (new ReflectionClass(ManageBusinessSchemaCommand::class))->newInstanceWithoutConstructor();

        self::assertSame('business-definition', $definitions->name());
        self::assertSame('business-schema', $schema->name());
        self::assertNotSame('', trim($definitions->description()));
        self::assertNotSame('', trim($schema->description()));
    }

    public function testDefinitionReadsAndMutationsAreSeparatelyGranted(): void
    {
        $reads = (new ReflectionClass(ManageBusinessDefinitionsCommand::class))->getConstant('READ_ACTIONS');
        self::assertIsArray($reads);
        self::assertSame(['list', 'get', 'draft', 'history', 'compatibility'], $reads);

        // Anything not named here takes content.update, so a new action cannot silently
        // become reachable with read-only authority.
        foreach (['import', 'validate', 'publish', 'supersede', 'deprecate', 'reject'] as $mutation) {
            self::assertNotContains($mutation, $reads);
        }
    }

    /** @return array<string, string> */
    private function schemaCapabilities(): array
    {
        $constant = (new ReflectionClass(ManageBusinessSchemaCommand::class))->getConstant('CAPABILITIES');
        self::assertIsArray($constant);

        /** @var array<string, string> $constant */
        return $constant;
    }
}
