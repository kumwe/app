<?php

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
