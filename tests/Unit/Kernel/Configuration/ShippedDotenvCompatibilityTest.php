<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Kernel\Configuration;

use Kumwe\App\Extension\Application\Package\PackageConformanceMode;
use Kumwe\App\Kernel\Configuration\ApplicationConfiguration;
use Kumwe\App\Kernel\Configuration\ConfigurationFactory;
use Kumwe\App\Kernel\Configuration\RuntimeEnvironment;
use Kumwe\App\Shared\Infrastructure\Configuration\Environment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins that every dotenv file this repository has told an operator to copy still boots the configuration.
 *
 * A developer copies `.env.example` once and keeps the resulting `.env` across many pulls, so the file on
 * disk reflects the example as it was on the day of the copy, not as it is today. The example shipped at
 * the Gate A acceptance is kept as a fixture beside the live one, and both must pass `ConfigurationFactory`
 * unchanged: a variable whose accepted vocabulary moves must keep reading the spellings it used to document,
 * or every existing installation stops at configuration time on the next deploy. The file contents are
 * read as written, with no process-environment overlay, so the proof is about the shipped values alone.
 *
 * @since  2.0.0
 */
#[CoversClass(ConfigurationFactory::class)]
#[CoversClass(ApplicationConfiguration::class)]
final class ShippedDotenvCompatibilityTest extends TestCase
{
    /**
     * Proves the example a developer copies today produces a development configuration as documented.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCurrentEnvironmentExampleBootsAsDocumented(): void
    {
        $configuration = $this->configurationFrom(dirname(__DIR__, 4) . '/.env.example');

        self::assertSame(RuntimeEnvironment::Development, $configuration->environment);
        self::assertTrue($configuration->debug);
        self::assertSame('http://localhost:8080', $configuration->baseUrl);
        self::assertSame('documentation', $configuration->siteContentProfile);
        self::assertSame('vdm', $configuration->businessProfile);
        self::assertTrue($configuration->allowUnsignedLocalExtensions);
        self::assertSame(PackageConformanceMode::Scan, $configuration->packageConformanceAdmission);
        self::assertSame('mariadb', $configuration->database->driver);
        self::assertSame('kumwe', $configuration->database->database);
        self::assertSame('redis', $configuration->redis->host);
    }

    /**
     * Proves a `.env` copied from the Gate A example, which spelled the admission mode `enforce`, still boots.
     *
     * The fixture is the example file as accepted at Gate A, byte for byte. It carries the pre-rename
     * `EXTENSIONS_CONFORMANCE_ADMISSION=enforce`, which is what every development installation created
     * before the rename has on disk, and it must resolve to the scanning posture rather than refuse.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGateAEnvironmentExampleStillBootsAfterTheAdmissionRename(): void
    {
        $configuration = $this->configurationFrom(__DIR__ . '/../../../Fixtures/Configuration/gate-a-development.env');

        self::assertSame(RuntimeEnvironment::Development, $configuration->environment);
        self::assertSame('documentation', $configuration->siteContentProfile);
        self::assertSame('vdm', $configuration->businessProfile);
        self::assertSame(PackageConformanceMode::Scan, $configuration->packageConformanceAdmission);
        self::assertSame('kumwe_', $configuration->database->tablePrefix);
    }

    /**
     * Proves the Gate A fixture really carries the pre-rename spelling, so the proof above cannot go stale.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testGateAFixtureCarriesThePreRenameAdmissionSpelling(): void
    {
        $values = $this->dotenvValues(__DIR__ . '/../../../Fixtures/Configuration/gate-a-development.env');

        self::assertSame('enforce', $values['EXTENSIONS_CONFORMANCE_ADMISSION'] ?? null);
        self::assertNull(PackageConformanceMode::tryFrom('enforce'));
    }

    /**
     * Build the configuration from one dotenv file's own values, with no process-environment overlay.
     *
     * @param   string  $path  Absolute path to the dotenv file under proof.
     *
     * @return  ApplicationConfiguration  The configuration the file's values produce.
     *
     * @since   2.0.0
     */
    private function configurationFrom(string $path): ApplicationConfiguration
    {
        return (new ConfigurationFactory())->create(new Environment($this->dotenvValues($path)));
    }

    /**
     * Read the plain `KEY=value` assignments of a shipped dotenv file, skipping comments and blank lines.
     *
     * The shipped examples use no quoting or escapes, so a literal split is the whole grammar they need;
     * the production parser's quoting rules are proven in `EnvironmentTest`.
     *
     * @param   string  $path  Absolute path to the dotenv file.
     *
     * @return  array<string, string>  Values by variable name, exactly as written.
     *
     * @since   2.0.0
     */
    private function dotenvValues(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines, sprintf('The dotenv file %s must be readable.', $path));

        $values = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $separator = strpos($line, '=');
            self::assertNotFalse($separator, sprintf('Every assignment in %s must carry an equals sign.', $path));
            $values[trim(substr($line, 0, $separator))] = trim(substr($line, $separator + 1));
        }

        return $values;
    }
}
