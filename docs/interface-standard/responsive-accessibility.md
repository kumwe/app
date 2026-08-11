# Responsive behavior and accessibility

KIS targets WCAG 2.2 AA and usable task completion, not merely zero automated violations. Semantics,
keyboard behavior, focus order, responsive reflow, long data, and state announcements are component
contracts.

## Container-first layout

Patterns respond to their usable container after shell navigation, padding, and local navigation are
accounted for. Product templates must not choose a breakpoint from viewport width alone when a fixed or
resizable sidebar changes the content budget.

Named tokens define shell, readable content, form, catalog, drawer, dialog, and table limits. Every grid
child that may shrink uses `min-width: 0`; long technical values wrap or scroll inside their own labelled
component. Master-detail collapses the catalog before form controls become narrower than their content.

Typography, spacing, control sizes, and density scale through tokens. Shrinking text to preserve a desktop
grid is prohibited. Touch targets remain at least the KIS minimum and retain space between destructive
and ordinary actions.

## Required matrix

Primary routes are exercised at 1920×1080, 1440×960, 1280×800, 1024×768, 768×1024, and 390×844, in light
and dark modes, with empty, sparse, representative, dense, and extreme-label data. Default, validation,
success, permission-reduced, and destructive-confirmation states are covered with mouse, keyboard-only,
and touch viewport interaction.

Reduced motion, high contrast, compact, comfortable, touch, and print behavior are component concerns.
Motion respects `prefers-reduced-motion`; focus, saving, error, and selection state never depend on motion.

## Keyboard and focus

- Focus order follows task order and contains no hidden or duplicated controls.
- Focus is always visible and is not obscured by sticky content.
- Tabs implement roving focus and preserve ordinary link activation.
- Drawers and dialogs trap focus only while modal, close with Escape when safe, and return focus to the
  invoker. Dirty state is handled before close.
- Adding/removing/reordering subform rows announces the change and restores a meaningful focus target.
- Validation summaries activate and focus targets in inactive tabs or steps.
- Navigation drawers restore focus to the navigation button.
- Drag-and-drop has a complete keyboard alternative.

## Assistive technology

Every page has one `h1`, a logical heading outline, named landmarks, named regions for intentional table
scrolling and results, explicit status/error announcements, and programmatic selected/expanded state.
Color is never the sole carrier of status, severity, selection, or validation.

## Deterministic layout failures

Conformance fails when a visible interactive element escapes its scroll container without an intentional
contract; a label, heading, button, badge, or navigation item clips; controls materially overlap; the
document scrolls horizontally; a table overflows an unlabelled region; sticky content obscures a target;
or a modal/drawer leaks focus.
