<?php

declare(strict_types=1);

namespace Kumwe\App\BusinessDefinition\Domain;

/**
 * Handling class a business field declares, and with it whether the runtime ever emits its values.
 *
 * The two upper levels are enforced and the three lower ones are declarative. A field at `Restricted` or
 * `Secret` is omitted from the record read path and from revision snapshots,
 * is refused as a search, filter, sort, or report target by the query compiler, cannot be declared
 * sortable at all, and is omitted from the audit metadata listing which fields a write changed.
 * `Public`, `Internal`, and `Confidential` classify the data for the people running the installation
 * without narrowing anything the runtime returns. Fields default to `Internal`, an encrypted
 * `core.secret` field must declare `Secret`, and changing a published field's class is reported as a
 * behaviour-changing difference because the values a caller can already see change with it.
 *
 * @since  2.0.0
 */
enum Sensitivity: string
{
    /**
     * Carries no handling obligation of its own beyond the visibility flags the field already declares.
     *
     * @since  2.0.0
     */
    case Public = 'public';

    /**
     * The default: ordinary operational data, meant for authenticated users of the installation.
     *
     * @since  2.0.0
     */
    case Internal = 'internal';

    /**
     * Commercially or personally sensitive data whose exposure is an operator policy matter.
     *
     * The runtime still returns these values in full. The class exists so a reviewer can find the fields
     * that deserve a policy decision without the definition having to redact them outright.
     *
     * @since  2.0.0
     */
    case Confidential = 'confidential';

    /**
     * The first enforced level: values are omitted on read and the field cannot be queried against.
     *
     * @since  2.0.0
     */
    case Restricted = 'restricted';

    /**
     * Credential-grade data, omitted exactly as `Restricted` is and required of encrypted secret fields.
     *
     * @since  2.0.0
     */
    case Secret = 'secret';
}
