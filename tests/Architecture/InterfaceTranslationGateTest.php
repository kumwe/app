<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use JsonException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Proves the translation gates in both directions: green on this tree, red on a reintroduction.
 *
 * A check that has only ever been observed passing is a check nobody knows works. Each gate here is
 * therefore run twice — once against the committed tree, and once against a copy of the tree with
 * the thing it forbids put back — and the failure is asserted to name the file and say what to do.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class InterfaceTranslationGateTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testTheCompiledCatalogueIsCurrentAgainstItsXliffSource(): void
    {
        [$status, $output] = $this->execute('tools/compile-catalogues.php', ['--check'], $this->root);

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('are current', $output);
    }

    public function testTheHardcodedStringGatePassesOnTheCommittedTree(): void
    {
        [$status, $output] = $this->execute('tools/verify-translated-strings.php', [], $this->root);

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('enforced', $output);
    }

    public function testTheHardcodedStringGateFailsWhenAnEnforcedTemplateGainsInlineText(): void
    {
        $tree = $this->treeCopy();
        file_put_contents(
            $tree . '/templates/site/home.twig',
            "{% extends \"layout.twig\" %}\n{% block content %}<p>Choose a published homepage.</p>{% endblock %}\n",
        );

        [$status, $output] = $this->execute('tools/verify-translated-strings.php', [], $tree);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('templates/site/home.twig', $output);
        self::assertStringContainsString('Choose a published homepage.', $output);
        self::assertStringContainsString('composer translation:compile', $output);
    }

    public function testTheHardcodedStringGateEnforcesATemplateNobodyRegistered(): void
    {
        $tree = $this->treeCopy();
        file_put_contents(
            $tree . '/templates/site/newcomer.twig',
            "<p>A brand new sentence nobody catalogued.</p>\n",
        );

        [$status, $output] = $this->execute('tools/verify-translated-strings.php', [], $tree);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('templates/site/newcomer.twig', $output);
    }

    public function testTheHardcodedStringGateRefusesAnIdentifierTheCatalogueDoesNotCarry(): void
    {
        $tree = $this->treeCopy();
        file_put_contents(
            $tree . '/templates/site/faq.twig',
            "{% extends \"layout.twig\" %}\n{% block content %}{{ t('core.site.faq.invented') }}{% endblock %}\n",
        );

        [$status, $output] = $this->execute('tools/verify-translated-strings.php', [], $tree);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('core.site.faq.invented', $output);
        self::assertStringContainsString('the source catalogue does not carry', $output);
    }

    public function testTheHardcodedStringGateRefusesACatalogueEntryNothingReferences(): void
    {
        $tree = $this->treeCopy();
        $compiled = $tree . '/resources/localization/compiled/en-GB.php';
        $contents = file_get_contents($compiled);
        self::assertIsString($contents);
        file_put_contents(
            $compiled,
            str_replace("return [\n", "return [\n    'core.orphan.example.message' => 'Orphan',\n", $contents),
        );

        [$status, $output] = $this->execute('tools/verify-translated-strings.php', [], $tree);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('core.orphan.example.message', $output);
        self::assertStringContainsString('no template references', $output);
    }

    public function testTheExtractionRegisterCannotOutliveTheWorkItRecords(): void
    {
        $tree = $this->treeCopy();
        $this->registerPendingTemplate($tree, 'templates/administrator/media.twig');
        unlink($tree . '/templates/administrator/media.twig');

        [$status, $output] = $this->execute('tools/verify-translated-strings.php', [], $tree);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('templates/administrator/media.twig', $output);
        self::assertStringContainsString('no longer exists', $output);
    }

    public function testAnExtractedTemplateMustLeaveTheRegisterRatherThanLingerInIt(): void
    {
        $tree = $this->treeCopy();
        $this->registerPendingTemplate($tree, 'templates/administrator/media.twig');

        [$status, $output] = $this->execute('tools/verify-translated-strings.php', [], $tree);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('remove it from the extraction register', $output);
    }

    public function testEveryRegisterEntryStatesWhyItIsExempt(): void
    {
        $encoded = file_get_contents($this->root . '/tools/translation-extraction.json');
        self::assertIsString($encoded);
        /** @var array{allowed_literals: list<array{value: string, reason: string}>,
         *      pending_extraction: list<array{path: string, reason: string}>} $register */
        $register = json_decode($encoded, true, 16, JSON_THROW_ON_ERROR);

        self::assertNotSame([], $register['allowed_literals']);
        foreach ($register['allowed_literals'] as $entry) {
            self::assertGreaterThan(20, strlen($entry['reason']), $entry['value']);
        }
        foreach ($register['pending_extraction'] as $entry) {
            self::assertGreaterThan(20, strlen($entry['reason']), $entry['path']);
            self::assertStringContainsString('V2-LNG-008', $entry['reason'], $entry['path']);
            self::assertFileExists($this->root . '/' . $entry['path']);
        }
    }

    /**
     * Every deliberately untranslatable source names a declared category, and every category a reason.
     *
     * This is the half of the widened gate a reader has to be able to audit: an exemption that does
     * not say which category it belongs to, or a category that does not say why it is not translated,
     * is indistinguishable from an oversight.
     *
     * @return  void
     *
     * @throws  JsonException  When the register is not valid JSON.
     *
     * @since   2.0.0
     */
    public function testEveryUntranslatableSourceNamesADeclaredCategoryAndItsReason(): void
    {
        $encoded = file_get_contents($this->root . '/tools/translation-extraction.json');
        self::assertIsString($encoded);
        /** @var array{untranslatable_categories: list<array{category: string, reason: string}>,
         *      untranslatable_sources: list<array{path: string, category: string, reason: string}>,
         *      user_facing_keys: list<string>} $register */
        $register = json_decode($encoded, true, 16, JSON_THROW_ON_ERROR);

        self::assertNotSame([], $register['user_facing_keys']);
        self::assertNotSame([], $register['untranslatable_categories']);
        $categories = [];
        foreach ($register['untranslatable_categories'] as $entry) {
            self::assertGreaterThan(40, strlen($entry['reason']), $entry['category']);
            $categories[$entry['category']] = true;
        }
        self::assertNotSame([], $register['untranslatable_sources']);
        foreach ($register['untranslatable_sources'] as $entry) {
            self::assertArrayHasKey($entry['category'], $categories, $entry['path']);
            self::assertGreaterThan(20, strlen($entry['reason']), $entry['path']);
            self::assertFileExists($this->root . '/' . $entry['path']);
        }
    }

    /**
     * A console command that writes wording inline instead of looking it up fails the gate.
     *
     * Console output is a translatable surface, so a `line()` carrying a sentence is exactly what the
     * widened scanner exists to catch; the failure has to name the file and the sentence.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheGateFailsWhenAConsoleCommandWritesWordingInline(): void
    {
        $tree = $this->treeCopy();
        $command = $tree . '/src/Delivery/Console/Command/HealthCheckCommand.php';
        $contents = file_get_contents($command);
        self::assertIsString($contents);
        file_put_contents($command, str_replace(
            "\$output->message('core.console.app_health.kumwe_is_ready');",
            "\$output->line('Kumwe is ready to serve traffic.');",
            $contents,
        ));

        [$status, $output] = $this->execute('tools/verify-translated-strings.php', [], $tree);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('HealthCheckCommand.php', $output);
        self::assertStringContainsString('Kumwe is ready to serve traffic.', $output);
        self::assertStringContainsString('message()', $output);
    }

    /**
     * A log line keeps its wording: the console rule reads the sink, not the method name.
     *
     * `error()` is both a console failure line and a PSR-3 log level. Refusing the second would make
     * the gate demand that log lines be translated, which the standard forbids, so this pins the
     * distinction the scanner draws.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheGateLeavesALoggerCallAlone(): void
    {
        $tree = $this->treeCopy();
        $middleware = $tree . '/src/Http/Middleware/ProblemDetailsMiddleware.php';
        $contents = file_get_contents($middleware);
        self::assertIsString($contents);
        file_put_contents($middleware, str_replace(
            "\$this->logger->error('Unhandled request exception.'",
            "\$this->logger->error('Unhandled request exception happened.'",
            $contents,
        ));

        [$status, $output] = $this->execute('tools/verify-translated-strings.php', [], $tree);

        self::assertSame(0, $status, $output);
    }

    /**
     * A user-facing error path that hardcodes its sentence fails the gate.
     *
     * The rendered-text half of the widened scanner is what keeps a new refusal from reaching an
     * operator as English regardless of the language the request resolved to.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheGateFailsWhenAnErrorPathHardcodesItsSentence(): void
    {
        $tree = $this->treeCopy();
        $handler = $tree . '/src/Administrator/Http/Handler/AdministratorLoginHandler.php';
        $contents = file_get_contents($handler);
        self::assertIsString($contents);
        file_put_contents($handler, str_replace(
            "'error' => \$this->translator->translate('core.administrator.login.invalid_credentials'),",
            "'error' => 'That email address and password do not match.',",
            $contents,
        ));

        [$status, $output] = $this->execute('tools/verify-translated-strings.php', [], $tree);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('AdministratorLoginHandler.php', $output);
        self::assertStringContainsString('That email address and password do not match.', $output);
    }

    /**
     * An exemption the tree no longer carries fails, exactly as a stale template entry does.
     *
     * An allowlist that outlives the file it excuses is how a gate quietly stops covering something,
     * so the register is held to the same freshness rule on both halves.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheGateRefusesAnExemptionForASourceThatNoLongerExists(): void
    {
        $tree = $this->treeCopy();
        $register = $tree . '/tools/translation-extraction.json';
        $contents = file_get_contents($register);
        self::assertIsString($contents);
        /** @var array{untranslatable_sources: list<array{path: string, category: string, reason: string}>} $decoded */
        $decoded = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        $decoded['untranslatable_sources'][] = [
            'path' => 'src/Delivery/Console/Command/RetiredCommand.php',
            'category' => $decoded['untranslatable_sources'][0]['category'],
            'reason' => 'Recorded by this fixture to prove a stale exemption is refused.',
        ];
        file_put_contents($register, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        [$status, $output] = $this->execute('tools/verify-translated-strings.php', [], $tree);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('RetiredCommand.php', $output);
        self::assertStringContainsString('no longer exists', $output);
    }

    /**
     * An exemption claiming a category the register never declared is refused before scanning.
     *
     * The category is what carries the reason a whole class of text is not translated, so an entry
     * inventing one would be an exemption with no stated justification behind it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheGateRefusesAnExemptionWithAnUndeclaredCategory(): void
    {
        $tree = $this->treeCopy();
        $register = $tree . '/tools/translation-extraction.json';
        $contents = file_get_contents($register);
        self::assertIsString($contents);
        /** @var array{untranslatable_sources: list<array{path: string, category: string, reason: string}>} $decoded */
        $decoded = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        $decoded['untranslatable_sources'][0]['category'] = 'because_i_said_so';
        file_put_contents($register, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        [$status, $output] = $this->execute('tools/verify-translated-strings.php', [], $tree);

        self::assertSame(65, $status, $output);
        self::assertStringContainsString('because_i_said_so', $output);
    }

    /**
     * A console identifier the catalogue does not carry fails, the way a template's does.
     *
     * The console half of the catalogue contract has to be proven in the same direction as the
     * template half, or a command could look up wording that resolves to its own identifier.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheGateRefusesAConsoleIdentifierTheCatalogueDoesNotCarry(): void
    {
        $tree = $this->treeCopy();
        $command = $tree . '/src/Delivery/Console/Command/HealthCheckCommand.php';
        $contents = file_get_contents($command);
        self::assertIsString($contents);
        file_put_contents($command, str_replace(
            "core.console.app_health.kumwe_is_ready",
            "core.console.app_health.invented_message",
            $contents,
        ));

        [$status, $output] = $this->execute('tools/verify-translated-strings.php', [], $tree);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('core.console.app_health.invented_message', $output);
        self::assertStringContainsString('the source catalogue does not carry', $output);
    }

    public function testTheStylesheetDirectionGatePassesOnTheCommittedTree(): void
    {
        [$status, $output] = $this->execute('tools/verify-stylesheet-direction.php', [], $this->root);

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('direction independent', $output);
    }

    public function testTheStylesheetDirectionGateFailsOnAReintroducedPhysicalDeclaration(): void
    {
        $tree = $this->treeCopy();
        $stylesheet = $tree . '/assets/site/styles.css';
        $contents = file_get_contents($stylesheet);
        self::assertIsString($contents);
        file_put_contents($stylesheet, $contents . "\n.reintroduced { margin-left: 1rem; text-align: left; }\n");

        [$status, $output] = $this->execute('tools/verify-stylesheet-direction.php', [], $tree);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('assets/site/styles.css', $output);
        self::assertStringContainsString('margin-inline-start', $output);
        self::assertStringContainsString('text-align: start', $output);
    }

    public function testTheThreeLayoutsEmitLanguageAndDirectionFromTheResolvedLocale(): void
    {
        foreach (['site', 'administrator', 'portal'] as $surface) {
            $layout = file_get_contents($this->root . '/templates/' . $surface . '/layout.twig');
            self::assertIsString($layout);
            self::assertStringContainsString('lang="{{ locale_tag() }}"', $layout, $surface);
            self::assertStringContainsString('dir="{{ text_direction() }}"', $layout, $surface);
            self::assertStringNotContainsString('<html lang="en">', $layout, $surface);
        }
    }

    /**
     * Record one template in a tree copy's extraction register, as the pending era did.
     *
     * The committed register holds no pending templates any more, so the register semantics —
     * a stale entry fails, a lingering entry fails — are proven against an entry this writes
     * into the copy rather than against an entry the tree no longer carries.
     *
     * @param  string  $tree      Root of the copied tree whose register is edited.
     * @param  string  $template  Repository-relative template path to record as pending.
     *
     * @return void
     *
     * @since  2.0.0
     */
    private function registerPendingTemplate(string $tree, string $template): void
    {
        $path = $tree . '/tools/translation-extraction.json';
        $encoded = file_get_contents($path);
        self::assertIsString($encoded);
        /** @var array{pending_extraction: list<array{path: string, reason: string}>} $register */
        $register = json_decode($encoded, true, 16, JSON_THROW_ON_ERROR);
        $register['pending_extraction'][] = [
            'path' => $template,
            'reason' => 'Awaiting extraction, reintroduced by this test fixture. V2-LNG-008.',
        ];
        file_put_contents($path, json_encode($register, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Run one gate against a tree and capture what an author would see.
     *
     * @param  string        $script     Repository-relative path of the gate.
     * @param  list<string>  $arguments  Arguments passed to the gate.
     * @param  string        $tree       Root the gate runs against.
     *
     * @return array{0: int, 1: string}  Exit status and combined output.
     *
     * @since  2.0.0
     */
    private function execute(string $script, array $arguments, string $tree): array
    {
        $command = escapeshellcmd(PHP_BINARY) . ' ' . escapeshellarg($tree . '/' . $script);
        foreach ($arguments as $argument) {
            $command .= ' ' . escapeshellarg($argument);
        }
        $output = [];
        $status = 0;
        exec($command . ' 2>&1', $output, $status);

        return [$status, implode("\n", $output)];
    }

    /**
     * Copy the parts of the tree the gates read into a scratch directory.
     *
     * @return string  Absolute path of the copy, removed when the process ends.
     *
     * @since  2.0.0
     */
    private function treeCopy(): string
    {
        $tree = sys_get_temp_dir() . '/kumwe-translation-gate-' . bin2hex(random_bytes(6));
        foreach (['templates', 'assets', 'resources/localization', 'tools', 'src'] as $directory) {
            $this->copyTree($this->root . '/' . $directory, $tree . '/' . $directory);
        }
        register_shutdown_function(function () use ($tree): void {
            $this->removeTree($tree);
        });

        return $tree;
    }

    /**
     * Copy one directory tree recursively.
     *
     * @param  string  $from  Source directory.
     * @param  string  $to    Destination directory.
     *
     * @return void
     *
     * @since  2.0.0
     */
    private function copyTree(string $from, string $to): void
    {
        if (!is_dir($to)) {
            mkdir($to, 0o775, true);
        }
        $entries = scandir($from);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $source = $from . '/' . $entry;
            if (is_dir($source)) {
                $this->copyTree($source, $to . '/' . $entry);
                continue;
            }
            copy($source, $to . '/' . $entry);
        }
    }

    /**
     * Remove one directory tree recursively.
     *
     * @param  string  $path  Directory to remove.
     *
     * @return void
     *
     * @since  2.0.0
     */
    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->removeTree($child) : unlink($child);
        }
        rmdir($path);
    }
}
