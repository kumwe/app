<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Integration\Extension;

use Closure;
use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\CreateRecordCommand;
use Kumwe\App\BusinessRecord\Application\Query\ReadRecordQuery;
use Kumwe\App\Delivery\Http\Api\Business\BusinessRecordApiHandler;
use Kumwe\App\Http\Middleware\BearerAuthenticationMiddleware;
use Kumwe\App\Tests\Support\MachineSurfaceHarness;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\SecurityHttpHarness;
use Kumwe\App\Tests\Support\TestKernelFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Qualifies a separate PHP REST adapter against the actual HTTP host and database authority.
 *
 * The adapter gets an empty environment and no App autoloader. These process-launch choices avoid
 * accidental credential inheritance; they are not an OS sandbox for arbitrary extension publishers.
 * The same test runs in each supported engine's ordinary integration lane.
 *
 * @since  2.0.0
 */
#[CoversClass(BusinessRecordService::class)]
#[CoversClass(BusinessRecordApiHandler::class)]
#[CoversClass(BearerAuthenticationMiddleware::class)]
final class RemoteRecordAdapterIntegrationTest extends TestCase
{
    /**
     * Prove authentication, narrow authority, exact retry, stale refusal and revocation over real HTTP.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSeparateAdapterUsesOnlyAuthenticatedVersionedIdempotentRecordAuthority(): void
    {
        $harness = SecurityHttpHarness::boot();
        $container = $harness->container;
        $context = TestKernelFactory::administratorContext($container);
        $records = $container->get(BusinessRecordService::class);
        self::assertInstanceOf(BusinessRecordService::class, $records);
        $marker = substr(str_replace('-', '', Uuid::uuid7()->toString()), -10);
        $definition = NeutralBusinessFixture::install(
            $container,
            $context,
            NeutralBusinessFixture::document($marker, Uuid::uuid7()->toString()),
        );
        $recordId = Uuid::uuid7()->toString();
        $created = $records->create(new CreateRecordCommand(
            $context,
            $definition->handle,
            NeutralBusinessFixture::recordValues('Remote record ' . $marker),
            NeutralBusinessFixture::idempotencyKey('remote-create-' . $marker),
            recordId: $recordId,
        ));
        $machines = new MachineSurfaceHarness($container, 'remote-adapter-' . $marker);
        $machines->enterOrganization('remote-' . $marker);
        $actor = $machines->token('rest', ['business.record.read', 'business.record.update']);
        $readOnly = $machines->token('rest', ['business.record.read']);
        $wrongAudience = $machines->token('mcp', ['business.record.read']);
        $listener = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
        self::assertIsResource($listener, $message);
        $address = stream_socket_get_name($listener, false);
        self::assertIsString($address);
        fclose($listener);
        $origin = 'http://' . $address;
        $root = dirname(__DIR__, 3);
        $log = tempnam(sys_get_temp_dir(), 'kumwe-remote-host-');
        self::assertIsString($log);
        $environment = getenv();
        $environment['APP_BASE_URL'] = $origin;
        $environment['APP_TRUSTED_HOSTS'] = '127.0.0.1';
        $server = proc_open(
            [PHP_BINARY, '-S', $address, '-t', $root . '/public', $root . '/public/index.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            $root,
            $environment,
        );
        self::assertIsResource($server);
        try {
            $ready = false;
            for ($attempt = 0; $attempt < 100; ++$attempt) {
                $socket = @stream_socket_client('tcp://' . $address, $error, $message, 0.1);
                if (is_resource($socket)) {
                    fclose($socket);
                    $ready = true;
                    break;
                }
                usleep(20000);
            }
            self::assertTrue($ready, 'The actual PHP HTTP host did not start.');
            $read = [
                'token' => $actor,
                'site' => 'default',
                'definition' => $definition->handle,
                'record' => $recordId,
                'operation' => 'read',
            ];
            $initial = self::invoke($origin, $read);
            self::assertSame(0, $initial['exit'], $initial['out'] . $initial['err']);
            self::assertSame(200, $initial['json']['status'] ?? null);
            self::assertStringNotContainsString('neutral-fixture-secret', $initial['out']);
            $document = $initial['json']['document'] ?? [];
            self::assertIsArray($document);
            $values = $document['values'] ?? [];
            self::assertIsArray($values);
            self::assertArrayNotHasKey('credential', $values);
            $patch = array_replace($read, [
                'operation' => 'update',
                'etag' => $initial['json']['etag'] ?? '',
                'idempotency_key' => 'remote-update-' . $marker,
                'values' => ['name' => 'Remote updated ' . $marker],
            ]);
            $first = self::invoke($origin, $patch);
            self::assertSame(0, $first['exit'], $first['out'] . $first['err']);
            $replay = self::invoke($origin, $patch);
            self::assertSame(0, $replay['exit'], $replay['out'] . $replay['err']);
            self::assertSame($first['json']['etag'], $replay['json']['etag']);
            $stored = $records->read(new ReadRecordQuery($context, $definition->handle, $recordId));
            self::assertSame($created->version + 1, $stored->version, 'An exact retry commits no second mutation.');
            self::assertSame('Remote updated ' . $marker, $stored->values['name']);
            $stale = self::invoke($origin, array_replace($patch, ['idempotency_key' => 'remote-stale-' . $marker]));
            self::assertSame(1, $stale['exit']);
            self::assertContains($stale['json']['status'] ?? null, [409, 412]);
            $denied = self::invoke($origin, array_replace($patch, ['token' => $readOnly]));
            self::assertSame(1, $denied['exit']);
            self::assertContains($denied['json']['status'] ?? null, [403, 404]);
            foreach (
                [
                ['token' => 'invalid-adapter-credential'],
                ['token' => $wrongAudience],
                ['site' => 'foreign-site'],
                ] as $foreign
            ) {
                $refused = self::invoke($origin, array_replace($read, $foreign));
                self::assertSame(1, $refused['exit']);
                self::assertSame(['status' => 401], $refused['json']);
            }
            $machines->cleanup();
            $revoked = self::invoke($origin, $read);
            self::assertSame(1, $revoked['exit']);
            self::assertSame(['status' => 401], $revoked['json']);
            self::assertStringNotContainsString($actor, $initial['out'] . $first['out'] . $revoked['out']);
        } finally {
            proc_terminate($server);
            proc_close($server);
            unlink($log);
            $machines->cleanup();
        }
    }

    /**
     * Refuse oversized, injected and destination-changing requests before any network operation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAdapterRefusesInputsOutsideItsClosedBoundedContract(): void
    {
        $read = [
            'token' => 'example-adapter-credential',
            'site' => 'default',
            'definition' => 'site.default.example',
            'record' => '0191574f-f0b8-7bf3-a9aa-91c6b8244e11',
            'operation' => 'read',
        ];
        foreach (
            [
            $read + ['destination' => 'https://untrusted.example'],
            array_replace($read, ['token' => "example\r\nInjected: value"]),
            array_replace($read, ['definition' => '../../extension-trust-keys']),
            array_replace($read, ['operation' => 'delete']),
            array_replace($read, ['token' => str_repeat('x', 32769)]),
            array_replace($read, [
                'operation' => 'update',
                'etag' => '*',
                'idempotency_key' => 'op',
                'values' => ['x' => 1],
            ]),
            ] as $invalid
        ) {
            $result = self::invoke('http://127.0.0.1:1', $invalid);
            self::assertSame(2, $result['exit']);
            self::assertSame('', $result['out']);
            self::assertSame("The adapter request failed validation or its bounded transport.\n", $result['err']);
        }
    }

    /**
     * Refuse redirects and oversized responses without disclosing a bearer credential to a second peer.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAdapterStopsAtRedirectAndResponseBudgets(): void
    {
        $request = [
            'token' => 'example-adapter-credential',
            'site' => 'default',
            'definition' => 'site.default.example',
            'record' => '0191574f-f0b8-7bf3-a9aa-91c6b8244e11',
            'operation' => 'read',
        ];
        $responses = [
            ["HTTP/1.1 302 Found\r\nLocation: http://127.0.0.1:1\r\nContent-Length: 0\r\n\r\n", 1],
            ["HTTP/1.1 200 OK\r\nContent-Length: 65537\r\n\r\n" . str_repeat('x', 65537), 2],
            ["HTTP/1.1 200 OK\r\nX-Excessive: " . str_repeat('x', 16384) . "\r\nContent-Length: 0\r\n\r\n", 2],
        ];
        foreach ($responses as [$response, $expectedExit]) {
            $listener = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
            self::assertIsResource($listener, $message);
            $address = stream_socket_get_name($listener, false);
            self::assertIsString($address);
            try {
                $result = self::invoke('http://' . $address, $request, static function () use (
                    $listener,
                    $response,
                ): void {
                    $peer = stream_socket_accept($listener, 5);
                    self::assertIsResource($peer);
                    try {
                        stream_set_timeout($peer, 5);
                        $headers = '';
                        while (!str_ends_with($headers, "\r\n\r\n") && strlen($headers) < 16384) {
                            $line = fgets($peer);
                            self::assertIsString($line);
                            $headers .= $line;
                        }
                        self::assertStringContainsString('Authorization: Bearer example-adapter-credential', $headers);
                        fwrite($peer, $response);
                    } finally {
                        fclose($peer);
                    }
                });
                self::assertSame($expectedExit, $result['exit']);
                self::assertSame($expectedExit === 1 ? ['status' => 302] : [], $result['json']);
                self::assertStringNotContainsString($request['token'], $result['out'] . $result['err']);
            } finally {
                fclose($listener);
            }
        }
    }

    /**
     * Invoke only the standalone client in a child with no inherited application environment.
     *
     * @param   string                $origin     Explicit loopback test origin.
     * @param   array<string, mixed>  $request    Request serialized privately through stdin.
     * @param   ?Closure(): void      $servePeer  Optional deliberately hostile HTTP test peer.
     *
     * @return  array{exit: int, out: string, err: string, json: array<string, mixed>}  Bounded process result.
     *
     * @since   2.0.0
     */
    private static function invoke(string $origin, array $request, ?Closure $servePeer = null): array
    {
        $client = dirname(__DIR__, 3) . '/examples/extensions/asset-inspection/remote/record-adapter.php';
        $process = proc_open([
            PHP_BINARY,
            '-n',
            '-d',
            'extension_dir=' . ini_get('extension_dir'),
            '-d',
            'extension=curl',
            $client,
            $origin,
            '--allow-loopback-http',
        ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, sys_get_temp_dir(), []);
        self::assertIsResource($process);
        fwrite($pipes[0], json_encode($request, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        if ($servePeer !== null) {
            $servePeer();
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        self::assertIsString($out);
        self::assertIsString($err);
        $json = $out === '' ? [] : json_decode($out, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($json);
        return ['exit' => $exit, 'out' => $out, 'err' => $err, 'json' => $json];
    }
}
