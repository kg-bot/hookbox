<?php

declare(strict_types=1);

return [
    'route_prefix' => 'webhooks',
    'queue' => [
        'connection' => null,
        'name' => null,
    ],
    'store_invalid_signatures' => true,
    // slug => ['name' => string, 'verifier' => class-string, ...source-specific options like secret, webhook_id, topic_arn, redact, retention_days]
    'sources' => [],
];
