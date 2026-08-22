<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\BusinessRecord\Application;

use DateTimeImmutable;
use Kumwe\App\Application\Authorization\ExecutionContext;
use Kumwe\App\Application\Authorization\SiteContext;
use Kumwe\App\Application\Authorization\SystemIdentity;
use Kumwe\App\Application\Automation\IdempotencyKey;
use Kumwe\App\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\App\BusinessDefinition\Domain\FieldDefinition;
use Kumwe\App\BusinessDefinition\Domain\PortalOperation;
use Kumwe\App\BusinessDefinition\Domain\RelationshipKind;
use Kumwe\App\BusinessDefinition\Domain\ScopeMode;
use Kumwe\App\BusinessRecord\Application\BusinessRecordDefinitionResolver;
use Kumwe\App\BusinessRecord\Application\BusinessRecordMutationFence;
use Kumwe\App\BusinessRecord\Application\BusinessRecordMutationGeneration;
use Kumwe\App\BusinessRecord\Application\BusinessRecordReadRepository;
use Kumwe\App\BusinessRecord\Application\BusinessRecordRelationshipCoordinator;
use Kumwe\App\BusinessRecord\Application\Command\DocumentLineInput;
use Kumwe\App\BusinessRecord\Application\Command\DocumentWriteIntent;
use Kumwe\App\BusinessRecord\Application\Command\RelateRecordsCommand;
use Kumwe\App\BusinessRecord\Application\Command\WriteDocumentCommand;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordNotFound;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordReferenceConflict;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRelationshipRejected;
use Kumwe\App\BusinessRecord\Application\OwnedLineCreateIntent;
use Kumwe\App\BusinessRecord\Application\OwnedLineMutationIntent;
use Kumwe\App\BusinessRecord\Application\OwnedLineWrite;
use Kumwe\App\BusinessRecord\Application\RecordRuleValidator;
use Kumwe\App\BusinessRecord\Application\RecordValueCodec;
use Kumwe\App\BusinessRecord\Application\ResolvedBusinessDefinition;
use Kumwe\App\BusinessRecord\Application\StoredOwnedLine;
use Kumwe\App\BusinessRecord\Application\StoredRecordIdentity;
use Kumwe\App\BusinessRecord\Domain\BusinessRecord;
use Kumwe\App\BusinessRecord\Domain\ExactDecimal;
use Kumwe\App\BusinessRecord\Domain\RecordScope;
use Kumwe\App\BusinessRecord\Infrastructure\Security\SodiumSecretCipher;
use Kumwe\App\BusinessSchema\Domain\PhysicalColumnBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalSchemaBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalTableBlueprint;
use Kumwe\App\BusinessSchema\Domain\PhysicalTableKind;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallation;
use Kumwe\App\BusinessSchema\Domain\SchemaInstallationStatus;
use Kumwe\App\BusinessSecurity\Application\BusinessRecordAccessPlan;
use Kumwe\App\BusinessSecurity\Application\FieldAccessUsage;
use Kumwe\App\BusinessSecurity\Application\FieldDisclosurePlan;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicyConstant;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicySchema;
use Kumwe\App\BusinessSecurity\Policy\RecordPolicySet;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Characterizes the relationship decisions extracted from the stable business-record facade.
 *
 * These cases pin both public single-line behavior and the whole-document intent used by the aggregate
 * command: identity normalization, nested field and row policy, dense order, unchanged rows, removals,
 * renumbering, scope refusal and the exact pinned line generation all remain decided before persistence.
 *
 * @since  2.0.0
 */
#[CoversClass(BusinessRecordRelationshipCoordinator::class)]
#[CoversClass(OwnedLineCreateIntent::class)]
#[CoversClass(OwnedLineMutationIntent::class)]
final class BusinessRecordRelationshipCoordinatorTest extends TestCase
{
    /**
     * Stable published header definition identity used throughout the characterization.
     *
     * @var    string
     * @since  2.0.0
     */
    private const HEADER_ID = '0191574f-f0b8-7bf3-a9aa-91c6b8245a10';

