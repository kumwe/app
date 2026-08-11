<?php

declare(strict_types=1);

namespace Kumwe\CMS\Demo\Infrastructure;

use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Application\Authorization\SystemPrincipal;
use Kumwe\CMS\BusinessDefinition\Domain\CanonicalDefinitionJson;
use Kumwe\CMS\Demo\Application\DemoProfileReconciler;
use Kumwe\CMS\Demo\Infrastructure\FilesystemDemoManifestCatalog;
use Kumwe\CMS\Demo\Infrastructure\Persistence\DoctrineDemoProfileLedger;
use Kumwe\CMS\Kernel\Configuration\ApplicationConfiguration;
use Throwable;

/**
 * Coordinates the two independently selectable, durable built-in demo datasets after schema migration.
 *
 * Site documentation and VDM business records deliberately have separate selector rows. Disabling business
 * examples is therefore persisted as the explicit `none` profile rather than treated as an instruction to
 * do nothing, and a later environment change cannot silently inject data into an established installation.
 * Each manifest is restartable at its resource checkpoints and marked complete only after its application
 * service pass succeeds.
 *
 * @since  2.0.0
 */
final readonly class DemoProfileInstaller implements DemoProfileReconciler
{
    /**
     * Bind configuration, immutable manifests, handlers, ledger, and purpose-specific system authority.
     *
     * @param  ApplicationConfiguration      $configuration  Validated process profile selectors.
     * @param  FilesystemDemoManifestCatalog $catalog        Shipped immutable JSON manifests.
     * @param  DemoContentProfileInstaller   $content        Managed site-content reconciler.
     * @param  VdmBusinessDemoInstaller      $business       VDM business runtime reconciler.
     * @param  DoctrineDemoProfileLedger     $ledger         Durable selector and restart state.
     * @param  SystemPrincipal               $system         Purpose-bound profile-installer authority.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ApplicationConfiguration $configuration,
        private FilesystemDemoManifestCatalog $catalog,
        private DemoContentProfileInstaller $content,
        private VdmBusinessDemoInstaller $business,
        private DoctrineDemoProfileLedger $ledger,
        private SystemPrincipal $system,
    ) {
    }

    /**
     * Reconcile both profile selections under one installation-wide advisory lock.
     *
     * @return  list<string>  Operator-facing summaries suitable for the migration command output.
     *
     * @throws  \RuntimeException  When a stored selector drifts, a manifest is invalid, or any canonical
     *          application operation cannot be completed. Failed datasets remain restartable.
     *
     * @since   2.0.0
     */
    public function reconcile(): array
    {
        $site = SiteContext::fromString($this->configuration->publicSite);

        return $this->ledger->synchronized($site->identifier(), function () use ($site): array {
            $context = $this->system->context(
                $site,
                'demo-profile-' . bin2hex(random_bytes(16)),
            );
            $messages = $this->content($context);
            array_push($messages, ...$this->business($context));

            return $messages;
        });
    }

    /**
     * Reconcile the selected documentation, legacy placeholder, or blank content profile.
     *
     * @param   \Kumwe\CMS\Application\Authorization\ExecutionContext  $context  Installer context.
     *
     * @return  list<string>  Content reconciliation diagnostics.
     *
     * @since   2.0.0
     */
    private function content(\Kumwe\CMS\Application\Authorization\ExecutionContext $context): array
    {
        $profile = $this->configuration->siteContentProfile;
        $loaded = $this->catalog->content($profile);
        $manifest = $loaded['manifest'];
        if (
            !$this->ledger->begin(
                $context->site()->identifier(),
                DemoContentProfileInstaller::DATASET,
                $profile,
                $manifest['version'],
                $loaded['checksum'],
            )
        ) {
            return [];
        }
        try {
            $placeholder = $this->catalog->content('placeholder')['manifest'];
            $messages = $this->content->install($context, $manifest, $placeholder);
            $this->ledger->complete($context->site()->identifier(), DemoContentProfileInstaller::DATASET);
            array_unshift($messages, sprintf('Reconciled site content profile %s.', $profile));

            return $messages;
        } catch (Throwable $failure) {
            $this->ledger->failed($context->site()->identifier(), DemoContentProfileInstaller::DATASET);
            throw $failure;
        }
    }

    /**
     * Reconcile VDM business examples or persist the explicit no-business-data selection.
     *
     * @param   \Kumwe\CMS\Application\Authorization\ExecutionContext  $context  Installer context.
     *
     * @return  list<string>  Business reconciliation diagnostics.
     *
     * @since   2.0.0
     */
    private function business(\Kumwe\CMS\Application\Authorization\ExecutionContext $context): array
    {
        $profile = $this->configuration->businessDemo ? 'vdm' : 'none';
        $loaded = $this->configuration->businessDemo ? $this->catalog->vdmBusiness() : $this->noBusinessManifest();
        $manifest = $loaded['manifest'];
        if (
            !$this->ledger->begin(
                $context->site()->identifier(),
                VdmBusinessDemoInstaller::DATASET,
                $profile,
                $manifest['version'],
                $loaded['checksum'],
            )
        ) {
            return [];
        }
        try {
            $messages = $this->configuration->businessDemo
                ? $this->business->install($context, $manifest)
                : ['Recorded the blank business-demo selection.'];
            $this->ledger->complete($context->site()->identifier(), VdmBusinessDemoInstaller::DATASET);

            return $messages;
        } catch (Throwable $failure) {
            $this->ledger->failed($context->site()->identifier(), VdmBusinessDemoInstaller::DATASET);
            throw $failure;
        }
    }

    /**
     * Build the immutable manifest representing a deliberate absence of business fixtures.
     *
     * @return  array{manifest: array<string, mixed>, checksum: string}  Blank selection and canonical digest.
     *
     * @since   2.0.0
     */
    private function noBusinessManifest(): array
    {
        $manifest = [
            'format' => 'kumwe.demo-business-profile/v1',
            'profile' => 'none',
            'version' => 1,
            'description' => 'No built-in business demonstration data.',
        ];

        return ['manifest' => $manifest, 'checksum' => CanonicalDefinitionJson::checksum($manifest)];
    }
}
