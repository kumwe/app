<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Integration\BusinessRecord;

use Doctrine\DBAL\Connection;
use Joomla\DI\Container;
use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\BusinessDefinition\Domain\EntityTypeDefinition;
use Kumwe\CMS\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\CMS\BusinessRecord\Application\BusinessRecordService;
use Kumwe\CMS\BusinessRecord\Application\RecordSecretRotation;
use Kumwe\CMS\BusinessRecord\Application\SecretAssociatedData;
use Kumwe\CMS\BusinessRecord\Application\SecretCipher;
use Kumwe\CMS\BusinessRecord\Application\SecretKeyProvider;
use Kumwe\CMS\BusinessRecord\Domain\EncryptedEnvelope;
use Kumwe\CMS\BusinessSchema\Application\BusinessSchemaInstallationRepository;
use Kumwe\CMS\Infrastructure\Persistence\TableNames;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CMS\Tests\Support\NeutralBusinessFixture;
use Kumwe\CMS\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use RuntimeException;

#[CoversNothing]
/**
 * Proves record secrets survive a key rotation on a real database, one bounded pass at a time.
 *
 * @since  2.0.0
 */
final class RecordSecretRotationIntegrationTest extends TestCase
{
    /**
     * Readable stem the rotated deployment's record secret is assembled from.
     *
     * It is repeated rather than written out at full length so no line of this file resembles a
     * credential to a secret scanner, and so a reader can see nothing here was ever real key material.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string RECORD_SECRET_STEM = 'rotation-fixture-';

    /**
     * Identifier that secret is configured under.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string ROTATED_KEY_ID = 'record-encryption-v2';

    /**
     * Restore the process environment so a later test still boots the unrotated deployment.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function tearDown(): void
    {
        putenv('RECORD_ENCRYPTION_KEY');
        putenv('RECORD_ENCRYPTION_KEY_ID');
    }

    /**
     * Prove envelopes written under the application secret survive the move to dedicated key material.
     *
     * The first container is the deployment as it shipped: one key, derived from `APP_SECRET`, stamped
     * `application-secret-v1`. The second is the same installation after an operator configured dedicated
     * key material and restarted, with no data migration in between. Everything already stored has to keep
     * opening across that restart, and everything written afterwards has to carry the new identifier.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testStoredSecretsMoveOntoTheActiveKeyWithoutBecomingUnreadable(): void
    {
        $legacy = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($legacy);
        $suffix = $this->suffix();
        $definition = NeutralBusinessFixture::install(
            $legacy,
            $context,
            NeutralBusinessFixture::document($suffix, Uuid::uuid7()->toString()),
        );
        $recordKey = $this->createRecord($legacy, $context, $definition, $suffix, 'first');

        self::assertSame(
            'application-secret-v1',
            $this->storedKeyId($legacy, $definition, $recordKey),
            'The unrotated deployment must still seal under the application-secret key.',
        );
        $sealed = $this->storedEnvelope($legacy, $definition, $recordKey);

        $rotated = $this->rotatedContainer();
        $rotatedContext = TestKernelFactory::administratorContext($rotated);
        $provider = $rotated->get(SecretKeyProvider::class);
        self::assertInstanceOf(SecretKeyProvider::class, $provider);
        self::assertSame(self::ROTATED_KEY_ID, $provider->activeKeyId());
        self::assertContains('application-secret-v1', $provider->knownKeyIds());
        self::assertSame(
            'neutral-fixture-secret',
            $this->open($rotated, $sealed, $definition, $recordKey),
            'A pre-rotation envelope must still open once dedicated key material is active.',
        );

        $rotation = $rotated->get(RecordSecretRotation::class);
        self::assertInstanceOf(RecordSecretRotation::class, $rotation);
        $report = $rotation->rotate($rotatedContext, 500);

        self::assertSame(self::ROTATED_KEY_ID, $report->activeKeyId);
        self::assertGreaterThanOrEqual(1, $report->resealed);
        self::assertSame(0, $report->superseded);
        self::assertTrue($report->complete, 'A pass with budget to spare must report itself complete.');

        $resealed = $this->storedEnvelope($rotated, $definition, $recordKey);
        self::assertSame(self::ROTATED_KEY_ID, $resealed->keyId);
        self::assertNotSame($sealed->ciphertext, $resealed->ciphertext);
        self::assertNotSame($sealed->nonce, $resealed->nonce);
        self::assertSame(
            'neutral-fixture-secret',
            $this->open($rotated, $resealed, $definition, $recordKey),
            'A re-sealed envelope must hold exactly the secret it held before.',
        );

        $second = $rotation->rotate($rotatedContext, 500);
        self::assertSame(0, $second->examined, 'A finished installation must give a later pass nothing to do.');
        self::assertSame(0, $second->resealed);
        self::assertTrue($second->complete);

        $audited = $this->database($rotated)->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE action = ?',
            $this->tables($rotated)->quoted('audit_events'),
        ), ['business.record.secret.rekeyed']);
        self::assertGreaterThanOrEqual(1, (int) $audited, 'Every re-keying chunk must be recorded.');
    }

    /**
     * Prove a pass stopped short leaves a consistent state that the next pass simply continues.
     *
     * The budget is set to one row, which is the strongest form of the interruption case: every pass
     * commits exactly one record and stops. Both the rows already moved and the rows still waiting have to
     * be readable at every point in between, because in production that is what a killed worker leaves.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnInterruptedRotationResumesWithoutLosingOrDoublingWork(): void
    {
        $legacy = TestKernelFactory::create(Environment::fromGlobals());
        $context = TestKernelFactory::administratorContext($legacy);
        $suffix = $this->suffix();
        $definition = NeutralBusinessFixture::install(
            $legacy,
            $context,
            NeutralBusinessFixture::document($suffix, Uuid::uuid7()->toString()),
        );
        $keys = [];
        foreach (['alpha', 'beta', 'gamma'] as $name) {
            $keys[] = $this->createRecord($legacy, $context, $definition, $suffix, $name);
        }

        $rotated = $this->rotatedContainer();
        $rotatedContext = TestKernelFactory::administratorContext($rotated);
        $rotation = $rotated->get(RecordSecretRotation::class);
        self::assertInstanceOf(RecordSecretRotation::class, $rotation);

        $moved = 0;
        for ($pass = 0; $pass < 3; $pass++) {
            $report = $rotation->rotate($rotatedContext, 1);
            $moved += $report->resealed;
            self::assertLessThanOrEqual(1, $report->examined, 'A pass must never exceed the budget it was given.');
            foreach ($keys as $key) {
                self::assertSame(
                    'neutral-fixture-secret',
                    $this->open($rotated, $this->storedEnvelope($rotated, $definition, $key), $definition, $key),
                    'Every record must stay readable while a rotation is only partly done.',
                );
            }
            if ($pass < 2) {
                self::assertFalse($report->complete, 'A pass that spent its whole budget cannot be complete.');
            }
        }

        self::assertSame(3, $moved, 'Three bounded passes must move exactly the three stored secrets.');
        foreach ($keys as $key) {
            self::assertSame(self::ROTATED_KEY_ID, $this->storedKeyId($rotated, $definition, $key));
        }
        self::assertTrue($rotation->rotate($rotatedContext, 10)->complete);
    }

    /**
     * Boot a second container for the same installation with dedicated record key material configured.
     *
     * @return  Container  Container whose record key ring has the dedicated key active and the
     *          application-secret key retired.
     *
     * @since   2.0.0
     */
    private function rotatedContainer(): Container
    {
        putenv('RECORD_ENCRYPTION_KEY=' . str_repeat(self::RECORD_SECRET_STEM, 3));
        putenv('RECORD_ENCRYPTION_KEY_ID=' . self::ROTATED_KEY_ID);

        return TestKernelFactory::create(Environment::fromGlobals());
    }

