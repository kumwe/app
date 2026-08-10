<?php

declare(strict_types=1);

namespace Kumwe\CMS\Administrator\Automation;

use InvalidArgumentException;
use Kumwe\CMS\BusinessIntegration\Domain\JobContributionDefinition;

/**
 * Compiles the bounded job payload schema into the graphical schedule form.
 *
 * Nested objects and arrays deliberately fail activation: an active job must have an operator-safe
 * form and cannot fall back to hand-authored JSON. Payload validation remains authoritative when the
 * job is queued; this compiler only describes the same closed primitive schema to the administrator.
 *
 * @since  2.0.0
 */
final readonly class ContributedJobFormCompiler
{
    /**
     * Register every active job definition in deterministic contribution order.
     *
     * @param   iterable<JobContributionDefinition>  $jobs      Trusted active job declarations.
     * @param   AutomationJobFormRegistry            $registry  Shared operator form catalog.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function compile(iterable $jobs, AutomationJobFormRegistry $registry): void
    {
        foreach ($jobs as $job) {
            $registry->register(
                $job->identifier(),
                $this->label($job->identifier()),
                $this->fields($job),
            );
        }
    }

    /**
     * @return list<AutomationJobField> Primitive payload fields in schema order.
     *
     * @throws InvalidArgumentException When a schema cannot be rendered without a raw escape hatch.
     */
    private function fields(JobContributionDefinition $job): array
    {
        $schema = $job->payloadSchema();
        if (($schema['type'] ?? null) !== 'object' || ($schema['additionalProperties'] ?? null) !== false) {
            throw new InvalidArgumentException('A contributed job payload must be a closed object schema.');
        }
        $properties = $schema['properties'] ?? [];
        $required = $schema['required'] ?? [];
        if (!is_array($properties) || array_is_list($properties) || !is_array($required) || !array_is_list($required)) {
            throw new InvalidArgumentException('A contributed job payload schema has invalid properties or requirements.');
        }
        $requiredMap = [];
        foreach ($required as $key) {
            if (!is_string($key) || !array_key_exists($key, $properties)) {
                throw new InvalidArgumentException('A contributed job payload requires an unknown field.');
            }
            $requiredMap[$key] = true;
        }

        $fields = [];
        foreach ($properties as $key => $property) {
            if (!is_string($key) || !is_array($property) || array_is_list($property)) {
                throw new InvalidArgumentException('A contributed job payload property is invalid.');
            }
            $type = $property['type'] ?? null;
            if (!is_string($type) || !in_array($type, ['string', 'integer', 'boolean'], true)) {
                throw new InvalidArgumentException('Contributed job forms support string, integer, and boolean fields.');
            }
            $title = $property['title'] ?? $this->label($key);
            $help = $property['description'] ?? '';
            if (!is_string($title) || trim($title) === '' || !is_string($help)) {
                throw new InvalidArgumentException('A contributed job payload field label or help is invalid.');
            }
            $default = $property['default'] ?? null;
            if (!is_bool($default) && !is_string($default) && !is_int($default) && $default !== null) {
                throw new InvalidArgumentException('A contributed job payload default is not a primitive value.');
            }

            if ($type === 'integer') {
                $minimum = $property['minimum'] ?? null;
                $maximum = $property['maximum'] ?? null;
                if (($minimum !== null && !is_int($minimum)) || ($maximum !== null && !is_int($maximum))) {
                    throw new InvalidArgumentException('An integer job payload bound must be an integer.');
                }
                $fields[] = new AutomationJobField(
                    $key,
                    trim($title),
                    'integer',
                    isset($requiredMap[$key]),
                    $default,
                    $minimum,
                    $maximum,
                    help: trim($help),
                );
                continue;
            }

            if ($type === 'boolean') {
                if ($default !== null && !is_bool($default)) {
                    throw new InvalidArgumentException('A boolean job payload default must be boolean.');
                }
                $fields[] = new AutomationJobField(
                    $key,
                    trim($title),
                    'boolean',
                    isset($requiredMap[$key]),
                    $default,
                    help: trim($help),
                );
                continue;
            }

            $enum = $property['enum'] ?? [];
            if (!is_array($enum) || !array_is_list($enum)) {
                throw new InvalidArgumentException('A string job payload enum must be a list.');
            }
            foreach ($enum as $option) {
                if (!is_string($option)) {
                    throw new InvalidArgumentException('A string job payload enum contains a non-string value.');
                }
            }
            /** @var list<string> $enum */
            $pattern = $property['pattern'] ?? null;
            if ($pattern !== null && (!is_string($pattern) || @preg_match($pattern, '') === false)) {
                throw new InvalidArgumentException('A string job payload pattern must be valid PCRE.');
            }
            $fields[] = new AutomationJobField(
                $key,
                trim($title),
                $enum === [] ? 'text' : 'select',
                isset($requiredMap[$key]),
                $default,
                pattern: $pattern,
                options: $enum,
                help: trim($help),
            );
        }

        return $fields;
    }

    /** @return string Human-readable caption derived from one namespaced identifier. */
    private function label(string $identifier): string
    {
        $parts = explode('.', $identifier);

        return ucfirst(str_replace(['_', '-'], ' ', (string) end($parts)));
    }
}
