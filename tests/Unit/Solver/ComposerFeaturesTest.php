<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Solver;

use Composer\Composer;
use Lockrot\Solver\ComposerFeatures;
use PHPUnit\Framework\TestCase;

final class ComposerFeaturesTest extends TestCase
{
    public function testComposer24AndLaterTakeTemporaryConstraints(): void
    {
        self::assertSame(version_compare(Composer::getVersion(), '2.4.0', '>='), ComposerFeatures::hasTemporaryConstraints(), Composer::getVersion());
    }

    /**
     * BaseCommand of each Composer tag: 2.9.2 maps COMPOSER_NO_SECURITY_BLOCKING to
     * `--no-security-blocking`, and 2.10.0 adds COMPOSER_NO_BLOCKING.
     *
     * @dataProvider blockingVariablesOfEachVersion
     *
     * @param list<string> $variables
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('blockingVariablesOfEachVersion')]
    public function testEachComposerReadsItsOwnVariablesThatTurnTheBlockingOff(string $version, array $variables): void
    {
        self::assertSame($variables, ComposerFeatures::blockingVariables($version));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function blockingVariablesOfEachVersion(): iterable
    {
        yield 'Composer 2.8 blocks nothing' => ['2.8.12', []];
        yield 'Composer 2.9.0' => ['2.9.0', []];
        yield 'Composer 2.9.1' => ['2.9.1', []];
        yield 'Composer 2.9.2' => ['2.9.2', ['COMPOSER_NO_SECURITY_BLOCKING']];
        yield 'Composer 2.9.5' => ['2.9.5', ['COMPOSER_NO_SECURITY_BLOCKING']];
        yield 'a 2.10 release candidate' => ['2.10.0-RC1', ['COMPOSER_NO_BLOCKING', 'COMPOSER_NO_SECURITY_BLOCKING']];
        yield 'Composer 2.10.3' => ['2.10.3', ['COMPOSER_NO_BLOCKING', 'COMPOSER_NO_SECURITY_BLOCKING']];
    }
}
