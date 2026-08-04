<?php

declare(strict_types=1);

namespace Kumwe\CMS\Delivery\Http\Api\Plan;

enum SafePlanOperation: string
{
    case ContentReview = 'content.review';
    case SeoReview = 'seo.review';
    case SiteStructureReview = 'site.structure.review';
    case ExtensionCompatibilityReview = 'extension.compatibility.review';
}
