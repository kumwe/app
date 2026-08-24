<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Keeps AP-7 composition owner-bound, POST-provisioned and on the published Studio seams.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class StudioCompositionAuthoringBoundaryTest extends TestCase
{
    /**
     * The read projection remains immutable while provisioning uses its separate write contract.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testBlueprintBindingWriteDoesNotWidenTheAP2ReadPort(): void
    {
        $reader = $this->contents('src/Studio/Application/Projection/ContentProjectionBindingRepository.php');
        $writer = $this->contents('src/Studio/Application/Composition/ContentBlueprintBindingStore.php');
        $service = $this->contents('src/Studio/Application/Composition/StudioContentCompositionService.php');

        self::assertStringNotContainsString('function add(', $reader);
        self::assertStringContainsString('function add(ContentBlueprintBinding $binding)', $writer);
        self::assertStringContainsString('$this->transactions->transactional(', $service);
        self::assertStringContainsString('$this->artifacts->store($artifact, null)', $service);
        self::assertStringContainsString('$this->bindingStore->add($binding)', $service);
        self::assertLessThan(
            strpos($service, '$this->bindingStore->add($binding)'),
            strpos($service, '$this->artifacts->store($artifact, null)'),
        );
    }

    /**
     * GET is read-only; first creation is the one CSRF and dual-capability protected POST route.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testCompositionRoutePreservesReadOnlyGetAndProtectedPost(): void
    {
        $container = $this->contents('src/Kernel/ContainerFactory.php');
        $handler = $this->contents('src/Administrator/Http/Handler/AdministratorStudioCompositionHandler.php');

        self::assertSame(2, substr_count(
            $container,
            "'/administrator/content-models/{id}/versions/{version}/composition'",
        ));
        self::assertStringContainsString(
            '[AdministratorCsrfMiddleware::class, AdministratorStudioCompositionHandler::class]',
            $container,
        );
        self::assertSame(2, substr_count($container, "'content.read', 'studio.mode.blueprint'"));
        self::assertStringContainsString("strtoupper(\$request->getMethod()) === 'POST'", $handler);
        self::assertStringContainsString('$this->compositions->provision(', $handler);
        self::assertStringContainsString('new RedirectResponse($path, 303)', $handler);
        self::assertStringContainsString('$this->compositions->find(', $handler);
        self::assertLessThan(
            strpos($handler, '$this->compositions->find('),
            strpos($handler, "strtoupper(\$request->getMethod()) === 'POST'"),
        );
    }

    /**
     * The browser uses the exact save/preview lifecycle and never advertises an absent resource port.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testBrowserSurfaceUsesPublishedLifecycleSeams(): void
    {
        $main = $this->contents('assets/administrator/main.ts');
        $surface = $this->contents('assets/administrator/components/studio-composition.ts');
        $adapter = $this->contents('assets/administrator/components/studio-host-adapter.ts');
        $contributions = $this->contents('assets/administrator/components/studio-contributions.ts');
        $template = $this->contents('templates/administrator/studio-composition.twig');

        self::assertStringContainsString("querySelector('[data-studio-composition]')", $main);
        self::assertStringContainsString("import('./components/studio-composition')", $main);
        self::assertStringContainsString('shell.markSaved(accepted.revision, acceptedStateVersion)', $surface);
        self::assertStringContainsString('await shell.updateComplete', $surface);
        self::assertStringContainsString('if (handle.session.dirty)', $surface);
        self::assertStringContainsString('location.pathname !== url.pathname', $surface);
        self::assertStringContainsString('shell.refreshPreviewGeometry()', $surface);
        self::assertStringContainsString('element.getClientRects()', $surface);
        self::assertStringContainsString('const sequence = documentSequence;', $surface);
        self::assertStringContainsString('createStagingPreviewFrame(activeFrame)', $surface);
        self::assertStringContainsString('const attempts = new Set<PreviewRenderAttempt>()', $surface);
        self::assertStringContainsString("sessionState: boot.status === 'draft' ? 'editable' : 'read-only'", $surface);
        self::assertStringContainsString('adapter.artifact.publish(reference, context)', $surface);
        self::assertStringContainsString('adapter.artifact.unpublish(reference, context)', $surface);
        self::assertStringContainsString("operation === 'cancel'", $adapter);
        self::assertStringContainsString('runtime.activate(contributionOwner, contributionSet', $contributions);
        self::assertStringNotContainsString("trustedOwner === 'core'", $contributions);
        self::assertStringNotContainsString("type.startsWith('studio.core/')", $contributions);
        self::assertStringContainsString("slot=\"preview\"", $template);
        self::assertStringContainsString('sandbox="allow-same-origin"', $template);
        self::assertStringContainsString('data-studio-publish', $template);
        self::assertStringContainsString('data-studio-unpublish', $template);
        self::assertStringNotContainsString('resource:', $adapter);
        self::assertStringNotContainsString('resource.search', $adapter);
    }

    /**
     * Qualification plumbing names executable evidence without self-attesting human or proof gates.
     *
     * @return  void
     *
     * @since  2.0.0
     */
    public function testAccountableJourneyRemainsHonestAndReleasePinned(): void
    {
        $root = dirname(__DIR__, 2);
        $journey = json_decode(
            $this->contents('tests/Fixtures/Studio/composition-acceptance-journey.json'),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
        $release = json_decode(
            $this->contents('resources/studio-contract/studio-release.json'),
            true,
            32,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($journey);
        self::assertIsArray($release);
        self::assertSame('not_run', $journey['status'] ?? null);
        self::assertSame($release['release'] ?? null, $journey['studioRelease'] ?? null);
        self::assertSame('open', $journey['qualificationDependencies']['p7fSignedContributedBlockAndRenderer'] ?? null);
        foreach ($journey['steps'] ?? [] as $step) {
            self::assertIsArray($step);
            self::assertNull($step['humanEvidence'] ?? null);
            $evidence = $step['automatedEvidence'] ?? null;
            self::assertIsArray($evidence);
            $path = $evidence['path'] ?? null;
            $marker = $evidence['marker'] ?? null;
            self::assertIsString($path);
            self::assertIsString($marker);
            $source = file_get_contents($root . '/' . $path);
            self::assertIsString($source);
            self::assertStringContainsString($marker, $source);
        }
    }

    /**
     * Read one required repository-relative source file.
     *
     * @param   string  $path  Repository-relative source path.
     *
     * @return  string  Required source bytes.
     *
     * @since  2.0.0
     */
    private function contents(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/' . $path);
        self::assertIsString($contents, sprintf('Could not read %s.', $path));

        return $contents;
    }
}
