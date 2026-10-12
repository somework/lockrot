<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Exception\ConfigException;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Verdict\FailOn;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** SPEC §6.1: the forms, kinds, the canonical `run.fail_on` and the errors of `--fail-on`. */
final class FailOnTest extends TestCase
{
    public function testTheAllowedValuesAreNoneThenEachKindInOrder(): void
    {
        self::assertSame(['none', 'critical', 'high', 'medium', 'low', 'abandoned', 'silent', 'pinned', 'left-behind', 'old-promise', 'stale', 'vulnerable', 'vulnerable-medium', 'vulnerable-high', 'vulnerable-critical', 'unchecked'], FailOn::allowed());
    }

    /** @return iterable<string, array{string, string, string, ?string}> */
    public static function oneValue(): iterable
    {
        yield 'a grade' => ['high', 'high', 'grade', 'high'];
        yield 'a flag' => ['left-behind', 'left-behind', 'flag', 'left-behind'];
        yield 'any advisory' => ['vulnerable', 'vulnerable', 'vulnerable', 'low'];
        yield 'a raw severity' => ['vulnerable-high', 'vulnerable-high', 'vulnerable', 'high'];
        yield 'unchecked' => ['unchecked', 'unchecked', 'unchecked', null];
        yield 'upper case, padded' => ['  Critical ', 'critical', 'grade', 'critical'];
    }

    #[DataProvider('oneValue')]
    public function testEachValueHasItsKindAndThreshold(string $input, string $value, string $kind, ?string $threshold): void
    {
        $failOn = FailOn::parse($input);

        self::assertSame($value, $failOn->value());
        self::assertSame([['value' => $value, 'kind' => $kind, 'threshold' => $threshold]], JsonPath::decoded($failOn->values()));
    }

    /** @return iterable<string, array{string|list<string>, string}> */
    public static function lists(): iterable
    {
        yield 'kind order, whatever the input order' => ['vulnerable-high,left-behind', 'left-behind,vulnerable-high'];
        yield 'whitespace around commas' => ['left-behind, vulnerable-high', 'left-behind,vulnerable-high'];
        yield 'every kind' => ['unchecked,vulnerable,stale,low', 'low,stale,vulnerable,unchecked'];
        yield 'a JSON array from config' => [['vulnerable-high', ' left-behind'], 'left-behind,vulnerable-high'];
        yield 'none' => ['none', 'none'];
    }

    /** @param string|list<string> $input */
    #[DataProvider('lists')]
    public function testTheCanonicalFormIsTheValuesInKindOrderJoinedByCommas($input, string $canonical): void
    {
        self::assertSame($canonical, FailOn::parse($input)->value());
    }

    public function testNoneHasNoValueAndNoKind(): void
    {
        self::assertTrue(FailOn::parse('none')->isNone());
        self::assertSame([], FailOn::parse('none')->values());
        self::assertTrue(FailOn::none()->isNone());
        self::assertFalse(FailOn::parse('high')->isNone());
    }

    public function testAKindIsFoundByItsName(): void
    {
        $failOn = FailOn::parse('stale,vulnerable-high');

        self::assertSame(['flag', 'vulnerable'], $failOn->kinds());
        self::assertTrue($failOn->has('flag'));
        self::assertFalse($failOn->has('grade'));
    }

    /** @return iterable<string, array{string|list<string>, string}> */
    public static function rejected(): iterable
    {
        yield 'two grades' => ['high,medium', '"high" and "medium" are both grades'];
        yield 'two flags' => ['stale, left-behind', '"stale" and "left-behind" are both flags'];
        yield 'two severities' => ['vulnerable,vulnerable-high', '"vulnerable" and "vulnerable-high" are both vulnerable values'];
        yield 'one value twice' => ['unchecked,unchecked', '"unchecked" is given twice'];
        yield 'none with another value' => ['none,high', '"none" stands alone'];
        yield 'an unknown value' => ['dead', 'unknown value "dead"'];
        yield 'an empty item' => ['high,,stale', 'unknown value ""'];
        yield 'empty' => ['', 'unknown value ""'];
        yield 'vulnerable-low' => ['vulnerable-low', 'use "vulnerable": every counted advisory is at least low'];
        yield 'an array with an unknown value' => [['high', 'dead'], 'unknown value "dead"'];
    }

    /** @param string|list<string> $input */
    #[DataProvider('rejected')]
    public function testAnInvalidListIsRejectedWithItsReason($input, string $message): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($message);

        FailOn::parse($input, 'LOCKROT_FAIL_ON');
    }

    public function testTheMessageNamesTheSource(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('extra.lockrot.fail-on: unknown value "dead"');

        FailOn::parse('dead', 'extra.lockrot.fail-on');
    }
}
