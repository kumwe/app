<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Support;

use DateInterval;
use Doctrine\DBAL\Connection;
use Kumwe\Approval\ApprovalBinding;
use Kumwe\Approval\ApprovalDenied;
use Kumwe\Approval\ApprovalRepository;
use Kumwe\Approval\ApprovalService;
use Kumwe\Approval\ApprovalStatus;
use Kumwe\App\BusinessSecurity\Application\Administration\BusinessSecurityAdministrationService;
use Kumwe\App\Identity\Application\Administration\AccessControlService;
use Kumwe\App\Identity\Application\Administration\AdministratorIdentityGateway;
use Kumwe\App\Identity\Application\Administration\AdministratorSessionStore;
use Kumwe\App\Identity\Application\Authentication\AuthenticatedPrincipal;
use Kumwe\App\Identity\Application\StepUp\AdministratorStepUpProvider;
use Kumwe\App\Identity\Application\StepUp\AuthorizationStepUpProofAdapter;
use Kumwe\App\Identity\Application\StepUp\StepUpCredentialStore;
use Kumwe\App\Identity\Application\StepUp\StepUpSecretCipher;
use Kumwe\App\Identity\Domain\StepUp\StepUpIntent;
use Kumwe\App\Identity\Domain\StepUp\StepUpVerification;
use Kumwe\App\Identity\Domain\UserStatus;
use Kumwe\App\Infrastructure\Persistence\TableNames;
use Kumwe\App\Kernel\Container;
use Kumwe\Context\Value\AuthenticatedSurface;
use Kumwe\Context\Value\AuthenticationStrength;
use Kumwe\Context\Value\ExecutionContext;
use Kumwe\Context\Value\SiteContext;
use RuntimeException;

/**
 * Restore acceptance using real policy administration, distinct actors and persisted single-use proofs.
 *
 * No approval, vote, rule or proof is manufactured with SQL. Restored spent state is challenged with
 * a fresh proof that then successfully consumes an approved sibling, ruling out an unusable context.
 *
 * @since  2.0.0
 */
