<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessSurface\Presentation;

use Kumwe\App\BusinessDefinition\Domain\DefinitionOwner;
use Kumwe\App\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\InvalidBusinessDefinition;
use Kumwe\App\BusinessSurface\Application\FieldModelContext;
use Kumwe\App\BusinessSurface\Application\PresentedField;
use Kumwe\Extension\Spi\BusinessSurface\Presentation\Field\FieldPresentationContext;
use Kumwe\Extension\Spi\BusinessSurface\Presentation\Field\FieldPresentationInput;
use Kumwe\Extension\Spi\BusinessSurface\Presentation\Field\FieldPresentationModel;
use Kumwe\Extension\Spi\BusinessSurface\Presentation\Field\FieldPresenter;
use Kumwe\App\BusinessSurface\Presentation\Field\FieldPresentationRegistry;
use Kumwe\Extension\Spi\BusinessSurface\Presentation\Field\FieldWidget;
use Kumwe\App\BusinessSurface\Presentation\Field\RegistryFieldModelPresenter;
use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RegistryFieldModelPresenter::class)]
#[CoversClass(FieldModelContext::class)]
#[CoversClass(PresentedField::class)]
/**
 * Pins the presentation adapter behind the application-owned business-surface rendering contract.
 *
 * The facade names contexts in its own vocabulary and receives a typed view model; this proves the
 * adapter translates each context to the extension-facing SPI value faithfully, forwards errors and
 * editability unchanged, reduces the registered strategy's presentation to exactly the display,
 * provenance and exported model the facade consumes, and keeps the registry's fail-closed coverage.
 *
 * @since  2.0.0
 */
