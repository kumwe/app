# Forms and subforms

## Form contract

Forms begin with information the actor understands. Generated identifiers, stable handles, bindings,
checksums, and other technical metadata follow or are generated. Fields are grouped by user decision,
not by storage table.

Each section has a concise purpose and at most one dominant action. A form with more than eight ordinary
fields, branching, dependencies, or protected review uses a step flow. Stable independent concerns use
URL-addressable tabs only when the complete submitted document and conflict behavior remain explicit.

## Field behavior

- Labels remain visible; placeholders never replace labels.
- Help explains consequences or format, not the label itself.
- Required, optional, read-only, server-computed, and immutable states are explicit.
- Conditional fields remain absent or hidden until relevant. Their value preservation or clearing rule
  is declared and tested.
- Locale-safe display never changes the canonical submitted value of exact decimal, money, quantity,
  date, time, or instant fields.
- Policy-hidden fields do not render. Read-only presentation is not an authorization control.
- A large or remote relationship uses an accessible search chooser, never an unbounded native select.

## Validation and state

Validation appears beside each field and in a top summary. Summary links focus the invalid field and,
when necessary, activate its tab or step first. Errors state how to recover and retain non-secret values.

The form exposes unchanged, unsaved, saving, saved, conflict, and failed states. Navigation away from an
unsaved enhanced form warns once without trapping the actor. A server response remains authoritative.

Optimistic conflicts use a standard compare/reload/reapply flow. They show the submitted version, current
version, meaningful changes, and fields that can be safely reapplied. A blind overwrite action is not a
conflict resolution strategy.

## Subforms

An inline subform is permitted only for 0–8 simple child values owned by the parent transaction.

- Every row has a collapsed human summary, stable order, and visible error count.
- Add focuses the first meaningful new control.
- Removing an unsaved row means discard; removing a saved row explicitly means archive or delete.
- Reordering works with keyboard buttons and drag-and-drop enhancement.
- Child identifiers and versions remain attached to their rows.
- Nested editable subforms deeper than one level are prohibited.

More than eight children, complex child fields, independent permission/history, or frequent child work
uses a child collection and focused editor. The parent shows a count and meaningful summary instead of
dozens of expanded controls.

## Choosers and relationships

Small static choices use radios or a select. Large or remote choices use a searchable chooser whose
selected items remain visible and removable without reopening search. Multi-selection states its count
and keyboard behavior. Relationship queries apply policy before labels, counts, search results, or
selected summaries are returned.

## No-JavaScript guarantee

Every essential form posts through an ordinary URL with CSRF, expected-version, and capability checks.
Enhanced tabs, drawers, choosers, and subforms may improve efficiency, but the server-rendered fallback
must expose all required values and a complete submission path. Inactive enhanced panels must be restored
before browser constraint validation or rely on equivalent server validation with accessible errors.