final class RestoreApprovalAcceptance
{
    /**
     * Fixture-only readable password shared by the three deliberately separate accounts.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string PASSWORD = 'backup approval fixture passphrase';

    /**
     * Binding purpose isolated from application actions and existing policies.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string ACTION = 'recovery.approval.acceptance';

    /**
     * Seed an approved sibling and a consumed request through installed production services.
     *
     * @param   Container         $container      Source kernel.
     * @param   ExecutionContext  $administrator  Bootstrap owner allowed to create fixture identities.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public static function seed(Container $container, ExecutionContext $administrator): void
    {
        $access = self::service($container, AccessControlService::class);
        $roles = [];
        $capabilitySets = [
            'policy' => ['business.security.manage', 'business.step_up.manage'],
            'maker' => ['business.approval.request'],
            'checker' => ['business.approval.approve'],
        ];
        foreach ($capabilitySets as $actor => $capabilities) {
            $user = $access->createUser(
                $administrator,
                self::email($actor),
                'Backup approval ' . $actor,
                self::PASSWORD,
                UserStatus::Active,
            );
            $role = $access->createRole($administrator, 'backup-approval-' . $actor, 'Backup approval ' . $actor);
            foreach (['administrator.access', ...$capabilities] as $capability) {
                $access->grant($administrator, $role, $capability);
            }
            $access->assignRole($administrator, $user, $role);
            $roles[$actor] = $role;
        }
        [$policy, $policyProof] = self::enroll(
            $container,
            'policy',
            BusinessSecurityAdministrationService::stepUpPurpose('separation_duty.create'),
        );
        self::service($container, BusinessSecurityAdministrationService::class)->createSeparationRule(
            self::context($container, $policy, $policyProof),
            'backup-approval-rule',
            'role',
            self::ACTION,
            'business.approval.approve',
            null,
            $roles['maker'],
            $roles['checker'],
            1,
            true,
        );
        [$maker, $makerProof] = self::enroll($container, 'maker', self::ACTION);
        [$checker, $checkerProof, $recoveryCodes] = self::enroll($container, 'checker', 'business.approval.approve');
        $makerContext = self::context($container, $maker, $makerProof);
        $approvals = self::service($container, ApprovalService::class);
        foreach (['spent', 'available'] as $offset => $marker) {
            $binding = ApprovalBinding::fromContext(
                $makerContext,
                self::ACTION,
                'role',
                $roles['maker'],
                1,
                hash('sha256', $marker),
            );
            $id = $approvals->request($makerContext, $binding, new DateInterval('P7D'))
                ?? throw new RuntimeException('The live separation rule did not require approval.');
            if ($offset > 0) {
                $checkerProof = self::service($container, AdministratorStepUpProvider::class)->recover(
                    self::intent($checker, $checkerProof->rotatedSession->sessionId, 'business.approval.approve'),
                    $recoveryCodes[0],
                    'backup-approval-acceptance',
                );
            }
            $status = $approvals->approve(self::context($container, $checker, $checkerProof), $id);
            if ($status !== ApprovalStatus::Approved) {
                throw new RuntimeException('The real checker did not approve the request.');
            }
            if ($marker === 'spent') {
                $approvals->consume($makerContext, $id, $binding);
            }
        }
        self::manifest($container);
    }

    /**
     * Describe exact restored workflow state without copying credentials or proof tokens into evidence.
     *
     * @param   Container  $container  Source or restored kernel.
     *
     * @return  array<string, array{id: string, status: string, version: int, binding: string}>
     *
     * @since   2.0.0
     */
    public static function manifest(Container $container): array
    {
        $database = self::service($container, Connection::class);
        $tables = self::service($container, TableNames::class);
        $rows = $database->fetchAllAssociative(sprintf(
            'SELECT id FROM %s WHERE action = ? ORDER BY id',
            $tables->quoted('approval_requests'),
        ), [self::ACTION]);
        $repository = self::service($container, ApprovalRepository::class);
        $result = [];
        foreach ($rows as $row) {
            if (!is_string($row['id'])) {
                throw new RuntimeException('The restored approval identity is malformed.');
            }
            $request = $repository->lock($row['id'])
                ?? throw new RuntimeException('The restored approval request is missing.');
            $marker = match ($request->binding->payloadDigest()) {
                hash('sha256', 'spent') => 'spent',
                hash('sha256', 'available') => 'available',
                default => throw new RuntimeException('An unexpected approval fixture binding was found.'),
            };
            $expected = $marker === 'spent' ? ApprovalStatus::Consumed : ApprovalStatus::Approved;
            if ($request->status !== $expected || isset($result[$marker])) {
                throw new RuntimeException('The approval fixture has duplicate or unexpected terminal state.');
            }
            $result[$marker] = [
                'id' => $request->id,
                'status' => $request->status->value,
                'version' => $request->version,
                'binding' => $request->binding->digest(),
            ];
        }
        if (count($result) !== 2) {
            throw new RuntimeException('Both approval recovery controls are required.');
        }
        ksort($result);

        return $result;
    }

    /**
     * Refuse restored spent state under fresh authority, then consume a live positive control.
     *
     * @param   Container  $container  Restored kernel with the original APP_SECRET.
     *
     * @return  array<string, bool>  Behavior actually executed against the restored stores.
     *
     * @since   2.0.0
     */
    public static function accept(Container $container): array
    {
        $before = self::manifest($container);
        $principal = self::authenticate($container, 'maker');
        $session = self::session($container, $principal);
        $credential = self::service($container, StepUpCredentialStore::class)->active($principal->subject())
            ?? throw new RuntimeException('The restored maker second factor is absent.');
        $secret = self::service($container, StepUpSecretCipher::class)->decrypt(
            $credential->encryptedSecret,
            "kumwe-step-up-v1\0" . strtolower($credential->id) . "\0" . strtolower($credential->subjectId),
        );
        $counter = max(intdiv(time(), 30), ($credential->lastAcceptedTimeStep ?? 0) + 1);
        $verification = self::service($container, AdministratorStepUpProvider::class)->challenge(
            self::intent($principal, $session, self::ACTION),
            TotpCodes::fromRawSecret($secret, $counter),
            'backup-approval-acceptance',
        );
        $context = self::context($container, $principal, $verification);
        $repository = self::service($container, ApprovalRepository::class);
        $approvals = self::service($container, ApprovalService::class);
        $spent = $repository->lock($before['spent']['id'])
            ?? throw new RuntimeException('The spent approval disappeared.');
        try {
            $approvals->consume($context, $spent->id, $spent->binding);
            throw new RuntimeException('A restored consumed approval was reusable.');
        } catch (ApprovalDenied) {
            // Prove the denial left both state and the usable fresh proof unchanged.
        }
        if ($before !== self::manifest($container)) {
            throw new RuntimeException('The refused spent approval changed persisted state.');
        }
        $available = $repository->lock($before['available']['id'])
            ?? throw new RuntimeException('The approved positive control disappeared.');
        $approvals->consume($context, $available->id, $available->binding);
        if ($repository->lock($available->id)?->status !== ApprovalStatus::Consumed) {
            throw new RuntimeException('The same fresh proof could not consume the approved positive control.');
        }

        return ['consumed_approval_refused' => true, 'fresh_proof_positive_control_consumed' => true];
    }

