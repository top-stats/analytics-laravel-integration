<?php

declare(strict_types=1);

namespace TopStats\Laravel;

use Illuminate\Support\ServiceProvider;
use TopStats\Analytics\TopStats;

final class TopStatsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/topstats.php', 'topstats');

        $this->app->singleton(TopStats::class, function ($app): TopStats {
            $config = $app['config']['topstats'];
            $options = ['defaultSource' => $config['source'] ?? 'laravel'];

            if (!empty($config['host'])) {
                $options['host'] = $config['host'];
            }

            return new TopStats((string) ($config['api_key'] ?? ''), $options);
        });

        $this->app->singleton(Capture::class, function ($app): Capture {
            return new SdkCapture($app->make(TopStats::class));
        });

        $this->app->singleton(Recorder::class, function ($app): Recorder {
            $config = $app['config']['topstats'];

            return new Recorder(
                $app->make(Capture::class),
                $config['ignore_paths'] ?? [],
                (float) ($config['sample_rate'] ?? 1.0),
                $config['actor'] ?? null,
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/topstats.php' => $this->app->configPath('topstats.php'),
        ], 'topstats-config');

        // PHP has no background thread: the SDK flushes on its buffer
        // threshold and in __destruct, and this hook covers graceful
        // terminations explicitly.
        $this->app->terminating(function (): void {
            if ($this->app->resolved(Capture::class)) {
                $this->app->make(Capture::class)->flush();
            }
        });
    }
}
