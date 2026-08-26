<?php

declare(strict_types=1);

namespace TopStats\Laravel\Middleware;

use TopStats\Laravel\Recorder;

/**
 * Laravel global middleware. The capture happens in terminate(), after the
 * response has left the client, so analytics never sits in the request path.
 * Register it in the global middleware stack (bootstrap/app.php on 11+,
 * Kernel::$middleware before that).
 */
final class TrackRequests
{
    /** @var array<string, float> */
    private array $startedAt = [];

    public function __construct(private readonly Recorder $recorder)
    {
    }

    public function handle(mixed $request, \Closure $next): mixed
    {
        $this->startedAt[spl_object_hash($request)] = microtime(true);

        return $next($request);
    }

    public function terminate(mixed $request, mixed $response): void
    {
        $key = spl_object_hash($request);
        $startedAt = $this->startedAt[$key] ?? null;
        unset($this->startedAt[$key]);

        $path = '/' . ltrim((string) $request->path(), '/');

        if ($this->recorder->skip($path)) {
            return;
        }

        $durationMs = 0;

        if ($startedAt !== null) {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        }

        $this->recorder->record(
            $this->routeTemplate($request),
            (string) $request->method(),
            (int) $response->getStatusCode(),
            $durationMs,
            $request,
        );
    }

    private function routeTemplate(mixed $request): string
    {
        $route = $request->route();

        if ($route !== null && method_exists($route, 'uri')) {
            $uri = (string) $route->uri();

            if ($uri !== '') {
                return '/' . ltrim($uri, '/');
            }
        }

        return 'unmatched';
    }
}
