# Kumwe security policy

## Supported versions

Kumwe is currently preparing the 2.0 release line. Security support begins with
the first published 2.x release; the historical 1.x baseline is unsupported and
must not be deployed.

## Reporting a vulnerability

Do not open a public issue for a suspected vulnerability. Use GitHub's private
security-advisory reporting for this repository and include:

- the affected version or commit;
- the required privileges and configuration;
- exact reproduction steps or a minimal proof of concept;
- the expected and observed behavior;
- the security impact and any known mitigations.

Please avoid accessing data that is not yours, degrading a live service, or
publishing details before a fix is available.

## Response and remediation timelines

These are the commitments a reporter can hold us to. Every clock starts when the
advisory is received and is measured in calendar days, not working days.

| Stage | Commitment |
| --- | --- |
| Acknowledgement | 3 days |
| Triage decision, with a severity and an owner | 10 days |
| Fix released — critical (CVSS 9.0–10.0) | 14 days |
| Fix released — high (7.0–8.9) | 30 days |
| Fix released — medium (4.0–6.9) | 90 days |
| Fix released — low (0.1–3.9) | next scheduled release |

Severity is assigned with CVSS v3.1 and stated in the advisory. Where a fix
cannot land inside its window we say so in the advisory thread before the window
closes, with what is blocking it and a revised date; silence is a defect in this
process, not an outcome of it.

## Disclosure

We coordinate disclosure and we do not sit on reports.

- The advisory is published when the fix is released, or **90 days** after
  acknowledgement, whichever comes first. A reporter who wants to publish sooner
  should say so and we will work to that date.
- Active exploitation shortens everything: we publish the mitigation immediately,
  before the fix if necessary, because an operator who can turn a feature off is
  better served than one waiting for a release.
- Reporters are credited by the name they choose, or anonymously on request.
- There is no bug bounty. There is no scenario in which we pursue a reporter who
  followed this policy.

## Dependency and supply-chain patching

Dependencies are updated on a schedule rather than on a discovery, so a patch is
routine by the time one is urgent.

- `.github/dependabot.yml` opens grouped update pull requests weekly for Composer,
  npm, GitHub Actions and the Docker base images. Security updates are ungrouped so
  they are not held behind an unrelated major.
- `composer audit --locked --abandoned=fail` and `npm audit --audit-level=high` gate
  every pull request and every release; the weekly security workflow re-runs them
  against the committed lockfiles, which is what catches an advisory published after
  a branch was merged.
- A dependency advisory inherits the remediation window of its severity above.

## Engineering requirements

- Production secrets must come from environment variables or mounted secret files.
- The public document root is `public/`.
- Authorization is deny-by-default and capability-based.
- Browser state changes require a non-safe method and CSRF validation.
- Passwords, tokens and recovery codes are stored only as one-way hashes.
- Privileged actions create immutable audit records.
- Dependencies are audited in CI and release artifacts include an SBOM.
- Extension installation validates archives, manifests, digests and signatures
  before activation.

The complete invariants are defined in
[the architecture](docs/product/kumwe-2.0-architecture.md#9-security-invariants).
