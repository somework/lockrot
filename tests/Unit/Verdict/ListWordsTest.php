<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Verdict\ListWords;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ListWordsTest extends TestCase
{
    /**
     * @dataProvider lists
     *
     * @param list<string> $items
     */
    #[DataProvider('lists')]
    public function testAListPutsAndBeforeItsLastItemAndCommasBetweenTheOthers(array $items, string $words): void
    {
        self::assertSame($words, ListWords::of($items));
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function lists(): iterable
    {
        yield 'none' => [[], ''];
        yield 'one' => [['S2'], 'S2'];
        yield 'two' => [['S2', 'S4'], 'S2 and S4'];
        yield 'three' => [['S2', 'S4', 'the advisory'], 'S2, S4 and the advisory'];
        yield 'four' => [['a/b', 'c/d', 'e/f', '2 more'], 'a/b, c/d, e/f and 2 more'];
        yield 'an item with a comma' => [['a, b', 'c'], 'a, b and c'];
    }
}
