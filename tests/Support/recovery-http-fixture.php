<?php

/**
 * Provision a real API token and neutral record request for the isolated source/restore HTTP drill.
 *
 * Secrets are written only to a new private fixture directory. The source database must be disposable;
 * the helper uses existing identity and business-definition services and never synthesizes persistence.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\App\Identity\Application\Administration\AccessControlService;
use Kumwe\App\Identity\Application\Administration\AdministratorSessionStore;
use Kumwe\App\Identity\Application\StepUp\AdministratorStepUpProvider;
use Kumwe\App\Identity\Application\StepUp\AuthorizationStepUpProofAdapter;
use Kumwe\App\Identity\Domain\StepUp\StepUpIntent;
use Kumwe\App\Identity\Domain\StepUp\StepUpVerification;
use Kumwe\App\Identity\Domain\UserStatus;
use Kumwe\App\BusinessSecurity\Application\Administration\BusinessSecurityAdministrationService;
use Kumwe\Access\MembershipDirectory;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use Kumwe\App\Tests\Support\NeutralBusinessFixture;
use Kumwe\App\Tests\Support\TestKernelFactory;
use Kumwe\App\Tests\Support\TotpCodes;
use Ramsey\Uuid\Uuid;

require __DIR__ . '/deployment-drill-autoload.php';

try {
    $arguments = $argv ?? [];
    $directory = $arguments[1] ?? '';
    if (
        count($arguments) !== 2 || !str_starts_with($directory, '/') || file_exists($directory)
        || getenv('KUMWE_RECOVERY_FIXTURE_DISPOSABLE') !== 'yes'
    ) {
        throw new RuntimeException(
            'Declare KUMWE_RECOVERY_FIXTURE_DISPOSABLE=yes and supply an absent absolute directory.',
        );
    }
    umask(0077);
    if (!mkdir($directory, 0700)) {
        throw new RuntimeException('Could not create the private HTTP fixture directory.');
    }
    $container = TestKernelFactory::create(Environment::fromGlobals());
    $context = TestKernelFactory::administratorContext($container);
    NeutralBusinessFixture::install($container, $context);
    $identities = $container->get(AdministratorIdentityGateway::class);
    if (!$identities instanceof AdministratorIdentityGateway) {
        throw new RuntimeException('The installed identity service is unavailable.');
    }
    // Business API tokens require a live exact organization membership. A separate policy actor
    // creates it through production administration with real step-up; self-escalation stays refused.
    $access = $container->get(AccessControlService::class);
    $sessions = $container->get(AdministratorSessionStore::class);
    $stepUp = $container->get(AdministratorStepUpProvider::class);
    $proofs = $container->get(AuthorizationStepUpProofAdapter::class);
    $security = $container->get(BusinessSecurityAdministrationService::class);
    $memberships = $container->get(MembershipDirectory::class);
    if (
        !$access instanceof AccessControlService || !$sessions instanceof AdministratorSessionStore
        || !$stepUp instanceof AdministratorStepUpProvider || !$proofs instanceof AuthorizationStepUpProofAdapter
        || !$security instanceof BusinessSecurityAdministrationService || !$memberships instanceof MembershipDirectory
    ) {
        throw new RuntimeException('The real organization-administration services are unavailable.');
    }
    $suffix = Uuid::uuid7()->toString();
    $policyEmail = 'recovery-policy-' . $suffix . '@example.test';
    $policyPassword = 'readable HTTP recovery policy fixture password';
    $user = $access->createUser($context, $policyEmail, 'HTTP recovery policy', $policyPassword, UserStatus::Active);
    $role = $access->createRole($context, 'recovery-policy-' . $suffix, 'HTTP recovery policy');
    foreach (['administrator.access', 'business.security.manage', 'business.step_up.manage'] as $capability) {
        $access->grant($context, $role, $capability);
    }
    $access->assignRole($context, $user, $role);
    $policy = $identities->authenticate($policyEmail, $policyPassword, 'recovery-http-fixture')
        ?? throw new RuntimeException('The separated policy actor could not authenticate.');
    $site = SiteContext::default();
    $session = $sessions->create($policy->context(
        $site,
        AuthenticationStrength::Password,
        'recovery-http-policy',
        surface: AuthenticatedSurface::Administrator,
    ), 'kumwe-recovery-http/2.0');
    $intent = static fn (string $sessionId, string $operation): StepUpIntent => new StepUpIntent(
        $policy->subject(),
        $sessionId,
        $site->identifier(),
        null,
        null,
        BusinessSecurityAdministrationService::stepUpPurpose($operation),
        $policy->securityEpoch(),
    );
    $elevated = static fn (StepUpVerification $proof): ExecutionContext => $policy->context(
        $site,
        AuthenticationStrength::MultiFactor,
        'recovery-http-policy',
        surface: AuthenticatedSurface::Administrator,
        sessionId: $proof->rotatedSession->sessionId,
        stepUpProof: $proofs->adapt($proof),
    );
    $setup = $stepUp->beginEnrollment($user, 'Kumwe', $policyEmail);
    $completion = $stepUp->confirmEnrollment(
        $intent($session->session->id, 'organization.create'),
        $setup->enrollmentId,
        TotpCodes::fromBase32($setup->secret, intdiv(time(), 30)),
        'recovery-http-fixture',
    );
    $organizationCode = 'recovery-http-' . $suffix;
    $organization = $security->createOrganization(
        $elevated($completion->verification),
        $organizationCode,
        'HTTP recovery fixture',
    );
    $verification = $stepUp->recover(
        $intent($completion->verification->rotatedSession->sessionId, 'membership.create'),
        $completion->recoveryCodes[0],
        'recovery-http-fixture',
    );
    $security->createMembership(
        $elevated($verification),
        $organization,
        $context->actorId(),
        new DateTimeImmutable('-1 minute'),
        null,
    );
    $membership = $memberships->resolve($context->actorId(), $site, $organizationCode)
        ?? throw new RuntimeException('The created organization membership could not be resolved.');
    $principal = $identities->authenticate(
        TestKernelFactory::ADMINISTRATOR_EMAIL,
        TestKernelFactory::ADMINISTRATOR_PASSWORD,
        'recovery-http-fixture',
    ) ?? throw new RuntimeException('The administrator could not reauthenticate.');
    $context = $principal->context(
        $site,
        AuthenticationStrength::Password,
        'recovery-http-token',
        membership: $membership,
    );
    $token = $identities->issueAccessToken(
        $context,
        TestKernelFactory::ADMINISTRATOR_EMAIL,
        'Native recovery HTTP drill',
        ['business.record.create', 'business.record.read'],
    );
    $files = [
        'email' => TestKernelFactory::ADMINISTRATOR_EMAIL,
        'password' => TestKernelFactory::ADMINISTRATOR_PASSWORD,
        'token' => $token['token'],
        'request.json' => json_encode([
            'format' => 'kumwe-recovery-http-request-v1',
            'site' => $site->identifier(),
            'method' => 'POST',
            'path' => '/api/v1/business/records/' . NeutralBusinessFixture::HANDLE,
            'key' => 'recovery-http-' . Uuid::uuid7()->toString(),
            'if_match' => '',
            'body' => ['values' => NeutralBusinessFixture::recordValues('Restored HTTP mutation')],
            'approval_request_id' => null,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n",
    ];
    foreach ($files as $name => $contents) {
        if (file_put_contents($directory . '/' . $name, $contents) !== strlen($contents)) {
            throw new RuntimeException('The private HTTP fixture file could not be written completely.');
        }
    }
    fwrite(STDOUT, "Real HTTP recovery fixture written. Keep credential and request files private.\n");
} catch (Throwable $failure) {
    fwrite(STDERR, 'HTTP recovery fixture failed: ' . $failure->getMessage() . "\n");
    exit(1);
}
