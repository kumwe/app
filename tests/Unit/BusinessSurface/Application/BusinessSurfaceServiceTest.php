<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessSurface\Application;

use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Access\AuthorizationDecision;
use Kumwe\Access\DecisionState;
use Kumwe\Access\AuthorizationGateway;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\Transaction\Contract\TransactionManager;
use Kumwe\BusinessDefinition\Application\FieldTypeDefinitionResolver;
use Kumwe\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\BusinessDefinition\Domain\FieldTypeDefinition;
use Kumwe\App\BusinessRecord\Application\BusinessRecordDefinitionResolver;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordNotFound;
use Kumwe\Record\Query\RecordCursor;
use Kumwe\Record\Query\RecordQuerySpecification;
use Kumwe\App\BusinessSecurity\Application\BusinessRecordAccessController;
use Kumwe\App\BusinessSurface\Application\BusinessSurface;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceCatalog;
use Kumwe\App\BusinessSurface\Application\BusinessSurfaceService;
use Kumwe\App\BusinessSurface\Application\FieldModelContext;
use Kumwe\App\BusinessSurface\Application\FieldModelPresenter;
use Kumwe\App\BusinessSurface\Application\PresentedField;
use Kumwe\App\Extension\Runtime\RuntimeMaterializationState;
use Kumwe\App\Tests\Support\AuthorizationContext;
use Kumwe\App\Tests\Support\NativeComputationContainer;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(BusinessSurfaceService::class)]
/**
 * Proves generated-business facade failures and presentation helpers remain safely bounded.
 *
 * @since  2.0.0
 */
