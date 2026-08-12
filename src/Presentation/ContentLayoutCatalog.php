<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation;

use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Content\Application\ContentModelRepository;
use Kumwe\CMS\Content\Application\ContentRecord;

/**
 * Chooses the public site template a published record renders through, by its content type.
 *
 * This is the deliberate seam between the content model and the presentation: every core layout maps
 * from a content-type handle here, unknown and historical types keep the general `page` layout, and a
 * future presentation binding — for example a per-menu template selection — extends this decision point
 * rather than the handlers. Templates resolve through the isolated site loader chain, so an installed
 * theme or extension namespace may restyle a layout without widening this catalog.
 *
 * @since  2.0.0
 */
final readonly class ContentLayoutCatalog
{
    /**
     * Core layout template names by content-type handle.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const array LAYOUTS = [
        'article' => 'article',
        'document' => 'document',
        'faq' => 'faq',
        'guide' => 'guide',
        'landing' => 'landing',
        'reference' => 'reference',
    ];

    /**
     * Bind the catalog to the content-model catalog of the configured public site.
     *
     * @param  ContentModelRepository  $models          Published content-type catalog.
     * @param  string                  $siteIdentifier  Site whose types the public routes serve.
     *
     * @since  2.0.0
     */
    public function __construct(
        private ContentModelRepository $models,
        private string $siteIdentifier,
    ) {
    }

    /**
     * Resolve the site template one published record renders through.
     *
     * @param   ContentRecord  $record  Published record whose pinned content type names the layout.
     *
     * @return  string  Template name without the `.twig` suffix; `page` when the type declares no layout.
     *
     * @since   2.0.0
     */
    public function templateFor(ContentRecord $record): string
    {
        $type = $this->models->contentType(
            SiteContext::fromString($this->siteIdentifier),
            $record->contentTypeId,
        );

        return self::LAYOUTS[$type->handle ?? ''] ?? 'page';
    }
}
