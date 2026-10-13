<?php

declare(strict_types=1);

namespace Lockrot\Analyzer;

use Lockrot\Security\Severity;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\ScoreModel;

/**
 * report-2's root `security` block, counted from the findings in the written order.
 *
 * @internal
 */
final class SecurityTotals implements \JsonSerializable
{
    private string $check;
    /** @var array<string, int> by security status, then `ignored` */
    private array $packages;
    private int $counted;
    private int $ignored;
    /** @var array<string, int> in {@see Severity::DISPLAY_ORDER} */
    private array $severities;
    /** @var array<string, int> vulnerable packages by fix kind, in {@see ScoreModel::FIX_KINDS} */
    private array $fixes;
    /** @var list<string> */
    private array $updateNow;

    /**
     * @param array<string, int> $packages
     * @param array<string, int> $severities
     * @param array<string, int> $fixes
     * @param list<string>       $updateNow
     */
    private function __construct(string $check, array $packages, int $counted, int $ignored, array $severities, array $fixes, array $updateNow)
    {
        $this->check = $check;
        $this->packages = $packages;
        $this->counted = $counted;
        $this->ignored = $ignored;
        $this->severities = $severities;
        $this->fixes = $fixes;
        $this->updateNow = $updateNow;
    }

    /** @param list<Finding> $findings */
    public static function of(array $findings): self
    {
        $checks = [];
        $packages = ['vulnerable' => 0, 'unchecked' => 0, 'ignored' => 0, 'clear' => 0];
        $advisories = ['counted' => 0, 'ignored' => 0];
        $severities = array_fill_keys(Severity::DISPLAY_ORDER, 0);
        $fixes = array_fill_keys(ScoreModel::FIX_KINDS, 0);
        foreach ($findings as $finding) {
            $standing = $finding->securityStanding();
            $checks[$standing->check()] = $standing->check();
            ++$packages[$standing->status()];
            $packages['ignored'] += $standing->ignoredCount() > 0 ? 1 : 0;
            $advisories['ignored'] += $standing->ignoredCount();
            if (!$standing->isVulnerable()) {
                continue;
            }
            $kind = $standing->fixKind();
            foreach ($standing->counts() as $severity => $count) {
                $severities[$severity] = ($severities[$severity] ?? 0) + $count;
                $advisories['counted'] += $count;
            }
            $fixes[$kind] = ($fixes[$kind] ?? 0) + 1;
        }

        return new self(\count($checks) === 1 ? reset($checks) : ($checks === [] ? 'complete' : 'partial'), $packages, $advisories['counted'], $advisories['ignored'], $severities, $fixes, Report2Root::updateNow($findings));
    }

    public function check(): string
    {
        return $this->check;
    }

    public function vulnerablePackages(): int
    {
        return $this->packages['vulnerable'];
    }

    public function countedAdvisories(): int
    {
        return $this->counted;
    }

    /** One of {@see Severity::DISPLAY_ORDER}. */
    public function advisoriesOf(string $severity): int
    {
        return $this->severities[$severity];
    }

    /** One of {@see ScoreModel::FIX_KINDS}. */
    public function packagesFixedBy(string $kind): int
    {
        return $this->fixes[$kind];
    }

    /** @return array{check: string, packages: array<string, int>, advisories: array{counted: int, ignored: int}, severities: array<string, int>, fixes: array<string, int>, update_now: list<string>, update_now_command: ?list<string>, fix_unknown: int} */
    public function jsonSerialize(): array
    {
        return [
            'check' => $this->check,
            'packages' => $this->packages,
            'advisories' => ['counted' => $this->counted, 'ignored' => $this->ignored],
            'severities' => $this->severities,
            'fixes' => $this->fixes,
            'update_now' => $this->updateNow,
            'update_now_command' => $this->updateNow === [] ? null : array_merge(['composer', 'update'], $this->updateNow),
            'fix_unknown' => $this->fixes['unknown'],
        ];
    }
}
