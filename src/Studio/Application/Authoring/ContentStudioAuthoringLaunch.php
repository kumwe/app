<?php

declare(strict_types=1);

namespace Kumwe\App\Studio\Application\Authoring;

use LogicException;

/**
 * Holds the atomic App decision for one contextual Content Studio mount.
 *
 * Availability and configuration cannot drift apart: an available launch always retains its exact
 * canonical configuration, while every structured fallback retains none. The configuration remains
 * an opaque PHP object until Studio publishes the browser contract that owns its serialization.
 *
 * @since  2.0.0
 */
final readonly class ContentStudioAuthoringLaunch
{
    /**
     * Hold one mutually consistent launch or structured-fallback decision.
     *
     * @param   ContentStudioAuthoringTarget             $target         PHP-authoritative Content coordinates.
     * @param   StudioContextualAuthoringReadiness       $readiness      Effective launch readiness.
     * @param   ?StudioContextualAuthoringConfiguration  $configuration  Exact per-mount configuration.
     *
     * @throws  LogicException  When availability and configuration do not describe one atomic state.
     *
     * @since   2.0.0
     */
    public function __construct(
        public ContentStudioAuthoringTarget $target,
        public StudioContextualAuthoringReadiness $readiness,
        public ?StudioContextualAuthoringConfiguration $configuration,
    ) {
        if ($readiness->available !== ($configuration !== null)) {
            throw new LogicException('Studio contextual authoring launch is not atomic.');
        }
    }

    /**
     * Present the host-owned launch state without serializing Studio's unpublished configuration.
     *
     * The opaque configuration stays in the PHP template context as the same object returned by the
     * canonical provider. The current production provider always returns null, so no browser-facing
     * configuration is emitted before the published contract supplies its own encoder.
     *
     * @return  array{
     *          available: bool,
     *          fallback: string,
     *          reason: ?string,
     *          target: array{
     *              target_id: string,
     *              surface: string,
     *              intent: string,
     *              model_id: ?string,
     *              model_version: ?string,
     *              model_revision: ?string,
     *              entry_id: ?string,
     *              entry_revision: ?string,
     *              return_path: string
     *          },
     *          configuration: ?StudioContextualAuthoringConfiguration
     *          }  Atomic template state.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            ...$this->readiness->toArray(),
            'target' => $this->target->toArray(),
            'configuration' => $this->configuration,
        ];
    }
}
