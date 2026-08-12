<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Administrator\Http\Handler;

use DateTimeImmutable;
use Kumwe\CMS\Administrator\Http\Handler\AdministratorContentEditorHandler;
use Kumwe\CMS\Application\Authorization\SiteContext;
use Kumwe\CMS\Content\Application\ContentService;
use Kumwe\CMS\Content\Domain\ContentTypeDefinition;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

#[CoversClass(AdministratorContentEditorHandler::class)]
final class AdministratorContentEditorHandlerTest extends TestCase
{
    public function testNewEntriesDefaultToTheCorePageTypeAmongManyTypes(): void
    {
        $selected = $this->select([
            $this->definition('018f22e2-7c8b-7ab0-8f3a-88e8026bb415', 'article'),
            $this->definition('018f22e2-7c8b-7ab0-8f3a-88e8026bb410', 'document'),
            $this->definition(ContentService::CORE_PAGE_TYPE_ID, 'page'),
        ]);

        self::assertSame('page', $selected->handle);
    }

    public function testNewEntriesHonourAnExplicitlyRequestedTypeHandle(): void
    {
        $selected = $this->select([
            $this->definition('018f22e2-7c8b-7ab0-8f3a-88e8026bb415', 'article'),
            $this->definition(ContentService::CORE_PAGE_TYPE_ID, 'page'),
        ], ['content_type' => 'article']);

        self::assertSame('article', $selected->handle);
    }

    public function testNewEntriesFallBackToTheFirstTypeWithoutTheCorePage(): void
    {
        $selected = $this->select([
            $this->definition('018f22e2-7c8b-7ab0-8f3a-88e8026bb415', 'article'),
            $this->definition('018f22e2-7c8b-7ab0-8f3a-88e8026bb410', 'document'),
        ]);

        self::assertSame('article', $selected->handle);
    }

    /**
     * Run the private type-selection decision without composing the full editor.
     *
     * @param   list<ContentTypeDefinition>  $definitions  Head versions offered to the editor.
     * @param   array<string, string>        $query        Query parameters carried by the request.
     *
     * @return  ContentTypeDefinition  The definition the editor would build its form from.
     *
     * @since   2.0.0
     */
    private function select(array $definitions, array $query = []): ContentTypeDefinition
    {
        $handler = (new ReflectionClass(AdministratorContentEditorHandler::class))
            ->newInstanceWithoutConstructor();
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://kumwe.test/administrator/content/new')
            ->withQueryParams($query);
        $selected = (new ReflectionMethod($handler, 'selectedType'))
            ->invoke($handler, $request, $definitions, null);
        self::assertInstanceOf(ContentTypeDefinition::class, $selected);

        return $selected;
    }

    /**
     * Build one published definition carrying the identity and handle under test.
     *
     * @param   string  $id      Content-type UUID.
     * @param   string  $handle  Operator-facing handle.
     *
     * @return  ContentTypeDefinition  Minimal valid definition.
     *
     * @since   2.0.0
     */
    private function definition(string $id, string $handle): ContentTypeDefinition
    {
        $now = new DateTimeImmutable('2026-08-12T00:00:00+00:00');

        return new ContentTypeDefinition(
            $id,
            SiteContext::default(),
            $handle,
            ucfirst($handle),
            '018f22e2-7c8b-7ab0-8f3a-88e8026bb401',
            1,
            ['type' => 'object', 'properties' => ['body' => ['type' => 'string']]],
            1,
            $now,
            $now,
        );
    }
}
