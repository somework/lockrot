<?php

declare(strict_types=1);

namespace Lockrot\Data\Http;

/** Replays HTTP responses recorded on disk, so an acceptance run needs no network. */
final class RecordedHttpClient implements HttpClientInterface
{
    private string $dir;

    public function __construct(string $dir)
    {
        // Kept as given; pathFor() is the only reader and trims the trailing separator itself.
        $this->dir = $dir;
    }

    public static function pathFor(string $dir, string $url): string
    {
        return rtrim($dir, '/\\').'/'.sha1($url).'.json';
    }

    /**
     * @param list<string> $urls
     * @param list<string> $headers ignored; recordings are headers-agnostic playback
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
