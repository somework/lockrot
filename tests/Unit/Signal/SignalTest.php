<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal;

use Lockrot\Json\JsonWriter;
use Lockrot\Signal\Data\ArchivedData;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\SignalDataStub;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** `findings[].signals[]`: the id, the level, the summary and the data that the rule built. */
final class SignalTest extends TestCase
{
    public function testItWritesItsKeysInOrderWithTheData(): void
    {
        $data = new ArchivedData('lox/xhprof', 'github.com', 'github');
        $signal = new Signal(Signal::LEVEL_HIGH, 'repository archived on GitHub', $data);

        self::assertSame('{"id":"S3","level":"high","summary":"repository archived on GitHub","data":{"repo":"lox/xhprof","host":"github.com","forge":"github"}}', JsonWriter::encode($signal, \JSON_UNESCAPED_SLASHES));
        self::assertSame($data, $signal->data());
    }

    /**
     * Each id has one data class, so a signal cannot write one id with the keys of another.
     *
     * @dataProvider ids
     */
    #[DataProvider('ids')]
    public function testItTakesItsIdFromTheClassOfItsData(string $id): void
    {
        self::assertSame($id, (new Signal(Signal::LEVEL_INFO, 'x', SignalDataStub::of($id, [])))->id());
    }

    /** @return iterable<string, array{string}> */
    public static function ids(): iterable
    {
        foreach (Signal::IDS as $id) {
            yield $id => [$id];
        }
    }

    /** The constructor takes no id to pair with the data, and no signal is without data. */
    public function testItTakesNoIdAndRequiresItsData(): void
    {
        $parameters = (new \ReflectionMethod(Signal::class, '__construct'))->getParameters();

        self::assertSame(['level', 'summary', 'data'], array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $parameters));
        self::assertFalse($parameters[2]->allowsNull());
        self::assertFalse($parameters[2]->isOptional());
    }
}
