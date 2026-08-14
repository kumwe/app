<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Functional\Localization;

use Kumwe\CMS\Kernel\ContainerFactory;
use Kumwe\CMS\Localization\Http\Middleware\LocaleNegotiationMiddleware;
use Kumwe\CMS\Shared\Infrastructure\Configuration\Environment;
use Laminas\Diactoros\ServerRequestFactory;
use Mezzio\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Proves the three layouts emit `lang` and `dir` from the locale a request actually resolved.
 *
 * The `default_locale` setting has existed since 2.0.0 and was consumed by nothing, and all three
 * layouts hardcoded `<html lang="en">` with no direction at all. These are the assertions that stop
 * either from being true again: every surface is requested through the real application, once with
 * no stated preference and once in each of a left-to-right and a right-to-left language.
 *
 * @since  2.0.0
 */
#[CoversClass(LocaleNegotiationMiddleware::class)]
final class ResolvedLocaleRenderingTest extends TestCase
{
    private const SURFACES = ['/', '/administrator/login', '/portal/login'];

    public function testEverySurfaceRendersTheSiteDefaultWhenNoPreferenceIsStated(): void
    {
        foreach (self::SURFACES as $path) {
            $body = $this->body($path);

            self::assertStringContainsString('<html lang="en-GB" dir="ltr">', $body, $path);
            self::assertStringNotContainsString('<html lang="en">', $body, $path);
        }
    }

    public function testEverySurfaceRendersRightToLeftForARightToLeftLanguage(): void
    {
        foreach (self::SURFACES as $path) {
            foreach (['he', 'ar'] as $language) {
                $body = $this->body($path, ['Accept-Language' => $language]);

                self::assertStringContainsString(
                    sprintf('<html lang="%s" dir="rtl">', $language),
                    $body,
                    $path . ' in ' . $language,
                );
            }
        }
    }

    public function testAnExplicitChoiceOutranksTheClientPreferenceOnEverySurface(): void
    {
        foreach (self::SURFACES as $path) {
            $body = $this->body($path . '?locale=de', ['Accept-Language' => 'he']);

            self::assertStringContainsString('<html lang="de" dir="ltr">', $body, $path);
        }
    }

    public function testAnUntranslatedLocaleStillRendersTheSourceWordingRatherThanBlanks(): void
    {
        $body = $this->body('/administrator/login', ['Accept-Language' => 'ar']);

        self::assertStringContainsString('dir="rtl"', $body);
        self::assertStringContainsString('Sign in to Kumwe', $body);
    }

    /** @param array<string, string> $headers */
    private function body(string $path, array $headers = []): string
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test' . $path)
            ->withHeader('Host', 'kumwe.test');
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        $query = parse_url($path, PHP_URL_QUERY);
        if (is_string($query)) {
            parse_str($query, $parameters);
            $request = $request->withQueryParams($parameters);
        }

        $application = (new ContainerFactory())->create(Environment::fromGlobals())->get(Application::class);
        $response = $application->handle($request);
        self::assertSame(200, $response->getStatusCode(), $path);

        return (string) $response->getBody();
    }
}
