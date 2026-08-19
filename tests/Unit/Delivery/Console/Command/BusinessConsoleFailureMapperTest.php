<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Delivery\Console\Command;

use Kumwe\App\Application\Authorization\AuthorizationDenied;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordNotFound;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordValidationFailed;
use Kumwe\App\BusinessRecord\Application\Exception\BusinessRecordVersionConflict;
use Kumwe\App\BusinessRecord\Application\ValidationViolation;
use Kumwe\App\BusinessSurface\Application\BusinessOperationNotFound;
use Kumwe\App\Delivery\Console\Command\BusinessConsoleFailure;
use Kumwe\App\Delivery\Console\Command\BusinessConsoleFailureMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(BusinessConsoleFailure::class)]
#[CoversClass(BusinessConsoleFailureMapper::class)]
/**
 * Proves generated-business CLI failures are stable, bounded, and non-enumerating.
 *
 * @since  2.0.0
 */
final class BusinessConsoleFailureMapperTest extends TestCase
{
    /**
     * Proves authorization failures disclose no actor, resource, or policy evidence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAuthorizationFailureDoesNotExposeSubjectOrResourceEvidence(): void
    {
        $failure = (new BusinessConsoleFailureMapper())->map(new AuthorizationDenied(
            'sensitive-actor',
            'business.record.read',
            'business_record',
            'sensitive-record',
            'private-site',
            'policy-v9',
            'row_filter_denied',
        ));
        $encoded = json_encode($failure->toArray(), JSON_THROW_ON_ERROR);

        self::assertSame(BusinessConsoleFailureMapper::EXIT_PERMISSION, $failure->exitCode);
        self::assertSame('authorization.denied', $failure->code);
        self::assertStringNotContainsString('sensitive-actor', $encoded);
        self::assertStringNotContainsString('sensitive-record', $encoded);
        self::assertStringNotContainsString('policy-v9', $encoded);
    }

    /**
     * Proves missing and policy-hidden resources share one not-found contract.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNotFoundAndDeniedDefinitionUseTheNonEnumeratingContract(): void
    {
        $mapper = new BusinessConsoleFailureMapper();
        $failure = $mapper->map(new BusinessRecordNotFound());
        $operation = $mapper->map(new BusinessOperationNotFound());

        self::assertSame(BusinessConsoleFailureMapper::EXIT_NOT_FOUND, $failure->exitCode);
        self::assertSame('business_record.not_found', $failure->code);
        self::assertSame($failure->toArray(), $operation->toArray());
    }

    /**
     * Proves validation and version failures expose only bounded repair details.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testValidationAndVersionFailuresCarryOnlyBoundedRepairEvidence(): void
    {
        $mapper = new BusinessConsoleFailureMapper();
        $validation = $mapper->map(new BusinessRecordValidationFailed([
            new ValidationViolation('amount', 'invalid_decimal', 'Amount must be an exact decimal string.'),
        ]));
        $version = $mapper->map(new BusinessRecordVersionConflict(3, 4));

        self::assertSame(BusinessConsoleFailureMapper::EXIT_DATA, $validation->exitCode);
        self::assertSame('invalid_decimal', $validation->details['violations'][0]['code']);
        self::assertSame(BusinessConsoleFailureMapper::EXIT_CONFLICT, $version->exitCode);
        self::assertSame(['expected_version' => 3, 'actual_version' => 4], $version->details);
    }

    /**
     * Proves unexpected exceptions never publish their internal message.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnexpectedFailureNeverReturnsItsMessage(): void
    {
        $failure = (new BusinessConsoleFailureMapper())->map(new RuntimeException(
            'driver password super-secret appeared in SQL',
        ));
        $encoded = json_encode($failure->toArray(), JSON_THROW_ON_ERROR);

        self::assertSame(BusinessConsoleFailureMapper::EXIT_INTERNAL, $failure->exitCode);
        self::assertSame('internal_error', $failure->code);
        self::assertStringNotContainsString('super-secret', $encoded);
        self::assertStringNotContainsString('SQL', $encoded);
    }
}
