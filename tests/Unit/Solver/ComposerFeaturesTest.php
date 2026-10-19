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
}
