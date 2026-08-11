<?php

declare(strict_types=1);

namespace Kumwe\CMS\InterfaceStandard;

/**
 * Version of the semantic interface contract a surface has been admitted against.
 *
 * A declaration names an exact supported version rather than a floating latest version, so a future
 * incompatible standard is refused until a deliberate compatibility path is available.
 *
 * @since  2.0.0
 */
enum InterfaceStandardVersion: string
{
    /**
     * First stable Kumwe Interface Standard contract.
     *
     * @since  2.0.0
     */
    case Kis1 = 'kis-1.0';
}
