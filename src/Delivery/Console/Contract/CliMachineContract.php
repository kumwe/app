<?php

declare(strict_types=1);

namespace Kumwe\App\Delivery\Console\Contract;

use InvalidArgumentException;
use JsonException;
use LogicException;

/**
 * Validated, executable view of one retained CLI machine-contract generation.
 *
 * The document is data rather than annotations scattered through command implementations. That gives
 * release tooling, SDK authors and the dispatcher the same closed view of names, actions, inputs,
 * streams and exits. Construction validates the entire document, including its surface digest, before
 * a single invocation may use it; runtime checks then reject drift before a command can perform work.
 *
 * @since  2.0.0
 */
final class CliMachineContract
{
    /**
     * Stable document format identifier understood by this implementation.
     *
     * @var    string
     * @since  2.0.0
     */
    public const FORMAT = 'kumwe-cli-machine-contract-v1';

    /**
     * Input classifiers with invocation validation defined by this generation.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const INPUT_TYPES = [
        'absolute-path',
        'base64',
        'boolean',
        'csv',
        'enum',
        'flag',
        'hex-digest',
        'identifier',
        'json-object',
        'json-object-list',
        'non-negative-integer',
        'nullable-identifier',
        'nullable-string',
        'output-path',
        'positive-integer',
        'protected-json-file',
        'protected-json-list-file',
        'secret-file',
        'string',
        'timestamp',
    ];

    /**
     * Closed stream formats a command may promise.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const OUTPUT_MODES = [
        'json-document',
        'json-lines',
        'json-or-text-lines',
        'none',
        'secret-lines',
        'text-lines',
    ];

    /**
     * Closed effect classification retained for every command action.
     *
     * `read` observes application state, `local-write` changes only an operator-selected local artifact or
     * reconciles local runtime files, `mutate` performs a bounded ordinary application mutation, and
     * `high-impact` covers credentials, authorization, trust, installation/schema lifecycle, recovery, broad
     * execution surfaces, or destructive operations. The value is metadata for orchestration and review;
     * authorization and application invariants remain the enforcement boundary.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    private const RISK_CLASSES = [
        'read',
        'local-write',
        'mutate',
        'high-impact',
    ];

    /**
     * Validated commands indexed by their dispatcher name.
     *
     * @var    array<string, array<string, mixed>>
     * @since  2.0.0
     */
    private array $commands = [];

    /**
     * Retain the validated document and build its name index.
     *
     * @param  array<string, mixed>  $document  Fully validated machine document.
     *
     * @since  2.0.0
     */
    private function __construct(private readonly array $document)
    {
        foreach (self::listValue($document, 'commands', 'contract') as $command) {
            if (!is_array($command) || array_is_list($command)) {
                throw new LogicException('Every CLI command definition must be an object.');
            }
            /** @var array<string, mixed> $command */
            $this->commands[self::stringValue($command, 'name', 'command')] = $command;
        }
    }

