<?php

declare(strict_types=1);

namespace Lockrot\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * A report-2 document cut to the findings that differ in shape, for a test that validates it many
 * times. A validation costs time per finding, and the tests that cover a line together must stay
 * below the timeout of a mutation run (infection.json5).
 */
final class ReportSample
{
    /**
     * The findings in their order that bring a shape no earlier kept finding has: the first, the
     * first of each signal id, verdict, gate state, origin kind, reach and dev flag, and the
     * named packages. The root blocks stay as written.
     *
     * @param array<mixed, mixed> $report
     * @param list<string>        $packages
     *
     * @return array<mixed, mixed>
     */
    public static function of(array $report, array $packages = []): array
    {
        $seen = [];
        $kept = [];
        foreach (JsonPath::arrayAt($report, ['findings']) as $at => $finding) {
            Assert::assertIsArray($finding);
            $keys = self::shape($finding);
            if ($at === 0 || \in_array($finding['package'] ?? null, $packages, true) || array_diff($keys, array_keys($seen)) !== []) {
                $kept[] = $finding;
                $seen += array_fill_keys($keys, true);
            }
        }
        $report['findings'] = $kept;

        return $report;
    }

    /**
     * As {@see of()}, on the JSON text: decoded to objects, so an empty object stays one.
     *
     * @param list<string> $packages
     */
    public static function json(string $json, array $packages = []): string
    {
        $document = json_decode($json);
        Assert::assertInstanceOf(\stdClass::class, $document);
        $findings = \is_array($document->findings ?? null) ? $document->findings : [];
        $shapes = json_decode($json, true);
        Assert::assertIsArray($shapes);
        $kept = array_column(JsonPath::arrayAt(self::of($shapes, $packages), ['findings']), 'package');
        $document->findings = array_values(array_filter($findings, static fn ($finding): bool => $finding instanceof \stdClass && \in_array($finding->package ?? null, $kept, true)));

        return (string) json_encode($document);
    }

    /**
     * @param array<mixed, mixed> $finding
     *
     * @return list<string>
     */
    private static function shape(array $finding): array
    {
        $gate = \is_array($finding['gate'] ?? null) ? $finding['gate'] : [];
        $origin = \is_array($finding['origin'] ?? null) ? $finding['origin'] : [];
        $keys = [
            'verdict:'.json_encode($finding['verdict'] ?? null),
            'gate:'.json_encode([$gate['reaches_fail_on'] ?? null, $gate['fails'] ?? null, $gate['exempt_by'] ?? null]),
            'origin:'.json_encode([$origin['kind'] ?? null, $origin['local'] ?? null, $finding['from_composer_repository'] ?? null]),
            'reach:'.json_encode([$finding['reach'] ?? null, $finding['dev'] ?? null]),
        ];
        foreach (\is_array($finding['signals'] ?? null) ? $finding['signals'] : [] as $signal) {
            $keys[] = 'signal:'.json_encode(\is_array($signal) ? ($signal['id'] ?? null) : null);
        }

        return $keys;
    }
}
