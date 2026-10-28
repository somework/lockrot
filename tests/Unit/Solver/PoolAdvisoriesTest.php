<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Solver;

use Composer\Advisory\PartialSecurityAdvisory;
use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\VersionParser;
use Lockrot\Solver\AdvisoryBlocking;
use Lockrot\Solver\PoolAdvisories;
use PHPUnit\Framework\TestCase;

/** The answers that Composer's `SecurityAdvisoryPoolFilter` reads, asked as it asks a repository. */
final class PoolAdvisoriesTest extends TestCase
{
    /** @var list<array{list<string>, bool}> */
    private array $asked = [];

    public function testThePartialAnswerAsksTheLookupForPartialRecordsEvenOfASeededName(): void
    {
        $advisories = $this->advisories()->withRecords(['acme/covered' => [self::advisory('acme/covered', '<1.0')]], ['acme/covered']);

        $answer = $advisories->answer(self::map('acme/covered', 'acme/new'), true);

        self::assertSame([[['acme/covered', 'acme/new'], false]], $this->asked);
        self::assertSame(['acme/covered' => ['<2.0'], 'acme/new' => ['<2.0']], self::pretty($answer['advisories']));
    }

    public function testTheCompleteAnswerServesTheSeededRecordsWithoutALookup(): void
    {
        $advisories = $this->advisories()->withRecords(['acme/covered' => [self::advisory('acme/covered', '<1.0')]], ['acme/covered', 'acme/clean']);

        $answer = $advisories->answer(self::map('acme/covered', 'acme/clean'), false);

        self::assertSame([], $this->asked);
        self::assertSame([['acme/covered'], ['acme/covered' => ['<1.0']], []], [$answer['namesFound'], self::pretty($answer['advisories']), $answer['unread']]);
    }

    public function testEachKindAsksTheLookupOncePerName(): void
    {
        $advisories = $this->advisories();

        $advisories->answer(self::map('acme/new'), true);
        $advisories->answer(self::map('acme/new', 'acme/other'), true);
        $advisories->answer(self::map('acme/new'), false);

        self::assertSame([[['acme/new'], false], [['acme/other'], false], [['acme/new'], true]], $this->asked);
    }

    /** A repository answers only the records whose range meets the versions that the filter asks about. */
    public function testARecordOutsideTheAskedConstraintIsNotAnswered(): void
    {
        $advisories = $this->advisories()->withRecords(['acme/lib' => [self::advisory('acme/lib', '<1.0'), self::advisory('acme/lib', '>=2.0,<2.1')]], ['acme/lib']);

        $answer = $advisories->answer(['acme/lib' => (new VersionParser())->parseConstraints('^2.0')], false);

        self::assertSame(['acme/lib' => ['>=2.0,<2.1']], self::pretty($answer['advisories']));
    }

    public function testANameWithNoRecordInTheAskedConstraintIsNotFound(): void
    {
        $advisories = $this->advisories()->withRecords(['acme/lib' => [self::advisory('acme/lib', '<1.0')]], ['acme/lib']);

        $answer = $advisories->answer(['acme/lib' => (new VersionParser())->parseConstraints('^2.0')], false);

        self::assertSame([[], [], []], [$answer['namesFound'], $answer['advisories'], $answer['unread']]);
    }

    public function testALookupThatCouldNotReadMarksItsNamesUnreadForThatKindOnly(): void
    {
        $advisories = new PoolAdvisories(AdvisoryBlocking::everyAdvisory(), function (array $names, bool $complete): ?array {
            $this->asked[] = [$names, $complete];

            return $complete ? [] : null;
        });

        $partial = $advisories->answer(self::map('acme/new'), true);
        $again = $advisories->answer(self::map('acme/new'), true);
        $complete = $advisories->answer(self::map('acme/new'), false);

        self::assertSame([['acme/new'], ['acme/new'], []], [$partial['unread'], $again['unread'], $complete['unread']]);
        self::assertSame([[['acme/new'], false], [['acme/new'], true]], $this->asked);
    }

