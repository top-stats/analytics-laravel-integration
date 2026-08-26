<?php

declare(strict_types=1);

namespace TopStats\Laravel\Tests;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as PsrResponse;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TopStats\Laravel\Capture;
use TopStats\Laravel\Middleware\TrackRequests;
use TopStats\Laravel\Psr15\TopStatsMiddleware;
use TopStats\Laravel\Recorder;

final class FakeCapture implements Capture
{
    /** @var list<array{name: string, properties: array<string, mixed>, context: array<string, mixed>}> */
    public array $events = [];

    public int $flushes = 0;

    public function capture(string $name, array $properties = [], array $context = []): void
    {
        $this->events[] = ['name' => $name, 'properties' => $properties, 'context' => $context];
    }

    public function flush(): void
    {
        ++$this->flushes;
    }
}

final class FakeResponse
{
    public function __construct(private readonly int $status)
    {
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }
}

final class IntegrationTest extends TestCase
{
    private function laravelRequest(string $uri, string $routeUri): Request
    {
        $request = Request::create($uri, 'GET');
        $route = new Route(['GET'], $routeUri, []);
        $route->bind($request);
        $request->setRouteResolver(static fn (): Route => $route);

        return $request;
    }

    public function testLaravelMiddlewareCapturesTheRouteTemplate(): void
    {
        $capture = new FakeCapture();
        $recorder = new Recorder($capture);

        // Laravel resolves a FRESH instance for terminate() unless the class
        // is a singleton - two instances here prove the start time survives
        // on the request rather than on middleware state.
        $request = $this->laravelRequest('/users/42', 'users/{id}');
        (new TrackRequests($recorder))->handle($request, static fn ($passed) => $passed);
        (new TrackRequests($recorder))->terminate($request, new FakeResponse(200));

        self::assertCount(1, $capture->events);
        self::assertSame('http_request', $capture->events[0]['name']);
        self::assertSame('/users/{id}', $capture->events[0]['properties']['route']);
        self::assertSame('GET', $capture->events[0]['properties']['method']);
        self::assertSame(200, $capture->events[0]['properties']['status']);
    }

    public function testLaravelServerErrorsAlsoCaptureHttpError(): void
    {
        $capture = new FakeCapture();
        $middleware = new TrackRequests(new Recorder($capture));

        $request = $this->laravelRequest('/broken', 'broken');
        $middleware->handle($request, static fn ($passed) => $passed);
        $middleware->terminate($request, new FakeResponse(500));

        self::assertCount(2, $capture->events);
        self::assertSame('http_error', $capture->events[1]['name']);
    }

    public function testIgnorePathsAndSamplingSuppressCapture(): void
    {
        $capture = new FakeCapture();
        $ignoring = new TrackRequests(new Recorder($capture, ['/health']));

        $request = $this->laravelRequest('/health', 'health');
        $ignoring->handle($request, static fn ($passed) => $passed);
        $ignoring->terminate($request, new FakeResponse(200));

        $sampledOut = new TrackRequests(new Recorder($capture, [], 0.0));
        $request = $this->laravelRequest('/users/1', 'users/{id}');
        $sampledOut->handle($request, static fn ($passed) => $passed);
        $sampledOut->terminate($request, new FakeResponse(200));

        self::assertSame([], $capture->events);
    }

    public function testActorClosureFeedsContextAndSurvivesThrows(): void
    {
        $capture = new FakeCapture();
        $recorder = new Recorder($capture, [], 1.0, static fn (): array => [
            'actor' => 'user-1',
            'actorLabel' => 'Ada',
        ]);
        $middleware = new TrackRequests($recorder);

        $request = $this->laravelRequest('/me', 'me');
        $middleware->handle($request, static fn ($passed) => $passed);
        $middleware->terminate($request, new FakeResponse(200));

        self::assertSame('user-1', $capture->events[0]['context']['actor']);
        self::assertSame('Ada', $capture->events[0]['context']['actorLabel']);

        $broken = new Recorder($capture, [], 1.0, static function (): array {
            throw new \RuntimeException('bad extractor');
        });
        $middleware = new TrackRequests($broken);
        $request = $this->laravelRequest('/me', 'me');
        $middleware->handle($request, static fn ($passed) => $passed);
        $middleware->terminate($request, new FakeResponse(200));

        self::assertSame([], $capture->events[1]['context']);
    }

    private function psrHandler(int $status): RequestHandlerInterface
    {
        return new class($status) implements RequestHandlerInterface {
            public function __construct(private readonly int $status)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                if ($this->status === 0) {
                    throw new \DomainException('kaboom');
                }

                return new PsrResponse($this->status);
            }
        };
    }

    public function testPsr15MiddlewareUsesTheTemplateResolver(): void
    {
        $capture = new FakeCapture();
        $middleware = new TopStatsMiddleware(
            new Recorder($capture),
            static fn (): string => '/things/{id}',
        );

        $request = (new Psr17Factory())->createServerRequest('POST', '/things/9');
        $response = $middleware->process($request, $this->psrHandler(201));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('/things/{id}', $capture->events[0]['properties']['route']);
        self::assertSame(201, $capture->events[0]['properties']['status']);
    }

    public function testPsr15ExceptionsAreCapturedAndRethrown(): void
    {
        $capture = new FakeCapture();
        $middleware = new TopStatsMiddleware(new Recorder($capture));
        $request = (new Psr17Factory())->createServerRequest('GET', '/x');

        try {
            $middleware->process($request, $this->psrHandler(0));
            self::fail('expected the exception to propagate');
        } catch (\DomainException) {
        }

        self::assertSame('http_request', $capture->events[0]['name']);
        self::assertSame('http_error', $capture->events[1]['name']);
        self::assertSame(\DomainException::class, $capture->events[1]['properties']['error']);
        self::assertSame('unmatched', $capture->events[0]['properties']['route']);
    }
}
