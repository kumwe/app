<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Functional\Security;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use InvalidArgumentException;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Http\Handler\ExtensionAssetHandler;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Tests\Support\SecurityHttpHarness;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Pins that a signed extension's published assets follow its trust through rotation and revocation.
 *
 * The public asset route re-asks the registry on every request whether the owning extension is active,
 * its release verified and its signing key enabled, unrevoked and in date, and every trust change advances
 * the runtime generation so a process booted before it drains with a retryable 503 instead of serving
 * from a superseded publication. This drives both promises over HTTP across the key lifecycle: the asset
 * is served while the signing key is trusted, keeps being served through a rotation's overlap, survives a
 * refused premature final revocation, and is withdrawn after an emergency revocation with the same bare
 * refusal a missing file gets, while the process that was running when trust first changed stays fenced.
 * A fake release has no deployable code, so the asset handler is driven through the production container
 * behind the fenced pipeline, and the release is left quarantined whatever the outcome.
 *
 * @since  2.0.0
 */
#[CoversClass(ExtensionAssetHandler::class)]
#[CoversClass(TrustStore::class)]
final class ExtensionTrustRevocationBoundaryTest extends TestCase
{
    /**
     * Rotation keeps the asset online through its overlap and emergency revocation takes it offline at once.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAssetsFollowTheSigningKeyThroughRotationAndEmergencyRevocation(): void
    {
        $harness = SecurityHttpHarness::boot();
        $unknown = $harness->handle($harness->request('GET', '/assets/extensions/unknown/extension/1.0.0/app.css'));
        self::assertSame(404, $unknown->getStatusCode(), 'An asset of no installed extension is a bare refusal.');
        $trust = $harness->container->get(TrustStore::class);
        $database = $harness->container->get(Connection::class);
        $tables = $harness->container->get(TableNames::class);
        self::assertInstanceOf(TrustStore::class, $trust);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(TableNames::class, $tables);
        $context = TestKernelFactory::administratorContext($harness->container);
        $marker = strtolower(str_replace('-', '', Uuid::uuid7()->toString()));
        $identifier = 'integration/asset' . substr($marker, -16);
        $oldKey = 'integration.asset-old.' . substr($marker, -16);
        $newKey = 'integration.asset-new.' . substr($marker, -16);
        $trust->add(
            $context,
            $oldKey,
            base64_encode(str_repeat('o', SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)),
            'integration',
            '*',
            new DateTimeImmutable('+1 year'),
        );
        $this->installVerifiedRelease($database, $tables, $identifier, $oldKey);
        $directory = dirname(__DIR__, 3) . '/public/assets/extensions/' . $identifier . '/1.0.0';
        mkdir($directory, 0o755, true);
        file_put_contents($directory . '/app.css', 'body { color: #123456; }');
        $path = '/assets/extensions/' . $identifier . '/1.0.0/app.css';

        $handler = $harness->container->get(ExtensionAssetHandler::class);
        self::assertInstanceOf(ExtensionAssetHandler::class, $handler);
        $asset = static fn (string $file) => $handler->handle(
            $harness->request('GET', '/assets/extensions/' . $identifier . '/1.0.0/' . $file)
                ->withAttribute('path', $identifier . '/1.0.0/' . $file),
        );

        try {
            $stale = $harness->handle($harness->request('GET', $path));
            self::assertSame(503, $stale->getStatusCode(), 'A process booted before a trust change is fenced.');
            self::assertSame('1', $stale->getHeaderLine('Retry-After'));

            $served = $asset('app.css');
            self::assertSame(200, $served->getStatusCode(), 'A verified release serves its asset.');
            self::assertSame('body { color: #123456; }', (string) $served->getBody());
            self::assertSame('private, no-store', $served->getHeaderLine('Cache-Control'));
            self::assertSame('nosniff', $served->getHeaderLine('X-Content-Type-Options'));

            $trust->rotate(
                $context,
                $oldKey,
                $newKey,
                base64_encode(str_repeat('n', SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)),
                'integration',
                '*',
                new DateTimeImmutable('+1 year'),
            );
            self::assertSame(200, $asset('app.css')->getStatusCode(), 'The rotation overlap keeps the asset.');
            try {
                $trust->finalizeRotation($context, $oldKey, 'premature final revocation');
                self::fail('A final revocation must wait until no active release needs the old key.');
            } catch (InvalidArgumentException) {
                self::assertSame(200, $asset('app.css')->getStatusCode());
            }

            self::assertSame([$identifier], $trust->emergencyRevoke($context, $oldKey, 'asset boundary exercise'));
            $withdrawn = $asset('app.css');
            self::assertSame(404, $withdrawn->getStatusCode(), 'A revoked key takes the asset offline at once.');
            self::assertSame('', (string) $withdrawn->getBody());
            $missing = $asset('none.css');
            self::assertSame($missing->getStatusCode(), $withdrawn->getStatusCode());
            self::assertSame($missing->getHeaders(), $withdrawn->getHeaders(), 'Revoked and missing look alike.');
            self::assertSame(503, $harness->handle($harness->request('GET', $path))->getStatusCode());
        } finally {
            $database->executeStatement(sprintf(
                "UPDATE %s SET status = 'quarantined' WHERE identifier = ? AND status = 'active'",
                $tables->quoted('extensions'),
            ), [$identifier]);
            array_map('unlink', glob($directory . '/*') ?: []);
            for ($level = 0; $level < 3; $level++) {
                if (is_dir($directory) && (scandir($directory) ?: []) === ['.', '..']) {
                    rmdir($directory);
                }
                $directory = dirname($directory);
            }
        }
    }

    /**
     * Record an active extension whose current release is verified under the given key.
     *
     * @param   Connection  $database    Suite database.
     * @param   TableNames  $tables      Prefixed table names.
     * @param   string      $identifier  Extension identifier, `vendor/name`.
     * @param   string      $keyId       Signing key the release verified against.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function installVerifiedRelease(
        Connection $database,
        TableNames $tables,
        string $identifier,
        string $keyId,
    ): void {
        $extensionId = Uuid::uuid7()->toString();
        $now = new DateTimeImmutable();
        $database->insert($tables->raw('extensions'), [
            'id' => $extensionId,
            'identifier' => $identifier,
            'extension_type' => 'plugin',
            'installed_version' => '1.0.0',
            'status' => 'active',
            'service_provider' => 'Integration\\Provider',
            'runtime_path' => $identifier . '/1.0.0',
            'registry_version' => 1,
            'installed_at' => $now,
            'updated_at' => $now,
        ], ['installed_at' => Types::DATETIME_IMMUTABLE, 'updated_at' => Types::DATETIME_IMMUTABLE]);
        $database->insert($tables->raw('extension_releases'), [
            'id' => Uuid::uuid7()->toString(),
            'extension_id' => $extensionId,
            'version' => '1.0.0',
            'manifest' => [
                'schema' => 1,
                'name' => $identifier,
                'type' => 'plugin',
                'version' => '1.0.0',
                'provider' => 'Integration\\Provider',
                'autoload' => ['psr-4' => ['Integration\\' => 'src/']],
                'requires' => ['kumwe' => '^2.0.0', 'php' => '^8.5.0'],
            ],
            'package_sha256' => str_repeat('a', 64),
            'artifact_sha256' => str_repeat('a', 64),
            'deployed_tree_sha256' => str_repeat('b', 64),
            'trust_state' => 'verified',
            'signature_algorithm' => 'ed25519',
            'signing_key_id' => $keyId,
            'signature_base64' => base64_encode(str_repeat('s', SODIUM_CRYPTO_SIGN_BYTES)),
            'released_at' => $now,
            'installed_at' => $now,
        ], [
            'manifest' => Types::JSON,
            'released_at' => Types::DATETIME_IMMUTABLE,
            'installed_at' => Types::DATETIME_IMMUTABLE,
        ]);
    }
}