    /**
     * Create one fixture record carrying a secret field and hand back its storage key.
     *
     * @param   Container             $container   Container whose record service performs the write.
     * @param   ExecutionContext      $context     Authorized actor performing it.
     * @param   EntityTypeDefinition  $definition  Installed fixture definition.
     * @param   string                $suffix      Run-unique suffix keeping idempotency keys apart.
     * @param   string                $name        Record name, also part of the idempotency key.
     *
     * @return  string  Storage key of the created record.
     *
     * @since   2.0.0
     */
    private function createRecord(
        Container $container,
        ExecutionContext $context,
        EntityTypeDefinition $definition,
        string $suffix,
        string $name,
    ): string {
        $records = $container->get(BusinessRecordService::class);
        if (!$records instanceof BusinessRecordService) {
            throw new RuntimeException('The business record service is unavailable.');
        }

        return $records->create(new CreateRecordCommand(
            $context,
            $definition->handle,
            NeutralBusinessFixture::recordValues('Rotation ' . $name),
            NeutralBusinessFixture::idempotencyKey('rotation-' . $name . '-' . $suffix),
            recordId: Uuid::uuid7()->toString(),
        ))->recordKey;
    }

    /**
     * Read one record's stored secret envelope straight out of its generated columns.
     *
     * @param   Container             $container   Container supplying the connection and the blueprint.
     * @param   EntityTypeDefinition  $definition  Definition whose installation names the columns.
     * @param   string                $recordKey   Storage key of the row to read.
     *
     * @return  EncryptedEnvelope  The envelope exactly as stored.
     *
     * @since   2.0.0
     */
    private function storedEnvelope(
        Container $container,
        EntityTypeDefinition $definition,
        string $recordKey,
    ): EncryptedEnvelope {
        $row = $this->storedRow($container, $definition, $recordKey);

        return new EncryptedEnvelope($row['ciphertext'], $row['nonce'], $row['key_id'], $row['algorithm']);
    }

