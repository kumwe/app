<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Pins the metadata every audit-writing service records, so a credential-shaped key cannot be added unseen.
 *
 * `DoctrineAuditRecorder` redacts credential-shaped keys and long opaque values before anything is stored or
 * digested, which is the runtime guard. This test is the other half GM-AUD-08 asks for: it reads every
 * `new AuditEvent(...)` construction under `src/`, takes the literal metadata keys each writing service
 * passes, and asserts that none of them is credential-shaped. The inventory of writing files is pinned as
 * well, so a new audit writer is reviewed here rather than trusted to the redactor alone. A key that is an
 * identifier but merely contains a denylisted fragment is listed with its reason; the redactor still masks
 * its value at write time, which loses nothing secret.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class AuditMetadataWriterPolicyTest extends TestCase
{
    /**
     * Fragments the package redactor treats as credential-shaped, compared against normalized keys.
     *
     * Kept identical to `Kumwe\Audit\Application\AuditMetadataRedactor`'s denylist; the second test proves it.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array FRAGMENTS = [
        'apikey',
        'authorization',
        'cookie',
        'credential',
        'passphrase',
        'password',
        'private',
        'recoverycode',
        'secret',
        'signature',
        'token',
    ];

    /**
     * Reviewed literal keys that contain a fragment but carry an identifier, never a credential.
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private const array REVIEWED = [
        'parent_token_id' => 'Token issuance records the parent token row identifier; `rotated_from` carries the '
            . 'same lineage unmasked, and the redactor masks this copy, so no secret is held.',
        'replacement_token_id' => 'Token rotation records the new token row identifier; the redactor masks it '
            . 'anyway, so the audit row loses an identifier and never holds a secret.',
    ];

    /**
     * No writing service passes a credential-shaped literal metadata key, and the writer inventory is pinned.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNoWritingServiceRecordsACredentialShapedMetadataKey(): void
    {
        $writers = $this->writers();
        $violations = [];
        foreach ($writers as $file => $keys) {
            foreach ($keys as $key) {
                $normalized = preg_replace('/[^a-z0-9]+/', '', strtolower($key)) ?? '';
                foreach (self::FRAGMENTS as $fragment) {
                    if (str_contains($normalized, $fragment) && !array_key_exists($key, self::REVIEWED)) {
                        $violations[] = $file . ': ' . $key;
                    }
                }
            }
        }

        self::assertSame([], $violations, 'Audit metadata must not carry credential-shaped keys.');
        self::assertSame(self::expectedWriters(), array_keys($writers), 'A new audit writer must be reviewed here.');
    }

    /**
     * The fragment list here is the package redactor's list, so the static and runtime guards agree.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheStaticDenylistMatchesThePackageRedactor(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/vendor/kumwe/audit/src/Application/AuditMetadataRedactor.php',
        );
        self::assertIsString($source);
        self::assertSame(1, preg_match('/KEY_FRAGMENTS = \[(.*?)\];/s', $source, $match));
        preg_match_all("/'([a-z]+)'/", $match[1], $fragments);
        self::assertSame(self::FRAGMENTS, $fragments[1]);
    }

    /**
     * Collect the literal metadata keys each `src/` file passes to `new AuditEvent(...)`.
     *
     * @return  array<string, list<string>>  Repository-relative file path to its sorted literal keys.
     *
     * @since   2.0.0
     */
    private function writers(): array
    {
        $root = dirname(__DIR__, 2);
        $writers = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src'));
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            if (!is_string($source) || !str_contains($source, 'new AuditEvent(')) {
                continue;
            }
            $keys = [];
            foreach ($this->metadataArguments($source) as $argument) {
                foreach ($argument as $index => $token) {
                    if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                        continue;
                    }
                    $next = $index + 1;
                    while (is_array($argument[$next] ?? null) && $argument[$next][0] === T_WHITESPACE) {
                        ++$next;
                    }
                    if (is_array($argument[$next] ?? null) && $argument[$next][0] === T_DOUBLE_ARROW) {
                        $keys[] = trim($token[1], '\'"');
                    }
                }
            }
            $keys = array_values(array_unique($keys));
            sort($keys);
            $writers[substr($file->getPathname(), strlen($root) + 1)] = $keys;
        }
        ksort($writers);

        return $writers;
    }

    /**
     * Return the token lists of every eighth (metadata) argument of `new AuditEvent(...)` in one source.
     *
     * @param   string  $source  PHP source text.
     *
     * @return  list<list<mixed>>  Tokens of each metadata argument; calls without one contribute nothing.
     *
     * @since   2.0.0
     */
    private function metadataArguments(string $source): array
    {
        $tokens = token_get_all($source);
        $arguments = [];
        $count = count($tokens);
        for ($index = 0; $index < $count; ++$index) {
            $token = $tokens[$index];
            if (!is_array($token) || $token[0] !== T_NEW) {
                continue;
            }
            $cursor = $index + 1;
            while (is_array($tokens[$cursor] ?? null) && $tokens[$cursor][0] === T_WHITESPACE) {
                ++$cursor;
            }
            $name = $tokens[$cursor] ?? null;
            if (!is_array($name) || !str_ends_with($name[1], 'AuditEvent') || ($tokens[$cursor + 1] ?? null) !== '(') {
                continue;
            }
            $depth = 0;
            $position = 0;
            $current = [];
            for ($scan = $cursor + 1; $scan < $count; ++$scan) {
                $part = $tokens[$scan];
                $text = is_array($part) ? $part[1] : $part;
                if (in_array($text, ['(', '[', '{'], true) || (is_array($part) && $part[0] === T_CURLY_OPEN)) {
                    ++$depth;
                    if ($depth === 1) {
                        continue;
                    }
                } elseif (in_array($text, [')', ']', '}'], true)) {
                    --$depth;
                    if ($depth === 0) {
                        break;
                    }
                } elseif ($text === ',' && $depth === 1) {
                    ++$position;
                    continue;
                }
                if ($position === 7) {
                    $current[] = $part;
                }
            }
            if ($current !== []) {
                $arguments[] = $current;
            }
        }

        return $arguments;
    }

    /**
     * The reviewed inventory of files that construct audit events.
     *
     * @return  list<string>  Repository-relative paths in sorted order.
     *
     * @since   2.0.0
     */
    private static function expectedWriters(): array
    {
        return [
            'src/Application/Authorization/ResourceOwnershipScopeService.php',
            'src/Application/Authorization/SiteGroupAdministration.php',
            'src/Application/Automation/AutomationManagementService.php',
            'src/Application/Presentation/Preference/PresentationPreferenceManager.php',
            'src/Audit/Infrastructure/Persistence/DoctrineAuditAnchorWriter.php',
            'src/Audit/Infrastructure/Persistence/DoctrineAuditRetentionService.php',
            'src/Audit/Infrastructure/Persistence/DoctrineAuditTrailExporter.php',
            'src/BusinessDefinition/Application/BusinessDefinitionService.php',
            'src/BusinessDefinition/Infrastructure/Persistence/DoctrinePackageDefinitionSynchronizer.php',
            'src/BusinessIntegration/Application/IntegrationOperationsService.php',
            'src/BusinessRecord/Application/BusinessRecordMutationPublication.php',
            'src/BusinessRecord/Application/PostingPeriodService.php',
            'src/BusinessRecord/Infrastructure/Persistence/DoctrineRecordSecretRotation.php',
            'src/BusinessReporting/Application/ExportGenerationService.php',
            'src/BusinessReporting/Application/ExportService.php',
            'src/BusinessSchema/Application/BusinessSchemaExecutor.php',
            'src/BusinessSchema/Application/BusinessSchemaPlanner.php',
            'src/BusinessSchema/Application/BusinessSchemaService.php',
            'src/BusinessSecurity/Application/Administration/BusinessSecurityAdministrationService.php',
            'src/Content/Application/ContentModelService.php',
            'src/Content/Application/ContentService.php',
            'src/Demo/Infrastructure/DemoAccessProvisioner.php',
            'src/Demo/Infrastructure/VdmBusinessDemoInstaller.php',
            'src/Extension/Application/Trust/RevocationFeedSynchronizer.php',
            'src/Extension/Application/Trust/TrustStore.php',
            'src/Extension/Infrastructure/DoctrineExtensionManager.php',
            'src/Identity/Application/Administration/AccessControlService.php',
            'src/Identity/Application/StepUp/TotpStepUpProvider.php',
            'src/Identity/Infrastructure/Administration/DoctrineAdministratorIdentityGateway.php',
            'src/Localization/Application/MessageOverrideService.php',
            'src/Media/Application/MediaService.php',
            'src/Navigation/Application/NavigationService.php',
            'src/Presentation/Infrastructure/DoctrineAdministratorThemeRecovery.php',
            'src/Site/Infrastructure/Persistence/DoctrineSiteSettings.php',
            'src/Studio/Application/Composition/StudioContentCompositionService.php',
            'src/Studio/Application/Host/StudioProducerMutationBoundary.php',
            'src/Studio/Application/Media/StudioMediaService.php',
        ];
    }
}
