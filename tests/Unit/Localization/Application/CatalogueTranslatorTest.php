<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Localization\Application;

use Kumwe\CMS\Localization\Application\ActiveLocale;
use Kumwe\CMS\Localization\Application\CatalogueTranslator;
use Kumwe\CMS\Localization\Application\MessageCatalogueRepository;
use Kumwe\CMS\Localization\Application\MessageOverrideRepository;
use Kumwe\CMS\Localization\Application\SupportedLocales;
use Kumwe\CMS\Localization\Application\TranslationScope;
use Kumwe\CMS\Localization\Domain\InvalidMessageIdentifier;
use Kumwe\CMS\Localization\Domain\LocaleTag;
use Kumwe\CMS\Localization\Domain\MessageCatalogue;
use Kumwe\CMS\Localization\Domain\MessageCatalogueChain;
use Kumwe\CMS\Localization\Domain\MessageCatalogueLayer;
use Kumwe\CMS\Localization\Infrastructure\ArrayMessageOverrideRepository;
use Kumwe\CMS\Localization\Infrastructure\IntlMessagePatternFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CatalogueTranslator::class)]
#[CoversClass(MessageCatalogueChain::class)]
#[CoversClass(MessageCatalogue::class)]
#[CoversClass(MessageCatalogueLayer::class)]
#[CoversClass(ArrayMessageOverrideRepository::class)]
#[CoversClass(ActiveLocale::class)]
#[CoversClass(TranslationScope::class)]
final class CatalogueTranslatorTest extends TestCase
{
    private const CLIENT = 'core.business.client.label';
    private const HEADING = 'core.business.client.heading';

    public function testEachLayerOfTheChainOverridesOnlyTheIdentifierItDeclares(): void
    {
        $core = ['en-GB' => [self::CLIENT => 'Client', self::HEADING => 'Client record']];

        $onCore = $this->translator($core);
        self::assertSame('Client', $onCore->translate(self::CLIENT));
        self::assertSame(MessageCatalogueLayer::Core, $onCore->attribution(self::CLIENT)['layer'] ?? null);

        $onExtension = $this->translator($core, ['en-GB' => [self::CLIENT => 'Patient']]);
        self::assertSame('Patient', $onExtension->translate(self::CLIENT));
        self::assertSame('Client record', $onExtension->translate(self::HEADING));
        self::assertSame(MessageCatalogueLayer::Extension, $onExtension->attribution(self::CLIENT)['layer'] ?? null);

        $onSite = $this->translator(
            $core,
            ['en-GB' => [self::CLIENT => 'Patient']],
            ['acme' => ['en-GB' => [self::CLIENT => 'Learner']]],
        );
        self::assertSame('Learner', $onSite->translate(self::CLIENT));
        self::assertSame('Client record', $onSite->translate(self::HEADING));
        self::assertSame(MessageCatalogueLayer::Site, $onSite->attribution(self::CLIENT)['layer'] ?? null);

        $onOrganization = $this->translator(
            $core,
            ['en-GB' => [self::CLIENT => 'Patient']],
            ['acme' => ['en-GB' => [self::CLIENT => 'Learner']]],
            ['acme/north' => ['en-GB' => [self::CLIENT => 'Guest']]],
            new TranslationScope('acme', 'north'),
        );
        self::assertSame('Guest', $onOrganization->translate(self::CLIENT));
        self::assertSame('Client record', $onOrganization->translate(self::HEADING));
        self::assertSame(
            MessageCatalogueLayer::Organization,
            $onOrganization->attribution(self::CLIENT)['layer'] ?? null,
        );
    }

    public function testAnOperatorChangesOneWordWithoutTakingOwnershipOfACatalogue(): void
    {
        $translator = $this->translator(
            ['en-GB' => [self::CLIENT => 'Client', self::HEADING => 'Client record', 'core.a.b.c' => 'Third']],
            [],
            ['acme' => ['en-GB' => [self::CLIENT => 'Patient']]],
        );

        self::assertSame('Patient', $translator->translate(self::CLIENT));
        self::assertSame('Client record', $translator->translate(self::HEADING));
        self::assertSame('Third', $translator->translate('core.a.b.c'));
    }

