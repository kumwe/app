<?php

/**
 * Observability contract for logging, health endpoints, and metric exposure.
 *
 * The values here are the deployment-independent parts of the contract: which context fields every
 * log line must carry, which fields are redacted before a line is written, and the paths and budgets
 * the health probes use. Environment variables tune the deployment-specific parts.
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
    'tracing' => [
        'enabled' => false,
        'exporter' => 'none',
        'sample_ratio' => 0.0,
    ],
];
