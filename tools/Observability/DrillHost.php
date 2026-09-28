<?php

declare(strict_types=1);

namespace Kumwe\App\Tools\Observability;

use RuntimeException;

/**
 * Runs the real application for the alert drills: web servers, workers, console commands and scrapes.
 *
 * The host owns every process it starts and stops only those, by the handle it holds. Each web server is PHP's
 * built-in server in front of the real front controller, bound to the loopback interface, with the metrics
 * endpoint enabled behind a scrape token that exists only for this run and with display of errors off, as a
 * production pool would run. Every child process gets the caller's environment plus the drill overrides, so the
 * application talks to the same database and Redis the caller configured; the Redis namespace is suffixed with
 * `.drills` so the metric counters of one drill run are not mixed with anything else using that server.
 *
 * @since  2.0.0
 */
final class DrillHost
{
    /**
     * Scrape job label, as a Prometheus scrape configuration would name the application target.
     *
     * @var    string
     * @since  2.0.0
     */
    public const JOB = 'kumwe';

    /**
     * Job and instance labels the probe's textfile samples are published under.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    public const PROBE_TARGET = ['instance' => 'probe', 'job' => 'kumwe-probe'];

    /**
     * Running processes by name.
     *
     * @var    array<string, array{process: resource, log: string}>
     * @since  2.0.0
     */
    private array $processes = [];

