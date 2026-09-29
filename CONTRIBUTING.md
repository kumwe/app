# Contributing to Kumwe

Read [AGENTS.md](AGENTS.md) for the current workflow and [the coding standard](docs/coding-standard.md)
for types, layout and useful documentation.

## Keep the host small

Use the existing Kumwe packages for reusable behavior. App composes their public APIs and owns host
security, persistence, delivery and operations. Do not copy package code, patch vendor files, add a
parallel registry or put product domains into core. Keep application rules shared across UI, REST,
CLI, MCP and workers, with dependencies pointing inward.

## Make a focused change

Continue the assigned branch, preserve existing work, and solve the concrete problem. Explain why
non-obvious choices matter. Document public contracts and operator steps; avoid comments that repeat
member names and status reports that duplicate the PR.

Package repositories own tests for package functionality. App tests cover host wiring, authorization,
persistence, extension lifecycle and working user flows. Use existing tests and add a focused regression
when needed. Do not add copied corpora, source-string assertions or counter/hash bookkeeping to prove
runtime behavior.

## Validate

```bash
bash tools/agent-setup.sh
. ./.agent-env
composer qa
npm run check
npm run build
```

Run the narrowest relevant tests during implementation. Database changes need real-database verification;
security changes need the relevant refusal cases. Broader recovery, browser and artifact qualification
belongs to the matching changed-path, scheduled and release lanes described in
[development and testing](docs/development.md).

Performance evidence uses bounded concurrent samples with workload, sample size, variation and limitations.
No 24-hour or 72-hour endurance run is required. Coverage is diagnostic; documentation counts and generated
inventories do not determine whether a change is correct.

## Submit

Push coherent changes to the current PR and describe the problem, resulting behavior, validation actually
performed and any limitation. Update the relevant documentation and changelog. Keep secrets, logs and
local database files out of commits. The maintainer's merge accepts App changes; agents do not merge or
publish an unaccepted release.
