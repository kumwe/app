<?php

declare(strict_types=1);

namespace Kumwe\App\Tools\Observability;

use Closure;

/**
 * Exercises a running deployment from the outside, read-only, the way a visitor and a scraper do.
 *
 * Internal gauges answer "is the application healthy"; they cannot see an ingress serving an expired
 * certificate, a trusted-host list that rejects the public name, or a scrape credential that no longer works.
 * The probe can, because it arrives over the same path real traffic does. Every check is read-only and
 * idempotent, so it is safe to run every minute against production:
 *
 * - `liveness` — `GET /health/live` answers 200;
 * - `readiness` — `GET /health/ready` answers 200;
 * - `public_page` — `GET` of the public page answers 200 with a body, echoes the probe's `X-Request-ID`, and
 *   echoes its W3C `traceparent` unchanged, so the edge still propagates trace context;
 * - `metrics` — with a scrape token configured, `/metrics` refuses a missing credential with 401 or 404 and
 *   serves the exposition, including `kumwe_build_info`, to the right one;
 * - `api` — with a read token configured, an authenticated REST read answers 200 with JSON.
 *
 * The result is a Prometheus textfile (`kumwe_probe_success`, `kumwe_probe_duration_seconds`,
 * `kumwe_probe_last_run_timestamp_seconds`) that node_exporter's textfile collector or any file-reading agent
 * publishes; the availability alerts evaluate it.
 *
 * @since  2.0.0
 */
