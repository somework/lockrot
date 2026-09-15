<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal;

use Composer\Semver\VersionParser;
use Lockrot\Signal\ConstraintOpenness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConstraintOpennessTest extends TestCase
{
    public function testConstructorUsesProvidedParser(): void
    {
        $parser = new class () extends VersionParser {
            public int $calls = 0;

            /** @return mixed */
            public function parseConstraints($constraints)
            {
                ++$this->calls;

                return parent::parseConstraints($constraints);
            }
        };
        $openness = new ConstraintOpenness($parser);
        self::assertTrue($openness->isOpenEndedFor('>=5.3.0', '8.4'));
        self::assertGreaterThan(0, $parser->calls);
    }

    /** @dataProvider constraints */
    #[DataProvider('constraints')]
    public function testOpenEndedFor84(string $constraint, bool $open): void
    {
        self::assertSame($open, (new ConstraintOpenness())->isOpenEndedFor($constraint, '8.4'), $constraint);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function constraints(): iterable
    {
        // open-ended promises written before PHP 8
        yield '>=5.3.0' => ['>=5.3.0', true];
        yield '>=5.0' => ['>=5.0', true];
        yield '>= 7' => ['>= 7', true];
        yield '>=5.5.9' => ['>=5.5.9', true];
        yield '*' => ['*', true];
        yield '>=7.1' => ['>=7.1', true];
        // explicit major mentions or upper bounds are deliberate
        yield '~7.0 || ~8.0' => ['~7.0 || ~8.0', false];
        yield '^7.2 || ^8.0' => ['^7.2 || ^8.0', false];
        yield '^7 || ^8' => ['^7 || ^8', false];
        yield '>=7.1 <8.0' => ['>=7.1 <8.0', false];
        yield '^5.6 || ^7.0' => ['^5.6 || ^7.0', false];
        yield '7.*' => ['7.*', false];
        yield '>=8.0' => ['>=8.0', false];
        yield '>=8.1' => ['>=8.1', false];
        yield 'invalid' => ['not a constraint !!', false];
    }

    public function testTargetWithoutMinorAccepted(): void
    {
        self::assertTrue((new ConstraintOpenness())->isOpenEndedFor('>=5.3', '8'));
        self::assertTrue((new ConstraintOpenness())->matches('>=5.3', '8.4.0'));
        self::assertFalse((new ConstraintOpenness())->matches('^7.0', '8.4'));
    }
}
