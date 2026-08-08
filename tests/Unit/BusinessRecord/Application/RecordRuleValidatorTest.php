<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\BusinessRecord\Application;

use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessRecord\Application\Exception\BusinessRecordValidationFailed;
use Kumwe\CMS\BusinessRecord\Application\RecordRuleValidator;
use Kumwe\CMS\BusinessRecord\Application\RecordValueCodec;
use Kumwe\CMS\BusinessRecord\Application\ValidationViolation;
use Kumwe\CMS\BusinessRecord\Domain\EncryptedEnvelope;
use Kumwe\CMS\BusinessRecord\Domain\ExactDecimal;
use Kumwe\CMS\BusinessRecord\Infrastructure\Security\SodiumSecretCipher;
use Kumwe\CMS\Tests\Support\NeutralBusinessFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RecordRuleValidator::class)]
final class RecordRuleValidatorTest extends TestCase
{
    public function testCreateAppliesDefaultsNormalizationEncryptionAndStoredComputation(): void
    {
        $definition = EntityTypeDefinition::fromArray(NeutralBusinessFixture::backupDocument());
        $rules = self::rules();
        $input = NeutralBusinessFixture::recordValues('  Canonical name  ');
        $values = $rules->create(
            $definition,
            $input,
            'default',
            NeutralBusinessFixture::RECORD_ID,
            NeutralBusinessFixture::RECORD_ID,
        );

        self::assertSame(NeutralBusinessFixture::RECORD_ID, $values['id']);
        self::assertSame('Canonical name', $values['name']);
        self::assertSame('draft', $values['status']);
        self::assertFalse($values['enabled']);
        self::assertSame('Canonical name', $values['display_name']);
        self::assertInstanceOf(ExactDecimal::class, $values['amount']);
        self::assertSame(
            '12345678901234567890123456789012345.123456789012345678901234567890',
            $values['amount']->value(),
        );
        self::assertInstanceOf(EncryptedEnvelope::class, $values['credential']);
    }

    public function testUpdateRecomputesDependenciesAndRejectsImmutableOrCallerComputedFields(): void
    {
        $definition = EntityTypeDefinition::fromArray(NeutralBusinessFixture::backupDocument());
        $rules = self::rules();
        $current = $rules->create(
            $definition,
            NeutralBusinessFixture::recordValues('Before'),
            'default',
            NeutralBusinessFixture::RECORD_ID,
            NeutralBusinessFixture::RECORD_ID,
        );
        $updated = $rules->update(
            $definition,
            $current,
            ['name' => '  After  '],
            'default',
            NeutralBusinessFixture::RECORD_ID,
            NeutralBusinessFixture::RECORD_ID,
        );

        self::assertSame('After', $updated['name']);
        self::assertSame('After', $updated['display_name']);

        try {
            $rules->update(
                $definition,
                $updated,
                ['id' => NeutralBusinessFixture::SECOND_RECORD_ID],
                'default',
                NeutralBusinessFixture::RECORD_ID,
                NeutralBusinessFixture::RECORD_ID,
            );
            self::fail('The immutable public identity must not change.');
        } catch (BusinessRecordValidationFailed $exception) {
            self::assertSame('immutable', $exception->violations[0]->code);
        }

        try {
            $input = NeutralBusinessFixture::recordValues();
            $input['display_name'] = 'caller value';
            $rules->create(
                $definition,
                $input,
                'default',
                NeutralBusinessFixture::RECORD_ID,
                NeutralBusinessFixture::RECORD_ID,
            );
            self::fail('A caller must not supply a computed field.');
        } catch (BusinessRecordValidationFailed $exception) {
            self::assertContains('read_only', array_map(
                static fn (ValidationViolation $violation): string => $violation->code,
                $exception->violations,
            ));
        }
    }

