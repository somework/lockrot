<?php

declare(strict_types=1);

namespace Lockrot\Legacy;

use Lockrot\Data\Advisory\Advisory;

/**
 * The advisory facts that report-1 wrote in S9's data and report-2 does not: per advisory the
 * highest tag outside its range (`fixed_by`), whether that tag is on the installed branch
 * (`fixed_on_branch`) and Composer's raw severity word. No report-2 field holds that tag. The
 * report-1 readers (the priority, the no-fix clause, the evidence and the `--explain` text) read
 * these facts until they move to report-2's fields.
 *
 * @internal
 *
 * @phpstan-type Row013 array{id: string, cve: ?string, title: ?string, link: ?string, severity: ?string, reported_at: ?string, affected_versions: ?string, fixed_by: ?string, fixed_on_branch: bool}
 */
final class AdvisoryFacts013
{
    /** @var list<Row013> */
    private array $rows;
    private bool $releasesRead;

    /** @param list<Row013> $rows in report-1's order: worst severity first, then the repository's own order */
    public function __construct(array $rows, bool $releasesRead)
    {
        $this->rows = $rows;
        $this->releasesRead = $releasesRead;
    }

    public static function none(): self
    {
        return new self([], false);
    }

    /**
     * @param list<array{Advisory, ?string, bool}> $fixes per advisory in report-1's order: the highest tag outside its range, and
     *                                                    whether that tag is on the installed branch
     */
    public static function of(array $fixes, bool $releasesRead): self
    {
        return new self(array_map(static fn (array $fix): array => self::row(...$fix), $fixes), $releasesRead);
    }

    /** @return Row013 */
    private static function row(Advisory $advisory, ?string $fixedBy, bool $onBranch): array
    {
        return $advisory->toArray() + ['fixed_by' => $fixedBy, 'fixed_on_branch' => $onBranch];
    }

    /** @return list<Row013> */
    public function rows(): array
    {
        return $this->rows;
    }

    /** Whether the releases were read and compared with each advisory's range. */
    public function releasesRead(): bool
    {
        return $this->releasesRead;
    }
}
