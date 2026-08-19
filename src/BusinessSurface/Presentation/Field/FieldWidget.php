<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessSurface\Presentation\Field;

/**
 * Allow-listed semantic widgets safe renderers may ask core Twig templates to emit.
 *
 * @since  2.0.0
 */
enum FieldWidget: string
{
    /** Read-only escaped output. @since 2.0.0 */
    case Output = 'output';

    /** Single-line text editor. @since 2.0.0 */
    case Text = 'text';

    /** Multi-line plain-text editor. @since 2.0.0 */
    case Textarea = 'textarea';

    /** Whole-number editor. @since 2.0.0 */
    case Integer = 'integer';

    /** Exact decimal-string editor. @since 2.0.0 */
    case Decimal = 'decimal';

    /** Boolean checkbox. @since 2.0.0 */
    case Checkbox = 'checkbox';

    /** Closed choice selector. @since 2.0.0 */
    case Select = 'select';

    /** Date editor. @since 2.0.0 */
    case Date = 'date';

    /** Local-time editor. @since 2.0.0 */
    case Time = 'time';

    /** Timestamp editor preserving its typed wire value. @since 2.0.0 */
    case DateTime = 'datetime';

    /** Email editor. @since 2.0.0 */
    case Email = 'email';

    /** URL editor. @since 2.0.0 */
    case Url = 'url';

    /** Phone-like text editor. @since 2.0.0 */
    case Phone = 'phone';

    /** Exact money composite. @since 2.0.0 */
    case Money = 'money';

    /** Exact quantity composite. @since 2.0.0 */
    case Quantity = 'quantity';

    /** Zoned date-time composite. @since 2.0.0 */
    case ZonedDateTime = 'zoned_datetime';

    /** Cursor-backed media identity selector. @since 2.0.0 */
    case MediaReference = 'media_reference';

    /** Cursor-backed business-record identity selector. @since 2.0.0 */
    case EntityReference = 'entity_reference';

    /** Bounded structured-value editor. @since 2.0.0 */
    case Json = 'json';

    /** Bounded ordered collection editor. @since 2.0.0 */
    case Collection = 'collection';

    /** Write-only secret editor that is never prefilled. @since 2.0.0 */
    case Secret = 'secret';
}
