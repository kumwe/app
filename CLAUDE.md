# Kumwe CMS

See [`AGENTS.md`](AGENTS.md) for the contributor entry point and
[`docs/coding-standard.md`](docs/coding-standard.md) for the normative coding standard. Those two files
are the single source of truth; this file exists so Claude Code loads them automatically and does not
carry a second, drifting copy of the rules.

## Quick orientation

- PHP 8.5, Mezzio/Laminas HTTP stack, Joomla Framework components, Doctrine DBAL persistence.
- Source is PSR-4 under `Kumwe\CMS\` in `src/`; tests are `Kumwe\CMS\Tests\` in `tests/`.
- `final readonly class` with constructor property promotion is the default class shape.
- PHPStan runs at level `max`; PHP_CodeSniffer enforces PSR-12 with a 120-character line limit.

## Commands

```bash
composer qa            # the full gate: architecture policy, cs, analyse, test
composer cs            # PSR-12 layout
composer analyse       # PHPStan level max
composer test:unit     # unit suite
composer docs:api      # documentation-block completeness (no vendor needed)
composer docs:format   # apply documentation-block alignment (no vendor needed)
```

## Before editing

Read `docs/coding-standard.md`. Every class, method, property, class constant, and enum case carries a
documentation block ending in `@since`, and existing narrow PHPDoc types are load-bearing for PHPStan —
never widen or delete them.
