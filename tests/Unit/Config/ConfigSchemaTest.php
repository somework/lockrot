<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Config;

use Lockrot\Config\ConfigSchema;
use Lockrot\Exception\ConfigException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigSchemaTest extends TestCase
{
    public function testEmptyArrayIsValid(): void
    {
        ConfigSchema::validate([]);
        $this->addToAssertionCount(1);
    }

    public function testFullValidConfigIsValid(): void
    {
        ConfigSchema::validate([
            'fail-on' => 'silent',
            'target-php' => '8.4',
            'format' => 'json',
            'include-dev' => true,
            'cache-ttl' => 3600,
            'release-warn-years' => 2,
            'release-high-years' => 4,
            'push-warn-years' => 2,
            'push-high-years' => 4,
            'ignore' => [
                ['package' => 'acme/legacy', 'reason' => 'internal fork', 'version' => '^1.0', 'expires' => '2027-01-31'],
            ],
        ]);
        $this->addToAssertionCount(1);
    }

    public function testUnknownKeysAreAllowed(): void
    {
        ConfigSchema::validate(['totally-unknown-key' => 'value']);
        $this->addToAssertionCount(1);
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @dataProvider invalidConfigs
     */
    #[DataProvider('invalidConfigs')]
    public function testInvalidPropertyIsReportedWithItsPath(array $extra, string $expectedPropertyPath): void
    {
        try {
            ConfigSchema::validate($extra);
            self::fail('Expected a ConfigException naming '.$expectedPropertyPath);
        } catch (ConfigException $e) {
            self::assertStringStartsWith('extra.lockrot is invalid:', $e->getMessage());
            self::assertStringContainsString('  - '.$expectedPropertyPath.':', $e->getMessage());
        }
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidConfigs(): iterable
    {
        yield 'fail-on not in enum' => [['fail-on' => 'dead'], 'fail-on'];
        yield 'target-php bad pattern' => [['target-php' => 'v8.4'], 'target-php'];
        yield 'format not in enum' => [['format' => 'xml'], 'format'];
        yield 'include-dev wrong type' => [['include-dev' => 'yes'], 'include-dev'];
        yield 'cache-ttl negative' => [['cache-ttl' => -1], 'cache-ttl'];
        yield 'cache-ttl digit string' => [['cache-ttl' => '3600'], 'cache-ttl'];
        yield 'release-warn-years digit string' => [['release-warn-years' => '4'], 'release-warn-years'];
        yield 'release-warn-years below minimum' => [['release-warn-years' => 0], 'release-warn-years'];
        yield 'release-high-years wrong type' => [['release-high-years' => 'many'], 'release-high-years'];
        yield 'push-warn-years below minimum' => [['push-warn-years' => 0], 'push-warn-years'];
        yield 'push-high-years wrong type' => [['push-high-years' => 'many'], 'push-high-years'];
        yield 'ignore not an array' => [['ignore' => 'acme/legacy'], 'ignore'];
        yield 'ignore entry not an object' => [['ignore' => ['acme/legacy']], 'ignore[0]'];
        yield 'ignore entry missing reason' => [['ignore' => [['package' => 'acme/legacy']]], 'ignore[0].reason'];
        yield 'ignore entry missing package' => [['ignore' => [['reason' => 'because']]], 'ignore[0].package'];
        yield 'ignore entry empty package' => [['ignore' => [['package' => '', 'reason' => 'because']]], 'ignore[0].package'];
        yield 'ignore entry empty reason' => [['ignore' => [['package' => 'a/b', 'reason' => '']]], 'ignore[0].reason'];
        yield 'ignore entry bad expires pattern' => [['ignore' => [['package' => 'a/b', 'reason' => 'x', 'expires' => 'soon']]], 'ignore[0].expires'];
    }
}