final class BusinessSurfaceServiceTest extends TestCase
{
    /**
     * Proves record-addressed reads hide catalog denial while discovery keeps its collection semantics.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testReadNormalizesUnavailableDefinitionWithoutChangingDiscovery(): void
    {
        $service = $this->service($this->emptyCatalog());

        foreach (BusinessSurface::cases() as $surface) {
            $context = $this->context($surface);
            self::assertSame([], $service->discover($context, $surface), $surface->value);

            try {
                $service->read($context, $surface, 'site.default.missing', 'missing-record');
                self::fail(
                    'An unavailable record-addressed definition must be indistinguishable from a missing record.',
                );
            } catch (BusinessRecordNotFound $exception) {
                self::assertSame('business_record.not_found', $exception->stableCode(), $surface->value);
            }
        }
    }

    /**
     * Proves selector labels are normalized to one line and a UTF-8-safe byte limit.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRelationshipChoiceLabelsAreSingleLineAndUtf8ByteBounded(): void
    {
        $choiceText = (new ReflectionClass(BusinessSurfaceService::class))->getMethod('choiceText');

        self::assertSame('Alpha Beta', $choiceText->invoke(null, " Alpha\n\tBeta ", 120));
        $bounded = $choiceText->invoke(null, str_repeat("\u{1F680}", 40), 120);
        self::assertIsString($bounded);
        self::assertLessThanOrEqual(120, strlen($bounded));
        self::assertSame(1, preg_match('//u', $bounded));
    }

    /**
     * Proves default projection narrowing keeps the opaque page-two cursor for final digest validation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCustomViewNarrowingCarriesCursorIntoTheFinalSignedProjection(): void
    {
        $document = NeutralBusinessFixture::document();
        $document['views'][] = [
            'handle' => 'summary',
            'label' => 'Summary',
            'kind' => 'list',
            'fields' => ['name', 'status'],
            'filters' => ['status'],
            'sorts' => ['name'],
            'administrator' => true,
            'portal' => false,
            'public' => false,
            'handler' => 'site.default.views.summary',
            'schema' => 'site.default.schemas.summary',
        ];
        $definition = EntityTypeDefinition::fromArray($document);
        $cursor = RecordCursor::fromString(str_repeat('a', 16) . '.' . str_repeat('b', 16));
        $query = new RecordQuerySpecification(after: $cursor);
        $service = (new ReflectionClass(BusinessSurfaceService::class))->newInstanceWithoutConstructor();
        $method = (new ReflectionClass(BusinessSurfaceService::class))->getMethod('customViewSpecification');
        $narrowed = $method->invoke($service, $query, $definition->toArray(), $definition, 'summary');

        self::assertInstanceOf(RecordQuerySpecification::class, $narrowed);
        self::assertSame($cursor, $narrowed->after);
        self::assertSame(['name', 'status'], $narrowed->projection->fields);
    }

    /**
     * Proves conditional visibility and editability are evaluated fail closed from the values at hand.
     *
     * A field carrying a visibility or editability condition is presented only when the condition reads
     * exactly true against the disclosed values: a false condition hides the field or pins it read-only,
     * and a condition whose dependency the values do not carry counts as false rather than as open. The
     * editability condition is consulted only in a context that accepts input at all.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testConditionalFieldsArePresentedFailClosedFromTheValuesAtHand(): void
    {
        $document = NeutralBusinessFixture::document();
        $document['fields'][] = [
            'handle' => 'conditional_note',
            'label' => 'Conditional note',
            'type' => 'core.text',
            'default' => 'Default note',
            'visibility_condition' => [
                'op' => 'eq',
                'type' => 'boolean',
                'args' => [
                    ['op' => 'field', 'type' => 'boolean', 'field' => 'enabled'],
                    ['op' => 'literal', 'type' => 'boolean', 'value' => true],
                ],
            ],
            'editability_condition' => [
                'op' => 'eq',
                'type' => 'boolean',
                'args' => [
                    ['op' => 'field', 'type' => 'string', 'field' => 'status'],
                    ['op' => 'literal', 'type' => 'string', 'value' => 'ready'],
                ],
            ],
        ];
        $definition = EntityTypeDefinition::fromArray($document);
        $metadata = ['fields' => [self::fieldMetadata('name', 1), self::fieldMetadata('conditional_note', 2)]];
        $service = $this->presentingService();
        $present = (new ReflectionClass(BusinessSurfaceService::class))->getMethod('present');
        $presented = static fn (FieldModelContext $context, array $values): array => array_map(
            static fn (array $field): array => [$field['handle'], $field['editable']],
            $present->invoke($service, $definition, $metadata, $context, $values),
        );

        self::assertSame(
            [['name', true], ['conditional_note', true]],
            $presented(FieldModelContext::Update, ['enabled' => true, 'status' => 'ready']),
            'Both conditions read true, so the field is shown and open for input.',
        );
        self::assertSame(
            [['name', true], ['conditional_note', false]],
            $presented(FieldModelContext::Update, ['enabled' => true, 'status' => 'draft']),
            'A false editability condition pins the visible field read-only.',
        );
        self::assertSame(
            [['name', true]],
            $presented(FieldModelContext::Update, ['enabled' => false, 'status' => 'ready']),
            'A false visibility condition hides the field entirely.',
        );
        self::assertSame(
            [['name', true]],
            $presented(FieldModelContext::Update, ['status' => 'ready']),
            'A visibility dependency the values do not carry fails closed to hidden.',
        );
        self::assertSame(
            [['name', true], ['conditional_note', false]],
            $presented(FieldModelContext::Update, ['enabled' => true]),
            'An editability dependency the values do not carry fails closed to read-only.',
        );
        self::assertSame(
            [['name', false], ['conditional_note', false]],
            $presented(FieldModelContext::Detail, ['enabled' => true, 'status' => 'ready']),
            'A context that accepts no input never consults the editability condition.',
        );
    }

    /**
     * Build a catalog that authorizes reads but contains no matching definition.
     *
     * @return  BusinessSurfaceCatalog  Executable empty catalog fixture.
     *
     * @since   2.0.0
     */
    private function emptyCatalog(): BusinessSurfaceCatalog
    {
        $definitions = $this->createStub(BusinessRecordDefinitionResolver::class);
        $definitions->method('activeInstalled')->willReturn([]);
        $authorization = $this->createStub(AuthorizationGateway::class);
        $authorization->method('decide')->willReturn(
            new AuthorizationDecision(DecisionState::Allow, 'test', 'allowed'),
        );
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );

