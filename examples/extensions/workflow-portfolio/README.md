# Signed workflow portfolio sources

These model fragments reuse the existing P7-E browser preparation in
`tests/Support/prepare-browser-contribution.php` at App commit `d196de05`. They contain no new core
vertical rules. `SignedWorkflowExtensionFixture` authors them into the released SDK's complete component
scaffold, preserves the scaffold's PHP 8.5 requirement and contributions, builds twice, runs conformance,
signs with a namespace-scoped test key, installs disabled, activates, independently approves every graph
schema plan and executes the portable DDL. It then grants the explicit test record policies.

| Fragment | Existing workflow | Original root handle |
| --- | --- | --- |
| `exact-document.json` | Thousand-line exact document; draft/review/approve/post | `site.default.doc_header_browser` |
| `relationship-service.json` | Shared relationship/self-service model with conditional fields and owned lines | `site.default.session5_order` |
| `mobile-assignment.json` | Assigned/in-progress/completed card with parts, labour, measurements and optional media | `site.default.browser_job_card` |
| `catalogue-order.json` | Portal order, payment state and administrator fulfilment | `site.default.browser_shop_order` |

The helper also accepts a contributed translation locale set. The content proof publishes through the
existing content workflow and associates its locale with that separately signed owner. The browser
journeys remain in `tests/Browser/user-acceptance-journeys.spec.ts`; these backend proofs do not replace
their graphical assertions.

## Browser preparation bridge

```php
$documents = json_decode(file_get_contents($fragmentPath), true, 64, JSON_THROW_ON_ERROR);
$fixture = SignedWorkflowExtensionFixture::install($environment, 'proof/ledger', $documents);
$container = $fixture->container;
$context = $fixture->context;
$header = $fixture->definitions['site.default.doc_header_browser'];
```

Use a unique owner for a repeated integration run and fresh definition UUIDs. A clean browser database
may use the fragment's fixed IDs. Handles become `proof.ledger.doc_header_browser` in the example;
exact relationship targets are remapped with them. Use the returned container/context and re-resolve
application services after each activation: the previous kernel correctly refuses stale generations.
Use a short vendor/name because the untouched SDK's migration has a portable 63-byte table-name limit.
Keep the returned fixture until lifecycle qualification finishes, then call `dispose()` to disable,
uninstall and revoke its test key. Never copy its signing seed to a published artifact.

This first checkpoint supplies the signed admission bridge and five executable backend cases. It does
not yet satisfy the six full P7-F portfolios, every primitive in their contract, the complete lifecycle
for each archive, browser handle adoption, or the three-engine backup/restore matrix. Those remain work
in the same Point 5 scope; the existing neutral asset package is not a substitute for them.
