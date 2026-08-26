<?php

declare(strict_types=1);

namespace TopStats\Laravel\Middleware;

use TopStats\Laravel\Recorder;

/**
 * Laravel global middleware. The capture happens in terminate(), after the
 * response has left the client, so analytics never sits in the request path.
 * Register it in the global middleware stack (bootstrap/app.php on 11+,
 * Kernel::$middleware before that).
 *
 * Laravel resolves a FRESH middleware instance for terminate() unless the
 * class is bound as a singleton, so the start time lives on the request's
 * attribute bag - never on instance state. The service provider binds the
 * singleton too, but the attribute bag keeps this correct either way.
 */
final class TrackRequests
{
    private const STARTED_AT_ATTRIBUTE = 'topstats.started_at';

    public function __construct(private readonly Recorder $recorder)
    {
    }

    public function handle(mixed $request, \Closure $next): mixed
    {
        $request->attributes->set(self::STARTED_AT_ATTRIBUTE, microtime(true));

        return $next($request);
    }

    public function terminate(mixed $request, mixed $response): void
    {
        $startedAt = $request->attributes->get(self::STARTED_AT_ATTRIBUTE);

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
