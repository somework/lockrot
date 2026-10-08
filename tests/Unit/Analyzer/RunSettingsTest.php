<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Analyzer;

use Lockrot\Analyzer\RunSettings;
use Lockrot\Config\LockrotConfig;
use Lockrot\Signal\Thresholds;
use Lockrot\Verdict\FailOn;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** report-2's run keys say where each setting came from: the first source that sets it. */
final class RunSettingsTest extends TestCase
{
    /**
     * @param array<string, mixed>  $extra
     * @param array<string, string> $env
     * @param array<string, string> $cli
     *
     * @dataProvider targetSources
     */
    #[DataProvider('targetSources')]
    public function testTheTargetPhpNamesTheSourceThatSetIt(array $extra, array $env, array $cli, ?string $platform, string $target, string $source): void
    {
        $config = LockrotConfig::fromSources($extra, $env, $cli, '8.3.12', $platform);

        self::assertSame([$target, $source], [$config->targetPhp(), $config->targetPhpSource()]);
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, string>, array<string, string>, ?string, string, string}> */
    public static function targetSources(): iterable
    {
        yield 'the option' => [['target-php' => '8.1'], ['LOCKROT_TARGET_PHP' => '8.2'], ['target-php' => '8.4.7'], '7.4', '8.4', RunSettings::SOURCE_OPTION];
        yield 'the environment' => [['target-php' => '8.1'], ['LOCKROT_TARGET_PHP' => '8.2'], [], '7.4', '8.2', RunSettings::SOURCE_ENV];
        yield 'extra.lockrot' => [['target-php' => '8.1'], [], [], '7.4', '8.1', RunSettings::SOURCE_CONFIG];
        yield 'config.platform.php' => [[], [], [], '7.4.33', '7.4', RunSettings::SOURCE_PLATFORM];
        yield 'the running PHP' => [[], [], [], null, '8.3', RunSettings::SOURCE_RUNTIME];
    }

    /**
     * @param array<string, mixed>  $extra
     * @param array<string, string> $env
     * @param array<string, string> $cli
     *
     * @dataProvider failOnSources
     */
    #[DataProvider('failOnSources')]
    public function testFailOnNamesTheSourceThatSetIt(array $extra, array $env, array $cli, string $failOn, string $source): void
    {
        $config = LockrotConfig::fromSources($extra, $env, $cli, '8.4.0', null);

        self::assertSame([$failOn, $source], [$config->failOn(), $config->failOnSource()]);
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, string>, array<string, string>, string, string}> */
    public static function failOnSources(): iterable
    {
        yield 'the option' => [['fail-on' => 'low'], ['LOCKROT_FAIL_ON' => 'medium'], ['fail-on' => 'high'], 'high', RunSettings::SOURCE_OPTION];
        yield 'the environment' => [['fail-on' => 'low'], ['LOCKROT_FAIL_ON' => 'medium'], [], 'medium', RunSettings::SOURCE_ENV];
        yield 'extra.lockrot' => [['fail-on' => 'low'], [], [], 'low', RunSettings::SOURCE_CONFIG];
        yield 'the default' => [[], [], [], 'none', RunSettings::SOURCE_DEFAULT];
    }

    /** @dataProvider gates */
    #[DataProvider('gates')]
    public function testOneGateEntryStandsForTheFailOnValue(string $failOn, string $kinds): void
    {
        $run = new RunSettings(null, null, '8.4', null, FailOn::fromString($failOn), new Thresholds(), null, false, 'check', RunSettings::SOURCE_OPTION, RunSettings::SOURCE_OPTION);

        self::assertSame($kinds, json_encode($run->toArray()['gates']));
    }

    /** @return iterable<string, array{string, string}> */
    public static function gates(): iterable
    {
        yield 'a grade word' => ['high', '[{"value":"high","kind":"grade","threshold":"high"}]'];
        yield 'a flag word' => ['left-behind', '[{"value":"left-behind","kind":"flag","threshold":"left-behind"}]'];
        yield 'unchecked' => ['unchecked', '[{"value":"unchecked","kind":"unchecked","threshold":null}]'];
        yield 'none' => ['none', '[]'];
    }

    public function testTheProjectsLowestPhpIsTheFloorsStablePoint(): void
    {
        $run = new RunSettings(null, null, '8.4', null, null, null, '^7.2 || ^8.0.0');
        $none = new RunSettings(null, null, '8.4', null, null, null, null);

        self::assertSame('7.2.0', $run->toArray()['project_php_lowest']);
        self::assertNull($none->toArray()['project_php_lowest']);
    }

    public function testTheRunKeysAreReport2sInOrder(): void
    {
        $keys = array_keys((new RunSettings(null, null, '8.4', null, null, null))->toArray(['counted' => 0]));

        self::assertSame(['project', 'root_package', 'target_php', 'target_php_source', 'project_php', 'project_php_lowest', 'lock_file', 'fail_on', 'fail_on_source', 'gates',
            'strict_network', 'mode', 'include_dev', 'thresholds', 'flag_ids', 'verdicts', 'graded_verdicts', 'signal_ids', 'fix_model', 'text_grammar', 'score_model', 'score_rules_used'], $keys);
    }
}