        return new BusinessSurfaceCatalog(
            $definitions,
            $this->createStub(BusinessRecordAccessController::class),
            $this->createStub(FieldTypeDefinitionResolver::class),
            $authorization,
            $transactions,
            RuntimeMaterializationState::unavailable('business-surface-service-test'),
        );
    }

    /**
     * Build a facade whose unreachable collaborators remain intentionally uninitialized.
     *
     * @param   BusinessSurfaceCatalog  $catalog  Exact catalog exercised by the read boundary.
     *
     * @return  BusinessSurfaceService  Reflection-backed focused fixture.
     *
     * @since   2.0.0
     */
    private function service(BusinessSurfaceCatalog $catalog): BusinessSurfaceService
    {
        $reflection = new ReflectionClass(BusinessSurfaceService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('catalog')->setValue($service, $catalog);

        return $service;
    }

    /**
     * Build a facade whose presenter echoes the handle and editability the facade settled.
     *
     * The conditions the facade judges run through the native formula port, so this fixture is bound to the
     * admitted engine like every other test that pins a condition verdict.
     *
     * @return  BusinessSurfaceService  Reflection-backed fixture with only the presentation collaborators.
     *
     * @since   2.0.0
     */
    private function presentingService(): BusinessSurfaceService
    {
        $presenter = new class implements FieldModelPresenter {
            /**
             * Echo the handle and the editability the facade decided, which is all the proof reads back.
             *
             * @param   FieldDefinition      $field     Field declaration being presented.
             * @param   FieldTypeDefinition  $type      Resolved field type.
             * @param   FieldModelContext    $context   Render or edit context.
             * @param   mixed                $value     Disclosed or retained value.
             * @param   list<string>         $errors    Caller-visible validation messages.
             * @param   bool                 $editable  Whether policy and conditions admit input now.
             *
             * @return  PresentedField  Presentation carrying the handle and editability only.
             *
             * @since   2.0.0
             */
            public function present(
                FieldDefinition $field,
                FieldTypeDefinition $type,
                FieldModelContext $context,
                mixed $value,
                array $errors = [],
                bool $editable = false,
            ): PresentedField {
                return new PresentedField(
                    is_string($value) ? $value : '',
                    null,
                    ['handle' => $field->handle, 'editable' => $editable],
                );
            }
        };
        $fieldTypes = $this->createStub(FieldTypeDefinitionResolver::class);
        $fieldTypes->method('get')->willReturn(
            new FieldTypeDefinition('core.text', 'Text', 'Bounded text.', 'string', 'string'),
        );
        $reflection = new ReflectionClass(BusinessSurfaceService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('presentations')->setValue($service, $presenter);
        $reflection->getProperty('fieldTypes')->setValue($service, $fieldTypes);
        $reflection->getProperty('formulas')->setValue($service, NativeComputationContainer::formulas());

        return $service;
    }

    /**
     * Build the policy-filtered metadata document one presented field is allowed under.
     *
     * @param   string  $handle  Field handle the metadata admits.
     * @param   int     $order   Form order the presentation sorts by.
     *
     * @return  array<string, mixed>  Field metadata in the shape the catalog generates.
     *
     * @since   2.0.0
     */
    private static function fieldMetadata(string $handle, int $order): array
    {
        return [
            'handle' => $handle,
            'description' => '',
            'help_text' => '',
            'type' => 'core.text',
            'schema' => ['type' => 'string'],
            'form_group' => 'general',
            'order' => $order,
            'placements' => ['form', 'detail'],
        ];
    }

    /**
     * Mint one provenance-matched context with generated read authority.
     *
     * @param   BusinessSurface  $surface  Generated adapter under test.
     *
     * @return  ExecutionContext  Authenticated caller context.
     *
     * @since   2.0.0
     */
    private function context(BusinessSurface $surface): ExecutionContext
    {
        $authenticated = match ($surface) {
            BusinessSurface::Administrator => AuthenticatedSurface::Administrator,
            BusinessSurface::Portal => AuthenticatedSurface::Portal,
            BusinessSurface::Api => AuthenticatedSurface::Api,
            BusinessSurface::Cli => AuthenticatedSurface::Cli,
            BusinessSurface::Mcp => AuthenticatedSurface::Mcp,
        };

        return AuthorizationContext::principal(['business.record.read'])->context(
            SiteContext::default(),
            AuthenticationStrength::BearerToken,
            'business-surface-read-normalization-test',
            surface: $authenticated,
        );
    }
}
