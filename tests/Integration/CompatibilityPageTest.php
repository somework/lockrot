<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Tests\Support\ClosedSets;
use Lockrot\Tests\Support\DocsPages;
use Lockrot\Verdict\ScoreModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * docs/compatibility.md states the order of the closed sets lockrot freezes for 1.x, and
 * docs/verdicts.md states the severity order. Both are read here against the code, so a page and
 * the engine cannot disagree about which verdict is worse. ClosedSetsTest holds the code and the
 * schemas to the same lists.
 *
 * `mkdocs build --strict` in CI checks that every page is in the navigation and every link's anchor
 * exists, through the `validation` block in mkdocs.yml.
 */
final class CompatibilityPageTest extends TestCase
{
    private const DOCS = __DIR__.'/../../docs/';

    /** The two pages that list the verdicts in verdict order. */
    private const VERDICT_PAGES = ['compatibility.md', 'verdicts.md'];

    /** @var array<string, string> page => contents, read once */
    private static array $pages = [];

    public function testThePageStatesTheOrdersTheCodeKeeps(): void
    {
        $page = self::prose('compatibility.md');
        self::assertStringContainsString('Grades, highest first: `'.implode('`, `', ScoreModel::GRADES).'`.', $page);
        self::assertStringContainsString('Signal levels, lowest first: `'.implode('`, `', ClosedSets::levels()).'`.', $page);
        self::assertStringContainsString("A finding's standing against the baseline: `".implode('`, `', ClosedSets::standings()).'`.', $page);
        self::assertStringContainsString("A finding's `activity.status`: `".implode('`, `', ClosedSets::activityStatuses()).'`.', $page);
    }

    /**
     * The page's list of closed sets is the registry, set for set and value for value, so the page
     * cannot call an open set closed or leave a closed one out.
     */
    public function testThePageListsExactlyTheClosedSetsOfReport2(): void
    {
        $listed = [];
        foreach (self::closedSetItems() as $item) {
            if (preg_match('/^(.+): ((?:`[^`]+`(?:, )?)+)\.$/', $item, $match) !== 1) {
                self::fail('a closed-set line reads "<set>: `a`, `b`.": '.$item);
            }
            preg_match_all('/`([^`]+)`/', $match[2], $values);
            $listed[$match[1]] = $values[1];
        }

        self::assertSame(ClosedSets::report2Values(), $listed);
    }

    /** The plugin does nothing below the Composer floor, so the page promises no reduced check there. */
    public function testThePagePromisesNoReducedPluginCheckOnAnOlderComposer(): void
    {
        self::assertStringNotContainsString('checks less under an older', self::prose('compatibility.md'));
    }

    /**
     * The plugin does nothing below the Composer floor (`LockrotPlugin::COMPOSER_FLOOR`), so no page
     * names an older Composer as a condition of lockrot's behaviour. The allowed hits state the floor.
     */
    public function testNoPageQualifiesABehaviourByAComposerBelowTheFloor(): void
    {
        $hits = [];
        foreach (array_merge(array_keys(DocsPages::in(self::DOCS)), ['../SECURITY.md', '../README.md']) as $page) {
            preg_match_all('/Composer 2\.[2-9](?!\d)\S*(?: \S+){0,3}/', self::prose($page), $found);
            foreach ($found[0] as $hit) {
                $hits[] = $page.': '.$hit;
            }
        }

        self::assertSame([
            'changes/0.14.md: Composer 2.8 and older do',
            'changes/0.14.md: Composer 2.9, it prints one',
        ], $hits);
    }

    public function testThePagePromisesNoLockrotActionMajorLine(): void
    {
        self::assertStringNotContainsString('lockrot-action@v1', self::read('compatibility.md'));
    }

    /**
     * The SARIF rules follow the order the results first use them (SarifFormatterTest pins that),
     * so neither page can name them among the lists kept in verdict order.
     */
    public function testThePagesDoNotClaimVerdictOrderForTheSarifRules(): void
    {
        self::assertStringContainsString('the SARIF rules appear in the order the results first use them', self::prose('compatibility.md'));

        foreach (self::VERDICT_PAGES as $page) {
            $text = self::prose($page);
            self::assertStringContainsString('`counts`, `run.verdicts`, the schema enums, this page', $text, $page);
            self::assertDoesNotMatchRegularExpression('/Where lockrot lists verdicts[^.;]*SARIF/', $text, $page.' lists the SARIF rules among the verdict-ordered lists');
        }
    }

    public function testThePageSaysItIsADraft(): void
    {
        self::assertStringContainsString('!!! warning "Draft until 1.0.0-RC1"', self::read('compatibility.md'));
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
        foreach (array_keys(DocsPages::in(self::DOCS)) as $page) {
            yield $page => [$page];
        }
    }

    public function testThePagesIncludeTheNestedOnes(): void
    {
        $pages = array_keys(iterator_to_array(self::pages()));

        self::assertContains('verdicts/model-1.md', $pages);
        self::assertContains('changes/0.14.md', $pages);
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

    /**
     * The items of the first list in "Closed sets and their order", each read as one line.
     *
     * @return list<string>
     */
    private static function closedSetItems(): array
    {
        $page = self::read('compatibility.md');
        $start = strpos($page, "\n### Closed sets and their order\n");
        self::assertNotFalse($start);
        if (preg_match('/\n((?:- .*\n(?: {4}.*\n)*)+)/', substr($page, $start), $list) !== 1) {
            self::fail('the closed sets section opens with a list');
        }

        return array_map(
            static fn (string $item): string => trim((string) preg_replace('/\s+/', ' ', $item)),
            array_values(array_filter(preg_split('/^- /m', $list[1]) ?: [], static fn (string $item): bool => trim($item) !== ''))
        );
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
