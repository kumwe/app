<?php

declare(strict_types=1);

namespace Kumwe\CMS\Infrastructure\Observability;

use InvalidArgumentException;

/**
 * The declared observability contract, loaded once and treated as the single source of truth.
 *
 * `config/observability.php` used to be a statement of intent that only an architecture test read:
 * it declared a JSON log format, a default level, a required-context set and a redaction list while
 * the running process wrote unstructured lines with none of it applied. This class closes that gap by
 * being the only reader of that file — the logger, the metrics endpoint and the health routes are all
 * composed from an instance of it, so changing the declaration changes runtime behaviour and a
 * declaration the runtime cannot honour fails at boot instead of drifting quietly.
 *
 * Every value is validated on load. A malformed contract is a configuration error, not a fallback:
 * silently defaulting a redaction list would be the one failure mode that leaks.
 *
 * @since  2.0.0
 */
final readonly class ObservabilityContract
{
    /**
     * Log levels the contract may name, lowest to highest severity.
     *
     * The set is Monolog's, spelled in lower case so the declaration and the `KUMWE_LOG_LEVEL`
     * override use one vocabulary. Keeping it here rather than importing `Monolog\Level` lets the
     * contract be validated by tooling that runs before Composer dependencies exist.
     *
     * @var    list<string>
     * @since  2.0.0
     */
    public const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    /**
     * Bind the validated contract values.
     *
     * The constructor is private because a contract only ever comes from the declaration file or an
     * already-decoded declaration array; both entry points validate, so no caller can assemble an
     * instance that skipped a rule.
     *
     * @param  string        $logDestination                 Stream every record is written to.
     * @param  string        $logFormat                      Wire format for a record; only `json` is implemented.
     * @param  string        $logDefaultLevel                Level records are emitted from when nothing overrides it.
     * @param  list<string>  $requiredContext                Context keys every record must carry.
     * @param  list<string>  $redactedFields                 Context key fragments whose values never reach a log.
     * @param  string        $livenessPath                   Path the process-liveness probe answers on.
     * @param  string        $readinessPath                  Path the traffic-readiness probe answers on.
     * @param  int           $dependencyTimeoutMilliseconds  Budget a deep readiness check may spend per dependency.
     * @param  bool          $exposeHealthDetails            Whether a probe response may name which check refused.
     * @param  bool          $metricsEnabled                 Whether the exposition endpoint answers at all.
     * @param  string        $metricsPath                    Path the exposition endpoint is served on.
     * @param  bool          $metricsPublic                  Whether the endpoint may be scraped without a token.
     * @param  list<string>  $forbiddenLabels                Label names no metric may ever carry.
     * @param  bool          $tracingEnabled                 Whether a tracer is wired; false in every shipped build.
     * @param  string        $tracingExporter                Exporter the tracer would use; `none` when unwired.
     * @param  float         $tracingSampleRatio             Fraction of traces a wired tracer would record.
     *
     * @since  2.0.0
     */
    private function __construct(
        public string $logDestination,
        public string $logFormat,
        public string $logDefaultLevel,
        public array $requiredContext,
        public array $redactedFields,
        public string $livenessPath,
        public string $readinessPath,
        public int $dependencyTimeoutMilliseconds,
        public bool $exposeHealthDetails,
        public bool $metricsEnabled,
        public string $metricsPath,
        public bool $metricsPublic,
        public array $forbiddenLabels,
        public bool $tracingEnabled,
        public string $tracingExporter,
        public float $tracingSampleRatio,
    ) {
    }

    /**
     * Load and validate the declaration that ships with the repository.
     *
     * @param   string  $root  Absolute repository root the `config/observability.php` path is resolved against.
     *
     * @return  self  The validated contract.
     *
     * @throws  InvalidArgumentException  When the file is missing, does not return an array, or declares a
     *          value the runtime cannot honour.
     *
     * @since   2.0.0
     */
    public static function load(string $root): self
    {
        $path = $root . '/config/observability.php';
        if (!is_file($path)) {
            throw new InvalidArgumentException('The observability contract file is missing.');
        }
        /** @var mixed $declaration */
        $declaration = require $path;
        if (!is_array($declaration)) {
            throw new InvalidArgumentException('The observability contract must return an array.');
        }

        return self::fromArray($declaration);
    }

    /**
     * Validate an already-decoded declaration into a contract.
     *
     * @param   array<mixed, mixed>  $declaration  Declaration tree as returned by the contract file.
     *
     * @return  self  The validated contract.
     *
     * @throws  InvalidArgumentException  When a declared value is missing or of the wrong shape.
     *
     * @since   2.0.0
     */
    public static function fromArray(array $declaration): self
    {
        $logging = self::section($declaration, 'logging');
        $health = self::section($declaration, 'health');
        $metrics = self::section($declaration, 'metrics');
        $tracing = self::section($declaration, 'tracing');
        $format = self::string($logging, 'format', 'logging');
        if ($format !== 'json') {
            throw new InvalidArgumentException('The only implemented log format is json.');
        }
        $level = self::string($logging, 'default_level', 'logging');
        if (!in_array($level, self::LEVELS, true)) {
            throw new InvalidArgumentException('The declared default log level is not a known level.');
        }
        $redacted = self::stringList($logging, 'redacted_fields', 'logging');
        if ($redacted === []) {
            throw new InvalidArgumentException('The redaction list may not be empty.');
        }

        return new self(
            logDestination: self::string($logging, 'destination', 'logging'),
            logFormat: $format,
            logDefaultLevel: $level,
            requiredContext: self::stringList($logging, 'required_context', 'logging'),
            redactedFields: $redacted,
            livenessPath: self::path($health, 'liveness_path'),
            readinessPath: self::path($health, 'readiness_path'),
            dependencyTimeoutMilliseconds: self::positiveInteger($health, 'dependency_timeout_milliseconds'),
            exposeHealthDetails: self::boolean($health, 'expose_details', 'health'),
            metricsEnabled: self::boolean($metrics, 'enabled', 'metrics'),
            metricsPath: self::path($metrics, 'path'),
            metricsPublic: self::boolean($metrics, 'public', 'metrics'),
            forbiddenLabels: self::stringList($metrics, 'forbidden_labels', 'metrics'),
            tracingEnabled: self::boolean($tracing, 'enabled', 'tracing'),
            tracingExporter: self::string($tracing, 'exporter', 'tracing'),
            tracingSampleRatio: self::ratio($tracing),
        );
    }

    /**
     * Reports whether a metric label name is refused by the declared cardinality policy.
     *
     * The comparison is a containment test rather than equality, so `user_id` in the declaration also
     * refuses `owner_user_id`. Cardinality is a correctness property for an exposition endpoint: a
     * label carrying a record or account identifier turns one time series into one per row and takes
     * the monitoring system down long before anybody reads the dashboard.
     *
     * @param   string  $label  Candidate label name.
     *
     * @return  bool  True when the label may not be used.
     *
     * @since   2.0.0
     */
    public function forbidsLabel(string $label): bool
    {
        $candidate = strtolower($label);
        foreach ($this->forbiddenLabels as $forbidden) {
            if (str_contains($candidate, $forbidden)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reports whether a context key must never reach a log record with its value intact.
     *
     * @param   string  $key  Context or extra key being written.
     *
     * @return  bool  True when the value has to be replaced before the record is formatted.
     *
     * @since   2.0.0
     */
    public function redactsKey(string $key): bool
    {
        $candidate = strtolower($key);
        foreach ($this->redactedFields as $field) {
            if (str_contains($candidate, $field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read one declared section as an array.
     *
     * @param   array<mixed, mixed>  $declaration  Whole declaration tree.
     * @param   string               $name         Section key being read.
     *
     * @return  array<mixed, mixed>  The section.
     *
     * @throws  InvalidArgumentException  When the section is absent or not an array.
     *
     * @since   2.0.0
     */
    private static function section(array $declaration, string $name): array
    {
        $section = $declaration[$name] ?? null;
        if (!is_array($section)) {
            throw new InvalidArgumentException(sprintf('The observability %s section is missing.', $name));
        }

        return $section;
    }

    /**
     * Read a non-empty string from a section.
     *
     * @param   array<mixed, mixed>  $section  Section being read.
     * @param   string               $key      Key within the section.
     * @param   string               $name     Section name, for the failure message.
     *
     * @return  string  The declared value.
     *
     * @throws  InvalidArgumentException  When the value is absent or not a non-empty string.
     *
     * @since   2.0.0
     */
    private static function string(array $section, string $key, string $name): string
    {
        $value = $section[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException(
                sprintf('The observability %s.%s value must be a non-empty string.', $name, $key),
            );
        }

        return $value;
    }

    /**
     * Read a boolean from a section.
     *
     * @param   array<mixed, mixed>  $section  Section being read.
     * @param   string               $key      Key within the section.
     * @param   string               $name     Section name, for the failure message.
     *
     * @return  bool  The declared value.
     *
     * @throws  InvalidArgumentException  When the value is absent or not a boolean.
     *
     * @since   2.0.0
     */
    private static function boolean(array $section, string $key, string $name): bool
    {
        $value = $section[$key] ?? null;
        if (!is_bool($value)) {
            throw new InvalidArgumentException(
                sprintf('The observability %s.%s value must be a boolean.', $name, $key),
            );
        }

        return $value;
    }

    /**
     * Read a lower-cased, de-duplicated list of non-empty strings from a section.
     *
     * @param   array<mixed, mixed>  $section  Section being read.
     * @param   string               $key      Key within the section.
     * @param   string               $name     Section name, for the failure message.
     *
     * @return  list<string>  The declared values, lower-cased.
     *
     * @throws  InvalidArgumentException  When the value is not a list of non-empty strings.
     *
     * @since   2.0.0
     */
    private static function stringList(array $section, string $key, string $name): array
    {
        $value = $section[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException(
                sprintf('The observability %s.%s value must be a list.', $name, $key),
            );
        }
        $values = [];
        foreach ($value as $entry) {
            if (!is_string($entry) || trim($entry) === '') {
                throw new InvalidArgumentException(
                    sprintf('The observability %s.%s list holds a non-string entry.', $name, $key),
                );
            }
            $values[strtolower(trim($entry))] = true;
        }

        return array_keys($values);
    }

    /**
     * Read an absolute request path from a section.
     *
     * @param   array<mixed, mixed>  $section  Section being read.
     * @param   string               $key      Key within the section.
     *
     * @return  string  The declared path.
     *
     * @throws  InvalidArgumentException  When the value is not an absolute path.
     *
     * @since   2.0.0
     */
    private static function path(array $section, string $key): string
    {
        $value = $section[$key] ?? null;
        if (!is_string($value) || preg_match('#^/[A-Za-z0-9/_.-]*$#D', $value) !== 1) {
            throw new InvalidArgumentException(
                sprintf('The observability %s value must be an absolute request path.', $key),
            );
        }

        return $value;
    }

    /**
     * Read a positive integer from a section.
     *
     * @param   array<mixed, mixed>  $section  Section being read.
     * @param   string               $key      Key within the section.
     *
     * @return  int<1, max>  The declared value.
     *
     * @throws  InvalidArgumentException  When the value is not a positive integer.
     *
     * @since   2.0.0
     */
    private static function positiveInteger(array $section, string $key): int
    {
        $value = $section[$key] ?? null;
        if (!is_int($value) || $value < 1) {
            throw new InvalidArgumentException(
                sprintf('The observability %s value must be a positive integer.', $key),
            );
        }

        return $value;
    }

    /**
     * Read the tracing sample ratio.
     *
     * @param   array<mixed, mixed>  $tracing  Tracing section.
     *
     * @return  float  Ratio between zero and one inclusive.
     *
     * @throws  InvalidArgumentException  When the value is outside the unit interval.
     *
     * @since   2.0.0
     */
    private static function ratio(array $tracing): float
    {
        $value = $tracing['sample_ratio'] ?? null;
        if (is_int($value)) {
            $value = (float) $value;
        }
        if (!is_float($value) || $value < 0.0 || $value > 1.0) {
            throw new InvalidArgumentException('The tracing sample ratio must be between zero and one.');
        }

        return $value;
    }
}
