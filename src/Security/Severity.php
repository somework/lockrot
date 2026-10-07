<?php

declare(strict_types=1);

namespace Lockrot\Security;

/**
 * The severity bucket of an advisory and the numbers each bucket carries. The
 * display order breaks ties and orders rows. The gate rank compares thresholds, where unrated
 * equals medium. Never swap the two.
 *
 * @internal
 */
final class Severity
{
    public const CRITICAL = 'critical';
    public const HIGH = 'high';
    public const MEDIUM = 'medium';
    public const UNRATED = 'unrated';
    public const LOW = 'low';

    /** @var list<self::CRITICAL|self::HIGH|self::MEDIUM|self::UNRATED|self::LOW> */
    public const DISPLAY_ORDER = [self::CRITICAL, self::HIGH, self::MEDIUM, self::UNRATED, self::LOW];

    /** @var array<self::CRITICAL|self::HIGH|self::MEDIUM|self::UNRATED|self::LOW, array{points: int, gate_rank: int, security_severity: ?string, sarif_rank: int}> */
    private const BUCKETS = [
        self::CRITICAL => ['points' => 32, 'gate_rank' => 4, 'security_severity' => '9.5', 'sarif_rank' => 95],
        self::HIGH => ['points' => 16, 'gate_rank' => 3, 'security_severity' => '8.0', 'sarif_rank' => 80],
        self::MEDIUM => ['points' => 8, 'gate_rank' => 2, 'security_severity' => '5.5', 'sarif_rank' => 55],
        self::UNRATED => ['points' => 8, 'gate_rank' => 2, 'security_severity' => null, 'sarif_rank' => 55],
        self::LOW => ['points' => 2, 'gate_rank' => 1, 'security_severity' => '2.0', 'sarif_rank' => 20],
    ];

    /** @var self::CRITICAL|self::HIGH|self::MEDIUM|self::UNRATED|self::LOW */
    private string $bucket;
    private ?string $published;

    /** @param self::CRITICAL|self::HIGH|self::MEDIUM|self::UNRATED|self::LOW $bucket */
    private function __construct(string $bucket, ?string $published)
    {
        $this->bucket = $bucket;
        $this->published = $published;
    }

    /** `moderate` is medium. A missing, empty or unknown value is unrated. */
    public static function fromComposer(?string $severity): self
    {
        $word = strtolower(trim((string) $severity));
        if ($word === 'moderate') {
            $word = self::MEDIUM;
        }

        return new self(\in_array($word, [self::CRITICAL, self::HIGH, self::MEDIUM, self::LOW], true) ? $word : self::UNRATED, $severity);
    }

    /** @return self::CRITICAL|self::HIGH|self::MEDIUM|self::UNRATED|self::LOW */
    public function bucket(): string
    {
        return $this->bucket;
    }

    /** The raw word the advisory carries, `severity_published`. */
    public function published(): ?string
    {
        return $this->published;
    }

    public function points(): int
    {
        return self::BUCKETS[$this->bucket]['points'];
    }

    public function gateRank(): int
    {
        return self::BUCKETS[$this->bucket]['gate_rank'];
    }

    /** SARIF's `security-severity` is a string. Null for unrated: SARIF then omits the property. */
    public function sarifSecuritySeverity(): ?string
    {
        return self::BUCKETS[$this->bucket]['security_severity'];
    }

    public function sarifRank(): int
    {
        return self::BUCKETS[$this->bucket]['sarif_rank'];
    }

    /** The bucket's index in {@see self::DISPLAY_ORDER}: lower comes first. */
    public function displayPosition(): int
    {
        return (int) array_search($this->bucket, self::DISPLAY_ORDER, true);
    }
}