    /**
     * Stable published line definition identity used throughout the characterization.
     *
     * @var    string
     * @since  2.0.0
     */
    private const LINE_ID = '0191574f-f0b8-7bf3-a9aa-91c6b8245a11';

    /**
     * Stable document identity used by amendment cases.
     *
     * @var    string
     * @since  2.0.0
     */
    private const DOCUMENT_ID = '0191574f-f0b8-7bf3-a9aa-91c6b8245a12';

    /**
     * First stable owned-line identity.
     *
     * @var    string
     * @since  2.0.0
     */
    private const LINE_A = '0191574f-f0b8-7bf3-a9aa-91c6b8245a13';

    /**
     * Second stable owned-line identity.
     *
     * @var    string
     * @since  2.0.0
     */
    private const LINE_B = '0191574f-f0b8-7bf3-a9aa-91c6b8245a14';

    /**
     * Third stable owned-line identity.
     *
     * @var    string
     * @since  2.0.0
     */
    private const LINE_C = '0191574f-f0b8-7bf3-a9aa-91c6b8245a15';

    /**
     * A create becomes one typed, dense intent before any repository write can run.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDocumentCreateProducesOneTypedDenseIntent(): void
    {
        [$owner, $line] = $this->resolvedDefinitions();
        $reads = $this->createMock(BusinessRecordReadRepository::class);
        $reads->expects(self::never())->method('ownedLinesForDocumentIntegrity');
        $coordinator = $this->coordinator($reads, $line);

        $intent = $coordinator->prepareDocumentMutation(
            $this->documentCommand([
                new DocumentLineInput($this->submittedLine('A', 'Alpha', '1.25'), self::LINE_A),
                new DocumentLineInput($this->submittedLine('B', 'Beta', '2.50'), self::LINE_B),
            ]),
            $owner,
            $this->scope(),
            null,
            $this->ownerAccess($owner->definition, $line->definition),
        );

        self::assertSame(RelationshipKind::OwnedLineCollection, $intent->relationship->kind);
        self::assertSame($line, $intent->line);
        self::assertSame([], $intent->removed);
        self::assertFalse($intent->renumber);
        self::assertSame([0, 1], array_map(
            static fn (OwnedLineWrite $write): int => $write->position,
            $intent->writes,
        ));
        self::assertSame(
            [self::LINE_A, self::LINE_B],
            array_map(static fn (OwnedLineWrite $write): string => $write->recordKey, $intent->writes),
        );
        self::assertSame([null, null], array_map(
            static fn (OwnedLineWrite $write): ?int => $write->storedVersion,
            $intent->writes,
        ));
        self::assertSame(
            [['id' => self::LINE_A, 'code' => 'A', 'description' => 'Alpha', 'amount' => '1.25'],
                ['id' => self::LINE_B, 'code' => 'B', 'description' => 'Beta', 'amount' => '2.50']],
            $intent->invariantValues(),
        );
    }

    /**
     * An unchanged survivor stays unwritten while an omitted stored line becomes an explicit removal.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDocumentAmendPreservesAnUnchangedLineAndRemovesTheOmittedLine(): void
    {
        [$owner, $line] = $this->resolvedDefinitions();
        $stored = [
            $this->storedLine(self::LINE_A, 0, 'A', 'Alpha', '1.25'),
            $this->storedLine(self::LINE_B, 1, 'B', 'Beta', '2.50'),
        ];
        $reads = $this->createMock(BusinessRecordReadRepository::class);
        $reads->expects(self::once())->method('ownedLinesForDocumentIntegrity')->willReturn($stored);

        $intent = $this->coordinator($reads, $line)->prepareDocumentMutation(
            $this->documentCommand(
                [new DocumentLineInput([], self::LINE_A)],
                DocumentWriteIntent::Amend,
            ),
            $owner,
            $this->scope(),
            $this->record($owner->definition, self::DOCUMENT_ID),
            $this->ownerAccess($owner->definition, $line->definition),
        );

        self::assertFalse($intent->renumber);
        self::assertSame([self::LINE_B], $intent->removed);
        self::assertCount(1, $intent->writes);
        self::assertFalse($intent->writes[0]->modified);
        self::assertSame(3, $intent->writes[0]->storedVersion);
    }

    /**
     * Moving one survivor requests deterministic two-pass renumbering for every retained existing row.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDocumentAmendTurnsAReorderIntoAnExplicitRenumberIntent(): void
    {
        [$owner, $line] = $this->resolvedDefinitions();
        $reads = $this->createMock(BusinessRecordReadRepository::class);
        $reads->method('ownedLinesForDocumentIntegrity')->willReturn([
            $this->storedLine(self::LINE_A, 0, 'A', 'Alpha', '1.25'),
            $this->storedLine(self::LINE_B, 1, 'B', 'Beta', '2.50'),
        ]);

        $intent = $this->coordinator($reads, $line)->prepareDocumentMutation(
            $this->documentCommand([
                new DocumentLineInput([], self::LINE_B),
                new DocumentLineInput([], self::LINE_A),
            ], DocumentWriteIntent::Amend),
            $owner,
            $this->scope(),
            $this->record($owner->definition, self::DOCUMENT_ID),
            $this->ownerAccess($owner->definition, $line->definition),
        );

        self::assertTrue($intent->renumber);
        self::assertSame([self::LINE_B, self::LINE_A], array_map(
            static fn (OwnedLineWrite $write): string => $write->recordId,
            $intent->writes,
        ));
        self::assertSame([0, 1], array_map(
            static fn (OwnedLineWrite $write): int => $write->position,
            $intent->writes,
        ));
        self::assertSame([true, true], array_map(
            static fn (OwnedLineWrite $write): bool => $write->modified,
            $intent->writes,
        ));
    }

    /**
     * A hidden stored line refuses a whole replacement instead of being deleted from a filtered view.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testDocumentAmendRefusesWhenNestedPolicyHidesAStoredLine(): void
    {
        [$owner, $line] = $this->resolvedDefinitions();
        $reads = $this->createMock(BusinessRecordReadRepository::class);
        $reads->method('ownedLinesForDocumentIntegrity')->willReturn([
            $this->storedLine(self::LINE_A, 0, 'A', 'Alpha', '1.25'),
        ]);

        $this->expectException(BusinessRecordNotFound::class);
        $this->coordinator($reads, $line)->prepareDocumentMutation(
            $this->documentCommand([], DocumentWriteIntent::Amend),
            $owner,
            $this->scope(),
            $this->record($owner->definition, self::DOCUMENT_ID),
            $this->ownerAccess($owner->definition, $line->definition, false),
        );
    }

    /**
     * The existing single-line relate behavior produces the same pinned and normalized line decision.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSingleLineRelateProducesAPinnedOwnedLineIntent(): void
    {
        [$owner, $line] = $this->resolvedDefinitions();
        $reads = $this->createMock(BusinessRecordReadRepository::class);
        $access = $this->ownerAccess($owner->definition, $line->definition)->related('lines');
        self::assertNotNull($access);
        $command = new RelateRecordsCommand(
            self::context(),
            $owner->definition->handle,
            self::DOCUMENT_ID,
            1,
            'lines',
            self::LINE_C,
            IdempotencyKey::fromString('relationship-coordinator-relate'),
            targetValues: $this->submittedLine('C', 'Gamma', '3.75'),
        );

        $intent = $this->coordinator($reads, $line)->prepareOwnedLineCreate(
            $command,
            $owner,
            $this->scope(),
            $owner->definition->runtimeRelationship('lines')
                ?? throw new BusinessRelationshipRejected('The fixture relationship is missing.'),
            $access,
        );

        self::assertSame($line, $intent->line);
        self::assertSame(self::LINE_C, $intent->recordKey);
        self::assertSame(self::LINE_C, $intent->recordId);
        $amount = $intent->values['amount'] ?? null;
        self::assertInstanceOf(ExactDecimal::class, $amount);
        self::assertSame('3.75', $amount->value());
    }

    /**
     * Detach and reorder resolve an owned line only inside the named owner and nested policy.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOwnedLineIdentityBecomesTheStorageKeyForSingleLineMutation(): void
    {
        [$owner, $line] = $this->resolvedDefinitions();
        $reads = $this->createMock(BusinessRecordReadRepository::class);
        $reads->expects(self::exactly(3))->method('ownedLineIdentity')->willReturnOnConsecutiveCalls(
            new StoredRecordIdentity(self::LINE_B, self::LINE_B, 1, 3),
            new StoredRecordIdentity(self::LINE_A, self::LINE_A, 1, 3),
            new StoredRecordIdentity(self::LINE_B, self::LINE_B, 1, 3),
        );
        $access = $this->ownerAccess($owner->definition, $line->definition)->related('lines');
        self::assertNotNull($access);

        $key = $this->coordinator($reads, $line)->ownedLineKey(
            self::context(),
            $owner,
            $this->record($owner->definition, self::DOCUMENT_ID),
            $owner->definition->runtimeRelationship('lines')
                ?? throw new BusinessRelationshipRejected('The fixture relationship is missing.'),
            $access,
            self::LINE_B,
            PortalOperation::Reorder,
        );

        self::assertSame(self::LINE_B, $key);
        self::assertSame([self::LINE_A, self::LINE_B], $this->coordinator($reads, $line)->ownedLineKeys(
            self::context(),
            $owner,
            $this->record($owner->definition, self::DOCUMENT_ID),
            $owner->definition->runtimeRelationship('lines')
                ?? throw new BusinessRelationshipRejected('The fixture relationship is missing.'),
            $access,
            [self::LINE_A, self::LINE_B],
            PortalOperation::Reorder,
        ));
    }

    /**
     * An ordinary relationship target must pass its nested row decision and occupy the source scope.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExistingTargetValidationPreservesNestedPolicyAndScopeIsolation(): void
    {
        [$owner, $line] = $this->resolvedDefinitions();
        $coordinator = $this->coordinator($this->createStub(BusinessRecordReadRepository::class), $line);
        $source = $this->record($owner->definition, self::DOCUMENT_ID);
        $target = $this->record($line->definition, self::LINE_A);

        $coordinator->assertExistingTarget(
            self::context(),
            $source,
            $line->definition,
            $target,
            $this->lineAccess($line->definition),
            PortalOperation::Relation,
        );
        self::assertTrue(true);

        $otherSite = $this->record(
            $line->definition,
            self::LINE_B,
            RecordScope::forDefinition(ScopeMode::Site, SiteContext::fromString('other'), null),
        );
        $this->expectException(BusinessRecordReferenceConflict::class);
        $coordinator->assertExistingTarget(
            self::context(),
            $source,
            $line->definition,
            $otherSite,
            $this->lineAccess($line->definition),
            PortalOperation::Relation,
        );
    }

    /**
     * Definition lookup and normalized-key refusal remain stable at the extracted boundary.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRelationshipLookupAndDuplicateNormalizedKeysRefuseByName(): void
    {
        [$owner, $line] = $this->resolvedDefinitions();
        $coordinator = $this->coordinator($this->createStub(BusinessRecordReadRepository::class), $line);

        self::assertSame(
            RelationshipKind::OwnedLineCollection,
            $coordinator->relationship($owner->definition, 'lines')->kind,
        );
        self::assertSame(
            [$line->definition->handle, $coordinator->relationship($owner->definition, 'lines')],
            $coordinator->relatedTarget($owner->definition, 'lines'),
        );
        self::assertTrue($coordinator->inputFieldAvailable(
            $this->field($line->definition, 'code'),
            FieldAccessUsage::Create,
        ));
        self::assertFalse($coordinator->inputFieldAvailable(
            $this->field($line->definition, 'id'),
            FieldAccessUsage::Create,
        ));

        $this->expectException(BusinessRelationshipRejected::class);
        $coordinator->assertUniqueTargetKeys([self::LINE_A, self::LINE_A]);
    }

    /**
     * Build the published header and line definitions with real, mutually pinned installation blueprints.
     *
     * @return  array{ResolvedBusinessDefinition, ResolvedBusinessDefinition}  Header then line definition.
     *
     * @since   2.0.0
     */
    private function resolvedDefinitions(): array
    {
        $line = EntityTypeDefinition::fromArray(NeutralBusinessFixture::documentLineDocument(
            'coord',
            self::LINE_ID,
        ))->published(1);
        $header = EntityTypeDefinition::fromArray(NeutralBusinessFixture::documentHeaderDocument(
            'coord',
            self::HEADER_ID,
            $line->handle,
            withAggregateInvariants: false,
        ))->published(1);

        return [
            $this->resolved($header, $line->definitionVersion),
            $this->resolved($line),
        ];
    }

