# Verify a release

## Beta1 identity and authority

The declared prerelease is **`v2.0.0-beta.1`**, with package/runtime version `2.0.0-beta.1`.
It is a beta, not a stable release or a declaration that Gate B has passed. Composer derives its
version from the Git tag; `composer.json` deliberately has no version field. npm metadata, the installer,
PHP's default release stamp, Docker's default/root Composer version and `.env.example` agree.
Existing `@since 2.0.0` annotations record introduction history and are unchanged.

The continuous lane preserves automatic numbering: each green master commit cuts the next
`<base>-<channel>.<n>` tag. The declared channel is beta, so the first cut is Beta1 when no beta tag
exists. A rerun of an already tagged commit does not cut another version. After source qualification,
the builder stamps that generated tag version into the six package/runtime version surfaces. The signed
manifest records each source and artifact file digest; dependency versions and introduction annotations
do not change. No tracked version bump, manual tag or repository configuration change is required for
subsequent beta cuts. Publication refuses unless all of these are true:

- the workflow runs from the release tag, the requested tag and checked-out commit agree, and the
  commit is reachable from `master`;
- GitHub records that exact commit as the result of a merged, non-draft PR into `kumwe/app` `master`;
- complete `ci.yml` push CI succeeded on that exact `master` commit;
- no GitHub release already occupies the tag; existing version/commit image aliases either agree with
  the qualified digest or publication fails.

Maintainer merge is the acceptance prerequisite. A draft PR, a feature-branch workflow, a tag on an
unmerged head or a successful subset of CI cannot publish Beta1. A workflow dispatch must use the tag
as its workflow ref as well as its `tag` input.

## Build once, qualify, then publish

The release workflow keeps the source quality-contract lane and all existing deployment checks. Its
order is now:

1. Check merge authority and exact-source CI, run the release quality contract, build each image once,
   create one Composer source ZIP and one dependency-complete ZIP, scan and produce SBOMs.
2. Bind both image digests, both ZIP checksums, Composer repository metadata, SBOMs, lockfiles, native
   source declaration, compiled browser asset digests and the fixed Studio family into immutable
   `kumwe-release-manifest.json` (contract version `2.0.0`). Record the merged PR, source CI run and
   build run/attempt. Sign the image digests and `SHA256SUMS`, attest the actual distribution bytes
   and preserve them as one Actions candidate artifact.
3. Download and verify that signed candidate in the existing deployment workflow. Release-mode jobs
   must not rebuild. Deploy digest-pinned images on MariaDB, MySQL and PostgreSQL, retaining the
   existing HTTP/CLI/MCP, extension lifecycle, restart and backup/restore drills. Install the preserved
   ZIP and the preserved Composer source ZIP; compare both installed lockfiles with the source lock.
   Verify image signatures, SBOM attestations and provenance against the exact workflow and commit.
4. Emit a receipt only after each lane succeeds: `distribution`, `mariadb`, `mysql`, `pgsql`.
   Every receipt binds the same manifest digest, commit, version, workflow run and attempt. Missing,
   duplicate, failed, substituted or previous-attempt receipts refuse promotion.
5. Recheck authority, sign the checksum set including `kumwe-release-qualification.json`, attest the
   qualification record, copy the already qualified image manifests to release aliases and verify
   their digests did not change. Publish the preserved bytes to GitHub as a prerelease.

Candidate registry tags are `candidate-<run>-<attempt>` and are created only after merge/CI authority
passes. They are not release aliases. Beta1 receives only `2.0.0-beta.1` and its source-commit image
aliases, with GitHub `prerelease=true` and `latest=false`. Stable versions may additionally receive
minor, major and `latest` aliases. Deploy signed digests rather than mutable aliases.

The signed manifest is never rewritten after qualification. The separately signed qualification record
references its digest. Four completed deployment receipts prove these four lanes; they do not by
themselves assert the independent review, isolation, proof portfolio, chaos programme or Gate B complete.
A failed attempt is not successful evidence. Re-running only publication with an earlier attempt's
receipts is refused; a new complete attempt must rebuild and requalify, and cannot replace published bytes.

## Verify downloaded artifacts

Download the complete release asset set before verifying. Substitute the intended exact version and
source commit; the commands below demonstrate Beta1, not an assertion that it has been published.

```bash
version=2.0.0-beta.1
identity="https://github.com/kumwe/app/.github/workflows/release.yml@refs/tags/v$version"
cosign verify-blob --bundle SHA256SUMS.cosign.bundle \
  --certificate-identity="$identity" \
  --certificate-oidc-issuer=https://token.actions.githubusercontent.com SHA256SUMS
sha256sum --strict --check SHA256SUMS
```

Inspect the signed manifest's source commit, merged PR, build identity and artifact digests. Check that
all four qualification receipts name this manifest's SHA-256 and the same run/attempt. The Studio
`record.release` must match every package in `record.packages`; compare `recordSha256` with the tagged
`resources/studio-contract/studio-release.json`. Check the compiled asset and input hashes against the
same source. Refuse a missing artifact, unexpected source or mismatched digest.

## Verify images and attestations

```bash
# Use the exact references from the verified manifest.
app_ref="$(jq -er '.images.application | .reference + "@" + .digest' kumwe-release-manifest.json)"
commit="$(jq -er '.source.commit' kumwe-release-manifest.json)"
cosign verify --certificate-identity="$identity" \
  --certificate-oidc-issuer=https://token.actions.githubusercontent.com "$app_ref"
cosign verify-attestation --type cyclonedx --certificate-identity="$identity" \
  --certificate-oidc-issuer=https://token.actions.githubusercontent.com "$app_ref"
gh attestation verify "oci://$app_ref" --repo kumwe/app \
  --signer-workflow kumwe/app/.github/workflows/release.yml --source-digest "$commit"
gh attestation verify "kumwe-app-$version.zip" --repo kumwe/app \
  --signer-workflow kumwe/app/.github/workflows/release.yml --source-digest "$commit"
gh attestation verify "kumwe-composer-$version.zip" --repo kumwe/app \
  --signer-workflow kumwe/app/.github/workflows/release.yml --source-digest "$commit"
```