    /**
     * Decode and validate one complete JSON contract.
     *
     * @param   string  $json  UTF-8 JSON document to validate.
     *
     * @return  self  Executable contract backed by the decoded document.
     *
     * @throws  JsonException  When the JSON is malformed or too deeply nested.
     * @throws  LogicException  When any contract invariant is broken.
     *
     * @since   2.0.0
     */
    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new LogicException('The CLI machine contract must be a JSON object.');
        }
        /** @var array<string, mixed> $decoded */
        self::validateDocument($decoded);

        return new self($decoded);
    }

    /**
     * Return the retained compatibility generation.
     *
     * @return  int  Positive monotonically increasing generation.
     *
     * @since   2.0.0
     */
    public function generation(): int
    {
        return self::integerValue($this->document, 'generation', 'contract');
    }

    /**
     * Return the declared surface digest, including its algorithm prefix.
     *
     * @return  string  `sha256:` followed by the lowercase command-surface digest.
     *
     * @since   2.0.0
     */
    public function digest(): string
    {
        return self::stringValue($this->document, 'surface_digest', 'contract');
    }

    /**
     * List every declared command in deterministic lexical order.
     *
     * @return  list<string>  Stable command names.
     *
     * @since   2.0.0
     */
    public function commandNames(): array
    {
        $names = array_keys($this->commands);
        sort($names);

        return $names;
    }

    /**
     * Return the complete retained action-risk vocabulary in severity order.
     *
     * @return  list<string>  Closed risk classifiers from observation through high impact.
     *
     * @since   2.0.0
     */
    public function riskClasses(): array
    {
        return self::RISK_CLASSES;
    }

    /**
     * Resolve retained effect metadata for one action, defaulting exactly as invocation validation does.
     *
     * This does not authorize an invocation. Consumers may use it to require confirmation, isolate execution,
     * or present an operator warning before handing the same request to the dispatcher.
     *
     * @param   string   $commandName  Declared command name.
     * @param   ?string  $actionName   Declared action, or null for the command's retained default.
     *
     * @return  string  One member of {@see self::RISK_CLASSES}.
     *
     * @throws  InvalidArgumentException  When the action is absent or unsupported.
     * @throws  LogicException  When the command is undeclared or has no retained default.
     *
     * @since   2.0.0
     */
    public function actionRisk(string $commandName, ?string $actionName = null): string
    {
        $command = $this->commands[$commandName] ?? null;
        if ($command === null) {
            throw new LogicException(sprintf('Registered command "%s" has no CLI contract.', $commandName));
        }
        if ($actionName === null) {
            $default = $command['default_action'] ?? null;
            if (!is_string($default)) {
                throw new LogicException(sprintf('Command "%s" has no default action.', $commandName));
            }
            $actionName = $default;
        }
        $risk = self::stringValue($this->action($command, $actionName), 'risk', 'action ' . $actionName);
        if (!in_array($risk, self::RISK_CLASSES, true)) {
            throw new LogicException(sprintf('Action "%s" has unknown risk class "%s".', $actionName, $risk));
        }

        return $risk;
    }

    /**
     * Validate one command argument vector before it reaches application code.
     *
     * @param   string        $commandName  Registered command being dispatched.
     * @param   list<string>  $arguments    Arguments following the command name.
     *
     * @return  list<string>  Validated arguments normalized for the live command implementation.
     *
     * @throws  InvalidArgumentException  When the action, positional or option vector violates the
     *          retained contract.
     * @throws  LogicException  When a registered command has no declared contract.
     *
     * @since   2.0.0
     */
    public function validateInvocation(string $commandName, array $arguments): array
    {
        $command = $this->commands[$commandName] ?? null;
        if ($command === null) {
            throw new LogicException(sprintf('Registered command "%s" has no CLI contract.', $commandName));
        }

        $actionArgument = self::booleanValue($command, 'action_argument', $commandName);
        $defaultAction = $command['default_action'] ?? null;
        if ($defaultAction !== null && !is_string($defaultAction)) {
            throw new LogicException(sprintf('Command "%s" has an invalid default action.', $commandName));
        }
        $executionArguments = $arguments;
        $actionName = is_string($defaultAction) ? $defaultAction : null;
        if ($actionArgument && isset($arguments[0]) && !str_starts_with($arguments[0], '--')) {
            $actionName = array_shift($arguments);
        } elseif ($actionArgument && is_string($defaultAction)) {
            array_unshift($executionArguments, $defaultAction);
        }
        if ($actionName === null) {
            throw new InvalidArgumentException(sprintf('Command "%s" requires an action.', $commandName));
        }

        $action = $this->action($command, $actionName);
        $positionals = [];
        /** @var array<string, string|true> $options */
        $options = [];
        $optionsStarted = false;
        $optionDefinitions = $this->optionIndex($command);

        foreach ($arguments as $argument) {
            if (!str_starts_with($argument, '--')) {
                if ($optionsStarted) {
                    throw new InvalidArgumentException('Positional arguments must precede command options.');
                }
                $positionals[] = $argument;
                continue;
            }

            $optionsStarted = true;
            if (preg_match('/^--([a-z][a-z0-9-]*)=(.*)$/D', $argument, $matches) === 1) {
                $optionName = $matches[1];
                $optionValue = $matches[2];
            } elseif (preg_match('/^--([a-z][a-z0-9-]*)$/D', $argument, $matches) === 1) {
                $optionName = $matches[1];
                $optionValue = true;
            } else {
                throw new InvalidArgumentException('Command options must use --name=value or a declared flag.');
            }
            if (array_key_exists($optionName, $options)) {
                throw new InvalidArgumentException(sprintf('The --%s option is duplicated.', $optionName));
            }
            $definition = $optionDefinitions[$optionName] ?? null;
            if ($definition === null) {
                throw new InvalidArgumentException(sprintf('The --%s option is unknown.', $optionName));
            }
            self::assertInputValue($definition, $optionValue, '--' . $optionName);
            $options[$optionName] = $optionValue;
        }

        $allowed = self::stringList($action, 'allowed_options', 'action ' . $actionName);
        foreach (array_keys($options) as $optionName) {
            if (!in_array($optionName, $allowed, true)) {
                throw new InvalidArgumentException(sprintf(
                    'The --%s option is not valid for action "%s".',
                    $optionName,
                    $actionName,
                ));
            }
        }
        foreach (self::stringList($action, 'required_options', 'action ' . $actionName) as $required) {
            if (!array_key_exists($required, $options)) {
                throw new InvalidArgumentException(sprintf('The --%s option is required.', $required));
            }
        }

        foreach (self::listValue($action, 'one_of_options', 'action ' . $actionName) as $group) {
            $group = self::referenceGroup($group, $actionName, 'one-of');
            $present = array_filter(
                $group,
                static fn (string $name): bool => array_key_exists($name, $options),
            );
            if ($present === []) {
                throw new InvalidArgumentException(sprintf(
                    'Action "%s" requires one option from: %s.',
                    $actionName,
                    implode(', ', $group),
                ));
            }
        }
        foreach (self::listValue($action, 'mutually_exclusive_options', 'action ' . $actionName) as $group) {
            $group = self::referenceGroup($group, $actionName, 'exclusion');
            $present = array_filter(
                $group,
                static fn (string $name): bool => array_key_exists($name, $options),
            );
            if (count($present) > 1) {
                throw new InvalidArgumentException(sprintf(
                    'Action "%s" received mutually exclusive options.',
                    $actionName,
                ));
            }
        }
        foreach (self::listValue($action, 'conditional_requirements', 'action ' . $actionName) as $condition) {
            if (!is_array($condition) || array_is_list($condition)) {
                throw new LogicException(sprintf('Action "%s" has an invalid conditional requirement.', $actionName));
            }
            /** @var array<string, mixed> $condition */
            if (!$this->conditionMatches($condition, $options)) {
                continue;
            }
            foreach (self::stringList($condition, 'require', 'conditional requirement') as $required) {
                if (!array_key_exists($required, $options)) {
                    throw new InvalidArgumentException(sprintf(
                        'The --%s option is conditionally required.',
                        $required,
                    ));
                }
            }
        }

        $positionalDefinitions = self::listValue($action, 'positionals', 'action ' . $actionName);
        if (count($positionals) > count($positionalDefinitions)) {
            throw new InvalidArgumentException(sprintf(
                'Action "%s" received too many positional arguments.',
                $actionName,
            ));
        }
        foreach ($positionalDefinitions as $index => $definition) {
            if (!is_array($definition) || array_is_list($definition)) {
                throw new LogicException(sprintf('Action "%s" has an invalid positional.', $actionName));
            }
            /** @var array<string, mixed> $definition */
            $required = self::booleanValue($definition, 'required', 'positional');
            if (!array_key_exists($index, $positionals)) {
                if ($required) {
                    throw new InvalidArgumentException(sprintf(
                        'The %s positional argument is required.',
                        self::stringValue($definition, 'name', 'positional'),
                    ));
                }
                continue;
            }
            self::assertInputValue(
                $definition,
                $positionals[$index],
                self::stringValue($definition, 'name', 'positional'),
            );
        }

        return $executionArguments;
    }

    /**
     * Assert that a command returned a status declared by its contract.
     *
     * @param   string  $commandName  Command that completed.
     * @param   int     $exitCode     Status returned by the implementation.
     *
     * @return  void
     *
     * @throws  LogicException  When the command is undeclared or returns an undeclared status.
     *
     * @since   2.0.0
     */
    public function assertExitCode(string $commandName, int $exitCode): void
    {
        $command = $this->commands[$commandName] ?? null;
        if ($command === null) {
            throw new LogicException(sprintf('Registered command "%s" has no CLI contract.', $commandName));
        }
        $exitCodes = self::exitCodeMap($command, $commandName);
        if (!array_key_exists((string) $exitCode, $exitCodes) && !array_key_exists($exitCode, $exitCodes)) {
            throw new LogicException(sprintf(
                'Command "%s" returned undeclared exit code %d.',
                $commandName,
                $exitCode,
            ));
        }
    }

    /**
     * Compute the digest over the ordered commands array exactly as the artifact generator does.
     *
     * @param   list<mixed>  $commands  Decoded command definitions in retained order.
     *
     * @return  string  Algorithm-prefixed lowercase digest.
     *
     * @throws  JsonException  When the surface cannot be encoded.
     *
     * @since   2.0.0
     */
    public static function surfaceDigest(array $commands): string
    {
        $normalized = [];
        foreach ($commands as $command) {
            if (is_array($command) && isset($command['exit_codes']) && is_array($command['exit_codes'])) {
                // Numeric JSON object keys decode as integer PHP array keys. Cast the map back to an
                // object so `[0, 1]` can never be confused with the declared `{"0": ..., "1": ...}`.
                $command['exit_codes'] = (object) $command['exit_codes'];
            }
            $normalized[] = $command;
        }

        return 'sha256:' . hash('sha256', json_encode(
            $normalized,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * Validate the top-level document and every nested command definition.
     *
     * @param   array<string, mixed>  $document  Decoded contract document.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function validateDocument(array $document): void
    {
        self::assertKeys(
            $document,
            [
                'format',
                'generation',
                'status',
                'surface_digest',
                'dispatcher',
                'input_types',
                'output_modes',
                'risk_classes',
                'commands',
            ],
            'contract',
        );
        if (self::stringValue($document, 'format', 'contract') !== self::FORMAT) {
            throw new LogicException('The CLI machine contract format is unsupported.');
        }
        if (self::integerValue($document, 'generation', 'contract') < 1) {
            throw new LogicException('The CLI machine contract generation must be positive.');
        }
        if (self::stringValue($document, 'status', 'contract') !== 'retained') {
            throw new LogicException('The CLI machine contract generation must be retained.');
        }
        if (self::stringList($document, 'input_types', 'contract') !== self::INPUT_TYPES) {
            throw new LogicException('The CLI machine contract input classifier set drifted.');
        }
        if (self::stringList($document, 'output_modes', 'contract') !== self::OUTPUT_MODES) {
            throw new LogicException('The CLI machine contract output mode set drifted.');
        }
        if (self::stringList($document, 'risk_classes', 'contract') !== self::RISK_CLASSES) {
            throw new LogicException('The CLI machine contract risk classifier set drifted.');
        }
        $dispatcher = self::objectValue($document, 'dispatcher', 'contract');
        self::assertKeys($dispatcher, ['list_exit', 'unknown_command_exit', 'invalid_invocation_exit'], 'dispatcher');
        foreach (['list_exit', 'unknown_command_exit', 'invalid_invocation_exit'] as $key) {
            $exit = self::integerValue($dispatcher, $key, 'dispatcher');
            if ($exit < 0 || $exit > 255) {
                throw new LogicException(sprintf('Dispatcher exit "%s" is outside the portable range.', $key));
            }
        }

        $commands = self::listValue($document, 'commands', 'contract');
        if ($commands === []) {
            throw new LogicException('The CLI machine contract declares no commands.');
        }
        $names = [];
        foreach ($commands as $command) {
            if (!is_array($command) || array_is_list($command)) {
                throw new LogicException('Every CLI command definition must be an object.');
            }
            /** @var array<string, mixed> $command */
            self::validateCommand($command);
            $name = self::stringValue($command, 'name', 'command');
            if (isset($names[$name])) {
                throw new LogicException(sprintf('CLI command name "%s" is duplicated.', $name));
            }
            $names[$name] = true;
        }

        $declaredDigest = self::stringValue($document, 'surface_digest', 'contract');
        if (!hash_equals(self::surfaceDigest($commands), $declaredDigest)) {
            throw new LogicException('The CLI machine contract surface digest does not match its commands.');
        }
    }

    /**
     * Validate one command definition and every action it exposes.
     *
     * @param   array<string, mixed>  $command  Decoded command object.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function validateCommand(array $command): void
    {
        self::assertKeys(
            $command,
            ['name', 'action_argument', 'default_action', 'options', 'output', 'exit_codes', 'actions'],
            'command',
        );
        $name = self::stringValue($command, 'name', 'command');
        if (preg_match('/^[a-z][a-z0-9-]*(?::[a-z][a-z0-9-]*)*$/D', $name) !== 1) {
            throw new LogicException(sprintf('CLI command name "%s" is invalid.', $name));
        }
        self::booleanValue($command, 'action_argument', $name);

        $optionNames = [];
        foreach (self::listValue($command, 'options', $name) as $option) {
            if (!is_array($option) || array_is_list($option)) {
                throw new LogicException(sprintf('Command "%s" has an invalid option definition.', $name));
            }
            /** @var array<string, mixed> $option */
            self::validateInputDefinition($option, false, $name);
            $optionName = self::stringValue($option, 'name', $name . ' option');
            if (isset($optionNames[$optionName])) {
                throw new LogicException(sprintf('Command "%s" duplicates option "%s".', $name, $optionName));
            }
            $optionNames[$optionName] = true;
        }

        $output = self::objectValue($command, 'output', $name);
        self::assertKeys($output, ['stdout', 'stderr'], $name . ' output');
        foreach (['stdout', 'stderr'] as $stream) {
            $mode = self::stringValue($output, $stream, $name . ' output');
            if (!in_array($mode, self::OUTPUT_MODES, true)) {
                throw new LogicException(sprintf('Command "%s" has invalid %s mode "%s".', $name, $stream, $mode));
            }
        }

        $exitCodes = self::exitCodeMap($command, $name);
        if ($exitCodes === []) {
            throw new LogicException(sprintf('Command "%s" declares no exit codes.', $name));
        }
        foreach ($exitCodes as $code => $meaning) {
            $integer = is_int($code) ? $code : (ctype_digit($code) ? (int) $code : -1);
            if ($integer < 0 || $integer > 255 || !is_string($meaning) || trim($meaning) === '') {
                throw new LogicException(sprintf('Command "%s" has an invalid exit declaration.', $name));
            }
        }

        $actionNames = [];
        foreach (self::listValue($command, 'actions', $name) as $action) {
            if (!is_array($action) || array_is_list($action)) {
                throw new LogicException(sprintf('Command "%s" has an invalid action definition.', $name));
            }
            /** @var array<string, mixed> $action */
            self::validateAction($name, $action, $optionNames);
            $actionName = self::stringValue($action, 'name', $name . ' action');
            if (isset($actionNames[$actionName])) {
                throw new LogicException(sprintf('Command "%s" duplicates action "%s".', $name, $actionName));
            }
            $actionNames[$actionName] = true;
        }
        if ($actionNames === []) {
            throw new LogicException(sprintf('Command "%s" declares no actions.', $name));
        }
        $default = $command['default_action'] ?? null;
        if ($default !== null && (!is_string($default) || !isset($actionNames[$default]))) {
            throw new LogicException(sprintf('Command "%s" has an undeclared default action.', $name));
        }
        if (!self::booleanValue($command, 'action_argument', $name) && $default === null) {
            throw new LogicException(sprintf('Command "%s" requires a default action.', $name));
        }
    }

    /**
     * Validate one action and all of its option references.
     *
     * @param   string                $commandName  Owning command.
     * @param   array<string, mixed>  $action       Decoded action object.
     * @param   array<string, bool>   $options      Declared option-name set.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function validateAction(string $commandName, array $action, array $options): void
    {
        self::assertKeys($action, [
            'name',
            'risk',
            'positionals',
            'allowed_options',
            'required_options',
            'one_of_options',
            'mutually_exclusive_options',
            'conditional_requirements',
        ], $commandName . ' action');
        $actionName = self::stringValue($action, 'name', $commandName . ' action');
        if (preg_match('/^[a-z][a-z0-9-]*$/D', $actionName) !== 1) {
            throw new LogicException(sprintf('Command "%s" has invalid action "%s".', $commandName, $actionName));
        }
        $risk = self::stringValue($action, 'risk', $commandName . ' action ' . $actionName);
        if (!in_array($risk, self::RISK_CLASSES, true)) {
            throw new LogicException(sprintf('Action "%s" has unknown risk class "%s".', $actionName, $risk));
        }
        $positionalNames = [];
        $optionalSeen = false;
        foreach (self::listValue($action, 'positionals', $actionName) as $positional) {
            if (!is_array($positional) || array_is_list($positional)) {
                throw new LogicException(sprintf('Action "%s" has an invalid positional definition.', $actionName));
            }
            /** @var array<string, mixed> $positional */
            self::validateInputDefinition($positional, true, $actionName);
            $positionalName = self::stringValue($positional, 'name', $actionName . ' positional');
            if (isset($positionalNames[$positionalName])) {
                throw new LogicException(sprintf(
                    'Action "%s" duplicates positional "%s".',
                    $actionName,
                    $positionalName,
                ));
            }
            $required = self::booleanValue($positional, 'required', $actionName . ' positional');
            if ($required && $optionalSeen) {
                throw new LogicException(sprintf(
                    'Action "%s" places a required positional after an optional one.',
                    $actionName,
                ));
            }
            $optionalSeen = $optionalSeen || !$required;
            $positionalNames[$positionalName] = true;
        }

        $allowed = self::stringList($action, 'allowed_options', $actionName);
        self::assertUniqueReferences($allowed, $options, $commandName, $actionName);
        $required = self::stringList($action, 'required_options', $actionName);
        self::assertUniqueReferences($required, array_fill_keys($allowed, true), $commandName, $actionName);
        foreach (['one_of_options', 'mutually_exclusive_options'] as $groupKey) {
            foreach (self::listValue($action, $groupKey, $actionName) as $group) {
                if (!is_array($group) || !array_is_list($group) || count($group) < 2) {
                    throw new LogicException(sprintf('Action "%s" has an invalid %s group.', $actionName, $groupKey));
                }
                /** @var list<mixed> $group */
                $references = [];
                foreach ($group as $reference) {
                    if (!is_string($reference)) {
                        throw new LogicException(sprintf(
                            'Action "%s" has a non-string option reference.',
                            $actionName,
                        ));
                    }
                    $references[] = $reference;
                }
                self::assertUniqueReferences(
                    $references,
                    array_fill_keys($allowed, true),
                    $commandName,
                    $actionName,
                );
            }
        }
        foreach (self::listValue($action, 'conditional_requirements', $actionName) as $condition) {
            if (!is_array($condition) || array_is_list($condition)) {
                throw new LogicException(sprintf('Action "%s" has an invalid conditional requirement.', $actionName));
            }
            /** @var array<string, mixed> $condition */
            self::assertKeys($condition, ['option', 'operator', 'value', 'require'], $actionName . ' condition');
            $operator = self::stringValue($condition, 'operator', $actionName . ' condition');
            if (!in_array($operator, ['equals', 'present'], true)) {
                throw new LogicException(sprintf(
                    'Action "%s" has unsupported condition operator "%s".',
                    $actionName,
                    $operator,
                ));
            }
            $conditionOption = self::stringValue($condition, 'option', $actionName . ' condition');
            self::assertUniqueReferences(
                [$conditionOption],
                array_fill_keys($allowed, true),
                $commandName,
                $actionName,
            );
            $conditionValue = $condition['value'] ?? null;
            if ($operator === 'equals' && !is_string($conditionValue)) {
                throw new LogicException(sprintf('Action "%s" has a condition without a string value.', $actionName));
            }
            if ($operator === 'present' && $conditionValue !== null) {
                throw new LogicException(sprintf('Action "%s" has a present condition with a value.', $actionName));
            }
            self::assertUniqueReferences(
                self::stringList($condition, 'require', $actionName . ' condition'),
                array_fill_keys($allowed, true),
                $commandName,
                $actionName,
            );
        }
    }

    /**
     * Validate one option or positional definition.
     *
     * @param   array<string, mixed>  $definition  Decoded input definition.
     * @param   bool                  $positional  Whether `required` must be declared.
     * @param   string                $owner       Owning command or action for diagnostics.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function validateInputDefinition(array $definition, bool $positional, string $owner): void
    {
        $keys = $positional ? ['name', 'type', 'required', 'enum'] : ['name', 'type', 'enum'];
        self::assertKeys($definition, $keys, $owner . ' input');
        $name = self::stringValue($definition, 'name', $owner . ' input');
        if (preg_match('/^[a-z][a-z0-9-]*$/D', $name) !== 1) {
            throw new LogicException(sprintf('%s input name "%s" is invalid.', $owner, $name));
        }
        $type = self::stringValue($definition, 'type', $owner . ' input');
        if (!in_array($type, self::INPUT_TYPES, true)) {
            throw new LogicException(sprintf('%s input "%s" has unknown type "%s".', $owner, $name, $type));
        }
        if ($positional) {
            self::booleanValue($definition, 'required', $owner . ' positional');
        }
        $enum = self::stringList($definition, 'enum', $owner . ' input');
        if (($type === 'enum') !== ($enum !== [])) {
            throw new LogicException(sprintf('%s input "%s" has an invalid enum declaration.', $owner, $name));
        }

        $secretShaped = preg_match('/(?:^|-)(?:credential|credentials|password|secret)(?:$|-)/D', $name) === 1;
        $safeFileTypes = [
            'absolute-path',
            'output-path',
            'protected-json-file',
            'protected-json-list-file',
            'secret-file',
        ];
        if ($secretShaped && (!str_ends_with($name, '-file') || !in_array($type, $safeFileTypes, true))) {
            throw new LogicException(sprintf('Raw secret-shaped CLI input "%s" is forbidden.', $name));
        }
        if ($type === 'secret-file' && !str_ends_with($name, '-file')) {
            throw new LogicException(sprintf('Secret input "%s" must be file-backed.', $name));
        }
    }

    /**
     * Assert a list contains unique references to declared options.
     *
     * @param   list<string>         $references   Option names to validate.
     * @param   array<string, bool>  $options      Available option-name set.
     * @param   string               $commandName  Owning command.
     * @param   string               $actionName   Owning action.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertUniqueReferences(
        array $references,
        array $options,
        string $commandName,
        string $actionName,
    ): void {
        $seen = [];
        foreach ($references as $reference) {
            if (!isset($options[$reference])) {
                throw new LogicException(sprintf(
                    'Command "%s" action "%s" references undeclared option "%s".',
                    $commandName,
                    $actionName,
                    $reference,
                ));
            }
            if (isset($seen[$reference])) {
                throw new LogicException(sprintf(
                    'Command "%s" action "%s" duplicates option reference "%s".',
                    $commandName,
                    $actionName,
                    $reference,
                ));
            }
            $seen[$reference] = true;
        }
    }

    /**
     * Resolve a declared action by name.
     *
     * @param   array<string, mixed>  $command     Owning command definition.
     * @param   string                $actionName  Requested action name.
     *
     * @return  array<string, mixed>  Matching action definition.
     *
     * @throws  InvalidArgumentException  When the action is not declared.
     *
     * @since   2.0.0
     */
    private function action(array $command, string $actionName): array
    {
        foreach (self::listValue($command, 'actions', 'command') as $action) {
            if (!is_array($action) || array_is_list($action)) {
                continue;
            }
            /** @var array<string, mixed> $action */
            if (($action['name'] ?? null) === $actionName) {
                return $action;
            }
        }
        throw new InvalidArgumentException(sprintf('Action "%s" is unsupported.', $actionName));
    }

    /**
     * Index a command's option definitions by name.
     *
     * @param   array<string, mixed>  $command  Owning command definition.
     *
     * @return  array<string, array<string, mixed>>  Definitions keyed by option name.
     *
     * @since   2.0.0
     */
    private function optionIndex(array $command): array
    {
        $options = [];
        foreach (self::listValue($command, 'options', 'command') as $option) {
            if (!is_array($option) || array_is_list($option)) {
                continue;
            }
            /** @var array<string, mixed> $option */
            $options[self::stringValue($option, 'name', 'option')] = $option;
        }

        return $options;
    }

    /**
     * Decide whether a validated conditional requirement applies to supplied options.
     *
     * @param   array<string, mixed>        $condition  Validated condition object.
     * @param   array<string, string|true>  $options    Supplied invocation options.
     *
     * @return  bool  True when the condition applies.
     *
     * @since   2.0.0
     */
    private function conditionMatches(array $condition, array $options): bool
    {
        $name = self::stringValue($condition, 'option', 'condition');
        $operator = self::stringValue($condition, 'operator', 'condition');
        if ($operator === 'present') {
            return array_key_exists($name, $options);
        }
        $value = $condition['value'] ?? null;

        return is_string($value) && ($options[$name] ?? null) === $value;
    }

    /**
     * Normalize a validated option-reference group into a strict string list.
     *
     * @param   mixed   $group       Decoded candidate group.
     * @param   string  $actionName  Owning action for diagnostics.
     * @param   string  $kind        Human-readable group kind.
     *
     * @return  list<string>  Non-empty string references in retained order.
     *
     * @since   2.0.0
     */
    private static function referenceGroup(mixed $group, string $actionName, string $kind): array
    {
        if (!is_array($group) || !array_is_list($group)) {
            throw new LogicException(sprintf('Action "%s" has an invalid %s group.', $actionName, $kind));
        }
        $references = [];
        foreach ($group as $reference) {
            if (!is_string($reference)) {
                throw new LogicException(sprintf('Action "%s" has a non-string %s reference.', $actionName, $kind));
            }
            $references[] = $reference;
        }

        return $references;
    }

    /**
     * Validate one supplied scalar according to its declared input classifier.
     *
     * @param   array<string, mixed>  $definition  Validated option or positional definition.
     * @param   string|true           $value       Supplied command-line value.
     * @param   string                $label       Human-readable input label.
     *
     * @return  void
     *
     * @throws  InvalidArgumentException  When the value violates its classifier.
     *
     * @since   2.0.0
     */
    private static function assertInputValue(array $definition, string|true $value, string $label): void
    {
        $type = self::stringValue($definition, 'type', 'input');
        if ($type === 'flag') {
            if ($value !== true) {
                throw new InvalidArgumentException(sprintf('%s is a valueless flag.', $label));
            }
            return;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('%s requires a value.', $label));
        }
        $valid = match ($type) {
            'absolute-path', 'output-path', 'protected-json-file', 'protected-json-list-file', 'secret-file' =>
                self::isAbsolutePath($value),
            'base64' => $value !== '' && base64_decode($value, true) !== false,
            'boolean' => in_array($value, ['0', '1'], true),
            'csv' => trim($value) !== '' && array_filter(
                array_map('trim', explode(',', $value)),
                static fn (string $item): bool => $item !== '',
            ) !== [],
            'enum' => in_array($value, self::stringList($definition, 'enum', 'input'), true),
            'hex-digest' => preg_match('/^[a-f0-9]{64}$/D', $value) === 1,
            'identifier', 'string' => trim($value) !== '',
            'json-object' => self::isJsonObject($value),
            'json-object-list' => self::isJsonObjectList($value),
            'non-negative-integer' => preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) === 1,
            'nullable-identifier', 'nullable-string' => true,
            'positive-integer' => preg_match('/^[1-9][0-9]*$/D', $value) === 1,
            'timestamp' => self::isTimestamp($value),
            default => false,
        };
        if (!$valid) {
            throw new InvalidArgumentException(sprintf('%s is not a valid %s value.', $label, $type));
        }
    }

    /**
     * Decide whether a path is absolute on Unix or Windows.
     *
     * @param   string  $value  Candidate path.
     *
     * @return  bool  True for a non-empty rooted path.
     *
     * @since   2.0.0
     */
    private static function isAbsolutePath(string $value): bool
    {
        return str_starts_with($value, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/D', $value) === 1;
    }

    /**
     * Decide whether text is a JSON object.
     *
     * @param   string  $value  Candidate JSON.
     *
     * @return  bool  True only for a well-formed object.
     *
     * @since   2.0.0
     */
    private static function isJsonObject(string $value): bool
    {
        try {
            return json_decode($value, false, 64, JSON_THROW_ON_ERROR) instanceof \stdClass;
        } catch (JsonException) {
            return false;
        }
    }

    /**
     * Decide whether text is a JSON list containing objects only.
     *
     * @param   string  $value  Candidate JSON.
     *
     * @return  bool  True only for a well-formed object list.
     *
     * @since   2.0.0
     */
    private static function isJsonObjectList(string $value): bool
    {
        try {
            $decoded = json_decode($value, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }
        if (!is_array($decoded)) {
            return false;
        }
        foreach ($decoded as $item) {
            if (!$item instanceof \stdClass) {
                return false;
            }
        }

        return true;
    }

    /**
     * Decide whether text is an ISO-compatible timestamp.
     *
     * @param   string  $value  Candidate timestamp.
     *
     * @return  bool  True when PHP can parse the complete value as a date-time.
     *
     * @since   2.0.0
     */
    private static function isTimestamp(string $value): bool
    {
        try {
            new \DateTimeImmutable($value);

            return trim($value) !== '';
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Assert that an object contains exactly the declared keys.
     *
     * Optional `enum` is represented by an empty list, so all shapes stay closed and deterministic.
     *
     * @param   array<string, mixed>  $value     Object to inspect.
     * @param   list<string>          $expected  Exact keys in any order.
     * @param   string                $owner     Object label for diagnostics.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private static function assertKeys(array $value, array $expected, string $owner): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new LogicException(sprintf('%s keys are not closed by the CLI contract schema.', ucfirst($owner)));
        }
    }

    /**
     * Read a non-empty string property.
     *
     * @param   array<string, mixed>  $value  Owning object.
     * @param   string                $key    Property name.
     * @param   string                $owner  Object label.
     *
     * @return  string  Non-empty property value.
     *
     * @since   2.0.0
     */
    private static function stringValue(array $value, string $key, string $owner): string
    {
        $item = $value[$key] ?? null;
        if (!is_string($item) || trim($item) === '') {
            throw new LogicException(sprintf('%s property "%s" must be a non-empty string.', ucfirst($owner), $key));
        }

        return $item;
    }

    /**
     * Read an integer property.
     *
     * @param   array<string, mixed>  $value  Owning object.
     * @param   string                $key    Property name.
     * @param   string                $owner  Object label.
     *
     * @return  int  Exact integer property value.
     *
     * @since   2.0.0
     */
    private static function integerValue(array $value, string $key, string $owner): int
    {
        $item = $value[$key] ?? null;
        if (!is_int($item)) {
            throw new LogicException(sprintf('%s property "%s" must be an integer.', ucfirst($owner), $key));
        }

        return $item;
    }

    /**
     * Read a boolean property.
     *
     * @param   array<string, mixed>  $value  Owning object.
     * @param   string                $key    Property name.
     * @param   string                $owner  Object label.
     *
     * @return  bool  Exact boolean property value.
     *
     * @since   2.0.0
     */
    private static function booleanValue(array $value, string $key, string $owner): bool
    {
        $item = $value[$key] ?? null;
        if (!is_bool($item)) {
            throw new LogicException(sprintf('%s property "%s" must be a boolean.', ucfirst($owner), $key));
        }

        return $item;
    }

    /**
     * Read a JSON-list property.
     *
     * @param   array<string, mixed>  $value  Owning object.
     * @param   string                $key    Property name.
     * @param   string                $owner  Object label.
     *
     * @return  list<mixed>  Exact decoded list.
     *
     * @since   2.0.0
     */
    private static function listValue(array $value, string $key, string $owner): array
    {
        $item = $value[$key] ?? null;
        if (!is_array($item) || !array_is_list($item)) {
            throw new LogicException(sprintf('%s property "%s" must be a list.', ucfirst($owner), $key));
        }

        return $item;
    }

    /**
     * Read a list containing strings only.
     *
     * @param   array<string, mixed>  $value  Owning object.
     * @param   string                $key    Property name.
     * @param   string                $owner  Object label.
     *
     * @return  list<string>  Exact string list.
     *
     * @since   2.0.0
     */
    private static function stringList(array $value, string $key, string $owner): array
    {
        $items = self::listValue($value, $key, $owner);
        $strings = [];
        foreach ($items as $item) {
            if (!is_string($item)) {
                throw new LogicException(sprintf('%s property "%s" must contain strings.', ucfirst($owner), $key));
            }
            $strings[] = $item;
        }

        return $strings;
    }

    /**
     * Read a JSON-object property.
     *
     * @param   array<string, mixed>  $value  Owning object.
     * @param   string                $key    Property name.
     * @param   string                $owner  Object label.
     *
     * @return  array<string, mixed>  Exact decoded object.
     *
     * @since   2.0.0
     */
    private static function objectValue(array $value, string $key, string $owner): array
    {
        $item = $value[$key] ?? null;
        if (!is_array($item) || array_is_list($item)) {
            throw new LogicException(sprintf('%s property "%s" must be an object.', ucfirst($owner), $key));
        }
        /** @var array<string, mixed> $item */

        return $item;
    }

    /**
     * Read the numeric-keyed JSON object that maps statuses onto stable meanings.
     *
     * PHP converts numeric JSON object keys into integer array keys, and a map containing exactly zero
     * and one therefore looks like a list to `array_is_list()`. Exit maps are deliberately read through
     * this dedicated helper so their JSON object semantics remain explicit and digest normalization can
     * restore the object before encoding.
     *
     * @param   array<string, mixed>  $command  Owning command definition.
     * @param   string                $owner    Command name for diagnostics.
     *
     * @return  array<int|string, mixed>  Numeric-keyed exit meanings.
     *
     * @since   2.0.0
     */
    private static function exitCodeMap(array $command, string $owner): array
    {
        $exitCodes = $command['exit_codes'] ?? null;
        if (!is_array($exitCodes)) {
            throw new LogicException(sprintf('%s property "exit_codes" must be an object.', ucfirst($owner)));
        }

        return $exitCodes;
    }
}
