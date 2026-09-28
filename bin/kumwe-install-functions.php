<?php

/**
 * Terminal and process helpers for the distribution installer.
 *
 * Kept separate so importing declarations never starts an installation.
 *
 * @since  2.0.0-beta.1
 */

declare(strict_types=1);

/**
 * Refuse incomplete or unsafe installer input.
 *
 * @param   string  $message  Operator-facing refusal.
 *
 * @return  never
 *
 * @since   2.0.0
 */
function fail(string $message): never
{
    fwrite(STDERR, "Kumwe setup failed: {$message}\n");
    exit(1);
}

/**
 * Read one interactive installer answer.
 *
 * @param   string  $label  Prompt displayed to the operator.
 * @param   ?string  $default  Value used for an empty answer.
 *
 * @return  string  Trimmed answer or its default.
 *
 * @since   2.0.0
 */
function prompt(string $label, ?string $default = null): string
{
    $suffix = $default === null || $default === '' ? '' : " [{$default}]";
    fwrite(STDOUT, $label . $suffix . ': ');
    $answer = fgets(STDIN);

    if (!is_string($answer)) {
        fail('Input ended before configuration was complete.');
    }

    $answer = trim($answer);

    return $answer === '' && $default !== null ? $default : $answer;
}

/**
 * Read a secret while restoring the terminal echo state afterwards.
 *
 * @param   string  $label  Prompt naming the secret.
 *
 * @return  string  Trimmed secret input.
 *
 * @since   2.0.0
 */
function secretPrompt(string $label): string
{
    fwrite(STDOUT, $label . ': ');
    $terminalState = terminalState();

    if ($terminalState === null) {
        fail('This terminal cannot hide secret input. Configure secrets non-interactively instead.');
    }

    setTerminalState('-echo');

    try {
        $answer = fgets(STDIN);
    } finally {
        setTerminalState($terminalState);
        fwrite(STDOUT, "\n");
    }

    if (!is_string($answer)) {
        fail('Input ended before configuration was complete.');
    }

    return trim($answer);
}

/**
 * Read the current terminal state without changing it.
 *
 * @return  ?string  Saved state, or null when no terminal is available.
 *
 * @since   2.0.0
 */
function terminalState(): ?string
{
    $process = proc_open(
        ['stty', '-g'],
        [STDIN, ['pipe', 'w'], STDERR],
        $pipes,
    );

    if (!is_resource($process) || !isset($pipes[1]) || !is_resource($pipes[1])) {
        return null;
    }

    $state = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    if (proc_close($process) !== 0 || !is_string($state) || trim($state) === '') {
        return null;
    }

    return trim($state);
}

/**
 * Apply the terminal state required for protected input.
 *
 * @param   string  $state  State returned by stty or the echo control.
 *
 * @return  void
 *
 * @since   2.0.0
 */
function setTerminalState(string $state): void
{
    $process = proc_open(['stty', $state], [STDIN, STDOUT, STDERR], $pipes);

    if (!is_resource($process) || proc_close($process) !== 0) {
        fail('Could not protect terminal secret input.');
    }
}

/**
 * Read a yes or no installation decision.
 *
 * @param   string  $label  Confirmation prompt.
 * @param   bool  $default  Choice used when the answer is empty.
 *
 * @return  bool  Whether the operator answered yes.
 *
 * @since   2.0.0
 */
function confirmation(string $label, bool $default): bool
{
    $answer = strtolower(prompt($label . ($default ? ' [Y/n]' : ' [y/N]'), $default ? 'y' : 'n'));

    return in_array($answer, ['y', 'yes'], true);
}

/**
 * Escape a value for the generated dotenv file.
 *
 * @param   string  $value  Unescaped configuration value.
 *
 * @return  string  Quoted dotenv value.
 *
 * @since   2.0.0
 */
function encodeEnvironmentValue(string $value): string
{
    return '"' . str_replace(
        ["\\", '"', "\r", "\n"],
        ["\\\\", '\\"', '\\r', '\\n'],
        $value,
    ) . '"';
}

/**
 * Reduce discovered demo manifest paths to sorted selectable profile names.
 *
 * @param   list<string>  $paths  Globbed manifest paths.
 * @param   bool  $directory  Whether the profile name comes from the containing directory.
 *
 * @return  list<string>  Valid profile names in display order.
 *
 * @since   2.0.0
 */
function discoveredProfiles(array $paths, bool $directory = false): array
{
    $profiles = [];

    foreach ($paths as $path) {
        $name = $directory ? basename(dirname($path)) : basename($path, '.json');

        if (preg_match('/^[a-z][a-z0-9-]{0,62}$/D', $name) === 1) {
            $profiles[] = $name;
        }
    }

    sort($profiles);

    return $profiles;
}

/**
 * Run the production CLI with the installer terminal streams.
 *
 * @param   non-empty-list<string>  $command  Executable and argument vector.
 * @param   string  $workingDirectory  Installed project directory.
 *
 * @return  int  CLI process exit status.
 *
 * @since   2.0.0
 */
function runCommand(array $command, string $workingDirectory): int
{
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $workingDirectory);

    if (!is_resource($process)) {
        fail('Could not start the Kumwe CLI.');
    }

    return proc_close($process);
}
