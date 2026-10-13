<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Exception\ConfigException;
use Lockrot\Json\Schemas;
use Lockrot\Tests\Support\ValidatesJsonSchemas;
use Lockrot\Verdict\FailOn;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * config-2's `fail-on` accepts every string and array that `FailOn::parse` accepts, so an editor
 * that validates against the published schema never marks a value that lockrot reads.
 *
 * @coversNothing
 *
 * @group covers-nothing
 */
#[CoversNothing]
#[Group('covers-nothing')]
final class FailOnSchemaAgreementTest extends TestCase
{
    use ValidatesJsonSchemas;

    private const BUILDER = __DIR__.'/../../tools/schema/build_other_schemas.py';

    public function testTheBuilderListsTheValuesThatFailOnAllows(): void
    {
        self::assertSame(1, preg_match("/^FAIL_ON_VALUES = (\\[[^\\]]*\\])/m", (string) file_get_contents(self::BUILDER), $match));
        $listed = json_decode(str_replace("'", '"', preg_replace('/\s+/', ' ', $match[1] ?? '') ?? ''), true);

        self::assertSame(FailOn::allowed(), $listed);
    }

    public function testEveryValueThatFailOnAcceptsIsValidConfig2(): void
    {
        $accepted = 0;
        $refused = [];
        foreach (self::inputs() as $input) {
            try {
                FailOn::parse($input);
            } catch (ConfigException $e) {
                continue;
            }
            ++$accepted;
            $json = (string) json_encode(['fail-on' => $input]);
            if ($this->errors(Schemas::CONFIG, $json, true, 2) !== []) {
                $refused[] = $json;
            }
        }

        self::assertGreaterThan(1000, $accepted, 'the generated inputs reach the parser');
        self::assertSame([], \array_slice($refused, 0, 20), \count($refused).' accepted values that the strict twin refuses');
    }

    /**
     * Every allowed value in three cases with whitespace around it, every pair of values in both
     * orders with three comma placements, and each pair as an array.
     *
     * @return iterable<string|list<string>>
     */
    private static function inputs(): iterable
    {
        $spellings = static function (string $value): array {
            $out = [];
            foreach ([$value, strtoupper($value), ucfirst($value)] as $cased) {
                foreach (['', ' ', "\t"] as $pad) {
                    $out[] = $pad.$cased.$pad;
                }
            }

            return $out;
        };
        foreach (FailOn::allowed() as $value) {
            yield from $spellings($value);
        }
        foreach (FailOn::allowed() as $first) {
            foreach (FailOn::allowed() as $second) {
                foreach ([',', ', ', ' , '] as $comma) {
                    yield $first.$comma.$second;
                    yield strtoupper($first).$comma.' '.$second.' ';
                }
                yield [$first, $second];
                yield [' '.strtoupper($first), $second.' '];
            }
        }
    }
}
