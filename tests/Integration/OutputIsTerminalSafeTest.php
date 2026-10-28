<?php

declare(strict_types=1);

namespace Lockrot\Tests\Integration;

use Lockrot\Analyzer\Report;
use Lockrot\Config\LockrotConfig;
use Lockrot\Explain\Explanation;
use Lockrot\Output\ConsoleMarkup;
use Lockrot\Output\ExplainFormatter;
use Lockrot\Output\FormatContext;
use Lockrot\Output\Formatters;
use Lockrot\Output\InstallSummaryFormatter;
use Lockrot\Output\TerminalText;
use Lockrot\Signal\Signal;
use Lockrot\Signal\Thresholds;
use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\FindingBuilder;
use Lockrot\Tests\Support\Notes;
use Lockrot\Tests\Support\SignalDataStub;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use Lockrot\Verdict\Finding;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\Move;
use Lockrot\Verdict\Moves;
use Lockrot\Verdict\MoveSubject;
use Lockrot\Verdict\ShellCommand;
use PHPUnit\Framework\TestCase;

/**
 * Every text that lockrot writes to stdout or stderr is safe for a terminal, and every command
 * that a text format prints is whole in that format's command form (R6-10, R6-11, R6-13).
 */
final class OutputIsTerminalSafeTest extends TestCase
{
    /** An escape sequence, a C1 CSI, bidi controls, zero-width characters and a line separator. */
    private const UNSAFE = "\x1B[31m\u{9B}31m\u{202E}\u{2066}\u{200B}\u{FEFF}\u{2028}";

    private const MOVED = 'acme/c0ffee12';
    private const COMMAND = ['composer', 'update', self::MOVED.':^20240101.1', '--with-dependencies'];

    public function testEveryOutputLineIsAFixedPointOfTheNeutraliser(): void
    {
        foreach (self::outputs() as $name => $lines) {
            self::assertNotSame([], $lines, $name);
            foreach ($lines as $index => $line) {
                self::assertSame(TerminalText::neutralise($line), $line, $name.' line '.$index);
            }
        }
    }

    public function testEveryCommandOfATextFormatIsWholeInItsCommandForm(): void
    {
        $outputs = self::outputs();
        $command = (string) ShellCommand::posix(self::COMMAND);

        self::assertStringContainsString('`'.$command.'`', implode("\n", $outputs['markdown']));
        self::assertContains($command, array_map(static fn (string $line): string => (string) preg_replace('/^\s*(do: )?/', '', $line), $outputs['table']), 'a table line of its own');
        self::assertStringContainsString($command, implode("\n", $outputs['install-time']));
        foreach (['markdown', 'table', 'install-time'] as $name) {
            $text = implode("\n", $outputs[$name]);
            preg_match_all('/'.preg_quote($command, '/').'(.|$)/su', $text, $after);
            foreach ($after[1] as $next) {
                self::assertContains($next, ['', ' ', "\n", '`'], $name.': the command is followed by "'.$next.'"');
            }
        }
    }

    /** @return array<string, list<string>> each output by its name, as lines */
    private static function outputs(): array
    {
        $outputs = [];
        foreach ([CaseHydrator::composite(), self::hostile()] as $index => $report) {
            foreach (LockrotConfig::FORMATS as $format) {
                $text = Formatters::for($format, FormatContext::create(null, 'none'))->format($report, true);
                if (Formatters::carriesConsoleMarkup($format)) {
                    $text = ConsoleMarkup::render($text, false);
                }
                // The vendored renderer indents its script with tabs, which no terminal acts on.
                $outputs[$format] = array_merge($outputs[$format] ?? [], explode("\n", $format === 'html' ? str_replace("\t", ' ', $text) : $text));
            }
            foreach ((new InstallSummaryFormatter())->format($report) as $line) {
                $outputs['install-time'][] = ConsoleMarkup::render($line, false);
            }
            foreach ($report->findings() as $finding) {
                $explanation = new Explanation($finding, F::facts(F::package(['name' => $finding->package(), 'version' => $finding->version()])), new Thresholds(), '8.4', $report);
                $outputs['explain text'] = array_merge($outputs['explain text'] ?? [], explode("\n", ConsoleMarkup::render((new ExplainFormatter())->text($explanation), false)));
                $outputs['explain json'] = array_merge($outputs['explain json'] ?? [], explode("\n", (new ExplainFormatter())->json($explanation)));
            }
            self::assertNotSame([], $report->findings(), 'report '.$index);
        }

        return $outputs;
    }

    /**
     * An abandoned finding whose update move runs $command after the platform step of $override.
     *
     * @param list<Signal> $signals
     * @param list<string> $command
     */
    private static function moved(string $package, string $override, array $signals, array $command): Finding
    {
        $subject = new MoveSubject($package, '1.0.0', true, [], '8.4', null, null, $override);
        $move = new Move(Move::UPDATE, FlagSet::VULNERABLE, [], null, null, null, null, '1.x', '1.0.3', '1.0.3', null, null, null, false, [], [], [], [], [], null, [], [$command], $subject);

        return (new FindingBuilder())->withPackage($package)->withSignals($signals)->withFlags(FlagSet::fromSignals($signals, null, []))->build()->withMoves(new Moves($move, null, null, null, []));
    }

    /** Graded findings whose text from the lock, a repository and an advisory holds {@see UNSAFE}. */
    private static function hostile(): Report
    {
        $abandoned = [new Signal(Signal::LEVEL_HIGH, 'marked'.self::UNSAFE, SignalDataStub::of(Signal::S1, ['marked_by' => 'repository', 'replacement' => 'evil/next'.self::UNSAFE]))];
        $vulnerable = [new Signal(Signal::LEVEL_HIGH, 'advisory'.self::UNSAFE, SignalDataStub::of(Signal::S9, ['advisories' => [['id' => 'PKSA-1', 'title' => 'title'.self::UNSAFE, 'severity' => 'high', 'affected_versions' => '<2.0']]]))];
        return new Report([
            self::moved(self::MOVED, '7.4', $abandoned, self::COMMAND),
            self::moved('evil/platform', '7.4'.self::UNSAFE, $abandoned, ['composer', 'update', 'evil/platform']),
            (new FindingBuilder())->withPackage('evil/name'.self::UNSAFE)->withVersion('1.0'.self::UNSAFE)->withSignals($abandoned)->withFlags(FlagSet::fromSignals($abandoned, null, []))->withAllowlistReason('reason'.self::UNSAFE)->build(),
            (new FindingBuilder())->withPackage('evil/advised')->withSignals($vulnerable)->withFlags(FlagSet::fromSignals($vulnerable, null, []))->build(),
        ], Notes::texts(['note'.self::UNSAFE]), new \DateTimeImmutable(F::NOW), 3, 0);
    }
}
