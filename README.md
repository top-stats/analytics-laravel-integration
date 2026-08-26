# TopStats for Laravel (and any PSR-15 stack)

Official TopStats Analytics integration for PHP web apps, on the
`topstats/analytics` SDK. Two ways in: a Laravel package, and a PSR-15
middleware for Symfony (PSR bridge), Slim, Mezzio and friends.

Both capture an `http_request` event per request - route **template**
(`/users/{id}`, never the raw URL), method, status, duration - plus
`http_error` on 5xx and exceptions. Optional actor extraction, ignore paths,
sampling. Capture never throws into your request path.

## Laravel

```sh
composer require topstats/laravel
php artisan vendor:publish --tag=topstats-config
```

Set `TOPSTATS_API_KEY` in `.env`, then register the middleware globally
(`bootstrap/app.php` on Laravel 11+):

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\TopStats\Laravel\Middleware\TrackRequests::class);
})
```

Capture happens in `terminate()`, after the response has left. Custom events
anywhere via the container:

```php
app(\TopStats\Laravel\Capture::class)->capture('order_placed', ['total' => 42]);
```

The honest PHP story: no background threads, so the SDK flushes on its buffer
threshold, on app termination, and in `__destruct`. Under Octane the buffer
survives across requests and batching genuinely pays off.

## PSR-15

```php
use TopStats\Laravel\{Recorder, SdkCapture};
use TopStats\Laravel\Psr15\TopStatsMiddleware;
use TopStats\Analytics\TopStats;

$recorder = new Recorder(new SdkCapture(new TopStats($apiKey)));
$app->add(new TopStatsMiddleware($recorder, fn ($request) => /* your route template */));
```

PSR routing is not standardized, so pass a template resolver for your router;
without one requests group under `unmatched` rather than leaking raw paths.

Docs: https://docs.topstats.gg/docs/analytics
