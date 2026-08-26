<?php

declare(strict_types=1);

namespace TopStats\Laravel;

use TopStats\Analytics\TopStats;

final class SdkCapture implements Capture
{
    public function __construct(private readonly TopStats $client)
    {
    }

    public function capture(string $name, array $properties = [], array $context = []): void
    {
        $this->client->capture($name, $properties, $context);
    }

    public function flush(): void
    {
        $this->client->flush();
    }
}
