<?php

declare(strict_types=1);

namespace Lockrot\Data\Repository;

/**
 * One stable release that {@see PackageMetadata} keeps for the release scan (SPEC-0.14 5.3,
 * DECISIONS.md 2.34). Five fields and nothing else: a large package keeps one per release.
 *
 * @internal
 */
final class StableRelease
{
    private string $normalized;
    private string $pretty;
    private ?\DateTimeImmutable $at;
    private ?string $php;
    private bool $sharedCommit;

    /**
     * @param ?\DateTimeImmutable $at  null when the release has no trusted date: no `time`, or a shared commit that no monorepo parent dates
     * @param ?string             $php the release's own `require.php` as written, null when it declares none
     */
    public function __construct(string $normalized, string $pretty, ?\DateTimeImmutable $at, ?string $php, bool $sharedCommit)
    {
        $this->normalized = $normalized;
        $this->pretty = $pretty;
        $this->at = $at;
        $this->php = $php;
        $this->sharedCommit = $sharedCommit;
    }

    public function normalized(): string
    {
        return $this->normalized;
    }

    public function pretty(): string
    {
        return $this->pretty;
    }

    public function at(): ?\DateTimeImmutable
    {
        return $this->at;
    }

    public function php(): ?string
    {
        return $this->php;
    }

    /** Whether {@see PackageMetadata::SHARED_COMMIT_TAGS} or more stable tags share the release's commit. */
    public function sharedCommit(): bool
    {
        return $this->sharedCommit;
    }

    public function withDate(?\DateTimeImmutable $at): self
    {
        $copy = clone $this;
        $copy->at = $at;

        return $copy;
    }

    /** @return array{normalized: string, pretty: string, at: ?string, php: ?string, shared_commit: bool} */
    public function toArray(): array
    {
        return [
            'normalized' => $this->normalized,
            'pretty' => $this->pretty,
            'at' => $this->at === null ? null : $this->at->format(\DATE_ATOM),
            'php' => $this->php,
            'shared_commit' => $this->sharedCommit,
        ];
    }
}
