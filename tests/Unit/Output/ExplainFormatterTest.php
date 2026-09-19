<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Composer\Package\Loader\ArrayLoader;
use Lockrot\Analyzer\Report;
use Lockrot\Data\Forge\RepoRef;
use Lockrot\Data\Forge\RepositoryActivity;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Explain\Explanation;
use Lockrot\Lock\LockedPackage;
use Lockrot\Output\ExplainFormatter;
use Lockrot\Signal\Signal;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use Lockrot\Version;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatter;

final class ExplainFormatterTest extends TestCase
{
    /** The text as a terminal without colour shows it: tags applied, escapes resolved. */
    private function plain(Explanation $explanation): string
    {
        $plain = (new OutputFormatter(false))->format((new ExplainFormatter())->text($explanation));
        self::assertIsString($plain);

        return $plain;
    }

    /** @param list<string> $notes */
    private function report(array $notes = []): Report
    {
        return new Report([], $notes, new \DateTimeImmutable(F::NOW), 3, 0, false);
    }

    /**
     * The whole text of a flagged, transitive, abandoned-with-replacement package with an archived
     * repository read from the cache: every block, every separator, the raw data in every shape a
     * signal can carry (scalars, null, a nested array, the advisory list with a row that is not one).
     */
    public function testAFlaggedPackageReadsVerdictSignalsDataAndBranches(): void
    {
        $s8 = new Signal(Signal::S8, Signal::LEVEL_WARN, 'branch 1.x last released 2021-06-01 (5.3 years ago); 2.x released 2.1.0 (2026-01-01)', ['branch' => '1.x', 'years' => 5.3, 'suggested_constraint' => null, 'flags' => ['a' => true]]);
        $s9 = new Signal(Signal::S9, Signal::LEVEL_WARN, '2 security advisories affect 1.5.0 (CVE-2026-1, PKSA-2)', ['advisories' => [
            ['id' => 'PKSA-1', 'cve' => 'CVE-2026-1', 'severity' => 'high', 'fixed_by' => '2.1.0', 'fixed_on_branch' => false, 'reported_at' => '2026-03-01T00:00:00+00:00', 'link' => 'https://example.test/a'],
            ['id' => 'PKSA-2', 'cve' => '', 'severity' => null, 'fixed_by' => null, 'fixed_on_branch' => false],
            'not-a-row',
        ], 'count' => 2]);
        $finding = new Finding('vendor/pkg', '1.5.0', Verdict::LEFT_BEHIND, [$s8, $s9], ['root/app', 'vendor/mid', 'vendor/pkg'], null, new \DateTimeImmutable(F::NOW), null, false, ['root/app', 'root/other']);
        // Branches listed out of order: the table sorts them.
        $metadata = F::metadata([['1.5.0', '2021-06-01T00:00:00+00:00'], ['1.4.9', '2021-01-01T00:00:00+00:00'], ['2.1.0', '2026-01-01T00:00:00+00:00']], true, 'vendor/next');
        $package = F::package(['version' => '1.5.0', 'php' => '>=7.1 <8.0', 'time' => '2021-06-01T00:00:00+00:00']);
        $activity = new RepositoryActivity(new RepoRef(RepoRef::GITHUB, 'github.com', 'vendor/pkg'), true, null, new \DateTimeImmutable(F::NOW), new \DateTimeImmutable('2026-09-13T00:00:00+00:00'));
        $explanation = new Explanation($finding, F::facts($package, $metadata, $activity), new Thresholds(), '8.4', $this->report(['GitHub token not set']));

        $expected = <<<'TEXT'
            vendor/pkg 1.5.0 — left-behind, priority high
              via root/app > vendor/mid > vendor/pkg; also reached from root/other

            signals
              S8 warn branch 1.x last released 2021-06-01 (5.3 years ago); 2.x released 2.1.0 (2026-01-01)
                       branch 1.x · years 5.3 · suggested_constraint null
                       flags {"a":true}
              S9 warn 2 security advisories affect 1.5.0 (CVE-2026-1, PKSA-2)
                       count 2
                       CVE-2026-1 (PKSA-1) high · fixed by 2.1.0, not on the installed branch · reported 2026-03-01 · https://example.test/a
                       PKSA-2 · no listed release fixes it
                       "not-a-row"

            composer.lock
              version 1.5.0 · php >=7.1 <8.0 · released 2021-06-01 · from a Composer repository
              source https://github.com/vendor/pkg.git

            repository metadata (as of 2026-09-14)
              3 versions listed · library · abandoned, replacement vendor/next
              source https://github.com/vendor/pkg.git
              last stable release 2.1.0 (2026-01-01)
                branch     highest tag        released           newest dated release
                2.x        2.1.0              2026-01-01         2.1.0 (2026-01-01)
              * 1.x        1.5.0              2021-06-01         1.5.0 (2021-06-01)

            repository activity
              GitHub vendor/pkg · archived · last push unknown · fetched 2026-09-14 (from lockrot's cache)

            thresholds: release-warn-years 3 · release-high-years 5 · push-warn-years 3 · push-high-years 5 · target PHP 8.4
            note: GitHub token not set

            TEXT;
        self::assertSame($expected, $this->plain($explanation));
        $raw = (new ExplainFormatter())->text($explanation);
        self::assertStringStartsWith("<options=bold>vendor/pkg 1.5.0</> — <fg=yellow>left-behind</fg=yellow>, priority high\n", $raw, 'a flagged verdict is coloured');
        self::assertStringContainsString('php \>=7.1 \<8.0', $raw, 'escaped for the console formatter, which the plain rendering resolves');
    }

    /**
     * The question `--explain` exists for: a package the report does not flag, and the rows that
     * say why S8 stayed quiet. Two of the three branches are dated only by a commit their tags share
     * (a subtree split, as {@see PackageMetadata::fromPackages()} reads it) and read `commit <date>`;
     * the third carries no date at all and reads `undated`.
     */
    public function testAnUnflaggedSplitPackageSaysWhyItsBranchIsNotMeasured(): void
    {
        $finding = new Finding('vendor/pkg', '10.48.28', Verdict::OK, [], ['vendor/pkg'], null, new \DateTimeImmutable(F::NOW));
        $loader = new ArrayLoader();
        $on = static fn (string $version, ?string $commit, ?string $time): array => array_filter(['name' => 'vendor/pkg', 'version' => $version, 'time' => $time, 'source' => $commit === null ? null : ['type' => 'git', 'url' => 'https://github.com/vendor/pkg.git', 'reference' => $commit]]);
        $metadata = PackageMetadata::fromPackages('vendor/pkg', [
            $loader->load($on('13.1.0', 'split-13', '2026-04-29T09:35:06+00:00')),
            $loader->load($on('13.0.1', 'split-13', '2026-04-29T09:35:06+00:00')),
            $loader->load($on('13.0.0', 'split-13', '2026-04-29T09:35:06+00:00')),
            $loader->load($on('12.0.0', null, null)),
            $loader->load($on('10.49.0', 'split-10', '2023-06-05T12:46:42+00:00')),
            $loader->load($on('10.20.0', 'split-10', '2023-06-05T12:46:42+00:00')),
            $loader->load($on('10.13.1', 'split-10', '2023-06-05T12:46:42+00:00')),
        ], new \DateTimeImmutable(F::NOW));
        $explanation = new Explanation($finding, F::facts(F::package(['version' => '10.48.28']), $metadata), new Thresholds(), '8.4', $this->report());

        $expected = <<<'TEXT'
            vendor/pkg 10.48.28 — ok, priority none
              direct requirement

            signals: none

            composer.lock
              version 10.48.28 · no php constraint · undated · from a Composer repository
              source https://github.com/vendor/pkg.git

            repository metadata (as of 2026-09-14)
              7 versions listed · library · not abandoned
              source https://github.com/vendor/pkg.git
              last stable release unknown: the highest tag 13.1.0 has no release date, so S2 does not measure the package
                branch     highest tag        released           newest dated release
                13.x       13.1.0             commit 2026-04-29  13.1.0 (2026-04-29)
                12.x       12.0.0             undated            —
              * 10.x       10.49.0            commit 2023-06-05  10.49.0 (2023-06-05)
              * the installed branch's highest tag has no release date — the repository leaves it undated, or dates it only by a commit other tags share (`commit …`: a subtree split, the day the directory last changed) — so S8 does not measure the branch

            repository activity
              not fetched — S3 and S4 have nothing to read; the run's notes below say why when a cap or a failure is the cause

            thresholds: release-warn-years 3 · release-high-years 5 · push-warn-years 3 · push-high-years 5 · target PHP 8.4

            TEXT;
        self::assertSame($expected, $this->plain($explanation));
        self::assertStringNotContainsString('<fg=', (new ExplainFormatter())->text($explanation), 'an unflagged verdict is not coloured');
    }

    public function testAPackageWithoutMetadataShowsTheNoteInsteadOfATable(): void
    {
        $finding = new Finding('vendor/pkg', '1.0.0', Verdict::UNKNOWN, [], ['vendor/pkg'], null, null, 'not from a Composer repository, not checked', true);
        $sourceless = new LockedPackage('vendor/pkg', '1.0.0', null, null, [], null, 'library', false, true, false);
        $explanation = new Explanation($finding, F::facts($sourceless), new Thresholds(), '8.4', $this->report());

        $text = $this->plain($explanation);

        self::assertStringContainsString("  direct requirement · packages-dev\n  note: not from a Composer repository, not checked\n", $text);
        self::assertStringContainsString("  version 1.0.0 · no php constraint · undated · not from a Composer repository\n", $text);
        self::assertStringContainsString("repository metadata\n  none — not from a Composer repository, not checked\n", $text);
        self::assertStringNotContainsString('branch     highest tag', $text);
        self::assertStringNotContainsString('  source ', $text, 'no source in the lock, no source line');

        $noNote = new Explanation(new Finding('vendor/pkg', '1.0.0', Verdict::UNKNOWN, [], ['vendor/pkg'], null, null), F::facts(F::package()), new Thresholds(), '8.4', $this->report());
        self::assertStringContainsString("repository metadata\n  none — not available\n", $this->plain($noNote));
    }

    public function testTheBranchTableIsCappedAndAnAllowlistedPackageSaysSo(): void
    {
        $releases = [];
        for ($i = 20; $i >= 1; --$i) {
            $releases[] = ['0.0.'.$i, '2020-01-01T00:00:00+00:00'];
        }
        $finding = new Finding('vendor/pkg', '0.0.3', Verdict::FINISHED, [], ['vendor/pkg'], 'interfaces only', new \DateTimeImmutable(F::NOW));
        $explanation = new Explanation($finding, F::facts(F::package(['version' => '0.0.3']), F::metadata($releases), F::activity(false, '2026-02-01T00:00:00+00:00')), new Thresholds(), '8.4', $this->report());

        $text = $this->plain($explanation);

        self::assertStringContainsString("  allowlisted: interfaces only\n", $text);
        self::assertStringContainsString("repository activity\n  GitHub vendor/pkg · not archived · last push 2026-02-01 · fetched 2026-09-14\n", $text, 'a dated push, fetched in this run');
        self::assertSame(Explanation::BRANCH_ROWS, preg_match_all('/^    0\.0\.\d+ /m', $text));
        self::assertStringContainsString('  … and 5 more', $text);

        $exactly = new Explanation($finding, F::facts(F::package(['version' => '0.0.3']), F::metadata(\array_slice($releases, 0, Explanation::BRANCH_ROWS))), new Thresholds(), '8.4', $this->report());
        self::assertStringNotContainsString('more', $this->plain($exactly), 'a table that fits is not counted');
    }

    public function testJsonCarriesTheEnvelopeAndTheExplanation(): void
    {
        $finding = new Finding('vendor/pkg', '1.0.0', Verdict::OK, [], ['vendor/pkg'], null, new \DateTimeImmutable(F::NOW));
        $explanation = new Explanation($finding, F::facts(F::package(), F::metadata([['1.0.0', '2026-01-01T00:00:00+00:00']])), new Thresholds(), '8.4', $this->report());

        $encoded = (new ExplainFormatter())->json($explanation);
        $json = json_decode($encoded, true);

        self::assertStringStartsWith("{\n    \"\$schema\": \"https://lockrot.dev/schema/explain-1.json\",\n    \"lockrot\": {\n", $encoded, 'pretty-printed');
        self::assertStringEndsWith("}\n", $encoded);
        self::assertStringContainsString('"https://github.com/vendor/pkg.git"', $encoded, 'slashes unescaped');
        self::assertIsArray($json);
        self::assertSame(['version' => Version::STRING, 'schema' => 1], $json['lockrot']);
        self::assertSame('vendor/pkg', $json['package']);
        self::assertIsArray($json['finding']);
        self::assertSame('ok', $json['finding']['verdict']);
        self::assertIsArray($json['metadata']);
        self::assertIsArray($json['metadata']['branches']);
        self::assertSame([['branch' => '1.x', 'installed' => true, 'highest' => '1.0.0', 'highest_released' => '2026-01-01T00:00:00+00:00', 'highest_commit_date' => null, 'newest_dated' => '1.0.0', 'newest_dated_released' => '2026-01-01T00:00:00+00:00', 'dated_by' => null]], $json['metadata']['branches']);
    }

    /** A split package dated by its monorepo: the rows read the parent's dates, and a footnote says whose they are. */
    public function testASplitPackageDatedByItsMonorepoSaysSo(): void
    {
        $finding = new Finding('illuminate/contracts', 'v10.48.28', Verdict::OK, [], ['illuminate/contracts'], null, new \DateTimeImmutable(F::NOW));
        $loader = new ArrayLoader();
        $on = static fn (string $name, string $version, string $commit, string $time, array $replace = []): array => array_filter(['name' => $name, 'version' => $version, 'time' => $time, 'source' => ['type' => 'git', 'url' => 'https://github.com/'.$name.'.git', 'reference' => $commit], 'replace' => $replace]);
        $child = PackageMetadata::fromPackages('illuminate/contracts', [
            $loader->load($on('illuminate/contracts', 'v10.49.0', 'split-10', '2023-06-05T12:46:42+00:00')),
            $loader->load($on('illuminate/contracts', 'v10.20.0', 'split-10', '2023-06-05T12:46:42+00:00')),
            $loader->load($on('illuminate/contracts', 'v10.13.1', 'split-10', '2023-06-05T12:46:42+00:00')),
            $loader->load($on('illuminate/contracts', 'v9.52.0', 'split-9', '2023-01-01T00:00:00+00:00')),
            $loader->load($on('illuminate/contracts', 'v9.51.0', 'split-9', '2023-01-01T00:00:00+00:00')),
            $loader->load($on('illuminate/contracts', 'v9.50.0', 'split-9', '2023-01-01T00:00:00+00:00')),
            $loader->load($on('illuminate/contracts', 'v8.83.27', 'own-8', '2022-01-13T14:47:47+00:00')),
        ], new \DateTimeImmutable(F::NOW));
        $parent = PackageMetadata::fromPackages('laravel/framework', [
            $loader->load($on('laravel/framework', 'v10.50.3', 'f10', '2026-08-12T03:46:26+00:00', ['illuminate/contracts' => 'self.version'])),
            $loader->load($on('laravel/framework', 'v9.52.22', 'f9', '2026-08-12T03:46:05+00:00')),
        ], new \DateTimeImmutable(F::NOW));
        $explanation = new Explanation($finding, F::facts(F::package(['name' => 'illuminate/contracts', 'version' => 'v10.48.28', 'source' => 'https://github.com/illuminate/contracts.git']), $child->datedBy($parent)), new Thresholds(), '8.4', $this->report());

        $expected = <<<'TEXT'
            illuminate/contracts v10.48.28 — ok, priority none
              direct requirement

            signals: none

            composer.lock
              version v10.48.28 · no php constraint · undated · from a Composer repository
              source https://github.com/illuminate/contracts.git

            repository metadata (as of 2026-09-14)
              7 versions listed · library · not abandoned
              source https://github.com/illuminate/contracts.git
              last stable release v10.50.3 (2026-08-12, dated by laravel/framework)
                branch     highest tag        released           newest dated release
              * 10.x       v10.50.3           2026-08-12         v10.50.3 (2026-08-12)
                9.x        v9.52.22           2026-08-12         v9.52.22 (2026-08-12)
                8.x        v8.83.27           2022-01-13         v8.83.27 (2022-01-13)
              branches 10.x, 9.x dated by laravel/framework, the monorepo this package is split out of: its own tags there are dated by a commit other tags share, the monorepo's by their release

            repository activity
              not fetched — S3 and S4 have nothing to read; the run's notes below say why when a cap or a failure is the cause

            thresholds: release-warn-years 3 · release-high-years 5 · push-warn-years 3 · push-high-years 5 · target PHP 8.4

            TEXT;
        self::assertSame($expected, $this->plain($explanation));
    }
}