final readonly class SyntheticProbe
{
    /**
     * Every check the probe can report, the `check` label's closed enumeration.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const CHECKS = ['liveness', 'readiness', 'public_page', 'metrics', 'api'];

    /**
     * User agent every probe request carries, so its traffic is recognisable in access logs.
     *
     * @var    string
     * @since  2.0.0
     */
    public const USER_AGENT = 'Kumwe-Synthetic-Probe/1';

    /**
     * Bind the probe to its target and transport.
     *
     * @param  string                                                           $baseUrl       Deployment base URL.
     * @param  Closure(string, array<string, string>): array{status: int, headers: array<string, string>, body: string}  $transport
     *         Performs a GET of an absolute URL with the given headers; header names come back lower-cased.
     * @param  ?string                                                          $metricsToken  Scrape token, or null to skip `metrics`.
     * @param  ?string                                                          $apiToken      Read token, or null to skip `api`.
     * @param  string                                                           $site          Site the API read is scoped to.
     * @param  string                                                           $page          Public page path.
     *
     * @since  2.0.0
     */
    public function __construct(
        private string $baseUrl,
        private Closure $transport,
        private ?string $metricsToken = null,
        private ?string $apiToken = null,
        private string $site = 'default',
        private string $page = '/',
    ) {
    }

    /**
     * Run every configured check once.
     *
     * @return  list<array{check: string, success: bool, seconds: float, detail: string}>  One result per check run.
     *
     * @since   2.0.0
     */
    public function run(): array
    {
        $results = [
            $this->check('liveness', fn (): string => $this->expectStatus('/health/live', [], 200)),
            $this->check('readiness', fn (): string => $this->expectStatus('/health/ready', [], 200)),
            $this->check('public_page', fn (): string => $this->publicPage()),
        ];
        if ($this->metricsToken !== null) {
            $results[] = $this->check('metrics', fn (): string => $this->metrics((string) $this->metricsToken));
        }
        if ($this->apiToken !== null) {
            $results[] = $this->check('api', fn (): string => $this->api((string) $this->apiToken));
        }

        return $results;
    }

    /**
     * Render results as a Prometheus textfile.
     *
     * @param   list<array{check: string, success: bool, seconds: float, detail: string}>  $results  Check results.
     * @param   int                                                                        $now      Unix time of the run.
     *
     * @return  string  Exposition text ending with a newline.
     *
     * @since   2.0.0
     */
    public static function exposition(array $results, int $now): string
    {
        $lines = [
            '# HELP kumwe_probe_success Whether the synthetic probe check passed: 1 passed, 0 failed.',
            '# TYPE kumwe_probe_success gauge',
        ];
        foreach ($results as $result) {
            $lines[] = sprintf('kumwe_probe_success{check="%s"} %d', $result['check'], $result['success'] ? 1 : 0);
        }
        $lines[] = '# HELP kumwe_probe_duration_seconds Wall time the synthetic probe check took.';
        $lines[] = '# TYPE kumwe_probe_duration_seconds gauge';
        foreach ($results as $result) {
            $lines[] = sprintf('kumwe_probe_duration_seconds{check="%s"} %.6F', $result['check'], $result['seconds']);
        }
        $lines[] = '# HELP kumwe_probe_last_run_timestamp_seconds Unix time the synthetic probe last ran.';
        $lines[] = '# TYPE kumwe_probe_last_run_timestamp_seconds gauge';
        $lines[] = sprintf('kumwe_probe_last_run_timestamp_seconds %d', $now);

        return implode("\n", $lines) . "\n";
    }

    /**
     * Time one check, turning any refusal into a failed result.
     *
     * @param   string           $check      Check name.
     * @param   Closure(): string  $operation  Returns a success detail or throws `RuleViolation` on failure.
     *
     * @return  array{check: string, success: bool, seconds: float, detail: string}  The result.
     *
     * @since   2.0.0
     */
    private function check(string $check, Closure $operation): array
    {
        $started = microtime(true);
        try {
            $detail = $operation();
            $success = true;
        } catch (RuleViolation $failure) {
            $detail = $failure->getMessage();
            $success = false;
        }

        return ['check' => $check, 'success' => $success, 'seconds' => microtime(true) - $started, 'detail' => $detail];
    }

    /**
     * Require one status from a GET.
     *
     * @param   string                 $path     Path relative to the base URL.
     * @param   array<string, string>  $headers  Extra request headers.
     * @param   int                    $status   Required status.
     *
     * @return  string  Success detail.
     *
     * @throws  RuleViolation  When the status differs or the transport failed.
     *
     * @since   2.0.0
     */
    private function expectStatus(string $path, array $headers, int $status): string
    {
        $response = $this->get($path, $headers);
        if ($response['status'] !== $status) {
            throw RuleViolation::at($path, sprintf('answered %d, expected %d', $response['status'], $status));
        }

        return sprintf('%s answered %d', $path, $status);
    }

    /**
     * Check the public page, request-identifier echo and trace-context echo.
     *
     * @return  string  Success detail.
     *
     * @throws  RuleViolation  When the page, the identifier or the trace context is wrong.
     *
     * @since   2.0.0
     */
    private function publicPage(): string
    {
        $requestId = 'probe-' . bin2hex(random_bytes(8));
        $traceparent = sprintf('00-%s-%s-01', bin2hex(random_bytes(16)), bin2hex(random_bytes(8)));
        $response = $this->get($this->page, ['X-Request-ID' => $requestId, 'traceparent' => $traceparent]);
        if ($response['status'] !== 200 || trim($response['body']) === '') {
            throw RuleViolation::at($this->page, sprintf('answered %d with %d bytes', $response['status'], strlen($response['body'])));
        }
        if (($response['headers']['x-request-id'] ?? '') !== $requestId) {
            throw RuleViolation::at($this->page, 'did not echo the probe request identifier');
        }
        if (($response['headers']['traceparent'] ?? '') !== $traceparent) {
            throw RuleViolation::at($this->page, 'did not echo the probe trace context');
        }

        return sprintf('%s answered 200 and propagated request and trace identifiers', $this->page);
    }

    /**
     * Check that the metrics endpoint refuses anonymous scrapes and serves the credentialed one.
     *
     * @param   string  $token  Scrape token.
     *
     * @return  string  Success detail.
     *
     * @throws  RuleViolation  When anonymous scrapes are served or the credentialed one is not.
     *
     * @since   2.0.0
     */
    private function metrics(string $token): string
    {
        $anonymous = $this->get('/metrics', []);
        if (!in_array($anonymous['status'], [401, 404], true)) {
            throw RuleViolation::at('/metrics', sprintf('served an anonymous scrape with %d', $anonymous['status']));
        }
        $scrape = $this->get('/metrics', ['Authorization' => 'Bearer ' . $token]);
        if ($scrape['status'] !== 200 || !str_contains($scrape['body'], 'kumwe_build_info')) {
            throw RuleViolation::at('/metrics', sprintf('refused the configured scrape credential with %d', $scrape['status']));
        }

        return '/metrics refused anonymous scrapes and served the credentialed one';
    }

    /**
     * Check an authenticated, read-only REST call.
     *
     * @param   string  $token  Read-scoped bearer token.
     *
     * @return  string  Success detail.
     *
     * @throws  RuleViolation  When the read is refused or answers something other than JSON.
     *
     * @since   2.0.0
     */
    private function api(string $token): string
    {
        $response = $this->get('/api/v1/content', [
            'Authorization' => 'Bearer ' . $token,
            'Kumwe-Site' => $this->site,
            'Accept' => 'application/json',
        ]);
        if ($response['status'] !== 200 || !is_array(json_decode($response['body'], true))) {
            throw RuleViolation::at('/api/v1/content', sprintf('answered %d without a JSON document', $response['status']));
        }

        return '/api/v1/content answered 200 with JSON';
    }

    /**
     * Perform a GET through the transport, turning a transport failure into a refusal.
     *
     * @param   string                 $path     Path relative to the base URL.
     * @param   array<string, string>  $headers  Extra request headers.
     *
     * @return  array{status: int, headers: array<string, string>, body: string}  The response.
     *
     * @throws  RuleViolation  When the transport could not complete the request.
     *
     * @since   2.0.0
     */
    private function get(string $path, array $headers): array
    {
        $response = ($this->transport)(
            rtrim($this->baseUrl, '/') . $path,
            $headers + ['User-Agent' => self::USER_AGENT],
        );
        if ($response['status'] === 0) {
            throw RuleViolation::at($path, 'could not be reached: ' . $response['body']);
        }

        return $response;
    }

    /**
     * Build the default transport over PHP's HTTP stream wrapper, with a bounded timeout and no redirects.
     *
     * @param   float  $timeout  Seconds before a request is abandoned.
     *
     * @return  Closure(string, array<string, string>): array{status: int, headers: array<string, string>, body: string}
     *          The transport; a connection failure answers status 0 with the reason as the body.
     *
     * @since   2.0.0
     */
    public static function streamTransport(float $timeout = 10.0): Closure
    {
        return static function (string $url, array $headers) use ($timeout): array {
            $lines = [];
            foreach ($headers as $name => $value) {
                $lines[] = $name . ': ' . $value;
            }
            $context = stream_context_create(['http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $lines),
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
            ]]);
            $error = '';
            set_error_handler(static function (int $severity, string $message) use (&$error): bool {
                $error = $message;

                return true;
            });
            try {
                $body = file_get_contents($url, false, $context);
            } finally {
                restore_error_handler();
            }
            $raw = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : [];
            if ($body === false || $raw === []) {
                return ['status' => 0, 'headers' => [], 'body' => $error === '' ? 'no response' : $error];
            }
            $status = preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $raw[0], $match) === 1 ? (int) $match[1] : 0;
            $parsed = [];
            foreach (array_slice($raw, 1) as $line) {
                if (str_contains((string) $line, ':')) {
                    [$name, $value] = explode(':', (string) $line, 2);
                    $parsed[strtolower(trim($name))] = trim($value);
                }
            }

            return ['status' => $status, 'headers' => $parsed, 'body' => $body];
        };
    }
}
