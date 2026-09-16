<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal\Rule;

use Lockrot\Clock;
use Lockrot\Data\Forge\RepoRef;
use Lockrot\Signal\Rule\ArchivedRule;
use Lockrot\Signal\Rule\NoPushRule;
use Lockrot\Signal\Signal;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use PHPUnit\Framework\TestCase;

final class RepositoryRulesTest extends TestCase
{
    public function testArchived(): void
    {
        $signal = (new ArchivedRule())->evaluate(F::facts(F::package(), null, F::activity(true, '2020-01-01')));
        self::assertNotNull($signal);
        self::assertSame(Signal::S3, $signal->id());
        self::assertSame(Signal::LEVEL_HIGH, $signal->level());
        self::assertSame('repository archived on GitHub', $signal->summary());
        self::assertSame(['repo' => 'vendor/pkg', 'host' => 'github.com'], $signal->data());
        self::assertNull((new ArchivedRule())->evaluate(F::facts(F::package(), null, F::activity(false, '2020-01-01'))));
        self::assertNull((new ArchivedRule())->evaluate(F::facts(F::package())));
    }

    public function testNoPushLevels(): void
    {
        $rule = new NoPushRule(Clock::fixed(F::NOW), new Thresholds());
        $high = $rule->evaluate(F::facts(F::package(), null, F::activity(false, '2015-11-16T16:31:37Z')));
        self::assertNotNull($high);
        self::assertSame(Signal::S4, $high->id());
        self::assertSame(Signal::LEVEL_HIGH, $high->level());
        self::assertSame('last push 2015-11-16 (10.8 years ago)', $high->summary());
        self::assertSame([
            'last_push' => '2015-11-16T16:31:37+00:00',
            'repo' => 'vendor/pkg',
            'host' => 'github.com',
            'years' => 10.8,
        ], $high->data());
        $warn = $rule->evaluate(F::facts(F::package(), null, F::activity(false, '2022-01-01')));
        self::assertNotNull($warn);
        self::assertSame(Signal::LEVEL_WARN, $warn->level());
        self::assertNull($rule->evaluate(F::facts(F::package(), null, F::activity(false, '2025-08-01'))));
        self::assertNull($rule->evaluate(F::facts(F::package(), null, F::activity(false, null))));
        self::assertNull($rule->evaluate(F::facts(F::package())));
    }

    /** Each forge names what it measured: GitHub a push, GitLab and Bitbucket the newest commit. */
    public function testTheSummariesNameTheForgeAndWhatItMeasured(): void
    {
        $archived = (new ArchivedRule())->evaluate(F::facts(F::package(), null, F::activity(true, '2020-01-01', RepoRef::GITLAB)));
        self::assertNotNull($archived);
        self::assertSame('repository archived on GitLab', $archived->summary());
        self::assertSame(['repo' => 'vendor/pkg', 'host' => 'gitlab.com'], $archived->data());

        $rule = new NoPushRule(Clock::fixed(F::NOW), new Thresholds());
        foreach ([RepoRef::GITLAB => 'gitlab.com', RepoRef::BITBUCKET => 'bitbucket.org'] as $forge => $host) {
            $signal = $rule->evaluate(F::facts(F::package(), null, F::activity(false, '2015-11-16T16:31:37Z', $forge)));
            self::assertNotNull($signal);
            self::assertSame('last commit 2015-11-16 (10.8 years ago)', $signal->summary(), $forge);
            self::assertSame($host, $signal->data()['host'], $forge);
        }
    }

    public function testCustomThresholds(): void
    {
        $rule = new NoPushRule(Clock::fixed(F::NOW), new Thresholds(3, 5, 1, 2));
        $signal = $rule->evaluate(F::facts(F::package(), null, F::activity(false, '2024-01-01')));
        self::assertNotNull($signal);
        self::assertSame(Signal::LEVEL_HIGH, $signal->level());
    }
}
