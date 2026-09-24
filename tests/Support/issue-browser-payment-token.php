<?php

/**
 * Issue the browser payment adapter's membership-scoped API token and print it on standard output.
 *
 * The P7-E catalogue-to-order journey (tests/Browser/user-acceptance-journeys.spec.ts) records a payment out of
 * process, the way a payment service would: through the REST API with its own bearer token. The adapter
 * identity is seeded by tests/Support/prepare-browser-contribution.php; the token is issued here, at the
 * moment the journey needs it, because an organization-scoped token is bound to the organization's policy
 * generation and earlier journeys legitimately move that generation on. The token carries record read and
 * update only.
 *
 * Usage: php tests/Support/issue-browser-payment-token.php
 *
 * @since  2.0.0
 */

declare(strict_types=1);

use Kumwe\Access\MembershipDirectory;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\App\Kernel\Container;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\SiteContext;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$container = require dirname(__DIR__, 2) . '/bootstrap/container.php';
if (!$container instanceof Container) {
    throw new RuntimeException('The application container is unavailable.');
}
$identities = $container->get(AdministratorIdentityGateway::class);
$memberships = $container->get(MembershipDirectory::class);
if (!$identities instanceof AdministratorIdentityGateway || !$memberships instanceof MembershipDirectory) {
    throw new RuntimeException('The browser payment adapter services are unavailable.');
}
$email = 'browser-payment-adapter@kumwe.test';
$principal = $identities->authenticate($email, 'browser payment adapter password', 'browser-payment-adapter');
if ($principal === null) {
    throw new RuntimeException('The browser payment adapter cannot be authenticated.');
}
$membership = $memberships->resolve($principal->subject(), SiteContext::default(), 'acme', 'north');
if ($membership === null) {
    throw new RuntimeException('The browser payment adapter membership is unavailable.');
}
$token = $identities->issueAccessToken(
    $principal->context(
        SiteContext::default(),
        AuthenticationStrength::Password,
        'browser-payment-adapter-' . bin2hex(random_bytes(8)),
        membership: $membership,
    ),
    $email,
    'Browser payment adapter',
    ['business.record.read', 'business.record.update'],
    new DateTimeImmutable('+10 minutes'),
);
fwrite(STDOUT, (string) $token['token']);
