<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Support;

use Lockrot\Tests\Support\TextGolden;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;

/**
 * A failing golden names the first line that differs, its case and the rewrite command, and its
 * diff holds only a few lines. A mutant that changes a large golden then fails about as fast as the
 * golden passes.
 *
 * It covers nothing: these tests check test code.
 *
 * @coversNothing
 */
#[CoversNothing]
final class TextGoldenTest extends TestCase
{
    private const CASES = 3000;

    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/lockrot-text-golden-'.bin2hex(random_bytes(6)).'/';
        self::assertTrue(mkdir($this->directory));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testATextEqualToItsGoldenPasses(): void
    {
        $text = self::cases();
        file_put_contents($this->directory.'g.txt', $text);
        $before = self::getCount();

        TextGolden::assertMatches('g.txt', $text, 'testX', $this->directory);

        self::assertSame(2, self::getCount() - $before);
    }

    public function testAChangedLineFailsWithItsCaseItsFirstLinesAndTheRewriteCommandAndNoWholeTextDiff(): void
    {
        $text = self::cases();
        file_put_contents($this->directory.'g.txt', $text);
        $changed = str_replace("== case c2500\nfirst line of c2500\n", "== case c2500\nfirst line changed\n", $text);
        self::assertNotSame($text, $changed);

        $failure = self::failure('g.txt', $changed);

        self::assertStringContainsString('g.txt: line 7502, in == case c2500, differs; rewrite it with LOCKROT_REWRITE_GOLDEN=1 vendor/bin/phpunit --filter testX, then review the diff', $failure);
        self::assertStringContainsString('7502: first line of c2500', $failure);
        self::assertStringContainsString('7502: first line changed', $failure);
        self::assertStringNotContainsString('c2499', $failure);
        self::assertStringNotContainsString('c2503', $failure);
        self::assertLessThan(2000, \strlen($failure));
    }

    public function testATextWithoutCaseHeadersNamesOnlyTheLine(): void
    {
        file_put_contents($this->directory.'g.txt', "a\nb\nc\n");

        $failure = self::failure('g.txt', "a\nB\nc\n");

        self::assertStringContainsString('g.txt: line 2 differs; rewrite it with', $failure);
        self::assertStringContainsString('2: b', $failure);
        self::assertStringContainsString('2: B', $failure);
    }

    public function testATextThatLosesOnlyItsLastNewlineFails(): void
    {
        file_put_contents($this->directory.'g.txt', "a\nb\n");

        self::assertStringContainsString('g.txt: line 3 differs', self::failure('g.txt', "a\nb"));
    }

    public function testATextWithALineMoreThanItsGoldenFails(): void
    {
        file_put_contents($this->directory.'g.txt', "a\n");

        $failure = self::failure('g.txt', "a\nb\n");

        self::assertStringContainsString('g.txt: line 2 differs', $failure);
        self::assertStringContainsString('2: b', $failure);
    }

    public function testAMissingGoldenNamesTheRecordCommand(): void
    {
        self::assertStringContainsString('record it with LOCKROT_REWRITE_GOLDEN=1 vendor/bin/phpunit --filter testX', self::failure('none.txt', "a\n"));
    }

    private function failure(string $file, string $actual): string
    {
        try {
            TextGolden::assertMatches($file, $actual, 'testX', $this->directory);
        } catch (AssertionFailedError $failure) {
            $diff = $failure instanceof ExpectationFailedException && $failure->getComparisonFailure() !== null ? $failure->getComparisonFailure()->getDiff() : '';

            return $failure->getMessage()."\n".$diff;
        }
        self::fail('the golden assertion passed');
    }

    private static function cases(): string
    {
        $text = '';
        for ($i = 0; $i < self::CASES; ++$i) {
            $text .= '== case c'.$i."\nfirst line of c".$i."\n".str_repeat('x', 150)."\n";
        }

        return $text;
    }
}
