<?php

declare(strict_types=1);

namespace Kumwe\App\Tools\Observability;

use RuntimeException;

/**
 * The operator drills: one per page-severity alert, each inducing its condition in the real application.
 *
 * Every drill follows the same shape. It observes the healthy installation and declares that the alert is quiet;
 * it causes the condition the way production would meet it (a stopped process, a stale marker, a
 * misconfigured replica, a failing backup, a tampered snapshot, a full volume, a burst of bad sign-ins); it
 * observes long enough for the rule's `for:` clause and declares that the alert fires; it performs the
 * recovery the alert's runbook section prescribes; and it declares that the alert clears. The observations are
 * real scrapes of the protected `/metrics` endpoint and real runs of the synthetic probe. Only the backup-age
 * drill holds synthetic time, because three hours cannot be waited for; it holds the last real scrape.
 *
 * The runbook section of each alert names its drill and contains the recovery phrase the drill performs, so a
 * runbook that stops describing a working recovery fails the drill run as surely as a rule that stops firing.
 *
 * @since  2.0.0
 */
final class AlertDrills
{
    /**
     * Metrics every drill timeline keeps besides those its alert reads, so preconditions can be asserted and
     * the fixture shows the replica's own view.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const WITNESSES = ['up', 'kumwe_ready', 'kumwe_metrics_collection_failed'];

    /**
     * Probe checks the drills configure: every check except the authenticated API read, which needs a token.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const PROBE_CHECKS = ['liveness', 'readiness', 'public_page', 'metrics'];

    /**
     * File the storage drill fills the media volume with.
     *
     * @var    string
     * @since  2.0.0
     */
    public const FILL_FILE = '.kumwe-drill-fill';

    /**
     * List every drill in the order a run performs them.
     *
     * @return  array<string, AlertDrill>  Drills keyed by identifier.
     *
     * @since   2.0.0
     */
    public static function catalogue(): array
    {
        $drills = [
            self::scrapeTargetDown(),
            self::readinessFailing(),
            self::syntheticProbeFailing(),
            self::serverErrorRateCritical(),
            self::noLiveWorker(),
            self::extensionRuntimeUntrusted(),
            self::authenticationFailureBurst(),
            self::backupStale(),
            self::restoreFailed(),
            self::storageNearlyFull(),
        ];
        $keyed = [];
        foreach ($drills as $drill) {
            $keyed[$drill->id] = $drill;
        }

        return $keyed;
    }

    /**
     * Bring the host to the baseline every drill starts from: runtime materialized, watcher and web server up.
     *
     * @param   DrillHost  $host  Host to prepare.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the runtime cannot be materialized or the replica never becomes ready.
     *
     * @since   2.0.0
     */
    public static function baseline(DrillHost $host): void
    {
        $status = $host->root . '/storage/operations';
        if (!is_dir($status) && !mkdir($status, 0775, true) && !is_dir($status)) {
            throw new RuntimeException('storage/operations cannot be created.');
        }
        if (!$host->running('watcher')) {
            $materialized = $host->console(['extension:runtime:materialize']);
            if ($materialized['exit'] !== 0) {
                $materialized = $host->console(['extension:runtime:materialize', '--repair']);
            }
            self::check($materialized['exit'] === 0, 'the extension runtime could not be materialized: '
                . trim($materialized['output']));
            self::startWatcher($host);
        }
        if (!$host->running('web')) {
            $host->startWeb('web', $host->port);
        }
        self::waitFor(
            static fn (): bool => $host->request('GET', self::url($host, '/health/ready'))['status'] === 200,
            45,
            'the replica did not report ready',
        );
    }

