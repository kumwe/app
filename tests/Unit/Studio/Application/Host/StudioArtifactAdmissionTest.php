<?php

declare(strict_types=1);

namespace Kumwe\App\Tests\Unit\Studio\Application\Host;

use Kumwe\App\Studio\Application\Host\StudioArtifactAdmission;
use Kumwe\App\Studio\Application\Host\StudioHostOperationRefused;
use Kumwe\App\Studio\Domain\Artifact\StoredStudioArtifact;
use Kumwe\App\Studio\Domain\Artifact\StudioStoredDocumentPolicy;
use Kumwe\App\Studio\Domain\Artifact\StudioStoredDocumentRejection;
use Kumwe\App\Studio\Domain\Artifact\UnsafeStudioStoredDocument;
use Kumwe\App\Studio\Domain\Contract\CanonicalJson;
use Kumwe\App\Studio\Domain\Contract\StudioContractSchemas;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Pins schema-first artifact admission, exact canonical bytes and active-content refusal.
 *
 * @since  2.0.0
 */
#[CoversClass(CanonicalJson::class)]
#[CoversClass(StoredStudioArtifact::class)]
#[CoversClass(StudioArtifactAdmission::class)]
#[CoversClass(StudioHostOperationRefused::class)]
#[CoversClass(StudioStoredDocumentPolicy::class)]
#[CoversClass(StudioStoredDocumentRejection::class)]
#[CoversClass(UnsafeStudioStoredDocument::class)]
final class StudioArtifactAdmissionTest extends TestCase
{
    /**
     * The published Blueprint fixture is stored byte-exactly with every locked dependency.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCanonicalBlueprintAndDependenciesRoundTripWithoutRewriting(): void
    {
        $document = self::fixture('blueprint.product.example.json');
        $admitted = self::admission()->admit('publisher-namibia', $document);

        self::assertSame(CanonicalJson::stringify($document), $admitted->canonicalDocument);
        self::assertEquals($document, $admitted->document());
        self::assertCount(4, $admitted->dependencies());
        self::assertSame([
            'org.example.catalog/price',
            'org.example.models/product',
            'org.example.themes/commerce',
            'studio.core/grid',
        ], array_map(static fn (stdClass $reference): string => $reference->id, $admitted->dependencies()));

        $revised = self::admission()->revise($admitted, 'product-card-r6', 'draft');
        self::assertSame('product-card-r6', $revised->document()->revision);
        self::assertSame('draft', $revised->document()->status);
        self::assertSame($admitted->canonicalDocument, CanonicalJson::stringify($admitted->document()));
    }

    /**
     * Enumerate presentation and executable material that generic Blueprint properties could otherwise hide.
     *
     * @return  iterable<string, array{string, mixed, string}>  Member, value and stable refusal code.
     *
     * @since   2.0.0
     */
    public static function unsafeProperties(): iterable
    {
        yield 'markup' => ['copy', '<script src="https://evil.example/x.js"></script>',
            'studio.artifact/executable-content'];
        yield 'style member' => ['style', 'color: red', 'studio.artifact/unsafe-member'];
        yield 'executable scheme' => ['targetUrl', 'javascript:alert(1)', 'studio.artifact/executable-content'];
        yield 'insecure url' => ['targetUrl', 'http://example.test/path', 'studio.artifact/unsafe-url'];
        yield 'out-of-schema url' => ['copy', 'https://example.test/path', 'studio.artifact/out-of-schema-url'];
        yield 'private literal url' => ['targetUrl', 'https://127.0.0.1/path', 'studio.artifact/unsafe-url'];
    }

    /**
     * Unsafe generic property payloads are rejected before a byte reaches storage and never sanitized.
     *
     * @param   string  $member  Generic Blueprint property member.
     * @param   mixed   $value   Unsafe candidate value.
     * @param   string  $code    Canonical expected diagnostic.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('unsafeProperties')]
    public function testUnsafePresentationAndExecutableValuesFailClosed(
        string $member,
        mixed $value,
        string $code,
    ): void {
        $document = self::fixture('blueprint.product.example.json');
        $roots = $document->roots;
        self::assertIsArray($roots);
        $root = $roots[0] ?? null;
        self::assertInstanceOf(stdClass::class, $root);
        $properties = $root->properties;
        self::assertInstanceOf(stdClass::class, $properties);
        $properties->{$member} = $value;

        try {
            self::admission()->admit('publisher-namibia', $document);
            self::fail('Unsafe artifact material must not be admitted.');
        } catch (StudioHostOperationRefused $refused) {
            self::assertSame('validation-failed', $refused->category);
            self::assertSame($code, $refused->diagnosticCode);
        }
    }

    /**
     * A valid JSON object outside the closed StudioArtifact union is refused with no fallback encoding.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUnsupportedAndSchemaInvalidDocumentsFailClosed(): void
    {
        foreach ([(object) ['kind' => 'theme'], (object) ['kind' => 'blueprint', 'id' => 'partial']] as $document) {
            try {
                self::admission()->admit('publisher-namibia', $document);
                self::fail('An unsupported or partial artifact must fail closed.');
            } catch (StudioHostOperationRefused $refused) {
                self::assertSame('validation-failed', $refused->category);
            }
        }
    }

    /**
     * Build artifact admission over the exact vendored Studio schemas.
     *
     * @return  StudioArtifactAdmission  Admission boundary under test.
     *
     * @since   2.0.0
     */
    private static function admission(): StudioArtifactAdmission
    {
        return new StudioArtifactAdmission(StudioContractSchemas::fromVendoredCorpus());
    }

    /**
     * Load one actual vendored protocol fixture as a decoded JSON object.
     *
     * @param   string  $name  Fixture filename.
     *
     * @return  stdClass  Decoded fixture document.
     *
     * @since   2.0.0
     */
    private static function fixture(string $name): stdClass
    {
        $document = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/Fixtures/Studio/testkit/fixtures/' . $name),
            false,
            64,
            JSON_THROW_ON_ERROR,
        );
        self::assertInstanceOf(stdClass::class, $document);

        return $document;
    }
}