    /** Ruling R2-47: one repository answered acme/partial and another failed, so the run's lookup did not read it in full. */
    public function testANameWithRecordsThatTheRunsLookupDidNotReadInFullIsUnreadAndNotAskedAgain(): void
    {
        $advisories = $this->advisories()->withRecords(['acme/partial' => [self::advisory('acme/partial', '<1.0')]], ['acme/read']);

        $answer = $advisories->answer(self::map('acme/partial', 'acme/read'), false);

        self::assertSame(['acme/partial'], $answer['unread']);
        self::assertSame([], $this->asked);
    }

    public function testANameThatALaterLookupReadsInFullIsNoLongerUnread(): void
    {
        $partial = $this->advisories()->withRecords(['acme/partial' => [self::advisory('acme/partial', '<1.0')]], []);

        $read = $partial->withRecords(['acme/partial' => [self::advisory('acme/partial', '<1.0')]], ['acme/partial']);

        self::assertSame([[], ['acme/partial']], [$read->answer(self::map('acme/partial'), false)['unread'], $partial->answer(self::map('acme/partial'), false)['unread']]);
    }

    /** Ruling R2-24: under `advisory-lookup: composer-repositories` a name that no Composer repository serves is never sent. */
    public function testAKeptOutNameIsNeverAskedAndStaysUnread(): void
    {
        $advisories = new PoolAdvisories(AdvisoryBlocking::everyAdvisory(), function (array $names, bool $complete): array {
            $this->asked[] = [$names, $complete];

            return [];
        }, ['acme/vcs']);

        $partial = $advisories->answer(self::map('acme/vcs', 'acme/new'), true);
        $complete = $advisories->answer(self::map('acme/vcs'), false);

        self::assertSame([[['acme/new'], false]], $this->asked);
        self::assertSame([['acme/vcs'], ['acme/vcs']], [$partial['unread'], $complete['unread']]);
    }

    public function testAKeptOutNameThatTheRunsLookupReadStaysRead(): void
    {
        $advisories = (new PoolAdvisories(AdvisoryBlocking::everyAdvisory(), static fn (array $names, bool $complete): array => [], ['acme/vcs']))->withRecords([], ['acme/vcs']);

        self::assertSame([], $advisories->answer(self::map('acme/vcs'), false)['unread']);
    }

    public function testACopyWithTheRunsRecordsLeavesTheOriginalWithoutThem(): void
    {
        $original = $this->advisories();

        $original->withRecords(['acme/covered' => [self::advisory('acme/covered', '<1.0')]], ['acme/covered']);

        self::assertSame(['acme/covered' => ['<2.0']], self::pretty($original->answer(self::map('acme/covered'), false)['advisories']));
        self::assertSame([[['acme/covered'], true]], $this->asked);
    }

    private function advisories(): PoolAdvisories
    {
        return new PoolAdvisories(AdvisoryBlocking::everyAdvisory(), function (array $names, bool $complete): array {
            $this->asked[] = [$names, $complete];

            return array_map(static fn (string $name): array => [self::advisory($name, '<2.0')], array_combine($names, $names));
        });
    }

    /** @return array<string, ConstraintInterface> each name => every version */
    private static function map(string ...$names): array
    {
        return array_fill_keys($names, (new VersionParser())->parseConstraints('*'));
    }

    private static function advisory(string $name, string $affected): PartialSecurityAdvisory
    {
        return PartialSecurityAdvisory::create($name, ['advisoryId' => 'PKSA-1111-2222-3333', 'affectedVersions' => $affected], new VersionParser());
    }

    /**
     * @param array<string, list<PartialSecurityAdvisory>> $advisories
     *
     * @return array<string, list<string>> name => the range of each record, as Composer writes it
     */
    private static function pretty(array $advisories): array
    {
        return array_map(static fn (array $list): array => array_map(static fn (PartialSecurityAdvisory $advisory): string => $advisory->affectedVersions->getPrettyString(), $list), $advisories);
    }
}