    /**
     * Pair one definition with the minimum real physical schema this coordinator reads.
     *
     * @param   EntityTypeDefinition  $definition     Published definition to install.
     * @param   ?int                  $lineVersion    Pinned target version for an owner line table.
     *
     * @return  ResolvedBusinessDefinition  Valid definition and active installation pair.
     *
     * @since   2.0.0
     */
    private function resolved(
        EntityTypeDefinition $definition,
        ?int $lineVersion = null,
    ): ResolvedBusinessDefinition {
        $column = new PhysicalColumnBlueprint('record_key', 'c_record_key_coord', 'guid');
        $table = new PhysicalTableBlueprint(
            $lineVersion === null ? 'record' : 'line:lines',
            $lineVersion === null ? 'kb_coord_record' : 'kb_coord_lines',
            $lineVersion === null ? PhysicalTableKind::Entity : PhysicalTableKind::OwnedLine,
            [$column],
            [$column->physicalName],
            options: $lineVersion === null ? [] : ['target_definition_version' => $lineVersion],
        );
        $blueprint = new PhysicalSchemaBlueprint(
            $definition->id,
            $definition->definitionVersion,
            $definition->checksum(),
            [$table],
        );
        $now = new DateTimeImmutable('2026-08-22T10:00:00+00:00');
        $installation = new SchemaInstallation(
            $definition->id,
            $definition->siteIdentifier,
            'core',
            $definition->definitionVersion,
            $definition->checksum(),
            $blueprint->checksum(),
            $blueprint,
            SchemaInstallationStatus::Active,
            $now,
            $now,
        );

        return new ResolvedBusinessDefinition($definition, $installation);
    }

