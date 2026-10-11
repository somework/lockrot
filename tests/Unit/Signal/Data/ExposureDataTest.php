<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal\Data;

use Lockrot\Json\JsonWriter;
use Lockrot\Signal\Data\ExposedPackage;
use Lockrot\Signal\Data\ExposureData;
use PHPUnit\Framework\TestCase;

/** The S7 data of ssddanbrown/asserthtml in the BookStack fixture app, with `--dev`. */
final class ExposureDataTest extends TestCase
{
    public function testItWritesTheCountAndEachPackageInOrder(): void
    {
        $package = new ExposedPackage('phar-io/version', ['ssddanbrown/asserthtml', 'phpunit/phpunit', 'phar-io/version'], 'low', 'stale', ['stale']);
        $data = new ExposureData([$package]);

        self::assertSame('{"flagged":1,"packages":[{"package":"phar-io/version","chain":["ssddanbrown/asserthtml","phpunit/phpunit","phar-io/version"],"verdict":"low","lead":"stale","flag_ids":["stale"]}]}', JsonWriter::encode($data, \JSON_UNESCAPED_SLASHES));
        self::assertSame([$package], $data->packages());
        self::assertSame('phar-io/version', $package->package());
        self::assertSame(['ssddanbrown/asserthtml', 'phpunit/phpunit', 'phar-io/version'], $package->chain());
        self::assertSame('stale', $package->lead());
    }

    public function testAPackageThatOnlyAnAdvisoryFlagsHasNoLead(): void
    {
        self::assertSame('{"package":"acme/lib","chain":["acme/app","acme/lib"],"verdict":"high","lead":null,"flag_ids":["vulnerable"]}', JsonWriter::encode(new ExposedPackage('acme/lib', ['acme/app', 'acme/lib'], 'high', null, ['vulnerable']), \JSON_UNESCAPED_SLASHES));
    }
}
