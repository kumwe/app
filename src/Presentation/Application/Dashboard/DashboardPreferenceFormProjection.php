<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Application\Dashboard;

use InvalidArgumentException;
use Kumwe\CMS\Application\Presentation\Dashboard\DashboardPreferenceQuery;
use Kumwe\CMS\Application\Presentation\Dashboard\DashboardPreferenceService;

/**
 * Bounded dashboard preference forms plus access-group browser evidence.
 *
 * A delivery surface renders `forms` and must retain `diagnostics` in its template contract. The explicit
 * browser state keeps search and deterministic page navigation server-rendered and independent of JavaScript.
 *
 * @since  2.0.0
 */
final readonly class DashboardPreferenceFormProjection
{
    /**
     * Validate one complete dashboard preference form projection.
     *
     * @param   list<array{
     *              scope: string,
     *              scope_id: string,
     *              scope_label: string,
     *              label: string,
     *              message_ids: bool,
     *              help: string,
     *              group_code: ?string,
     *              available_widgets: list<array<string, mixed>>,
     *              available_shortcuts: list<array<string, mixed>>,
     *              selected_widget_ids: list<string>,
     *              widget_order: array<string, int>,
     *              widget_version: int,
     *              selected_shortcut_ids: list<string>,
     *              shortcut_order: array<string, int>,
     *              shortcut_version: int
     *          }>                        $forms                      Personal form followed by manageable groups.
     * @param   list<string>              $diagnostics                Stable non-sensitive query diagnostics.
     * @param   bool                      $accessGroupAdministration  Whether group management is authorized.
     * @param   DashboardPreferenceQuery  $accessGroupQuery           Validated role page and normalized search.
     * @param   int                       $accessGroupResultCount     Authorized group rows on this page.
     * @param   bool                      $accessGroupHasPrevious     Whether a previous page is reachable.
     * @param   bool                      $accessGroupHasNext         Whether a later page is reachable.
     * @param   bool                      $accessGroupBrowseLimit     Whether targeted search is required.
     * @param   ?DashboardWorkflowPage    $workflowPage               Bounded workflow browser state when enabled.
     *
     * @throws  InvalidArgumentException  When form or diagnostic collections are malformed or unbounded.
     *
     * @since   2.0.0
     */
    public function __construct(
        public array $forms,
        public array $diagnostics,
        public bool $accessGroupAdministration,
        public DashboardPreferenceQuery $accessGroupQuery,
        public int $accessGroupResultCount,
        public bool $accessGroupHasPrevious,
        public bool $accessGroupHasNext,
        public bool $accessGroupBrowseLimit,
        public ?DashboardWorkflowPage $workflowPage = null,
    ) {
        if (!array_is_list($forms) || count($forms) > DashboardPreferenceService::ACCESS_GROUP_PAGE_SIZE + 1) {
            throw new InvalidArgumentException('Dashboard preference forms must be a bounded list.');
        }
        if (!array_is_list($diagnostics) || count($diagnostics) > 4) {
            throw new InvalidArgumentException('Dashboard preference diagnostics must be a bounded list.');
        }
        foreach ($forms as $form) {
            if (
                !is_array($form)
                || !isset(
                    $form['scope'],
                    $form['scope_id'],
                    $form['available_widgets'],
                    $form['available_shortcuts'],
                )
                || !is_array($form['available_widgets'])
                || !array_is_list($form['available_widgets'])
                || count($form['available_widgets']) > 256
                || !is_array($form['available_shortcuts'])
                || !array_is_list($form['available_shortcuts'])
                || count($form['available_shortcuts']) > 256
            ) {
                throw new InvalidArgumentException('A dashboard preference form projection is invalid.');
            }
        }
        foreach ($diagnostics as $diagnostic) {
            if (
                !is_string($diagnostic)
                || preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)+$/D', $diagnostic) !== 1
            ) {
                throw new InvalidArgumentException('A dashboard preference form diagnostic is invalid.');
            }
        }
        if (
            $accessGroupResultCount < 0
            || $accessGroupResultCount > DashboardPreferenceService::ACCESS_GROUP_PAGE_SIZE
            || $accessGroupResultCount !== count($forms) - 1
        ) {
            throw new InvalidArgumentException('Dashboard access-group result count is inconsistent.');
        }
        if (
            !$accessGroupAdministration
            && (
                $accessGroupResultCount !== 0
                || $accessGroupHasPrevious
                || $accessGroupHasNext
                || $accessGroupBrowseLimit
            )
        ) {
            throw new InvalidArgumentException('Dashboard access-group browser state is not authorized.');
        }
        if ($accessGroupHasPrevious !== ($accessGroupAdministration && $accessGroupQuery->page > 1)) {
            throw new InvalidArgumentException('Dashboard access-group previous-page state is inconsistent.');
        }
        if (
            $accessGroupBrowseLimit
            && ($accessGroupQuery->page !== DashboardPreferenceQuery::MAXIMUM_PAGE || $accessGroupHasNext)
        ) {
            throw new InvalidArgumentException('Dashboard access-group browse-limit state is inconsistent.');
        }
    }
}
