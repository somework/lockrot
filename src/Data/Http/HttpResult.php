<?php

declare(strict_types=1);

namespace Lockrot\Data\Http;

final class HttpResult
{
    private string $url;
    private int $status;
    private ?string $body;
    private \DateTimeImmutable $fetchedAt;
    private ?string $error;
    /** Set on the copy {@see asCached()} returns; never part of the envelope. */
    private bool $fromCache = false;

    public function __construct(string $url, int $status, ?string $body, \DateTimeImmutable $fetchedAt, ?string $error = null)
    {
        $this->url = $url;
        $this->status = $status;
        $this->body = $body;
        $this->fetchedAt = $fetchedAt;
        $this->error = $error;
    }

    public static function failure(string $url, string $error, \DateTimeImmutable $at): self
    {
        return new self($url, 0, null, $at, $error);
    }

    /** @param array<string, mixed> $envelope */
    public static function fromEnvelope(string $url, array $envelope): self
    {
        $fetchedAt = $envelope['fetched_at'] ?? null;
        $at = \is_string($fetchedAt) ? self::parseDate($fetchedAt) : new \DateTimeImmutable('@0');
        $body = $envelope['body'] ?? null;
        $error = $envelope['error'] ?? null;
        $status = $envelope['status'] ?? 0;

        return new self($url, \is_int($status) ? $status : 0, \is_string($body) ? $body : null, $at, \is_string($error) ? $error : null);
    }

    /**
     * Decodes a raw JSON envelope and returns the HttpResult it describes, or null
     * when the JSON is invalid, not an object, or missing the required `status` field.
     */
    public static function fromEnvelopeJson(string $url, ?string $raw): ?self
    {
        if ($raw === null) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!\is_array($decoded) || !isset($decoded['status'])) {
            return null;
        }
        /** @var array<string, mixed> $envelope */
        $envelope = $decoded;

        return self::fromEnvelope($url, $envelope);
    }

    private static function parseDate(string $iso): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($iso);
        } catch (\Exception $e) {
            return new \DateTimeImmutable('@0');
        }
    }

    /** @return array{status: int, fetched_at: string, body: ?string, error: ?string} */
    public function toEnvelope(): array
    {
        return ['status' => $this->status, 'fetched_at' => $this->fetchedAt->format(\DATE_ATOM), 'body' => $this->body, 'error' => $this->error];
    }

    public function url(): string
    {
        return $this->url;
    }
    public function status(): int
    {
        return $this->status;
    }
    public function body(): ?string
    {
        return $this->body;
    }
    public function fetchedAt(): \DateTimeImmutable
    {
        return $this->fetchedAt;
    }
    public function error(): ?string
    {
        return $this->error;
    }

    /** Whether this answer was served from lockrot's cache rather than fetched in this run. */
    public function fromCache(): bool
    {
        return $this->fromCache;
    }

    /** The same answer, marked as served from the cache; this instance is left unchanged. */
    public function asCached(): self
    {
        $copy = clone $this;
        $copy->fromCache = true;

        return $copy;
    }

    public function isOk(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
    public function isNotFound(): bool
    {
        return $this->status === 404;
    }

    public function isFailure(): bool
    {
        return $this->status === 0 || $this->status >= 500 || $this->status === 403 || $this->status === 429 || $this->status === 401;
    }

    /** @return array<string, mixed>|null */
    public function json(): ?array
    {
        if ($this->body === null) {
            return null;
        }
        $decoded = json_decode($this->body, true);
        if (!\is_array($decoded)) {
            return null;
        }
        /** @var array<string, mixed> $result */
        $result = $decoded;

        return $result;
    }
}
