# Contributing to Kumwe

Read [the Kumwe coding standard](docs/coding-standard.md) before your first change. It is the normative
source for documentation blocks, type declarations, naming, structure, and error handling, and it
applies to human and automated contributors alike. [`AGENTS.md`](AGENTS.md) is the short entry point for
agent contributors.

## Development principles

- Preserve the dependency direction defined in the 2.0 architecture.
- Prefer a maintained Joomla Framework component; use Laminas or Mezzio only when
  Joomla has no maintained equivalent.
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
`docs/product/phase-roadmap.md`. A phase commit must include its tests and relevant
documentation.

## Required local checks

The canonical commands are declared as Composer scripts. Before submitting a
change, run `composer qa`. It runs the architecture policy, the documentation-block
check, PHP_CodeSniffer, PHPStan, and PHPUnit. Database integration tests require the
PostgreSQL service described by the development Compose file.

The two documentation tools are dependency free and run without `composer install`:

```bash
php tools/verify-docblocks.php src   # report members missing documentation
php tools/format-docblocks.php src   # apply the house alignment rules
```

## Security-sensitive changes

Authentication, authorization, session, archive, extension, token, upload and MCP
write changes require adversarial tests in addition to the ordinary happy path.
