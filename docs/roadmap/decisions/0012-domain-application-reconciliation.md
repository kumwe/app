# ADR 0012 — Domain and Application reconciliation: shared-kernel classification and the one approved interface

**Status** Accepted
**Decided by** Product owner, by accepting package P3-D's own taxonomy (move inward, invert, move
outward, or record an exact interface); this record applies it to the fifteen Domain-to-Application
edges the dependency baseline carried
**Verified against** `ea2a01d11a2b7ee0f3b5adb402b4f6394ef7e4d1`
**Findings** `V2-ARC-003`
**Gate** A

---

## Context

Package P3-D requires every Domain import of an Application type to be reconciled: a genuine domain
value moves inward, an application concern is inverted or passed as a result, an adapter-only
implementation moves outward, and any genuine exception is encoded as an exact interface in a
recorded decision and the dependency checker. The baseline carried fifteen such edges under
`V2-ARC-003`, all expiring 2027-06-30.

Two surfaces constrained the original mechanics:

- **Published migrations are byte-immutable.** `ApplicationAuthorizationMigration` imports
  `SiteContext` by its full name; changing that name would either change frozen bytes or leave the
  migration referencing a class that no longer exists.
- **The public extension SPI was App-owned and hash-pinned.** The pre-stable extraction deliberately
  reset that authority before the first supported 2.0 alpha. The package-owned SDK contract and its
  immutable release artifact now freeze the author surface instead.

The layer graph already separates classification from physical namespace: `layers.json` resolves a
class through longest-prefix rules before segment rules, and a full class name is a valid prefix.
Classification-in-place is therefore the mechanism that moves a type inward without moving a byte
of any frozen artifact.

## Decision

1. **Shared-kernel classification, in place.** `SiteContext` and `AuthenticatedSurface` are
   classified `shared`: each is framework-free, has a stable semantic owner (site identity; the
   authentication-surface vocabulary) and domain consumers across four modules — meeting the
   shared-kernel bar P3-D sets. `ContributionOwner` and `ContributionDefinition` are classified
   `domain`: the ownership identity and the pure contribution contract are implemented by domain
   types across four modules and depend only on domain types themselves.
   `ContributionDefinitionChecksum` keeps its application classification explicitly, because it
   depends on the runtime canonical serializer.
2. **`PortalContext` moves outward.** It pairs a site with a versioned membership authorization
   proof, produced by trusted portal resolution — application vocabulary, resolved by
   `Portal\Application` services. It now lives beside its resolvers in `Portal\Application`.
3. **The SDK manifest graph is the sole contribution authority.** Package admission parses the
   manifest once through `Kumwe\Extension\Manifest\ExtensionManifest` and consumes its immutable
   `ManifestContributions` graph. Schema one receives the SDK's explicit inert `fromSchemaOne()`
   graph; it has no code-side registration channel. The App may apply host admission policy and
   construct host-domain values from the already validated declarations, but it may not decode the
   document again, retain an App-owned declaration set, or translate historical author contracts.
   Executable code binds only to identifiers in that signed graph through the SDK binding SPI.
4. **A file's own namespace declaration is not a dependency.** The checker no longer records the
   declaration's name as a reference, which class-level classification would otherwise trip as a
   false self-edge.

## Consequences

- The dependency baseline carries no Domain-to-Application edge; the `V2-ARC-003` family in the
  baseline shrank by fifteen. The temporary manifest-parser exception was removed by the canonical
  SDK extraction. The remaining recorded families — delivery-to-infrastructure,
  contribution-to-presentation and infrastructure-to-kernel — keep their owner, finding and expiry
  and belong to the seams that own them.
- Any future Domain import of an Application type fails the build immediately: nothing may join
  the baseline's Domain-to-Application family again, and `approved_interfaces` entries require a
  recorded decision here.
- The physical namespaces of the four reclassified types are names, not layer statements;
  `layers.json` and the architecture map are the classification authority.
