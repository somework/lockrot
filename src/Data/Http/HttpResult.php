<?php

declare(strict_types=1);

namespace Lockrot\Data\Http;

/** @internal */
final class HttpResult
{
    private string $url;
    private int $status;
    private ?string $body;
    private \DateTimeImmutable $fetchedAt;
    private ?string $error;
    /** Set on the copy that {@see asCached()} returns, never part of the envelope. */
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

    /**
     * Null when `fetched_at` is missing or does not parse: a stored answer without an age is no
     * answer, because {@see CachingHttpClient} decides freshness by it and the report prints it.
     *
     * @param array<string, mixed> $envelope
     */
    public static function fromEnvelope(string $url, array $envelope): ?self
    {
        $fetchedAt = $envelope['fetched_at'] ?? null;
        $at = \is_string($fetchedAt) ? self::parseDate($fetchedAt) : null;
        if ($at === null) {
            return null;
        }
        $body = $envelope['body'] ?? null;
        $error = $envelope['error'] ?? null;
        $status = $envelope['status'] ?? 0;

        return new self($url, \is_int($status) ? $status : 0, \is_string($body) ? $body : null, $at, \is_string($error) ? $error : null);
    }

    /** Null for invalid JSON, a non-object, a missing `status` or an unreadable `fetched_at`. */
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

    /**
     * Strict, in the format {@see toEnvelope()} writes: the loose constructor reads `""` as now, and
     * a stored answer of unknown age must not. A date that parses but is not real (a 30th of
     * February) comes back with a warning that getLastErrors() reports. Before PHP 8.2
     * getLastErrors() also returns an array when nothing is wrong, so the code counts warnings.
     */
    private static function parseDate(string $iso): ?\DateTimeImmutable
    {
        $at = \DateTimeImmutable::createFromFormat(\DATE_ATOM, $iso);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($at === false || ($errors !== false && $errors['warning_count'] > 0)) {
            return null;
        }

        return $at;
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

    public function fromCache(): bool
    {
        return $this->fromCache;
    }

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
