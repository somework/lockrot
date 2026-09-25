<?php

declare(strict_types=1);

namespace Lockrot\Data\Http;

/** @internal */
interface HttpClientInterface
{
    /**
     * @param list<string> $urls
     * @param list<string> $headers raw "Name: value" lines applied to every request in the batch
     * @return array<string, HttpResult> keyed by URL; every requested URL is present
     */
    public function fetchAll(array $urls, array $headers = []): array;
}
