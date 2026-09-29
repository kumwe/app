<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use Kumwe\App\BusinessDefinition\Application\FormulaEvaluation;
use Kumwe\App\BusinessReporting\Application\ReportMaterialization;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Kernel\NativeComputationFactory;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CanonicalJson\CanonicalEncoder;
use Kumwe\Computation\NativeAdapter;
use Kumwe\Computation\NativeAdapterFactory;
use Kumwe\Computation\NativeCompatibility;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Composes the production native computation bindings once for the tests that need real evaluation.
 *
 * Record validation, aggregate invariants, conditional visibility, report materialization and the digest
 * families all execute natively after the Computation cutover, so the unit tests that pin those verdicts
 * are bound to the admitted `ext-kumwe_engine` build exactly as `NativeComputationFactoryTest` is. This
 * support class boots `NativeComputationFactory::register()` on an empty container from the process
 * environment and hands out the shared services; without the extension it fails closed, which is the
 * documented sandbox outcome rather than a fallback. Tests that hold plans of their own take an isolated
 * package adapter so the shared plan pool stays untouched.
 *
 * @since  2.0.0
 */
final class NativeComputationContainer
{
    /**
     * Container the factory registered its bindings in, shared by every caller in the process.
     *
     * @var    ?Container
     * @since  2.0.0
     */
    private static ?Container $container = null;

    /**
     * The container carrying the seven package bindings and the two App ports.
     *
     * @return  Container  Container registered from the process environment.
     *
     * @since   2.0.0
     */
    public static function container(): Container
    {
        if (self::$container === null) {
            $container = new Container();
            (new NativeComputationFactory())->register($container, Environment::fromGlobals());
            self::$container = $container;
        }

        return self::$container;
    }

    /**
     * The App formula evaluation port, bound to the native executor.
     *
     * @return  FormulaEvaluation  Shared production adapter.
     *
     * @since   2.0.0
     */
    public static function formulas(): FormulaEvaluation
    {
        return self::service(FormulaEvaluation::class);
    }

    /**
     * The App report materialization port, bound to the native executor.
     *
     * @return  ReportMaterialization  Shared production adapter.
     *
     * @since   2.0.0
     */
    public static function reports(): ReportMaterialization
    {
        return self::service(ReportMaterialization::class);
    }

    /**
     * The canonical encoder the container binds, which is the native package encoder.
     *
     * @return  CanonicalEncoder  Shared production encoder.
     *
     * @since   2.0.0
     */
    public static function encoder(): CanonicalEncoder
    {
        return self::service(CanonicalEncoder::class);
    }

    /**
     * The independently recorded tuple the factory admitted the runtime against.
     *
     * @return  NativeCompatibility  Admitted tuple.
     *
     * @since   2.0.0
     */
    public static function compatibility(): NativeCompatibility
    {
        return self::service(NativeCompatibility::class);
    }

    /**
     * A package adapter over a runtime of its own, whose plan pool no other test shares.
     *
     * @return  NativeAdapter  Freshly built adapter admitted against the same tuple.
     *
     * @since   2.0.0
     */
    public static function isolatedAdapter(): NativeAdapter
    {
        return (new NativeAdapterFactory())(self::psr());
    }

    /**
     * The minimal PSR-11 container the package adapter factory reads the tuple from.
     *
     * @return  ContainerInterface  Container answering only for the admitted tuple.
     *
     * @since   2.0.0
     */
    private static function psr(): ContainerInterface
    {
        return new class (self::compatibility()) implements ContainerInterface {
            /**
             * Hold the tuple the package adapter factory reads.
             *
             * @param   NativeCompatibility  $compatibility  Admitted tuple.
             *
             * @since   2.0.0
             */
            public function __construct(
                private readonly NativeCompatibility $compatibility,
            ) {
            }

            /**
             * Resolve the admitted tuple.
             *
             * @param   string  $id  Requested service.
             *
             * @return  mixed  The admitted tuple.
             *
             * @throws  RuntimeException  When the requested service is not the tuple.
             *
             * @since   2.0.0
             */
            public function get(string $id): mixed
            {
                if ($id === NativeCompatibility::class) {
                    return $this->compatibility;
                }

                throw new RuntimeException('The native computation fixture container does not supply ' . $id . '.');
            }

            /**
             * Tell whether the fixture supplies a service.
             *
             * @param   string  $id  Requested service.
             *
             * @return  bool  Whether the fixture supplies it.
             *
             * @since   2.0.0
             */
            public function has(string $id): bool
            {
                return $id === NativeCompatibility::class;
            }
        };
    }

    /**
     * Resolve one shared service and hold it to its declared type.
     *
     * @template T of object
     *
     * @param   class-string<T>  $id  Service identifier.
     *
     * @return  T  Shared service.
     *
     * @throws  RuntimeException  When the container resolves something else.
     *
     * @since   2.0.0
     */
    private static function service(string $id): object
    {
        $service = self::container()->get($id);
        if (!$service instanceof $id) {
            throw new RuntimeException(sprintf('The native computation container did not resolve %s.', $id));
        }

        return $service;
    }
}
