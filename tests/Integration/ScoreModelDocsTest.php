<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Tests\Support\JsonPath;
use Lockrot\Verdict\ScoreModel;
use PHPUnit\Framework\TestCase;

/**
 * Every report links each score rule as `run.score_model.docs` + `#` + the rule's `doc`. The page
 * behind that URL is docs/verdicts/model-1.md, and `site/` is not committed, so the anchors are
 * read from the Markdown.
 */
final class ScoreModelDocsTest extends TestCase
{
    private const PAGE = __DIR__.'/../../docs/verdicts/model-1.md';

    public function testTheModelLinksThePageOfItsOwnNumber(): void
    {
        self::assertSame('https://lockrot.dev/verdicts/model-'.ScoreModel::ID.'/', ScoreModel::toArray()['docs']);
        self::assertFileExists(__DIR__.'/../../docs/verdicts/model-'.ScoreModel::ID.'.md');
    }

    public function testThePageHoldsAnAnchorForEveryRuleInStageOrder(): void
    {
        $docs = JsonPath::column(ScoreModel::toArray(), ['rules'], 'doc');
        self::assertCount(\count(ScoreModel::RULES), $docs);

        self::assertSame($docs, self::anchors(), 'docs/verdicts/model-1.md: one `## … {#score-<rule id>}` heading per rule, in stage order');
    }

    /** @return list<string> the `{#score-…}` heading ids of the page, in page order */
    private static function anchors(): array
    {
        $page = file_get_contents(self::PAGE);
        self::assertIsString($page);
        preg_match_all('/^## .*\{#(score-[a-z0-9-]+)\}$/m', $page, $matches);

        return $matches[1];
    }
}
