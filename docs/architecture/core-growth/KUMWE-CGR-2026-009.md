---
schema: kumwe-core-growth-record/v1
id: KUMWE-CGR-2026-009
title: "Host substitution of protected storage for sealed secrets before the record-value guard"
symbols:
  - Kumwe\App\BusinessRecord\Domain\RecordValueProtection
layer: domain
capability_index_sha256: "623ca68c369b197c84b01ffd2b58ff60cbae278826256eb55cfa38c8380b5a72"
packages_reviewed:
  - package: kumwe/record-values
    version: 0.1.4
    symbols_inspected:
      - "Kumwe\\Record\\Value\\RecordValueGuard"
      - "Kumwe\\Record\\Value\\ProtectedRecordValue"
    source_inspected:
      - vendor/kumwe/record-values/src
    tests_inspected:
      - vendor/kumwe/record-values/docs/test-ownership.md
  - package: kumwe/secret-envelope
    version: 0.1.1
    symbols_inspected:
      - "Kumwe\\Secret\\Value\\EncryptedEnvelope"
      - "Kumwe\\Secret\\Contract\\EnvelopeCipher"
    source_inspected:
      - vendor/kumwe/secret-envelope/src
    tests_inspected:
      - vendor/kumwe/secret-envelope/docs/test-ownership.md
search_terms:
  - protected record value
  - encrypted envelope
  - record value guard
  - canonical storage spelling
  - host cryptography adapter
  - unsupported runtime type
required_capability: "Every business-record value App hands the package guard, including a sealed secret held as a Kumwe\\Secret\\Value\\EncryptedEnvelope, must be admitted and canonicalised as before, with the envelope's storage spelling and therefore every stored checksum, fingerprint and revision byte unchanged, while the package guard knows no cryptography and refuses a raw envelope."
consumers:
  - src/BusinessRecord/Domain/BusinessRecord.php
  - src/BusinessRecord/Domain/BusinessRecordRevision.php
  - src/BusinessRecord/Application/BusinessRecordMutationPublication.php
  - src/BusinessRecord/Application/BusinessRecordRelationshipCoordinator.php
  - src/BusinessRecord/Application/BusinessRecordRevisionView.php
  - src/BusinessRecord/Application/BusinessRecordService.php
  - src/BusinessRecord/Application/RecordFingerprint.php
  - src/BusinessRecord/Application/RecordRequestGuard.php
  - src/BusinessRecord/Application/RecordRuleValidator.php
  - src/BusinessRecord/Application/RecordValueCodec.php
  - src/BusinessRecord/Infrastructure/Persistence/DoctrineBusinessRecordRevisionRepository.php
  - src/BusinessSurface/Application/BusinessMutationPlanService.php
overlap_reviewed: []
decision: approved
decided_by: "eWɘyn"
reviewer: "eWɘyn"
decided_on: "2026-09-23"
pull_request: "https://github.com/kumwe/app/pull/151"
---

## Capability required

A business record, a revision snapshot, a request fingerprint, a mutation diff and the revision row all pass
their values through the record-value guard, and a `core.secret` field holds its value as a sealed
`Kumwe\Secret\Value\EncryptedEnvelope`. After `kumwe/record-values 0.1.4` owns the guard, App still has to
admit that envelope wherever it admitted it before, and its canonical spelling has to stay the four storage
strings — base64 ciphertext, base64 nonce, key identifier, algorithm — in the order the App has always written,
checksummed and fingerprinted them. Nothing may be decrypted, verified or re-encoded on the way, and a PHP
float or an unsupported object must still be refused at the same boundary.

## Why existing package APIs are insufficient

`Kumwe\Record\Value\RecordValueGuard` (`assertValue()`, `canonical()`) admits null, bool, int, string, the
conversion values, `ZonedDateTimeValue`, `DateTimeImmutable`, arrays of those, and
`Kumwe\Record\Value\ProtectedRecordValue`; a `Kumwe\Secret\Value\EncryptedEnvelope` is an unsupported runtime
type to it, by design, because the package's dependency ceiling excludes cryptography.
`ProtectedRecordValue` is the form it admits: an opaque, bounded, string-keyed map "supplied by the host
cryptography adapter", canonicalised as the map itself. The package therefore provides the target of the
substitution but not the substitution, and cannot: it does not depend on `kumwe/secret-envelope` and does not
know which App values are envelopes. `Kumwe\Secret\Value\EncryptedEnvelope::toStorage()` provides the storage
spelling but knows nothing of the guard. Neither package can perform the mapping between them.

## Why extending the owning package is inappropriate

Teaching `kumwe/record-values` to admit `EncryptedEnvelope` would add `kumwe/secret-envelope` to a package whose
charter names cryptography as a non-responsibility and whose `ProtectedRecordValue` exists precisely so that a
host supplies protected storage without the package taking an encryption dependency. Teaching
`kumwe/secret-envelope` about the record guard would invert the dependency direction between two foundations.
The mapping is the seam between the two packages, and the seam belongs to the composition root that installs
both.

## Why a new focused package is inappropriate

The behaviour is one static substitution of one App-chosen value type into one package value type, bounded by
the guard's own depth, with no consumer outside App and no semantics of its own: the storage bytes are the
envelope's, the admission and canonical rules are the guard's. There is no portable bounded context to
extract.

## App-specific responsibility

This is host composition and a cryptography adapter, which the library-first policy names as App-owned. App
owns sealing, decryption, associated data, key custody and rotation for record secrets; it is the only party
that knows an `EncryptedEnvelope` is what a `core.secret` value is, and the only party that can promise the
guard that the substituted storage is exactly what its checksums cover. If the substitution lived outside App,
either a package would take a cryptography dependency it refuses, or App would have to change what a
`core.secret` value is in every codec, rotation and backup path, moving stored bytes for no gain.
`RecordValueProtection::protect()` is the one place the substitution happens; every guard call in App passes
through it, and it adds no other behaviour.

## Tests proving the boundary

`tests/Unit/BusinessRecord/Domain/RecordValueProtectionTest.php` pins that an envelope is admitted by the package
guard after substitution, that its canonical bytes are `toStorage()` in its own member order at the top of a
value and nested inside a snapshot map, that a value without an envelope passes through untouched, that a PHP
float is still refused at the App boundary (the host assertion the retired `RecordValueGuardTest` held), and
that a structure beyond the guard's depth bound is refused by the guard rather than walked.
`tests/Unit/BusinessRecord/Domain/RecordIntegrityTest.php` proves a revision holding a sealed secret checksums
and its disclosure view redacts it; `tests/Unit/BusinessRecord/Domain/ExactValueCodecTest.php` and
`tests/Unit/BusinessRecord/Infrastructure/SecretKeyLifecycleTest.php` prove sealing, associated data and key
lifecycle stay in App; the retired `RecordValueGuardTest` and `ConvertedMoneyPresentationInvariantTest` show App
duplicates nothing the package's `NormalizationBoundaryTest` owns.

## Decision

Approved on 2026-09-23 by eWɘyn, reviewed by eWɘyn, under the standing maintainer mandate for the
kumwe/record-values adoption (KUMWE-MIG-2026-029). The review opened the installed `kumwe/record-values 0.1.4`
and `kumwe/secret-envelope 0.1.1` sources, their test-ownership records and the App call sites listed above,
and found no package API that performs the substitution and no package that may take the dependency it needs.
Approval covers the one FQCN and its single public method. Revisit this record when `kumwe/record-values` next
releases a way for a host to register protected-storage suppliers, or when a record-model adoption moves the
record and revision aggregates that call it; if either package comes to own the seam, the adapter is deleted
and this record retired.
