<?php

/**
 * Draft one exact-value thousand-line ledger document and print its record identifier on standard output.
 *
 * The P7-E exact-document journey (tests/Browser/user-acceptance-journeys.spec.ts) reviews, approves, posts,
 * inspects and exports a document of one thousand owned lines. A person does not key a thousand lines into a
 * browser: they arrive from an import or an upstream system, which is what this script stands in for. It
 * drafts a fresh document at the moment the journey needs one, so every browser project and every retry
 * reviews its own draft instead of meeting another run's posted document. Line n carries n hundredths, so
 * the exact total is 5005.00; every line code starts with the given prefix so the journey can find this
 * document's lines in an export that also holds other runs' lines.
 *
 * Usage: php tests/Support/draft-browser-ledger-document.php <title> <line-code-prefix>
 *
 * @since  2.0.0
 */

declare(strict_types=1);

use Kumwe\App\BusinessRecord\Application\BusinessRecordService;
use Kumwe\App\BusinessRecord\Application\Command\DocumentLineInput;
use Kumwe\App\BusinessRecord\Application\Command\WriteDocumentCommand;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\App\Kernel\Container;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\SiteContext;
use Ramsey\Uuid\Uuid;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$title = $argv[1] ?? '';
$prefix = $argv[2] ?? '';
if ($title === '' || preg_match('/^[A-Z0-9-]{1,24}$/', $prefix) !== 1) {
    throw new InvalidArgumentException('Usage: draft-browser-ledger-document.php <title> <LINE-CODE-PREFIX>');
}
$email = getenv('KUMWE_BROWSER_ADMIN_EMAIL');
$password = getenv('KUMWE_BROWSER_ADMIN_PASSWORD');
$email = is_string($email) && $email !== '' ? $email : 'browser-administrator@kumwe.test';
$password = is_string($password) && $password !== '' ? $password : 'browser administrator password';

$container = require dirname(__DIR__, 2) . '/bootstrap/container.php';
if (!$container instanceof Container) {
    throw new RuntimeException('The application container is unavailable.');
}
$identities = $container->get(AdministratorIdentityGateway::class);
$records = $container->get(BusinessRecordService::class);
if (!$identities instanceof AdministratorIdentityGateway || !$records instanceof BusinessRecordService) {
    throw new RuntimeException('The browser ledger drafting services are unavailable.');
}
$principal = $identities->authenticate($email, $password, 'browser-ledger-draft');
if ($principal === null) {
    throw new RuntimeException('The browser administrator cannot be authenticated.');
}
$context = $principal->context(
    SiteContext::default(),
    AuthenticationStrength::Password,
    'browser-ledger-draft-' . bin2hex(random_bytes(8)),
);
$lines = [];
for ($index = 1; $index <= 1000; ++$index) {
    $lines[] = new DocumentLineInput([
        'code' => sprintf('%s-%04d', $prefix, $index),
        'description' => sprintf('Ledger entry %d', $index),
        'amount' => sprintf('%d.%02d', intdiv($index, 100), $index % 100),
    ]);
}
$recordId = Uuid::uuid7()->toString();
$records->writeDocument(new WriteDocumentCommand(
    $context,
    'site.default.doc_header_browser',
    'lines',
    ['title' => $title, 'total' => '5005.00'],
    $lines,
    NeutralBusinessFixture::idempotencyKey('browser-ledger-draft-' . $recordId),
    recordId: $recordId,
));
fwrite(STDOUT, $recordId);
