<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Proves the catalogue quality gate in both directions: green on the committed catalogues, red on each defect.
 *
 * `tools/verify-catalogue-quality.php` claims to catch what a completeness check lets through — an ICU
 * pattern a locale cannot format, an argument a translation dropped, a plural form a language needs but
 * the pattern omits, a target left in English, and a register entry that excuses nothing. Each claim is
 * exercised against a copy of the real catalogues with exactly that defect put in, and the failure is
 * asserted to name the locale and the identifier, so a translator can act on it.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class CatalogueQualityGateTest extends TestCase
{
    /**
     * Repository root the gate and the catalogues are read from.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $root;

    /**
     * Resolve the repository root.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    /**
     * The nine committed catalogues are qualified, and the report names every locale and its plural set.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheCommittedCataloguesAreQualified(): void
    {
        [$status, $output] = $this->gate(null);

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('The 9 interface catalogues are qualified', $output);
        foreach (['en-GB', 'en-US', 'af', 'de', 'he', 'ar', 'es', 'pt-BR', 'zh-Hans'] as $locale) {
            self::assertMatchesRegularExpression('/^  ' . preg_quote($locale, '/') . '\s+\d+ units/m', $output);
        }
        self::assertStringContainsString('{zero, one, two, few, many, other}', $output);
        self::assertStringContainsString('{other, one, two}', $output);
        self::assertMatchesRegularExpression('/zh-Hans .* checked against \{other\}/', $output);
    }

    /**
     * A pattern ICU refuses for its locale fails the gate and names the unit.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAPatternIcuRefusesFailsTheGate(): void
    {
        $catalogues = $this->catalogueCopy();
        $this->setTarget(
            $catalogues,
            'de',
            'core.administrator.content_list.items_on_this_page',
            '{count, plural, one {# Element',
        );

        [$status, $output] = $this->gate($catalogues);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString(
            'de core.administrator.content_list.items_on_this_page is refused by ICU',
            $output,
        );
    }

    /**
     * A translation that drops or renames an argument its source names fails the gate.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testATranslationThatDropsAnArgumentFailsTheGate(): void
    {
        $catalogues = $this->catalogueCopy();
        $this->setTarget(
            $catalogues,
            'es',
            'core.administrator.media.file_page_of',
            '{count, plural, one {# archivo} other {# archivos}} · Página {pagina}',
        );

        [$status, $output] = $this->gate($catalogues);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString(
            'es core.administrator.media.file_page_of names arguments {count}, {pagina} but its source names '
                . '{count}, {page}, {pages}.',
            $output,
        );
    }

    /**
     * Arabic without its `few` form and Hebrew without its dual both fail, each naming the missing category.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMissingPluralCategoryFailsTheGate(): void
    {
        $catalogues = $this->catalogueCopy();
        $this->setTarget(
            $catalogues,
            'ar',
            'core.administrator.media.file_count',
            '{count, plural, zero {# ملفات} one {# ملف} two {# ملفين} many {# ملف} other {# ملف}}',
        );
        $this->setTarget(
            $catalogues,
            'he',
            'core.administrator.media.file_count',
            '{count, plural, one {# קובץ} other {# קבצים}}',
        );

        [$status, $output] = $this->gate($catalogues);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString(
            'ar core.administrator.media.file_count has no `few` form for {count}; ICU selects `few` for 3',
            $output,
        );
        self::assertStringContainsString(
            'he core.administrator.media.file_count has no `two` form for {count}; ICU selects `two` for 2',
            $output,
        );
    }

    /**
     * A source plural without the `one` form English needs fails in the source language too.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testASourcePluralWithoutItsOneFormFailsTheGate(): void
    {
        $catalogues = $this->catalogueCopy();
        $this->setSource(
            $catalogues,
            'en-GB',
            'core.administrator.business_definitions.definitions',
            '{count, plural, other {# definitions}}',
        );

        [$status, $output] = $this->gate($catalogues);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString(
            'en-GB core.administrator.business_definitions.definitions has no `one` form for {count}',
            $output,
        );
    }

    /**
     * A non-English target left identical to prose English fails and names what to do.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnUntranslatedTargetFailsTheGate(): void
    {
        $catalogues = $this->catalogueCopy();
        $this->setTarget($catalogues, 'he', 'core.site.layout.skip_to_content', 'Skip to content');

        [$status, $output] = $this->gate($catalogues);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString(
            'he core.site.layout.skip_to_content is left identical to its English source "Skip to content"',
            $output,
        );
        self::assertStringContainsString('tools/verify-catalogue-quality.php', $output);
    }

    /**
     * A register entry that no longer excuses any identical target fails as stale.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAStaleRegisterEntryFailsTheGate(): void
    {
        $catalogues = $this->catalogueCopy();
        $this->replaceTargetText($catalogues, 'de', 'Quorum', 'Beschlussfähigkeit');

        [$status, $output] = $this->gate($catalogues);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('excuses "Quorum" in de, but no de target is identical to it', $output);
    }

    /**
     * A Studio shell message is held to placeholder parity, because Studio, not ICU, formats it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAStudioShellMessageMustKeepItsPlaceholders(): void
    {
        $catalogues = $this->catalogueCopy();
        $this->setTarget(
            $catalogues,
            'pt-BR',
            'core.studio.shell.inspector-binding-accepts',
            'Aceita valor {value-type}',
        );

        [$status, $output] = $this->gate($catalogues);

        self::assertSame(1, $status, $output);
        self::assertStringContainsString(
            'pt-BR core.studio.shell.inspector-binding-accepts names placeholders {value-type} but its source '
                . 'names {cardinality}, {value-type}.',
            $output,
        );
    }

    /**
     * Run the gate against the committed catalogues or a modified copy.
     *
     * @param   ?string  $catalogues  Directory of `<locale>.xlf` files, or null for the committed ones.
     *
     * @return  array{0: int, 1: string}  Exit status and combined output.
     *
     * @since   2.0.0
     */
    private function gate(?string $catalogues): array
    {
        $command = escapeshellcmd(PHP_BINARY) . ' '
            . escapeshellarg($this->root . '/tools/verify-catalogue-quality.php');
        if ($catalogues !== null) {
            $command .= ' ' . escapeshellarg('--catalogues=' . $catalogues);
        }
        $output = [];
        $status = 0;
        exec($command . ' 2>&1', $output, $status);

        return [$status, implode("\n", $output)];
    }

    /**
     * Copy the nine authored catalogues into a scratch directory removed when the process ends.
     *
     * @return  string  Absolute path of the copy.
     *
     * @since   2.0.0
     */
    private function catalogueCopy(): string
    {
        $directory = sys_get_temp_dir() . '/kumwe-catalogue-quality-' . bin2hex(random_bytes(6));
        mkdir($directory, 0o775, true);
        $sources = glob($this->root . '/resources/localization/messages/*.xlf');
        foreach ($sources === false ? [] : $sources as $source) {
            copy($source, $directory . '/' . basename($source));
        }
        register_shutdown_function(static function () use ($directory): void {
            $files = glob($directory . '/*.xlf');
            foreach ($files === false ? [] : $files as $file) {
                unlink($file);
            }
            rmdir($directory);
        });

        return $directory;
    }

    /**
     * Replace the target of one unit in one copied catalogue.
     *
     * @param   string  $catalogues  Directory of the copied catalogues.
     * @param   string  $locale      Catalogue to edit.
     * @param   string  $identifier  Unit whose target is replaced.
     * @param   string  $target      New target text, unescaped.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function setTarget(string $catalogues, string $locale, string $identifier, string $target): void
    {
        $this->setSide($catalogues, $locale, $identifier, 'target', $target);
    }

    /**
     * Replace the source of one unit in one copied catalogue.
     *
     * @param   string  $catalogues  Directory of the copied catalogues.
     * @param   string  $locale      Catalogue to edit.
     * @param   string  $identifier  Unit whose source is replaced.
     * @param   string  $source      New source text, unescaped.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function setSource(string $catalogues, string $locale, string $identifier, string $source): void
    {
        $this->setSide($catalogues, $locale, $identifier, 'source', $source);
    }

    /**
     * Replace one side of one unit, asserting the unit exists so a renamed identifier fails loudly.
     *
     * @param   string  $catalogues  Directory of the copied catalogues.
     * @param   string  $locale      Catalogue to edit.
     * @param   string  $identifier  Unit to edit.
     * @param   string  $side        Either `source` or `target`.
     * @param   string  $text        New text, unescaped.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function setSide(string $catalogues, string $locale, string $identifier, string $side, string $text): void
    {
        $path = $catalogues . '/' . $locale . '.xlf';
        $document = file_get_contents($path);
        self::assertIsString($document);
        $pattern = sprintf(
            '/(<unit id="%s">(?:(?!<\/unit>)[\s\S])*?<%s>)(?:(?!<\/unit>)[\s\S])*?(<\/%s>)/u',
            preg_quote($identifier, '/'),
            $side,
            $side,
        );
        $escaped = htmlspecialchars($text, ENT_XML1 | ENT_NOQUOTES, 'UTF-8');
        $count = 0;
        $edited = preg_replace_callback(
            $pattern,
            static fn (array $match): string => $match[1] . $escaped . $match[2],
            $document,
            1,
            $count,
        );
        self::assertSame(1, $count, sprintf('%s carries no %s for %s.', $locale, $side, $identifier));
        file_put_contents($path, $edited);
    }

    /**
     * Replace every target whose whole text equals `$from` in one copied catalogue.
     *
     * @param   string  $catalogues  Directory of the copied catalogues.
     * @param   string  $locale      Catalogue to edit.
     * @param   string  $from        Existing target text.
     * @param   string  $to          Replacement target text.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function replaceTargetText(string $catalogues, string $locale, string $from, string $to): void
    {
        $path = $catalogues . '/' . $locale . '.xlf';
        $document = file_get_contents($path);
        self::assertIsString($document);
        self::assertStringContainsString('<target>' . $from . '</target>', $document);
        file_put_contents(
            $path,
            str_replace('<target>' . $from . '</target>', '<target>' . $to . '</target>', $document),
        );
    }
}
