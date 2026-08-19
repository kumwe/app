# Demo profiles

Kumwe's built-in demonstration data is packaged as discoverable, versioned profiles rather than hard-coded
seeds. A profile is a directory of validated JSON manifests below `resources/demo`; the demo catalog
discovers what a release ships, operators select profiles through environment variables, and the migration
command reconciles the selection durably. Forks and derived distributions add their own demonstrations —
a school, a farm, or an online shop — by dropping a new profile next to the released ones. No PHP changes
are required to make a well-formed profile selectable.

## Datasets and selection

Two datasets are selected independently, so any combination of content and business data is possible:

| Dataset | Selector | Shipped choices | Default |
|---|---|---|---|
| Site content | `KUMWE_SITE_CONTENT_PROFILE` | `documentation`, `placeholder`, `blank` | `documentation` |
| Business demonstration | `KUMWE_BUSINESS_PROFILE` | `vdm`, `none` | `vdm` |

The legacy boolean `KUMWE_BUSINESS_DEMO` keeps working as an alias: `true` selects `vdm` and `false`
selects `none`. The named selector wins when both are set. `composer create-project` prompts for both
choices and lists whatever profiles the installed release actually ships.

Selection is frozen per installation the first time a dataset reconciles: the durable profile ledger
records the choice and later migration runs refuse a different profile, a manifest downgrade, or a
same-version byte change. A new profile therefore only affects fresh installations; released profiles
evolve through explicit version bumps.

## Where profiles live

```text
resources/demo/
├── content/
│   ├── documentation.json      # kumwe.demo-content/v1
│   ├── placeholder.json
│   └── blank.json
└── business/
    └── vdm/
        ├── profile.json        # kumwe.demo-business-profile/v1
        ├── definitions/*.json  # one typed entity definition per file
        └── records.json        # kumwe.demo-business-records/v1
```

A site-content profile is one `content/<name>.json` manifest. A business profile is one
`business/<name>/profile.json` manifest with its definition documents and records document beside it.
Profile names must match `^[a-z][a-z0-9-]{0,62}$`; the manifest's own `profile` field must equal its
file or directory name. Every manifest is bounded (2 MB per file, JSON depth 64) and carries an integer
`version` that must be raised whenever any byte of the profile changes.

The envelope also bounds how much a profile may declare: at most **64 definitions** in a business
profile's `installation_order`, and in an access manifest at most **64 staff**, **32 organizations**,
**16 members** per organization and **32 roles**. These are the demo-profile envelope — what a manifest
may hold before it stops being a demonstration dataset — and they are named once, as constants on
`FilesystemDemoManifestCatalog`, because the reader, the installer and the exporter must all agree on
them. An installation larger than the envelope is not exportable as a demo profile; the export command
says so and writes nothing.

## Site-content manifests

A site-content manifest declares the managed site, its settings (including presentation), content pages,
and menus, each carrying a stable `fixture_key`. Reconciliation is a three-way checksum comparison:
a fixture is updated only while it still matches the released state Kumwe last applied, so operator
customizations are always preserved and reported rather than overwritten.

## Business manifests

A business profile declares typed entity definitions in dependency order and replays records, relations,
workflow actions, and archives through the same application services an operator uses — policy, audit,
revisions, idempotency, and schema plans included. Definition handles must use the profile's own
namespace, `site.default.<profile>_<name>`, and the installer projects handles and identities
deterministically for any additional site. Applied operations are append-only and byte-immutable:
a released fixture can never be edited, reordered, or removed, only extended by appended operations
under a higher manifest version. Record access policies are derived from each definition's field
flags and installed with provenance checkpoints under `core.demo.<profile>.…` policy codes.

## Access manifests