    /**
     * Read only the key identifier a stored secret carries.
     *
     * @param   Container             $container   Container supplying the connection and the blueprint.
     * @param   EntityTypeDefinition  $definition  Definition whose installation names the columns.
     * @param   string                $recordKey   Storage key of the row to read.
     *
     * @return  string  Identifier stamped into the stored envelope.
     *
     * @since   2.0.0
     */
    private function storedKeyId(
        Container $container,
        EntityTypeDefinition $definition,
        string $recordKey,
    ): string {
        return $this->storedRow($container, $definition, $recordKey)['key_id'];
    }

    /**
     * Fetch the four sealed components of the fixture's `credential` field for one row.
     *
     * @param   Container             $container   Container supplying the connection and the blueprint.
     * @param   EntityTypeDefinition  $definition  Definition whose installation names the columns.
     * @param   string                $recordKey   Storage key of the row to read.
     *
     * @return  array{ciphertext: string, nonce: string, key_id: string, algorithm: string}  Raw components.
     *
     * @since   2.0.0
     */
    private function storedRow(
        Container $container,
        EntityTypeDefinition $definition,
        string $recordKey,
    ): array {
        $installations = $container->get(BusinessSchemaInstallationRepository::class);
        if (!$installations instanceof BusinessSchemaInstallationRepository) {
            throw new RuntimeException('The schema installation repository is unavailable.');
        }
        $installation = $installations->find($definition->id);
        $table = $installation?->blueprint->table('record');
        if ($table === null) {
            throw new RuntimeException('The fixture record table is unavailable.');
        }
        $columns = [];
        foreach (['ciphertext', 'nonce', 'key_id', 'algorithm'] as $component) {
            $column = $table->column('credential.' . $component);
            if ($column === null) {
                throw new RuntimeException('The fixture secret column is unavailable.');
            }
            $columns[$component] = $column->physicalName;
        }
        $database = $this->database($container);
        $row = $database->fetchAssociative(sprintf(
            'SELECT %s, %s, %s, %s FROM %s WHERE %s = ?',
            $database->quoteSingleIdentifier($columns['ciphertext']),
            $database->quoteSingleIdentifier($columns['nonce']),
            $database->quoteSingleIdentifier($columns['key_id']),
            $database->quoteSingleIdentifier($columns['algorithm']),
            $database->quoteSingleIdentifier($table->physicalName),
            $database->quoteSingleIdentifier($table->primaryKey[0]),
        ), [$recordKey]);
        if ($row === false) {
            throw new RuntimeException('The fixture record row is unavailable.');
        }

        return [
            'ciphertext' => $this->bytes($row[$columns['ciphertext']] ?? null),
            'nonce' => $this->bytes($row[$columns['nonce']] ?? null),
            'key_id' => $this->bytes($row[$columns['key_id']] ?? null),
            'algorithm' => $this->bytes($row[$columns['algorithm']] ?? null),
        ];
    }

