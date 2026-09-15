<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Baseline\Baseline;
use Lockrot\Baseline\BaselineComparison;
use Lockrot\Baseline\BaselineEntry;
use Lockrot\Config\LockrotConfig;
use Lockrot\Exception\ConfigException;
use Lockrot\Output\FormatContext;
use Lockrot\Output\Formatters;
use Lockrot\Output\GitlabFormatter;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\TestCase;

final class GitlabFormatterTest extends TestCase
{
    private const AT = '2026-09-14T06:00:00+00:00';

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            if (is_file($dir.'/composer.lock')) {
                unlink($dir.'/composer.lock');
            }
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
        $this->tempDirs = [];
    }

    /** acme/abandoned is on line 4 of the lock written here, acme/silent on line 8. */
    private function lockPath(): string
    {
        $dir = sys_get_temp_dir().'/lockrot-gitlab-'.uniqid('', true);
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create temp dir: '.$dir);
        }
        $this->tempDirs[] = $dir;
        file_put_contents($dir.'/composer.lock', <<<'JSON'
            {
                "packages": [
                    {
                        "name": "acme/abandoned",
                        "version": "1.0.0"
                    },
                    {
                        "name": "acme/silent",
                        "version": "2.0.8"
                    }
                ],
                "packages-dev": []
            }
            JSON);

        return $dir.'/composer.lock';
    }

    private function report(): Report
    {
        $at = new \DateTimeImmutable(self::AT);

        return new Report([
            new Finding('acme/abandoned', '1.0.0', Verdict::ABANDONED, [new Signal('S1', 'high', 'marked abandoned by its repository')], ['acme/abandoned'], null, $at),
            new Finding('acme/silent', '2.0.8', Verdict::SILENT, [new Signal('S2', 'high', 'last release 2015-11-16 (10.8 years ago)')], ['a/parent', 'acme/silent'], null, $at),
            new Finding('acme/absent', '3.0.0', Verdict::STALE, [new Signal('S2', 'warn', 'last release 2022-05-20 (4.3 years ago)')], ['acme/absent'], null, $at),
            new Finding('acme/fine', '4.0.0', Verdict::OK, [], ['acme/fine'], null, $at),
        ], ['GitHub token not set: repository activity checked only for 2 candidate packages'], $at, 4, 0, false);
    }

    private function formatter(string $failOn, ?string $lockPath): GitlabFormatter
    {
        return new GitlabFormatter(FormatContext::create($lockPath, $failOn));
    }

    /** @return list<array<string, mixed>> */
    private function decode(string $json): array
    {
        self::assertStringEndsWith("\n", $json);
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);

        /** @var list<array<string, mixed>> $decoded */
        return $decoded;
    }

    public function testShape(): void
    {
        $issues = $this->decode($this->formatter(Verdict::SILENT, $this->lockPath())->format($this->report()));

        // acme/absent (stale) is also flagged: Verdict::flagged() includes stale and above.
        self::assertCount(3, $issues);
        $first = $issues[0];
        self::assertSame('issue', $first['type']);
        self::assertSame('lockrot/abandoned', $first['check_name']);
        self::assertSame('acme/abandoned 1.0.0 — abandoned (critical): marked abandoned by its repository', $first['description']);
        self::assertSame(['Bug Risk'], $first['categories']);
        self::assertSame('major', $first['severity']);
        self::assertSame(hash('sha256', 'lockrot|acme/abandoned|abandoned'), $first['fingerprint']);
        self::assertSame('composer.lock', JsonPath::stringAt($first, ['location', 'path']));
        self::assertSame(4, JsonPath::intAt($first, ['location', 'lines', 'begin']));

        $second = $issues[1];
        self::assertSame('acme/silent 2.0.8 — silent (high): last release 2015-11-16 (10.8 years ago) (via a/parent)', $second['description']);
        self::assertSame(8, JsonPath::intAt($second, ['location', 'lines', 'begin']));
    }

    public function testSeverityMappingAtTheFailOnBoundary(): void
    {
        $lockPath = $this->lockPath();

        $atSilent = $this->decode($this->formatter(Verdict::SILENT, $lockPath)->format($this->report(), true));
        self::assertSame(['major', 'major', 'minor', 'info'], array_column($atSilent, 'severity'));

        $atAbandoned = $this->decode($this->formatter(Verdict::ABANDONED, $lockPath)->format($this->report(), true));
        self::assertSame(['major', 'minor', 'minor', 'info'], array_column($atAbandoned, 'severity'));

        $atNone = $this->decode($this->formatter(LockrotConfig::FAIL_ON_NONE, $lockPath)->format($this->report(), true));
        self::assertSame(['minor', 'minor', 'minor', 'info'], array_column($atNone, 'severity'));
    }

    public function testABaselinedFindingIsDemotedToInfo(): void
    {
        $report = $this->report();
        $names = [];
        foreach ($report->findings() as $finding) {
            $names[] = $finding->package();
        }
        $baseline = Baseline::of([
            new BaselineEntry('acme/abandoned', '1.0.0', Verdict::ABANDONED, '2026-01-15'),
        ], self::AT);
        $withBaseline = $report->withBaseline(BaselineComparison::compare($baseline, $report, 'lockrot-baseline.json', $names));

        $issues = $this->decode($this->formatter(Verdict::SILENT, $this->lockPath())->format($withBaseline));

        self::assertSame('info', $issues[0]['severity'], 'known finding is demoted to info');
        self::assertSame('major', $issues[1]['severity'], 'a finding the baseline does not know stays at its normal severity');
    }

    public function testFingerprintIsStableAcrossRunsAndDifferentPerVerdict(): void
    {
        $lockPath = $this->lockPath();
        $first = $this->decode($this->formatter(Verdict::SILENT, $lockPath)->format($this->report()));
        $second = $this->decode($this->formatter(Verdict::SILENT, $lockPath)->format($this->report()));

        self::assertSame($first[0]['fingerprint'], $second[0]['fingerprint'], 'same package/verdict must hash the same across runs');
        self::assertNotSame($first[0]['fingerprint'], $first[1]['fingerprint'], 'different verdicts must not collide');
    }

    /**
     * The same lock with one extra package above acme/abandoned, so the package GitLab is told about
     * sits on line 8 here rather than line 4.
     */
    private function shiftedLockPath(): string
    {
        $dir = sys_get_temp_dir().'/lockrot-gitlab-'.uniqid('', true);
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create temp dir: '.$dir);
        }
        $this->tempDirs[] = $dir;
        file_put_contents($dir.'/composer.lock', <<<'JSON'
            {
                "packages": [
                    {
                        "name": "acme/newcomer",
                        "version": "1.0.0"
                    },
                    {
                        "name": "acme/abandoned",
                        "version": "9.9.9"
                    }
                ],
                "packages-dev": []
            }
            JSON);

        return $dir.'/composer.lock';
    }

    /**
     * The identity GitLab tracks an issue by must survive a version bump and a reformatted lock:
     * the fingerprint is the package and the verdict, nothing else.
     */
    public function testFingerprintIgnoresTheVersionAndTheLockLine(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $signal = new Signal('S1', 'high', 'marked abandoned by its repository');
        $before = new Report([new Finding('acme/abandoned', '1.0.0', Verdict::ABANDONED, [$signal], ['acme/abandoned'], null, $at)], [], $at, 1, 0, false);
        $after = new Report([new Finding('acme/abandoned', '9.9.9', Verdict::ABANDONED, [$signal], ['acme/abandoned'], null, $at)], [], $at, 1, 0, false);

        $first = $this->decode($this->formatter(Verdict::SILENT, $this->lockPath())->format($before));
        $second = $this->decode($this->formatter(Verdict::SILENT, $this->shiftedLockPath())->format($after));

        self::assertSame(4, JsonPath::intAt($first[0], ['location', 'lines', 'begin']));
        self::assertSame(8, JsonPath::intAt($second[0], ['location', 'lines', 'begin']));
        self::assertNotSame($first[0]['description'], $second[0]['description'], 'the version really did change');
        self::assertSame($first[0]['fingerprint'], $second[0]['fingerprint']);
    }

    public function testTheBaselineStaleNoteIsNotRepresentableAndIsDropped(): void
    {
        $report = $this->report();
        $baseline = Baseline::of([new BaselineEntry('acme/departed', '1.0.0', Verdict::ABANDONED, '2026-01-15')], self::AT);
        $withBaseline = $report->withBaseline(BaselineComparison::compare($baseline, $report, 'lockrot-baseline.json', ['acme/abandoned']));

        $out = $this->formatter(Verdict::SILENT, $this->lockPath())->format($withBaseline);

        self::assertStringNotContainsString('acme/departed', $out, 'GitLab Code Quality has no field for a document-level note');
    }

    public function testLinesBeginFallsBackToOneWithoutALockPath(): void
    {
        $issues = $this->decode($this->formatter(Verdict::SILENT, null)->format($this->report()));

        foreach ($issues as $issue) {
            self::assertSame(1, JsonPath::intAt($issue, ['location', 'lines', 'begin']));
        }
    }

    public function testEmptyReportIsAnEmptyArray(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $out = $this->formatter(Verdict::SILENT, $this->lockPath())->format(new Report([], [], $at, 0, 0, false));

        self::assertSame("[]\n", $out);
    }

    public function testShowAllIncludesUnflaggedRows(): void
    {
        $issues = $this->decode($this->formatter(Verdict::SILENT, $this->lockPath())->format($this->report(), true));
        self::assertCount(4, $issues);
        self::assertSame('lockrot/ok', $issues[3]['check_name']);
    }

    /**
     * Code Quality has no title field of its own, so the description carries the same
     * `<verdict> (<priority>)` phrase the GitHub annotation title does. Severity and fingerprint are
     * untouched by it: both still follow the verdict alone.
     */
    public function testTheDescriptionNamesThePriority(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([
            new Finding('acme/abandoned', '1.0.0', Verdict::ABANDONED, [new Signal('S1', 'high', 'marked abandoned by its repository')], ['acme/abandoned'], null, $at),
            // Transitive and development-only: two steps below critical.
            new Finding('acme/silent', '2.0.8', Verdict::ABANDONED, [new Signal('S1', 'high', 'marked abandoned by its repository')], ['a/parent', 'acme/silent'], null, $at, null, true),
            new Finding('acme/fine', '4.0.0', Verdict::OK, [], ['acme/fine'], null, $at),
        ], [], $at, 3, 0, false);

        $issues = $this->decode($this->formatter(LockrotConfig::FAIL_ON_NONE, null)->format($report, true));

        self::assertSame([
            'acme/abandoned 1.0.0 — abandoned (critical): marked abandoned by its repository',
            'acme/silent 2.0.8 — abandoned (medium): marked abandoned by its repository (via a/parent)',
            'acme/fine 4.0.0 — ok (none)',
        ], array_column($issues, 'description'));

        self::assertSame(['minor', 'minor', 'info'], array_column($issues, 'severity'), 'severity still follows the verdict and fail-on');
        self::assertSame(
            hash('sha256', 'lockrot|acme/abandoned|abandoned'),
            $issues[0]['fingerprint'],
            'the priority must not enter the issue identity'
        );
    }

    public function testUnflaggedRowsAreOmittedWithoutShowAll(): void
    {
        $issues = $this->decode($this->formatter(Verdict::SILENT, $this->lockPath())->format($this->report()));
        self::assertCount(3, $issues);
        self::assertNotContains('lockrot/ok', array_column($issues, 'check_name'));
    }

    public function testAllowlistReasonIsCarriedAsDescription(): void
    {
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([
            new Finding('acme/abandoned', '1.0.0', Verdict::FINISHED, [], ['acme/abandoned'], 'interfaces', $at),
        ], [], $at, 1, 0, false);

        $issues = $this->decode($this->formatter(Verdict::SILENT, $this->lockPath())->format($report, true));

        self::assertSame('acme/abandoned 1.0.0 — finished (none): allowlisted: interfaces', $issues[0]['description']);
    }

    /** Report notes are not representable in this format and must never leak into an issue's fields. */
    public function testReportNotesAreNotRepresented(): void
    {
        $out = $this->formatter(Verdict::SILENT, $this->lockPath())->format($this->report());
        self::assertStringNotContainsString('GitHub token not set', $out);
    }

    public function testWordingAvoidsBannedTerms(): void
    {
        $out = strtolower($this->formatter(Verdict::SILENT, $this->lockPath())->format($this->report(), true));
        foreach (['vulnerable', 'broken', 'insecure', 'dead'] as $banned) {
            self::assertStringNotContainsString($banned, $out);
        }
    }

    public function testFactory(): void
    {
        self::assertInstanceOf(
            GitlabFormatter::class,
            Formatters::for('gitlab', FormatContext::create(null, LockrotConfig::FAIL_ON_NONE))
        );
    }

    public function testFactoryStillRejectsAnUnknownFormat(): void
    {
        $this->expectException(ConfigException::class);
        Formatters::for('xml', FormatContext::create(null, LockrotConfig::FAIL_ON_NONE));
    }
}
