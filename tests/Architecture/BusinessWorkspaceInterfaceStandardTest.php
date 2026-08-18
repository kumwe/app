<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Architecture;

use Kumwe\CMS\Tests\Support\ResolvedWording;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class BusinessWorkspaceInterfaceStandardTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    public function testBusinessDefinitionsUseTheKisWorkspaceAndFocusedFieldContract(): void
    {
        $template = $this->contents('templates/administrator/business-definitions.twig');
        $handler = $this->contents(
            'src/BusinessDefinition/Delivery/Administrator/BusinessDefinitionsHandler.php',
        );

        foreach (
            [
                'page-header',
                'tabs',
                'master-detail',
                'resource-toolbar',
                'tab-panel',
                'technical-value',
                'empty-state',
            ] as $component
        ) {
            self::assertStringContainsString("@kis/{$component}.twig", $template);
        }
        foreach (['identity', 'fields', 'relationships', 'delivery', 'workflow', 'publication', 'history'] as $tab) {
            self::assertStringContainsString("'{$tab}' =>", $handler);
        }
        self::assertStringContainsString("{% if loop.first %} open{% endif %}", $template);
        self::assertStringNotContainsString('data-row="field" open>\n                    <input', $template);
        self::assertStringContainsString('name="return_tab"', $template);
        self::assertStringContainsString("capabilities['content.update'] is defined", $template);
        self::assertStringContainsString('Package owned.', $template);
    }

    public function testSchemaPlansUseBoundedTasksAndCapabilityGatedActions(): void
    {
        $template = $this->contents('templates/administrator/business-schema-plans.twig');
        $handler = $this->contents(
            'src/BusinessSchema/Delivery/Administrator/BusinessSchemaPlansHandler.php',
        );

        foreach (
            [
                'page-header',
                'tabs',
                'master-detail',
                'resource-toolbar',
                'tab-panel',
                'technical-value',
                'empty-state',
            ] as $component
        ) {
            self::assertStringContainsString("@kis/{$component}.twig", $template);
        }
        foreach (['summary', 'operations', 'approval', 'execution', 'recovery', 'history'] as $tab) {
            self::assertStringContainsString("'{$tab}' =>", $handler);
        }
        foreach (
            [
                'business.schema.plan',
                'business.schema.approve',
                'business.schema.execute',
                'business.schema.recover',
                'business.schema.destructive',
            ] as $capability
        ) {
            self::assertStringContainsString("capabilities['{$capability}'] is defined", $template);
        }
        self::assertGreaterThanOrEqual(6, substr_count($template, 'name="return_tab"'));
    }

    public function testServerMarkupKeepsEveryTabPanelAvailableWithoutJavaScript(): void
    {
        foreach (
            [
                'templates/administrator/business-definitions.twig',
                'templates/administrator/business-schema-plans.twig',
            ] as $path
        ) {
            $template = $this->contents($path);
            self::assertStringNotContainsString('hidden data-kis-tab-panel', $template);
            self::assertStringNotContainsString('style="display: none', $template);
        }
    }

    private function contents(string $path): string
    {
        $contents = file_get_contents($this->root . '/' . $path);
        self::assertIsString($contents);

        return str_ends_with($path, '.twig') ? ResolvedWording::withResolved($contents) : $contents;
    }
}
