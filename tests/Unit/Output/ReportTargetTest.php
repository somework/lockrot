<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Config\LockrotConfig;
use Lockrot\Exception\ConfigException;
use Lockrot\Output\ReportTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportTargetTest extends TestCase
{
    /**
     * The spec starts with a format name lockrot knows and a colon; the rest is the path, colons
     * included. A name is matched whole, not cut at the first colon, so a format whose name holds a
     * colon (the `<vendor>:<name>` form docs/compatibility.md reserves) stays parseable.
     */
    public function testTheFormatIsAKnownNameFollowedByAColon(): void
    {
        $windows = ReportTarget::parse('json:C:\\out\\r.json', '/projects/app');
        self::assertSame('json', $windows->format());
        self::assertSame('C:\\out\\r.json', $windows->path(), 'a drive letter is an absolute path, kept as given');
        self::assertSame('C:\\out\\r.json', $windows->displayPath());

        $colon = ReportTarget::parse('sarif:a:b.sarif', '/projects/app');
        self::assertSame('sarif', $colon->format());
        self::assertSame('/projects/app/a:b.sarif', $colon->path());
        self::assertSame('a:b.sarif', $colon->displayPath());
    }

    public function testARelativePathIsResolvedAgainstTheWorkingDirectoryAndDisplayedAsGiven(): void
    {
        $target = ReportTarget::parse('markdown:out/summary.md', '/projects/app/');

        self::assertSame('/projects/app/out/summary.md', $target->path());
        self::assertSame('out/summary.md', $target->displayPath());
        self::assertSame('--output=markdown:out/summary.md', $target->option());
    }

    public function testAnAbsolutePathIsKeptAsGiven(): void
    {
        $target = ReportTarget::parse('html:/srv/reports/lockrot.html', '/projects/app');

        self::assertSame('/srv/reports/lockrot.html', $target->path());
        self::assertSame('/srv/reports/lockrot.html', $target->displayPath());
    }

    /** Paths may carry spaces at either end; nothing is trimmed. */
    public function testThePathIsTakenVerbatim(): void
    {
        self::assertSame('/projects/app/ my report.json ', ReportTarget::parse('json: my report.json ', '/projects/app')->path());
    }

    /** @return iterable<string, array{string}> */
    public static function formats(): iterable
    {
        foreach (LockrotConfig::FORMATS as $format) {
            yield $format => [$format];
        }
    }

    /**
     * @dataProvider formats
     */
    #[DataProvider('formats')]
    public function testEveryFormatTheConfigAcceptsCanBeAFile(string $format): void
    {
        self::assertSame($format, ReportTarget::parse($format.':r.out', '/projects/app')->format());
    }

    /** @return iterable<string, array{string, string}> */
    public static function malformed(): iterable
    {
        yield 'no colon' => ['r.json', '--output=r.json: expected <format>:<path>, e.g. --output=sarif:lockrot.sarif'];
        yield 'an unknown format' => ['xml:r.xml', '--output=xml:r.xml: unknown format "xml"; the formats are table, json, github, sarif, gitlab, markdown, html'];
        yield 'no format' => [':r.json', '--output=:r.json: unknown format ""'];
        yield 'a known name that runs on' => ['jsonx:r.json', '--output=jsonx:r.json: unknown format "jsonx"'];
        yield 'a known name without its colon' => ['json', '--output=json: expected <format>:<path>'];
        yield 'an unknown vendor format is named up to its first colon' => ['acme:lint:r.txt', '--output=acme:lint:r.txt: unknown format "acme"'];
        yield 'formats are case-sensitive, as --format is' => ['JSON:r.json', '--output=JSON:r.json: unknown format "JSON"'];
        yield 'an empty path' => ['json:', '--output=json:: the path is empty'];
        yield 'a path ending in a slash' => ['json:out/', '--output=json:out/: the path names a directory, not a file'];
        yield 'a path ending in a backslash' => ['json:out\\', '--output=json:out\\: the path names a directory, not a file'];
    }

    /**
     * @dataProvider malformed
     */
    #[DataProvider('malformed')]
    public function testAMalformedSpecIsAConfigError(string $spec, string $message): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($message);

        ReportTarget::parse($spec, '/projects/app');
    }
}
