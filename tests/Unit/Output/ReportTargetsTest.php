<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Analyzer\Report;
use Lockrot\Config\LockrotConfig;
use Lockrot\Exception\ConfigException;
use Lockrot\Output\FormatContext;
use Lockrot\Output\Formatters;
use Lockrot\Output\ReportTarget;
use Lockrot\Output\ReportTargets;
use Lockrot\Signal\Signal;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportTargetsTest extends TestCase
{
    private const AT = '2026-09-14T06:00:00+00:00';
    private const BASELINE_REASON = 'that is the baseline file, which lockrot writes only with --generate-baseline';

    private string $cwd;

    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        $this->cwd = $this->tempDir();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            self::removeTree($dir);
        }
        $this->tempDirs = [];
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/lockrot-targets-'.uniqid('', true);
        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('cannot create temp dir: '.$dir);
        }
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private static function removeTree(string $dir): void
    {
        $entries = is_dir($dir) ? scandir($dir) : false;
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) && !is_link($path) ? self::removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * @param list<string>                     $specs
     * @param list<array{0: string, 1: string}> $protected
     */
    private function resolve(array $specs, array $protected = []): ReportTargets
    {
        return ReportTargets::resolve($specs, $this->cwd, $protected);
    }

    private function refusal(callable $resolve): string
    {
        try {
            $resolve();
        } catch (ConfigException $e) {
            return $e->getMessage();
        }
        self::fail('the specs were accepted');
    }

    /**
     * One abandoned direct requirement — critical, so the table colours it and puts it under a bold
     * header — whose evidence quotes a constraint with both angle brackets in it.
     */
    private static function report(): Report
    {
        $at = new \DateTimeImmutable(self::AT);

        return new Report([
            new Finding('acme/old', '1.0.0', Verdict::ABANDONED, [
                new Signal('S1', 'high', 'marked abandoned by its repository'),
                new Signal('S5', 'warn', 'admits 8.4 untested (php ">=7.1 <8")'),
            ], ['acme/old'], null, $at),
        ], ['a note with <info>tags</info> in it'], $at, 1, 0, false);
    }

    public function testNoSpecsWantsNothingAndWritesNothing(): void
    {
        $targets = $this->resolve([]);
        $called = false;

        self::assertFalse($targets->wants('table'));
        $targets->write(self::report(), FormatContext::create(null, LockrotConfig::FAIL_ON_NONE), null, false, static function () use (&$called): void {
            $called = true;
        });
        self::assertFalse($called);
        self::assertSame([], array_values(array_diff((array) scandir($this->cwd), ['.', '..'])));
    }

    public function testWantsIsTrueOnlyForAFormatAsked(): void
    {
        $targets = $this->resolve(['json:r.json', 'sarif:r.sarif']);

        self::assertTrue($targets->wants('json'));
        self::assertTrue($targets->wants('sarif'));
        self::assertFalse($targets->wants('html'));
    }

    /** @return iterable<string, array{string}> */
    public static function lockFiles(): iterable
    {
        yield 'composer.json' => ['json:composer.json'];
        yield 'composer.lock' => ['json:composer.lock'];
        yield 'dot-relative' => ['json:./composer.lock'];
        yield 'through a directory that does not exist' => ['json:sub/../composer.lock'];
        yield 'through a directory that does not exist, Windows separators' => ['json:sub\\..\\composer.lock'];
        yield 'in a directory that does not exist' => ['json:nope/composer.lock'];
        yield 'another case' => ['json:Composer.LOCK'];
        yield 'another package of a monorepo' => ['json:vendor/x/composer.json'];
    }

    /**
     * Whatever directory it sits in, and whether or not it exists: a report is never written over a
     * Composer manifest or lock. The rule comes before any look at the filesystem, so the reason
     * given is this one and not a missing directory.
     *
     * @dataProvider lockFiles
     */
    #[DataProvider('lockFiles')]
    public function testComposerJsonAndComposerLockAreNeverTargets(string $spec): void
    {
        mkdir($this->cwd.'/vendor/x', 0777, true);
        touch($this->cwd.'/vendor/x/composer.json');

        self::assertSame(
            '--output='.$spec.': lockrot never writes composer.json or composer.lock',
            $this->refusal(fn () => $this->resolve([$spec]))
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function baselineSpellings(): iterable
    {
        yield 'as configured' => ['ci/base.json', 'json:ci/base.json'];
        yield 'through dot segments' => ['ci/base.json', 'json:./ci/../ci/base.json'];
        yield 'in another case' => ['ci/base.json', 'json:CI/Base.JSON'];
        yield 'the default name, in another case' => ['lockrot-baseline.json', 'html:LOCKROT-BASELINE.json'];
        yield 'in a directory that does not exist yet' => ['later/base.json', 'json:later/base.json'];
        yield 'in a directory that does not exist yet, in another case' => ['later/base.json', 'json:LATER/base.json'];
        // Windows folds `..` by spelling before it asks whether `missing` exists
        yield 'through a directory that does not exist' => ['lockrot-baseline.json', 'json:missing/../lockrot-baseline.json'];
        yield 'through a directory that does not exist, Windows separators' => ['lockrot-baseline.json', 'json:missing\\..\\lockrot-baseline.json'];
        yield 'up and back down through a directory that does not exist' => ['ci/base.json', 'json:missing/../ci/base.json'];
    }

    /**
     * @dataProvider baselineSpellings
     */
    #[DataProvider('baselineSpellings')]
    public function testAProtectedFileIsNeverATargetHoweverItIsSpelled(string $baseline, string $spec): void
    {
        mkdir($this->cwd.'/ci');

        self::assertSame(
            '--output='.$spec.': '.self::BASELINE_REASON,
            $this->refusal(fn () => $this->resolve([$spec], [[$this->cwd.'/'.$baseline, self::BASELINE_REASON]]))
        );
    }

    public function testAProtectedFileNamedByAnAbsolutePathIsRefusedToo(): void
    {
        $spec = 'json:'.$this->cwd.'/lockrot-baseline.json';

        self::assertSame(
            '--output='.$spec.': '.self::BASELINE_REASON,
            $this->refusal(fn () => $this->resolve([$spec], [[$this->cwd.'/lockrot-baseline.json', self::BASELINE_REASON]]))
        );
    }

    public function testEveryProtectedFileIsChecked(): void
    {
        $protected = [[$this->cwd.'/one.json', 'the first'], [$this->cwd.'/two.json', 'the second']];

        self::assertSame('--output=json:one.json: the first', $this->refusal(fn () => $this->resolve(['json:one.json'], $protected)));
        self::assertSame('--output=json:two.json: the second', $this->refusal(fn () => $this->resolve(['json:two.json'], $protected)));
        self::assertTrue($this->resolve(['json:three.json'], $protected)->wants('json'));
    }

    /** @return iterable<string, array{string}> */
    public static function windowsAliases(): iterable
    {
        yield 'the lock with a trailing dot' => ['json:composer.lock.'];
        yield 'the lock with a trailing space' => ['json:composer.lock '];
        yield 'the lock\'s default data stream' => ['json:composer.lock::$DATA'];
        yield 'the manifest with a trailing dot' => ['json:composer.json.'];
        yield 'the baseline with a trailing dot' => ['json:lockrot-baseline.json.'];
        yield 'the baseline\'s default data stream' => ['json:lockrot-baseline.json::$DATA'];
        yield 'a named stream of any file' => ['json:r.json:stream'];
        yield 'any name with a trailing dot' => ['json:r.json.'];
    }

    /**
     * Win32 drops trailing dots and spaces from a name and reads `name::$DATA` as the file itself,
     * so each of these passes a name comparison and then lands on the file it spells. Such a name is
     * refused outright, on every system, before any other rule.
     *
     * @dataProvider windowsAliases
     */
    #[DataProvider('windowsAliases')]
    public function testANameWindowsReadsAsAnotherIsNeverATarget(string $spec): void
    {
        file_put_contents($this->cwd.'/lockrot-baseline.json', '{}');

        self::assertSame(
            '--output='.$spec.': a file name that ends in a dot or a space, or holds a colon, is another name on Windows',
            $this->refusal(fn () => $this->resolve([$spec], [[$this->cwd.'/lockrot-baseline.json', self::BASELINE_REASON]]))
        );
    }

    /**
     * A name that resolves on disk to a protected file — a symlink here; an 8.3 short name such as
     * `LOCKRO~1.JSO` on Windows — is that file.
     */
    public function testALinkToAProtectedFileIsRefused(): void
    {
        file_put_contents($this->cwd.'/lockrot-baseline.json', '{}');
        mkdir($this->cwd.'/out');
        symlink($this->cwd.'/lockrot-baseline.json', $this->cwd.'/out/r.json');

        self::assertSame(
            '--output=json:out/r.json: '.self::BASELINE_REASON,
            $this->refusal(fn () => $this->resolve(['json:out/r.json'], [[$this->cwd.'/lockrot-baseline.json', self::BASELINE_REASON]]))
        );
        self::assertTrue($this->resolve(['json:out/r.json'], [[$this->cwd.'/other.json', self::BASELINE_REASON]])->wants('json'));
    }

    public function testALinkToAComposerLockIsRefused(): void
    {
        file_put_contents($this->cwd.'/composer.lock', '{}');
        symlink($this->cwd.'/composer.lock', $this->cwd.'/r.json');

        self::assertSame(
            '--output=json:r.json: lockrot never writes composer.json or composer.lock',
            $this->refusal(fn () => $this->resolve(['json:r.json']))
        );
    }

    /**
     * A second name for the file itself — a hard link here; on macOS's case-insensitive APFS any
     * spelling Unicode case folding sends to the same entry, such as `composer.locK` with a Kelvin
     * sign, which lower-casing ASCII never matches — is that file.
     */
    public function testAnotherNameForAProtectedFileOnDiskIsRefused(): void
    {
        file_put_contents($this->cwd.'/lockrot-baseline.json', '{}');
        mkdir($this->cwd.'/out');
        link($this->cwd.'/lockrot-baseline.json', $this->cwd.'/out/r.json');

        self::assertSame(
            '--output=json:out/r.json: '.self::BASELINE_REASON,
            $this->refusal(fn () => $this->resolve(['json:out/r.json'], [[$this->cwd.'/lockrot-baseline.json', self::BASELINE_REASON]]))
        );
    }

    /** Beside the target, whatever the caller protects: a composer.json or composer.lock is never written. */
    public function testAnotherNameForAComposerFileBesideTheTargetIsRefused(): void
    {
        mkdir($this->cwd.'/pkg');
        file_put_contents($this->cwd.'/pkg/composer.json', '{}');
        file_put_contents($this->cwd.'/pkg/composer.lock', '{}');
        link($this->cwd.'/pkg/composer.json', $this->cwd.'/pkg/manifest.json');
        link($this->cwd.'/pkg/composer.lock', $this->cwd.'/pkg/lock.json');

        foreach (['json:pkg/manifest.json', 'json:pkg/lock.json'] as $spec) {
            self::assertSame(
                '--output='.$spec.': lockrot never writes composer.json or composer.lock',
                $this->refusal(fn () => $this->resolve([$spec]))
            );
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function foldedSpellings(): iterable
    {
        yield 'the lock, with a Kelvin sign' => ['composer.lock', "json:composer.loc\u{212A}", 'lockrot never writes composer.json or composer.lock'];
        yield 'the manifest, with a long s' => ['composer.json', "markdown:compo\u{17F}er.json", 'lockrot never writes composer.json or composer.lock'];
        yield 'the baseline, with a Kelvin sign' => ['lockrot-baseline.json', "json:loc\u{212A}rot-baseline.json", self::BASELINE_REASON];
    }

    /**
     * On a filesystem that folds case by Unicode rules (APFS and HFS+ by default, NTFS) these names
     * open the protected file. Skipped where the filesystem does not fold them — there they are
     * other files, and writing one harms nothing.
     *
     * @dataProvider foldedSpellings
     */
    #[DataProvider('foldedSpellings')]
    public function testASpellingTheFilesystemFoldsToAProtectedFileIsRefused(string $file, string $spec, string $reason): void
    {
        file_put_contents($this->cwd.'/'.$file, '{}');
        if (!file_exists($this->cwd.'/'.substr($spec, (int) strpos($spec, ':') + 1))) {
            self::markTestSkipped('this filesystem does not fold the spelling to '.$file);
        }

        self::assertSame(
            '--output='.$spec.': '.$reason,
            $this->refusal(fn () => $this->resolve([$spec], [[$this->cwd.'/lockrot-baseline.json', self::BASELINE_REASON]]))
        );
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function duplicates(): iterable
    {
        yield 'dot-relative' => [['json:r.json', 'html:./r.json'], '--output=html:./r.json: the same file as --output=json:r.json'];
        yield 'in another case' => [['json:R.json', 'html:r.json'], '--output=html:r.json: the same file as --output=json:R.json'];
        yield 'the third against the first' => [['json:r.json', 'sarif:r.sarif', 'table:r.json'], '--output=table:r.json: the same file as --output=json:r.json'];
    }

    /**
     * @param list<string> $specs
     *
     * @dataProvider duplicates
     */
    #[DataProvider('duplicates')]
    public function testTheSameFileTwiceIsAConfigError(array $specs, string $message): void
    {
        self::assertSame($message, $this->refusal(fn () => $this->resolve($specs)));
    }

    /** Two names that exist and are one file on disk — a hard link here — are the same file named twice. */
    public function testTwoNamesForOneFileOnDiskAreTheSameFileTwice(): void
    {
        file_put_contents($this->cwd.'/a.json', 'the last run');
        link($this->cwd.'/a.json', $this->cwd.'/b.json');

        self::assertSame(
            '--output=html:b.json: the same file as --output=json:a.json',
            $this->refusal(fn () => $this->resolve(['json:a.json', 'html:b.json']))
        );
    }

    /**
     * A name that does not exist yet can still turn out to be a file this run has just written — on
     * APFS `café.json` spelled precomposed and decomposed is one file. Checked again before each
     * write, it stops the run with exit 2 naming both, instead of losing the first report silently;
     * the first file is kept as written.
     */
    public function testAFileThatTurnsOutToBeOneAlreadyWrittenStopsTheRun(): void
    {
        $targets = $this->resolve(['json:a.json', 'markdown:b.md', 'json:c.json']);
        $context = FormatContext::create(null, LockrotConfig::FAIL_ON_NONE);
        $written = [];

        $message = $this->refusal(function () use ($targets, $context, &$written): void {
            $targets->write(self::report(), $context, null, false, function (ReportTarget $target) use (&$written): void {
                $written[] = $target->displayPath();
                if ($target->displayPath() === 'b.md') {
                    link($this->cwd.'/a.json', $this->cwd.'/c.json');
                }
            });
        });

        self::assertSame('--output=json:c.json: the same file as --output=json:a.json', $message);
        self::assertSame(['a.json', 'b.md'], $written);
        self::assertSame(Formatters::for('json', $context)->format(self::report()), file_get_contents($this->cwd.'/a.json'));
    }

    public function testNormalizationEquivalentNamesAreOneFileWhereTheFilesystemSaysSo(): void
    {
        $composed = "caf\u{E9}.json";
        $decomposed = "cafe\u{301}.json";
        file_put_contents($this->cwd.'/probe-'.$composed, '');
        $folds = file_exists($this->cwd.'/probe-'.$decomposed);
        unlink($this->cwd.'/probe-'.$composed);
        if (!$folds) {
            self::markTestSkipped('this filesystem keeps both spellings apart');
        }
        $targets = $this->resolve(['json:'.$composed, 'markdown:'.$decomposed]);

        $message = $this->refusal(fn () => $targets->write(self::report(), FormatContext::create(null, LockrotConfig::FAIL_ON_NONE), null, false, static function (): void {
        }));

        self::assertSame('--output=markdown:'.$decomposed.': the same file as --output=json:'.$composed, $message);
    }

    public function testOneFormatMayGoToTwoFiles(): void
    {
        self::assertTrue($this->resolve(['json:a.json', 'json:b.json'])->wants('json'));
    }

    public function testAMissingDirectoryIsAConfigErrorAndIsNotCreated(): void
    {
        self::assertSame(
            '--output=json:nope/r.json: directory nope does not exist; lockrot does not create directories',
            $this->refusal(fn () => $this->resolve(['json:nope/r.json']))
        );
        self::assertDirectoryDoesNotExist($this->cwd.'/nope');
    }

    public function testAParentThatIsAFileIsAConfigError(): void
    {
        touch($this->cwd.'/a.txt');

        self::assertSame(
            '--output=json:a.txt/r.json: a.txt is not a directory',
            $this->refusal(fn () => $this->resolve(['json:a.txt/r.json']))
        );
    }

    public function testATargetThatExistsAndIsNotARegularFileIsAConfigError(): void
    {
        mkdir($this->cwd.'/out');

        self::assertSame(
            '--output=json:out: out exists and is not a regular file',
            $this->refusal(fn () => $this->resolve(['json:out']))
        );
    }

    public function testAnExistingRegularFileIsATarget(): void
    {
        file_put_contents($this->cwd.'/r.json', 'the last run');

        self::assertTrue($this->resolve(['json:r.json'])->wants('json'));
    }

    public function testASymlinkToARegularFileIsATarget(): void
    {
        file_put_contents($this->cwd.'/real.json', 'the last run');
        symlink($this->cwd.'/real.json', $this->cwd.'/link.json');

        self::assertTrue($this->resolve(['json:link.json'])->wants('json'));
    }

    /** The syntax of every spec is checked before any rule about a single one. */
    public function testTheFirstBadSpecIsTheOneReported(): void
    {
        self::assertSame(
            '--output=xml:r.xml: unknown format "xml"; the formats are table, json, github, sarif, gitlab, markdown, html',
            $this->refusal(fn () => $this->resolve(['json:r.json', 'xml:r.xml', 'json:composer.lock']))
        );
    }

    public function testEachFileIsWhatItsFormatterPrintsInTheOrderGiven(): void
    {
        mkdir($this->cwd.'/out');
        // sarif points each result at its line of the lock
        file_put_contents($this->cwd.'/composer.lock', "{\n    \"packages\": [\n        {\n            \"name\": \"acme/old\",\n            \"version\": \"1.0.0\"\n        }\n    ]\n}\n");
        $targets = $this->resolve(['sarif:out/r.sarif', 'json:r.json', 'markdown:r.md']);
        $context = FormatContext::create($this->cwd.'/composer.lock', LockrotConfig::FAIL_ON_NONE);
        $written = [];

        $targets->write(self::report(), $context, null, false, static function (ReportTarget $target) use (&$written): void {
            $written[] = $target->format().' '.$target->displayPath();
        });

        self::assertSame(['sarif out/r.sarif', 'json r.json', 'markdown r.md'], $written);
        self::assertSame(Formatters::for('sarif', $context)->format(self::report()), file_get_contents($this->cwd.'/out/r.sarif'));
        self::assertSame(Formatters::for('json', $context)->format(self::report()), file_get_contents($this->cwd.'/r.json'));
        self::assertSame(Formatters::for('markdown', $context)->format(self::report()), file_get_contents($this->cwd.'/r.md'));
    }

    /** `--all` reaches the files as it reaches stdout. */
    public function testShowAllIsPassedToTheFormatter(): void
    {
        $targets = $this->resolve(['markdown:all.md']);
        $context = FormatContext::create(null, LockrotConfig::FAIL_ON_NONE);
        $at = new \DateTimeImmutable(self::AT);
        $report = new Report([
            new Finding('acme/old', '1.0.0', Verdict::ABANDONED, [new Signal('S1', 'high', 'marked abandoned by its repository')], ['acme/old'], null, $at),
            new Finding('acme/fine', '2.0.0', Verdict::OK, [], ['acme/fine'], null, $at),
        ], [], $at, 2, 0, false);

        $targets->write($report, $context, null, true, static function (): void {
        });

        self::assertSame(Formatters::for('markdown', $context)->format($report, true), file_get_contents($this->cwd.'/all.md'));
        self::assertStringContainsString('acme/fine', (string) file_get_contents($this->cwd.'/all.md'));
    }

    /**
     * The table's console markup is for a terminal. In a file it is resolved away the way a
     * redirected stdout resolves it: no style tags, no ANSI, and the escaped brackets back to what
     * the evidence said.
     */
    public function testATableFileCarriesNoConsoleMarkup(): void
    {
        $targets = $this->resolve(['table:r.txt']);
        $context = FormatContext::create(null, LockrotConfig::FAIL_ON_NONE);
        $raw = Formatters::for('table', $context)->format(self::report());
        self::assertStringContainsString('<fg=red>', $raw, 'the table does carry markup for the terminal');

        $targets->write(self::report(), $context, null, false, static function (): void {
        });

        $file = (string) file_get_contents($this->cwd.'/r.txt');
        self::assertStringContainsString("critical (1)\n", $file);
        self::assertStringContainsString('(php ">=7.1 <8")', $file);
        self::assertStringContainsString('a note with <info>tags</info> in it', $file);
        self::assertStringNotContainsString('<fg=', $file);
        self::assertStringNotContainsString('<options=', $file);
        self::assertStringNotContainsString('\\<', $file);
        self::assertStringNotContainsString("\e[", $file);
    }

    public function testTheFirstFailedWriteStopsTheRestAndKeepsWhatWasWritten(): void
    {
        $targets = $this->resolve(['json:a.json', 'json:b.json', 'json:c.json']);
        $context = FormatContext::create(null, LockrotConfig::FAIL_ON_NONE);
        $written = [];

        $message = $this->refusal(function () use ($targets, $context, &$written): void {
            $targets->write(self::report(), $context, null, false, function (ReportTarget $target) use (&$written): void {
                $written[] = $target->displayPath();
                if ($target->displayPath() === 'a.json') {
                    // Something takes the second path between the checks and the write.
                    mkdir($this->cwd.'/b.json');
                    touch($this->cwd.'/b.json/occupied');
                }
            });
        });

        self::assertMatchesRegularExpression('/^Cannot write b\.json: \S/', $message);
        self::assertSame(['a.json'], $written);
        self::assertFileExists($this->cwd.'/a.json');
        self::assertFileDoesNotExist($this->cwd.'/c.json');
    }
}