    /**
     * Build the extracted coordinator over mocks while keeping value normalization real.
     *
     * @param   BusinessRecordReadRepository  $reads  Read behavior selected by the current case.
     * @param   ResolvedBusinessDefinition    $line   Pinned line definition every target lookup returns.
     *
     * @return  BusinessRecordRelationshipCoordinator  Independently testable relationship seam.
     *
     * @since   2.0.0
     */
    private function coordinator(
        BusinessRecordReadRepository $reads,
        ResolvedBusinessDefinition $line,
    ): BusinessRecordRelationshipCoordinator {
        $fence = $this->createStub(BusinessRecordMutationFence::class);
        $fence->method('lock')->willReturn($this->generation($line));
        $definitions = $this->createStub(BusinessRecordDefinitionResolver::class);
        $definitions->method('pinned')->willReturn($line);
        $definitions->method('forCreate')->willReturn($line);
        $codec = new RecordValueCodec(new SodiumSecretCipher(
            'relationship-coordinator-key-v1',
            str_repeat("\x21", SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES),
        ));

        return new BusinessRecordRelationshipCoordinator(
            $reads,
            $fence,
            $definitions,
            $codec,
            new RecordRuleValidator($codec),
        );
    }

    /**
     * Rebuild the exact active generation held by the line-definition fence.
     *
     * @param   ResolvedBusinessDefinition  $line  Definition and installation observed under the lock.
     *
     * @return  BusinessRecordMutationGeneration  Generation matching every installation coordinate.
     *
     * @since   2.0.0
     */
    private function generation(ResolvedBusinessDefinition $line): BusinessRecordMutationGeneration
    {
        $installation = $line->installation;

        return new BusinessRecordMutationGeneration(
            $installation->definitionId,
            $installation->siteIdentifier,
            $installation->ownerIdentifier,
            $installation->definitionVersion,
            $installation->definitionChecksum,
            $installation->schemaChecksum,
            $installation->status,
        );
    }

