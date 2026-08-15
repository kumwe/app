<?php

declare(strict_types=1);

namespace Kumwe\CMS\Presentation\Application\Dashboard;

use InvalidArgumentException;
use JsonException;
use Kumwe\CMS\InterfaceStandard\SurfaceId;

/**
 * Immutable, bounded semantic dashboard widget rendered only by core-owned templates.
 *
 * Caller-supplied widgets carry translated message identifiers and semantic data but no top-level
 * destination. Workflow widgets are created from an already policy-filtered navigation row through
 * `fromNavigation()`, which is the only production path that places an href in the view model.
 *
 * @since  2.0.0
 */
final readonly class DashboardWidget
{
    /**
     * Aggregate metrics and optional progress or search context.
     *
     * @var    string
     * @since  2.0.0
     */
    public const KIND_SUMMARY = 'summary';

    /**
     * Bounded recent or pending work records.
     *
     * @var    string
     * @since  2.0.0
     */
    public const KIND_ACTIVITY = 'activity';

    /**
     * Current site, organization, workspace, or access context.
     *
     * @var    string
     * @since  2.0.0
     */
    public const KIND_CONTEXT = 'context';

    /**
     * One destination projected from visible administrator or portal navigation.
     *
     * @var    string
     * @since  2.0.0
     */
    public const KIND_WORKFLOW = 'workflow';

    /**
     * Compact single-column card size.
     *
     * @var    string
     * @since  2.0.0
     */
    public const SIZE_SMALL = 'small';

    /**
     * Ordinary dashboard card size.
     *
     * @var    string
     * @since  2.0.0
     */
    public const SIZE_MEDIUM = 'medium';

    /**
     * Prominent card size spanning additional grid space.
     *
     * @var    string
     * @since  2.0.0
     */
    public const SIZE_LARGE = 'large';

    /**
     * Full-row card size for dense activity.
     *
     * @var    string
     * @since  2.0.0
     */
    public const SIZE_WIDE = 'wide';

    /**
     * Closed semantic widget vocabulary.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const KINDS = [
        self::KIND_SUMMARY,
        self::KIND_ACTIVITY,
        self::KIND_CONTEXT,
        self::KIND_WORKFLOW,
    ];

    /**
     * Closed responsive card-size vocabulary.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const SIZES = [
        self::SIZE_SMALL,
        self::SIZE_MEDIUM,
        self::SIZE_LARGE,
        self::SIZE_WIDE,
    ];

    /**
     * Maximum encoded semantic data carried by one widget.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAX_DATA_BYTES = 65_536;

    /**
     * Maximum scalar and collection nodes accepted in one widget payload.
     *
     * @var    int
     * @since  2.0.0
     */
    private const MAX_DATA_NODES = 1_024;

    /**
     * Validated bounded semantic widget data.
     *
     * @var    array<string, mixed>
     * @since  2.0.0
     */
    public array $data;

    /**
     * Root-relative destination copied from filtered navigation, or null for every core data widget.
     *
     * @var    ?string
     * @since  2.0.0
     */
    public ?string $href;

    /**
     * Construct one core semantic widget or an internally projected navigation workflow.
     *
     * Application callers supply summary, activity, or context widgets with `$href` omitted. Dashboard
     * composition refuses caller-supplied workflow widgets, while `fromNavigation()` uses the final
     * parameter only after the shell registries have applied capability, trust, and policy filtering.
     *
     * @param   string                $id          Canonical dotted widget or navigation identifier.
     * @param   string                $kind        One closed widget kind.
     * @param   string                $title       Message identifier, or navigation display text.
     * @param   string                $description Message identifier, or navigation display text.
     * @param   string                $icon        Approved semantic KIS icon name.
     * @param   string                $group       Optional plain-text catalog group.
     * @param   string                $size        Closed responsive card size.
     * @param   array<string, mixed>  $data        Exact per-kind markup-free semantic template contract.
     * @param   bool                  $messageIds  Whether translatable strings are message identifiers.
     * @param   ?string               $href        Filtered navigation destination for a workflow widget only.
     *
     * @throws  InvalidArgumentException  When identity, vocabulary, text, data, or href is unsafe or unbounded.
     *
     * @since   2.0.0
     */
    public function __construct(
        public string $id,
        public string $kind,
        public string $title,
        public string $description,
        public string $icon = 'dashboard',
        public string $group = '',
        public string $size = self::SIZE_MEDIUM,
        array $data = [],
        public bool $messageIds = true,
        ?string $href = null,
    ) {
        SurfaceId::fromString($id);
        if (!in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('A dashboard widget kind is unsupported.');
        }
        if (!in_array($size, self::SIZES, true)) {
            throw new InvalidArgumentException('A dashboard widget size is unsupported.');
        }
        self::assertIconName($icon);
        self::assertPlainText($group, 'group', 120, true);
        if ($messageIds) {
            self::assertMessageId($title, 'title');
            self::assertMessageId($description, 'description');
        } else {
            self::assertPlainText($title, 'title', 120);
            self::assertPlainText($description, 'description', 500);
        }
        if (($kind === self::KIND_WORKFLOW) !== ($href !== null)) {
            throw new InvalidArgumentException(
                'Only a dashboard workflow widget may carry a filtered navigation destination.',
            );
        }
        if ($href !== null) {
            self::assertRootRelativeHref($href);
        }
        if ($data !== [] && array_is_list($data)) {
            throw new InvalidArgumentException('Dashboard widget data must be a semantic object.');
        }

        $nodes = 0;
        self::assertDataNode($data, $nodes);
        try {
            $encoded = json_encode($data, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Dashboard widget data must be JSON-compatible.', 0, $exception);
        }
        if (strlen($encoded) > self::MAX_DATA_BYTES) {
            throw new InvalidArgumentException('Dashboard widget data exceeds sixty-four kibibytes.');
        }
        self::assertSemanticData($kind, $data);
        self::assertDataMessageIds($kind, $data, $messageIds);

        $this->data = $data;
        $this->href = $href;
    }

    /**
     * Project one already capability-, trust-, and policy-filtered navigation row into a workflow widget.
     *
     * The canonical navigation identifier remains the widget identifier, which lets stored dashboard-card
     * and shortcut selections name the same stable contribution. Navigation labels are current display text,
     * not message identifiers, until navigation contributions themselves carry translation metadata.
     *
     * @param   array<string, int|string>  $item  Visible administrator or portal navigation row.
     *
     * @return  self  Safe workflow widget whose only destination is the supplied filtered href.
     *
     * @throws  InvalidArgumentException  When a required navigation field is absent or unsafe.
     *
     * @since   2.0.0
     */
    public static function fromNavigation(array $item): self
    {
        $id = $item['id'] ?? null;
        $label = $item['label'] ?? null;
        $description = $item['description'] ?? null;
        $href = $item['href'] ?? null;
        $icon = $item['icon'] ?? null;
        $group = $item['group'] ?? null;
        if (
            !is_string($id)
            || !is_string($label)
            || !is_string($description)
            || !is_string($href)
            || !is_string($icon)
            || !is_string($group)
        ) {
            throw new InvalidArgumentException('A dashboard navigation workflow is malformed.');
        }

        return new self(
            $id,
            self::KIND_WORKFLOW,
            $label,
            $description,
            $icon,
            $group,
            self::SIZE_SMALL,
            [],
            false,
            $href,
        );
    }

    /**
     * Report whether this model carries a navigation-owned workflow destination.
     *
     * @return  bool  True only for workflow widgets.
     *
     * @since   2.0.0
     */
    public function isWorkflow(): bool
    {
        return $this->kind === self::KIND_WORKFLOW;
    }

    /**
     * Export the strict template contract rendered by core KIS widget partials.
     *
     * @return  array{
     *              id: string,
     *              kind: string,
     *              title: string,
     *              description: string,
     *              icon: string,
     *              group: string,
     *              size: string,
     *              href: ?string,
     *              data: array<string, mixed>,
     *              message_ids: bool
     *          }  Bounded markup-free widget view.
     *
     * @since   2.0.0
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'title' => $this->title,
            'description' => $this->description,
            'icon' => $this->icon,
            'group' => $this->group,
            'size' => $this->size,
            'href' => $this->href,
            'data' => $this->data,
            'message_ids' => $this->messageIds,
        ];
    }

    /**
     * Validate one icon identifier from the closed semantic icon vocabulary.
     *
     * @param   string  $value  Candidate icon identifier.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the value is outside the bounded icon grammar.
     *
     * @since   2.0.0
     */
    private static function assertIconName(string $value): void
    {
        if (
            strlen($value) > 64
            || preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $value) !== 1
        ) {
            throw new InvalidArgumentException('A dashboard widget icon is invalid.');
        }
    }

    /**
     * Validate one lower-case dotted translation identifier.
     *
     * @param   string  $value  Candidate message identifier.
     * @param   string  $field  Field name used in a stable failure message.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the value is not a bounded dotted message identifier.
     *
     * @since   2.0.0
     */
    private static function assertMessageId(string $value, string $field): void
    {
        if (
            strlen($value) > 191
            || !str_contains($value, '.')
            || preg_match('/^[a-z][a-z0-9_]*(?:[.-][a-z0-9][a-z0-9_]*)+$/D', $value) !== 1
        ) {
            throw new InvalidArgumentException(sprintf(
                'A translated dashboard widget %s must be a lower-case dotted message identifier.',
                $field,
            ));
        }
    }

    /**
     * Validate bounded display text without treating it as markup.
     *
     * @param   string  $value       Candidate plain text.
     * @param   string  $field       Field name used in a stable failure message.
     * @param   int     $maximum     Maximum UTF-8 character count.
     * @param   bool    $allowsEmpty Whether an absent catalog group is valid.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When text is empty, overlong, or contains control characters.
     *
     * @since   2.0.0
     */
    private static function assertPlainText(
        string $value,
        string $field,
        int $maximum,
        bool $allowsEmpty = false,
    ): void {
        if (
            (!$allowsEmpty && $value === '')
            || mb_strlen($value) > $maximum
            || preg_match('/[\x00-\x1F\x7F]/u', $value) === 1
        ) {
            throw new InvalidArgumentException(sprintf('A dashboard widget %s must be bounded plain text.', $field));
        }
    }

    /**
     * Validate one root-relative destination already admitted by a navigation registry.
     *
     * @param   string  $href  Candidate filtered navigation destination.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the path could escape into a scheme or protocol-relative URL.
     *
     * @since   2.0.0
     */
    private static function assertRootRelativeHref(string $href): void
    {
        $pathParts = preg_split('/[?#]/', $href, 2);
        $decodedPath = rawurldecode($pathParts === false ? $href : ($pathParts[0] ?? $href));
        if (
            $href === ''
            || strlen($href) > 2_048
            || !str_starts_with($href, '/')
            || str_starts_with($href, '//')
            || str_contains($href, '\\')
            || preg_match('/[\x00-\x20\x7F]/', $href) === 1
            || str_starts_with($decodedPath, '//')
            || str_contains($decodedPath, '\\')
            || preg_match('/[\x00-\x20\x7F<>"\'`]/', $decodedPath) === 1
            || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $decodedPath) === 1
        ) {
            throw new InvalidArgumentException('A dashboard workflow href must be a safe root-relative path.');
        }
    }

    /**
     * Walk and validate the closed scalar/list/object space accepted by core widget renderers.
     *
     * @param   mixed    $value  Current semantic data node.
     * @param   int      $nodes  Running node count, updated in place.
     * @param   int      $depth  Current collection depth.
     * @param   ?string  $key    Parent object key, used to validate safe core destinations.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When data is unbounded, non-portable, or carries an unsafe href.
     *
     * @since   2.0.0
     */
    private static function assertDataNode(mixed $value, int &$nodes, int $depth = 0, ?string $key = null): void
    {
        $nodes++;
        if ($nodes > self::MAX_DATA_NODES || $depth > 10) {
            throw new InvalidArgumentException('Dashboard widget data is too large or deeply nested.');
        }
        if (is_string($value)) {
            self::assertPlainText($value, 'data value', 4_096, true);
            if ($key === 'href' || $key === 'action') {
                self::assertRootRelativeHref($value);
            }
            return;
        }
        if (is_int($value) || is_bool($value) || $value === null) {
            return;
        }
        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new InvalidArgumentException('Dashboard widget numeric data must be finite.');
            }
            return;
        }
        if (!is_array($value) || count($value) > 128) {
            throw new InvalidArgumentException('Dashboard widget data contains an unsupported or unbounded node.');
        }
        $list = array_is_list($value);
        foreach ($value as $childKey => $child) {
            if (
                !$list
                && (!is_string($childKey) || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $childKey) !== 1)
            ) {
                throw new InvalidArgumentException('Dashboard widget data contains an invalid semantic key.');
            }
            self::assertDataNode($child, $nodes, $depth + 1, $list ? null : (string) $childKey);
        }
    }

    /**
     * Validate the exact semantic objects read by the strict core widget template.
     *
     * @param   string                $kind  Closed widget kind.
     * @param   array<string, mixed>  $data  Bounded JSON-compatible semantic data.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a rendered field is missing, mistyped, or unsupported.
     *
     * @since   2.0.0
     */
    private static function assertSemanticData(string $kind, array $data): void
    {
        if ($kind === self::KIND_SUMMARY) {
            self::assertSummaryData($data);
            return;
        }
        if ($kind === self::KIND_ACTIVITY) {
            self::assertActivityData($data);
            return;
        }
        if ($kind === self::KIND_CONTEXT) {
            self::assertContextData($data);
            return;
        }
        if ($kind === self::KIND_WORKFLOW && $data !== []) {
            throw new InvalidArgumentException('A dashboard workflow widget cannot carry semantic data.');
        }
    }

    /**
     * Validate optional search, metric, and progress models.
     *
     * @param   array<string, mixed>  $data  Candidate summary data.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertSummaryData(array $data): void
    {
        self::assertObject($data, ['search', 'metrics', 'progress'], [], 'summary data');
        if (array_key_exists('search', $data)) {
            $search = self::objectValue($data['search'], 'summary search');
            self::assertObject(
                $search,
                ['action', 'label', 'placeholder', 'button'],
                ['action', 'label', 'placeholder', 'button'],
                'summary search',
            );
            self::assertRootRelativeHref(self::stringValue($search['action'], 'summary search action'));
            self::stringValue($search['label'], 'summary search label');
            self::stringValue($search['placeholder'], 'summary search placeholder');
            self::stringValue($search['button'], 'summary search button');
        }
        if (array_key_exists('metrics', $data)) {
            foreach (self::listValue($data['metrics'], 32, 'summary metrics') as $metric) {
                $metric = self::objectValue($metric, 'summary metric');
                self::assertObject(
                    $metric,
                    ['label', 'value', 'tone', 'parameters'],
                    ['label', 'value'],
                    'summary metric',
                );
                self::stringValue($metric['label'], 'summary metric label');
                self::numericValue($metric['value'], 'summary metric value');
                if (array_key_exists('tone', $metric)) {
                    self::assertTone(self::stringValue($metric['tone'], 'summary metric tone'));
                }
                if (array_key_exists('parameters', $metric)) {
                    self::assertParameters($metric['parameters'], 'summary metric parameters');
                }
            }
        }
        if (array_key_exists('progress', $data)) {
            $progress = self::objectValue($data['progress'], 'summary progress');
            self::assertObject(
                $progress,
                ['value', 'label', 'parameters', 'help', 'help_parameters'],
                ['value', 'label'],
                'summary progress',
            );
            $value = self::numericValue($progress['value'], 'summary progress value');
            if ($value < 0 || $value > 100) {
                throw new InvalidArgumentException('A dashboard summary progress value must be from zero to 100.');
            }
            self::stringValue($progress['label'], 'summary progress label');
            if (array_key_exists('parameters', $progress)) {
                self::assertParameters($progress['parameters'], 'summary progress parameters');
            }
            if (array_key_exists('help', $progress)) {
                self::stringValue($progress['help'], 'summary progress help', true);
            }
            if (array_key_exists('help_parameters', $progress)) {
                self::assertParameters($progress['help_parameters'], 'summary progress help parameters');
            }
        }
    }

    /**
     * Validate bounded activity rows, their empty state, and optional destinations.
     *
     * @param   array<string, mixed>  $data  Candidate activity data.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertActivityData(array $data): void
    {
        self::assertObject(
            $data,
            ['items', 'empty_title', 'empty_message', 'action'],
            ['items', 'empty_title', 'empty_message'],
            'activity data',
        );
        self::stringValue($data['empty_title'], 'activity empty title');
        self::stringValue($data['empty_message'], 'activity empty message');
        foreach (self::listValue($data['items'], 32, 'activity items') as $item) {
            $item = self::objectValue($item, 'activity item');
            self::assertObject(
                $item,
                ['title', 'detail', 'status', 'status_tone', 'href', 'action_label'],
                ['title'],
                'activity item',
            );
            self::stringValue($item['title'], 'activity item title');
            if (array_key_exists('detail', $item)) {
                self::stringValue($item['detail'], 'activity item detail', true);
            }
            if (array_key_exists('status', $item)) {
                $status = self::stringValue($item['status'], 'activity item status');
                if (preg_match('/^[a-z][a-z0-9_-]{0,39}$/D', $status) !== 1) {
                    throw new InvalidArgumentException('A dashboard activity status is invalid.');
                }
            }
            if (array_key_exists('status_tone', $item)) {
                if (!array_key_exists('status', $item)) {
                    throw new InvalidArgumentException('A dashboard activity status tone requires a status.');
                }
                self::assertTone(self::stringValue($item['status_tone'], 'activity status tone'));
            }
            if (array_key_exists('href', $item)) {
                self::assertRootRelativeHref(self::stringValue($item['href'], 'activity item href'));
                if (!array_key_exists('action_label', $item)) {
                    throw new InvalidArgumentException('A dashboard activity href requires an action label.');
                }
            }
            if (array_key_exists('action_label', $item)) {
                if (!array_key_exists('href', $item)) {
                    throw new InvalidArgumentException('A dashboard activity action label requires an href.');
                }
                self::stringValue($item['action_label'], 'activity item action label');
            }
        }
        if (array_key_exists('action', $data)) {
            $action = self::objectValue($data['action'], 'activity action');
            self::assertObject($action, ['href', 'label'], ['href', 'label'], 'activity action');
            self::assertRootRelativeHref(self::stringValue($action['href'], 'activity action href'));
            self::stringValue($action['label'], 'activity action label');
        }
    }

    /**
     * Validate context rows whose label and scalar value are read unconditionally by Twig.
     *
     * @param   array<string, mixed>  $data  Candidate context data.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertContextData(array $data): void
    {
        self::assertObject($data, ['items'], ['items'], 'context data');
        foreach (self::listValue($data['items'], 32, 'context items') as $item) {
            $item = self::objectValue($item, 'context item');
            self::assertObject($item, ['label', 'value'], ['label', 'value'], 'context item');
            self::stringValue($item['label'], 'context item label');
            self::displayValue($item['value'], 'context item value');
        }
    }

    /**
     * Require one associative semantic object with a closed set of keys.
     *
     * @param   array<array-key, mixed>  $value     Candidate object.
     * @param   list<string>             $allowed   Complete supported keys.
     * @param   list<string>             $required  Keys read unconditionally by Twig.
     * @param   string                   $field     Stable field name for failures.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertObject(array $value, array $allowed, array $required, string $field): void
    {
        if ($value !== [] && array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('Dashboard widget %s must be a semantic object.', $field));
        }
        foreach ($value as $key => $_value) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Dashboard widget %s contains an unsupported field.',
                    $field,
                ));
            }
        }
        foreach ($required as $key) {
            if (!array_key_exists($key, $value)) {
                throw new InvalidArgumentException(sprintf('Dashboard widget %s is missing a required field.', $field));
            }
        }
    }

    /**
     * Narrow one mixed value to an associative semantic object.
     *
     * @param   mixed   $value  Candidate nested object.
     * @param   string  $field  Stable field name for failures.
     *
     * @return  array<string, mixed>  Proven semantic object.
     *
     * @since   2.0.0
     */
    private static function objectValue(mixed $value, string $field): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException(sprintf('Dashboard widget %s must be a semantic object.', $field));
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * Narrow one mixed value to a bounded list.
     *
     * @param   mixed   $value    Candidate list.
     * @param   int     $maximum  Maximum accepted items.
     * @param   string  $field    Stable field name for failures.
     *
     * @return  list<mixed>  Proven bounded list.
     *
     * @since   2.0.0
     */
    private static function listValue(mixed $value, int $maximum, string $field): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $maximum) {
            throw new InvalidArgumentException(sprintf('Dashboard widget %s must be a bounded list.', $field));
        }

        return $value;
    }

    /**
     * Narrow one mixed field to bounded plain text.
     *
     * @param   mixed   $value        Candidate text.
     * @param   string  $field        Stable field name for failures.
     * @param   bool    $allowsEmpty  Whether an empty string remains meaningful.
     *
     * @return  string  Proven bounded plain text.
     *
     * @since   2.0.0
     */
    private static function stringValue(mixed $value, string $field, bool $allowsEmpty = false): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('Dashboard widget %s must be text.', $field));
        }
        self::assertPlainText($value, $field, 4_096, $allowsEmpty);

        return $value;
    }

    /**
     * Narrow one mixed field to a finite integer or float.
     *
     * @param   mixed   $value  Candidate numeric value.
     * @param   string  $field  Stable field name for failures.
     *
     * @return  int|float  Proven finite number.
     *
     * @since   2.0.0
     */
    private static function numericValue(mixed $value, string $field): int|float
    {
        if ((!is_int($value) && !is_float($value)) || (is_float($value) && !is_finite($value))) {
            throw new InvalidArgumentException(sprintf('Dashboard widget %s must be a finite number.', $field));
        }

        return $value;
    }

    /**
     * Validate one scalar context value safe for direct escaped display.
     *
     * @param   mixed   $value  Candidate display value.
     * @param   string  $field  Stable field name for failures.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function displayValue(mixed $value, string $field): void
    {
        if (is_string($value)) {
            self::assertPlainText($value, $field, 4_096, true);
            return;
        }
        self::numericValue($value, $field);
    }

    /**
     * Admit only status tones for which the shared stylesheet has semantics.
     *
     * @param   string  $tone  Candidate tone token.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertTone(string $tone): void
    {
        if (!in_array($tone, ['neutral', 'success', 'warning', 'danger'], true)) {
            throw new InvalidArgumentException('A dashboard widget tone is unsupported.');
        }
    }

    /**
     * Validate a bounded translation-parameter object of scalar values.
     *
     * @param   mixed   $value  Candidate parameter object.
     * @param   string  $field  Stable field name for failures.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertParameters(mixed $value, string $field): void
    {
        $parameters = self::objectValue($value, $field);
        if (count($parameters) > 32) {
            throw new InvalidArgumentException(sprintf('Dashboard widget %s is unbounded.', $field));
        }
        foreach ($parameters as $key => $parameter) {
            if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $key) !== 1) {
                throw new InvalidArgumentException(sprintf('Dashboard widget %s has an invalid key.', $field));
            }
            if (is_string($parameter)) {
                self::assertPlainText($parameter, $field, 1_024, true);
                continue;
            }
            self::numericValue($parameter, $field);
        }
    }

    /**
     * Validate translation-bearing labels in each core widget's documented semantic data shape.
     *
     * Dynamic activity entry fields are deliberately not interpreted as message identifiers; they are
     * caller data and remain safely auto-escaped. Only the static labels owned by the widget contract are
     * checked here.
     *
     * @param   string                $kind        Closed widget kind.
     * @param   array<string, mixed>  $data        Already bounded semantic data.
     * @param   bool                  $messageIds  Whether metric labels are translated identifiers.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When a static label is not a message identifier.
     *
     * @since   2.0.0
     */
    private static function assertDataMessageIds(string $kind, array $data, bool $messageIds): void
    {
        if ($kind === self::KIND_SUMMARY) {
            $metrics = $data['metrics'] ?? [];
            if ($messageIds && is_array($metrics)) {
                foreach ($metrics as $metric) {
                    if (is_array($metric) && array_key_exists('label', $metric)) {
                        if (!is_string($metric['label'])) {
                            throw new InvalidArgumentException(
                                'A translated dashboard widget metric label must be a message identifier.',
                            );
                        }
                        self::assertMessageId($metric['label'], 'metric label');
                    }
                }
            }
            self::assertNestedMessageId($data, 'progress', 'label', 'progress label');
            self::assertNestedMessageId($data, 'search', 'label', 'search label');
            self::assertNestedMessageId($data, 'search', 'placeholder', 'search placeholder');
            self::assertNestedMessageId($data, 'search', 'button', 'search button');
            self::assertNestedMessageId($data, 'progress', 'help', 'progress help');
        }
        if ($kind === self::KIND_ACTIVITY) {
            self::assertOptionalMessageId($data, 'empty_title', 'empty title');
            self::assertOptionalMessageId($data, 'empty_message', 'empty message');
            self::assertNestedMessageId($data, 'action', 'label', 'activity action label');
            $items = $data['items'] ?? [];
            if (is_array($items)) {
                foreach ($items as $item) {
                    if (!is_array($item) || !array_key_exists('action_label', $item)) {
                        continue;
                    }
                    if (!is_string($item['action_label'])) {
                        throw new InvalidArgumentException(
                            'A translated dashboard widget activity action label must be a message identifier.',
                        );
                    }
                    self::assertMessageId($item['action_label'], 'activity item action label');
                }
            }
        }
        if ($kind === self::KIND_CONTEXT) {
            $items = $data['items'] ?? [];
            if (is_array($items)) {
                foreach ($items as $item) {
                    if (is_array($item) && array_key_exists('label', $item)) {
                        if (!is_string($item['label'])) {
                            throw new InvalidArgumentException(
                                'A translated dashboard widget context label must be a message identifier.',
                            );
                        }
                        self::assertMessageId($item['label'], 'context label');
                    }
                }
            }
        }
    }

    /**
     * Validate one optional top-level message identifier.
     *
     * @param   array<string, mixed>  $data   Semantic widget data.
     * @param   string                $key    Optional message-bearing key.
     * @param   string                $field  Human field name for failures.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the present field is not a message identifier.
     *
     * @since   2.0.0
     */
    private static function assertOptionalMessageId(array $data, string $key, string $field): void
    {
        if (!array_key_exists($key, $data)) {
            return;
        }
        if (!is_string($data[$key])) {
            throw new InvalidArgumentException(sprintf(
                'A translated dashboard widget %s must be a message identifier.',
                $field,
            ));
        }
        self::assertMessageId($data[$key], $field);
    }

    /**
     * Validate one optional message identifier inside a documented nested object.
     *
     * @param   array<string, mixed>  $data       Semantic widget data.
     * @param   string                $parentKey  Optional nested object key.
     * @param   string                $key        Optional message-bearing child key.
     * @param   string                $field      Human field name for failures.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the present field is not a message identifier.
     *
     * @since   2.0.0
     */
    private static function assertNestedMessageId(
        array $data,
        string $parentKey,
        string $key,
        string $field,
    ): void {
        $parent = $data[$parentKey] ?? null;
        if (!is_array($parent) || !array_key_exists($key, $parent)) {
            return;
        }
        if (!is_string($parent[$key])) {
            throw new InvalidArgumentException(sprintf(
                'A translated dashboard widget %s must be a message identifier.',
                $field,
            ));
        }
        self::assertMessageId($parent[$key], $field);
    }
}
