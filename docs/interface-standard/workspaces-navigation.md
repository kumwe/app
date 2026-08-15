# Workspaces and navigation

## Shell contract

Administrator, portal, and public shells are separate security and presentation surfaces. They may use
different themes and navigation depth, but share KIS task semantics. Guest and authenticated states are
explicit shell states; removing a navigation element must never leave its grid column or landmark behind.

Every authenticated workspace provides:

- one main landmark and a skip target;
- a predictable global navigation landmark;
- visible current location and selected navigation state;
- a page header with purpose, current context, status, and at most one primary action;
- a stable content container with `min-width: 0` at every grid boundary;
- a safe mobile navigation mechanism that restores focus to its invoking control;
- no dependency on JavaScript for route navigation or form submission.

Portal language describes ordinary work, approvals, reports, and account security. Internal capability,
definition, storage, and policy terminology appears only where the portal actor genuinely manages it.

## Page header

A KIS page header renders, in order:

1. optional breadcrumb or bounded-context label;
2. one `h1` naming the resource or task;
3. a concise purpose sentence;
4. contextual identity and lifecycle status when they affect decisions;
5. one primary action for the current state;
6. secondary actions in an ordered group or overflow menu.

The page header must identify the task from the first viewport. It must not become a toolbar for every
possible operation. Technical handles, UUIDs, checksums, and versions use the technical-value component.

## Local navigation and tabs

Stable destinations have real URLs. A selected tab is encoded in a bounded query parameter or path
segment, survives refresh and history navigation, and can be linked. Permission filtering omits denied
tabs entirely without leaving empty panels or counts.

For 2–5 stable concerns, horizontal tabs provide:

- `tablist`, `tab`, and `tabpanel` semantics;
- `aria-selected`, `aria-controls`, and a labelled panel;
- Left/Right, Home/End, Enter/Space, and ordinary link behavior;
- a visible active state that does not depend on color alone;
- horizontal overflow with an explicit scroll affordance on constrained containers;
- server-rendered links and stacked, anchored sections as the no-JavaScript fallback.

More than five stable concerns use grouped local navigation. Do not squeeze an application menu into a
single tab row. A permission-reduced actor sees only coherent destinations and never empty placeholders.

## Master-detail workspaces

A master-detail workspace combines a searchable catalog with one selected resource. It must provide:

- a labelled catalog, count, filters, selected state, and empty result;
- a selected-resource context header independent of the active task tab;
- a stable selected-resource URL;
- a bounded catalog width and a detail column with `min-width: 0`;
- container-aware collapse before either column becomes unusable;
- a catalogue drawer or focused selector on constrained containers;
- focus return and dirty-state protection when a drawer closes;
- a stacked no-JavaScript fallback with catalog links followed by detail.

The catalog may be sticky only when it does not obscure targets, exceed the usable viewport height, or
trap keyboard users. The detail column owns task tabs; catalogs do not contain management forms.

## Dashboard workspaces

An authenticated administrator or portal dashboard is a semantic overview of permitted work, not a
content index or a page builder. It contains a page header, an ordered widget region, an ordered quick-link
region and, when the actor may customize it, one progressively disclosed server-rendered preference workspace.
The widget kinds are closed: `summary`, `activity`, `context` and `workflow`. Summary and activity data is
bounded, context names the server-resolved scope affecting the session, and workflow widgets link to a real
task route. A permission-reduced actor still receives coherent permitted workflows even when no core summary
applies.

The workflow and quick-link catalog is the visible navigation catalog after ordinary owner, extension trust
and lifecycle, area and capability filtering. Composition removes the dashboard's own link, rejects
cross-area destinations and intersects saved semantic identifiers with the remaining live catalog. An
extension therefore becomes selectable by contributing its ordinary owned KIS surface and navigation item;
it does not contribute dashboard HTML, an unfiltered URL or a second navigation record.

The `dashboard-cards` and `navigation-shortcuts` slots select and order identifiers only. Dashboard cards
use the bounded dotted surface/navigation grammar rather than a core-only component vocabulary, so every
valid contributed navigation identifier can participate. Administrator and portal defaults may be
specialized for canonical identity access groups; multiple direct group lists combine deterministically,
then a personal list replaces the group result. A stale extension identifier produces a safe fallback
diagnostic and disappears rather than retaining its old destination. Saving and resetting use CSRF,
optimistic versions, ordinary authorization and audit through the presentation-preference mutation boundary,
and remain usable without JavaScript.

Access-group editing stays inside the dashboard area being configured. The forms appear only to an actor who
holds `users.manage`, and the mutation boundary rechecks that capability against the exact canonical role.
This means a portal group default is selected from the destinations visible in that real portal session; an
administrator surface never guesses which portal navigation another context could discover.

Every rendered widget has a programmatic heading and its visual size is a responsive hint, never reading
order. Search has a visible label, quick links remain a labelled navigation landmark, progress exposes its
numeric value, and empty or fully deselected dashboards explain the next available path. Templates may style
the shared components but cannot re-evaluate permissions, manufacture choices or suppress the reset path.

## Context preservation

Site, organization, workspace, definition, schema plan, user, report, and extension context remain
visible while navigating related tasks. Context changes are explicit and never silently inferred from a
previous browser state. A URL may omit a context only when one unambiguous default exists and the page
shows that default.

## Navigation contribution contract

Every core or extension navigation item has a stable owner-scoped identifier, workspace, label, purpose,
URL, capability, icon name, order, and KIS surface identifier. The icon must resolve through the shared
registry or an intentional extension fallback. Unknown icons, routes without enforceable capabilities,
duplicate identifiers, and items pointing outside their owner are rejected.

Templates may rearrange shell presentation within the approved override contract. They must retain the
navigation landmarks, current-state semantics, main target, capability-filtered entries, and mobile focus
behavior.
