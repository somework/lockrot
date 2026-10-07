<?php

declare(strict_types=1);

namespace Lockrot\Allowlist;

use Composer\Semver\VersionParser;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\ScoreModel;

/** @internal */
final class AllowlistEntry
{
    private const LIVENESS = [FlagSet::ABANDONED, FlagSet::SILENT, FlagSet::STALE];

    private static ?VersionParser $parser = null;

    private string $pattern;
    private ?string $version;
    private string $reason;
    private ?\DateTimeImmutable $expires;
    private string $source;
    /** @var list<string>|null */
    private ?array $flags;

    /** @param list<string>|null $flags the maintenance flags the entry accepts, null for every one */
    public function __construct(string $pattern, ?string $version, string $reason, ?\DateTimeImmutable $expires, string $source, ?array $flags = null)
    {
        $this->pattern = $pattern;
        $this->version = $version;
        $this->reason = $reason;
        $this->expires = $expires;
        $this->source = $source;
        $this->flags = $flags;
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

    /** @return list<string>|null null when the entry accepts every maintenance flag */
    public function flags(): ?array
    {
        return $this->flags;
    }

    public function acceptsAll(): bool
    {
        return $this->flags === null;
    }

    /**
     * A listed liveness word also accepts the words after it: `abandoned` accepts `silent` and
     * `stale`, and `silent` accepts `stale`. No entry accepts `vulnerable`.
     */
    public function accepts(string $flag): bool
    {
        if (!isset(ScoreModel::POINTS[$flag])) {
            return false;
        }
        if ($this->flags === null || \in_array($flag, $this->flags, true)) {
            return true;
        }
        $at = array_search($flag, self::LIVENESS, true);

        return $at !== false && array_intersect(\array_slice(self::LIVENESS, 0, $at), $this->flags) !== [];
    }
}