    /**
     * Environment every child process receives.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private array $environment;

    /**
     * Prepare the host; nothing is started until a method asks for it.
     *
     * @param  string                 $root       Repository root the application runs from.
     * @param  string                 $work       Drill working directory for logs, tokens, backups and fixtures.
     * @param  int                    $port       Port of the primary web server; the second replica uses the next.
     * @param  array<string, string>  $inherited  Environment to extend, normally the caller's.
     *
     * @throws  RuntimeException  When the working directory cannot be prepared.
     *
     * @since  2.0.0
     */
    public function __construct(
        public readonly string $root,
        public readonly string $work,
        public readonly int $port,
        array $inherited,
    ) {
        foreach ([$work, $work . '/logs', $work . '/backups', $work . '/secrets'] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException(sprintf('The drill directory %s cannot be created.', $directory));
            }
        }
        $token = bin2hex(random_bytes(24));
        $this->secret('metrics-token', $token);
        $this->secret('database-password', (string) ($inherited['DB_PASSWORD'] ?? ''));
        $this->secret('wrong-database-password', 'drill-' . bin2hex(random_bytes(12)));
        unset($inherited['KUMWE_METRICS_TOKEN']);
        $this->environment = array_merge($inherited, [
            'APP_BASE_URL' => sprintf('http://127.0.0.1:%d', $port),
            'APP_TRUSTED_HOSTS' => '127.0.0.1,localhost',
            'APP_DEBUG' => 'false',
            'KUMWE_METRICS_ENABLED' => 'true',
            'KUMWE_METRICS_TOKEN_FILE' => $work . '/secrets/metrics-token',
            'REDIS_NAMESPACE' => ($inherited['REDIS_NAMESPACE'] ?? 'kumwe') . '.drills',
            'KUMWE_OPERATIONS_STATUS_DIR' => $root . '/storage/operations',
        ]);
    }

    /**
     * Name the scrape target labels of the primary web server.
     *
     * @return  array{instance: string, job: string}  Labels a Prometheus scrape would attach.
     *
     * @since   2.0.0
     */
    public function target(): array
    {
        return ['instance' => sprintf('127.0.0.1:%d', $this->port), 'job' => self::JOB];
    }

    /**
     * Report one value of the environment the children receive.
     *
     * @param   string  $name  Variable name.
     *
     * @return  string  The value, or an empty string when unset.
     *
     * @since   2.0.0
     */
    public function env(string $name): string
    {
        return $this->environment[$name] ?? '';
    }

    /**
     * Start a web server on the loopback interface and wait until it answers.
     *
     * @param   string                 $name       Process name.
     * @param   int                    $port       Port to bind.
     * @param   array<string, string>  $overrides  Environment differences for this server only.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the server does not answer within twenty seconds.
     *
     * @since   2.0.0
     */
    public function startWeb(string $name, int $port, array $overrides = []): void
    {
        $this->spawn($name, [
            PHP_BINARY,
            '-d',
            'display_errors=0',
            '-S',
            sprintf('127.0.0.1:%d', $port),
            '-t',
            'public',
            'tools/browser-router.php',
        ], $overrides);
        $deadline = microtime(true) + 20;
        while (microtime(true) < $deadline) {
            if ($this->request('GET', sprintf('http://127.0.0.1:%d/health/live', $port))['status'] > 0) {
                return;
            }
            usleep(100_000);
        }

        throw new RuntimeException(sprintf('The %s web server did not answer on port %d.', $name, $port));
    }

    /**
     * Start a long-running child process whose output goes to its own log.
     *
     * @param   string                 $name       Process name; must not already be running.
     * @param   list<string>           $command    Argument vector.
     * @param   array<string, string>  $overrides  Environment differences for this process only.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the name is taken or the process cannot start.
     *
     * @since   2.0.0
     */
    public function spawn(string $name, array $command, array $overrides = []): void
    {
        if ($this->running($name)) {
            throw new RuntimeException(sprintf('The %s process is already running.', $name));
        }
        $log = sprintf('%s/logs/%s.log', $this->work, $name);
        $process = proc_open(
            $command,
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            $this->root,
            array_merge($this->environment, $overrides),
        );
        if (!is_resource($process)) {
            throw new RuntimeException(sprintf('The %s process could not be started.', $name));
        }
        $this->processes[$name] = ['process' => $process, 'log' => $log];
    }

    /**
     * Report whether a named child is still running.
     *
     * @param   string  $name  Process name.
     *
     * @return  bool  True while the process exists and has not exited.
     *
     * @since   2.0.0
     */
    public function running(string $name): bool
    {
        $entry = $this->processes[$name] ?? null;

        return $entry !== null && proc_get_status($entry['process'])['running'];
    }

    /**
     * Stop a named child with SIGTERM, escalating to SIGKILL after the grace period.
     *
     * @param   string  $name   Process name; stopping one that is not running is a no-op.
     * @param   float   $grace  Seconds to wait for a clean exit.
     *
     * @return  ?int  The exit code, or null when nothing was running.
     *
     * @since   2.0.0
     */
    public function stop(string $name, float $grace = 20.0): ?int
    {
        $entry = $this->processes[$name] ?? null;
        if ($entry === null) {
            return null;
        }
        unset($this->processes[$name]);
        $status = proc_get_status($entry['process']);
        if ($status['running']) {
            proc_terminate($entry['process'], 15);
            $deadline = microtime(true) + $grace;
            while (($status = proc_get_status($entry['process']))['running'] && microtime(true) < $deadline) {
                usleep(50_000);
            }
            if ($status['running']) {
                proc_terminate($entry['process'], 9);
                usleep(200_000);
                $status = proc_get_status($entry['process']);
            }
        }
        proc_close($entry['process']);

        return $status['running'] ? null : $status['exitcode'];
    }

    /**
     * Stop every child still running, in reverse start order.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function shutdown(): void
    {
        foreach (array_reverse(array_keys($this->processes)) as $name) {
            $this->stop($name);
        }
    }

    /**
     * Run a command to completion.
     *
     * @param   list<string>           $command    Argument vector, run from the repository root.
     * @param   array<string, string>  $overrides  Environment differences for this command only.
     * @param   int                    $timeout    Seconds before the command is killed.
     *
     * @return  array{exit: int, output: string}  Exit status and combined output (the tail when long).
     *
     * @throws  RuntimeException  When the command cannot start.
     *
     * @since   2.0.0
     */
    public function run(array $command, array $overrides = [], int $timeout = 300): array
    {
        $log = sprintf('%s/logs/command-%s.log', $this->work, bin2hex(random_bytes(4)));
        $process = proc_open(
            $command,
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']],
            $pipes,
            $this->root,
            array_merge($this->environment, $overrides),
        );
        if (!is_resource($process)) {
            throw new RuntimeException(sprintf('The command %s could not be started.', $command[0] ?? ''));
        }
        $deadline = microtime(true) + $timeout;
        while (($status = proc_get_status($process))['running'] && microtime(true) < $deadline) {
            usleep(50_000);
        }
        if ($status['running']) {
            proc_terminate($process, 9);
            usleep(200_000);
            $status = proc_get_status($process);
        }
        proc_close($process);
        $output = (string) @file_get_contents($log);
        @unlink($log);

        return [
            'exit' => $status['running'] ? 124 : (int) $status['exitcode'],
            'output' => strlen($output) > 4000 ? substr($output, -4000) : $output,
        ];
    }

    /**
     * Run a console command of the application.
     *
     * @param   list<string>           $arguments  Command name and arguments for `bin/kumwe`.
     * @param   array<string, string>  $overrides  Environment differences for this command only.
     *
     * @return  array{exit: int, output: string}  Exit status and output.
     *
     * @since   2.0.0
     */
    public function console(array $arguments, array $overrides = []): array
    {
        return $this->run(array_merge([PHP_BINARY, 'bin/kumwe'], $arguments), $overrides);
    }

    /**
     * Environment the recovery tools need to back up and verify this installation.
     *
     * @param   bool  $wrongPassword  Whether to hand the tools a password the database refuses.
     *
     * @return  array<string, string>  Variables for `tools/backup.sh` and `tools/restore-verify.sh`.
     *
     * @since   2.0.0
     */
    public function recoveryEnvironment(bool $wrongPassword = false): array
    {
        $trees = [];
        foreach (
            [
                'KUMWE_MEDIA_DIR' => 'storage/media',
                'KUMWE_PRIVATE_DIR' => 'storage/private',
                'KUMWE_EXTENSIONS_DIR' => 'extensions',
                'KUMWE_EXTENSION_ASSETS_DIR' => 'public/assets/extensions',
            ] as $variable => $relative
        ) {
            $path = $this->root . '/' . $relative;
            if (!is_dir($path)) {
                // A tree the installation has not created yet is backed up as the empty tree it is.
                $path = $this->work . '/empty/' . basename($relative);
                if (!is_dir($path)) {
                    mkdir($path, 0700, true);
                }
            }
            $trees[$variable] = $path;
        }

        return $trees + [
            'KUMWE_BACKUP_DIR' => $this->work . '/backups',
            'KUMWE_BACKUP_CONSISTENCY' => 'quiesced',
            'KUMWE_DB_DRIVER' => $this->env('DB_DRIVER') === '' ? 'mariadb' : $this->env('DB_DRIVER'),
            'KUMWE_DB_HOST' => $this->env('DB_HOST'),
            'KUMWE_DB_PORT' => $this->env('DB_PORT'),
            'KUMWE_DB_NAME' => $this->env('DB_NAME'),
            'KUMWE_DB_USER' => $this->env('DB_USER'),
            'KUMWE_DB_TABLE_PREFIX' => $this->env('DB_TABLE_PREFIX') === '' ? 'kumwe_' : $this->env('DB_TABLE_PREFIX'),
            'KUMWE_DB_PASSWORD_FILE' => $this->work . '/secrets/' . ($wrongPassword ? 'wrong-' : '') . 'database-password',
            'KUMWE_RELEASE' => '2.0.0',
        ];
    }

    /**
     * Send one HTTP request.
     *
     * @param   string                 $method   Request method.
     * @param   string                 $url      Absolute URL.
     * @param   array<string, string>  $headers  Request headers.
     * @param   ?string                $body     Request body, or null.
     *
     * @return  array{status: int, body: string}  Status (0 when nothing answered) and body.
     *
     * @since   2.0.0
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $options = [
            'method' => $method,
            'header' => implode("\r\n", $lines),
            'timeout' => 30,
            'ignore_errors' => true,
            'follow_location' => 0,
        ];
        if ($body !== null) {
            $options['content'] = $body;
        }
        set_error_handler(static fn (): bool => true);
        try {
            $response = file_get_contents($url, false, stream_context_create(['http' => $options]));
        } finally {
            restore_error_handler();
        }
        $raw = http_get_last_response_headers() ?? [];
        if ($response === false || $raw === []) {
            return ['status' => 0, 'body' => ''];
        }

        return [
            'status' => preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $raw[0], $match) === 1 ? (int) $match[1] : 0,
            'body' => $response,
        ];
    }

    /**
     * Scrape the primary web server's protected metrics endpoint.
     *
     * @return  ?list<array{name: string, labels: array<string, string>, value: float}>  Samples with the target
     *          labels attached, or null when the scrape failed.
     *
     * @since   2.0.0
     */
    public function scrape(): ?array
    {
        $response = $this->request('GET', sprintf('http://127.0.0.1:%d/metrics', $this->port), [
            'Authorization' => 'Bearer ' . trim((string) file_get_contents($this->work . '/secrets/metrics-token')),
        ]);
        if ($response['status'] !== 200) {
            return null;
        }
        $samples = [];
        foreach (Exposition::parse($response['body'], '/metrics') as $sample) {
            $sample['labels'] = $this->target() + $sample['labels'];
            $samples[] = $sample;
        }

        return $samples;
    }

    /**
     * Run the real synthetic probe command once against the primary web server.
     *
     * @param   string  $host  Host name the probe addresses, as the public name would be.
     *
     * @return  list<array{name: string, labels: array<string, string>, value: float}>  The probe's textfile samples.
     *
     * @throws  RuntimeException  When the probe wrote no textfile.
     *
     * @since   2.0.0
     */
    public function probe(string $host = 'localhost'): array
    {
        $textfile = $this->work . '/probe.prom';
        @unlink($textfile);
        $this->run([
            PHP_BINARY,
            'tools/synthetic-probe.php',
            sprintf('--base-url=http://%s:%d', $host, $this->port),
            '--textfile=' . $textfile,
            '--metrics-token-file=' . $this->work . '/secrets/metrics-token',
            '--timeout=15',
        ], [], 120);
        if (!is_file($textfile)) {
            throw new RuntimeException('The synthetic probe wrote no textfile.');
        }
        $samples = [];
        foreach (Exposition::parse((string) file_get_contents($textfile), 'probe textfile') as $sample) {
            $sample['labels'] = self::PROBE_TARGET + $sample['labels'];
            $samples[] = $sample;
        }

        return $samples;
    }

    /**
     * Scrape (and optionally probe) once and record the result as the timeline's next tick.
     *
     * A failed scrape is recorded as `up == 0` with none of the target's series, as Prometheus records it.
     *
     * @param   DrillTimeline  $timeline  Timeline to extend.
     * @param   string         $phase     Drill phase label.
     * @param   bool           $probe     Whether to run the synthetic probe too.
     *
     * @return  bool  Whether the scrape succeeded.
     *
     * @since   2.0.0
     */
    public function observe(DrillTimeline $timeline, string $phase, bool $probe = false): bool
    {
        $samples = $this->scrape();
        $up = $samples !== null;
        $samples ??= [];
        $samples[] = ['name' => 'up', 'labels' => $this->target(), 'value' => $up ? 1.0 : 0.0];
        if ($probe) {
            $samples = array_merge($samples, $this->probe());
        }
        $timeline->observe($phase, microtime(true), $samples);

        return $up;
    }

    /**
     * Read a child's log tail, for failure evidence.
     *
     * @param   string  $name   Process name.
     * @param   int     $bytes  Maximum bytes from the end.
     *
     * @return  string  The tail, or an empty string.
     *
     * @since   2.0.0
     */
    public function logTail(string $name, int $bytes = 2000): string
    {
        $contents = (string) @file_get_contents(sprintf('%s/logs/%s.log', $this->work, $name));

        return strlen($contents) > $bytes ? substr($contents, -$bytes) : $contents;
    }

    /**
     * Write a secret file readable only by this user.
     *
     * @param   string  $name   File name under the secrets directory.
     * @param   string  $value  Contents.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the file cannot be written.
     *
     * @since   2.0.0
     */
    private function secret(string $name, string $value): void
    {
        $path = $this->work . '/secrets/' . $name;
        if (file_put_contents($path, $value) === false || !chmod($path, 0600)) {
            throw new RuntimeException(sprintf('The drill secret %s cannot be written.', $name));
        }
    }
}
