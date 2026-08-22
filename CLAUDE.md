# Kumwe App

See [`AGENTS.md`](AGENTS.md) for the operator checklist and
[`docs/coding-standard.md`](docs/coding-standard.md) for the normative coding standard.
Those two files are the single source of truth; this file exists so Claude Code loads
them automatically and does not carry a second, drifting copy of the rules.

## Quick orientation

- PHP 8.5, Mezzio/Laminas HTTP stack, Joomla Framework components, Doctrine DBAL persistence.
- Source is PSR-4 under `Kumwe\App\` in `src/`; tests are `Kumwe\App\Tests\` in `tests/`.
- `final readonly class` with constructor property promotion is the default class shape.
- PHPStan runs at level `max`; PHP_CodeSniffer enforces PSR-12 with a 120-character line limit.
- Gate A is passed. Open work is in `docs/roadmap/STATUS.md`. Do not reopen Gate A.

## Commands

```bash
composer qa                 # the local lane (see AGENTS.md for the full member set)
composer baseline:record    # after tests, routes, commands, migrations, or skips move
composer cs                 # PSR-12 layout
composer analyse            # PHPStan level max
composer test:unit          # unit suite
composer docs:api           # documentation-block completeness (no vendor needed)
composer docs:format        # apply documentation-block alignment (no vendor needed)
composer security:secrets   # history-wide gitleaks; needs Docker; not inside qa
```

## Before editing

Read `AGENTS.md`. Every class, method, property, class constant, and enum case carries a
documentation block ending in `@since`, and existing narrow PHPDoc types are load-bearing
for PHPStan — never widen or delete them. Adding a `public function test…` without
re-recording `docs/quality/baseline.json` fails `composer qa`.
