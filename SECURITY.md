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
