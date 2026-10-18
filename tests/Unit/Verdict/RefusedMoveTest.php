<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Solver\MoveCheck;
use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\FindingRelations;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Verdict\MoveText;
use PHPUnit\Framework\TestCase;

/**
 * Case `move-check‡` with its one recorded check changed: typo3/html-sanitizer v2.2.0 has one
 * release above it, v2.3.2, so a refusal of `^2.3.2` leaves no other release to move to.
 */
final class RefusedMoveTest extends TestCase
{
    private const MOVE = 'update typo3/html-sanitizer ^2.3.2 ';

    public function testARefusalThatNamesNoHoldBlocksTheReleaseAndIsNoReachableFix(): void
    {
        $document = self::document(['result' => 'refused', 'form' => null, 'reason' => null, 'holds' => []]);
        $finding = self::finding($document);

        self::assertSame(['blocked', 'blocked'], [JsonPath::stringAt($finding, ['next_step', 'kind']), JsonPath::stringAt($finding, ['next_step', 'fix_kind'])]);
        self::assertSame('refused', JsonPath::stringAt($finding, ['next_step', 'check', 'result']));
        self::assertSame([], FindingRelations::violations($document));
        self::assertSame('blocked', JsonPath::stringAt($finding, ['security', 'fix_kind']), 'the verdict counts no reachable fix');
        self::assertNull(JsonPath::arrayAt($finding, ['security'])['gets'], 'composer update installs no release that Composer refuses');
    }

    public function testTheTextOfAMoveThatComposerRefusesWithNoHoldNamesTheRefusal(): void
    {
        $case = CaseHydrator::case('move-check‡');
        $inputs = JsonPath::arrayAt($case, ['inputs']);
        $inputs['checks'] = [self::MOVE => ['result' => 'refused', 'form' => null, 'reason' => null, 'holds' => []]];
        $case['inputs'] = $inputs;
        $finding = CaseHydrator::analysisOf($case)->finding('typo3/html-sanitizer');
        self::assertNotNull($finding);
        $move = $finding->details()->moves()->nextStep();
        self::assertNotNull($move);

        self::assertSame("no move installs 2.x: Composer names no hold; Composer's solver refuses it against the lock", MoveText::render($move));
    }

    /**
     * One pass at the cap: the refusal of that pass still sets the class, so the move becomes a
     * `require`, and its new request is not asked.
     */
    public function testAtThePassCapTheLastRefusalStillSetsTheClassAndTheNextRequestIsNotAsked(): void
    {
        $case = CaseHydrator::case('move-check‡');
        $inputs = JsonPath::arrayAt($case, ['inputs']);
        $inputs['checks'] = [self::MOVE => ['result' => 'refused', 'form' => null, 'reason' => null, 'holds' => [['source' => 'root', 'package' => null, 'version' => null, 'link' => 'conflict', 'target' => null, 'constraint' => '>=2.3']]]];
        $inputs['check_passes'] = 1;
        $case['inputs'] = $inputs;
        $document = JsonPath::decoded(CaseHydrator::report($case)->toArray());
        $finding = self::finding($document);

        self::assertSame(['require', 'upgrade', 'not_checked', MoveCheck::PASS_CAP], [JsonPath::stringAt($finding, ['next_step', 'kind']), JsonPath::stringAt($finding, ['next_step', 'fix_kind']), JsonPath::stringAt($finding, ['next_step', 'check', 'result']), JsonPath::stringAt($finding, ['next_step', 'check', 'reason'])]);
        self::assertSame('upgrade', JsonPath::stringAt($finding, ['security', 'fix_kind']));
        self::assertSame([], FindingRelations::violations($document));
    }

    /** @return iterable<string, array{array<string, ?string>, string}> */
    public static function holds(): iterable
    {
        yield 'a release that replaces another package' => [['source' => 'package', 'package' => 'acme/fork', 'version' => '1.0.0', 'link' => 'replace', 'target' => 'acme/legacy', 'constraint' => '2.0.*'], 'held by acme/fork 1.0.0 (replaces acme/legacy 2.0.*)'];
        yield 'a package that replaces the moved one' => [['source' => 'package', 'package' => 'acme/fork', 'version' => '1.0.0', 'link' => 'replace', 'target' => null, 'constraint' => '*'], 'held by acme/fork 1.0.0 (replaces it)'];
        yield 'a requirement that no release in the pool meets' => [['source' => 'root', 'package' => null, 'version' => null, 'link' => 'root-require', 'target' => null, 'constraint' => '^2.3.2'], 'held by composer.json (no release of ^2.3.2 can install)'];
        yield 'a conflict that composer.json declares' => [['source' => 'root', 'package' => null, 'version' => null, 'link' => 'conflict', 'target' => null, 'constraint' => '>=2.3'], 'held by composer.json (conflicts with >=2.3)'];
    }

    /**
     * @dataProvider holds
     *
     * @param array<string, ?string> $hold
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('holds')]
    public function testTheTextOfARefusedMoveNamesEachHoldInWords(array $hold, string $words): void
    {
        $finding = self::finding(self::document(['result' => 'refused', 'form' => null, 'reason' => null, 'holds' => [$hold]]));

        self::assertStringContainsString($words, JsonPath::stringAt($finding, ['next_step', 'text']));
    }

    /**
     * @param array<string, mixed> $check
     *
     * @return array<mixed, mixed>
     */
    private static function document(array $check): array
    {
        $case = CaseHydrator::case('move-check‡');
        $inputs = JsonPath::arrayAt($case, ['inputs']);
        $inputs['checks'] = [self::MOVE => $check];
        $case['inputs'] = $inputs;

        return JsonPath::decoded(CaseHydrator::report($case)->toArray());
    }

    /**
     * @param array<mixed, mixed> $document
     *
     * @return array<mixed, mixed>
     */
    private static function finding(array $document): array
    {
        foreach (JsonPath::arrayAt($document, ['findings']) as $finding) {
            if (\is_array($finding) && ($finding['package'] ?? null) === 'typo3/html-sanitizer') {
                return $finding;
            }
        }
        self::fail('the report has no finding of typo3/html-sanitizer');
    }
}