    /**
     * Resolve a typed, already registered production service.
     * @template T of object
     * @param Container $container Booted kernel.
     * @param class-string<T> $id Service contract.
     * @return T Registered service.
     * @since 2.0.0
     */
    private static function service(Container $container, string $id): object
    {
        $service = $container->get($id);
        if (!$service instanceof $id) {
            throw new RuntimeException('The recovery acceptance service is unavailable: ' . $id);
        }

        return $service;
    }

    /**
     * Stable identity shared by seed and restore.
     * @param string $actor Fixture actor.
     * @return string Email.
     * @since 2.0.0
     */
    private static function email(string $actor): string
    {
        return 'backup-approval-' . $actor . '@example.test';
    }

    /**
     * Reauthenticate restored password and role authority.
     * @param Container $container Kernel.
     * @param string $actor Fixture actor.
     * @return AuthenticatedPrincipal Real identity.
     * @since 2.0.0
     */
    private static function authenticate(Container $container, string $actor): AuthenticatedPrincipal
    {
        return self::service($container, AdministratorIdentityGateway::class)->authenticate(
            self::email($actor),
            self::PASSWORD,
            'backup-approval-acceptance',
        ) ?? throw new RuntimeException('The approval fixture actor cannot authenticate.');
    }

    /**
     * Issue a persisted session.
     * @param Container $container Kernel.
     * @param AuthenticatedPrincipal $principal Authenticated actor.
     * @return string Session UUID.
     * @since 2.0.0
     */
    private static function session(Container $container, AuthenticatedPrincipal $principal): string
    {
        return self::service($container, AdministratorSessionStore::class)->create($principal->context(
            SiteContext::default(),
            AuthenticationStrength::Password,
            'backup-approval-login',
            surface: AuthenticatedSurface::Administrator,
        ), 'kumwe-recovery-approval/2.0')->session->id;
    }

    /**
     * Enroll through the live provider.
     * @param Container $container Source kernel.
     * @param string $actor Fixture actor.
     * @param string $purpose Exact single-use purpose.
     * @return array{AuthenticatedPrincipal, StepUpVerification, list<string>} Enrollment proof and recovery codes.
     * @since 2.0.0
     */
    private static function enroll(Container $container, string $actor, string $purpose): array
    {
        $principal = self::authenticate($container, $actor);
        $session = self::session($container, $principal);
        $provider = self::service($container, AdministratorStepUpProvider::class);
        $setup = $provider->beginEnrollment($principal->subject(), 'Kumwe', self::email($actor));
        $completion = $provider->confirmEnrollment(
            self::intent($principal, $session, $purpose),
            $setup->enrollmentId,
            TotpCodes::fromBase32($setup->secret, intdiv(time(), 30)),
            'backup-approval-acceptance',
        );

        return [$principal, $completion->verification, $completion->recoveryCodes];
    }

    /**
     * Bind a challenge to real scope and epoch.
     * @param AuthenticatedPrincipal $principal Actor.
     * @param string $session Persisted session.
     * @param string $purpose Exact operation.
     * @return StepUpIntent Live intent.
     * @since 2.0.0
     */
    private static function intent(AuthenticatedPrincipal $principal, string $session, string $purpose): StepUpIntent
    {
        return new StepUpIntent(
            $principal->subject(),
            $session,
            SiteContext::default()->identifier(),
            null,
            null,
            $purpose,
            $principal->securityEpoch(),
        );
    }

    /**
     * Attach production verification to a real administrator principal.
     * @param Container $container Kernel.
     * @param AuthenticatedPrincipal $principal Actor.
     * @param StepUpVerification $verification Accepted challenge.
     * @return ExecutionContext Proof-bearing context.
     * @since 2.0.0
     */
    private static function context(
        Container $container,
        AuthenticatedPrincipal $principal,
        StepUpVerification $verification,
    ): ExecutionContext {
        return $principal->context(
            SiteContext::default(),
            AuthenticationStrength::MultiFactor,
            'backup-approval-acceptance',
            surface: AuthenticatedSurface::Administrator,
            sessionId: $verification->rotatedSession->sessionId,
            stepUpProof: self::service($container, AuthorizationStepUpProofAdapter::class)->adapt($verification),
        );
    }
}