    public function testValidationAggregatesUnknownExactAndCrossFieldFailuresDeterministically(): void
    {
        $definition = EntityTypeDefinition::fromArray(NeutralBusinessFixture::backupDocument());
        $input = NeutralBusinessFixture::recordValues('X');
        $input['amount'] = '-1.000000';
        $input['unknown'] = 'value';

        try {
            self::rules()->create(
                $definition,
                $input,
                'default',
                NeutralBusinessFixture::RECORD_ID,
                NeutralBusinessFixture::RECORD_ID,
            );
            self::fail('The invalid record must be rejected.');
        } catch (BusinessRecordValidationFailed $exception) {
            $violations = array_map(
                static fn (ValidationViolation $violation): string => $violation->field . ':' . $violation->code,
                $exception->violations,
            );
            self::assertContains('name:min_length', $violations);
            self::assertContains('amount:min', $violations);
            self::assertContains('unknown:unknown', $violations);
            self::assertContains('non_negative_amount:invariant.non_negative_amount', $violations);
        }
    }

    public function testRepinRenormalizesStoredValuesRecomputesFormulasAndRejectsTargetViolations(): void
    {
        $definition = EntityTypeDefinition::fromArray(NeutralBusinessFixture::backupDocument());
        $rules = self::rules();
        $stored = $rules->create(
            $definition,
            NeutralBusinessFixture::recordValues('Before'),
            'default',
            NeutralBusinessFixture::RECORD_ID,
            NeutralBusinessFixture::RECORD_ID,
        );
        $stored['name'] = '  Re-pinned  ';
        $stored['display_name'] = 'stale computed value';
        $repinned = $rules->repin(
            $definition,
            $stored,
            'default',
            NeutralBusinessFixture::RECORD_ID,
            NeutralBusinessFixture::RECORD_ID,
        );
        self::assertSame('Re-pinned', $repinned['name']);
        self::assertSame('Re-pinned', $repinned['display_name']);

        $stored['name'] = ' X ';
        try {
            $rules->repin(
                $definition,
                $stored,
                'default',
                NeutralBusinessFixture::RECORD_ID,
                NeutralBusinessFixture::RECORD_ID,
            );
            self::fail('A stored row that violates the target definition must not be repinned.');
        } catch (BusinessRecordValidationFailed $exception) {
            self::assertContains('min_length', array_map(
                static fn (ValidationViolation $violation): string => $violation->code,
                $exception->violations,
            ));
        }
    }

    public function testOptionalNonNullDefaultsApplyAndExplicitNullUpdatesAreRejected(): void
    {
        $document = NeutralBusinessFixture::backupDocument();
        $document['fields'][] = [
            'handle' => 'non_null_optional',
            'label' => 'Non-null optional',
            'type' => 'core.text',
            'required' => false,
            'nullable' => false,
            'default' => 'fallback',
        ];
        $definition = EntityTypeDefinition::fromArray($document);
        $rules = self::rules();
        $values = $rules->create(
            $definition,
            NeutralBusinessFixture::recordValues(),
            'default',
            NeutralBusinessFixture::RECORD_ID,
            NeutralBusinessFixture::RECORD_ID,
        );
        self::assertSame('fallback', $values['non_null_optional']);

        try {
            $rules->update(
                $definition,
                $values,
                ['non_null_optional' => null],
                'default',
                NeutralBusinessFixture::RECORD_ID,
                NeutralBusinessFixture::RECORD_ID,
            );
            self::fail('An explicit null update reached a non-null physical column.');
        } catch (BusinessRecordValidationFailed $exception) {
            self::assertContains('not_nullable', array_map(
                static fn (ValidationViolation $violation): string => $violation->code,
                $exception->violations,
            ));
        }
    }

    private static function rules(): RecordRuleValidator
    {
        $cipher = new SodiumSecretCipher(
            'validation-key-v1',
            str_repeat("\x42", SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES),
        );

        return new RecordRuleValidator(new RecordValueCodec($cipher));
    }
}
