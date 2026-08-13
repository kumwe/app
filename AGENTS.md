# Working on Kumwe

This file is the entry point for any automated contributor — Claude Code, Copilot, Cursor, or a bespoke
agent — as well as for humans who want the short version. It does not restate the rules; it points at
the one place they live so the standard stays unified.

## Read first

| Document | What it governs |
| --- | --- |
| [`docs/coding-standard.md`](docs/coding-standard.md) | **Normative.** Documentation blocks, types, naming, structure, errors, tests. |
| [`CONTRIBUTING.md`](CONTRIBUTING.md) | Contribution workflow, commit structure, required checks. |
| [`docs/architecture/principles.md`](docs/architecture/principles.md) | Dependency direction and layer boundaries. |
| [`docs/development.md`](docs/development.md) | Local checks, database matrix, release gates. |

## Non-negotiables

1. **Every documentable member carries a documentation block.** Class-like declarations, methods,
   properties, class constants, and enum cases. Format and tag order are in
   `docs/coding-standard.md` section 3.
2. **Every block ends with `@since`.** Members present in the 2.0.0 documentation pass use
   `@since  2.0.0`. Never rewrite an existing `@since`; it records introduction, not modification.
3. **Never widen or delete an existing PHPDoc type.** Static analysis runs at PHPStan level `max`, and
   a narrow type such as `list<string>` or `array{id: string}` is load-bearing. Add prose around it;
   do not replace it.
4. **Documentation changes touch documentation only.** A documentation pass must not alter a single
   statement, signature, import, or piece of formatting outside comment blocks.
5. **Dependencies arrive through constructors.** No static container, no service locator.
6. **Preserve the dependency direction.** Domain depends on nothing; application depends on domain;
   infrastructure and delivery depend inward.
7. **Do not add Symfony or Laravel** as direct production dependencies, and do not add Kumwe 1.x
   compatibility layers.

## Checks before you hand work back

```bash
composer qa                 # architecture policy, PHP_CodeSniffer, PHPStan, PHPUnit
composer docs:api           # documentation-block completeness
composer docs:format        # apply the house alignment rules
composer security:secrets   # required before pushing new fixtures or configuration
```

`composer security:secrets` runs the pinned gitleaks image the security workflow runs, over the
branch's whole history rather than its working tree, so a literal introduced by an earlier commit is
caught before the push instead of by CI. It needs a Docker daemon and says so loudly when there is
none, which is also why it stays out of `composer qa`: `qa` is documented as running inside the
application container, where no daemon exists, and a gate that cannot run in the normal workflow gets
skipped rather than obeyed.

A test fixture almost never needs a random-looking literal. Derive it from a readable stem or a fixed
label — `str_repeat("\x01", 32)`, a hash of a known string — so it is unmistakably synthetic and
carries no entropy. A genuinely fixed vector that must stay literal, such as a compatibility guarantee
proved against a known input and output, earns a fingerprint-scoped entry in `.gitleaksignore` with
the reason beside it. Never exempt a path or a rule wholesale; that removes the control rather than
the finding.

When `composer install` is unavailable, the documentation tools still run — they are dependency free:

```bash
php tools/verify-docblocks.php src
php tools/format-docblocks.php src
```

## Writing documentation blocks well

Write the block a reader needs, not the block a template demands. The summary says what the member
does; the optional paragraph says *when to reach for it and what it guarantees*. A `@param` description
says what the value means to this method, and a `@throws` entry says under which condition it fires.

Restating the identifier — "Gets the name. `@return string The name.`" — is noise, and reviewers will
ask for it to be removed. Section 3.9 of the coding standard shows the difference.
