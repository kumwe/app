<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Architecture;

use Kumwe\CMS\Application\Automation\IdempotencyPurger;
use Kumwe\CMS\Application\Idempotency\IdempotencyLedger;
use Kumwe\CMS\Application\Idempotency\SecretOnceIdempotencyLedger;
use Kumwe\CMS\Infrastructure\Automation\DoctrineIdempotencyPurger;
use Kumwe\CMS\Infrastructure\Persistence\DoctrineIdempotencyLedger;
use Kumwe\CMS\Infrastructure\Persistence\DoctrineSecretOnceIdempotencyLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use SplFileInfo;

#[CoversClass(DoctrineIdempotencyLedger::class)]
#[CoversClass(DoctrineSecretOnceIdempotencyLedger::class)]
#[CoversClass(DoctrineIdempotencyPurger::class)]
/**
 * Pins the idempotency seam by type rather than by grep, the way the transaction seam is pinned.
 *
 * Which operations are replay-protected, and what a reservation means, are use-case decisions, so
 * Application declares the ledger contracts and Infrastructure supplies the Doctrine adapters. The HTTP
 * middlewares that consume them are delivery code, and delivery code writing Doctrine state directly is
 * the exact leak `P3-C` closed — so these checks read reflected signatures and token streams, which is
 * what makes them survive a rename: a driver type reaching back into the middleware directory fails here
 * even when it arrives without a `use` line for a textual gate to find.
 *
 * @since  2.0.0
 */
final class IdempotencySeamBoundaryTest extends TestCase
{
    /**
     * The idempotency ports belong to Application, and every shipped adapter to Infrastructure.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheIdempotencyPortsAreOwnedByApplicationAndAdaptedByInfrastructure(): void
    {
        $bindings = [
            DoctrineIdempotencyLedger::class => IdempotencyLedger::class,
            DoctrineSecretOnceIdempotencyLedger::class => SecretOnceIdempotencyLedger::class,
            DoctrineIdempotencyPurger::class => IdempotencyPurger::class,
        ];

        foreach ($bindings as $adapter => $port) {
            $portClass = new ReflectionClass($port);
            $adapterClass = new ReflectionClass($adapter);

            self::assertTrue($portClass->isInterface(), $port . ' must be a contract, not a class.');
            self::assertStringStartsWith('Kumwe\\CMS\\Application\\', $port);
            self::assertStringStartsWith('Kumwe\\CMS\\Infrastructure\\', $adapter);
            self::assertTrue($adapterClass->implementsInterface($port), $adapter . ' must answer ' . $port . '.');
            self::assertStringStartsWith(
                dirname(__DIR__, 2) . '/src/Application/',
                (string) $portClass->getFileName(),
                'The port file must sit inside the application layer, not merely carry its namespace.',
            );
            self::assertStringStartsWith(
                dirname(__DIR__, 2) . '/src/Infrastructure/',
                (string) $adapterClass->getFileName(),
                $adapter . ' must live under src/Infrastructure.',
            );
        }
    }

    /**
     * Nothing a caller passes through an idempotency port is a driver type.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheIdempotencyPortsNameNoDriverTypeInTheirSignatures(): void
    {
        foreach ([IdempotencyLedger::class, SecretOnceIdempotencyLedger::class, IdempotencyPurger::class] as $port) {
            foreach ((new ReflectionClass($port))->getMethods() as $method) {
                foreach ($method->getParameters() as $parameter) {
                    self::assertSame(
                        [],
                        $this->driverTypes($parameter->getType()),
                        sprintf('%s::%s() accepts a driver type.', $port, $method->getName()),
                    );
                }
                self::assertSame(
                    [],
                    $this->driverTypes($method->getReturnType()),
                    sprintf('%s::%s() returns a driver type.', $port, $method->getName()),
                );
            }
        }
    }

    /**
     * No delivery idempotency file names a driver or an Infrastructure type anywhere in its code.
     *
     * The imports a file declares are the easy half. This reads the token stream instead, so a fully
     * qualified `\Doctrine\DBAL\Connection` written inline — the shape that carries no `use` line for a
     * gate to find — fails here too, while the same name in a documentation block does not.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNoDeliveryIdempotencyFileNamesADriverOutsideItsDocumentation(): void
    {
        $root = dirname(__DIR__, 2) . '/src/Delivery/Http/Api/Idempotency';
        $offenders = [];
        $files = 0;
        foreach (
            new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            ) as $file
        ) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $files++;
            $source = file_get_contents($file->getPathname());
            self::assertIsString($source, sprintf('Could not read %s.', $file->getPathname()));
            foreach (token_get_all($source) as $token) {
                if (!is_array($token)) {
                    continue;
                }
                if (!in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    continue;
                }
                $name = ltrim($token[1], '\\');
                if (str_starts_with($name, 'Doctrine\\') || str_starts_with($name, 'Kumwe\\CMS\\Infrastructure\\')) {
                    $offenders[] = sprintf('%s:%d %s', $file->getFilename(), $token[2], $name);
                }
            }
        }

        self::assertGreaterThan(0, $files, 'The delivery idempotency directory could not be enumerated.');
        self::assertSame(
            [],
            $offenders,
            'Delivery idempotency code must reach the ledger only through its application-owned ports.',
        );
    }

    /**
     * Names every driver type a declaration mentions, unwrapping unions and intersections.
     *
     * @param   ?ReflectionType  $type  Declared type of a parameter or a return, or null when untyped.
     *
     * @return  list<string>  Fully qualified driver type names, empty when the declaration is clean.
     *
     * @since   2.0.0
     */
    private function driverTypes(?ReflectionType $type): array
    {
        if ($type instanceof ReflectionNamedType) {
            return str_starts_with($type->getName(), 'Doctrine\\') ? [$type->getName()] : [];
        }
        if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
            $names = [];
            foreach ($type->getTypes() as $member) {
                foreach ($this->driverTypes($member) as $name) {
                    $names[] = $name;
                }
            }

            return $names;
        }

        return [];
    }
}
