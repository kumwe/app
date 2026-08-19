<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Localization\Presentation;

use Kumwe\App\Localization\Presentation\TranslationTwigExtension;
use Kumwe\App\Tests\Support\InterfaceTranslation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Loader\ArrayLoader;

#[CoversClass(TranslationTwigExtension::class)]
final class TranslationTwigExtensionTest extends TestCase
{
    public function testATemplateResolvesAMessageThroughTheRealCompiledCatalogue(): void
    {
        self::assertSame(
            'Skip to content',
            $this->render("{{ t('core.site.layout.skip_to_content') }}"),
        );
    }

    public function testATemplateSuppliesTheValuesAMessageNames(): void
    {
        self::assertSame(
            'About us · Kumwe demonstration',
            $this->render(
                "{{ t('core.site.layout.document_title', {page: 'About us', site: 'Kumwe demonstration'}) }}",
            ),
        );
    }

    public function testTheLayoutReadsTheLanguageAndDirectionOfTheLocaleInFlight(): void
    {
        self::assertSame('en-GB ltr', $this->render('{{ locale_tag() }} {{ text_direction() }}'));
        self::assertSame('he rtl', $this->render('{{ locale_tag() }} {{ text_direction() }}', 'he'));
        self::assertSame('ar rtl', $this->render('{{ locale_tag() }} {{ text_direction() }}', 'ar'));
    }

    public function testAMessageCarryingInlineMarkupKeepsItsElementAndStillEscapesTheValues(): void
    {
        $rendered = $this->render(
            "{{ t_html('core.administrator.access_denied.explanation', {capability: capability}) }}",
            'en-GB',
            ['capability' => '<script>alert(1)</script>'],
        );

        self::assertStringContainsString('<code>', $rendered);
        self::assertStringContainsString('&lt;script&gt;', $rendered);
        self::assertStringNotContainsString('<script>', $rendered);
    }

    public function testOrdinaryTranslationIsAutoescapedLikeEveryOtherExpression(): void
    {
        $rendered = $this->render(
            "{{ t('core.site.reference.example_label', {term: term}) }}",
            'en-GB',
            ['term' => '<b>bold</b>'],
        );

        self::assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $rendered);
        self::assertStringNotContainsString('<b>', $rendered);
    }

    public function testAnUncataloguedMessageRendersItsIdentifierRatherThanNothing(): void
    {
        self::assertSame(
            'core.not.in.catalogue',
            $this->render("{{ t('core.not.in.catalogue') }}"),
        );
    }

    public function testAnEnvironmentWithoutTheExtensionCannotResolveAMessage(): void
    {
        $twig = new Environment(new ArrayLoader(['page' => "{{ t('core.site.layout.skip_to_content') }}"]));

        $this->expectException(SyntaxError::class);

        $twig->render('page', []);
    }

    /** @param array<string, mixed> $context */
    private function render(string $template, string $locale = 'en-GB', array $context = []): string
    {
        $twig = new Environment(new ArrayLoader(['page' => $template]), ['strict_variables' => true]);
        $twig->addExtension(InterfaceTranslation::twigExtension($locale));

        return $twig->render('page', $context);
    }
}
