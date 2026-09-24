<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Administrator\Presentation;

use Kumwe\Administrator\Contract\AdministratorNavigationDefinition;
use Kumwe\Administrator\Contract\AdministratorWorkspaceDefinition;
use Kumwe\App\Administrator\Navigation\AdministratorNavigationRegistry;
use Kumwe\App\Administrator\Presentation\AdministratorRenderer;
use Kumwe\App\Administrator\Presentation\RecoveryAdministratorRenderer;
use Kumwe\App\BusinessSurface\Presentation\Field\SdkFieldConfigurationAdmission;
use Kumwe\App\Extension\Contribution\CapabilityDefinition;
use Kumwe\App\Extension\Contribution\ExtensionContributionRegistrySet;
use Kumwe\App\Presentation\Twig\AdministratorTwigEnvironment;
use Kumwe\App\Presentation\Twig\RecoveryAdministratorTwigEnvironment;
use Kumwe\App\Tests\Support\DeterministicCanonicalEncoder;
use Kumwe\App\Tests\Support\InterfaceTranslation;
use Kumwe\Contribution\ContributionOwner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Twig\Loader\ArrayLoader;

/**
 * Proves core's administrator navigation speaks the request's language while contributions keep theirs.
 *
 * A contributed navigation definition carries source-language text, so without this the sidebar, the
 * dashboard quick links and the command palette stayed English in every translated locale even though
 * each catalogue was complete. Core entries resolve through the catalogue; an extension's entries keep
 * exactly what their contributor declared, and a renderer without a translation extension keeps the
 * declared text rather than printing identifiers.
 *
 * @since  2.0.0
 */
#[CoversClass(AdministratorRenderer::class)]
final class AdministratorRendererNavigationWordingTest extends TestCase
{
    /**
     * Capabilities that reveal the core entries asserted here and the extension entry.
     *
     * @var    array<string, true>
     * @since  2.0.0
     */
    private const array CAPABILITIES = [
        'administrator.access' => true,
        'content.read' => true,
        'content.create' => true,
        'settings.manage' => true,
        'acme.tools.manage' => true,
    ];

    /**
     * Core entries, their group and the sidebar headings resolve in German; the extension's do not change.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCoreNavigationResolvesInTheRequestLocaleAndContributionsKeepTheirText(): void
    {
        $renderer = $this->renderer($this->registryWithExtension(), 'de');

        $rows = $this->rowsById($renderer->visibleNavigation(self::CAPABILITIES));

        self::assertSame('Inhalt', $rows['core.content']['label']);
        self::assertSame('Inhalte finden, bearbeiten und veröffentlichen', $rows['core.content']['description']);
        self::assertSame('Arbeitsbereich', $rows['core.content']['group']);
        self::assertSame('Einstellungen', $rows['core.settings']['label']);
        self::assertSame('System', $rows['core.settings']['group']);
        self::assertSame('Acme tools', $rows['acme.tools.home']['label']);
        self::assertSame('Open the acme tools.', $rows['acme.tools.home']['description']);
        self::assertSame('Acme', $rows['acme.tools.home']['group']);

        $html = $renderer->render('proof', ['capabilities' => self::CAPABILITIES]);

        self::assertStringContainsString(
            'core.workspace=Arbeitsbereich:Tägliche Inhalts- und Veröffentlichungsarbeit.',
            $html,
        );
        self::assertStringContainsString('core.system=System:', $html);
        self::assertStringContainsString('acme.tools.workspace=Acme:Acme workspace.', $html);
        self::assertStringContainsString('"label":"Inhalt erstellen"', $html);
    }

    /**
     * A right-to-left locale receives its own wording through the same path.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testRightToLeftLocaleReceivesItsOwnNavigationWording(): void
    {
        $renderer = $this->renderer(AdministratorNavigationRegistry::core(new DeterministicCanonicalEncoder()), 'he');

        $rows = $this->rowsById($renderer->visibleNavigation(self::CAPABILITIES));

        self::assertSame('יצירת תוכן', $rows['core.create-content']['label']);
        self::assertSame('סביבת עבודה', $rows['core.create-content']['group']);
    }

    /**
     * Without a translation extension the declared source text renders, never an identifier.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEnvironmentWithoutTranslationKeepsTheDeclaredText(): void
    {
        $renderer = $this->renderer(AdministratorNavigationRegistry::core(new DeterministicCanonicalEncoder()), null);

        $rows = $this->rowsById($renderer->visibleNavigation(self::CAPABILITIES));
        $html = $renderer->render('proof', ['capabilities' => self::CAPABILITIES]);

        self::assertSame('Content', $rows['core.content']['label']);
        self::assertSame('Workspace', $rows['core.content']['group']);
        self::assertStringContainsString('core.workspace=Workspace:Daily content and publishing work.', $html);
    }

    /**
     * Build a renderer whose proof template prints the workspace headings and the command-palette JSON.
     *
     * @param   AdministratorNavigationRegistry  $registry  Registry the menu is built from.
     * @param   ?string                          $locale    Request locale, or null for no translation extension.
     *
     * @return  AdministratorRenderer  Renderer over an isolated administrator environment.
     *
     * @since   2.0.0
     */
    private function renderer(AdministratorNavigationRegistry $registry, ?string $locale): AdministratorRenderer
    {
        $twig = new AdministratorTwigEnvironment(new ArrayLoader([
            'proof.twig' => '{% for workspace in administrator_workspaces %}'
                . '{{ workspace.id }}={{ workspace.label }}:{{ workspace.description }}|{% endfor %}'
                . '{{ administrator_commands_json|raw }}',
        ]));
        if ($locale !== null) {
            $twig->addExtension(InterfaceTranslation::twigExtension($locale));
        }

        return new AdministratorRenderer(
            $twig,
            new RecoveryAdministratorRenderer(new RecoveryAdministratorTwigEnvironment(new ArrayLoader())),
            new DeterministicCanonicalEncoder(),
            $registry,
        );
    }

    /**
     * Build the core registry plus one extension workspace and entry.
     *
     * @return  AdministratorNavigationRegistry  Registry carrying core and `acme/tools` entries.
     *
     * @since   2.0.0
     */
    private function registryWithExtension(): AdministratorNavigationRegistry
    {
        $registries = new ExtensionContributionRegistrySet(
            new DeterministicCanonicalEncoder(),
            new SdkFieldConfigurationAdmission(),
        );
        $owner = ContributionOwner::extension('acme/tools');
        $registries->capabilities()->register(
            $owner,
            new CapabilityDefinition('acme.tools.manage', 'Manage acme tools', 'Manage the acme tools.'),
        );
        $registries->workspaces()->register(
            $owner,
            new AdministratorWorkspaceDefinition('acme.tools.workspace', 'Acme', 'Acme workspace.', 90),
        );
        $registries->navigation()->registerOwned($owner, new AdministratorNavigationDefinition(
            'acme.tools.home',
            'acme.tools.workspace',
            'Acme tools',
            'Open the acme tools.',
            '/',
            'dashboard',
            'acme.tools.manage',
            10,
        ));

        return $registries->navigation();
    }

    /**
     * Index presented navigation rows by their identifier.
     *
     * @param   list<array<string, int|string>>  $rows  Rows the renderer presented.
     *
     * @return  array<string, array<string, int|string>>  The same rows keyed by `id`.
     *
     * @since   2.0.0
     */
    private function rowsById(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string) $row['id']] = $row;
        }

        return $indexed;
    }
}
