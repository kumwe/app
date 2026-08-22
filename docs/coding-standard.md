# Kumwe coding standard

This is the single normative source for how Kumwe source code is written. It applies to every
contributor: humans, and any AI agent that opens a pull request. `AGENTS.md` is the operator
checklist for sequencing a change; `CLAUDE.md` exists so Claude Code loads the same two files.
Neither restates these rules, so there is exactly one place to change when the standard changes.

Two checks enforce this document:

```bash
composer cs        # PSR-12 layout and line width, via PHP_CodeSniffer
composer docs:api  # documentation-block completeness, via tools/verify-docblocks.php
```

Both run inside `composer qa`, and both are required to pass before a change is merged.

## 1. Language baseline

- Kumwe targets PHP 8.5. Use language features the target supports; do not add compatibility shims for
  older runtimes.
- Every PHP file starts with `<?php`, a blank line, the file-level documentation block where one
  applies, and `declare(strict_types=1);`.
- One class-like declaration per file, named after the file, autoloaded PSR-4 from `Kumwe\App\`.
- Layout follows [PSR-12](https://www.php-fig.org/psr/psr-12/): four-space indentation, no tabs, LF
  line endings, one trailing newline, no trailing whitespace.
- Lines stay at or below **120 characters**. This is a hard limit for documentation blocks too; wrap
  prose rather than letting a block run wide.

## 2. Type declarations

- Declare native parameter, return, and property types on everything. A missing native type is a defect,
  not a style preference.
- Prefer the narrowest honest type. Use `never` and `void` where they apply.
- Classes are `final` unless a documented extension point requires otherwise, and `readonly` when the
  instance carries no mutable state. `final readonly class` is the default shape in this codebase.
- Constructor property promotion is the default for dependency wiring.
- Arrays are typed precisely in documentation blocks — `list<string>`, `array<string, mixed>`,
  `array{id: string, title: string}` — because static analysis runs at PHPStan level `max`. A bare
  `array` in a documentation block is a defect.

## 3. Documentation blocks

Every class-like declaration, method, function, property, class constant, and enum case carries a
documentation block. The format below is the Joomla-flavoured phpDocumentor style: an aligned,
tag-ordered block that reads the same everywhere in the tree.

### 3.1 Canonical shape

```php
/**
 * Summary sentence that says what the member does, ending with a period.
 *
 * Optional paragraph that says when to reach for it: the problem it solves, the guarantee it
 * makes, and which collaborator owns the parts it does not.
 *
 * @param   string        $reference  What the caller identifies with this value.
 * @param   list<string>  $fields     Which fields the projection restricts itself to.
 *
 * @return  ContentRecord  The stored record with its publication window resolved.
 *
 * @throws  ContentNotFound  When no record matches the reference.
 *
 * @since   2.0.0
 */
```

### 3.2 Tag order

Tags appear in this order, each group separated from the next by a `*`-only line:

1. `@template`, `@extends`, `@implements` (generics, when present)
2. `@param`
3. `@return`
4. `@throws`
5. `@var`, `@deprecated`, `@see`, `@link`, `@internal`, `@since`

`@since` is always last, and always present. The trailing group is not split further, so a property's
`@var` and `@since` sit on consecutive lines.

### 3.3 Alignment

Within one block, every tag value starts in the same column: **two spaces after the longest tag name
in the block**, with shorter tag names padded to match.

```php
/**
 * @param   string  $slug  Route segment identifying the page.
 *
 * @return  bool  Whether the page is publicly reachable.
 *
 * @since   2.0.0
 */
```

```php
/**
 * @var    array<string, true>
 * @since  2.0.0
 */
