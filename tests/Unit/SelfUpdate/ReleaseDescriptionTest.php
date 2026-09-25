<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\SelfUpdate;

use Lockrot\Exception\ConfigException;
use Lockrot\SelfUpdate\ReleaseDescription;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReleaseDescriptionTest extends TestCase
{
    private const URL = 'https://github.com/somework/lockrot/releases/download/v0.13.0/lockrot.phar.meta.json';
    private const KEY = 'sha256:ec3ca71b1a3ced86f871b89cff7973b58454e5694136683680b72b18070a8f87';

    public function testReadsTheFloorAndTheKey(): void
    {
        $description = ReleaseDescription::fromJson('{"php": "8.1.0", "selfupdate-key": "'.self::KEY.'"}', self::URL);

        self::assertSame('8.1.0', $description->phpFloor());
        self::assertSame(self::KEY, $description->signingKey());
    }

    /** A later release may say more; what it says beyond these two is not this build's business. */
    public function testFieldsItDoesNotKnowAreIgnored(): void
    {
        $description = ReleaseDescription::fromJson('{"php": "7.4.0", "selfupdate-key": "'.self::KEY.'", "extensions": ["json"]}', self::URL);

        self::assertSame('7.4.0', $description->phpFloor());
    }

    public function testUndescribedIsTheSevenFourFloorWithoutAKey(): void
    {
        $description = ReleaseDescription::undescribed();

        self::assertSame('7.4.0', $description->phpFloor());
        self::assertSame(ReleaseDescription::UNDESCRIBED_PHP_FLOOR, $description->phpFloor());
        self::assertNull($description->signingKey());
    }

    /** @return iterable<string, array{0: string}> */
    public static function notADescription(): iterable
    {
        $key = '"selfupdate-key": "'.self::KEY.'"';

        yield 'not json' => ['<html>404</html>'];
        yield 'json but not an object' => ['"7.4.0"'];
        yield 'no floor' => ['{'.$key.'}'];
        yield 'a floor that is not a string' => ['{"php": 7.4, '.$key.'}'];
        yield 'a floor that is not a version' => ['{"php": "soon", '.$key.'}'];
        yield 'no key' => ['{"php": "7.4.0"}'];
        yield 'a key that is not a string' => ['{"php": "7.4.0", "selfupdate-key": 42}'];
        yield 'a key without its algorithm' => ['{"php": "7.4.0", "selfupdate-key": "'.substr(self::KEY, 7).'"}'];
        yield 'a key under another algorithm' => ['{"php": "7.4.0", "selfupdate-key": "md5:'.str_repeat('a', 32).'"}'];
        yield 'a key one digit short' => ['{"php": "7.4.0", "selfupdate-key": "'.substr(self::KEY, 0, -1).'"}'];
        yield 'a key one digit long' => ['{"php": "7.4.0", "selfupdate-key": "'.self::KEY.'0"}'];
        yield 'a key in capitals' => ['{"php": "7.4.0", "selfupdate-key": "'.strtoupper(self::KEY).'"}'];
        yield 'a key with something in front' => ['{"php": "7.4.0", "selfupdate-key": "x'.self::KEY.'"}'];
        yield 'a key followed by a newline' => ['{"php": "7.4.0", "selfupdate-key": "'.self::KEY.'\n"}'];
    }

    /** @dataProvider notADescription */
    #[DataProvider('notADescription')]
    public function testAnythingElseIsAnErrorNamingTheUrl(string $body): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage(
            self::URL.' is not a lockrot release description ({"php": "<version>", "selfupdate-key": "sha256:<hex>"} expected)'
        );
        ReleaseDescription::fromJson($body, self::URL);
    }
}
