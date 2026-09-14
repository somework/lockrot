<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Config;

use Lockrot\Analyzer\Report;
use Lockrot\Config\LockrotConfig;
use Lockrot\Config\Policy;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\Verdict;
use PHPUnit\Framework\TestCase;

final class PolicyTest extends TestCase
{
    /** @param list<string> $verdicts */
    private function report(array $verdicts, bool $networkFailures = false): Report
    {
        $findings = [];
        foreach ($verdicts as $i => $verdict) {
            $findings[] = new Finding('v/p'.$i, '1.0.0', $verdict, [], ['v/p'.$i], null, null);
        }

        return new Report($findings, [], new \DateTimeImmutable('2026-09-14T00:00:00+00:00'), \count($findings), 0, $networkFailures);
    }

    private function config(string $failOn, bool $strict = false): LockrotConfig
    {
        return LockrotConfig::fromSources([], [], ['fail-on' => $failOn, 'strict-network' => $strict], '8.4.0', null);
    }

    public function testNoneNeverFails(): void
    {
        self::assertSame(0, Policy::exitCode($this->report([Verdict::ABANDONED]), $this->config('none')));
    }

    public function testThresholdInclusive(): void
    {
        self::assertSame(1, Policy::exitCode($this->report([Verdict::SILENT]), $this->config('silent')));
        self::assertSame(1, Policy::exitCode($this->report([Verdict::ABANDONED]), $this->config('silent')));
        self::assertSame(0, Policy::exitCode($this->report([Verdict::STALE, Verdict::PINNED]), $this->config('silent')));
        self::assertSame(1, Policy::exitCode($this->report([Verdict::STALE]), $this->config('stale')));
        self::assertSame(0, Policy::exitCode($this->report([Verdict::UNKNOWN, Verdict::FINISHED, Verdict::OK]), $this->config('stale')));
    }

    public function testStrictNetwork(): void
    {
        self::assertSame(0, Policy::exitCode($this->report([], true), $this->config('none')));
        self::assertSame(1, Policy::exitCode($this->report([], true), $this->config('none', true)));
    }
}
