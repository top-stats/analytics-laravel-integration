<?php

declare(strict_types=1);

return [
    // Create a key in your workspace under Settings -> API keys.
    'api_key' => env('TOPSTATS_API_KEY', ''),

    // Override the API host; leave null for the default.
    'host' => env('TOPSTATS_HOST'),

    // _source on captured events.
    'source' => env('TOPSTATS_SOURCE', 'laravel'),

    // Exact paths that never produce events.
    'ignore_paths' => ['/up', '/health'],

    // Fraction of requests captured, 0..1.
    'sample_rate' => 1.0,

    // Closure resolving ['actor' => ..., 'actorLabel' => ...] from the request.
    'actor' => null,
];