    /**
     * Build a header plan carrying the exact nested line plan used by relationship writes.
     *
     * @param   EntityTypeDefinition  $owner      Header definition protected by the outer plan.
     * @param   EntityTypeDefinition  $line       Line definition protected by the nested plan.
     * @param   bool                  $allowRows  Whether line row policy admits the collection.
     *
     * @return  BusinessRecordAccessPlan  Header plan with one `lines` child.
     *
     * @since   2.0.0
     */
    private function ownerAccess(
        EntityTypeDefinition $owner,
        EntityTypeDefinition $line,
        bool $allowRows = true,
    ): BusinessRecordAccessPlan {
        return new BusinessRecordAccessPlan(
            $owner->id,
            'business.record.relate',
            $this->rowPolicy(true),
            new FieldDisclosurePlan(),
            hash('sha256', 'relationship-owner-access'),
            ['lines' => $this->lineAccess($line, $allowRows)],
        );
    }

    /**
     * Build one nested line plan with selectable row visibility and full writable fields.
     *
     * @param   EntityTypeDefinition  $line       Line definition protected by the plan.
     * @param   bool                  $allowRows  Whether the line row predicate admits values.
     *
     * @return  BusinessRecordAccessPlan  Exact line target plan.
     *
     * @since   2.0.0
     */
    private function lineAccess(
        EntityTypeDefinition $line,
        bool $allowRows = true,
    ): BusinessRecordAccessPlan {
        return new BusinessRecordAccessPlan(
            $line->id,
            'business.record.relate',
            $this->rowPolicy($allowRows),
            new FieldDisclosurePlan([
                'create' => ['code', 'description', 'amount'],
                'update' => ['code', 'description', 'amount'],
                'public_reference' => ['id'],
            ]),
            hash('sha256', 'relationship-line-access-' . ($allowRows ? 'allow' : 'deny')),
        );
    }

