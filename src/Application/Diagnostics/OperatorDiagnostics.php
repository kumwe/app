<?php

declare(strict_types=1);

namespace Kumwe\App\Application\Diagnostics;

use Kumwe\Context\Value\ExecutionContext;

/**
 * Read-only, installation-wide diagnostics shared by administrator and machine surfaces.
 *
 * @since  2.0.0
 */
interface OperatorDiagnostics
{
    /**
     * Explicit operator authority; ordinary site administration does not grant this capability.
     *
     * @var    string
     * @since  2.0.0
     */
    public const string CAPABILITY = 'system.diagnostics.read';

    /**
     * Fixed diagnostic vocabulary, keeping caller input out of SQL and metric labels.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const array SECTIONS = ['contention', 'queues', 'slow', 'backlog', 'retention'];

    /**
     * Answer one diagnostic question under declared result and server statement budgets.
     *
     * @param   ExecutionContext  $context  Authenticated operator; authority is checked before any probe.
     * @param   string            $section  One of the fixed diagnostic sections.
     *
     * @return  array<string, mixed>  Timestamp, cost bounds, availability and bounded scalar result rows.
     *
     * @throws  \Kumwe\Access\AuthorizationDenied  When installation-wide diagnostics authority is absent.
     * @throws  \InvalidArgumentException  When the section is unknown.
     *
     * @since   2.0.0
     */
    public function read(ExecutionContext $context, string $section = 'queues'): array;
}
