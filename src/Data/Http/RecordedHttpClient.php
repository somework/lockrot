<?php

declare(strict_types=1);

namespace Lockrot\Data\Http;

/** @internal */
final class RecordedHttpClient implements HttpClientInterface
{
    private string $dir;

    public function __construct(string $dir)
    {
        // Kept as given: pathFor() trims the trailing separator.
        $this->dir = $dir;
    }

    public static function pathFor(string $dir, string $url): string
    {
        return rtrim($dir, '/\\').'/'.sha1($url).'.json';
    }

    /**
     * @param list<string> $urls
     * @param list<string> $headers ignored: a recording replays for any headers
     * @return array<string, HttpResult>
     */
    public function fetchAll(array $urls, array $headers = []): array
    {
        $results = [];
        foreach ($urls as $url) {
            $path = self::pathFor($this->dir, $url);
            $raw = is_file($path) ? file_get_contents($path) : false;
            $result = HttpResult::fromEnvelopeJson($url, $raw === false ? null : $raw);
            $results[$url] = $result ?? HttpResult::failure($url, 'not recorded: '.$url, new \DateTimeImmutable('@0'));
        }

        return $results;
    }
}
