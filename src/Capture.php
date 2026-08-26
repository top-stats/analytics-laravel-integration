<?php

declare(strict_types=1);

namespace TopStats\Laravel;

/**
 * The slice of the SDK the middlewares need, so a fake works in tests and an
 * existing client can be shared. The real client satisfies it structurally
 * through the SdkCapture adapter.
 */
interface Capture
{
    /**
     * @param array<string, mixed> $properties
     * @param array<string, mixed> $context
     */
    public function capture(string $name, array $properties = [], array $context = []): void;

    public function flush(): void;
}
