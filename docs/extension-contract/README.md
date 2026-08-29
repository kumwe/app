# Extension contract authority

The public extension contract belongs to [`kumwe/extension-sdk`](https://github.com/kumwe/extension-sdk),
not to the App. The installed dependency is the sole authority for manifest generations, author-facing
SPI classifications, signed compatibility fixtures, scaffold templates, package inspection, and build
and signing tools.

An installed App reads those artifacts directly from:

- `vendor/kumwe/extension-sdk/resources/contract/`
- `vendor/kumwe/extension-sdk/resources/fixtures/`
- `vendor/kumwe/extension-sdk/resources/extension-scaffold/`

`composer extension:contract` verifies every installed SDK resource against the package-owned
`resources/PIN.json`. App releases then pin one immutable SDK release through Composer. The App does not
copy class-shape fixtures, translate historical `Kumwe\\App` names, maintain aliases, or accept a legacy
contract ledger.

Host admission policy, authorization, persistence, trust enforcement, lifecycle coordination, and the
interpretation of structurally validated manifest declarations remain App responsibilities. Extensions
implement and call only the canonical `Kumwe\\Extension` contracts.

## Pre-stable reset

The extraction is a deliberate reset before the first supported 2.0 alpha. Earlier development fixtures
and classifications described App-owned types and are not executable compatibility inputs. Development
installations made before this boundary must reinstall and rebaseline as described in
[`docs/operations/upgrade.md`](../operations/upgrade.md).