    /**
     * List the metric names a drill's timeline keeps.
     *
     * @param   AlertRule   $rule   The drill's alert.
     * @param   AlertDrill  $drill  The drill, for its own witnesses.
     *
     * @return  list<string>  Metrics the expression reads, plus the witnesses.
     *
     * @since   2.0.0
     */
    public static function metrics(AlertRule $rule, AlertDrill $drill): array
    {
        $names = array_merge(self::WITNESSES, $drill->witnesses);
        foreach (PromQlAnalysis::selectors($rule->tree) as $selector) {
            if (is_string($selector['name'] ?? null)) {
                $names[] = $selector['name'];
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Check the drill catalogue against the rules and the runbook without running anything.
     *
     * Every page-severity alert needs exactly one drill; every drill must name a page alert; and the alert's
     * runbook section must name its drill, and its **Act** step must contain the recovery phrase the drill
     * performs, so the operator is told to do what the drill proved works. A `Drill:` line in
     * any other section must not name a drill that does not exist, so the runbook never promises evidence
     * nothing produces.
     *
     * @param   string                    $root    Repository root.
     * @param   array<string, AlertRule>  $alerts  Declared alerts keyed by name.
     *
     * @return  list<string>  Problems, empty when the catalogue and the runbook agree.
     *
     * @since   2.0.0
     */
    public static function problems(string $root, array $alerts): array
    {
        $problems = [];
        $drills = self::catalogue();
        $byAlert = [];
        foreach ($drills as $drill) {
            $rule = $alerts[$drill->alert] ?? null;
            if ($rule === null || $rule->severity() !== 'page') {
                $problems[] = sprintf('drill %s names %s, which is not a page-severity alert', $drill->id, $drill->alert);
                continue;
            }
            $byAlert[$drill->alert][] = $drill->id;
            $section = self::runbook($root, $rule);
            if ($section === null) {
                $problems[] = sprintf('%s: its runbook annotation does not resolve to a section', $drill->alert);
            } elseif (!str_contains($section['text'], '`' . $drill->id . '`') || !str_contains(self::act($section['text']), $drill->action)) {
                $problems[] = sprintf(
                    '%s: its runbook section must name the drill `%s` and prescribe "%s" in its Act step',
                    $drill->alert,
                    $drill->id,
                    $drill->action,
                );
            }
        }
        foreach ($alerts as $name => $rule) {
            if ($rule->severity() === 'page' && count($byAlert[$name] ?? []) !== 1) {
                $problems[] = sprintf('%s: a page-severity alert needs exactly one drill in tools/Observability/AlertDrills.php', $name);
            }
        }
        preg_match_all('/\*\*Drill:\*\* `([a-z0-9-]+)`/', (string) @file_get_contents($root . '/docs/operations/runbooks.md'), $named);
        foreach ($named[1] as $id) {
            if (!isset($drills[$id])) {
                $problems[] = sprintf('docs/operations/runbooks.md: names the drill `%s`, which does not exist', $id);
            }
        }

        return $problems;
    }

    /**
     * Extract the **Act** step of a runbook section.
     *
     * @param   string  $section  Section text.
     *
     * @return  string  The Act bullet with its continuation lines, or an empty string when there is none.
     *
     * @since   2.0.0
     */
    public static function act(string $section): string
    {
        return preg_match('/^- \*\*Act:\*\*(.*?)(?=^- \*\*|\z)/ms', $section, $match) === 1 ? trim($match[1]) : '';
    }

    /**
     * Return the runbook section an alert's `runbook` annotation links to.
     *
     * @param   string     $root  Repository root.
     * @param   AlertRule  $rule  Alert whose section is wanted.
     *
     * @return  ?array{anchor: string, heading: string, text: string}  The section, or null when it does not resolve.
     *
     * @since   2.0.0
     */
    public static function runbook(string $root, AlertRule $rule): ?array
    {
        $link = $rule->annotations['runbook'] ?? '';
        if (preg_match('/^(docs\/operations\/[a-z0-9-]+\.md)#([a-z0-9-]+)$/D', $link, $match) !== 1) {
            return null;
        }
        $document = (string) @file_get_contents($root . '/' . $match[1]);
        $sections = preg_split('/^(?=#{2,4} )/m', $document) ?: [];
        foreach ($sections as $section) {
            if (preg_match('/^#{2,4} (.+)$/m', $section, $heading) !== 1) {
                continue;
            }
            $anchor = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', trim($heading[1])), '-'));
            if ($anchor === $match[2]) {
                return ['anchor' => $link, 'heading' => trim($heading[1]), 'text' => $section];
            }
        }

        return null;
    }

    /**
     * Stop the replica's web server, observe the refused scrapes, then start it again.
     *
     * @return  AlertDrill  The drill.
     *
     * @since   2.0.0
     */
    private static function scrapeTargetDown(): AlertDrill
    {
        return new AlertDrill(
            'scrape-target-down',
            'KumweScrapeTargetDown',
            'Stops the replica\'s web server, so every scrape of /metrics is refused at the socket.',
            'Starts the web server again, as restarting the replica\'s web container does.',
            'restart the replica\'s web and app containers',
            static fn (DrillHost $host): array => [$host->target()],
            static function (DrillHost $host, DrillTimeline $timeline): void {
                self::ticks($host, $timeline, 'healthy', 3);
                self::check($timeline->latest('up', $host->target()) === 1.0, 'the healthy scrape failed');
                $timeline->expect('healthy', false);
                $host->stop('web');
                self::ticks($host, $timeline, 'induced', 7);
                self::check($timeline->latest('up', $host->target()) === 0.0, 'a scrape succeeded with no web server');
                $timeline->expect('firing', true);
                $host->startWeb('web', $host->port);
                self::ticks($host, $timeline, 'recovered', 2);
                self::check($timeline->latest('up', $host->target()) === 1.0, 'the restarted replica was not scraped');
                $timeline->expect('cleared', false);
            },
        );
    }

    /**
     * Stop the runtime watcher until the readiness marker ages out, then restore it.
     *
     * @return  AlertDrill  The drill.
     *
     * @since   2.0.0
     */
    private static function readinessFailing(): AlertDrill
    {
        return new AlertDrill(
            'readiness-failing',
            'KumweReadinessFailing',
            'Stops the extension runtime watcher and waits until the signed readiness marker is older than the '
            . 'thirty-second window, so /health/ready answers 503 and kumwe_ready reports 0.',
            'Materializes the runtime, which publishes a fresh marker, and starts the watcher again.',
            'extension:runtime:watch',
            static fn (DrillHost $host): array => [$host->target()],
            static function (DrillHost $host, DrillTimeline $timeline): void {
                self::ticks($host, $timeline, 'healthy', 3);
                self::check($timeline->latest('kumwe_ready', $host->target()) === 1.0, 'the replica was not ready');
                $timeline->expect('healthy', false);
                $host->stop('watcher');
                self::waitFor(
                    static fn (): bool => $host->request('GET', self::url($host, '/health/ready'))['status'] === 503,
                    60,
                    'readiness did not fail after the watcher stopped',
                );
                self::ticks($host, $timeline, 'induced', 12);
                self::check($timeline->latest('kumwe_ready', $host->target()) === 0.0, 'kumwe_ready stayed 1');
                $timeline->expect('firing', true);
                $materialized = $host->console(['extension:runtime:materialize']);
                self::check($materialized['exit'] === 0, 'materialization failed: ' . $materialized['output']);
                self::startWatcher($host);
                self::ticks($host, $timeline, 'recovered', 2);
                self::check($timeline->latest('kumwe_ready', $host->target()) === 1.0, 'readiness did not recover');
                $timeline->expect('cleared', false);
            },
            static function (DrillHost $host): void {
                if (!$host->running('watcher')) {
                    self::startWatcher($host);
                }
            },
        );
    }

    /**
     * Restart the replica with a trusted-host list that drops the public name, then with the right one.
     *
     * @return  AlertDrill  The drill.
     *
     * @since   2.0.0
     */
    private static function syntheticProbeFailing(): AlertDrill
    {
        return new AlertDrill(
            'synthetic-probe-failing',
            'KumweSyntheticProbeFailing',
            'Restarts the replica with APP_TRUSTED_HOSTS naming only its address, as a domain change that forgot '
            . 'the public name would: the probe, addressing the public name, is refused on every check while the '
            . 'scrape by address and every internal signal stay green.',
            'Restarts the replica with the public name back in APP_TRUSTED_HOSTS.',
            'APP_TRUSTED_HOSTS',
            static fn (DrillHost $host): array => array_map(
                static fn (string $check): array => ['check' => $check],
                self::PROBE_CHECKS,
            ),
            static function (DrillHost $host, DrillTimeline $timeline): void {
                self::ticks($host, $timeline, 'healthy', 3, probe: true);
                self::probeResults($host, $timeline, 1.0);
                $timeline->expect('healthy', false);
                $host->stop('web');
                $host->startWeb('web', $host->port, ['APP_TRUSTED_HOSTS' => '127.0.0.1']);
                self::ticks($host, $timeline, 'induced', 7, probe: true);
                self::probeResults($host, $timeline, 0.0);
                self::check(
                    $timeline->latest('up', $host->target()) === 1.0
                    && $timeline->latest('kumwe_ready', $host->target()) === 1.0,
                    'an internal signal failed too, so the probe proved nothing the scrape could not see',
                );
                $timeline->expect('firing', true);
                $host->stop('web');
                $host->startWeb('web', $host->port);
                self::ticks($host, $timeline, 'recovered', 2, probe: true);
                self::probeResults($host, $timeline, 1.0);
                $timeline->expect('cleared', false);
            },
            static function (DrillHost $host): void {
                $host->stop('web');
                $host->startWeb('web', $host->port);
            },
        );
    }

    /**
     * Serve a share of traffic from a replica whose table prefix does not match the installation.
     *
     * @return  AlertDrill  The drill.
     *
     * @since   2.0.0
     */
    private static function serverErrorRateCritical(): AlertDrill
    {
        $traffic = static function (DrillHost $host): array {
            $statuses = ['primary' => [], 'replica' => []];
            for ($request = 0; $request < 5; $request++) {
                $statuses['primary'][] = $host->request('GET', self::url($host, '/'))['status'];
                $statuses['replica'][] = $host->request('GET', self::url($host, '/', 1))['status'];
            }

            return $statuses;
        };

        return new AlertDrill(
            'server-error-rate-critical',
            'KumweServerErrorRateCritical',
            'Starts a second replica and sends it half the public traffic, then restarts it with a DB_TABLE_PREFIX '
            . 'that does not match the installation, as a mis-templated deployment would: it answers every request '
            . 'with a 5xx while its liveness stays green.',
            'Restarts the second replica with the installation\'s configuration.',
            'redeploy it with the installation\'s configuration',
            static fn (DrillHost $host): array => [[]],
            static function (DrillHost $host, DrillTimeline $timeline) use ($traffic): void {
                $host->startWeb('replica', $host->port + 1);
                $healthy = static function (DrillHost $host) use ($traffic): void {
                    foreach (array_merge(...array_values($traffic($host))) as $status) {
                        self::check($status > 0 && $status < 500, sprintf('healthy traffic answered %d', $status));
                    }
                };
                self::ticks($host, $timeline, 'healthy', 6, $healthy);
                $timeline->expect('healthy', false);
                $host->stop('replica');
                $prefix = $host->env('DB_TABLE_PREFIX') === '' ? 'kumwe_' : $host->env('DB_TABLE_PREFIX');
                $host->startWeb('replica', $host->port + 1, ['DB_TABLE_PREFIX' => $prefix . 'absent_']);
                self::ticks($host, $timeline, 'induced', 8, static function (DrillHost $host) use ($traffic): void {
                    $statuses = $traffic($host);
                    foreach ($statuses['replica'] as $status) {
                        self::check($status >= 500, sprintf('the misconfigured replica answered %d', $status));
                    }
                    foreach ($statuses['primary'] as $status) {
                        self::check($status > 0 && $status < 500, sprintf('the healthy replica answered %d', $status));
                    }
                });
                $timeline->expect('firing', true);
                $host->stop('replica');
                $host->startWeb('replica', $host->port + 1);
                self::ticks($host, $timeline, 'recovered', 8, $healthy);
                $timeline->expect('cleared', false);
            },
            static function (DrillHost $host): void {
                $host->stop('replica');
            },
        );
    }

    /**
     * Drain the only worker cleanly, then start it again.
     *
     * @return  AlertDrill  The drill.
     *
     * @since   2.0.0
     */
    private static function noLiveWorker(): AlertDrill
    {
        return new AlertDrill(
            'no-live-worker',
            'KumweNoLiveWorker',
            'Starts a real queue worker, then sends it SIGTERM: it drains cleanly and removes its own heartbeat row, '
            . 'which leaves no heartbeat for an age threshold to trip on.',
            'Starts the worker again with bin/kumwe queue:work.',
            'bin/kumwe queue:work',
            static fn (DrillHost $host): array => [[]],
            static function (DrillHost $host, DrillTimeline $timeline): void {
                self::startWorker($host);
                self::ticks($host, $timeline, 'healthy', 3);
                self::check(
                    ($timeline->latest('kumwe_worker_heartbeat_age_seconds', $host->target()) ?? INF) < 300,
                    'the worker heartbeat is not fresh',
                );
                $timeline->expect('healthy', false);
                self::check($host->stop('worker') === 0, 'the worker did not drain cleanly');
                self::ticks($host, $timeline, 'induced', 7);
                self::check(
                    $timeline->latest('kumwe_workers_registered', $host->target()) === 0.0
                    || ($timeline->latest('kumwe_worker_heartbeat_age_seconds', $host->target()) ?? 0.0) > 300,
                    'a fresh heartbeat survived the worker',
                );
                $timeline->expect('firing', true);
                self::startWorker($host);
                self::ticks($host, $timeline, 'recovered', 2);
                $timeline->expect('cleared', false);
            },
            static function (DrillHost $host): void {
                $host->stop('worker');
            },
        );
    }

    /**
     * Tamper with the replica's signed runtime map with the watcher stopped, then materialize it.
     *
     * @return  AlertDrill  The drill.
     *
     * @since   2.0.0
     */
    private static function extensionRuntimeUntrusted(): AlertDrill
    {
        return new AlertDrill(
            'extension-runtime-untrusted',
            'KumweExtensionRuntimeUntrusted',
            'Stops the runtime watcher and alters one byte-range of the compiled extension runtime map, so its '
            . 'signature no longer verifies and the replica serves without a trusted runtime.',
            'Materializes the trusted generation and starts the watcher again.',
            'extension:runtime:materialize',
            static fn (DrillHost $host): array => [$host->target()],
            static function (DrillHost $host, DrillTimeline $timeline): void {
                self::ticks($host, $timeline, 'healthy', 3);
                self::check(
                    $timeline->latest('kumwe_extension_runtime_trusted', $host->target()) === 1.0,
                    'the replica did not report a trusted runtime',
                );
                $timeline->expect('healthy', false);
                $host->stop('watcher');
                $map = $host->root . '/storage/cache/extensions.json';
                self::check(is_file($map) && file_put_contents($map, "\n", FILE_APPEND) !== false, 'no runtime map');
                self::ticks($host, $timeline, 'induced', 7);
                self::check(
                    $timeline->latest('kumwe_extension_runtime_trusted', $host->target()) === 0.0,
                    'the altered runtime was still trusted',
                );
                $timeline->expect('firing', true);
                $materialized = $host->console(['extension:runtime:materialize']);
                self::check($materialized['exit'] === 0, 'materialization failed: ' . $materialized['output']);
                self::startWatcher($host);
                self::ticks($host, $timeline, 'recovered', 2);
                self::check(
                    $timeline->latest('kumwe_extension_runtime_trusted', $host->target()) === 1.0,
                    'the runtime was not trusted again',
                );
                $timeline->expect('cleared', false);
            },
            static function (DrillHost $host): void {
                if (!$host->running('watcher')) {
                    $host->console(['extension:runtime:materialize', '--repair']);
                    self::startWatcher($host);
                }
            },
        );
    }

    /**
     * Send a burst of failed administrator sign-ins across many accounts, then stop.
     *
     * @return  AlertDrill  The drill.
     *
     * @since   2.0.0
     */
    private static function authenticationFailureBurst(): AlertDrill
    {
        $failures = static fn (int $count): \Closure => static function (DrillHost $host) use ($count): void {
            for ($attempt = 0; $attempt < $count; $attempt++) {
                $csrf = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
                $status = $host->request('POST', self::url($host, '/administrator/login'), [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Cookie' => 'kumwe_administrator_login_csrf=' . $csrf,
                ], http_build_query([
                    '_csrf' => $csrf,
                    'email' => sprintf('drill-%s@example.com', bin2hex(random_bytes(6))),
                    'password' => bin2hex(random_bytes(12)),
                ]))['status'];
                self::check($status === 401, sprintf('a wrong sign-in answered %d instead of 401', $status));
            }
        };

        return new AlertDrill(
            'authentication-failure-burst',
            'KumweAuthenticationFailureBurst',
            'Sends fifteen failed administrator sign-ins a minute, each for a different account, as a password '
            . 'spraying run does; no account and origin pair reaches its own throttle.',
            'Stops the burst; the ingress block the runbook prescribes has the same effect.',
            'block the source at the ingress',
            static fn (DrillHost $host): array => [[]],
            static function (DrillHost $host, DrillTimeline $timeline) use ($failures): void {
                self::ticks($host, $timeline, 'healthy', 3, $failures(1));
                $timeline->expect('healthy', false);
                self::ticks($host, $timeline, 'induced', 9, $failures(15));
                $timeline->expect('firing', true);
                self::ticks($host, $timeline, 'recovered', 10, $failures(1));
                $timeline->expect('cleared', false);
            },
        );
    }

    /**
     * Record a successful backup, then a failing one, let three hours pass, then back up again.
     *
     * @return  AlertDrill  The drill.
     *
     * @since   2.0.0
     */
    private static function backupStale(): AlertDrill
    {
        return new AlertDrill(
            'backup-stale',
            'KumweBackupStale',
            'Runs a real tools/backup.sh against the database, then runs it again with a database password the '
            . 'server refuses, as after a credential rotation the backup job missed; the timeline then holds the '
            . 'last scrape for three hours and twenty minutes with no further backup.',
            'Runs tools/backup.sh with the right credential.',
            'tools/backup.sh',
            static fn (DrillHost $host): array => [[]],
            static function (DrillHost $host, DrillTimeline $timeline): void {
                self::backup($host);
                self::ticks($host, $timeline, 'healthy', 3);
                $success = ['operation' => 'backup'] + $host->target();
                self::check(
                    ($timeline->latest('kumwe_recovery_last_success_timestamp_seconds', $success) ?? 0.0) > 0,
                    'the successful backup was not recorded',
                );
                $timeline->expect('healthy', false);
                self::nextSecond();
                $refused = $host->run(['bash', 'tools/backup.sh'], $host->recoveryEnvironment(true), 900);
                self::check($refused['exit'] !== 0, 'a backup with a refused password succeeded');
                self::ticks($host, $timeline, 'induced', 1);
                self::check(
                    ($timeline->latest('kumwe_recovery_last_failure_timestamp_seconds', $success) ?? 0.0)
                    > ($timeline->latest('kumwe_recovery_last_success_timestamp_seconds', $success) ?? 0.0),
                    'the failed backup was not recorded',
                );
                $timeline->hold('induced', 200, microtime(true));
                $timeline->expect('firing', true);
                self::nextSecond();
                self::backup($host);
                self::ticks($host, $timeline, 'recovered', 2);
                $timeline->expect('cleared', false);
            },
            witnesses: ['kumwe_recovery_last_failure_timestamp_seconds'],
        );
    }

    /**
     * Verify an intact snapshot, verify a tampered one, then verify the intact one again.
     *
     * @return  AlertDrill  The drill.
     *
     * @since   2.0.0
     */
    private static function restoreFailed(): AlertDrill
    {
        return new AlertDrill(
            'restore-failed',
            'KumweRestoreFailed',
            'Takes two real backups, appends bytes to the second one\'s database dump, and runs '
            . 'tools/restore-verify.sh on it, which refuses the checksum.',
            'Verifies the previous intact snapshot with tools/restore-verify.sh.',
            'tools/restore-verify.sh',
            static fn (DrillHost $host): array => [['operation' => 'restore_verify']],
            static function (DrillHost $host, DrillTimeline $timeline): void {
                $intact = self::backup($host);
                self::verify($host, $intact, true);
                self::ticks($host, $timeline, 'healthy', 3);
                foreach (['restore', 'restore_verify'] as $operation) {
                    $labels = ['operation' => $operation] + $host->target();
                    self::check(
                        ($timeline->latest('kumwe_recovery_last_failure_timestamp_seconds', $labels) ?? 0.0)
                        <= ($timeline->latest('kumwe_recovery_last_success_timestamp_seconds', $labels) ?? 0.0),
                        sprintf('an earlier %s failure is still the latest outcome in storage/operations', $operation),
                    );
                }
                $timeline->expect('healthy', false);
                self::nextSecond();
                $tampered = self::backup($host);
                self::check(
                    file_put_contents($tampered . '/database.dump', "-- altered by the restore-failed drill\n", FILE_APPEND)
                    !== false,
                    'the second snapshot could not be altered',
                );
                self::verify($host, $tampered, false);
                self::ticks($host, $timeline, 'induced', 4);
                $timeline->expect('firing', true);
                self::nextSecond();
                self::verify($host, $intact, true);
                self::ticks($host, $timeline, 'recovered', 2);
                $timeline->expect('cleared', false);
            },
        );
    }

    /**
     * Fill the media volume to two percent free, then remove the fill.
     *
     * @return  AlertDrill  The drill.
     *
     * @since   2.0.0
     */
    private static function storageNearlyFull(): AlertDrill
    {
        $ratio = static function (DrillHost $host, DrillTimeline $timeline): float {
            $labels = ['volume' => 'media'] + $host->target();
            $free = $timeline->latest('kumwe_storage_free_bytes', $labels);
            $total = $timeline->latest('kumwe_storage_capacity_bytes', $labels);
            self::check($free !== null && $total !== null && $total > 0, 'the media volume is not reported');

            return (float) $free / (float) $total;
        };

        return new AlertDrill(
            'storage-nearly-full',
            'KumweStorageNearlyFull',
            'Writes one file to the media volume until two percent of it is free, as an unbounded import would.',
            'Deletes the file, freeing the space.',
            'free space or grow the volume',
            static fn (DrillHost $host): array => [['volume' => 'media'] + $host->target()],
            static function (DrillHost $host, DrillTimeline $timeline) use ($ratio): void {
                self::ticks($host, $timeline, 'healthy', 3);
                self::check($ratio($host, $timeline) >= 0.05, 'the media volume is already nearly full');
                $timeline->expect('healthy', false);
                self::fill($host->root . '/storage/media');
                self::ticks($host, $timeline, 'induced', 12);
                self::check($ratio($host, $timeline) < 0.05, 'the media volume did not fill');
                $timeline->expect('firing', true);
                @unlink($host->root . '/storage/media/' . self::FILL_FILE);
                self::ticks($host, $timeline, 'recovered', 2);
                self::check($ratio($host, $timeline) >= 0.05, 'the media volume was not freed');
                $timeline->expect('cleared', false);
            },
            static function (DrillHost $host): void {
                @unlink($host->root . '/storage/media/' . self::FILL_FILE);
            },
            static function (DrillHost $host): ?string {
                $media = $host->root . '/storage/media';
                $parent = @stat($host->root . '/storage');
                $volume = @stat($media);
                $total = @disk_total_space($media);
                if ($parent === false || $volume === false || $parent['dev'] === $volume['dev']) {
                    return 'storage/media is not a separate volume; mount a small one there (CI mounts a tmpfs)';
                }
                if ($total === false || $total > 1024 ** 3) {
                    return 'storage/media is larger than 1 GiB; the drill fills it and needs a small volume';
                }

                return null;
            },
            scope: ['volume' => 'media'],
        );
    }

    /**
     * Observe a number of ticks, running an action before each.
     *
     * @param   DrillHost                 $host      Host to observe.
     * @param   DrillTimeline             $timeline  Timeline to extend.
     * @param   string                    $phase     Phase label.
     * @param   int                       $count     Number of ticks.
     * @param   ?\Closure(DrillHost): void $before   Action performed before each observation.
     * @param   bool                      $probe     Whether to run the synthetic probe each tick.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function ticks(
        DrillHost $host,
        DrillTimeline $timeline,
        string $phase,
        int $count,
        ?\Closure $before = null,
        bool $probe = false,
    ): void {
        for ($tick = 0; $tick < $count; $tick++) {
            if ($before !== null) {
                $before($host);
            }
            $host->observe($timeline, $phase, $probe);
        }
    }

    /**
     * Assert every configured probe check reported the expected result in the latest tick.
     *
     * @param   DrillHost      $host      Host whose probe ran.
     * @param   DrillTimeline  $timeline  Timeline holding the probe samples.
     * @param   float          $expected  1.0 for passing, 0.0 for failing.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function probeResults(DrillHost $host, DrillTimeline $timeline, float $expected): void
    {
        foreach (self::PROBE_CHECKS as $check) {
            $value = $timeline->latest('kumwe_probe_success', DrillHost::PROBE_TARGET + ['check' => $check]);
            self::check($value === $expected, sprintf('the probe check %s reported %s', $check, var_export($value, true)));
        }
    }

    /**
     * Start the runtime watcher as the deployment's sidecar would.
     *
     * @param   DrillHost  $host  Host to start it on.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function startWatcher(DrillHost $host): void
    {
        $host->spawn('watcher', [PHP_BINARY, 'bin/kumwe', 'extension:runtime:watch', '--interval=5']);
    }

    /**
     * Start one queue worker and wait until its heartbeat is published.
     *
     * @param   DrillHost  $host  Host to start it on.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function startWorker(DrillHost $host): void
    {
        $host->spawn('worker', [PHP_BINARY, 'bin/kumwe', 'queue:work', '--sleep-ms=500']);
        self::waitFor(static function () use ($host): bool {
            foreach ($host->scrape() ?? [] as $sample) {
                if ($sample['name'] === 'kumwe_worker_heartbeat_age_seconds' && $sample['value'] < 60) {
                    return true;
                }
            }

            return false;
        }, 60, 'the worker published no heartbeat: ' . $host->logTail('worker'));
    }

    /**
     * Take a real backup and return its directory.
     *
     * @param   DrillHost  $host  Host whose installation is backed up.
     *
     * @return  string  Absolute snapshot directory.
     *
     * @since   2.0.0
     */
    private static function backup(DrillHost $host): string
    {
        $result = $host->run(['bash', 'tools/backup.sh'], $host->recoveryEnvironment(), 900);
        self::check($result['exit'] === 0, 'tools/backup.sh failed: ' . $result['output']);
        $prefix = $host->work . '/backups/kumwe-';
        foreach (array_reverse(explode("\n", $result['output'])) as $line) {
            if (str_starts_with(trim($line), $prefix)) {
                return trim($line);
            }
        }

        throw new RuntimeException('tools/backup.sh did not print the snapshot directory.');
    }

    /**
     * Run the real snapshot verification and assert its outcome.
     *
     * @param   DrillHost  $host       Host to run it on.
     * @param   string     $snapshot   Snapshot directory.
     * @param   bool       $succeeds   Whether verification must pass.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function verify(DrillHost $host, string $snapshot, bool $succeeds): void
    {
        $result = $host->run(['bash', 'tools/restore-verify.sh', $snapshot], $host->recoveryEnvironment(), 900);
        self::check(
            ($result['exit'] === 0) === $succeeds,
            sprintf('tools/restore-verify.sh exited %d on the %s snapshot: %s', $result['exit'], $succeeds ? 'intact' : 'altered', $result['output']),
        );
    }

    /**
     * Fill a volume with one file until two percent of it is free.
     *
     * @param   string  $directory  Directory on the volume.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function fill(string $directory): void
    {
        $free = disk_free_space($directory);
        $total = disk_total_space($directory);
        self::check($free !== false && $total !== false, 'the volume size is unreadable');
        $remaining = (int) ($free - 0.02 * $total);
        $handle = fopen($directory . '/' . self::FILL_FILE, 'wb');
        if ($handle === false) {
            throw new RuntimeException('the fill file cannot be created');
        }
        $chunk = str_repeat("\0", 1024 * 1024);
        while ($remaining > 0) {
            $written = @fwrite($handle, $remaining >= strlen($chunk) ? $chunk : substr($chunk, 0, $remaining));
            if ($written === false || $written === 0) {
                break;
            }
            $remaining -= $written;
        }
        fclose($handle);
    }

    /**
     * Wait until the wall clock moves to the next second, so recorded outcomes cannot share a timestamp.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function nextSecond(): void
    {
        $second = time();
        while (time() === $second) {
            usleep(20_000);
        }
    }

    /**
     * Poll until a condition holds.
     *
     * @param   \Closure(): bool  $condition  Condition to wait for.
     * @param   float            $timeout    Seconds before giving up.
     * @param   string           $message    Failure message.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the condition never held.
     *
     * @since   2.0.0
     */
    private static function waitFor(\Closure $condition, float $timeout, string $message): void
    {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            if ($condition()) {
                return;
            }
            usleep(250_000);
        }

        throw new RuntimeException($message);
    }

    /**
     * Build a URL on the primary web server, or on a replica beside it.
     *
     * @param   DrillHost  $host    Host.
     * @param   string     $path    Absolute path.
     * @param   int        $offset  Port offset: 0 for the primary, 1 for the second replica.
     *
     * @return  string  The URL.
     *
     * @since   2.0.0
     */
    private static function url(DrillHost $host, string $path, int $offset = 0): string
    {
        return sprintf('http://127.0.0.1:%d%s', $host->port + $offset, $path);
    }

    /**
     * Fail the drill unless a condition holds.
     *
     * @param   bool    $condition  Condition.
     * @param   string  $message    Failure message.
     *
     * @return  void
     *
     * @throws  RuntimeException  When the condition does not hold.
     *
     * @since   2.0.0
     */
    private static function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}