Repeat image checks for the manifest's web image. Review the SBOM and scan evidence; a passing scan
records the artifact and vulnerability database at scan time, not absence of every vulnerability.

## Install the qualified Composer distribution

The published `composer-repository.json` binds the root package version, source reference and preserved
`kumwe-composer-<version>.zip` URL/checksum. After verifying it with the signed checksum set, use it as
the Composer repository:

```bash
composer create-project --no-dev --prefer-dist \
  --repository='{"type":"composer","url":"file:///absolute/path/composer-repository.json"}' \
  kumwe/app ./kumwe 2.0.0-beta.1
```

Preserve `composer.lock` and run `composer audit --locked --abandoned=fail`. Composer also checks the
source ZIP's SHA-1 transport checksum; the release signature independently binds its SHA-256.
The qualification lane changes only the URL to a local file containing those exact bytes. The generic
Packagist/GitHub-generated source ZIP is a different transport artifact; successful installation of it
must not be represented as qualification of these exact bytes.

## Publication prerequisites and remaining qualification

Signing uses the existing GitHub Actions OIDC identity with `id-token: write`, `attestations: write`,
`packages: write` and `contents: write`; no manually supplied signing key is required. Authority queries
also require `actions: read` and `pull-requests: read`. The reusable acceptance caller grants read-only
package/attestation access. Existing continuous-release and release jobs already use these token permissions to create tags and
publish signed alpha releases. The repository's rulesets response was empty and master reported
`protected=false` when checked on 2026-09-28; this workflow adds no protection or environment approval
requirement. It uses the existing Actions authority and access to GHCR and Sigstore's identity,
certificate and transparency services. Branch/ruleset or environment requirements still apply. A local
shell cannot manufacture GitHub's workflow OIDC identity or replace that prerequisite with a fake key.

This document specifies the implemented release path, not a successful publication record. Actual
Beta1 qualification still requires the integrated source to pass checks, maintainer merge, release tag,
and a successful release workflow with retained signed assets. Current Point 5 obligations also include
`P7-B` scaled chaos/backlog recovery, `GM-SUP-05` out-of-process isolation, `P7-F` separately signed
vertical-neutral proof extensions on all three engines, `P7-H` synchronized release documentation,
and `P7-I` independent candidate review. The portfolio and isolation require their own implementation
owners. Optional long operator soaks remain distinct under ADR 0021. The parent integration owns the
acceptance ledger, baseline and changelog; none of these obligations is silently declared delivered here.

## Business-security acceptance

The commands in this section run in the controlled source-build and release-qualification environment. They
are not production installation or server-operation steps. Released images and archives already contain the
compiled browser assets; an installed Kumwe production runtime must contain and require neither Node.js nor
npm.

Before promotion, retain evidence from the supported-database matrix for owner-aware catalog synchronization,
deny-overrides policy evaluation, field-usage isolation, policy-before-pagination SQL, non-enumerating direct reads,
organization/workspace freshness, scoped token non-escalation, maker-checker quorum and replay, TOTP/recovery replay,
portal cookie and CSRF isolation, extension trust lifecycle, recovery-mode isolation, and accessible browser flows.
Run `composer qa`, `npm run check`, `npm run build`, and `npm run test:browser` from the exact release source and
repeat clean install, migration, backup, restore, and post-restore security acceptance for MariaDB, MySQL, and
PostgreSQL.

### Source licences and complete dependency evidence

`resources/release/license-policy.json` is the repository's finite source-distribution policy. It permits
Apache-2.0, BSD-2-Clause, BSD-3-Clause, ISC, MIT, MPL-2.0, PHP-3.01 and Unicode-3.0 with their notice and source
obligations. Missing, unknown or unreviewed compound declarations fail. This policy is an agent implementation
decision reviewed with the release change; maintainer merge accepts it without a separate configuration step.

`tools/source-sbom.sh` verifies the admitted native source archive checksum and embedded source file inventory,
then `tools/source-sbom.mjs` produces CycloneDX for **every** Composer production/development dependency and npm
production/development/optional-platform lock occurrence. Native binding, Engine, PCRE2, SLJIT, Unicode and
PHP-filter notices are embedded from those verified bytes. Changing the admitted native archive requires its
licence review pin to change too. The inventory preserves distribution URLs, source references and lock-entry
hashes, including the corresponding source locations for unmodified MPL browser modules. Upstream licence,
copyright and notice files remain part of installed packages; App never modifies vendor source.

The evaluation records the exact commit, inventory digest, policy digest, component decisions and result. The
security workflow retains it for 90 days on each tested commit, alongside the existing independent Syft
filesystem inventory, and attests successful master-push evidence using GitHub OIDC. Pull-request workflows
retain evidence without requiring privileged fork credentials. OS packages and workflow actions remain in
Syft's separate inventories and scans; this source policy does not claim their licences were evaluated.

Release builds publish the same three source evidence files as signed manifest subjects and build-provenance
subjects: `kumwe-source.cdx.json`, `kumwe-license-policy.json` and `kumwe-license-evaluation.json`. Image and
archive Syft SBOMs, image vulnerability scans, Cosign signatures and deployment qualification remain required.
An absent or changed licence evidence file invalidates the candidate digest set.