    /**
     * Open one stored envelope through the container's own cipher and binding.
     *
     * @param   Container             $container   Container supplying the key ring cipher.
     * @param   EncryptedEnvelope     $envelope    Envelope as stored.
     * @param   EntityTypeDefinition  $definition  Definition the record belongs to.
     * @param   string                $recordKey   Storage key bound into the associated data.
     *
     * @return  string  The plaintext the envelope protects.
     *
     * @since   2.0.0
     */
    private function open(
        Container $container,
        EncryptedEnvelope $envelope,
        EntityTypeDefinition $definition,
        string $recordKey,
    ): string {
        $cipher = $container->get(SecretCipher::class);
        if (!$cipher instanceof SecretCipher) {
            throw new RuntimeException('The record secret cipher is unavailable.');
        }

        return $cipher->decrypt($envelope, SecretAssociatedData::for(
            $definition->siteIdentifier,
            $definition->id,
            $recordKey,
            'credential',
        ));
    }

    /**
     * Read one raw column value as bytes, draining a stream when the driver hands one back.
     *
     * @param   mixed  $value  Raw driver value.
     *
     * @return  string  The column's bytes.
     *
     * @since   2.0.0
     */
    private function bytes(mixed $value): string
    {
        if (is_resource($value)) {
            $contents = stream_get_contents($value);
            if (!is_string($contents)) {
                throw new RuntimeException('A stored secret column could not be read.');
            }

            return $contents;
        }
        if (!is_string($value)) {
            throw new RuntimeException('A stored secret column holds an unexpected value.');
        }

        return $value;
    }

    /**
     * Resolve the connection the fixture rows live on.
     *
     * @param   Container  $container  Container supplying it.
     *
     * @return  Connection  The DBAL connection.
     *
     * @since   2.0.0
     */
    private function database(Container $container): Connection
    {
        $database = $container->get(Connection::class);
        if (!$database instanceof Connection) {
            throw new RuntimeException('The integration connection is unavailable.');
        }

        return $database;
    }

    /**
     * Resolve the prefixed table-name map.
     *
     * @param   Container  $container  Container supplying it.
     *
     * @return  TableNames  The prefix resolver.
     *
     * @since   2.0.0
     */
    private function tables(Container $container): TableNames
    {
        $tables = $container->get(TableNames::class);
        if (!$tables instanceof TableNames) {
            throw new RuntimeException('The integration table map is unavailable.');
        }

        return $tables;
    }

    /**
     * Build a run-unique suffix so repeated runs never collide on a fixture handle.
     *
     * @return  string  Twelve lowercase hexadecimal characters.
     *
     * @since   2.0.0
     */
    private function suffix(): string
    {
        return strtolower(substr(str_replace('-', '', Uuid::uuid7()->toString()), -12));
    }
}
