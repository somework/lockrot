<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Json\Schemas;
use Lockrot\Tests\Support\ValidatesJsonSchemas;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The model's coverage documents with a failed advisory feed validate against report-2: no case of
 * `cases.json` has a failed feed. The strict twin is left out: one of them carries an
 * `advisories_not_checked` note with the reason `lookup_failed`, which lockrot does not write.
 */
final class CoverageVariantsConformanceTest extends TestCase
{
    use ValidatesJsonSchemas;

    /** @return iterable<string, array{string}> */
    public static function variants(): iterable
    {
        $files = (array) glob(__DIR__.'/../fixtures/flags/coverage/*.json');
        sort($files);
        foreach ($files as $file) {
            yield basename((string) $file, '.json') => [(string) $file];
        }
    }

    /** @dataProvider variants */
    #[DataProvider('variants')]
    public function testTheVariantValidatesAgainstReport2(string $file): void
    {
        $this->assertValid(Schemas::REPORT, (string) file_get_contents($file), basename($file));
    }

    public function testTheFourFailedFeedShapesAreThere(): void
    {
        self::assertSame(['clear-partial-lookup', 'feed-invalid-response', 'lookup-not-run-lookup-failed', 'vulnerable-partial-lookup'], array_keys(iterator_to_array(self::variants())));
    }
}
