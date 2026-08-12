<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Architecture;

use JsonException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Pins Phase 3 security and identity presentation changes to their frozen authority contracts.
 *
 * @since  2.0.0
 */
#[CoversNothing]
final class PhaseThreeSecurityIdentityParityTest extends TestCase
{
    /**
     * Repository root containing the manifest and production delivery sources.
     *
     * @var    string
     * @since  2.0.0
     */
    private string $root;

    /**
     * Resolve the repository root before reading source-bound evidence.
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
     * Every frozen authority action, application call and submitted field remains represented.
     *
     * @return  void
     *
     * @throws  JsonException  When the parity artifact is not valid JSON.
     *
     * @since   2.0.0
     */
    public function testEveryFrozenAuthorityActionRetainsItsDeliveryContract(): void
    {
        foreach ($this->manifest()['surfaces'] as $surface) {
            $handler = $this->contents($surface['handler']);
            $applications = implode('', array_map($this->contents(...), $surface['application_sources']));
            $templates = implode('', array_map($this->contents(...), $surface['templates']));

            foreach ($surface['actions'] as $action) {
                self::assertStringContainsString(
                    $action['action'],
                    $handler,
                    sprintf('%s lost action %s.', $surface['id'], $action['action']),
                );
                self::assertStringContainsString(
                    $action['service'] . '(',
                    $handler . $applications,
                    sprintf('%s lost application call %s.', $action['action'], $action['service']),
                );
                foreach ($action['fields'] as $field) {
                    if (
                        preg_match('/^fields_([a-z_]+)\[\]$/D', $field, $matches) === 1
                        && $field !== 'fields_actions[]'
                    ) {
                        self::assertStringContainsString('name="fields_{{ usage }}[]"', $templates);
                        self::assertStringContainsString("'{$matches[1]}'", $templates);
                        continue;
                    }
                    self::assertStringContainsString(
                        sprintf('name="%s"', $field),
                        $templates,
                        sprintf('%s lost field %s.', $action['action'], $field),
                    );
                }
            }
        }
    }

    /**
     * Step-up remains exact-purpose, session-rotating and outside ordinary browse states.
     *
     * @return  void
     *
     * @throws  JsonException  When the parity artifact is not valid JSON.
     *
     * @since   2.0.0
     */
    public function testStepUpAndNoJavaScriptBoundariesRemainExplicit(): void
    {
        $manifest = $this->manifest();
        foreach ($manifest['surfaces'] as $surface) {
            $handler = $this->contents($surface['handler']);
            $templates = implode('', array_map($this->contents(...), $surface['templates']));
            self::assertStringContainsString('method="post"', $templates);
            self::assertStringContainsString('name="_csrf"', $templates);

            foreach ($surface['actions'] as $action) {
                if ($action['step_up'] === null) {
                    continue;
                }
                self::assertStringContainsString(
                    $action['step_up'],
                    json_encode($manifest, JSON_THROW_ON_ERROR),
                );
            }
            self::assertStringContainsString('StepUpIntent', $handler);
            self::assertStringContainsString('rotatedSession', $handler);
        }

        $business = $this->contents('templates/administrator/business-security.twig');
        self::assertStringNotContainsString('name="policy_json"', $business);
        self::assertStringNotContainsString('name="canonical_ast"', $business);
        self::assertStringNotContainsString('<textarea', $business);
        self::assertStringContainsString('data-kis-policy-step-flow', $business);
        self::assertStringContainsString('data-kis-policy-step-link="{{ step.id }}"', $business);
        self::assertStringContainsString('data-kis-policy-step-panel="scope"', $business);
        self::assertStringContainsString('data-kis-policy-step-panel="review"', $business);
        self::assertStringContainsString('policy_steps[3].url', $business);
        self::assertStringNotContainsString('href="#policy-step-', $business);
        self::assertDoesNotMatchRegularExpression(
            '/<fieldset[^>]+data-kis-policy-step-panel[^>]+hidden/s',
            $business,
            'Server-rendered policy stages must remain expanded without JavaScript.',
        );

        $state = $this->contents('src/Administrator/Presentation/SecurityWorkspaceState.php');
        self::assertStringContainsString('public function policySteps(', $state);
        self::assertStringContainsString("'step' => \$this->step", $state);

        $enhancement = $this->contents('assets/administrator/components/policy-step-flow.ts');
        self::assertStringContainsString('window.history.pushState(', $enhancement);
        self::assertStringContainsString('private revealAllPanels(): void', $enhancement);
        self::assertStringContainsString("this.root.addEventListener('invalid'", $enhancement);
        self::assertStringContainsString('pending.target.focus()', $enhancement);
        self::assertStringContainsString(
            "setupPolicyStepFlows();",
            $this->contents('assets/administrator/main.ts'),
        );
    }

