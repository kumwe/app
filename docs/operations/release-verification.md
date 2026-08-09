# Verify a release

The release workflow accepts protected `v2.x.y` tags only after the complete MariaDB, MySQL, and PostgreSQL deployment gate succeeds. It builds the PHP 8.5 application and web images, creates the dependency-complete ZIP, scans artifacts, generates CycloneDX SBOMs, signs image digests and checksums with keyless Cosign, and publishes provenance attestations.

Stable releases publish these image aliases:

- exact version, such as `2.3.1`;
- source commit SHA;
- minor line, such as `2.3`;
- major line `2`;
- `latest`.

Use aliases to discover a release. Resolve and deploy the signed digest for production.

## Verify downloaded artifacts

```bash
sha256sum --check SHA256SUMS
cosign verify-blob \
  --bundle SHA256SUMS.cosign.bundle \
  --certificate-identity-regexp='^https://github.com/Kumwe/cms/.github/workflows/release.yml@refs/tags/v2\.' \
  --certificate-oidc-issuer=https://token.actions.githubusercontent.com \
  SHA256SUMS
```

The checksum list covers the release ZIP and supplied SBOMs. Confirm the certificate identity matches this repository and workflow, the tag points at the expected source commit, and the GitHub release is not a draft or prerelease unless that is the intended rollout.

## Verify images and attestations

```bash
cosign verify \
  --certificate-identity-regexp='^https://github.com/Kumwe/cms/.github/workflows/release.yml@refs/tags/v2\.' \
  --certificate-oidc-issuer=https://token.actions.githubusercontent.com \
  ghcr.io/kumwe/cms/app@sha256:APP_DIGEST
cosign verify-attestation \
  --type cyclonedx \
  --certificate-identity-regexp='^https://github.com/Kumwe/cms/.github/workflows/release.yml@refs/tags/v2\.' \
  --certificate-oidc-issuer=https://token.actions.githubusercontent.com \
  ghcr.io/kumwe/cms/app@sha256:APP_DIGEST
```

Repeat for the web image. Compare both digests with release provenance subjects and the deployment record. Review the SBOM and scanner evidence under the site's risk policy. A passing scan is evidence for the artifact and vulnerability database at scan time, not proof that software has no vulnerabilities.

## Composer releases

Before `composer create-project`, verify that the selected `kumwe/cms` version resolves to the same protected Git tag and locked dependency set as the GitHub release. Use `composer audit --locked --abandoned=fail` after installation, preserve `composer.lock`, and do not accept an unexpected source branch or development constraint in production.

## Business-security acceptance

Before promotion, retain evidence from the supported-database matrix for owner-aware catalog synchronization,
deny-overrides policy evaluation, field-usage isolation, policy-before-pagination SQL, non-enumerating direct reads,
organization/workspace freshness, scoped token non-escalation, maker-checker quorum and replay, TOTP/recovery replay,
portal cookie and CSRF isolation, extension trust lifecycle, recovery-mode isolation, and accessible browser flows.
Run `composer qa`, `npm run check`, `npm run build`, and `npm run test:browser` from the exact release source and
repeat clean install, migration, backup, restore, and post-restore security acceptance for MariaDB, MySQL, and
PostgreSQL.
