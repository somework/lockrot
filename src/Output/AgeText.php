<?php

declare(strict_types=1);

namespace Lockrot\Output;

use Lockrot\Data\Repository\ReleaseBranch;
use Lockrot\Signal\AgeReading;
use Lockrot\Signal\Thresholds;
use Lockrot\Verdict\Finding;

/**
 * The `age:` line of the text formats and the age block of `--explain`, from `finding.age`.
 * Lines print years, never a level.
 *
 * @internal
 */
final class AgeText
{
    /** The installed reading a line leaves out: the S6 sentence names a branch snapshot, and no details means no reading. */
    private const SILENT_CAUSES = ['branch_snapshot', 'details_missing'];

    /** The years phrase: a published 0 is below 0.05 years, so it reads as less than three weeks. */
    public static function ago(float $years): string
    {
        return $years === 0.0 ? 'less than three weeks ago' : \sprintf('%.1F years ago', $years);
    }

    /**
     * The `age:` line of a finding, null when it has no measured reading. The caller decides whether
     * the finding prints one (graded, and none of S2, S4 and S8 fired).
     */
    public static function line(Finding $finding): ?string
    {
        $age = $finding->details()->age();
        $version = $finding->version();
        $branch = self::branchOf($version);
        $installed = $age->installed();
        $release = $age->release()->isMeasured() ? $age->release() : null;
        $branchRelease = $age->branchRelease()->isMeasured() ? $age->branchRelease() : null;
        $parts = [];
        if ($installed->isMeasured()) {
            $text = 'installed '.$version.' released '.self::when($installed);
            if ($release !== null && $release->version() === $version) {
                $text .= ', its newest release';
            } elseif ($branchRelease !== null && $branchRelease->version() === $version) {
                $text .= ', the newest on '.$branch;
            }
            $parts[] = $text;
        } elseif (!\in_array($installed->unmeasured(), self::SILENT_CAUSES, true)) {
            $parts[] = 'installed '.$version.', release date not known ('.CauseWords::of((string) $installed->unmeasured()).')';
        }
        if ($branchRelease !== null && $branchRelease->version() !== $version && ($release === null || $release->version() !== $branchRelease->version())) {
            $parts[] = $branch.' last released '.$branchRelease->version().' '.self::when($branchRelease);
        }
        if ($release !== null && $release->version() !== $version) {
            $parts[] = $branchRelease !== null && $branchRelease->version() === $release->version()
                ? $branch.' last released '.$release->version().' '.self::when($release).', its newest release'
                : 'newest release '.$release->version().' '.self::when($release);
        }
        if ($age->push()->isMeasured()) {
            $parts[] = 'last '.($finding->details()->activity()->event() ?? 'push').' '.self::when($age->push());
        }

        return $parts === [] ? null : 'age: '.implode('; ', $parts);
    }

    /**
     * The `age` block of `--explain`: every reading, measured or not, with the threshold
     * it passed, then the libyears line.
     *
     * @return list<string>
     */
    public static function block(Finding $finding, Thresholds $thresholds): array
    {
        $age = $finding->details()->age();
        $version = $finding->version();
        $event = $finding->details()->activity()->event() ?? 'push';
        $rows = [
            ['installed', $version, $age->installed(), null],
            [self::branchOf($version), $age->branchRelease()->version(), $age->branchRelease(), [$thresholds->releaseWarnYears(), $thresholds->releaseHighYears(), 'release']],
            ['newest', $age->release()->version(), $age->release(), [$thresholds->releaseWarnYears(), $thresholds->releaseHighYears(), 'release']],
            ['last '.$event, null, $age->push(), [$thresholds->pushWarnYears(), $thresholds->pushHighYears(), 'push']],
        ];
        $lines = [];
        foreach ($rows as [$name, $release, $reading, $limits]) {
            $head = str_pad($name, 11).str_pad($release ?? '', 10);
            if (!$reading->isMeasured()) {
                $lines[] = $head.'not read ('.CauseWords::of((string) $reading->unmeasured()).')';
                continue;
            }
            $words = [];
            if ($limits !== null) {
                $level = $reading->level();
                $words[] = ($level === null ? 'below '.$limits[2].'-warn-years '.$limits[0] : 'past '.$limits[2].'-'.$level.'-years '.($level === 'high' ? $limits[1] : $limits[0]));
            }
            if ($reading->datedBy() !== null) {
                $words[] = 'dated by '.$reading->datedBy();
            }
            $lines[] = rtrim($head.$reading->measuredAt()->format('Y-m-d').'   '.str_pad(self::ago((float) $reading->years()), 30).implode('; ', $words));
        }
        $libyears = $finding->libyears();
        $unmeasured = $finding->libyearsUnmeasured();
        $lines[] = $libyears === null
            ? 'libyears not measured: '.CauseWords::of((string) $unmeasured)
            : \sprintf('libyears %s%.1F behind the newest stable release', $finding->isLibyearsAtLeast() ? 'at least ' : '', $libyears);

        return $lines;
    }

    /** `finding.branch`, else a branch-alias snapshot's own line, else `its branch`. */
    public static function branchOf(string $version): string
    {
        $key = ReleaseBranch::of($version) ?? ReleaseBranch::aliasLine($version);

        return $key === null ? 'its branch' : ReleaseBranch::label($key);
    }

    private static function when(AgeReading $reading): string
    {
        $datedBy = $reading->datedBy();

        return $reading->measuredAt()->format('Y-m-d').' ('.self::ago((float) $reading->years()).($datedBy === null ? '' : ', dated by '.$datedBy).')';
    }
}
