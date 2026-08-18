<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Architecture;

use Kumwe\CMS\Tests\Support\ResolvedWording;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Keeps every surface saying the same true thing about what the extension boundary is.
 *
 * The trusted-extension API boundary is not runtime isolation, and the risk in a claim like that is not
 * that someone writes it deliberately — it is that it drifts. A docblock loosens, an operator page
 * paraphrases, an administrator prompt is written by someone who read the paraphrase, and the
 * installation decision gets made against a guarantee nobody ever built.
 *
 * So the wording is the contract here, and this reads it as source text: the phrase every surface uses,
 * the disclaimer that has to sit beside the container, the ambient-authority inventory that has to ship
 * with the deployment controls bounding it, and the absence of any claim that the boundary isolates
 * anything. Nothing here can prove the boundary is safe, because it is not that kind of boundary. What it
 * proves is that the repository says so.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class ExtensionTrustPostureTest extends TestCase
{
    /**
     * The one phrase every surface uses for the supported tier.
     *
     * @var    string
     * @since  2.0.0
     */
    private const string CANONICAL = 'trusted in-process extension code';

    /**
     * Each surface that must carry the posture, with the fragment proving it states it.
     *
     * @return  array<string, array{string, list<string>}>  Path and the fragments it must contain.
     *
     * @since   2.0.0
     */
    public static function surfaces(): array
    {
        return [
            'the container implementation' => [
                'src/Extension/Runtime/RestrictedExtensionContainer.php',
                [self::CANONICAL, 'not a security sandbox'],
            ],
            'the container contract' => [
                'src/Extension/Runtime/ExtensionContainer.php',
                [self::CANONICAL, 'not a security sandbox'],
            ],
            'the architecture record' => [
                'docs/architecture/extensions.md',
                [self::CANONICAL, 'Ambient authority an admitted extension inherits'],
            ],
            'the extension author guide' => [
                'docs/extensions.md',
                [self::CANONICAL, 'API compatibility boundary and not a sandbox'],
            ],
            'the operator deployment guide' => [
                'docs/operations/deploy.md',
                [self::CANONICAL, 'not a runtime boundary'],
            ],
            'the administrator install prompt' => [
                'templates/administrator/extensions.twig',
                [self::CANONICAL, 'no sandbox'],
            ],
        ];
    }

    /**
     * Every surface names the supported tier with the same phrase and refuses the isolation claim.
     *
     * @param   string        $path      Repository-relative file that must carry the posture.
     * @param   list<string>  $required  Fragments proving it states the posture rather than implying it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('surfaces')]
    public function testEverySurfaceStatesTheSamePosture(string $path, array $required): void
    {
        $source = self::prose($path);

        foreach ($required as $fragment) {
            self::assertStringContainsString($fragment, $source, $path);
        }
    }

    /**
     * Read a surface as one run of prose, with every line break and indent flattened to a space.
     *
     * The wording is the contract; where the wrapping falls is not. Normalising here is what lets a
     * sentence be rewrapped by an editor without failing a test that has nothing to say about wrapping.
     *
     * @param   string  $path  Repository-relative file to read.
     *
     * @return  string  File contents with every whitespace run collapsed to one space.
     *
     * @since   2.0.0
     */
    private static function prose(string $path): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($source, $path);
        if (str_ends_with($path, '.twig')) {
            $source = ResolvedWording::withResolved($source);
        }

        return preg_replace('/\s+/', ' ', $source) ?? $source;
    }

    /**
     * The ambient-authority inventory names every dimension, each beside a deployment control.
     *
     * An inventory that lists the authority without the control is a warning; one that lists both is
     * something an operator can act on, which is the difference the finding asks for.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheAmbientAuthorityInventoryShipsWithItsDeploymentControls(): void
    {
        $section = strstr(self::prose('docs/architecture/extensions.md'), 'Ambient authority an admitted');
        self::assertIsString($section, 'The ambient-authority inventory is missing.');
        $section = strstr($section, '## Package boundary', true);
        self::assertIsString($section);

        foreach (['Filesystem', 'Network', 'Environment', 'Database', 'Process'] as $dimension) {
            self::assertStringContainsString('| ' . $dimension . ' |', $section, $dimension);
        }
        foreach (['unprivileged user', 'egress', 'least privilege', 'php.ini'] as $control) {
            self::assertStringContainsString($control, $section, $control);
        }
        self::assertStringContainsString('None of these is enforced by Kumwe', $section);
    }

    /**
     * No surface claims the boundary isolates, sandboxes or contains admitted extension code.
     *
     * The check is deliberately blunt: the words appear in the repository for other, honest reasons —
     * a Twig namespace is isolated, a Content-Security-Policy sandboxes an SVG — so it is scoped to the
     * files that describe the extension boundary and to the sentence shapes that would be a claim.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNoSurfaceClaimsTheBoundaryIsolatesAdmittedCode(): void
    {
        $forbidden = [
            '/\bextension sandbox\b/i',
            '/\bsandboxe?d? extension/i',
            '/\bisolates? (?:the )?extension (?:code|php)\b/i',
            '/\bextension(?:s)? (?:run|execute)s? in (?:a|its own) sandbox\b/i',
        ];
        foreach (array_column(self::surfaces(), 0) as $path) {
            $source = self::prose($path);
            foreach ($forbidden as $pattern) {
                self::assertDoesNotMatchRegularExpression($pattern, $source, $path);
            }
        }
    }

    /**
     * Untrusted and marketplace PHP is stated as unsupported, with the out-of-process route named.
     *
     * Saying what is unsupported without saying what to do instead is how the unsupported thing gets
     * done anyway, so both halves are required.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUntrustedCodeIsUnsupportedAndTheOutOfProcessRouteIsNamed(): void
    {
        foreach (['docs/architecture/extensions.md', 'docs/extensions.md'] as $path) {
            $source = self::prose($path);
            self::assertMatchesRegularExpression(
                '/marketplace PHP is (?:not a supported tier|not supported)/i',
                $source,
                $path,
            );
            self::assertStringContainsString('out of process', $source, $path);
            self::assertStringContainsString('business-integrations.md', $source, $path);
        }
    }

    /**
     * Recovery composition is wired so that no extension provider, template or asset is reachable.
     *
     * `createRecovery()` builds with the runtime unloaded, which is what makes the recovery surfaces
     * usable while an installation's runtime map is missing or untrusted. This pins the three properties
     * that make the claim true — providers unexecuted, no extension template namespace, no extension
     * asset route — at the composition root, where behaviour tests would otherwise need an installed
     * package to demonstrate the absence of one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRecoveryCompositionReachesNoExtensionCodeTemplateOrAsset(): void
    {
        $factory = self::prose('src/Kernel/ContainerFactory.php');

        self::assertStringContainsString(
            'return $this->build($environment, true, false);',
            $factory,
            'Recovery must compose with the extension runtime unloaded.',
        );
        self::assertStringContainsString(
            'Builds recovery surfaces without executing any installed extension code.',
            $factory,
        );
        self::assertStringContainsString(
            'Recovery construction uses the same core contribution path but never evaluates the signed '
            . 'extension publication, instantiates providers, or adds extension template namespaces.',
            self::prose('docs/architecture/extensions.md'),
        );
    }
}
