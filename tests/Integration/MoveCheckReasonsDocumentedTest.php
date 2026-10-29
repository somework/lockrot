<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Solver\MoveCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A user who meets a `check.reason` in a report looks it up in the move check section of
 * docs/verdicts.md, so each reason that lockrot writes is named there.
 */
final class MoveCheckReasonsDocumentedTest extends TestCase
{
    private const PAGE = __DIR__.'/../../docs/verdicts.md';

    /** @return iterable<string, array{string}> */
    public static function reasons(): iterable
    {
        foreach (MoveCheck::REASONS as $reason) {
            yield $reason => [$reason];
        }
    }

    /**
     * @dataProvider reasons
     */
    #[DataProvider('reasons')]
    public function testTheMoveCheckSectionNamesTheReason(string $reason): void
    {
        self::assertStringContainsString('`'.$reason.'`', self::moveCheckSection(), 'docs/verdicts.md#the-move-check does not name the check.reason `'.$reason.'`');
    }

    private static function moveCheckSection(): string
    {
        $page = (string) file_get_contents(self::PAGE);
        $start = strpos($page, '{#the-move-check}');
        self::assertNotFalse($start, 'docs/verdicts.md has the heading {#the-move-check}');
        $end = strpos($page, "\n### ", $start);

        return $end === false ? substr($page, $start) : substr($page, $start, $end - $start);
    }
}
