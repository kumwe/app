<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Presentation;

/**
 * Resolves the bounded, URL-addressable concern shown by a security administration workspace.
 *
 * The value object is deliberately presentation-only: it accepts no capability or domain state and cannot
 * authorize a record. Handlers use it to constrain query-string navigation before selecting an already
 * authorized read model, while application services remain the sole mutation and disclosure boundaries.
 *
 * @since  2.0.0
 */
final readonly class SecurityWorkspaceState
{
    /**
     * Business Security concerns and the task modes each one supports.
     *
     * @var    array<string, list<string>>
     * @since  2.0.0
     */
    private const BUSINESS_CONCERNS = [
        'overview' => ['browse'],
        'organizations' => ['browse', 'create'],
        'memberships' => ['browse', 'create', 'edit', 'review'],
        'policies' => ['browse', 'create', 'review', 'history'],
        'approvals' => ['browse', 'review', 'history'],
        'credentials' => ['browse', 'history'],
    ];

    /**
     * Users and Access concerns and the task modes each one supports.
     *
     * @var    array<string, list<string>>
     * @since  2.0.0
     */
    private const ACCESS_CONCERNS = [
        'users' => ['browse', 'create', 'edit', 'review', 'history', 'password', 'unenroll', 'sessions'],
        'groups' => ['browse', 'create', 'review', 'history'],
        'grants' => ['browse', 'create', 'review', 'history'],
        'assignments' => ['browse', 'create', 'review', 'history'],
        'tokens' => ['browse', 'create', 'review', 'history'],
        'events' => ['browse', 'edit', 'review', 'history'],
    ];

    /**
     * Ordered stages in the server-first policy authoring flow.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const POLICY_STEPS = ['scope', 'predicate', 'disclosure', 'review'];

    /**
     * Build one already validated workspace selection.
     *
     * @param  string   $section  Stable concern identifier.
     * @param  string   $mode     Focused task mode within the concern.
     * @param  ?string  $kind     Optional resource kind for a creation or review flow.
     * @param  ?string  $id       Optional scoped record selector.
     * @param  string   $step     Current guided-policy stage.
     *
     * @since  2.0.0
     */
    private function __construct(
        public string $section,
        public string $mode,
        public ?string $kind,
        public ?string $id,
        public string $step,
    ) {
    }

    /**
     * Resolve a Business Security request to one closed concern and task.
     *
     * @param   array<array-key, mixed>  $query  Untrusted PSR-7 request query parameters.
     *
     * @return  self  Safe state whose values belong to the Business Security vocabulary.
     *
     * @since   2.0.0
     */
    public static function business(array $query): self
    {
        return self::resolve($query, self::BUSINESS_CONCERNS, 'overview', true);
    }

    /**
     * Resolve a Users and Access request to one closed concern and task.
     *
     * @param   array<array-key, mixed>  $query  Untrusted PSR-7 request query parameters.
     *
     * @return  self  Safe state whose values belong to the identity workspace vocabulary.
     *
     * @since   2.0.0
     */
    public static function access(array $query): self
    {
        return self::resolve($query, self::ACCESS_CONCERNS, 'users', false);
    }

    /**
     * Return the template-safe scalar representation of this state.
     *
     * @return  array{section: string, mode: string, kind: ?string, id: ?string, step: string}
     *          Closed values suitable for Twig comparisons and link construction.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'section' => $this->section,
            'mode' => $this->mode,
            'kind' => $this->kind,
            'id' => $this->id,
            'step' => $this->step,
        ];
    }

    /**
     * Build the canonical deep link for this state with optional response flags.
     *
     * @param   string                 $path   Absolute application path without a query string.
     * @param   array<string, string>  $extra  Additional bounded response markers such as `saved=1`.
     *
     * @return  string  RFC 3986 encoded route preserving the focused concern and task.
     *
     * @since   2.0.0
     */
    public function url(string $path, array $extra = []): string
    {
        $query = ['section' => $this->section];
        if ($this->mode !== 'browse') {
            $query['mode'] = $this->mode;
        }
        if ($this->kind !== null) {
            $query['kind'] = $this->kind;
        }
        if ($this->id !== null) {
            $query['id'] = $this->id;
        }
        if ($this->isPolicyAuthoringFlow()) {
            $query['step'] = $this->step;
        }

        return $path . '?' . http_build_query($query + $extra, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Build the ordered canonical destinations for the active resource-policy authoring flow.
     *
     * Each destination carries the complete bounded workspace context and an explicit step selector.
     * The fragment provides a useful no-JavaScript landing position, while the scoped enhancement can
     * update the same URL in place so non-secret form input is not discarded between stages.
     *
     * @param   string  $path  Absolute Business Security path without a query string.
     *
     * @return  list<array{id: string, label: string, url: string, current: bool}>  Ordered authoring steps,
     *          or an empty list outside resource-policy creation.
     *
     * @since   2.0.0
     */
    public function policySteps(string $path): array
    {
        if (!$this->isPolicyAuthoringFlow()) {
            return [];
        }

        $labels = [
            'scope' => '1. Scope',
            'predicate' => '2. Predicate',
            'disclosure' => '3. Disclosure',
            'review' => '4. Review',
        ];
        $steps = [];
        foreach ($labels as $step => $label) {
            $state = new self($this->section, $this->mode, $this->kind, null, $step);
            $steps[] = [
                'id' => $step,
                'label' => $label,
                'url' => $state->url($path) . '#policy-step-' . $step,
                'current' => $step === $this->step,
            ];
        }

        return $steps;
    }

    /**
     * Resolve shared scalar fields against one surface-specific concern map.
     *
     * @param   array<array-key, mixed>      $query     Untrusted PSR-7 request query parameters.
     * @param   array<string, list<string>>  $concerns  Allowed concern-to-mode map.
     * @param   string                       $default   Concern used when the request is absent or invalid.
     * @param   bool                         $business  Whether Business Security kind and step rules apply.
     *
     * @return  self  Closed state with invalid optional selectors removed.
     *
     * @since   2.0.0
     */
    private static function resolve(
        array $query,
        array $concerns,
        string $default,
        bool $business,
    ): self {
        $section = self::scalar($query['section'] ?? null);
        if ($section === null || !isset($concerns[$section])) {
            $section = $default;
        }
        $mode = self::scalar($query['mode'] ?? null) ?? 'browse';
        if (!in_array($mode, $concerns[$section], true)) {
            $mode = 'browse';
        }
        $kind = $business ? self::businessKind($section, $mode, $query['kind'] ?? null) : null;
        $id = self::recordId($query['id'] ?? null);
        if (!in_array($mode, ['edit', 'review', 'history', 'password', 'unenroll', 'sessions'], true)) {
            $id = null;
        }
        $step = 'scope';
        if ($business && $section === 'policies' && $mode === 'create' && $kind === 'resource') {
            $candidate = self::scalar($query['step'] ?? null);
            if ($candidate !== null && in_array($candidate, self::POLICY_STEPS, true)) {
                $step = $candidate;
            }
        }

        return new self($section, $mode, $kind, $id, $step);
    }

    /**
     * Select the only resource kinds admitted by Business Security focused flows.
     *
     * @param   string  $section  Current Business Security concern.
     * @param   string  $mode     Current focused task mode.
     * @param   mixed   $value    Untrusted requested kind.
     *
     * @return  ?string  Closed resource kind, or null when this flow has no kind selector.
     *
     * @since   2.0.0
     */
    private static function businessKind(string $section, string $mode, mixed $value): ?string
    {
        $allowed = match ([$section, $mode]) {
            ['organizations', 'create'] => ['organization', 'workspace'],
            ['policies', 'create'], ['policies', 'review'] => ['resource', 'separation'],
            ['credentials', 'history'] => ['step-up', 'token'],
            default => [],
        };
        if ($allowed === []) {
            return null;
        }
        $candidate = self::scalar($value);

        return $candidate !== null && in_array($candidate, $allowed, true) ? $candidate : $allowed[0];
    }

    /**
     * Read a query scalar without accepting arrays, objects, resources, or blank text.
     *
     * @param   mixed  $value  Untrusted query value.
     *
     * @return  ?string  Trimmed non-empty scalar or null.
     *
     * @since   2.0.0
     */
    private static function scalar(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Accept only bounded opaque selectors that can safely round-trip through one deep link.
     *
     * @param   mixed  $value  Untrusted record selector.
     *
     * @return  ?string  Valid selector or null when malformed.
     *
     * @since   2.0.0
     */
    private static function recordId(mixed $value): ?string
    {
        $value = self::scalar($value);

        return $value !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$/D', $value) === 1
            ? $value
            : null;
    }

    /**
     * Whether this state owns the ordered resource-policy authoring stages.
     *
     * @return  bool  True only for the one flow whose step selector is meaningful.
     *
     * @since   2.0.0
     */
    private function isPolicyAuthoringFlow(): bool
    {
        return $this->section === 'policies'
            && $this->mode === 'create'
            && $this->kind === 'resource';
    }
}
