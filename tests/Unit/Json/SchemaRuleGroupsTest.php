<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Json;

use Lockrot\Baseline\BaselineSchema;
use Lockrot\Config\ConfigSchema;
use Lockrot\Exception\ConfigException;
use PHPUnit\Framework\TestCase;

/**
 * A refusal names the rule that a document breaks. The message of a broken group is its
 * `description` ({@see \Lockrot\Json\SchemaErrors}). So each group in config-2 and baseline-2 is
 * an `allOf` member that holds only the rule and the group (`rule_group()` in
 * tools/schema/build_other_schemas.py). An editor description stays on the node around it.
 */
final class SchemaRuleGroupsTest extends TestCase
{
    private const SCHEMAS = ['lockrot-config-2.schema.json', 'lockrot-baseline-2.schema.json'];

    public function testEachDescribedGroupIsARuleGroupMember(): void
    {
        $other = [];
        $groups = 0;
        foreach (self::SCHEMAS as $file) {
            $schema = json_decode((string) file_get_contents(__DIR__.'/../../../resources/'.$file), true);
            self::assertIsArray($schema);
            self::walk($schema, $file, false, $other, $groups);
        }

        self::assertSame([], $other, 'a described oneOf, anyOf or not outside a rule group');
        self::assertGreaterThan(0, $groups);
    }

    /**
     * @param array<mixed> $node
     * @param list<string> $other
     */
    private static function walk(array $node, string $path, bool $inAllOf, array &$other, int &$groups): void
    {
        $keywords = array_values(array_intersect(['oneOf', 'anyOf', 'not'], array_keys($node)));
        if ($keywords !== [] && isset($node['description'])) {
            if ($inAllOf && \count($keywords) === 1 && array_keys($node) === ['description', $keywords[0]]) {
                ++$groups;
            } else {
                $other[] = $path;
            }
        }
        foreach ($node as $key => $child) {
            if (\is_array($child)) {
                self::walk($child, $path.'/'.$key, \is_int($key) && substr($path, -6) === '/allOf', $other, $groups);
            }
        }
    }

    public function testABaselineEntryWithoutFactsNamesTheEntryRule(): void
    {
        $message = self::refusal(static function (): void {
            BaselineSchema::validate([
                '$schema' => 'https://lockrot.dev/schema/baseline-2.json',
                'lockrot' => ['version' => '0.14.0', 'schema' => 2],
                'generated_at' => '2026-09-14T00:00:00+00:00',
                'target_php' => '8.4',
                'target_php_source' => 'option',
                'score_model_id' => 1,
                'fix_model' => 1,
                'findings' => ['mrclay/minify' => ['version' => '1.0.0', 'first_seen' => '2026-01-15']],
            ]);
        });

        self::assertSame("baseline file is invalid:\n  - findings.mrclay/minify: An entry needs `flags`, `advisories` or both.", $message);
    }

    public function testAnAcceptedCheckWithTwoScopesNamesTheScopeRule(): void
    {
        $message = self::refusal(static function (): void {
            ConfigSchema::validate(['accept-unchecked' => [['check' => 'release_metadata', 'reason' => 'mirror', 'host' => 'example.org', 'package' => 'acme/*']]]);
        });

        self::assertStringContainsString('  - accept-unchecked[0]: An entry needs exactly one of `host`, `repository` and `package`.', $message);
    }

    public function testAFailOnOfAnotherTypeNamesTheAcceptedForms(): void
    {
        $message = self::refusal(static function (): void {
            ConfigSchema::validate(['fail-on' => 5]);
        });

        self::assertStringContainsString('  - fail-on: `fail-on` is one value, a comma-separated list of values, or a non-empty array of distinct values.', $message);
    }

    public function testAFailOnItemThatIsNoValueNamesTheValueRule(): void
    {
        $message = self::refusal(static function (): void {
            ConfigSchema::validate(['fail-on' => ['high', 'dead']]);
        });

        self::assertStringContainsString('  - fail-on[1]: Each item is one fail-on value.', $message);
    }

    private static function refusal(callable $validate): string
    {
        try {
            $validate();
        } catch (ConfigException $e) {
            return $e->getMessage();
        }
        self::fail('the document is valid');
    }
}
