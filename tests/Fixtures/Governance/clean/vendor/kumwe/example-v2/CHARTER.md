# The Example charter

**Kumwe Example** describes a subject with a configured prefix through one stable contract. It exists so
the governance tooling has a complete, fictitious Version 2 package to index; nothing in it is a real
release.

## What Example is

1. One contract, `ExampleServiceInterface`, and one default implementation, `ExampleService`.
2. A `ConfigProvider` with one factory and one alias, so a host can register it explicitly.

## What Example must never contain

1. Site settings, actor context or authorization: those are host authority.
2. Persistence of subjects or descriptions.
