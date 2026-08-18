<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Delivery\Console;

use Kumwe\CMS\BusinessReporting\Delivery\Console\ReportCommand;
use Kumwe\CMS\Delivery\Console\Command;
use Kumwe\CMS\Delivery\Console\Command\ActivateExtensionCommand;
use Kumwe\CMS\Delivery\Console\Command\BuildExtensionCommand;
use Kumwe\CMS\Delivery\Console\Command\CreateAccessTokenCommand;
use Kumwe\CMS\Delivery\Console\Command\CreateAdministratorCommand;
use Kumwe\CMS\Delivery\Console\Command\DemoAccessCommand;
use Kumwe\CMS\Delivery\Console\Command\DemoExamplesCommand;
use Kumwe\CMS\Delivery\Console\Command\DemoExportCommand;
use Kumwe\CMS\Delivery\Console\Command\DemoInstallCommand;
use Kumwe\CMS\Delivery\Console\Command\DisableExtensionCommand;
use Kumwe\CMS\Delivery\Console\Command\ExportAuditTrailCommand;
use Kumwe\CMS\Delivery\Console\Command\HealthCheckCommand;
use Kumwe\CMS\Delivery\Console\Command\InspectExtensionCommand;
use Kumwe\CMS\Delivery\Console\Command\InstallExtensionCommand;
use Kumwe\CMS\Delivery\Console\Command\IntegrationWorkCommand;
use Kumwe\CMS\Delivery\Console\Command\ListExtensionsCommand;
use Kumwe\CMS\Delivery\Console\Command\ManageAccessCommand;
use Kumwe\CMS\Delivery\Console\Command\ManageAutomationCommand;
use Kumwe\CMS\Delivery\Console\Command\ManageBusinessDefinitionsCommand;
use Kumwe\CMS\Delivery\Console\Command\ManageBusinessRecordsCommand;
use Kumwe\CMS\Delivery\Console\Command\ManageBusinessSchemaCommand;
use Kumwe\CMS\Delivery\Console\Command\ManageContentCommand;
use Kumwe\CMS\Delivery\Console\Command\ManageContentModelsCommand;
use Kumwe\CMS\Delivery\Console\Command\ManageIntegrationsCommand;
use Kumwe\CMS\Delivery\Console\Command\ManageNavigationCommand;
use Kumwe\CMS\Delivery\Console\Command\ManagePostingPeriodsCommand;
use Kumwe\CMS\Delivery\Console\Command\ManageSettingsCommand;
use Kumwe\CMS\Delivery\Console\Command\ManageTrustStoreCommand;
use Kumwe\CMS\Delivery\Console\Command\MaterializeExtensionRuntimeCommand;
use Kumwe\CMS\Delivery\Console\Command\McpServeCommand;
use Kumwe\CMS\Delivery\Console\Command\MigrateCommand;
use Kumwe\CMS\Delivery\Console\Command\MigrationStatusCommand;
use Kumwe\CMS\Delivery\Console\Command\QueueWorkCommand;
use Kumwe\CMS\Delivery\Console\Command\RecoverAdministratorThemeCommand;
use Kumwe\CMS\Delivery\Console\Command\RecoverCredentialsCommand;
use Kumwe\CMS\Delivery\Console\Command\RecoverMigrationLockCommand;
use Kumwe\CMS\Delivery\Console\Command\RotateRecordSecretsCommand;
use Kumwe\CMS\Delivery\Console\Command\RunExtensionConformanceCommand;
use Kumwe\CMS\Delivery\Console\Command\ScaffoldExtensionCommand;
use Kumwe\CMS\Delivery\Console\Command\ScheduleRunCommand;
use Kumwe\CMS\Delivery\Console\Command\SignExtensionCommand;
use Kumwe\CMS\Delivery\Console\Command\UninstallExtensionCommand;
use Kumwe\CMS\Delivery\Console\Command\VerifyAuditTrailCommand;
use Kumwe\CMS\Delivery\Console\Command\WatchExtensionRuntimeCommand;
use Kumwe\CMS\Localization\Domain\MessageIdentifier;
use Kumwe\CMS\Tests\Support\InterfaceTranslation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins the summary line every command contributes to `bin/kumwe list`.
 *
 * A description is the one piece of wording a command surrenders to the dispatcher: it returns an
 * identifier and the listing resolves it, which is what lets the listing be translated without any
 * command carrying a translator. An identifier the catalogue does not carry would print itself, so
 * this walks the whole command set rather than sampling it.
 *
 * @since  2.0.0
 */
