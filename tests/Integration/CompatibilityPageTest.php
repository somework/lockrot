<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Tests\Support\ClosedSets;
use Lockrot\Verdict\Priority;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * docs/compatibility.md states the order of the closed sets lockrot freezes for 1.x, and
 * docs/verdicts.md states the severity ladder; both are read here against the code, so a page and
 * the engine cannot come to disagree about which verdict is worse. ClosedSetsTest holds the code and
 * the schemas to the same lists.
 *
 * Whether every page is in the site navigation, and every link's anchor exists, is `mkdocs build
 * --strict` in CI, through the `validation` block in mkdocs.yml.
 */
final class CompatibilityPageTest extends TestCase
{
    private const DOCS = __DIR__.'/../../docs/';

    /** The two pages that list verdicts in Verdict::all() order. */
    private const VERDICT_PAGES = ['compatibility.md', 'verdicts.md'];

    /** @var array<string, string> page => contents, read once */
    private static array $pages = [];

    public function testThePageStatesTheOrdersTheCodeKeeps(): void
    {
        $page = self::prose('compatibility.md');

        self::assertStringContainsString('Verdicts, most severe first: `'.implode('`, `', Verdict::all()).'`.', $page);
        self::assertStringContainsString('Priorities, highest first: `'.implode('`, `', Priority::all()).'`.', $page);
        self::assertStringContainsString('Signal levels, lowest first: `'.implode('`, `', ClosedSets::levels()).'`.', $page);
        self::assertStringContainsString("A finding's standing against the baseline: `".implode('`, `', ClosedSets::standings()).'`.', $page);
    }

    /**
     * The SARIF rules follow the order the results first use them (SarifFormatterTest pins that),
     * so neither page may name them among the lists kept in Verdict::all() order.
     */
    public function testThePagesDoNotClaimVerdictOrderForTheSarifRules(): void
    {
        self::assertStringContainsString('the SARIF rules appear in the order the results first use them', self::prose('compatibility.md'));

        foreach (self::VERDICT_PAGES as $page) {
            $text = self::prose($page);
            self::assertStringContainsString('`counts`, `run.flagged_verdicts`, the schema enums, this page', $text, $page);
            self::assertDoesNotMatchRegularExpression('/Where lockrot lists verdicts[^.;]*SARIF/', $text, $page.' lists the SARIF rules among the verdict-ordered lists');
        }
    }

    public function testThePageSaysItIsADraft(): void
    {
        self::assertStringContainsString('!!! warning "Draft until 1.0.0-RC1"', self::read('compatibility.md'));
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

        self::assertStringContainsString('`'.$ladder.'`', self::prose('verdicts.md'));
    }

    /**
     * The site renders with Python-Markdown, which needs four spaces to nest: a sub-list indented by
     * two renders as more items of the outer list, and a paragraph indented by two after a blank line
     * leaves the list, taking the bullets that follow it into one paragraph as literal `- ` lines.
     * After a list item's own paragraph, the next item also needs a blank line before it, or it is
     * read as more of that paragraph.
     *
     * @return iterable<string, array{string}>
     */
    public static function pages(): iterable
    {
        foreach (glob(self::DOCS.'*.md') ?: [] as $path) {
            yield basename($path) => [basename($path)];
        }
    }

    /** @dataProvider pages */
    #[DataProvider('pages')]
    public function testNestedListsAreIndentedTheWayTheSiteRendersThem(string $page): void
    {
        self::assertSame([], self::misnestedLines(self::read($page)), $page);
    }

    public function testTheListRuleCatchesWhatTheSiteWouldFlatten(): void
    {
        self::assertSame([], self::misnestedLines("- a\n    - b\n\n    more of a\n\n- c\n"));
        self::assertSame([2 => 'a sub-list indented by fewer than four spaces'], self::misnestedLines("- a\n  - b\n"));
        self::assertSame([3 => 'a paragraph indented by fewer than four spaces'], self::misnestedLines("- a\n\n  more of a\n"));
        self::assertSame([4 => 'no blank line before this item, after a paragraph of the one before'], self::misnestedLines("- a\n\n    more of a\n- b\n"));
        self::assertSame([], self::misnestedLines("```\n  - code\n\n  code\n```\n"), 'fenced code is left alone');
    }

    /**
     * Line number => what is wrong with it.
     *
     * @return array<int, string>
     */
    private static function misnestedLines(string $markdown): array
    {
        $problems = [];
        $fenced = false;
        $previous = '';
        $itemHasParagraph = false;
        foreach (explode("\n", $markdown) as $index => $line) {
            if (preg_match('/^\s*```/', $line) === 1) {
                $fenced = !$fenced;
                $previous = $line;
                continue;
            }
            if ($fenced) {
                $previous = $line;
                continue;
            }
            $blankBefore = trim($previous) === '';
            if (preg_match('/^ {1,3}[-*+] /', $line) === 1) {
                $problems[$index + 1] = 'a sub-list indented by fewer than four spaces';
            } elseif ($blankBefore && preg_match('/^ {1,3}\S/', $line) === 1) {
                $problems[$index + 1] = 'a paragraph indented by fewer than four spaces';
            } elseif (preg_match('/^[-*+] /', $line) === 1) {
                if (!$blankBefore && $itemHasParagraph) {
                    $problems[$index + 1] = 'no blank line before this item, after a paragraph of the one before';
                }
                $itemHasParagraph = false;
            } elseif ($blankBefore && preg_match('/^ {4}\S/', $line) === 1 && preg_match('/^ {4}[-*+] /', $line) !== 1) {
                $itemHasParagraph = true;
            } elseif (trim($line) !== '' && preg_match('/^\s/', $line) !== 1) {
                $itemHasParagraph = false;
            }
            $previous = $line;
        }

        return $problems;
    }

    /** The page with every run of whitespace, line breaks included, read as one space. */
    private static function prose(string $page): string
    {
        return (string) preg_replace('/\s+/', ' ', self::read($page));
    }

    private static function read(string $page): string
    {
        if (!isset(self::$pages[$page])) {
            $contents = file_get_contents(self::DOCS.$page);
            self::assertIsString($contents, $page);
            self::$pages[$page] = $contents;
        }

        return self::$pages[$page];
    }
}
