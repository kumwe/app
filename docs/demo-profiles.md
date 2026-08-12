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
