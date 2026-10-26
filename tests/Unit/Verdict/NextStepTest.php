<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Composer\Package\BasePackage;
use Composer\Package\Loader\ArrayLoader;
use Lockrot\Clock;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Data\Repository\StableRelease;
use Lockrot\Lock\LockFile;
use Lockrot\Security\Candidate;
use Lockrot\Security\Fix;
use Lockrot\Signal\BranchRow;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\PhpFloor;
use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\Move;
use Lockrot\Verdict\MoveDraft;
use Lockrot\Verdict\NextStep;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NextStepTest extends TestCase
{
    private const NOW = '2026-10-08T00:00:00+00:00';

    public function testAMergeKeepsTheLeadReleaseWhenTheOtherMoveNamesNoRelease(): void
    {
        $row = self::row();
        $candidate = new Candidate(new StableRelease('2.0.0.0', '2.0.0', null, null, false), '2.x', Fix::UPGRADE, []);
        $security = MoveDraft::of(Move::REQUIRE, FlagSet::VULNERABLE)->to($row, $candidate, '^2.0.0')->withFixKind(Fix::UPGRADE);
        $maintenance = MoveDraft::of(Move::REQUIRE, FlagSet::LEFT_BEHIND)->to($row, null, '^2.5');

        $merged = NextStep::merge($security, $maintenance);
        $lower = $merged->lower();

        self::assertNotNull($lower);
        self::assertSame(['2.0.0', Move::REQUIRE, '^2.0.0'], [$lower->release()->pretty(), $merged->kind(), $merged->constraint()]);
    }

    /** @return iterable<string, array{string}> SPEC §5.6, choice of move, rule 4: the cases of a merge that Composer's solver refuses */
    public static function splitCases(): iterable
    {
        yield 'the security update resolves alone' => ['mc-split‡'];
        yield 'both moves are refused' => ['mc-split-both-refused‡'];
        yield 'a merge that is not checked stays merged' => ['mc-split-not-checked‡'];
        yield 'the larger maintenance part' => ['mc-split-maintenance-larger‡'];
    }

    /**
     * @dataProvider splitCases
     */
    #[DataProvider('splitCases')]
    public function testARefusedMergeSplitsAndTheSecurityMoveLeadsWithItsOwnCheck(string $id): void
    {
        $case = CaseHydrator::case($id);
        $package = JsonPath::stringAt($case, ['finding', 'package']);
        $findings = JsonPath::arrayAt(JsonPath::decoded(CaseHydrator::report($case)->toArray()), ['findings']);
        $finding = array_values(array_filter($findings, static fn ($each): bool => \is_array($each) && ($each['package'] ?? null) === $package))[0] ?? [];
        self::assertIsArray($finding);

        self::assertSame(self::split(JsonPath::arrayAt($case, ['finding', 'next_step'])), self::split(JsonPath::arrayAt($finding, ['next_step'])), $id);
    }

    /**
     * @param array<mixed, mixed> $move
     *
     * @return array<mixed, mixed> the fields of a move and of its `also[]` moves that the split sets
     */
    private static function split(array $move): array
    {
        $fields = static fn ($each): array => \is_array($each) ? [$each['kind'] ?? null, $each['source'] ?? null, $each['merged'] ?? null, $each['constraint'] ?? null, $each['check'] ?? null, $each['clears'] ?? null] : [];

        return [$fields($move), array_map($fields, \is_array($move['also'] ?? null) ? $move['also'] : [])];
    }

    private static function row(): BranchRow
    {
        $release = (new ArrayLoader())->load(['name' => 'a/b', 'version' => '2.0.0', 'time' => '2026-01-01T00:00:00+00:00']);
        self::assertInstanceOf(BasePackage::class, $release);
        $locked = LockFile::fromArray(['packages' => [['name' => 'a/b', 'version' => '1.0.0', 'notification-url' => 'https://packagist.org/downloads/']]])->find('a/b');
        self::assertNotNull($locked);
        $facts = new PackageFacts($locked, PackageMetadata::fromPackages('a/b', [$release], new \DateTimeImmutable(self::NOW), '1.0.0.0'), null, []);

        return BranchRow::all($facts, new PhpFloor('8.4'), Clock::fixed(self::NOW))[0];
    }
}
