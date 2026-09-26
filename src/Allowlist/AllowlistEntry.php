<?php

declare(strict_types=1);

namespace Lockrot\Allowlist;

use Composer\Semver\VersionParser;

/** @internal */
final class AllowlistEntry
{
    private static ?VersionParser $parser = null;

    private string $pattern;
    private ?string $version;
    private string $reason;
    private ?\DateTimeImmutable $expires;
    private string $source;

    public function __construct(string $pattern, ?string $version, string $reason, ?\DateTimeImmutable $expires, string $source)
    {
        $this->pattern = $pattern;
        $this->version = $version;
        $this->reason = $reason;
        $this->expires = $expires;
        $this->source = $source;
    }

    public function matches(string $name, string $version): bool
    {
        if (!fnmatch($this->pattern, $name)) {
            return false;
        }
        if ($this->version === null) {
            return true;
        }
        $parser = self::$parser ??= new VersionParser();
        try {
            return $parser->normalize($this->version) === $parser->normalize($version);
        } catch (\UnexpectedValueException $e) {
            return $this->version === $version;
        }
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $this->expires !== null && $this->expires < $now;
    }

    public function pattern(): string
    {
        return $this->pattern;
    }

    public function version(): ?string
    {
        return $this->version;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function expires(): ?\DateTimeImmutable
    {
        return $this->expires;
    }

    public function source(): string
    {
        return $this->source;
    }
}