```

In the first block the longest tag is `@return`/`@throws` (seven characters), so values start at column
nine. In the second the longest is `@since` (six characters), so values start at column eight.

Inside a `@param` group, the type column and the variable column are each padded to the widest entry in
that group, separated by two spaces.

Tags longer than eight characters (`@deprecated`, `@phpstan-*`, `@template-covariant`) take a single
space and do not participate in the alignment calculation.

`tools/format-docblocks.php` applies this mechanically. Write the content; let the formatter align it.

### 3.4 `@since`

Every documentation block ends with `@since`. Members that exist as of the 2.0.0 documentation pass
carry `@since  2.0.0`. Members added later carry the release they first appear in. Never change an
existing `@since` value when editing a member — it records introduction, not last modification.

### 3.5 `@param`

- One entry per parameter, in signature order, including promoted constructor properties.
- The type mirrors the native declaration exactly, or narrows it. Never widen it and never drop an
  existing narrow type: if a block already says `list<string>`, it keeps saying `list<string>`.
- Where the native type is `array`, `iterable`, `callable`, or `object`, the documentation block must
  supply the precise shape. PHPStan level `max` rejects the bare forms.
- The description says what the value *means to this method*, not what its type already says.
  `@param string $id The id.` is filler; `@param string $id UUID of the record to publish.` is not.
- Variadics keep the `...` in the signature but not in the tag: `@param  string  $segments`.

### 3.6 `@return`

- Present on every method except constructors, which must not carry one.
- `void` and `never` are written out: `@return  void`.
- A description follows the type whenever the return value carries meaning the type does not — which
  key is used, what an empty list signifies, whether `null` means "absent" or "not applicable". Omit
  the description when the type says everything, as with `@return  void`.
- `@return  static` and `@return  self` follow the native declaration.

### 3.7 `@throws`

- List the exceptions a caller must reasonably handle: those thrown directly in the body, and those
  raised by a collaborator that this method deliberately lets propagate as part of its contract.
- Do not list exceptions the method catches and converts, and do not list `Throwable` or `Exception` as
  a blanket entry.
- Name the class the same way the code does — the imported short name where there is an import, a
  leading-backslash fully qualified name otherwise — so the reference resolves.
- Each entry states the condition: `@throws  ExtensionLocked  When another process holds the registry lease.`
- Omit the tag entirely when the member throws nothing.

### 3.8 `@var`

- Properties and class constants carry `@var` with the type, plus a description and `@since`.
- The type mirrors the native declaration or narrows it, under the same rule as `@param`.
- Promoted constructor properties are documented by the constructor's `@param` entry, not by a second
  block.
- **Enum cases do not carry `@var`.** PHPStan rejects a `@var` tag that names no variable unless it
  sits on a property or a constant, so `@var string` above a case fails `composer analyse` with
  `varTag.noVariable`. A case block carries its description and `@since`, nothing else — the backing
  type is already declared on the enum.

### 3.9 What good prose looks like

The summary is one sentence. The optional paragraph exists to answer *why this exists and when to use
it* — the thing a reader cannot recover from the signature.

```php
/**
 * Compiles the active extension set into the immutable runtime map the request path reads.
 *
 * Compilation is the only writer of the runtime map. Callers hold an `ExtensionRegistryLease` for
 * the whole operation so that a concurrent install cannot publish a partially written map.
 *
 * @since  2.0.0
 */
final readonly class ExtensionRuntimeMapCompiler
```

Do not write blocks that restate the identifier:

```php
/**
 * Gets the name.          // says nothing the signature does not
 *
 * @return  string  The name.
 *
 * @since   2.0.0
 */
```

Write what the caller needs:

```php
/**
 * Returns the machine name the extension registers its services under.
 *
 * @return  string  Lowercase, dot-separated, stable across upgrades.
 *
 * @since   2.0.0
 */
```

### 3.10 Interfaces and implementations

An interface documents the contract: the guarantee every implementation owes. Each implementation
carries its own full block describing what *that* implementation does — which store it reads, which
driver it wraps, which failure modes it introduces. Do not use `{@inheritDoc}` as the whole block; a
reader working through the concrete class should not have to open the interface to learn what it does.

### 3.11 Enums

The enum block explains what the type models and where its values come from. Each case carries a short
block saying what the case means in domain terms, not what its backing value spells.

```php
/**
 * Lifecycle state of an installed extension as stored in the registry.
 *
 * @since  2.0.0
 */
