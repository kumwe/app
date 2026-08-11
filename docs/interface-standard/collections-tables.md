# Collections and tables

## Collection workspace

A collection workspace separates discovery from creation and editing. Its normal order is page header,
context, filter/search toolbar, active-filter summary, results summary, collection, bulk actions when a
selection exists, and pagination.

Creation uses a dedicated route, step flow, or bounded drawer selected by
[Pattern selection](pattern-selection.md). A full creation form must not sit permanently above a list.

## Discovery toolbar

- Search has an accessible name and describes what it searches when the scope is not obvious.
- Ordinary filters remain visible; advanced filters use a labelled disclosure or drawer.
- Applied filters render as removable values and offer a single reset action.
- Sorting states both field and direction.
- Saved/default views record owner, scope, schema version, filters, sort, columns, and density.
- Filter application and reset work without JavaScript.
- Query controls never reveal unauthorized values, labels, counts, or relationships.

## Required states

Every resource collection declares and tests loading, empty, sparse, representative, dense, extreme
labels, error, and permission-reduced states. Empty states distinguish between no data, no matching data,
and a prerequisite the actor cannot satisfy. Loading placeholders preserve layout but are never the only
status announcement.

## Table contract

- The first useful identity column and row actions remain discoverable.
- Headers use correct scope and expose sort state.
- Captions or labelled regions explain the table purpose and intentional scrolling.
- Cells wrap ordinary text and use technical-value treatment for identifiers.
- Row actions have stable accessible names that include the target where needed.
- Horizontal scrolling is allowed only inside a labelled region with a visible affordance.
- A table that loses row comprehension at a constrained width becomes summary cards or a primary/secondary
  disclosure; merely shrinking typography is prohibited.
- Sticky headers or columns must not obscure focused controls, anchors, validation targets, or dialogs.
- Empty cells have a meaningful unavailable state, not ambiguous whitespace.

Column visibility, order, and width may be customized within mandatory identity, status, selection, and
action constraints. Policy-hidden fields never enter the client document or column chooser.

## Selection and bulk work

Bulk controls appear only after selection and state the selected count and scope. “Select all” must say
whether it means the current page, current result set, or an explicitly bounded total. A bulk mutation
uses the same authorization, optimistic version, idempotency, audit, review, and confirmation rules as a
single mutation. Partial outcomes identify succeeded, skipped, conflicted, and failed targets without
exposing denied resources.

## Pagination and large data

Pagination preserves filters, sort, selected view, and allowed column preferences. Cursor pagination is
preferred for generated business collections whose ordering may change. Page size is bounded by KIS and
cannot be customized into an unbounded query. Counts and aggregates apply record policy in the query.

Large results render incrementally or paginate; they do not hydrate an unbounded catalog into the DOM.
Export is a separately authorized, auditable operation and never a client-side dump of hidden columns.
