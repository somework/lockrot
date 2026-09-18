<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal\Rule;

use Lockrot\Data\Advisory\Advisory;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Rule\AdvisoryRule;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use PHPUnit\Framework\TestCase;

final class AdvisoryRuleTest extends TestCase
{
    private function advisory(string $id, ?string $cve = null): Advisory
    {
        return new Advisory($id, $cve, 'Title of '.$id, 'https://example.test/'.$id, 'high', new \DateTimeImmutable('2024-03-01T12:00:00+00:00'));
    }

    public function testNoAdvisoriesIsNull(): void
    {
        self::assertNull((new AdvisoryRule())->evaluate(F::facts(F::package())));
    }

    public function testOneAdvisoryIsNamedByItsCve(): void
    {
        $facts = new PackageFacts(F::package(['version' => 'v1.2.3']), null, null, [$this->advisory('PKSA-1', 'CVE-2024-0001')]);

        $signal = (new AdvisoryRule())->evaluate($facts);

        self::assertNotNull($signal);
        self::assertSame(Signal::S9, $signal->id());
        self::assertSame(Signal::LEVEL_WARN, $signal->level());
        self::assertSame('1 security advisory affects v1.2.3 (CVE-2024-0001)', $signal->summary());
        self::assertSame(['advisories' => [[
            'id' => 'PKSA-1',
            'cve' => 'CVE-2024-0001',
            'title' => 'Title of PKSA-1',
            'link' => 'https://example.test/PKSA-1',
            'severity' => 'high',
            'reported_at' => '2024-03-01T12:00:00+00:00',
        ]]], $signal->data());
    }

    public function testThreeAdvisoriesAreAllNamedAndAnIdStandsInForAMissingCve(): void
    {
        $facts = new PackageFacts(F::package(), null, null, [$this->advisory('PKSA-1', 'CVE-2024-0001'), $this->advisory('GHSA-aaaa'), $this->advisory('PKSA-3', 'CVE-2024-0003')]);

        $signal = (new AdvisoryRule())->evaluate($facts);

        self::assertNotNull($signal);
        self::assertSame('3 security advisories affect 1.0.0 (CVE-2024-0001, GHSA-aaaa, CVE-2024-0003)', $signal->summary());
    }

    public function testBeyondThreeTheRestAreCounted(): void
    {
        $advisories = [];
        foreach (range(1, 5) as $i) {
            $advisories[] = $this->advisory('PKSA-'.$i, 'CVE-2024-000'.$i);
        }
        $facts = new PackageFacts(F::package(), null, null, $advisories);

        $signal = (new AdvisoryRule())->evaluate($facts);

        self::assertNotNull($signal);
        self::assertSame('5 security advisories affect 1.0.0 (CVE-2024-0001, CVE-2024-0002, CVE-2024-0003 and 2 more)', $signal->summary());
        self::assertIsArray($signal->data()['advisories']);
        self::assertCount(5, $signal->data()['advisories']);
    }

    public function testExactlyThreeAreNotCounted(): void
    {
        $facts = new PackageFacts(F::package(), null, null, [$this->advisory('A'), $this->advisory('B'), $this->advisory('C')]);

        $signal = (new AdvisoryRule())->evaluate($facts);

        self::assertNotNull($signal);
        self::assertStringEndsWith('(A, B, C)', $signal->summary());
    }
}
