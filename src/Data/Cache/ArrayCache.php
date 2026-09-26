<?php

declare(strict_types=1);

namespace Lockrot\Data\Cache;

use Lockrot\Data\Http\HttpResult;

/** @internal */
final class ArrayCache implements CacheInterface
{
    /** @var array<string, HttpResult> */
    private array $items = [];

    public function get(string $key): ?HttpResult
    {
        return $this->items[$key] ?? null;
    }

    public function set(string $key, HttpResult $result): void
    {
        $this->items[$key] = $result;
    }
}
