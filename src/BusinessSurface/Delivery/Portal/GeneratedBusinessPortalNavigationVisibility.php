<?php

declare(strict_types=1);

namespace Kumwe\CMS\BusinessSurface\Delivery\Portal;

use Kumwe\CMS\BusinessSurface\Application\BusinessSurface;
use Kumwe\CMS\BusinessSurface\Application\BusinessSurfaceCatalog;
use Kumwe\CMS\BusinessSurface\Application\BusinessSurfaceOperation;
use Kumwe\CMS\Portal\Application\PortalExecutionContextFactory;
use Kumwe\CMS\Portal\Application\PortalSession;
use Kumwe\CMS\Portal\Presentation\PortalNavigationVisibility;
use Ramsey\Uuid\Uuid;

/**
 * Shows generated business navigation only when shared portal discovery returns an authorized workspace.
 *
 * @since  2.0.0
 */
final readonly class GeneratedBusinessPortalNavigationVisibility implements PortalNavigationVisibility
{
    /**
     * Core navigation identity reserved for generated portal business workspaces.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string NAVIGATION_ID = 'core.portal-business-records';

    /**
     * Bind request visibility to the canonical portal context and policy-filtered surface catalog.
     *
     * @param  BusinessSurfaceCatalog         $catalog   Shared definition exposure and policy boundary.
     * @param  PortalExecutionContextFactory  $contexts  Portal provenance-owning context factory.
     *
     * @since  2.0.0
     */
    public function __construct(
        private BusinessSurfaceCatalog $catalog,
        private PortalExecutionContextFactory $contexts,
    ) {
    }

    /**
     * Preserve ordinary navigation and require one discoverable portal definition for the generated item.
     *
     * @param   PortalSession              $session  Live portal session and membership snapshot.
     * @param   array<string, int|string>  $item     Capability- and trust-filtered navigation row.
     *
     * @return  bool  True for ordinary items or an authorized non-empty generated business catalog.
     *
     * @since   2.0.0
     */
    public function visible(PortalSession $session, array $item): bool
    {
        if (($item['id'] ?? null) !== self::NAVIGATION_ID) {
            return true;
        }
        $context = $this->contexts->create(
            $session,
            'portal-business-navigation-' . Uuid::uuid7()->toString(),
        );

        return $this->catalog->definitions(
            $context,
            BusinessSurface::Portal,
            BusinessSurfaceOperation::Discover,
        ) !== [];
    }
}
