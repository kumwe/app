<?php

declare(strict_types=1);

namespace Kumwe\App\Infrastructure\Mcp;

use ReflectionMethod;

/**
 * Turns the published MCP surface's own rules into a gate the release cannot boot past.
 *
 * The catalogue used to be a declaration checked by review: a tool could name a handler that did not
 * exist, annotate a removal as an ordinary write, open its schema to arguments nothing validates, or
 * publish a property that carries a secret inbound, and nothing but a reader would notice.
 * `KumweMcpServerFactory` runs this before it registers anything, so each of those is now a boot
 * failure naming the tool and the rule it broke. The checks are deterministic — a catalogue and the exact
 * installed handler source in, a list of violations out — which lets the unit suite drive deliberately
 * broken catalogues through the same code the runtime uses.
 *
 * Three families of rule are enforced. **Identity**: names are unique, well formed, and bound to a
 * public handler method whose complete parameter list matches the schema, and whose executable capability
 * and mutation-guard routes match the typed catalogue binding. **Risk coherence**:
 * every tool declares an `McpRiskClass`, and its read-only, destructive, idempotent, capability and
 * operation-identity declarations must agree with the class it claims. **Non-disclosure**: no declared
 * property anywhere in a published schema may be shaped like a credential or a host path, and no handler
 * parameter may be marked `#[\SensitiveParameter]`, because a value worth marking sensitive is a value
 * that must not cross a tool boundary at all.
 *
 * @since  2.0.0
 */