    /**
     * Build a constant row policy for a characterization case.
     *
     * @param   bool  $allowed  Truth value returned for every row.
     *
     * @return  RecordPolicySet  One explicit allow predicate and no denies.
     *
     * @since   2.0.0
     */
    private function rowPolicy(bool $allowed): RecordPolicySet
    {
        return new RecordPolicySet(
            new RecordPolicySchema([]),
            [new RecordPolicyConstant($allowed)],
        );
    }

    /**
     * Build a whole-document command for a create or amendment case.
     *
     * @param   list<DocumentLineInput>  $lines   Final submitted line collection.
     * @param   DocumentWriteIntent      $intent  Create or amend operation under test.
     *
     * @return  WriteDocumentCommand  Validated command carrying stable identities.
     *
     * @since   2.0.0
     */
    private function documentCommand(
        array $lines,
        DocumentWriteIntent $intent = DocumentWriteIntent::Create,
    ): WriteDocumentCommand {
        return new WriteDocumentCommand(
            self::context(),
            'site.default.doc_header_coord',
            'lines',
            [],
            $lines,
            IdempotencyKey::fromString('relationship-coordinator-document-' . $intent->value),
            $intent,
            $intent === DocumentWriteIntent::Amend ? 1 : null,
            $intent === DocumentWriteIntent::Amend ? self::DOCUMENT_ID : null,
        );
    }