final class RegistryFieldModelPresenterTest extends TestCase
{
    /**
     * Every application context reaches the registered strategy as the same-valued SPI context.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryContextIsTranslatedToTheSameValuedPresentationContext(): void
    {
        foreach (FieldModelContext::cases() as $context) {
            $seen = [];
            $presenter = new RegistryFieldModelPresenter(
                $this->registry($seen),
                $this->createStub(ExtensionExecutionGate::class),
            );

            $presented = $presenter->present($this->field(), $this->type(), $context, 'stored value');

            self::assertCount(1, $seen);
            self::assertSame($context->value, $seen[0]->context->value, $context->value);
            self::assertSame('stored value', $seen[0]->value, $context->value);
            self::assertInstanceOf(PresentedField::class, $presented);
        }
    }

    /**
     * Display, provenance and the exported model reach the caller exactly as the strategy produced them.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testPresentedFieldCarriesDisplayProvenanceAndTheExportedModel(): void
    {
        $seen = [];
        $presenter = new RegistryFieldModelPresenter(
            $this->registry($seen),
            $this->createStub(ExtensionExecutionGate::class),
        );

        $presented = $presenter->present($this->field(), $this->type(), FieldModelContext::Detail, 'stored value');

        self::assertSame('stored value', $presented->display);
        self::assertNull($presented->provenance);
        self::assertSame('code', $presented->model['handle']);
        self::assertSame('detail', $presented->model['context']);
        self::assertSame('output', $presented->model['widget']);
        self::assertSame('stored value', $presented->model['display']);
        self::assertFalse($presented->model['editable']);
    }

    /**
     * Errors and admitted editability are forwarded unchanged into the strategy's validated request.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testErrorsAndEditabilityAreForwardedIntoTheValidatedRequest(): void
    {
        $seen = [];
        $presenter = new RegistryFieldModelPresenter(
            $this->registry($seen),
            $this->createStub(ExtensionExecutionGate::class),
        );

        $presented = $presenter->present(
            $this->field(),
            $this->type(),
            FieldModelContext::Update,
            'submitted',
            errors: ['This value is refused.'],
            editable: true,
        );

        self::assertSame(['This value is refused.'], $seen[0]->errors);
        self::assertTrue($seen[0]->editable);
        self::assertSame(['This value is refused.'], $presented->model['errors']);
        self::assertTrue($presented->model['editable']);
    }

    /**
     * A type and context pair without a registered safe presenter still fails closed through the port.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUncoveredContextFailsClosedThroughThePort(): void
    {
        $presenter = new RegistryFieldModelPresenter(
            new FieldPresentationRegistry(),
            $this->createStub(ExtensionExecutionGate::class),
        );

        $this->expectException(InvalidBusinessDefinition::class);
        $this->expectExceptionMessage('No safe presenter is registered for this field context.');
        $presenter->present($this->field(), $this->type(), FieldModelContext::Detail, 'stored value');
    }

    /**
     * Refuse an extension presenter from a superseded generation before its strategy is called.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStaleGenerationCannotEnterResidentFieldPresenter(): void
    {
        $strategy = $this->createMock(FieldPresenter::class);
        $strategy->expects(self::never())->method('present');
        $registry = new FieldPresentationRegistry();
        $registry->register(
            DefinitionOwner::extension('acme/editor'),
            $this->type()->id,
            [FieldPresentationContext::Detail],
            $strategy,
        );
        $execution = $this->createMock(ExtensionExecutionGate::class);
        $execution->expects(self::once())
            ->method('assertCurrent')
            ->willThrowException(new RuntimeException('stale extension generation'));
        $presenter = new RegistryFieldModelPresenter($registry, $execution);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('stale extension generation');
        $presenter->present($this->field(), $this->type(), FieldModelContext::Detail, 'stored value');
    }

    /**
     * Build a registry whose single strategy echoes each request and records what it received.
     *
     * @param   list<FieldPresentationInput>  $seen  Captured requests, appended in call order.
     *
     * @return  FieldPresentationRegistry  Registry covering every context for the fixture type.
     *
     * @since   2.0.0
     */
    private function registry(array &$seen): FieldPresentationRegistry
    {
        $registry = new FieldPresentationRegistry();
        $registry->register(
            DefinitionOwner::extension('acme/editor'),
            $this->type()->id,
            FieldPresentationContext::cases(),
            new class ($seen) implements FieldPresenter {
                /**
                 * Record the capture buffer the fixture strategy appends to.
                 *
                 * @param  list<FieldPresentationInput>  $seen  Captured requests, appended in call order.
                 *
                 * @since  2.0.0
                 */
                public function __construct(private array &$seen)
                {
                }

                /**
                 * Echo the request back as a minimal valid presentation while recording it.
                 *
                 * @param   FieldPresentationInput  $request  Typed declarative presentation input.
                 *
                 * @return  FieldPresentationModel  Markup-free bounded semantic model.
                 *
                 * @since   2.0.0
                 */
                public function present(FieldPresentationInput $request): FieldPresentationModel
                {
                    $this->seen[] = $request;
                    $editable = $request->permitsEditing();

                    return new FieldPresentationModel(
                        $request->handle,
                        $request->label,
                        $request->context,
                        $editable ? FieldWidget::Text : FieldWidget::Output,
                        is_string($request->value) ? $request->value : '',
                        $editable ? $request->value : null,
                        $editable,
                        $request->required,
                        $request->errors,
                    );
                }
            },
        );

        return $registry;
    }

    /**
     * Build the extension-owned field declaration the fixtures present.
     *
     * @return  FieldDefinition  Field declaration bound to the fixture type.
     *
     * @since   2.0.0
     */
    private function field(): FieldDefinition
    {
        return new FieldDefinition('code', 'Code', $this->type()->id);
    }

    /**
     * Build the extension-owned field type every fixture registers against.
     *
     * @return  FieldTypeDefinition  Immutable logical and storage family.
     *
     * @since   2.0.0
     */
    private function type(): FieldTypeDefinition
    {
        return new FieldTypeDefinition(
            'acme.editor.code',
            'Code',
            'A bounded extension-owned code.',
            'string',
            'string',
        );
    }
}