    /**
     * Membership context rotation must return every focused access task to its selected record.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAccessContextSelectionPreservesFocusedRecordState(): void
    {
        $access = $this->contents('templates/administrator/access-control.twig');
        self::assertStringContainsString(
            "{% if selected_id is not null %}&amp;id={{ selected_id|url_encode }}{% endif %}",
            $access,
        );

        $handler = $this->contents(
            'src/Administrator/Http/Handler/AdministratorAccessControlHandler.php',
        );
        self::assertStringContainsString("if ((\$form['action'] ?? null) === 'context.select')", $handler);
        self::assertStringContainsString("return new RedirectResponse(\$workspace->url(", $handler);
    }

    /**
     * Scoped and redacted projections cannot regress into identity, secret or audit-metadata disclosure.
     *
     * @return  void
     *
     * @throws  JsonException  When the parity artifact is not valid JSON.
     *
     * @since   2.0.0
     */
    public function testPermissionReducedAndPortalProjectionsStayRedacted(): void
    {
        $manifest = $this->manifest();
        foreach ($manifest['surfaces'] as $surface) {
            $templates = implode('', array_map($this->contents(...), $surface['templates']));
            foreach ($surface['forbidden_presentation'] as $forbidden) {
                if (str_contains($forbidden, ' ') || str_contains($forbidden, '-')) {
                    continue;
                }
                self::assertStringNotContainsString(
                    $forbidden,
                    $templates,
                    sprintf('%s exposed forbidden presentation field %s.', $surface['id'], $forbidden),
                );
            }
            self::assertNotEmpty($surface['positive_actors']);
            self::assertNotEmpty($surface['negative_actors']);
            self::assertNotEmpty($surface['scope_constraints']);
        }

        $repository = $this->contents(
            'src/Identity/Infrastructure/Administration/DoctrineAccessControlRepository.php',
        );
        self::assertStringContainsString(
            'SELECT id, occurred_at, actor_id, action, subject_type, subject_id, outcome',
            $repository,
        );
        self::assertStringNotContainsString('SELECT * FROM', $repository);
    }

    /**
     * Decode the committed Phase 3 source-parity artifact.
     *
     * @return  array{surfaces: list<array<string, mixed>>}  Frozen security and identity contracts.
     *
     * @throws  JsonException  When the artifact is not valid JSON.
     *
     * @since   2.0.0
     */
    private function manifest(): array
    {
        /** @var array{surfaces: list<array<string, mixed>>} $manifest */
        $manifest = json_decode(
            $this->contents('tests/Fixtures/InterfaceStandard/phase-three-security-identity-parity.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return $manifest;
    }

    /**
     * Read one repository file or fail with its relative path.
     *
     * @param   string  $path  Repository-relative source path.
     *
     * @return  string  Complete source contents.
     *
     * @since   2.0.0
     */
    private function contents(string $path): string
    {
        $contents = file_get_contents($this->root . '/' . $path);
        self::assertIsString($contents, sprintf('Unable to read %s.', $path));

        return $contents;
    }
}