    public function testTerminologyMayBeAdaptedForOneLanguageWithoutTouchingTheOthers(): void
    {
        $translator = $this->translator(
            ['en-GB' => [self::CLIENT => 'Client'], 'de' => [self::CLIENT => 'Kunde']],
            [],
            ['acme' => ['de' => [self::CLIENT => 'Patient']]],
            [],
            new TranslationScope('acme'),
        );

        self::assertSame('Patient', $translator->translate(self::CLIENT, [], LocaleTag::fromString('de')));
        self::assertSame('Client', $translator->translate(self::CLIENT, [], LocaleTag::fromString('en-GB')));
    }

    public function testAMissingTranslationFallsBackThroughTheLocaleThenToTheSourceLocale(): void
    {
        $translator = $this->translator([
            'en-GB' => [self::CLIENT => 'Client', self::HEADING => 'Client record'],
            'pt' => [self::CLIENT => 'Cliente'],
        ]);

        self::assertSame('Cliente', $translator->translate(self::CLIENT, [], LocaleTag::fromString('pt-BR')));
        self::assertSame('Client record', $translator->translate(self::HEADING, [], LocaleTag::fromString('pt-BR')));
    }

    public function testAMessageNoLayerCarriesComesBackAsItsIdentifierRatherThanAsNothing(): void
    {
        $translator = $this->translator(['en-GB' => []]);

        self::assertSame('core.absent.message.name', $translator->translate('core.absent.message.name'));
        self::assertNotSame('', $translator->translate('core.absent.message.name'));
        self::assertFalse($translator->has('core.absent.message.name'));
        self::assertNull($translator->attribution('core.absent.message.name'));
    }

    public function testTheRenderPathLoadsEachLayerOnceRatherThanOncePerMessage(): void
    {
        $messages = [];
        for ($index = 0; $index < 200; $index++) {
            $messages[sprintf('core.bulk.message.n%d', $index)] = 'Message ' . $index;
        }
        $catalogues = new class (['en-GB' => $messages]) implements MessageCatalogueRepository {
            public int $loads = 0;

            /** @param array<string, array<string, string>> $byLocale */
            public function __construct(private readonly array $byLocale)
            {
            }

            public function catalogue(MessageCatalogueLayer $layer, LocaleTag $locale): MessageCatalogue
            {
                $this->loads++;
                $messages = $layer === MessageCatalogueLayer::Core
                    ? ($this->byLocale[$locale->toString()] ?? [])
                    : [];

                return new MessageCatalogue($locale, $layer, $messages);
            }
        };
        $overrides = new class implements MessageOverrideRepository {
            public int $reads = 0;

            public function siteOverrides(string $site, LocaleTag $locale): array
            {
                $this->reads++;

                return [];
            }

            public function organizationOverrides(string $site, string $organization, LocaleTag $locale): array
            {
                $this->reads++;

                return [];
            }
        };
        $supported = new SupportedLocales();
        $active = new ActiveLocale($supported);
        $active->begin(LocaleTag::fromString('en-GB'), TranslationScope::default());
        $translator = new CatalogueTranslator(
            $catalogues,
            $overrides,
            new IntlMessagePatternFormatter(),
            $active,
            $supported,
        );

        foreach (array_keys($messages) as $identifier) {
            self::assertNotSame('', $translator->translate($identifier));
        }

        self::assertSame(2, $catalogues->loads, 'One core and one extension load for the whole page.');
        self::assertSame(1, $overrides->reads, 'One site override read; no organization is in scope.');
    }

