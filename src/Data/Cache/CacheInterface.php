<?php

declare(strict_types=1);

namespace Lockrot\Data\Cache;

use Lockrot\Data\Http\HttpResult;

/** @internal */
interface CacheInterface
{
    public function get(string $key): ?HttpResult;

    public function set(string $key, HttpResult $result): void;
}