A business profile may ship an `access.json` manifest (`kumwe.demo-access/v1`) declaring its
demonstration cast: administrator staff roles with their capability sets, portal client organizations
with their workspaces, and the sign-in identities for both. The released VDM cast declares an
accountant, clerk, bookkeeper, stockkeeper, and system administrator on the staff side, and portal
organizations in which one company has two members while others have one — every address inside the
reserved `.example` zone. The `demo:provision-access` command turns this manifest into real accounts:
roles and users through the canonical access-control service under an authenticated administrator,
memberships through the Business Security tables with audit records, and one generated password per
new account written to an owner-only credentials file. The `demo:install` command is the front door:
it runs this provisioning and the example-extension installation together in one authenticated,
repeatable step. See [the command reference](cli.md#demonstration-sign-ins).

## What profiles never contain

Profiles are data-only. They never contain PHP, SQL, environment interpolation, or credentials of any
kind — no passwords, tokens, key material, or secrets, which the manifest tests enforce. Demonstration
accounts receive generated credentials at deployment time through host-authorized commands; see
[the production demonstration](demonstration.md) for how those credentials are surfaced to reviewers.

## Adding a profile in a fork

1. Create the manifest files under `resources/demo/content` or `resources/demo/business/<name>` with the
   correct `format`, `profile`, and `version` fields.
2. For a business profile, give every definition the `site.default.<name>_` handle prefix, a fresh UUID,
   and list it in `profile.json` `installation_order` with correct `depends_on` entries; declare every
   record operation in `records.json` with a unique `fixture_key` and idempotency key.
3. Select it with `KUMWE_SITE_CONTENT_PROFILE=<name>` or `KUMWE_BUSINESS_PROFILE=<name>` before the first
   `php bin/kumwe database:migrate`.
4. Keep the release contract: bump `version` on any change, only append business operations, and update
   the profile's expected counts and its verification contracts.

The released VDM business example under `resources/demo/business/vdm` is the reference implementation of
this contract.

## Exporting a running system

The `demo:export-profile` command projects a running installation back into an installable package
through the same authorized application services an administrator uses:

```bash
php bin/kumwe demo:export-profile \
  --admin-email=admin@example.test \
  --admin-password-file=/root/.kumwe-admin-password \
  --profile=my-site \
  --output=/root/kumwe-export
```

The password file obeys the same rules as `demo:provision-access`: an absolute path to a regular file
that is not a symlink and carries no group or other permission bits. The output directory must not
exist yet; the command creates it, writes the package below `<output>/resources/demo`, and re-validates
every manifest through the same catalog that guards release manifests before reporting success.

The package contains the site-content manifest (`content/<profile>.json`), and — when the site
publishes site-owned business definitions — the business dataset under `business/<profile>/`: the
profile document, one canonical definition document per published definition in dependency order, the
records document with every record, relation, workflow action, and archive, and the demonstration
access manifest. Beside the tree the command writes `export.json`, an integrity index repeating each
document's canonical checksum so a recipient can verify the package without trusting its transport.

A site that publishes more definitions — or references more roles — than the envelope carries is
refused before anything is written, naming the count it found and the bound it exceeded. A demonstration
dataset is not a backup: exporting a fully populated production site is outside what the format is for.
Copying `<output>/resources/demo` over an installation's `resources/demo` makes the profile selectable.

Resources installed from a profile keep their ledgered fixture keys, idempotency keys, and applied
request bytes, so an export of an installed dataset stays diffable against the manifest it came from;
operator-created resources receive freshly minted keys. Record-access modes are recovered through the
frozen-selector invariant: a definition still declared by the configured source business profile keeps
the `record_access` that profile shipped for it, and every other definition is exported as
`administration`, the most restrictive mode, for an operator to relax deliberately.

The access manifest applies a hard privacy rule: only identities whose address already lives inside
the reserved `.example` zone are exported. Every other identity is withheld, the command reports how
many were withheld, and when no identity qualifies at all `access.json` is not written rather than
written invalid. Credentials are never exported — no export path can reach a password, token, or key —
so accounts on a target installation are re-provisioned with `demo:provision-access`, which generates
fresh passwords at deployment time and writes them once to an owner-only credentials file
(a regenerate-and-reset flow, never a copy).
