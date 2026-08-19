<?php

/**
 * The second caller an aggregate-document race needs, run as a separate operating-system process.
 *
 * Two amendments cannot genuinely contend from one PHP process: the loser only exists while the winner is
 * still inside its transaction, and a blocked session blocks the interpreter with it. This script is
 * therefore spawned by `AggregateDocumentConcurrencyIntegrationTest`, boots the same kernel from the same
 * environment, and submits its own whole-document amendment against the same aggregate version the test
 * read. The two processes coordinate through files rather than sleeps, so the overlap is observed rather
 * than hoped for.
 *
 * Usage: php tests/Support/document-amend-partner.php <handshake-directory> <definition-handle>
 *        <record-id> <expected-version> <line-code>
 *
 * @since  2.0.0
 */

declare(strict_types=1);

use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\DocumentLineInput;
use Kumwe\App\BusinessRecord\Application\Command\DocumentWriteIntent;
use Kumwe\App\BusinessRecord\Application\Command\WriteDocumentCommand;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$directory = $argv[1] ?? null;
$handle = $argv[2] ?? null;
$recordId = $argv[3] ?? null;
$expectedVersion = $argv[4] ?? null;
$code = $argv[5] ?? null;
if (
    !is_string($directory) || !is_string($handle) || !is_string($recordId)
    || !is_string($expectedVersion) || !is_string($code)
) {
    fwrite(STDERR, "Usage: document-amend-partner.php <dir> <handle> <record-id> <version> <line-code>\n");
    exit(2);
}

$outcome = 'not-attempted';
try {
    $container = TestKernelFactory::create(Environment::fromGlobals());
    $context = TestKernelFactory::administratorContext($container);
    $records = $container->get(BusinessRecordService::class);
    if (!$records instanceof BusinessRecordService) {
        throw new RuntimeException('The business-record runtime is unavailable to the partner process.');
    }
    file_put_contents($directory . '/partner-ready', 'ready');
    $deadline = microtime(true) + 20.0;
    while (microtime(true) < $deadline) {
        clearstatcache(true, $directory . '/test-ready');
        if (is_file($directory . '/test-ready')) {
            break;
        }
        usleep(5_000);
    }
    $records->writeDocument(new WriteDocumentCommand(
        $context,
        $handle,
        'lines',
        ['total' => '7.00'],
        [new DocumentLineInput([
            'code' => $code,
            'description' => 'Partner line',
            'amount' => '7.00',
        ])],
        NeutralBusinessFixture::idempotencyKey('doc-race-partner-' . $code),
        DocumentWriteIntent::Amend,
        (int) $expectedVersion,
        $recordId,
    ));
    $outcome = 'committed';
} catch (Throwable $exception) {
    $outcome = $exception::class;
}

file_put_contents($directory . '/partner-outcome', $outcome);
exit(0);
