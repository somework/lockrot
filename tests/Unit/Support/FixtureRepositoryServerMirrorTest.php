<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Support;

use Lockrot\Clock;
use Lockrot\Data\Http\HttpResult;
use Lockrot\Data\Http\RecordedHttpClient;
use Lockrot\Tests\Support\FixtureRepositoryServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Which locked names a mirrored `composer` repository serves, as Composer reads its name lists. */
final class FixtureRepositoryServerMirrorTest extends TestCase
{
    private const ORIGIN = 'https://repo.example.com';
    private const NAMES = ['acme/one', 'other/two'];

    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            array_map('unlink', glob($dir.'/*') ?: []);
            rmdir($dir);
        }
    }

    /**
     * @param array<string, mixed> $lists
     * @param list<string>         $served
     *
     * @dataProvider lists
     */
    #[DataProvider('lists')]
    public function testAMirrorServesTheNamesThatComposerReadsFromTheLists(array $lists, array $served): void
    {
        $dir = sys_get_temp_dir().'/lockrot-mirror-'.bin2hex(random_bytes(6));
        mkdir($dir);
        $this->dirs[] = $dir;
        $this->record($dir, self::ORIGIN.'/packages.json', (string) json_encode(['metadata-url' => '/p2/%package%.json'] + $lists));
        foreach (self::NAMES as $name) {
            $this->record($dir, self::ORIGIN.'/p2/'.$name.'.json', '{"packages":{}}');
        }
        $server = FixtureRepositoryServer::fromLockFiles([], __DIR__, self::NAMES);
        try {
            $mirror = $server->withMirror(self::ORIGIN, $dir);
            $server->start();
            $got = static fn (string $name): bool => @file_get_contents($mirror.'/p2/'.$name.'.json') !== false;

            self::assertSame($served, array_values(array_filter(self::NAMES, $got)));
        } finally {
            $server->stop();
        }
    }

    /** @return iterable<string, array{array<string, mixed>, list<string>}> */
    public static function lists(): iterable
    {
        yield 'no list' => [[], ['acme/one', 'other/two']];
        yield 'empty lists are no list' => [['available-packages' => [], 'available-package-patterns' => []], ['acme/one', 'other/two']];
        yield 'an exact name without regard to case' => [['available-packages' => ['Acme/One']], ['acme/one']];
        yield 'a pattern' => [['available-package-patterns' => ['other/*']], ['other/two']];
    }

    private function record(string $dir, string $url, string $body): void
    {
        file_put_contents(RecordedHttpClient::pathFor($dir, $url), (string) json_encode((new HttpResult($url, 200, $body, Clock::fixed('2026-01-01')->now()))->toEnvelope()));
    }
}