final readonly class McpCatalogValidator
{
    /**
     * Word segments that make a property or parameter name credential-bearing wherever it appears.
     *
     * Matching is done on segments rather than substrings so that an identifier that merely mentions a
     * secret-adjacent noun — `tokenId`, `keyId`, `publicKeyBase64` — is not confused with the secret
     * itself. Each entry here is a word that has no non-secret reading in an argument name.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array FORBIDDEN_SEGMENTS = [
        'password', 'passwd', 'passphrase', 'secret', 'secrets', 'credential', 'credentials',
        'apikey', 'privatekey', 'signingkey', 'otp', 'mfacode', 'seed',
    ];

    /**
     * Adjacent segment pairs that name credential material only when they appear together.
     *
     * `private` and `key` are both innocuous alone and are a private key together; `recovery` and
     * `code` likewise. Listing the pair rather than either word keeps `keyId` and `failure_code` out of
     * the way while still refusing the shapes that matter.
     *
     * @var    list<array{string, string}>
     * @since  2.0.0
     */
    private const array FORBIDDEN_PAIRS = [
        ['private', 'key'], ['signing', 'key'], ['secret', 'key'], ['recovery', 'code'],
        ['recovery', 'codes'], ['backup', 'code'], ['backup', 'codes'], ['access', 'token'],
        ['bearer', 'token'], ['refresh', 'token'], ['reset', 'token'], ['api', 'key'],
        ['step', 'up'], ['current', 'password'],
    ];

    /**
     * Final segments that make a name a secret value rather than a reference to one.
     *
     * A name ending in `token` or `key` publishes the material; one ending in `id`, `ids` or a
     * qualifier such as `base64` publishes a reference to it, which is what the machine surface is
     * allowed to carry. `path` is refused outright because a host path is the other thing an agent must
     * never be handed the raw form of.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const array FORBIDDEN_FINAL_SEGMENTS = [
        'token', 'tokens', 'key', 'keys', 'path', 'paths', 'filepath', 'realpath', 'directory',
    ];

    /**
     * Assert the whole published surface satisfies every rule, or say exactly which entries do not.
     *
     * Callers treat a normal return as permission to register the surface; there is no result to read.
     * Every violation found is reported in one message, because a change that breaks several tools is
     * one mistake and fixing it one tool at a time wastes the author's time.
     *
     * @param   list<array{
     *              name: string, handler: string, capability: string|null,
     *              capabilityResolver: string|McpDynamicCapabilityResolver,
     *              mutationGuard: McpMutationGuardMode, readOnly: bool, destructive: bool,
     *              idempotent: bool, risk: McpRiskClass, alternative: string,
     *              inputSchema: array<string, mixed>, outputSchema: array<string, mixed>, ...
     *          }>  $tools      Tool declarations to check in full.
     * @param   list<array{uri: string, handler: string, ...}>   $resources  Resource declarations to check.
     * @param   list<array{name: string, handler: string, ...}>  $prompts    Prompt declarations to check.
     * @param   object                                           $handlers   Handler object the entries name.
     *
     * @return  void
     *
     * @throws  McpCatalogInvalid  When any tool, resource or prompt breaks an identity, risk-coherence
     *          or non-disclosure rule.
     *
     * @since   2.0.0
     */
    public function assertValid(array $tools, array $resources, array $prompts, object $handlers): void
    {
        $violations = $this->violations($tools, $resources, $prompts, $handlers);
        if ($violations === []) {
            return;
        }

        throw new McpCatalogInvalid(sprintf(
            "The published MCP surface breaks %d of its own rules:\n- %s",
            count($violations),
            implode("\n- ", $violations),
        ));
    }

    /**
     * List every rule the published surface breaks, in catalogue order.
     *
     * Exposed separately from `assertValid()` so a test can read the exact violations a deliberately
     * broken catalogue produces instead of matching on exception text.
     *
     * The lists are accepted directly rather than through `McpCapabilityCatalog`: the production class
     * remains final and immutable, while each aggregate refusal can be exercised with a deliberately
     * malformed snapshot through the exact same boundary the server factory calls.
     *
     * @param   list<array{
     *              name: string, handler: string, capability: string|null,
     *              capabilityResolver: string|McpDynamicCapabilityResolver,
     *              mutationGuard: McpMutationGuardMode, readOnly: bool, destructive: bool,
     *              idempotent: bool, risk: McpRiskClass, alternative: string,
     *              inputSchema: array<string, mixed>, outputSchema: array<string, mixed>, ...
     *          }>  $tools      Tool declarations to check in full.
     * @param   list<array{uri: string, handler: string, ...}>   $resources  Resource declarations to check.
     * @param   list<array{name: string, handler: string, ...}>  $prompts    Prompt declarations to check.
     * @param   object                                           $handlers   Handler object the entries name.
     *
     * @return  list<string>  One sentence per violation; empty when the surface is coherent.
     *
     * @since   2.0.0
     */
    public function violations(array $tools, array $resources, array $prompts, object $handlers): array
    {
        $violations = [];
        $seen = [];
        if ($tools === []) {
            $violations[] = 'The catalogue publishes no tools at all.';
        }

        foreach ($tools as $tool) {
            $name = $tool['name'];
            if (isset($seen[$name])) {
                $violations[] = sprintf('Tool "%s" is declared more than once.', $name);
            }
            $seen[$name] = true;
            if (preg_match('/^kumwe_[a-z0-9]+(?:_[a-z0-9]+)*$/D', $name) !== 1) {
                $violations[] = sprintf('Tool "%s" is not a lowercase kumwe_-prefixed name.', $name);
            }
            $violations = [...$violations, ...$this->toolViolations($tool, $handlers)];
        }

        foreach ($resources as $resource) {
            $violations = [
                ...$violations,
                ...$this->handlerViolations($resource['uri'], $resource['handler'], null, $handlers),
            ];
        }

        foreach ($prompts as $prompt) {
            $violations = [
                ...$violations,
                ...$this->handlerViolations($prompt['name'], $prompt['handler'], null, $handlers),
            ];
        }

        return array_values(array_unique($violations));
    }

    /**
     * List every rule one published tool breaks, without reference to the rest of the catalogue.
     *
     * Public because it is how the suite proves each rule fails in the right direction: a test takes a
     * real entry, breaks exactly one property of it, and asserts the matching sentence comes back. Only
     * the cross-tool rules — name uniqueness, and the catalogue being non-empty — live in
     * `violations()` instead, because one entry cannot answer them.
     *
     * @param   array{
     *            name: string, handler: string, capability: string|null,
     *            capabilityResolver: string|McpDynamicCapabilityResolver,
     *            mutationGuard: McpMutationGuardMode, readOnly: bool, destructive: bool,
     *            idempotent: bool, risk: McpRiskClass, alternative: string,
     *            inputSchema: array<string, mixed>, outputSchema: array<string, mixed>, ...
     *          }      $tool      One published catalogue entry.
     * @param   object  $handlers  Handler object the entry names a method on.
     *
     * @return  list<string>  One sentence per violation; empty when the entry is sound.
     *
     * @since   2.0.0
     */
    public function toolViolations(array $tool, object $handlers): array
    {
        return [
            ...$this->handlerViolations($tool['name'], $tool['handler'], $tool['inputSchema'], $handlers),
            ...$this->riskViolations($tool),
            ...$this->schemaViolations($tool['name'], 'input', $tool['inputSchema'], true),
            ...$this->schemaViolations($tool['name'], 'output', $tool['outputSchema'], false),
            ...$this->disclosureViolations($tool['name'], $tool),
            ...$this->handlerSignatureViolations($tool['handler'], $handlers),
            ...(new McpToolExecutionEvidence())->violations($tool, $handlers),
        ];
    }

    /**
     * Check that an entry's handler exists, is publicly callable, and exactly matches its input envelope.
     *
     * Every declared property must have a parameter and every parameter must be declared. A parameter
     * without a default must also be required by the schema; otherwise a valid client omission reaches
     * PHP as a missing argument. This is intentionally bidirectional: accepting an undeclared parameter
     * makes a later schema widening silently security-relevant, while declaring an argument no handler
     * accepts makes the published tool unreachable.
     *
     * @param   string                     $entry     Tool name, resource URI or prompt name, for the message.
     * @param   string                     $handler   Method name the entry binds to.
     * @param   array<string, mixed>|null  $input     Input schema whose required properties must map to
     *          parameters, or null for a resource or prompt, which declares no input schema.
     * @param   object                     $handlers  Object the method must exist on.
     *
     * @return  list<string>  One sentence per violation; empty when the binding is sound.
     *
     * @since   2.0.0
     */
    private function handlerViolations(string $entry, string $handler, ?array $input, object $handlers): array
    {
        if (!method_exists($handlers, $handler)) {
            return [sprintf('Entry "%s" names handler %s, which does not exist.', $entry, $handler)];
        }

        $method = new ReflectionMethod($handlers, $handler);
        if (!$method->isPublic() || $method->isStatic()) {
            return [sprintf('Entry "%s" names handler %s, which is not a public instance method.', $entry, $handler)];
        }
        if ($input === null) {
            return [];
        }

        $parameters = [];
        foreach ($method->getParameters() as $parameter) {
            $parameters[$parameter->getName()] = $parameter;
        }
        $violations = [];
        $properties = $input['properties'] ?? [];
        $required = $input['required'] ?? [];
        foreach (is_array($properties) ? array_keys($properties) : [] as $property) {
            if (is_string($property) && !isset($parameters[$property])) {
                $violations[] = sprintf(
                    'Tool "%s" publishes property "%s", which handler %s has no parameter for.',
                    $entry,
                    $property,
                    $handler,
                );
            }
        }
        foreach (is_array($required) ? $required : [] as $property) {
            if (is_string($property) && !isset($parameters[$property])) {
                $violations[] = sprintf(
                    'Tool "%s" requires property "%s", which handler %s has no parameter for.',
                    $entry,
                    $property,
                    $handler,
                );
            }
        }
        foreach ($parameters as $parameterName => $parameter) {
            if (!is_array($properties) || !array_key_exists($parameterName, $properties)) {
                $violations[] = sprintf(
                    'Handler %s accepts parameter $%s, which tool "%s" does not publish.',
                    $handler,
                    $parameterName,
                    $entry,
                );
                continue;
            }
            if (!$parameter->isOptional() && (!is_array($required) || !in_array($parameterName, $required, true))) {
                $violations[] = sprintf(
                    'Handler %s requires $%s, but tool "%s" marks that property optional.',
                    $handler,
                    $parameterName,
                    $entry,
                );
            }
        }

        return $violations;
    }

    /**
     * Check that a tool's annotations, capability and operation identity match the risk class it claims.
     *
     * This is where the taxonomy stops being advisory. A removal annotated as an ordinary write, a
     * credential or trust operation with no named capability, or a mutation a retry could apply twice
     * are each refused here rather than shipped with a comment explaining them.
     *
     * @param   array{
     *            name: string, capability: string|null, readOnly: bool, destructive: bool,
     *            idempotent: bool, risk: McpRiskClass, alternative: string,
     *            inputSchema: array<string, mixed>, ...
     *          }  $tool  One published catalogue entry.
     *
     * @return  list<string>  One sentence per violation; empty when the declaration is coherent.
     *
     * @since   2.0.0
     */
    private function riskViolations(array $tool): array
    {
        $name = $tool['name'];
        $risk = $tool['risk'];
        $violations = [];
        if ($risk->changesState() === $tool['readOnly']) {
            $violations[] = sprintf(
                'Tool "%s" declares risk class %s but reports readOnly as %s.',
                $name,
                $risk->value,
                $tool['readOnly'] ? 'true' : 'false',
            );
        }
        if ($tool['destructive'] && !$risk->permitsDestructiveAnnotation()) {
            $violations[] = sprintf(
                'Tool "%s" is annotated destructive, which risk class %s does not permit.',
                $name,
                $risk->value,
            );
        }
        if (!$tool['destructive'] && $risk->requiresDestructiveAnnotation()) {
            $violations[] = sprintf(
                'Tool "%s" declares risk class %s without the destructive hint.',
                $name,
                $risk->value,
            );
        }
        if ($risk->requiresDeclaredCapability() && $tool['capability'] === null) {
            $violations[] = sprintf('Tool "%s" declares risk class %s but names no capability.', $name, $risk->value);
        }
        if (
            $tool['capability'] !== null
            && preg_match('/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)+$/D', $tool['capability']) !== 1
        ) {
            $violations[] = sprintf('Tool "%s" names an invalid capability.', $name);
        }
        if ($risk->reachesBeyondTheCallersSite() && $tool['readOnly']) {
            $violations[] = sprintf(
                'Tool "%s" claims a reach beyond the site while reporting itself read-only.',
                $name,
            );
        }
        if (trim($tool['alternative']) === '') {
            $violations[] = sprintf('Tool "%s" documents no non-MCP alternative.', $name);
        }
        if (!$risk->changesState()) {
            return $violations;
        }
        if (!$tool['idempotent']) {
            $violations[] = sprintf(
                'Mutating tool "%s" is not annotated idempotent, so a retry may apply twice.',
                $name,
            );
        }
        $required = $tool['inputSchema']['required'] ?? [];
        if (!is_array($required) || !in_array('operationId', $required, true)) {
            $violations[] = sprintf('Mutating tool "%s" does not require an operationId.', $name);
        }

        return $violations;
    }

    /**
     * Check one schema side has a valid object envelope and explicit membership decisions throughout.
     *
     * A closed object refuses an argument nothing validates; an object that is deliberately open — a
     * definition-shaped value map — is allowed, but it has to say so, because the difference between
     * "open on purpose" and "nobody decided" is exactly what a reviewer cannot see.
     *
     * @param   string                $name        Tool name, for the message.
     * @param   string                $side        `input` or `output`, named in each violation.
     * @param   array<string, mixed>  $schema      Schema to walk in full.
     * @param   bool                  $closedRoot  Whether the root must reject additional properties.
     *
     * @return  list<string>  One sentence per violation; empty when every object states its decision.
     *
     * @since   2.0.0
     */
    private function schemaViolations(string $name, string $side, array $schema, bool $closedRoot): array
    {
        $violations = [];
        if (($schema['type'] ?? null) !== 'object') {
            $violations[] = sprintf('Tool "%s" publishes an %s schema whose root is not an object.', $name, $side);
        }
        if ($closedRoot && ($schema['additionalProperties'] ?? null) !== false) {
            $violations[] = sprintf('Tool "%s" publishes an input schema that is not a closed object.', $name);
        }
        if ($side === 'input' && !is_array($schema['properties'] ?? null)) {
            $violations[] = sprintf('Tool "%s" publishes an input schema without a property map.', $name);
        }
        if ($side === 'input' && !is_array($schema['required'] ?? null)) {
            $violations[] = sprintf('Tool "%s" publishes an input schema without a required-property list.', $name);
        }

        foreach ($this->objectSchemas($schema, '') as $path => $node) {
            $location = $path === '' ? '(root)' : $path;
            if (!array_key_exists('additionalProperties', $node)) {
                $violations[] = sprintf(
                    'Tool "%s" publishes %s object schema %s without an additionalProperties decision.',
                    $name,
                    $side,
                    $location,
                );
            }
            if (array_key_exists('properties', $node) && !is_array($node['properties'])) {
                $violations[] = sprintf(
                    'Tool "%s" publishes %s object schema %s with an invalid property map.',
                    $name,
                    $side,
                    $location,
                );
                continue;
            }
            if (!array_key_exists('required', $node)) {
                continue;
            }
            $required = $node['required'];
            if (!is_array($required) || !array_is_list($required)) {
                $violations[] = sprintf(
                    'Tool "%s" publishes %s object schema %s with an invalid required-property list.',
                    $name,
                    $side,
                    $location,
                );
                continue;
            }
            $validRequired = true;
            foreach ($required as $property) {
                if (!is_string($property)) {
                    $validRequired = false;
                }
            }
            if (!$validRequired) {
                $violations[] = sprintf(
                    'Tool "%s" publishes %s object schema %s with an invalid required-property list.',
                    $name,
                    $side,
                    $location,
                );
                continue;
            }
            /** @var list<string> $required */
            if (count($required) !== count(array_unique($required))) {
                $violations[] = sprintf(
                    'Tool "%s" publishes %s object schema %s with duplicate required properties.',
                    $name,
                    $side,
                    $location,
                );
            }
            $properties = $node['properties'] ?? [];
            foreach ($required as $property) {
                if (!is_array($properties) || !array_key_exists($property, $properties)) {
                    $violations[] = sprintf(
                        'Tool "%s" requires undeclared %s property "%s" in object schema %s.',
                        $name,
                        $side,
                        $property,
                        $location,
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * Check that nothing in a serialized tool is shaped like a secret or a host path.
     *
     * The walk covers property names at every depth of both schemas, so a credential smuggled into a
     * nested object or an array item schema is caught in the same pass as a top-level one.
     *
     * @param string $name Tool name, for the message.
     * @param   array{inputSchema: array<string, mixed>, outputSchema: array<string, mixed>, ...}  $tool  One
     *          published catalogue entry.
     *
     * @return  list<string>  One sentence per violation; empty when the tool discloses nothing.
     *
     * @since   2.0.0
     */
    private function disclosureViolations(string $name, array $tool): array
    {
        $violations = [];
        foreach (['inputSchema' => $tool['inputSchema'], 'outputSchema' => $tool['outputSchema']] as $side => $schema) {
            foreach ($this->propertyNames($schema) as $property) {
                if (self::isCredentialShaped($property)) {
                    $violations[] = sprintf(
                        'Tool "%s" publishes credential-shaped property "%s" in its %s.',
                        $name,
                        $property,
                        $side,
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * Check that a handler bound to a published tool accepts no credential material.
     *
     * Two things are refused: a parameter whose name is credential-shaped, and a parameter marked
     * `#[\SensitiveParameter]`. The second is the stronger signal — the attribute exists to keep a
     * value out of stack traces, so its presence is the author saying this argument is a secret, and a
     * secret has no business crossing a machine-surface tool boundary in any form.
     *
     * @param   string  $handler   Method name the tool binds to.
     * @param   object  $handlers  Object the tools bind to.
     *
     * @return  list<string>  One sentence per violation; empty when the handler takes no secret.
     *
     * @since   2.0.0
     */
    private function handlerSignatureViolations(string $handler, object $handlers): array
    {
        if (!method_exists($handlers, $handler)) {
            return [];
        }

        $violations = [];
        foreach ((new ReflectionMethod($handlers, $handler))->getParameters() as $parameter) {
            $parameterName = $parameter->getName();
            if (self::isCredentialShaped($parameterName)) {
                $violations[] = sprintf(
                    'Handler %s accepts credential-shaped parameter $%s.',
                    $handler,
                    $parameterName,
                );
            }
            if ($parameter->getAttributes(\SensitiveParameter::class) !== []) {
                $violations[] = sprintf(
                    'Handler %s marks $%s sensitive, so it must not be reachable from a tool.',
                    $handler,
                    $parameterName,
                );
            }
        }

        return $violations;
    }

    /**
     * Decide whether an argument or property name publishes secret material rather than a reference.
     *
     * Public because the surrounding suite and any future surface that publishes a schema needs the
     * same rule, and a second copy of it would drift from this one.
     *
     * @param   string  $name  Property or parameter name as the schema or signature spells it.
     *
     * @return  bool  True when the name is credential-shaped or names a raw host path.
     *
     * @since   2.0.0
     */
    public static function isCredentialShaped(string $name): bool
    {
        $segments = self::segments($name);
        if ($segments === []) {
            return false;
        }
        foreach ($segments as $segment) {
            if (in_array($segment, self::FORBIDDEN_SEGMENTS, true)) {
                return true;
            }
        }
        $last = $segments[count($segments) - 1];
        if (in_array($last, self::FORBIDDEN_FINAL_SEGMENTS, true)) {
            return true;
        }
        foreach (self::FORBIDDEN_PAIRS as [$first, $second]) {
            for ($index = 0; $index < count($segments) - 1; $index++) {
                if ($segments[$index] === $first && $segments[$index + 1] === $second) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Split a camelCase or snake_case identifier into its lowercase word segments.
     *
     * @param   string  $name  Identifier as written in a schema or a signature.
     *
     * @return  list<string>  Lowercase segments in source order; empty when the name carries no letters.
     *
     * @since   2.0.0
     */
    private static function segments(string $name): array
    {
        $spaced = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $name) ?? $name;
        $parts = preg_split('/[^A-Za-z0-9]+/', $spaced);
        $segments = [];
        foreach ($parts === false ? [] : $parts as $segment) {
            if ($segment !== '') {
                $segments[] = strtolower($segment);
            }
        }

        return $segments;
    }

    /**
     * Collect every declared property name at any depth of a JSON Schema fragment.
     *
     * @param   array<array-key, mixed>  $schema  Schema fragment to walk.
     *
     * @return  list<string>  Property names in traversal order, including repeats.
     *
     * @since   2.0.0
     */
    private function propertyNames(array $schema): array
    {
        $names = [];
        $properties = $schema['properties'] ?? null;
        if (is_array($properties)) {
            foreach (array_keys($properties) as $property) {
                $names[] = (string) $property;
            }
        }
        foreach ($schema as $value) {
            if (is_array($value)) {
                $names = [...$names, ...$this->propertyNames($value)];
            }
        }

        return $names;
    }

    /**
     * Collect every object-typed schema node at any depth, keyed by its path from the fragment root.
     *
     * @param   array<array-key, mixed>  $schema  Schema fragment to walk.
     * @param   string                   $path    Path accumulated so far, empty at the root.
     *
     * @return  array<string, array<array-key, mixed>>  Object schemas keyed by dotted path.
     *
     * @since   2.0.0
     */
    private function objectSchemas(array $schema, string $path): array
    {
        $found = [];
        $type = $schema['type'] ?? null;
        $isObject = $type === 'object' || (is_array($type) && in_array('object', $type, true));
        if ($isObject) {
            $found[$path] = $schema;
        }
        foreach ($schema as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            $child = $path === '' ? (string) $key : $path . '.' . $key;
            $found = [...$found, ...$this->objectSchemas($value, $child)];
        }

        return $found;
    }
}
