<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Observability;

use Closure;
use Kumwe\App\Tools\Observability\SyntheticProbe;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Pins what the synthetic probe calls a pass, and the textfile the availability alerts read.
 *
 * The subject is `tools/Observability/SyntheticProbe.php`, driven through a recording transport: every check is
 * a read-only GET, the public page must echo the probe's request identifier and trace context, the metrics
 * endpoint must refuse an anonymous scrape and serve the credentialed one, and an unreachable target fails every
 * check rather than raising.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class SyntheticProbeTest extends TestCase
{
    /**
     * Load the tooling classes once.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 3) . '/tools/Observability/bootstrap.php';
    }

    /**
     * A healthy deployment passes every configured check with read-only requests.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAHealthyDeploymentPassesEveryCheckWithReadOnlyRequests(): void
    {
        $requests = [];
        $probe = new SyntheticProbe(
            'https://site.example/',
            self::transport($requests),
            'scrape-token',
            'read-token',
            'default',
        );

        $results = $probe->run();

        self::assertSame(SyntheticProbe::CHECKS, array_column($results, 'check'));
        foreach ($results as $result) {
            self::assertTrue($result['success'], $result['detail']);
        }
        self::assertSame('https://site.example/health/live', $requests[0]['url']);
        self::assertSame(SyntheticProbe::USER_AGENT, $requests[0]['headers']['User-Agent']);
        self::assertArrayNotHasKey('Authorization', $requests[3]['headers'], 'The first scrape is anonymous.');
        self::assertSame('Bearer scrape-token', $requests[4]['headers']['Authorization']);
        self::assertSame('default', $requests[5]['headers']['Kumwe-Site']);
    }

    /**
     * A page that does not echo trace context, an open metrics endpoint and a refused read each fail their check.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEdgeRegressionsFailTheirOwnCheckOnly(): void
    {
        $requests = [];
        $probe = new SyntheticProbe('https://site.example', self::transport($requests, [
            'drop-trace' => true,
            'open-metrics' => true,
            'api-status' => 403,
        ]), 'scrape-token', 'read-token');

        $results = array_column($probe->run(), null, 'check');

        self::assertTrue($results['liveness']['success']);
        self::assertTrue($results['readiness']['success']);
        self::assertFalse($results['public_page']['success']);
        self::assertStringContainsString('did not echo the probe trace context', $results['public_page']['detail']);
        self::assertFalse($results['metrics']['success']);
        self::assertStringContainsString('served an anonymous scrape with 200', $results['metrics']['detail']);
        self::assertFalse($results['api']['success']);
        self::assertStringContainsString('answered 403', $results['api']['detail']);
    }

    /**
     * An unreachable target fails every check, unconfigured checks are skipped, and the textfile says so.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUnreachableTargetFailsEveryCheckAndRendersATextfile(): void
    {
        $probe = new SyntheticProbe(
            'http://127.0.0.1:9',
            static fn (string $url, array $headers): array => ['status' => 0, 'headers' => [], 'body' => 'refused'],
        );

        $results = $probe->run();
        $textfile = SyntheticProbe::exposition($results, 1_790_000_000);

        self::assertSame(['liveness', 'readiness', 'public_page'], array_column($results, 'check'));
        self::assertSame([false, false, false], array_column($results, 'success'));
        self::assertStringContainsString('could not be reached: refused', $results[0]['detail']);
        self::assertStringContainsString("kumwe_probe_success{check=\"liveness\"} 0\n", $textfile);
        self::assertStringContainsString("# TYPE kumwe_probe_duration_seconds gauge\n", $textfile);
        self::assertStringEndsWith("kumwe_probe_last_run_timestamp_seconds 1790000000\n", $textfile);
    }

    /**
     * The probe command exits non-zero against a closed port and refuses a missing base URL as a usage error.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheProbeCommandReportsFailureAndUsage(): void
    {
        $root = dirname(__DIR__, 3);
        $textfile = sys_get_temp_dir() . '/kumwe-probe-' . bin2hex(random_bytes(6)) . '.prom';

        [$status, $output] = self::command([
            PHP_BINARY,
            $root . '/tools/synthetic-probe.php',
            '--base-url=http://127.0.0.1:9',
            '--textfile=' . $textfile,
        ]);
        self::assertSame(1, $status);
        self::assertCount(3, array_filter(explode("\n", $output)));
        self::assertStringContainsString(
            'kumwe_probe_success{check="readiness"} 0',
            (string) file_get_contents($textfile),
        );
        unlink($textfile);

        [$status] = self::command([PHP_BINARY, $root . '/tools/synthetic-probe.php']);
        self::assertSame(64, $status);
    }

    /**
     * Run a command and collect its exit status and standard output.
     *
     * @param   list<string>  $command  Argument vector.
     *
     * @return  array{int, string}  Exit status and stdout.
     *
     * @since   2.0.0
     */
    private static function command(array $command): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }

    /**
     * Build a transport that answers like a healthy deployment unless told otherwise, recording each request.
     *
     * @param   list<array{url: string, headers: array<string, string>}>  $requests  Recorded requests.
     * @param   array<string, mixed>                                      $faults    Deviations to simulate.
     *
     * @return  Closure(string, array<string, string>): array{status: int, headers: array<string, string>, body: string}
     *          The transport.
     *
     * @since   2.0.0
     */
    private static function transport(array &$requests, array $faults = []): Closure
    {
        return static function (string $url, array $headers) use (&$requests, $faults): array {
            $requests[] = ['url' => $url, 'headers' => $headers];
            $path = (string) parse_url($url, PHP_URL_PATH);
            if ($path === '/metrics') {
                $authorized = ($headers['Authorization'] ?? '') === 'Bearer scrape-token';

                return $authorized || ($faults['open-metrics'] ?? false)
                    ? ['status' => 200, 'headers' => [], 'body' => "kumwe_build_info 1\n"]
                    : ['status' => 401, 'headers' => [], 'body' => ''];
            }
            if ($path === '/api/v1/content') {
                return ['status' => (int) ($faults['api-status'] ?? 200), 'headers' => [], 'body' => '{"items":[]}'];
            }
            $echo = ['x-request-id' => $headers['X-Request-ID'] ?? ''];
            if (!($faults['drop-trace'] ?? false)) {
                $echo['traceparent'] = $headers['traceparent'] ?? '';
            }

            return ['status' => 200, 'headers' => $echo, 'body' => '<html>ok</html>'];
        };
    }
}
