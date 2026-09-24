<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use Kumwe\App\BusinessDefinition\Application\FormulaEvaluation;
use Kumwe\App\BusinessReporting\Application\ReportMaterialization;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Kernel\NativeComputationFactory;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\CanonicalJson\CanonicalEncoder;
use Kumwe\CanonicalJson\Limits;
use Kumwe\Computation\NativeAdapter;
use Kumwe\Computation\NativeAdapterFactory;
use Kumwe\Computation\NativeCanonicalEncoderFactory;
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
 * documented sandbox outcome rather than a fallback. A test that must hold plans of its own, or encode under
 * budgets other than the container's, takes an isolated package service over its own runtime so the shared
 * plan pool stays untouched.
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
        return (new NativeAdapterFactory())(self::psr(null));
    }

    /**
     * A package encoder over a runtime of its own, honouring the given canonical budgets.
     *
     * @param   Limits  $limits  Budgets the encoder enforces instead of the profile defaults.
     *
     * @return  CanonicalEncoder  Freshly built encoder admitted against the same tuple.
     *
     * @since   2.0.0
     */
    public static function boundedEncoder(Limits $limits): CanonicalEncoder
    {
        return (new NativeCanonicalEncoderFactory())(self::psr($limits));
    }

    /**
     * The minimal PSR-11 container the package factories read the tuple and optional budgets from.
     *
     * @param   ?Limits  $limits  Budgets to expose, or null to leave the profile defaults in force.
     *
     * @return  ContainerInterface  Container answering only for the tuple and the budgets.
     *
     * @since   2.0.0
     */
    private static function psr(?Limits $limits): ContainerInterface
    {
        return new class (self::compatibility(), $limits) implements ContainerInterface {
            /**
             * Hold the tuple and the optional budgets the package factories read.
             *
             * @param   NativeCompatibility  $compatibility  Admitted tuple.
             * @param   ?Limits              $limits         Budgets, when the caller supplies them.
             *
             * @since   2.0.0
             */
            public function __construct(
                private readonly NativeCompatibility $compatibility,
                private readonly ?Limits $limits,
            ) {
            }

            /**
             * Resolve the tuple or the budgets.
             *
             * @param   string  $id  Requested service.
             *
             * @return  mixed  The tuple or the budgets.
             *
             * @throws  RuntimeException  When the service is not one of the two.
             *
             * @since   2.0.0
             */
            public function get(string $id): mixed
            {
                if ($id === NativeCompatibility::class) {
                    return $this->compatibility;
                }
                if ($id === Limits::class && $this->limits !== null) {
                    return $this->limits;
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
                return $id === NativeCompatibility::class || ($id === Limits::class && $this->limits !== null);
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