enum ExtensionStatus: string
{
    /**
     * Installed and present on disk, but excluded from the compiled runtime map.
     *
     * @since  2.0.0
     */
    case Disabled = 'disabled';
}
```

### 3.12 Self-checksumming migrations are exempt

A migration under `src/Infrastructure/Persistence/Migration/` that computes
`hash_file('sha256', __FILE__)` publishes that digest as an immutability contract. An installed site
compares the digest against the migration it already ran, so the file's bytes are part of the released
API. **Adding or realigning a documentation block changes those bytes and breaks every existing
installation's upgrade path**, which `tests/Support/install-parent-schema.php` catches as
`The immutable core migration checksum changed.`

Those files are left exactly as released. `tools/verify-docblocks.php` and
`tools/format-docblocks.php` both detect the self-checksum and skip the file, so neither the gate nor
the formatter will ask you to change one. Document a new migration when you write it, before it ships
and before any site has run it.

### 3.13 File-level blocks

Scripts that are not class files — `bin/`, `tools/`, `config/`, `bootstrap/` — carry a file-level block
describing the file's job, its invocation, and `@since`. Class files do not need one; the class block
carries the description.

## 4. Naming

- Classes, interfaces, traits, and enums: `PascalCase`. Interfaces are named for the role
  (`AuditRecorder`), not suffixed with `Interface`.
- Methods, properties, and variables: `camelCase`. Class constants and enum cases: `SCREAMING_SNAKE_CASE`
  for constants, `PascalCase` for enum cases.
- Infrastructure adapters are prefixed with the technology they bind to: `DoctrineContentRepository`,
  `RedisRateLimiter`.
- Domain names come from the product vocabulary in `docs/`, not from the storage engine.

## 5. Structure and dependency direction

- Preserve the dependency direction in `docs/architecture/principles.md`: domain depends on nothing,
  application depends on domain, infrastructure and delivery depend inward.
- Product policy belongs in domain and application code; driver behaviour belongs in adapters.
- Never fetch services from a static container or service locator. Dependencies arrive through the
  constructor.
- Prefer a maintained Joomla Framework component; reach for Laminas or Mezzio only where Joomla has no
  maintained equivalent. Symfony and Laravel are not direct production dependencies.

## 6. Errors

- Throw domain-named exceptions from domain and application code. Adapters translate driver exceptions
  into those names rather than leaking `PDOException` or `Doctrine\DBAL\Exception` outward.
- Exception messages are complete sentences addressed to an operator, and never contain secrets,
  credentials, tokens, or raw request bodies.
- Catch narrowly. A bare `catch (Throwable $e)` needs a comment explaining the boundary it guards.

## 7. Tests

- Every behaviour-bearing class gets focused unit tests; every infrastructure boundary gets integration
  tests against real services.
- Test classes and test methods carry documentation blocks under the same rules. A test block says what
  behaviour is pinned, which is the part a future reader needs. `composer docs:tests` enforces this
  against a shrinking record of the debt that existed when the gate was armed
  ([`docs/quality/test-docblock-baseline.json`](quality/test-docblock-baseline.json)): anything the
  record does not carry fails, and an entry cannot be deleted until the member it names is documented.
  New tests are held to the rule in full.
- Security-sensitive changes — authentication, authorization, session, archive, extension, token,
  upload, MCP writes — carry adversarial tests alongside the happy path.

## 8. Checking your work

```bash
php tools/verify-docblocks.php src            # completeness, per-violation detail
php tools/verify-docblocks.php --summary src  # coverage counters only
php tools/format-docblocks.php src            # apply the alignment rules
composer qa                                   # the full gate
```

`tools/verify-docblocks.php` exits non-zero when a documentable member is missing a block, a summary, a
`@since`, a `@param` for a declared parameter, or a `@return`; when a block documents a parameter that
does not exist; or when a line exceeds 120 characters. It is dependency free and runs without
`composer install`.
