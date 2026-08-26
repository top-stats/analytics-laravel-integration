<?php

declare(strict_types=1);

namespace TopStats\Laravel;

/**
 * The one place events are built: http_request per request with the route
 * TEMPLATE (never the raw URL, so ids do not explode property cardinality),
 * plus http_error on 5xx. Never throws into the caller.
 */
final class Recorder
{
    /**
     * @param list<string> $ignorePaths
     */
    public function __construct(
        private readonly Capture $capture,
        private readonly array $ignorePaths = [],
        private readonly float $sampleRate = 1.0,
        private readonly ?\Closure $actor = null,
    ) {
    }

    public function skip(string $path): bool
    {
        foreach ($this->ignorePaths as $ignored) {
            if ($path === $ignored) {
                return true;
            }
        }

        return $this->sampleRate < 1 && mt_rand() / mt_getrandmax() >= $this->sampleRate;
    }

    public function record(
        string $route,
        string $method,
        int $status,
        int $durationMs,
        mixed $request = null,
        ?string $errorName = null,
    ): void {
        try {
            $context = [];

            if ($this->actor !== null) {
                try {
                    $resolved = ($this->actor)($request);

                    if (is_array($resolved) && isset($resolved['actor'])) {
                        $context['actor'] = (string) $resolved['actor'];

                        if (isset($resolved['actorLabel'])) {
                            $context['actorLabel'] = (string) $resolved['actorLabel'];
                        }
                    }
                } catch (\Throwable) {
                    // A broken extractor must never break a response.
                }
            }

            $this->capture->capture('http_request', [
                'route' => $route,
                'method' => $method,
                'status' => $status,
                'duration_ms' => $durationMs,
            ], $context);

            if ($errorName !== null || $status >= 500) {
                $properties = [
                    'route' => $route,
                    'method' => $method,
                    'status' => $status,
                ];

                if ($errorName !== null) {
                    $properties['error'] = $errorName;
                }

                $this->capture->capture('http_error', $properties, $context);
            }
        } catch (\Throwable) {
            // Capture must never break a response.
        }
    }
}
