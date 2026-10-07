<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** .claude/rules/tests.md, "Hermetic by default": a test reads the clock through `Clock::fixed()` or `LOCKROT_TODAY`. */
final class HermeticTestsTest extends TestCase
{
    private const REAL_CLOCK = '{(?<![\w>:$])(?:time\(\)|(?:gm)?date\(\s*[\'"][^\'"]*[\'"]\s*\)|strtotime\(|mktime\()|new\s+\\\\?DateTime(?:Immutable)?\(\s*(?:[\'"](?:now|today|tomorrow|yesterday|midnight)[\'"]\s*(?:,[^)]*)?)?\)|new\s+(?:\\\\?Lockrot\\\\)?Clock\(\s*\)|Clock::fromEnvironment\(\s*\[\s*\]}';

    /** Tests of the real clock and of a file modification time, relative to tests/. */
    private const ALLOWED = ['Unit/ClockTest.php', 'Unit/SelfUpdate/PharUpdaterTest.php'];

    public function testNoTestReadsTheRealClock(): void
    {
        $root = \dirname(__DIR__).'/';
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);
            $path = substr($file->getPathname(), \strlen($root));
            if (substr($path, -4) !== '.php' || $file->getPathname() === __FILE__ || \in_array($path, self::ALLOWED, true)) {
                continue;
            }
            $code = '';
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                $skip = \is_array($token) && \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true);
                $code .= \is_array($token) ? ($skip ? ' ' : $token[1]) : $token;
            }
            if (preg_match_all(self::REAL_CLOCK, $code, $matches) > 0) {
                $found[$path] = $matches[0];
            }
        }

        self::assertSame([], $found, 'pass a fixed instant instead');
    }
}
