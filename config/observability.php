<?php

/**
 * Observability contract for logging, health endpoints, and metric exposure.
 *
 * The values here are the deployment-independent parts of the contract: which context fields every
 * log line must carry, which fields are redacted before a line is written, and the paths and budgets
 * the health probes use. Environment variables tune the deployment-specific parts.
 *
 * The `tracing` block is a truthful declaration, not a switch. Kumwe ships no tracer and no exporter: it
 * propagates W3C trace context by accepting a well-formed inbound `traceparent` and stamping its trace and
 * span identifiers onto that request's log records, and it never records, samples or exports spans. Adopting
 * an exporter is a separately reviewed dependency decision (docs/roadmap/decisions/0022), so these values
 * stay disabled until that decision lands together with the code that would read them.
 *
 * @return array<string, mixed> The observability configuration tree.
 *
 * @since  2.0.0
 */

declare(strict_types=1);

return [
    'version' => 1,
    'logging' => [
        'destination' => 'php://stderr',
        'format' => 'json',
        'default_level' => 'info',
        'required_context' => [
            'correlation_id',
            'release',
            'runtime',
            'outcome',
        ],
        'redacted_fields' => [
            'authorization',
            'cookie',
            'password',
            'secret',
            'set-cookie',
            'token',
        ],
    ],
    'health' => [
        'liveness_path' => '/health/live',
        'readiness_path' => '/health/ready',
        'dependency_timeout_milliseconds' => 2_000,
        'expose_details' => false,
    ],
    'metrics' => [
        'enabled' => false,
        'path' => '/metrics',
        'public' => false,
        'forbidden_labels' => [
            'content_id',
            'email',
            'session_id',
            'token_id',
            'user_id',
        ],
    ],
    // Propagation only: no tracer or exporter reads this block (see the file-level note above).
    'tracing' => [
        'enabled' => false,
        'exporter' => 'none',
        'sample_ratio' => 0.0,
    ],
];
