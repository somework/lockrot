<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Config;

use Lockrot\Baseline\Baseline;
use Lockrot\Exception\ConfigException;
use Lockrot\Lock\ProjectConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a config or baseline error says first. lockrot's own checks speak before the schema
 * (SPEC-0.14.md §6), and a broken rule group of the schema is named by its path and its
 * description, with the branch errors as details that only `-v` prints.
 */
final class ConfigErrorOrderTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>, string}> the `extra.lockrot`, and the message that names its error */
    public static function ownChecks(): iterable
    {
        yield 'vulnerable-low' => [['fail-on' => 'vulnerable-low'], 'use "vulnerable": every counted advisory is at least low'];
        yield 'an unknown value' => [['fail-on' => 'high,dead'], 'unknown value "dead"'];
        yield 'ignore[].flags naming vulnerable' => [['ignore' => [['package' => 'a/b', 'reason' => 'r', 'flags' => ['vulnerable']]]], "accept advisories in Composer's audit policy"];
    }

    /**
     * @param array<string, mixed> $lockrot
     *
     * @dataProvider ownChecks
     */
    #[DataProvider('ownChecks')]
    public function testLockrotsOwnMessageWinsOverTheSchemas(array $lockrot, string $message): void
    {
        $error = self::configError($lockrot);

        self::assertStringContainsString($message, $error->getMessage());
        self::assertStringNotContainsString('extra.lockrot is invalid', $error->getMessage(), 'the schema did not speak first');
    }

    public function testABrokenConfigGroupIsNamedByItsPathAndDescription(): void
    {
        $error = self::configError(['accept-unchecked' => [['check' => 'advisories', 'package' => 'a/*', 'repository' => 'https://mirror.example.org', 'reason' => 'two scopes']]]);

        self::assertSame("extra.lockrot is invalid:\n  - accept-unchecked[0]: One accepted check.", $error->getMessage());
        self::assertNotSame([], $error->details(), 'the branch errors are details');
        self::assertStringContainsString('accept-unchecked[0]', implode("\n", $error->details()));
    }

    public function testABrokenBaselineGroupIsNamedByItsPathAndDescription(): void
    {
        $error = null;
        try {
            Baseline::fromArray([
                '$schema' => 'https://lockrot.dev/schema/baseline-2.json',
                'lockrot' => ['version' => '0.14.0', 'schema' => 2],
                'generated_at' => '2026-09-14T00:00:00+00:00',
                'target_php' => '8.4',
                'target_php_source' => 'option',
                'score_model_id' => 1,
                'fix_model' => 1,
                'findings' => ['acme/empty' => ['version' => '1.0.0', 'first_seen' => '2026-01-15']],
            ]);
        } catch (ConfigException $e) {
            $error = $e;
        }

        self::assertNotNull($error);
        self::assertStringStartsWith("baseline file is invalid:\n  - findings.acme/empty: What the baseline knows about one package", $error->getMessage());
        self::assertStringNotContainsString('required', $error->getMessage(), 'the branch errors wait for -v');
        self::assertStringContainsString('flags', implode("\n", $error->details()));
    }

    public function testAnErrorBesideAGroupStaysInTheMessage(): void
    {
        $error = self::configError(['accept-unchecked' => [['check' => 'advisories', 'package' => 'a/*', 'reason' => 7]]]);

        self::assertStringContainsString('accept-unchecked[0].reason', $error->getMessage());
        self::assertSame([], $error->details());
    }

    /** @param array<string, mixed> $lockrot */
    private static function configError(array $lockrot): ConfigException
    {
        try {
            ProjectConfig::fromArray(['extra' => ['lockrot' => $lockrot]]);
        } catch (ConfigException $e) {
            return $e;
        }
        self::fail('the config was accepted');
    }
}