    /**
     * Build normalized stored values for one line.
     *
     * @param   string  $id           Stable line identity.
     * @param   string  $code         Unique line code.
     * @param   string  $description  Human-readable line description.
     * @param   string  $amount       Exact two-decimal amount.
     *
     * @return  array<string, mixed>  Values in the shape a read repository returns.
     *
     * @since   2.0.0
     */
    private function storedValues(string $id, string $code, string $description, string $amount): array
    {
        return [
            'id' => $id,
            'code' => $code,
            'description' => $description,
            'amount' => ExactDecimal::fromString($amount, 18, 2),
        ];
    }

    /**
     * Build one stored owned line for document diff characterization.
     *
     * @param   string  $id           Stable line identity and storage key.
     * @param   int     $position     Current dense position.
     * @param   string  $code         Unique line code.
     * @param   string  $description  Human-readable line description.
     * @param   string  $amount       Exact two-decimal amount.
     *
     * @return  StoredOwnedLine  Stored line at optimistic version three.
     *
     * @since   2.0.0
     */
    private function storedLine(
        string $id,
        int $position,
        string $code,
        string $description,
        string $amount,
    ): StoredOwnedLine {
        return new StoredOwnedLine(
            $id,
            $id,
            $position,
            3,
            $this->storedValues($id, $code, $description, $amount),
        );
    }

    /**
     * Build caller-submitted values for one new line.
     *
     * @param   string  $code         Unique line code.
     * @param   string  $description  Human-readable line description.
     * @param   string  $amount       Exact two-decimal amount.
     *
     * @return  array<string, mixed>  Unnormalized line input.
     *
     * @since   2.0.0
     */
    private function submittedLine(string $code, string $description, string $amount): array
    {
        return ['code' => $code, 'description' => $description, 'amount' => $amount];
    }

    /**
     * Find one field declared by a characterization fixture.
     *
     * @param   EntityTypeDefinition  $definition  Definition to inspect.
     * @param   string                $handle      Field handle expected to exist.
     *
     * @return  FieldDefinition  Matching field declaration.
     *
     * @since   2.0.0
     */
    private function field(EntityTypeDefinition $definition, string $handle): FieldDefinition
    {
        foreach ($definition->fields() as $field) {
            if ($field->handle === $handle) {
                return $field;
            }
        }

        throw new BusinessRelationshipRejected('The fixture field is missing.');
    }

    /**
     * Build one record in a selectable scope without imposing unrelated definition field rules.
     *
     * @param   EntityTypeDefinition  $definition  Definition identity and version carried by the row.
     * @param   string                $recordId    Caller-facing identity and internal key.
     * @param   ?RecordScope          $scope       Explicit scope, or the default site scope.
     *
     * @return  BusinessRecord  Live record at optimistic version one.
     *
     * @since   2.0.0
     */
    private function record(
        EntityTypeDefinition $definition,
        string $recordId,
        ?RecordScope $scope = null,
    ): BusinessRecord {
        $now = new DateTimeImmutable('2026-08-22T10:00:00+00:00');

        return new BusinessRecord(
            $definition->id,
            $definition->definitionVersion,
            $recordId,
            $recordId,
            $scope ?? $this->scope(),
            1,
            null,
            [],
            'relationship-coordinator-test',
            $now,
            'relationship-coordinator-test',
            $now,
        );
    }

    /**
     * Return the default site scope shared by owner and line rows.
     *
     * @return  RecordScope  Site-scoped record coordinate.
     *
     * @since   2.0.0
     */
    private function scope(): RecordScope
    {
        return RecordScope::forDefinition(ScopeMode::Site, SiteContext::default(), null);
    }

    /**
     * Return the system context used by relationship commands in these isolated tests.
     *
     * @return  ExecutionContext  Background context on the default site.
     *
     * @since   2.0.0
     */
    private static function context(): ExecutionContext
    {
        return ExecutionContext::issueSystem(
            new \stdClass(),
            SystemIdentity::Worker,
            SiteContext::default(),
            'relationship-coordinator-test',
        );
    }
}
