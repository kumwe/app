<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use DateTimeImmutable;
use Kumwe\App\Extension\Application\ExtensionExecutionGate;
use Kumwe\App\Extension\Application\Trust\ExtensionArtifactVerifier;
use Kumwe\App\Extension\Application\Trust\TrustRuntimeInvalidator;
use Kumwe\App\Extension\Application\Trust\TrustStore;
use Kumwe\App\Extension\Application\Trust\TrustStoreRepository;
use Kumwe\Audit\Application\AuditRecorder;
use Kumwe\Extension\Package\PublicKeyPackageSignatureVerifier;
use Kumwe\Transaction\Contract\TransactionManager;
use PHPUnit\Framework\MockObject\Stub;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * One verified probe release and the real trust boundary that reads it, for the resident-code trust fences.
 *
 * The job handler, the Studio preview renderer, the route handler and the trust store's own reader fence are
 * all exercised against the same `acme/probe` release: a real `TrustStore` whose verifier and artifact
 * doubles accept it, over a repository double the test configures. Keeping the release, the compiled entry
 * that agrees with it and the store in one place means every fence is proven against identical evidence.
 *
 * @since  2.0.0
 */
trait ResidentTrustFixtures
{
    /**
     * Canonical owner of the probe package every fixture describes.
     *
     * @return  string  `acme/probe`.
     *
     * @since   2.0.0
     */
    protected static function probeExtension(): string
    {
        return 'acme/probe';
    }

    /**
     * Build the real trust boundary over a repository double, with every verifier accepting the probe.
     *
     * @param   TrustStoreRepository  $repository  Repository double holding the release inventory.
     * @param   ?LoggerInterface      $logger      Sink for an unreadable trust authority, when observed.
     *
     * @return  TrustStore  Trust boundary whose enforcement path runs against the double.
     *
     * @since   2.0.0
     */
    protected static function probeTrustStore(
        TrustStoreRepository $repository,
        ?LoggerInterface $logger = null,
    ): TrustStore {
        $verifier = self::createStub(PublicKeyPackageSignatureVerifier::class);
        $verifier->method('verify')->willReturn(true);
        $transactions = self::createStub(TransactionManager::class);
        $transactions->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );
        $clock = self::createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-08-05T08:00:00+00:00'));

        return new TrustStore(
            new DeterministicCanonicalEncoder(),
            $repository,
            $verifier,
            self::createStub(ExtensionArtifactVerifier::class),
            self::createStub(TrustRuntimeInvalidator::class),
            $transactions,
            self::createStub(AuditRecorder::class),
            $clock,
            AuthorizationContext::gateway(),
            logger: $logger,
        );
    }

    /**
     * Stub the generation, inventory, release and key lookups the enforcement read performs.
     *
     * The lifecycle lock and quarantine are deliberately left unconfigured so each test can state its own
     * expectation about them.
     *
     * @template T of Stub&TrustStoreRepository
     *
     * @param   T                     $repository  Repository double to configure.
     * @param   list<string>          $active      Extensions the store reports as active.
     * @param   array<string, mixed>  $release     Installed release record the store returns for the probe.
     *
     * @return  T  The same double, configured.
     *
     * @since   2.0.0
     */
    protected static function probeRepository(
        Stub&TrustStoreRepository $repository,
        array $active,
        array $release,
    ): Stub&TrustStoreRepository {
        $repository->method('lockGeneration')->willReturn(1);
        $repository->method('activeExtensions')->willReturn($active);
        $repository->method('installedRelease')->willReturn($release);
        $repository->method('usable')->willReturn([
            'public_key_base64' => base64_encode(str_repeat('k', SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)),
        ]);

        return $repository;
    }

    /**
     * Build a boot-generation gate that answers each generation read from a fixed script.
     *
     * `assertCurrent()` consumes the same script as `isCurrent()` and refuses with the production gate's
     * message on a false answer, exactly as `CurrentExtensionExecutionGate` derives one from the other.
     *
     * @param   bool  ...$answers  Answers in read order; the last one repeats once the script runs out.
     *
     * @return  ExtensionExecutionGate  Gate double.
     *
     * @since   2.0.0
     */
    protected static function scriptedGate(bool ...$answers): ExtensionExecutionGate
    {
        $last = $answers === [] ? true : $answers[array_key_last($answers)];
        $read = static function () use (&$answers, $last): bool {
            return $answers === [] ? $last : array_shift($answers);
        };
        $execution = self::createStub(ExtensionExecutionGate::class);
        $execution->method('isCurrent')->willReturnCallback($read);
        $execution->method('assertCurrent')->willReturnCallback(static function () use ($read): void {
            if (!$read()) {
                throw new RuntimeException('This process cannot execute a stale or untrusted extension generation.');
            }
        });

        return $execution;
    }

    /**
     * Build the installed release record the probe package was admitted with.
     *
     * @param   string  $trustState  Trust state recorded on the release.
     *
     * @return  array<string, mixed>  Release record matching the compiled runtime entry.
     *
     * @since   2.0.0
     */
    protected static function probeRelease(string $trustState = 'verified'): array
    {
        return [
            'identifier' => self::probeExtension(),
            'installed_version' => '1.0.0',
            'service_provider' => 'Acme\\Probe\\Provider',
            'extension_type' => 'plugin',
            'runtime_path' => 'acme/probe/1.0.0',
            'manifest' => json_encode([
                'schema' => 1,
                'name' => self::probeExtension(),
                'type' => 'plugin',
                'version' => '1.0.0',
                'provider' => 'Acme\\Probe\\Provider',
                'autoload' => ['psr-4' => ['Acme\\Probe\\' => 'src/']],
                'requires' => ['kumwe' => '^2.0.0', 'php' => '^8.5.0'],
                'dependencies' => [],
                'migrations' => [],
                'configuration' => new \stdClass(),
                'permissions' => [],
                'routes' => [],
                'events' => [],
                'assets' => [],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'package_sha256' => str_repeat('a', 64),
            'artifact_sha256' => str_repeat('a', 64),
            'deployed_tree_sha256' => str_repeat('b', 64),
            'signing_key_id' => 'acme.probe.signing',
            'signature_base64' => base64_encode(str_repeat('s', SODIUM_CRYPTO_SIGN_BYTES)),
            'trust_state' => $trustState,
        ];
    }

    /**
     * Build the exact compiled runtime entry that loaded the probe package.
     *
     * @return  array<string, mixed>  Runtime-map entry agreeing with the release record.
     *
     * @since   2.0.0
     */
    protected static function probeRuntimeEntry(): array
    {
        return [
            'identifier' => self::probeExtension(),
            'version' => '1.0.0',
            'provider' => 'Acme\\Probe\\Provider',
            'type' => 'plugin',
            'root' => 'acme/probe/1.0.0',
            'autoload' => ['Acme\\Probe\\' => 'src/'],
            'signing_key_id' => 'acme.probe.signing',
            'artifact_sha256' => str_repeat('a', 64),
            'deployed_tree_sha256' => str_repeat('b', 64),
        ];
    }
}
