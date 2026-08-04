# Release verification

The release workflow accepts only `v2.x.y` tags, requires a committed dependency
lock, runs the project quality suite, builds the runtime and web images, scans
them, generates CycloneDX SBOMs, signs image digests and release checksums with
keyless Cosign, and publishes provenance attestations.

Before deployment, verify the Git tag and downloaded checksums, then verify the
keyless signing identity expected for this repository. Replace the repository and
workflow references below with the exact canonical values if the project moves:

```bash
sha256sum --check SHA256SUMS
cosign verify \
  --certificate-identity-regexp='^https://github.com/Kumwe/cms/.github/workflows/release.yml@refs/tags/v2\.' \
  --certificate-oidc-issuer=https://token.actions.githubusercontent.com \
  ghcr.io/kumwe/cms/app@sha256:APP_DIGEST
cosign verify-attestation \
  --type cyclonedx \
  --certificate-identity-regexp='^https://github.com/Kumwe/cms/.github/workflows/release.yml@refs/tags/v2\.' \
  --certificate-oidc-issuer=https://token.actions.githubusercontent.com \
  ghcr.io/kumwe/cms/app@sha256:APP_DIGEST
cosign verify-blob \
  --bundle SHA256SUMS.cosign.bundle \
  --certificate-identity-regexp='^https://github.com/Kumwe/cms/.github/workflows/release.yml@refs/tags/v2\.' \
  --certificate-oidc-issuer=https://token.actions.githubusercontent.com \
  SHA256SUMS
```

Compare the image digests with the provenance subjects and deploy those digests,
not mutable tags. Review the SBOM and scanner output under the site's risk policy.
A passing scanner is evidence for the scanned database and artifact, not proof
that the application is vulnerability-free.