    public function testTwoUnitsOfWorkInOneProcessRenderInTheirOwnLanguage(): void
    {
        $supported = new SupportedLocales();
        $active = new ActiveLocale($supported);
        $translator = new CatalogueTranslator(
            $this->catalogues([
                'en-GB' => [self::CLIENT => 'Client'],
                'ar' => [self::CLIENT => 'عميل'],
                'de' => [self::CLIENT => 'Kunde'],
            ]),
            new ArrayMessageOverrideRepository(),
            new IntlMessagePatternFormatter(),
            $active,
            $supported,
        );

        $rendered = [];
        foreach (['ar', 'de', 'ar'] as $jobLocale) {
            $active->begin(LocaleTag::fromString($jobLocale), TranslationScope::default());
            $rendered[] = $translator->translate(self::CLIENT);
            $active->end();
        }

        self::assertSame(['عميل', 'Kunde', 'عميل'], $rendered);
        self::assertSame('en-GB', $active->locale()->toString(), 'Outside a unit of work the source locale answers.');
    }

    /**
     * A shared translator rereads administered wording when the next request begins.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTwoUnitsOfWorkDoNotShareAnOverrideSnapshot(): void
    {
        $overrides = new class implements MessageOverrideRepository {
            /** @var string @since 2.0.0 */
            public string $value = 'First wording';

            /** @var int @since 2.0.0 */
            public int $reads = 0;

            /** @return array<string, string> @since 2.0.0 */
            public function siteOverrides(string $site, LocaleTag $locale): array
            {
                ++$this->reads;

                return ['core.business.client.label' => $this->value];
            }

            /** @return array<string, string> @since 2.0.0 */
            public function organizationOverrides(string $site, string $organization, LocaleTag $locale): array
            {
                return [];
            }
        };
        $supported = new SupportedLocales();
        $active = new ActiveLocale($supported);
        $translator = new CatalogueTranslator(
            $this->catalogues(['en-GB' => [self::CLIENT => 'Core wording']]),
            $overrides,
            new IntlMessagePatternFormatter(),
            $active,
            $supported,
        );

        $active->begin(LocaleTag::fromString('en-GB'), new TranslationScope('acme'));
        self::assertSame('First wording', $translator->translate(self::CLIENT));
        $active->end();

        $overrides->value = 'Second wording';
        $active->begin(LocaleTag::fromString('en-GB'), new TranslationScope('acme'));
        self::assertSame('Second wording', $translator->translate(self::CLIENT));
        $active->end();

        self::assertSame(2, $overrides->reads);
    }

    public function testItRefusesToLookUpSourceTextAsAnIdentifier(): void
    {
        $translator = $this->translator(['en-GB' => []]);

        $this->expectException(InvalidMessageIdentifier::class);

        $translator->translate('Save settings and design');
    }

    /**
     * @param array<string, array<string, string>>                  $core
     * @param array<string, array<string, string>>                  $extension
     * @param array<string, array<string, array<string, string>>>   $site
     * @param array<string, array<string, array<string, string>>>   $organization
     */
    private function translator(
        array $core,
        array $extension = [],
        array $site = [],
        array $organization = [],
        ?TranslationScope $scope = null,
    ): CatalogueTranslator {
        $supported = new SupportedLocales();
        $active = new ActiveLocale($supported);
        $active->begin(
            LocaleTag::fromString('en-GB'),
            $scope ?? ($site === [] ? TranslationScope::default() : new TranslationScope('acme')),
        );

        return new CatalogueTranslator(
            $this->catalogues($core, $extension),
            new ArrayMessageOverrideRepository($site, $organization),
            new IntlMessagePatternFormatter(),
            $active,
            $supported,
        );
    }

    /**
     * @param array<string, array<string, string>>  $core
     * @param array<string, array<string, string>>  $extension
     */
    private function catalogues(array $core, array $extension = []): MessageCatalogueRepository
    {
        return new class ($core, $extension) implements MessageCatalogueRepository {
            /**
             * @param array<string, array<string, string>>  $core
             * @param array<string, array<string, string>>  $extension
             */
            public function __construct(private readonly array $core, private readonly array $extension)
            {
            }

            public function catalogue(MessageCatalogueLayer $layer, LocaleTag $locale): MessageCatalogue
            {
                $source = $layer === MessageCatalogueLayer::Core ? $this->core : $this->extension;

                return new MessageCatalogue($locale, $layer, $source[$locale->toString()] ?? []);
            }
        };
    }
}
