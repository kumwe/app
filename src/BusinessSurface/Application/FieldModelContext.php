<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Application;

/**
 * Closed contexts in which the business-surface facade asks for a generated field model.
 *
 * This is the application layer's own vocabulary for the choice it makes — list cell, detail value,
 * create or update editor, filter, relation selector or history value — so the facade can name a context
 * without importing the presentation layer it feeds. The extension-facing
 * `FieldPresentationContext` in `BusinessSurface\Presentation\Field` spells the same closed set for the
 * published presenter SPI, and the presentation adapter translates between the two by backing value;
 * `BusinessSurfaceRenderingSeamTest` fails the build if the two enumerations or their editing rules
 * drift apart.
 *
 * @since  2.0.0
 */
enum FieldModelContext: string
{
    /** Collection cell. @since 2.0.0 */
    case List = 'list';

    /** Record detail value. @since 2.0.0 */
    case Detail = 'detail';

    /** Create-form editor. @since 2.0.0 */
    case Create = 'create';

    /** Update-form editor. @since 2.0.0 */
    case Update = 'update';

    /** Query filter editor. @since 2.0.0 */
    case Filter = 'filter';

    /** Cursor-bounded relationship selector. @since 2.0.0 */
    case Relation = 'relation';

    /** Revision-history value. @since 2.0.0 */
    case History = 'history';

    /**
     * Report whether this context accepts submitted input.
     *
     * @return  bool  True for create, update, filter and relation contexts.
     *
     * @since   2.0.0
     */
    public function edits(): bool
    {
        return in_array($this, [self::Create, self::Update, self::Filter, self::Relation], true);
    }
}
