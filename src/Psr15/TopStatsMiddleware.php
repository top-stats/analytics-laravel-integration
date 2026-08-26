<?php

declare(strict_types=1);

namespace TopStats\Laravel\Psr15;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TopStats\Laravel\Recorder;

/**
 * PSR-15 middleware for Symfony (via PSR bridges), Slim, Mezzio and friends.
 * PSR routing is not standardized, so the route template comes from a
 * resolver callback; without one, requests group under "unmatched" rather
 * than leaking raw high-cardinality paths.
 */
final class TopStatsMiddleware implements MiddlewareInterface
{
    /**
     * @param null|\Closure(ServerRequestInterface): (string|null) $templateResolver
     */
    public function __construct(
        private readonly Recorder $recorder,
        private readonly ?\Closure $templateResolver = null,
    ) {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        if ($this->recorder->skip($request->getUri()->getPath())) {
            return $handler->handle($request);
        }

        $startedAt = microtime(true);
        $errorName = null;
        $status = 500;

        try {
            $response = $handler->handle($request);
            $status = $response->getStatusCode();

            return $response;
        } catch (\Throwable $caught) {
            $errorName = $caught::class;

            throw $caught;
        } finally {
            $this->recorder->record(
                $this->template($request),
                $request->getMethod(),
                $status,
                (int) round((microtime(true) - $startedAt) * 1000),
                $request,
                $errorName,
            );
        }
    }

    private function template(ServerRequestInterface $request): string
    {
        if ($this->templateResolver !== null) {
            try {
                $resolved = ($this->templateResolver)($request);

                if (is_string($resolved) && $resolved !== '') {
                    return $resolved;
                }
            } catch (\Throwable) {
                // Fall through to unmatched.
            }
        }

        return 'unmatched';
    }
}
