# Contributing to Kumwe

Read [`AGENTS.md`](AGENTS.md) first. It is the operator checklist: where code lives, which
gate watches a given change, and the recipes that keep `composer qa` green. Read
[the Kumwe coding standard](docs/coding-standard.md) before your first change. It is the
normative source for documentation blocks, type declarations, naming, structure, and
error handling, and it applies to human and automated contributors alike.

## Development principles

- Preserve the dependency direction defined in the 2.0 architecture.
- Prefer a maintained Laminas or Mezzio component; add another maintained, focused
  package only with explicit justification recorded in the change.
- Do not add Symfony or Laravel as direct production dependencies.
- Do not add Kumwe 1.x migrations or compatibility layers.
- Do not fetch services from a static container or service locator.
- Put product policy in domain/application code and driver behavior in adapters.
- Add unit tests for every behavior-bearing Kumwe class and integration tests for
  infrastructure boundaries.
- Document every class, method, property, class constant, and enum case with a
  documentation block that ends in `@since`, as
  [the coding standard](docs/coding-standard.md) describes.

## Commit structure

Kumwe 2.0 development uses focused commits aligned with the numbered phases in
[`docs/roadmap/`](docs/roadmap/README.md). Start at
[`docs/roadmap/STATUS.md`](docs/roadmap/STATUS.md) for live work. A phase commit
must include its tests and relevant documentation.

## Required local checks

The canonical commands are declared as Composer scripts. Before submitting a
change, run `composer qa`. Its authoritative member set is
[`docs/quality/contract.json`](docs/quality/contract.json), reproduced in
[`AGENTS.md`](AGENTS.md) section 6. The complete list of changes that require
`composer baseline:record` is the watcher table in [`AGENTS.md`](AGENTS.md)
section 4 — this file deliberately does not restate it. [`AGENTS.md`](AGENTS.md)
lists the recipes, and `bash tools/agent-setup.sh` provisions a fresh sandbox.

Database integration tests run against the database service described by the
development Compose file — MariaDB by default, with MySQL and PostgreSQL as the
other supported engines.

The two documentation tools are dependency free and run without `composer install`:

```bash
php tools/verify-docblocks.php src   # report members missing documentation
php tools/format-docblocks.php src   # apply the house alignment rules
```

## Security-sensitive changes

Authentication, authorization, session, archive, extension, token, upload and MCP
write changes require adversarial tests in addition to the ordinary happy path.
