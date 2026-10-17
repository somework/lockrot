<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Tests\Support\FiredSignalsUnblocked;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * {@see FiredSignalsUnblocked} holds on every document that lockrot recorded: the contract bundle
 * and the baselined projects. tools/corpus/differential.php holds it on lockrot's corpus documents.
 *
 * @coversNothing
 *
 * @group covers-nothing
 */
#[CoversNothing]
#[Group('covers-nothing')]
final class FiredSignalsUnblockedTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../fixtures/';
    private const RECORDED = ['contract/report-2/*.json', 'contract/explain-2/*.json', 'contract/baseline-2/*.json', 'baselined/*/*.json', 'baselined/*/*/*.json'];

    public function testNoRecordedFindingBlocksASignalThatFired(): void
    {
        $paths = [];
        foreach (self::RECORDED as $pattern) {
            $paths = array_merge($paths, glob(self::FIXTURES.$pattern) ?: []);
        }
        sort($paths);
        self::assertNotSame([], $paths);
        $broken = [];
        foreach ($paths as $path) {
            foreach (FiredSignalsUnblocked::violations(JsonPath::decodeFile($path)) as $violation) {
                $broken[] = substr($path, \strlen(self::FIXTURES)).' '.$violation;
            }
        }

        self::assertSame([], \array_slice($broken, 0, 20), \count($broken).' violations');
    }

    public function testAnS10ThatBlocksAFiredSignalIsAViolation(): void
    {
        $finding = ['package' => 'acme/a', 'signals' => [
            ['id' => 'S8', 'data' => []],
            ['id' => 'S9', 'data' => []],
            ['id' => 'S10', 'data' => ['unchecked' => [
                ['check' => 'release_dates', 'reason' => 'undated_releases', 'blocks' => ['S2', 'S8']],
                ['check' => 'releases', 'reason' => 'releases_unknown', 'blocks' => ['S9']],
            ]]],
        ]];

        self::assertSame(['acme/a: release_dates blocks S8, which fired'], FiredSignalsUnblocked::violations(['findings' => [$finding]]));
    }
}
