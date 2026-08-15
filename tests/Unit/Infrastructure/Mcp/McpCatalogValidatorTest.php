<?php

declare(strict_types=1);

namespace Kumwe\CMS\Tests\Unit\Infrastructure\Mcp;

use Kumwe\CMS\Infrastructure\Mcp\KumweMcpHandlers;
use Kumwe\CMS\Infrastructure\Mcp\McpCapabilityCatalog;
use Kumwe\CMS\Infrastructure\Mcp\McpCatalogInvalid;
use Kumwe\CMS\Infrastructure\Mcp\McpCatalogValidator;
use Kumwe\CMS\Infrastructure\Mcp\McpRiskClass;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SensitiveParameter;
use stdClass;

#[CoversClass(McpCatalogValidator::class)]
#[CoversClass(McpRiskClass::class)]
#[CoversClass(McpCatalogInvalid::class)]
final class McpCatalogValidatorTest extends TestCase
{
    /**
     * Proves the surface this release actually publishes satisfies every rule the validator enforces.
     *
     * This is the gate itself rather than a sample of it: identity, handler binding, risk coherence,
     * schema closure and non-disclosure are all checked against the real catalogue and the real handler
     * signatures, so a tool added without a risk class, with a mis-stated annotation, with an object
     * schema nobody decided about, or carrying credential material fails here.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheShippedSurfaceSatisfiesEveryPublishedRule(): void
    {
        $catalog = new McpCapabilityCatalog();

        self::assertSame([], (new McpCatalogValidator())->violations(
            $catalog->tools(),
            $catalog->resources(),
            $catalog->prompts(),
            self::handlers(),
        ));
    }

    /**
     * Proves a catalogue cannot make the machine surface empty.
     *
     * The production catalogue stays final and immutable; the declaration seam lets this test hand the
     * same runtime validator an empty surface so the refusal cannot become uncovered or unreachable again.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnEmptyCatalogueIsRefused(): void
    {
        self::assertSame(
            ['The catalogue publishes no tools at all.'],
            (new McpCatalogValidator())->violations([], [], [], self::handlers()),
        );
    }

    /**
     * Proves two declarations cannot publish the same tool name.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testADuplicateToolNameIsRefused(): void
    {
        $tool = self::tool('kumwe_discover');

        self::assertSame(
            ['Tool "kumwe_discover" is declared more than once.'],
            (new McpCatalogValidator())->violations([$tool, $tool], [], [], self::handlers()),
        );
    }

    /**
     * Proves every tool name uses the lowercase prefixed grammar clients rely on.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMalformedToolNameIsRefused(): void
    {
        $tool = self::tool('kumwe_discover');
        $tool['name'] = 'Kumwe_Discover';

        self::assertSame(
            ['Tool "Kumwe_Discover" is not a lowercase kumwe_-prefixed name.'],
            (new McpCatalogValidator())->violations([$tool], [], [], self::handlers()),
        );
    }

    /**
     * Proves every published tool carries a risk class and a documented non-MCP alternative.
     *
     * The classification is only useful if it is total, so this asserts coverage of the whole list
     * rather than of the entries a reviewer happened to think of.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEveryPublishedToolIsClassifiedAndHasANonMcpAlternative(): void
    {
        $tools = (new McpCapabilityCatalog())->tools();
        self::assertNotEmpty($tools);

        foreach ($tools as $tool) {
            self::assertInstanceOf(McpRiskClass::class, $tool['risk'], $tool['name']);
            self::assertNotSame('', $tool['alternative'], $tool['name']);
            self::assertNotSame('', $tool['risk']->summary());
        }
    }

    /**
     * Proves the four elevated risk classes are actually used, so the taxonomy is not decorative.
     *
     * A taxonomy every tool answers with the same value classifies nothing. The destructive, credential,
     * trust and installation-global classes each have to name at least one real tool for the risk-versus-
     * surface rules to have anything to bite on.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheDestructiveCredentialTrustAndInstallationGlobalClassesAreAllPopulated(): void
    {
        $byClass = [];
        foreach ((new McpCapabilityCatalog())->tools() as $tool) {
            $byClass[$tool['risk']->value][] = $tool['name'];
        }

        foreach (McpRiskClass::cases() as $case) {
            self::assertArrayHasKey($case->value, $byClass, sprintf('No tool is classified %s.', $case->value));
        }
        self::assertContains('kumwe_extension_uninstall', $byClass[McpRiskClass::Trust->value]);
        self::assertContains('kumwe_token_revoke', $byClass[McpRiskClass::Credential->value]);
        self::assertContains('kumwe_menu_item_delete', $byClass[McpRiskClass::Destructive->value]);
        self::assertContains(
            'kumwe_token_emergency_revoke_subject',
            $byClass[McpRiskClass::InstallationGlobal->value],
        );
    }

    /**
     * Proves nothing anywhere in the serialized surface is shaped like a secret or a host path.
     *
     * This is the recursive scan the credential-transport finding asks for, run over the whole encoded
     * catalogue rather than over the three schemas the defect happened to appear in, so a secret
     * reintroduced under any name at any depth of any tool fails here.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNoPublishedPropertyAnywhereIsShapedLikeASecret(): void
    {
        $offenders = [];
        foreach ((new McpCapabilityCatalog())->tools() as $tool) {
            foreach (self::propertyNames([$tool['inputSchema'], $tool['outputSchema']]) as $property) {
                if (McpCatalogValidator::isCredentialShaped($property)) {
                    $offenders[] = $tool['name'] . '.' . $property;
                }
            }
        }

        self::assertSame([], $offenders);
    }

    /**
     * Proves the extension-lifecycle tools carry no step-up property and their handlers no secret.
     *
     * The specific regression: `currentPassword` was published on three input schemas and accepted by
     * three handler signatures. Both halves are asserted, because removing the schema property while
     * leaving the parameter would leave the credential one transport change away from returning.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testExtensionLifecycleToolsNeitherPublishNorAcceptAStepUpCredential(): void
    {
        $lifecycle = ['kumwe_extension_activate', 'kumwe_extension_disable', 'kumwe_extension_uninstall'];
        $encoded = '';
        foreach ((new McpCapabilityCatalog())->tools() as $tool) {
            if (!in_array($tool['name'], $lifecycle, true)) {
                continue;
            }
            $encoded .= json_encode($tool, JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey('currentPassword', $tool['inputSchema']['properties']);
            self::assertSame([], (new McpCatalogValidator())->toolViolations($tool, self::handlers()));
        }

        self::assertNotSame('', $encoded);
        self::assertStringNotContainsString('currentPassword', $encoded);
        self::assertStringNotContainsString('writeOnly', $encoded);
    }

    /**
     * Proves a handler that is named but missing is reported rather than registered.
     *
     * Passing a bare object as the handler collection makes every binding in the catalogue dangle, which
     * is the cheapest way to show the binding rule fires at all instead of silently returning clean.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMissingHandlerIsReportedForEveryEntryThatNamesOne(): void
    {
        $catalog = new McpCapabilityCatalog();
        $violations = (new McpCatalogValidator())->violations(
            $catalog->tools(),
            $catalog->resources(),
            $catalog->prompts(),
            new stdClass(),
        );

        self::assertNotEmpty($violations);
        foreach ($violations as $violation) {
            self::assertStringContainsString('which does not exist', $violation);
        }
    }

    /**
     * Proves `assertValid()` raises a named failure listing every rule the surface broke.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAssertValidRaisesOneFailureNamingEveryViolation(): void
    {
        $this->expectException(McpCatalogInvalid::class);
        $this->expectExceptionMessageMatches('/breaks \d+ of its own rules/');

        $catalog = new McpCapabilityCatalog();
        (new McpCatalogValidator())->assertValid(
            $catalog->tools(),
            $catalog->resources(),
            $catalog->prompts(),
            new stdClass(),
        );
    }

    /**
     * Proves a coherent surface is admitted silently, which is the permission the factory acts on.
     *
     * `KumweMcpServerFactory` reads a normal return as leave to register every entry, so the passing
     * direction has to be proved as deliberately as the failing one: a gate that raised on the shipped
     * catalogue would take the whole machine surface down at boot, and one that could never return
     * cleanly would never be switched on at all.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAssertValidAdmitsTheSurfaceThisReleasePublishesWithoutRaising(): void
    {
        $catalog = new McpCapabilityCatalog();

        (new McpCatalogValidator())->assertValid(
            $catalog->tools(),
            $catalog->resources(),
            $catalog->prompts(),
            self::handlers(),
        );

        self::assertNotSame([], $catalog->tools());
    }

    /**
     * Proves a handler bound as anything but a public instance method is refused, not registered.
     *
     * A static or non-public binding is a tool the server would advertise and then fail to serve, so it
     * is named at boot rather than discovered by the first client that calls it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAHandlerThatIsNotAPublicInstanceMethodIsRefused(): void
    {
        $violations = (new McpCatalogValidator())->toolViolations(
            self::readToolBoundTo('boundStatically'),
            self::misdeclaredHandlers(),
        );

        $expected = 'Entry "kumwe_fixture_read" names handler boundStatically, '
            . 'which is not a public instance method.';
        self::assertContains($expected, $violations);
    }

    /**
     * Proves a handler parameter named after secret material is refused however the schema is spelled.
     *
     * This is the half of the non-disclosure rule the schema scan cannot see. The regression this PR
     * repairs left three lifecycle handlers accepting a step-up credential after the property had gone
     * from the published schema, which is one transport change away from carrying it again — so the
     * signature is judged on its own rather than trusted because the schema looks clean.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAHandlerAcceptingACredentialShapedParameterIsRefused(): void
    {
        $violations = (new McpCatalogValidator())->toolViolations(
            self::readToolBoundTo('acceptsCredentialArgument'),
            self::misdeclaredHandlers(),
        );

        $expected = 'Handler acceptsCredentialArgument accepts credential-shaped parameter $accessToken.';
        self::assertContains($expected, $violations);
    }

    /**
     * Proves a parameter marked `#[\SensitiveParameter]` keeps its handler off the machine surface.
     *
     * The attribute exists to keep a value out of stack traces, so writing it is the author stating that
     * this argument is secret material. The rule reads that statement at face value: a value worth
     * hiding from a trace has no business crossing a tool boundary, whatever the parameter is called,
     * which is what makes this refusal independent of the name-shape list.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAHandlerMarkingAnArgumentSensitiveIsRefusedWhateverItIsNamed(): void
    {
        $violations = (new McpCatalogValidator())->toolViolations(
            self::readToolBoundTo('marksArgumentSensitive'),
            self::misdeclaredHandlers(),
        );

        self::assertFalse(McpCatalogValidator::isCredentialShaped('proofValue'));
        $expected = 'Handler marksArgumentSensitive marks $proofValue sensitive, '
            . 'so it must not be reachable from a tool.';
        self::assertContains($expected, $violations);
    }

    /**
     * Proves a name carrying no word segments at all is judged rather than mistaken for a secret.
     *
     * A schema property or parameter spelled entirely in punctuation splits into nothing, and the
     * matcher has to answer that with a decision instead of reading past the end of an empty list.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testANameWithNoWordSegmentsIsNotTreatedAsCredentialMaterial(): void
    {
        self::assertFalse(McpCatalogValidator::isCredentialShaped(''));
        self::assertFalse(McpCatalogValidator::isCredentialShaped('__'));
        self::assertFalse(McpCatalogValidator::isCredentialShaped('-.-'));
    }

    /**
     * Proves each risk-coherence rule fails in the right direction when one property is broken.
     *
     * Every case takes the real `kumwe_menu_item_delete` entry — a classified destructive tool that passes
     * today — and changes exactly one thing about it, so a rule that stopped firing would show up as a
     * missing violation rather than as a still-green suite.
     *
     * @param   array<string, mixed>  $overrides  Properties to replace on the real entry.
     * @param   string                $expected   Fragment the resulting violation must contain.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('brokenDeclarations')]
    public function testOneBrokenPropertyProducesItsOwnViolation(array $overrides, string $expected): void
    {
        $tool = self::tool('kumwe_menu_item_delete');
        self::assertSame([], (new McpCatalogValidator())->toolViolations($tool, self::handlers()));

        $violations = (new McpCatalogValidator())->toolViolations([...$tool, ...$overrides], self::handlers());

        self::assertNotEmpty($violations);
        $matched = false;
        foreach ($violations as $violation) {
            $matched = $matched || str_contains($violation, $expected);
        }
        self::assertTrue($matched, sprintf('No violation mentioned "%s": %s', $expected, implode('; ', $violations)));
    }

    /**
     * Supply one broken property per case, with the fragment its violation must carry.
     *
     * @return  array<string, array{array<string, mixed>, string}>  Overrides and their expected fragment.
     *
     * @since   2.0.0
     */
    public static function brokenDeclarations(): array
    {
        return [
            'a removal demoted to an ordinary write' => [
                ['risk' => McpRiskClass::ScopedWrite],
                'is annotated destructive, which risk class scoped_write does not permit',
            ],
            'a destructive tool that drops its hint' => [
                ['destructive' => false],
                'without the destructive hint',
            ],
            'a mutation claiming to be read-only' => [
                ['readOnly' => true],
                'reports readOnly as true',
            ],
            'an elevated tool with no capability' => [
                ['capability' => null],
                'names no capability',
            ],
            'a malformed capability identifier' => [
                ['capability' => 'Users Manage'],
                'names an invalid capability',
            ],
            'an installation-wide reach that calls itself read-only' => [
                ['risk' => McpRiskClass::InstallationGlobal, 'readOnly' => true],
                'claims a reach beyond the site while reporting itself read-only',
            ],
            'a trust change that calls itself read-only' => [
                ['risk' => McpRiskClass::Trust, 'readOnly' => true],
                'claims a reach beyond the site while reporting itself read-only',
            ],
            'a mutation that is not idempotent' => [
                ['idempotent' => false],
                'is not annotated idempotent',
            ],
            'a mutation with no operation identity' => [
                ['inputSchema' => [
                    'type' => 'object', 'additionalProperties' => false, 'properties' => [], 'required' => [],
                ]],
                'does not require an operationId',
            ],
            'an input schema whose root is not an object' => [
                ['inputSchema' => [
                    'type' => 'array', 'additionalProperties' => false, 'properties' => [], 'required' => [],
                ]],
                'root is not an object',
            ],
            'an open input schema' => [
                ['inputSchema' => [
                    'type' => 'object', 'additionalProperties' => true, 'properties' => [], 'required' => [],
                ]],
                'is not a closed object',
            ],
            'a nested object nobody decided about' => [
                ['inputSchema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['operationId'],
                    'properties' => ['operationId' => ['type' => 'string'], 'id' => ['type' => 'object']],
                ]],
                'without an additionalProperties decision',
            ],
            'a credential smuggled into a nested schema' => [
                ['inputSchema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['operationId'],
                    'properties' => [
                        'operationId' => ['type' => 'string'],
                        'id' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => ['recoveryCode' => ['type' => 'string']],
                        ],
                    ],
                ]],
                'credential-shaped property "recoveryCode"',
            ],
            'no documented alternative' => [
                ['alternative' => '   '],
                'documents no non-MCP alternative',
            ],
            'a required property the handler cannot receive' => [
                ['inputSchema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['operationId', 'nonexistentArgument'],
                    'properties' => ['operationId' => ['type' => 'string']],
                ]],
                'has no parameter for',
            ],
            'an optional property the handler cannot receive' => [
                ['inputSchema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['operationId', 'id', 'version'],
                    'properties' => [
                        'operationId' => ['type' => 'string'],
                        'id' => ['type' => 'string'],
                        'version' => ['type' => 'integer'],
                        'unused' => ['type' => 'string'],
                    ],
                ]],
                'publishes property "unused", which handler deleteMenuItem has no parameter for',
            ],
            'a handler requirement the schema marks optional' => [
                ['inputSchema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['operationId', 'id'],
                    'properties' => [
                        'operationId' => ['type' => 'string'],
                        'id' => ['type' => 'string'],
                        'version' => ['type' => 'integer'],
                    ],
                ]],
                'requires $version, but tool "kumwe_menu_item_delete" marks that property optional',
            ],
            'a required property absent from its property map' => [
                ['inputSchema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['operationId', 'id', 'version'],
                    'properties' => [
                        'operationId' => ['type' => 'string'],
                        'id' => ['type' => 'string'],
                    ],
                ]],
                'requires undeclared input property "version"',
            ],
            'an output object with no membership decision' => [
                ['outputSchema' => ['type' => 'object', 'properties' => []]],
                'output object schema (root) without an additionalProperties decision',
            ],
        ];
    }

    /**
     * Proves the credential matcher fires on secret material and stays silent on references to it.
     *
     * The distinction is the whole reason the rule reads word segments rather than substrings: a
     * catalogue that could not publish `tokenId` or `publicKeyBase64` would be unusable, and one that
     * happily published `apiKey` would be the defect all over again.
     *
     * @param   string  $name      Property or parameter name to judge.
     * @param   bool    $rejected  Whether the matcher must treat it as credential material.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    #[DataProvider('credentialShapes')]
    public function testTheCredentialMatcherSeparatesSecretsFromReferencesToThem(
        string $name,
        bool $rejected,
    ): void {
        self::assertSame($rejected, McpCatalogValidator::isCredentialShaped($name), $name);
    }

    /**
     * Supply names on both sides of the credential rule.
     *
     * @return  array<string, array{string, bool}>  Name and whether it must be refused.
     *
     * @since   2.0.0
     */
    public static function credentialShapes(): array
    {
        $shapes = [
            'currentPassword' => true,
            'current_password' => true,
            'passphrase' => true,
            'clientSecret' => true,
            'secret_key' => true,
            'privateKey' => true,
            'private_key_pem' => true,
            'recoveryCode' => true,
            'recovery_codes' => true,
            'backupCode' => true,
            'apiKey' => true,
            'accessToken' => true,
            'refresh_token' => true,
            'stepUpProof' => true,
            'credentials' => true,
            'packagePath' => true,
            'archive_path' => true,
            'token' => true,
            'signingKey' => true,
            'tokenId' => false,
            'keyId' => false,
            'oldKeyId' => false,
            'newKeyId' => false,
            'publicKeyBase64' => false,
            'operationId' => false,
            'identifier' => false,
            'filename' => false,
            'failure_code' => false,
            'query_digest' => false,
            'expectedChecksum' => false,
            'vendorNamespace' => false,
        ];
        $cases = [];
        foreach ($shapes as $name => $rejected) {
            $cases[$name] = [(string) $name, $rejected];
        }

        return $cases;
    }

    /**
     * Read one published entry by name.
     *
     * @param   string  $name  Tool name to select.
     *
     * @return  array{
     *              name: string, handler: string, capability: string|null, readOnly: bool,
     *              destructive: bool, idempotent: bool, risk: McpRiskClass, alternative: string,
     *              inputSchema: array<string, mixed>, outputSchema: array<string, mixed>, ...
     *          }  The published entry.
     *
     * @since   2.0.0
     */
    private static function tool(string $name): array
    {
        foreach ((new McpCapabilityCatalog())->tools() as $tool) {
            if ($tool['name'] === $name) {
                return $tool;
            }
        }

        self::fail(sprintf('The catalogue does not publish %s.', $name));
    }

    /**
     * Build the handler object the catalogue binds to, without touching its collaborators.
     *
     * Only the method signatures matter here, so the instance is never constructed and never called.
     *
     * @return  KumweMcpHandlers  Uninitialised handler instance carrying the real signatures.
     *
     * @since   2.0.0
     */
    private static function handlers(): KumweMcpHandlers
    {
        return (new ReflectionClass(KumweMcpHandlers::class))->newInstanceWithoutConstructor();
    }

    /**
     * Build a handler object whose methods each break exactly one handler-side rule.
     *
     * The shipped handlers cannot demonstrate a refusal, because the point of the release is that none
     * of them break these rules. Each method here breaks one and only one, so a rule that stopped firing
     * shows up as a missing violation rather than as a still-green suite. Nothing is ever called: only
     * the signatures are read.
     *
     * @return  object  Handler collection carrying one deliberately misdeclared method per rule.
     *
     * @since   2.0.0
     */
    private static function misdeclaredHandlers(): object
    {
        return new class {
            /**
             * A binding a client could never reach, because the server calls handlers on an instance.
             *
             * @param   string  $operationId  Deduplication identity, present so nothing else is broken.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public static function boundStatically(string $operationId): void
            {
            }

            /**
             * A binding that would carry secret material inbound under a credential-shaped name.
             *
             * @param   string  $operationId  Deduplication identity, present so nothing else is broken.
             * @param   string  $accessToken  Credential-shaped name the rule exists to refuse.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function acceptsCredentialArgument(string $operationId, string $accessToken): void
            {
            }

            /**
             * A binding whose author marked an argument secret, under a name no list would catch.
             *
             * @param   string  $proofValue  Argument the attribute declares to be secret material.
             *
             * @return  void
             *
             * @since   2.0.0
             */
            public function marksArgumentSensitive(#[SensitiveParameter] string $proofValue): void
            {
            }
        };
    }

    /**
     * Compose a published entry that breaks no rule of its own, bound to the named handler method.
     *
     * Read-only, non-destructive, idempotent and closed, so every violation a case reports comes from
     * the handler the case names rather than from the entry that carries it.
     *
     * @param   string  $handler  Handler method the entry binds to.
     *
     * @return  array<string, mixed>  Entry shaped exactly as the catalogue publishes one.
     *
     * @since   2.0.0
     */
    private static function readToolBoundTo(string $handler): array
    {
        return [
            'name' => 'kumwe_fixture_read',
            'title' => 'Fixture read',
            'description' => 'A sound read-only entry that exists only to carry one handler binding.',
            'handler' => $handler,
            'capability' => null,
            'readOnly' => true,
            'destructive' => false,
            'idempotent' => true,
            'risk' => McpRiskClass::Read,
            'alternative' => 'Administrator console: Content, or bin/kumwe content.',
            'inputSchema' => [
                'type' => 'object', 'properties' => [], 'required' => [], 'additionalProperties' => false,
            ],
            'outputSchema' => [
                'type' => 'object', 'properties' => [], 'additionalProperties' => false,
            ],
        ];
    }

    /**
     * Collect every declared property name at any depth of a set of schema fragments.
     *
     * @param   array<int|string, mixed>  $node  Schema fragment or list of fragments to walk.
     *
     * @return  list<string>  Property names in traversal order.
     *
     * @since   2.0.0
     */
    private static function propertyNames(array $node): array
    {
        $names = [];
        if (isset($node['properties']) && is_array($node['properties'])) {
            foreach (array_keys($node['properties']) as $property) {
                $names[] = (string) $property;
            }
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                $names = [...$names, ...self::propertyNames($value)];
            }
        }

        return $names;
    }
}
