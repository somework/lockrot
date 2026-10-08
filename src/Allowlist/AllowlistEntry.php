<?php

declare(strict_types=1);

namespace Lockrot\Allowlist;

use Composer\Semver\VersionParser;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\ScoreModel;

/** @internal */
final class AllowlistEntry
{
    /** Who accepted: the built-in finished list, the project's `extra.lockrot.ignore[]`, or a package type. */
    public const BY_BUILTIN = 'builtin';
    public const BY_PROJECT = 'project';
    public const BY_TYPE = 'type';

    /** Whose words the reason is. */
    public const REASON_BY_USER = 'user';
    public const REASON_BY_LOCKROT = 'lockrot';

    private const LIVENESS = [FlagSet::ABANDONED, FlagSet::SILENT, FlagSet::STALE];

    private static ?VersionParser $parser = null;

    /** Null on a type entry, which matches a package type, not a name. */
    private ?string $pattern;
    private ?string $version;
    private string $reason;
    private ?\DateTimeImmutable $expires;
    private string $source;
    /** @var list<string>|null */
    private ?array $flags;
    private ?string $reasonId;

    /**
     * @param string            $source   one of the `BY_*` constants
     * @param list<string>|null $flags    the maintenance flags the entry accepts, null for every one
     * @param ?string           $reasonId the id of a reason lockrot wrote, null for a user's reason
     */
    public function __construct(?string $pattern, ?string $version, string $reason, ?\DateTimeImmutable $expires, string $source, ?array $flags = null, ?string $reasonId = null)
    {
        $this->pattern = $pattern;
        $this->version = $version;
        $this->reason = $reason;
        $this->expires = $expires;
        $this->source = $source;
        $this->flags = $flags;
        $this->reasonId = $reasonId;
    }

    /** The entry lockrot makes for a package of a type that only lists dependencies. */
    public static function forType(string $type): self
    {
        return new self(null, null, 'package type "'.$type.'" only lists dependencies', null, self::BY_TYPE, null, 'type-'.$type);
    }

    public function matches(string $name, string $version): bool
    {
        if ($this->pattern === null || !fnmatch($this->pattern, $name)) {
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

    public function pattern(): ?string
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

    /** One of the `BY_*` constants. */
    public function by(): string
    {
        return $this->source;
    }

    /** A project entry holds the user's words, every other entry lockrot's. */
    public function reasonBy(): string
    {
        return $this->source === self::BY_PROJECT ? self::REASON_BY_USER : self::REASON_BY_LOCKROT;
    }

    public function reasonId(): ?string
    {
        return $this->reasonId;
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

    /** @return array{by: string, pattern: ?string, version: ?string, reason: string, reason_id: ?string, reason_by: string, expires: ?string, flag_ids: list<string>|null} */
    public function toArray(): array
    {
        return [
            'by' => $this->source,
            'pattern' => $this->pattern,
            'version' => $this->version,
            'reason' => $this->reason,
            'reason_id' => $this->reasonId,
            'reason_by' => $this->reasonBy(),
            'expires' => $this->expires === null ? null : $this->expires->format('Y-m-d'),
            'flag_ids' => $this->flags,
        ];
    }
}
