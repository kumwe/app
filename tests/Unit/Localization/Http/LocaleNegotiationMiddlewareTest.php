<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Localization\Http;

use Kumwe\CMS\Application\Authorization\ExecutionContext;
use Kumwe\CMS\Localization\Application\ActiveLocale;
use Kumwe\CMS\Localization\Application\LocaleNegotiator;
use Kumwe\CMS\Localization\Application\SiteDefaultLocale;
use Kumwe\CMS\Localization\Application\SupportedLocales;
use Kumwe\CMS\Localization\Domain\LocaleTag;
use Kumwe\CMS\Localization\Http\Middleware\LocaleNegotiationMiddleware;
use Kumwe\CMS\Site\Application\SiteSettings;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

#[CoversClass(LocaleNegotiationMiddleware::class)]
final class LocaleNegotiationMiddlewareTest extends TestCase
{
    public function testItPublishesTheResolvedLocaleOnTheRequestAndOnTheRequestScopedHolder(): void
    {
        $active = $this->holder();
        $middleware = $this->middleware($active, 'en');
        $seen = null;
        $handler = $this->handler(static function (ServerRequestInterface $request) use ($active, &$seen): void {
            $seen = [
                'attribute' => $request->getAttribute(LocaleNegotiationMiddleware::ATTRIBUTE),
                'holder' => $active->locale()->toString(),
                'scope' => $active->scope()->key(),
            ];
        });

        $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', 'https://kumwe.test/')
                ->withHeader('Accept-Language', 'de'),
            $handler,
        );

        self::assertIsArray($seen);
        self::assertInstanceOf(LocaleTag::class, $seen['attribute']);
        self::assertSame('de', $seen['attribute']->toString());
        self::assertSame('de', $seen['holder']);
        self::assertSame('acme', $seen['scope']);
    }

    public function testAnExplicitQueryChoiceOverridesTheClientPreference(): void
    {
        $active = $this->holder();
        $seen = null;
        $handler = $this->handler(static function (ServerRequestInterface $request) use (&$seen): void {
            $seen = $request->getAttribute(LocaleNegotiationMiddleware::ATTRIBUTE);
        });

        $this->middleware($active, 'en')->process(
            (new ServerRequestFactory())
                ->createServerRequest('GET', 'https://kumwe.test/?locale=he')
                ->withQueryParams(['locale' => 'he'])
                ->withHeader('Accept-Language', 'de'),
            $handler,
        );

        self::assertInstanceOf(LocaleTag::class, $seen);
        self::assertSame('he', $seen->toString());
    }

    public function testTheHolderIsClosedEvenWhenTheRequestEndsByThrowing(): void
    {
        $active = $this->holder();
        $middleware = $this->middleware($active, 'he');
        $handler = $this->handler(static function (): void {
            throw new RuntimeException('The handler failed.');
        });

        try {
            $middleware->process(
                (new ServerRequestFactory())->createServerRequest('GET', 'https://kumwe.test/'),
                $handler,
            );
            self::fail('The handler failure must propagate.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }

        self::assertSame('en-GB', $active->locale()->toString());
    }

    private function holder(): ActiveLocale
    {
        return new ActiveLocale(new SupportedLocales());
    }

    private function middleware(ActiveLocale $active, string $storedLocale): LocaleNegotiationMiddleware
    {
        $supported = new SupportedLocales();
        $settings = new class ($storedLocale) implements SiteSettings {
            public function __construct(private readonly string $storedLocale)
            {
            }

            public function current(): array
            {
                return ['default_locale' => $this->storedLocale];
            }

            public function managed(ExecutionContext $context): array
            {
                return $this->current();
            }

            public function update(ExecutionContext $context, string $siteName, string $homepageSlug): void
            {
            }

            public function updateAll(ExecutionContext $context, array $settings): void
            {
            }
        };

        return new LocaleNegotiationMiddleware(
            new LocaleNegotiator($supported, new SiteDefaultLocale($settings, $supported)),
            $active,
            'acme',
        );
    }

    private function handler(callable $inspect): RequestHandlerInterface
    {
        return new class ($inspect) implements RequestHandlerInterface {
            /** @var callable(ServerRequestInterface): void */
            private $inspect;

            public function __construct(callable $inspect)
            {
                $this->inspect = $inspect;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ($this->inspect)($request);

                return new Response();
            }
        };
    }
}
