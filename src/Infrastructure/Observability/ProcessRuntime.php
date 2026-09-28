<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Observability;

/**
 * Names the role this PHP process plays, which every log line carries as `runtime`.
 *
 * One container serves the front controller, the CLI, the queue worker, the scheduler and the
 * integration worker, so the container cannot say which of them it is serving; the entry point can.
 * A web request arrives under a server SAPI, and a console process names its command as the first
 * argument, which is enough to tell the long-running roles apart from a one-shot command. The result is
 * a closed enumeration, because it is also the `runtime` label on `kumwe_build_info` and a label must
 * never take a value nobody declared.
 *
 * The backup and restore tools are shell programs outside this process; they stamp `backup` and
 * `restore` on their own JSON lines through `tools/recovery-common.sh`.
 *
 * @since  2.0.0
 */
final readonly class ProcessRuntime
{
    /**
     * A request served by PHP-FPM or another web SAPI.
     *
     * @var    string
     * @since  2.0.0
     */
    public const HTTP = 'http';

    /**
     * A one-shot console command.
     *
     * @var    string
     * @since  2.0.0
     */
    public const CONSOLE = 'console';

    /**
     * The long-running `queue:work` job worker.
     *
     * @var    string
     * @since  2.0.0
     */
    public const WORKER = 'worker';

    /**
     * The `schedule:run` scheduler.
     *
     * @var    string
     * @since  2.0.0
     */
    public const SCHEDULER = 'scheduler';

    /**
     * The `integration:work` outbox, inbox and process-work dispatcher.
     *
     * @var    string
     * @since  2.0.0
     */
    public const INTEGRATION = 'integration';

    /**
     * The `mcp:serve` machine-surface process.
     *
     * @var    string
     * @since  2.0.0
     */
    public const MCP = 'mcp';

    /**
     * Every value `detect()` can return.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const VALUES = [
        self::HTTP,
        self::CONSOLE,
        self::WORKER,
        self::SCHEDULER,
        self::INTEGRATION,
        self::MCP,
    ];

    /**
     * Console commands that run as a distinct long-lived role rather than as a one-shot command.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const COMMANDS = [
        'queue:work' => self::WORKER,
        'schedule:run' => self::SCHEDULER,
        'integration:work' => self::INTEGRATION,
        'mcp:serve' => self::MCP,
    ];

    /**
     * Resolve the role from the SAPI name and the process argument vector.
     *
     * @param   string        $sapi       Value of `PHP_SAPI` for this process.
     * @param   list<string>  $arguments  Process argument vector, script path first.
     *
     * @return  string  One of `VALUES`.
     *
     * @since   2.0.0
     */
    public static function detect(string $sapi, array $arguments): string
    {
        if ($sapi !== 'cli' && $sapi !== 'phpdbg') {
            return self::HTTP;
        }

        return self::COMMANDS[$arguments[1] ?? ''] ?? self::CONSOLE;
    }
}
