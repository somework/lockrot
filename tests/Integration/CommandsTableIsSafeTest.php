<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Tests\Support\Contract\ContractBundle;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Verdict\CommandArgument;
use PHPUnit\Framework\TestCase;

/**
 * The safety claim of SPEC-0.14.md §5.6 over every row of the recorded commands table: a row with an
 * argument outside the set is refused on every shell. Else the double-quoted cmd.exe arguments hold
 * no `"`, `%` or `!`, and PowerShell writes `composer --%`, then text with no `%` and no `|` outside double quotes.
 */
final class CommandsTableIsSafeTest extends TestCase
{
    public function testEveryRowIsRefusedOnEveryShellOrSafeOnEach(): void
    {
        $rows = JsonPath::arrayAt(JsonPath::decodeFile(ContractBundle::DIRECTORY.'/en/commands.json'), ['rows']);
        self::assertNotSame([], $rows);
        $seen = ['refused' => 0, 'passed' => 0];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            $argv = array_values(array_filter(JsonPath::arrayAt($row, ['argv']), 'is_string'));
            $label = json_encode($argv, \JSON_THROW_ON_ERROR);
            if (!CommandArgument::allPass($argv)) {
                self::assertSame([null, null, null], [$row['posix'], $row['cmd'], $row['powershell']], $label);
                ++$seen['refused'];
                continue;
            }
            $cmd = JsonPath::stringAt($row, ['cmd']);
            $powershell = JsonPath::stringAt($row, ['powershell']);
            self::assertIsString($row['posix'], $label);
            self::assertSame(0, preg_match('~[%!]~', $cmd), $label);
            self::assertSame(0, substr_count($cmd, '"') % 2, $label.': a double-quoted cmd.exe argument holds a quote');
            self::assertStringStartsWith('composer --% ', $powershell, $label);
            $verbatim = substr($powershell, \strlen('composer --% '));
            self::assertSame(0, self::pipesOutsideQuotes($verbatim), $label);
            self::assertStringNotContainsString('%', $verbatim, $label);
            ++$seen['passed'];
        }
        self::assertGreaterThan(0, $seen['refused']);
        self::assertGreaterThan(0, $seen['passed']);
    }

    private static function pipesOutsideQuotes(string $text): int
    {
        return substr_count((string) preg_replace('~"[^"]*"~', '', $text), '|');
    }
}
