<?php

return [
    'schema_version' => '1.1.0',

    'user_agent' => env('MONITOR_USER_AGENT', 'MonitorBCV/1.1 (+https://monitorbcv.web.test)'),

    'timeout' => (int) env('MONITOR_TIMEOUT', 15),

    'interval' => (int) env('MONITOR_INTERVAL', 5),

    'web_tick_limit' => (int) env('MONITOR_WEB_TICK_LIMIT', 3),

    'web_tick_budget_ms' => (int) env('MONITOR_WEB_TICK_BUDGET_MS', 12000),

    'use_os_trust_store' => (bool) env('MONITOR_USE_OS_TRUST_STORE', true),

    'retry_untrusted_issuer' => (bool) env('MONITOR_RETRY_UNTRUSTED_ISSUER', true),

    'fastapi' => [
        'url' => env('MONITOR_API_URL', 'http://127.0.0.1:8100'),
        'enabled' => (bool) env('MONITOR_API_ENABLED', false),
        'token' => env('MONITOR_API_TOKEN', ''),
        'connect_timeout' => (float) env('MONITOR_API_CONNECT_TIMEOUT', 3),
        'health_timeout' => (float) env('MONITOR_API_HEALTH_TIMEOUT', 8),
    ],

    'probes' => [
        'internal' => [
            'url' => env('MONITOR_API_INTERNAL_URL', env('MONITOR_API_URL', 'http://127.0.0.1:8100')),
            'token' => env('MONITOR_API_INTERNAL_TOKEN', env('MONITOR_API_TOKEN', '')),
            'enabled' => (bool) env('MONITOR_API_INTERNAL_ENABLED', env('MONITOR_API_ENABLED', false)),
        ],
        'external' => [
            'url' => env('MONITOR_API_EXTERNAL_URL', ''),
            'token' => env('MONITOR_API_EXTERNAL_TOKEN', ''),
            'enabled' => (bool) env('MONITOR_API_EXTERNAL_ENABLED', false),
        ],
    ],

    'degradation' => [
        'ttfb_warning_ms' => (int) env('MONITOR_TTFB_WARNING_MS', 1000),
        'ttfb_warning_streak' => (int) env('MONITOR_TTFB_WARNING_STREAK', 3),
        'ttfb_critical_ms' => (int) env('MONITOR_TTFB_CRITICAL_MS', 3000),
        'ssl_warning_days' => (int) env('MONITOR_SSL_WARNING_DAYS', 15),
        'ssl_critical_days' => (int) env('MONITOR_SSL_CRITICAL_DAYS', 7),
    ],

    'jsonl_path' => 'monitor/checks',

    'remote_commands' => [
        'output_max_bytes' => (int) env('MONITOR_SSH_OUTPUT_MAX', 32768),
        'default_timeout' => (int) env('MONITOR_SSH_TIMEOUT', 15),
    ],
];