#[CoversClass(ActivateExtensionCommand::class)]
#[CoversClass(BuildExtensionCommand::class)]
#[CoversClass(CreateAccessTokenCommand::class)]
#[CoversClass(CreateAdministratorCommand::class)]
#[CoversClass(DemoAccessCommand::class)]
#[CoversClass(DemoExamplesCommand::class)]
#[CoversClass(DemoExportCommand::class)]
#[CoversClass(DemoInstallCommand::class)]
#[CoversClass(DisableExtensionCommand::class)]
#[CoversClass(ExportAuditTrailCommand::class)]
#[CoversClass(HealthCheckCommand::class)]
#[CoversClass(InspectExtensionCommand::class)]
#[CoversClass(InstallExtensionCommand::class)]
#[CoversClass(IntegrationWorkCommand::class)]
#[CoversClass(ListExtensionsCommand::class)]
#[CoversClass(ManageAccessCommand::class)]
#[CoversClass(ManageAutomationCommand::class)]
#[CoversClass(ManageBusinessDefinitionsCommand::class)]
#[CoversClass(ManageBusinessRecordsCommand::class)]
#[CoversClass(ManageBusinessSchemaCommand::class)]
#[CoversClass(ManageContentCommand::class)]
#[CoversClass(ManageContentModelsCommand::class)]
#[CoversClass(ManageIntegrationsCommand::class)]
#[CoversClass(ManageNavigationCommand::class)]
#[CoversClass(ManagePostingPeriodsCommand::class)]
#[CoversClass(ManageSettingsCommand::class)]
#[CoversClass(ManageTrustStoreCommand::class)]
#[CoversClass(MaterializeExtensionRuntimeCommand::class)]
#[CoversClass(McpServeCommand::class)]
#[CoversClass(MigrateCommand::class)]
#[CoversClass(MigrationStatusCommand::class)]
#[CoversClass(QueueWorkCommand::class)]
#[CoversClass(RecoverAdministratorThemeCommand::class)]
#[CoversClass(RecoverCredentialsCommand::class)]
#[CoversClass(RecoverMigrationLockCommand::class)]
#[CoversClass(RotateRecordSecretsCommand::class)]
#[CoversClass(RunExtensionConformanceCommand::class)]
#[CoversClass(ScaffoldExtensionCommand::class)]
#[CoversClass(ScheduleRunCommand::class)]
#[CoversClass(SignExtensionCommand::class)]
#[CoversClass(UninstallExtensionCommand::class)]
#[CoversClass(VerifyAuditTrailCommand::class)]
#[CoversClass(WatchExtensionRuntimeCommand::class)]
#[CoversClass(ReportCommand::class)]
final class CommandDescriptionTest extends TestCase
{
    /**
     * Every command names its summary with a valid identifier the catalogue actually carries.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryCommandDescribesItselfWithACatalogueMessage(): void
    {
        $translator = InterfaceTranslation::translator();
        foreach (self::commands() as $class) {
            $command = (new ReflectionClass($class))->newInstanceWithoutConstructor();
            self::assertInstanceOf(Command::class, $command);
            $identifier = $command->description();

            self::assertTrue(
                MessageIdentifier::isValid($identifier),
                sprintf('%s returns %s, which is not a message identifier.', $class, $identifier),
            );
            self::assertTrue(
                $translator->has($identifier),
                sprintf('%s names %s, which the source catalogue does not carry.', $class, $identifier),
            );
            self::assertNotSame(
                $identifier,
                $translator->translate($identifier),
                sprintf('%s resolves to its own identifier.', $class),
            );
        }
    }

    /**
     * Every command's name stays the stable, colon-separated token an operator types.
     *
     * The name is machinery, not wording: a translated command name would break every script and
     * runbook that invokes it, so it must not have moved into the catalogue with the summary.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryCommandKeepsItsNameOutOfTheCatalogue(): void
    {
        $names = [];
        foreach (self::commands() as $class) {
            $command = (new ReflectionClass($class))->newInstanceWithoutConstructor();
            self::assertInstanceOf(Command::class, $command);
            $name = $command->name();

            self::assertMatchesRegularExpression('/^[a-z][a-z0-9:-]*$/D', $name, $class);
            self::assertArrayNotHasKey($name, $names, sprintf('%s repeats the command name %s.', $class, $name));
            $names[$name] = true;
        }

        self::assertCount(44, $names);
    }

    /**
     * Every dispatchable command class, the reporting one outside the command directory included.
     *
     * @return  list<class-string<Command>>  The full registered command set.
     *
     * @since   2.0.0
     */
    private static function commands(): array
    {
        return [
            ActivateExtensionCommand::class,
            BuildExtensionCommand::class,
            CreateAccessTokenCommand::class,
            CreateAdministratorCommand::class,
            DemoAccessCommand::class,
            DemoExamplesCommand::class,
            DemoExportCommand::class,
            DemoInstallCommand::class,
            DisableExtensionCommand::class,
            ExportAuditTrailCommand::class,
            HealthCheckCommand::class,
            InspectExtensionCommand::class,
            InstallExtensionCommand::class,
            IntegrationWorkCommand::class,
            ListExtensionsCommand::class,
            ManageAccessCommand::class,
            ManageAutomationCommand::class,
            ManageBusinessDefinitionsCommand::class,
            ManageBusinessRecordsCommand::class,
            ManageBusinessSchemaCommand::class,
            ManageContentCommand::class,
            ManageContentModelsCommand::class,
            ManageIntegrationsCommand::class,
            ManageNavigationCommand::class,
            ManagePostingPeriodsCommand::class,
            ManageSettingsCommand::class,
            ManageTrustStoreCommand::class,
            MaterializeExtensionRuntimeCommand::class,
            McpServeCommand::class,
            MigrateCommand::class,
            MigrationStatusCommand::class,
            QueueWorkCommand::class,
            RecoverAdministratorThemeCommand::class,
            RecoverCredentialsCommand::class,
            RecoverMigrationLockCommand::class,
            RotateRecordSecretsCommand::class,
            RunExtensionConformanceCommand::class,
            ScaffoldExtensionCommand::class,
            ScheduleRunCommand::class,
            SignExtensionCommand::class,
            UninstallExtensionCommand::class,
            VerifyAuditTrailCommand::class,
            WatchExtensionRuntimeCommand::class,
            ReportCommand::class,
        ];
    }
}
