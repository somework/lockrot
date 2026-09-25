<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Verdict\Priority;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\TestCase;

/**
 * docs/compatibility.md states the order of the closed sets lockrot freezes for 1.x, and
 * docs/verdicts.md states the severity ladder; both are read here against the code, so a page and
 * the engine cannot come to disagree about which verdict is worse. ClosedSetsTest holds the code and
 * the schemas to the same lists.
 *
 * The site navigation is checked too: `mkdocs build --strict` does not fail on a page left out of
 * the nav, it only prints it, so a new page could ship unreachable.
 */
final class CompatibilityPageTest extends TestCase
{
    private const DOCS = __DIR__.'/../../docs/';
    private const MKDOCS = __DIR__.'/../../mkdocs.yml';

    public function testThePageStatesTheOrdersTheCodeKeeps(): void
    {
        $page = self::read(self::DOCS.'compatibility.md');

        self::assertStringContainsString('Verdicts, most severe first: `'.implode('`, `', Verdict::all()).'`.', $page);
        self::assertStringContainsString('Priorities, highest first: `'.implode('`, `', Priority::all()).'`.', $page);
    }

    public function testThePageSaysItIsADraft(): void
    {
        self::assertStringContainsString('!!! warning "Draft until 1.0.0-RC1"', self::read(self::DOCS.'compatibility.md'));
    }

    /** `>` where the next verdict is less severe, `=` where the two tie — `finished = ok` today. */
    public function testTheVerdictsPageStatesTheSameLadder(): void
    {
        $verdicts = Verdict::all();
        $ladder = $verdicts[0];
        for ($i = 1, $count = \count($verdicts); $i < $count; ++$i) {
            $tie = Verdict::severity($verdicts[$i - 1]) === Verdict::severity($verdicts[$i]);
            $ladder .= ($tie ? ' = ' : ' > ').$verdicts[$i];
        }

        self::assertStringContainsString('`'.$ladder.'`', self::read(self::DOCS.'verdicts.md'));
    }

    public function testEveryDocsPageIsInTheSiteNavigation(): void
    {
        $mkdocs = self::read(self::MKDOCS);
        $pages = glob(self::DOCS.'*.md') ?: [];
        self::assertNotEmpty($pages);

        foreach ($pages as $path) {
            $page = basename($path);
            self::assertMatchesRegularExpression('/^\s+- [^:\n]+: '.preg_quote($page, '/').'$/m', $mkdocs, $page.' is in the mkdocs.yml nav');
        }
    }

    private static function read(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents, $path);

        return $contents;
    }
}
