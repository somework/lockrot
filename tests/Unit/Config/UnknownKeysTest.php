<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Config;

use Lockrot\Config\UnknownKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnknownKeysTest extends TestCase
{
    public function testAConfigWithOnlyKnownKeysWarnsNothing(): void
    {
        $extra = array_fill_keys(UnknownKeys::KNOWN, 'any value: the schema judges values, this only reads names');
        $extra['ignore'] = [array_fill_keys(UnknownKeys::KNOWN_IN_IGNORE, 'x')];

        self::assertSame([], UnknownKeys::warnings($extra));
    }

    public function testAnEmptyConfigWarnsNothing(): void
    {
        self::assertSame([], UnknownKeys::warnings([]));
    }

    public function testATypoNamesTheNearestKnownKey(): void
    {
        self::assertSame(
            ['unknown key extra.lockrot.install-tme ignored (did you mean install-time?)'],
            UnknownKeys::warnings(['install-tme' => 'off'])
        );
    }

    /** @dataProvider typos */
    #[DataProvider('typos')]
    public function testATypoWithinReachSuggestsTheKeyItWasMeantToBe(string $typo, string $meant): void
    {
        self::assertSame(
            ['unknown key extra.lockrot.'.$typo.' ignored (did you mean '.$meant.'?)'],
            UnknownKeys::warnings([$typo => true])
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function typos(): iterable
    {
        // one edit away, the everyday typo
        yield 'camel case' => ['failOn', 'fail-on'];
        yield 'underscore' => ['include_dev', 'include-dev'];
        yield 'plural' => ['ignores', 'ignore'];
        // distance 2 on a 6-byte key: exactly a third, the edge of the threshold
        yield 'transposition at the threshold' => ['fromat', 'format'];
        // distance 0 only once both sides are compared in lower case
        yield 'upper case' => ['FORMAT', 'format'];
        // a key of three bytes or more that a known key contains
        yield 'substring of three bytes' => ['dev', 'include-dev'];
        yield 'another substring of three bytes' => ['php', 'target-php'];
        yield 'substring in upper case' => ['DEV', 'include-dev'];
        yield 'longer substring' => ['budget', 'install-time-budget'];
        // several substring matches: the nearest one wins
        yield 'the nearest of several substrings' => ['years', 'push-high-years'];
    }

    /** Distance 3 on a 7-byte key is past a third; a looser threshold would reach `format`. */
    public function testJustPastTheThresholdGetsNoSuggestion(): void
    {
        self::assertSame(['unknown key extra.lockrot.fromatt ignored'], UnknownKeys::warnings(['fromatt' => 'json']));
    }

    public function testAKeyNothingResemblesGetsNoSuggestion(): void
    {
        self::assertSame(['unknown key extra.lockrot.slack-webhook ignored'], UnknownKeys::warnings(['slack-webhook' => 'https://example.com']));
    }

    /**
     * `e` is in half the known keys and `on` is in `fail-on`: a key that short says nothing about
     * which setting was meant, so the substring rule needs three bytes.
     *
     * @dataProvider tooShortToBeASubstring
     */
    #[DataProvider('tooShortToBeASubstring')]
    public function testAKeyShorterThanThreeBytesIsNotMatchedAsASubstring(string $key): void
    {
        self::assertSame(['unknown key extra.lockrot.'.$key.' ignored'], UnknownKeys::warnings([$key => 1]));
    }

    /** @return iterable<string, array{string}> */
    public static function tooShortToBeASubstring(): iterable
    {
        yield 'one byte' => ['e'];
        yield 'two bytes' => ['on'];
    }

    /** `release-hirn-years` is two edits from both `release-high-years` and `release-warn-years`. */
    public function testATieGoesToTheAlphabeticallyFirstKey(): void
    {
        self::assertSame(
            ['unknown key extra.lockrot.release-hirn-years ignored (did you mean release-high-years?)'],
            UnknownKeys::warnings(['release-hirn-years' => 4])
        );
    }

    public function testExtensionsIsReservedAndNotWalked(): void
    {
        self::assertSame([], UnknownKeys::warnings(['extensions' => ['acme/x' => ['anything' => 1]]]));
    }

    /** Anything under `extensions` belongs to whoever reads it, whatever its shape. */
    public function testExtensionsIsReservedWhateverItHolds(): void
    {
        self::assertSame([], UnknownKeys::warnings(['extensions' => 'not even an object']));
    }

    public function testXPrefixedKeysAreReserved(): void
    {
        self::assertSame([], UnknownKeys::warnings(['x-acme' => 1, 'x-' => 2]));
    }

    /**
     * The prefix is exactly `x-`, at the start, in lower case: the OpenAPI convention it borrows.
     *
     * @dataProvider notReservedLookalikes
     */
    #[DataProvider('notReservedLookalikes')]
    public function testOnlyALeadingLowerCaseXDashIsReserved(string $key): void
    {
        $warnings = UnknownKeys::warnings([$key => 1]);

        self::assertCount(1, $warnings);
        self::assertStringStartsWith('unknown key extra.lockrot.'.$key.' ignored', $warnings[0]);
    }

    /** @return iterable<string, array{string}> */
    public static function notReservedLookalikes(): iterable
    {
        yield 'upper case' => ['X-acme'];
        yield 'further in' => ['my-x-key'];
        yield 'one byte in' => ['ax-foo'];
        yield 'no dash' => ['xfoo'];
    }

    public function testExtensionsIsOnlyReservedAtTheTopLevel(): void
    {
        self::assertSame(
            ['unknown key extra.lockrot.ignore[0].extensions ignored'],
            UnknownKeys::warnings(['ignore' => [['package' => 'a/b', 'reason' => 'r', 'extensions' => []]]])
        );
    }

    /** `expire` for `expires` is the typo that turns a temporary ignore into a permanent one. */
    public function testAnUnknownKeyInAnIgnoreEntryIsNamedWithItsIndex(): void
    {
        $extra = ['ignore' => [
            ['package' => 'a/b', 'reason' => 'r', 'expires' => '2027-01-01'],
            ['package' => 'c/d', 'reason' => 'r', 'expire' => '2027-01-01'],
        ]];

        self::assertSame(
            ['unknown key extra.lockrot.ignore[1].expire ignored (did you mean expires?)'],
            UnknownKeys::warnings($extra)
        );
    }

    public function testEveryUnknownKeyOfEveryIgnoreEntryIsNamed(): void
    {
        $extra = ['ignore' => [
            ['package' => 'a/b', 'reason' => 'r', 'expire' => '2027-01-01', 'note' => 'n'],
            ['package' => 'c/d', 'reason' => 'r', 'pinned' => true],
        ]];

        self::assertSame(
            [
                'unknown key extra.lockrot.ignore[0].expire ignored (did you mean expires?)',
                'unknown key extra.lockrot.ignore[0].note ignored',
                'unknown key extra.lockrot.ignore[1].pinned ignored',
            ],
            UnknownKeys::warnings($extra)
        );
    }

    /** An ignore entry is only compared with the keys an ignore entry has, not with the top level's. */
    public function testAnIgnoreEntryKeyIsComparedWithTheIgnoreEntryKeys(): void
    {
        self::assertSame(
            ['unknown key extra.lockrot.ignore[0].format ignored'],
            UnknownKeys::warnings(['ignore' => [['package' => 'a/b', 'reason' => 'r', 'format' => 'json']]])
        );
    }

    /** A top-level key is only compared with the top level's keys, not with an ignore entry's. */
    public function testATopLevelKeyIsComparedWithTheTopLevelKeys(): void
    {
        self::assertSame(['unknown key extra.lockrot.reason ignored'], UnknownKeys::warnings(['reason' => 'r']));
    }

    public function testXPrefixedKeysInAnIgnoreEntryAreReserved(): void
    {
        self::assertSame([], UnknownKeys::warnings(['ignore' => [['package' => 'a/b', 'reason' => 'r', 'x-ticket' => 'ACME-1']]]));
    }

    /** JSON object keys that look like integers arrive in PHP as integer keys. */
    public function testANumericKeyInAnIgnoreEntryIsNamedToo(): void
    {
        self::assertSame(
            ['unknown key extra.lockrot.ignore[0].5 ignored'],
            UnknownKeys::warnings(['ignore' => [['package' => 'a/b', 'reason' => 'r', 5 => 'five']]])
        );
    }

    /** The warnings follow the document: top-level keys in order, an ignore entry's where `ignore` is. */
    public function testEachUnknownKeyGetsOneLineInDocumentOrder(): void
    {
        $extra = [
            'zzz' => 1,
            'fail-on' => 'none',
            'ignore' => [['package' => 'a/b', 'reason' => 'r', 'mmm' => 1]],
            'aaa' => 2,
        ];

        self::assertSame(
            [
                'unknown key extra.lockrot.zzz ignored',
                'unknown key extra.lockrot.ignore[0].mmm ignored',
                'unknown key extra.lockrot.aaa ignored',
            ],
            UnknownKeys::warnings($extra)
        );
    }

    public function testAControlCharacterInAKeyStaysOnOneLine(): void
    {
        self::assertSame(['unknown key extra.lockrot.bad\nkey\x1B[31m ignored'], UnknownKeys::warnings(["bad\nkey\033[31m" => 1]));
    }

    /**
     * A config the schema refused is still read for unknown keys, and there `ignore` can be an
     * object: its keys are the project's text too, and stay on one line.
     */
    public function testAnIgnoreObjectKeyIsEscapedLikeAnyOtherKey(): void
    {
        self::assertSame(
            ['unknown key extra.lockrot.ignore[bad\nkey\x1B[31m].pinned ignored'],
            UnknownKeys::warnings(['ignore' => ["bad\nkey\033[31m" => ['package' => 'a/b', 'reason' => 'r', 'pinned' => 1]]])
        );
    }

    /**
     * A key is the project's text: shown as written, console tags and all, in both places a key can
     * be. How it reaches the terminal without Symfony's formatter is the printers' business.
     *
     * @dataProvider keysShownAsWritten
     */
    #[DataProvider('keysShownAsWritten')]
    public function testAKeyIsShownAsWritten(string $key, string $shown): void
    {
        self::assertSame(
            ['unknown key extra.lockrot.'.$shown.' ignored', 'unknown key extra.lockrot.ignore[0].'.$shown.' ignored'],
            UnknownKeys::warnings([$key => 1, 'ignore' => [['package' => 'a/b', 'reason' => 'r', $key => 1]]])
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function keysShownAsWritten(): iterable
    {
        yield 'a style tag' => ['<<fg=red>>', '<<fg=red>>'];
        yield 'a link tag' => ['<<href=x>>', '<<href=x>>'];
        yield 'an escaped tag, its backslash escaped in turn' => ['a\\<b', 'a\\\\<b'];
    }

    /**
     * Every escape starts with a backslash, and a backslash in the key is escaped too, so no two keys
     * print alike.
     *
     * @dataProvider keysThatLookAlike
     */
    #[DataProvider('keysThatLookAlike')]
    public function testDistinctKeysAreShownDistinctly(string $one, string $other): void
    {
        $warnings = UnknownKeys::warnings([$one => 1, $other => 2]);

        self::assertCount(2, $warnings);
        self::assertNotSame($warnings[0], $warnings[1]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function keysThatLookAlike(): iterable
    {
        yield 'a newline and a backslash-n' => ["a\nb", 'a\\nb'];
        yield 'a tag and an escaped tag' => ['<c', '\\<c'];
    }

    /**
     * PHP 7.4's levenshtein() gives up on strings longer than 255 bytes and returns -1, which is not
     * a distance; and a key that long is never a near-miss. It is shown cut at 255 bytes.
     */
    public function testAVeryLongKeyIsCutAndGetsNoSuggestion(): void
    {
        self::assertSame(
            ['unknown key extra.lockrot.'.str_repeat('f', 255).'… ignored'],
            UnknownKeys::warnings([str_repeat('f', 300) => 1])
        );
    }

    public function testAKeyOfTheLimitIsShownWhole(): void
    {
        $key = str_repeat('f', 255);

        self::assertSame(['unknown key extra.lockrot.'.$key.' ignored'], UnknownKeys::warnings([$key => 1]));
    }

    /** Cut to the same 255 bytes, two long keys would print the same line twice: it is printed once. */
    public function testTwoLongKeysThatLookTheSameOnceCutAreOneLine(): void
    {
        $prefix = str_repeat('f', 300);

        self::assertSame(
            ['unknown key extra.lockrot.'.str_repeat('f', 255).'… ignored', 'unknown key extra.lockrot.short ignored'],
            UnknownKeys::warnings([$prefix.'a' => 1, $prefix.'b' => 2, 'short' => 3])
        );
    }

    /** PHP turns a JSON key like "5" into the integer 5; at the top level too, it is named, never a TypeError. */
    public function testANumericTopLevelKeyIsNamed(): void
    {
        self::assertSame(['unknown key extra.lockrot.5 ignored'], UnknownKeys::warnings([5 => 'five']));
    }

    /**
     * Compared in ASCII lower case whatever the locale: PHP 7.4's strtolower() follows LC_CTYPE, and
     * under a Turkish locale it lowers `I` to a byte that is not `i`.
     */
    public function testUpperCaseIsFoldedWithoutTheLocale(): void
    {
        $previous = setlocale(\LC_CTYPE, '0');
        setlocale(\LC_CTYPE, 'tr_TR.ISO8859-9', 'tr_TR.ISO-8859-9', 'tr_TR', 'tr_TR.UTF-8');
        try {
            $warnings = UnknownKeys::warnings(['INSTALL' => 'off']);
        } finally {
            setlocale(\LC_CTYPE, (string) $previous);
        }

        self::assertSame(['unknown key extra.lockrot.INSTALL ignored (did you mean install-time?)'], $warnings);
    }

    /**
     * The schema rejects both before this is ever asked; asked anyway, there is nothing to walk.
     *
     * @param mixed $ignore
     *
     * @dataProvider ignoresTheSchemaRejects
     */
    #[DataProvider('ignoresTheSchemaRejects')]
    public function testAnIgnoreThatIsNotAListOfObjectsIsLeftToTheSchema($ignore): void
    {
        self::assertSame([], UnknownKeys::warnings(['ignore' => $ignore]));
    }

    /** @return iterable<string, array{mixed}> */
    public static function ignoresTheSchemaRejects(): iterable
    {
        yield 'a string' => ['acme/legacy'];
        yield 'a list of strings' => [['acme/legacy']];
    }
}
