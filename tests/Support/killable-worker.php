<?php

/**
 * A real durable worker in its own operating-system process, so a drill can genuinely kill one.
 *
 * Killed-worker recovery was proven only by moving lease timestamps in a store, which tests the reaper
 * rather than the recovery: nothing had ever taken a live worker away from a job it was executing. This
 * script is that worker. It boots the same kernel the console does, claims one job from the queue it is
 * pointed at through the real fenced claim, announces on disk that its handler has started, and then
 * blocks. It never settles the job and never returns on its own, which leaves the drill free to end it
 * with `SIGKILL` at the one moment that matters: while the lease is held and the work is unfinished.
 *
 * The same script serves the database-loss drills. Dropping a `resume` file into the handshake directory
 * releases the handler, which returns and leaves the process in the worker's settlement path — the one
 * place a database that went away while the job was in flight actually surfaces. A drill that has
 * meanwhile taken the database away, either by killing the relay this process connects through or by
 * asking the server to terminate its session, gets to watch which of the two answers the platform
 * gives: a session it can replace is replaced, and a server that is gone ends the process with the
 * job's lease still standing rather than letting it carry on without a database.
 *
 * Only the handler is a drill fixture. The container, the authorization, the queue, the claim, the
 * fencing token and the lease are the production ones, because a killed process that was not really
 * holding a real lease would prove nothing about what happens to real ones.
 *
 * Usage: php tests/Support/killable-worker.php <queue> <handshake-directory> <lease-seconds>
 *
 * @since  2.0.0
 */

declare(strict_types=1);

use Kumwe\App\Application\Automation\JobHandlerRegistry;
use Kumwe\App\Application\Automation\Worker;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\DrillDirectedJobHandler;
use Kumwe\App\Tests\Support\TestKernelFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$queue = $argv[1] ?? null;
$directory = $argv[2] ?? null;
$leaseSeconds = $argv[3] ?? null;
if (!is_string($queue) || !is_string($directory) || !is_string($leaseSeconds)) {
    fwrite(STDERR, "Usage: killable-worker.php <queue> <handshake-directory> <lease-seconds>\n");
    exit(2);
}

$container = TestKernelFactory::create(Environment::fromGlobals());
$context = TestKernelFactory::workerContext($container);
$wired = $container->get(Worker::class);
if (!$wired instanceof Worker) {
    fwrite(STDERR, "The wired worker is unavailable.\n");
    exit(3);
}
$collaborator = static function (string $property) use ($wired): mixed {
    return (new ReflectionProperty(Worker::class, $property))->getValue($wired);
};
$worker = new Worker(
    $collaborator('queue'),
    new JobHandlerRegistry([new DrillDirectedJobHandler($directory)]),
    $collaborator('authorization'),
    $collaborator('ownership'),
    $collaborator('system'),
    $collaborator('jobScope'),
    $collaborator('globalPrincipals'),
);

file_put_contents($directory . '/worker-ready', (string) getmypid());
$claimed = $worker->runOnce($context, $queue, 'killable-worker', (int) $leaseSeconds, 3_600);
file_put_contents($directory . '/worker-returned', $claimed ? 'claimed' : 'idle');
exit(0);
