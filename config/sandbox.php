<?php

return [
    'code_execution' => [
        'enabled' => false,
        'network_disabled' => true,
        'memory_limit_mb' => 512,
        'timeout_seconds' => 30,
    ],
    'browser' => [
        'enabled' => false,
        'allowlist_only' => true,
        'read_only_default' => true,
        'downloads_quarantined' => true,
    ],
];
