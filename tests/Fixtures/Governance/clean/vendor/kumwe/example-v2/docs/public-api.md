# Public API

## `Kumwe\Example\Contract\ExampleServiceInterface`

- `describe(string $subject): string` — returns the description of the subject; never empty.

## `Kumwe\Example\ExampleService`

- `DEFAULT_PREFIX` — the prefix used when none is configured.
- `__construct(string $prefix = self::DEFAULT_PREFIX)`.
- `describe(string $subject): string` — `<prefix>: <subject>`.

## `Kumwe\Example\ConfigProvider`

- `__invoke(): array` — dependencies and the `kumwe.example` defaults.

## `Kumwe\Example\Container\ExampleServiceFactory`

- `__invoke(ContainerInterface $container): ExampleService` — reads `kumwe.example.prefix`.
