<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Config\ConfigSchema;
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
        self::assertSame(['none', 'critical', 'high', 'medium', 'low', 'abandoned', 'silent', 'pinned', 'left-behind', 'old-promise', 'stale', 'capped', 'vulnerable', 'vulnerable-medium', 'vulnerable-high', 'vulnerable-critical', 'unchecked'], FailOn::allowed());
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
        yield 'padded with each character of \s in the config-2 pattern' => [" \t\n\x0B\f\rhigh \t\n\x0B\f\r", 'high', 'grade', 'high'];
    }

    /** @dataProvider oneValue */
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
        yield 'a form feed around a comma' => ["high\f,\fstale", 'high,stale'];
        yield 'none' => ['none', 'none'];
    }

    /**
     * @param string|list<string> $input
     *
     * @dataProvider lists
     */
    #[DataProvider('lists')]
    public function testTheCanonicalFormIsTheValuesInKindOrderJoinedByCommas($input, string $canonical): void
    {
        self::assertSame($canonical, FailOn::parse($input)->value());
    }

    /** @return iterable<string, array{string, string}> */
    public static function unicodeWhitespace(): iterable
    {
        yield 'a next line after the value' => ["high\u{0085}", 'high'];
        yield 'a no-break space after the value' => ["high\u{00A0}", 'high'];
        yield 'both around a comma' => ["\u{00A0}high\u{0085},\u{00A0}stale\u{0085}", 'high,stale'];
    }

    /**
     * The config-2 pattern runs as a `/u` regex, so its `\s` matches Unicode whitespace.
     *
     * @dataProvider unicodeWhitespace
     */
    #[DataProvider('unicodeWhitespace')]
    public function testAValueThatTheConfigSchemaAcceptsParses(string $input, string $canonical): void
    {
        ConfigSchema::validate(['fail-on' => $input]);

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
        yield 'vulnerable-low' => ['vulnerable-low', 'LOCKROT_FAIL_ON: vulnerable-low is not a value: use "vulnerable": every counted advisory is at least low'];
        yield 'an array with an unknown value' => [['high', 'dead'], 'unknown value "dead"'];
        yield 'a byte that is not UTF-8' => ["high\xFF", "unknown value \"high\xFF\""];
    }

    /**
     * @param string|list<string> $input
     *
     * @dataProvider rejected
     */
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

    public function testAnUpperCaseIParsesUnderTheTurkishLocale(): void
    {
        $previous = setlocale(\LC_CTYPE, '0');
        if (setlocale(\LC_CTYPE, 'tr_TR.UTF-8', 'tr_TR', 'tr_TR.ISO8859-9') === false) {
            self::markTestSkipped('No Turkish locale is installed.');
        }

        try {
            self::assertSame('unchecked', FailOn::parse('UNCHECKED')->value());
            self::assertSame('high', FailOn::parse('HIGH')->value());
        } finally {
            setlocale(\LC_CTYPE, $previous === false ? 'C' : $previous);
        }
    }

    public function testANonAsciiByteStaysAsItIsWhenLowerCasing(): void
    {
        try {
            FailOn::parse("H\xC4\xB0GH\xDD");
            self::fail('The value is not a known one.');
        } catch (ConfigException $e) {
            self::assertStringContainsString("\"h\xC4\xB0gh\xDD\"", $e->getMessage());
        }
    }
}
