<?php

declare(strict_types=1);

namespace Lockrot\Data\Packagist;

use Lockrot\Data\Http\HttpClientInterface;
use Lockrot\Data\Http\HttpResult;

final class PackagistClient
{
    public const DEFAULT_BASE_URL = 'https://repo.packagist.org/p2/';

    private HttpClientInterface $http;
    private string $baseUrl;

    public function __construct(HttpClientInterface $http, string $baseUrl = self::DEFAULT_BASE_URL)
    {
        $this->http = $http;
        $this->baseUrl = $baseUrl;
    }

    public function url(string $name, bool $dev = false): string
    {
        return $this->baseUrl.$name.($dev ? '~dev' : '').'.json';
    }

    /**
     * Memory: p2 responses are large (a few MB each for symfony/*), so nothing is kept beyond the
     * scalars PackageMetadata needs. Each response is decoded, folded into a PackageMetadata and
     * dropped inside the loop; only packages with no tagged release survive into the `~dev` round.
     *
     * @param list<string> $names
     */
    public function fetch(array $names): PackagistBatch
    {
        if ($names === []) {
            return new PackagistBatch([], [], []);
        }
        $names = array_values(array_unique($names));

        $urls = [];
        foreach ($names as $name) {
            $urls[$name] = $this->url($name);
        }
        $responses = $this->http->fetchAll(array_values($urls));

        $metadata = [];
        $notFound = [];
        $failed = [];
        /** @var array<string, list<array<string, mixed>>> $pendingDev package => expanded stable versions (empty by construction) */
        $pendingDev = [];
        /** @var array<string, \DateTimeImmutable> $fetchedAt */
        $fetchedAt = [];
        foreach ($names as $name) {
            $result = $responses[$urls[$name]];
            unset($responses[$urls[$name]]);
            if ($result->isNotFound()) {
                $notFound[] = $name;
                continue;
            }
            $json = $this->decodeOk($result);
            if ($json === null) {
                $failed[$name] = $this->formatError($result);
                continue;
            }
            $at = $result->fetchedAt();
            unset($result);
            $stable = P2Expander::expandResponse($json, $name);
            unset($json);
            if ($stable === []) {
                $pendingDev[$name] = $stable;
                $fetchedAt[$name] = $at;
                continue;
            }
            $metadata[$name] = PackageMetadata::fromP2($name, $stable, [], $at);
            unset($stable);
        }
        unset($responses);

        foreach ($this->fetchDev($pendingDev, $failed) as $name => $devVersions) {
            $metadata[$name] = PackageMetadata::fromP2($name, $pendingDev[$name], $devVersions, $fetchedAt[$name]);
        }

        return new PackagistBatch($metadata, $notFound, $failed);
    }

    /**
     * @param array<string, list<array<string, mixed>>> $pendingDev
     * @param array<string, string>                     $failed     package => error, appended to in place
     *
     * @return array<string, list<array<string, mixed>>> package => expanded dev versions
     */
    private function fetchDev(array $pendingDev, array &$failed): array
    {
        if ($pendingDev === []) {
            return [];
        }
        $urls = [];
        foreach (array_keys($pendingDev) as $name) {
            $urls[$name] = $this->url($name, true);
        }
        $responses = $this->http->fetchAll(array_values($urls));

        $dev = [];
        foreach ($urls as $name => $url) {
            $result = $responses[$url];
            unset($responses[$url]);
            $dev[$name] = $this->classifyDevResult($name, $result, $failed);
        }

        return $dev;
    }

    /**
     * @param array<string, string> $failed package => error, appended to in place
     *
     * @return list<array<string, mixed>>
     */
    private function classifyDevResult(string $name, HttpResult $result, array &$failed): array
    {
        if ($result->isNotFound()) {
            return [];
        }
        $json = $this->decodeOk($result);
        if ($json === null) {
            $failed[$name] = '~dev: '.$this->formatError($result);

            return [];
        }

        return P2Expander::expandResponse($json, $name);
    }

    private function formatError(HttpResult $result): string
    {
        return $result->error() ?? ('HTTP '.$result->status());
    }

    /** @return array<string, mixed>|null */
    private function decodeOk(HttpResult $result): ?array
    {
        return $result->isOk() ? $result->json() : null;
    }
}
