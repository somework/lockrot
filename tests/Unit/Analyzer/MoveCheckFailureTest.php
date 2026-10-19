<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Analyzer;

use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\TestCase;

final class MoveCheckFailureTest extends TestCase
{
    /** Case `move-check‡` with a lock entry whose name reads as a command option. */
    public function testALockEntryWhoseNameIsNoPackageNameGetsNoFindingAndOneNote(): void
    {
        $case = CaseHydrator::case('move-check‡');
        $inputs = JsonPath::arrayAt($case, ['inputs']);
        $lock = JsonPath::arrayAt($inputs, ['lock']);
        $lock[] = ['name' => '--with-all-dependencies', 'version' => '1.0.0', 'dev' => false, 'origin' => 'packagist'];
        $inputs['lock'] = $lock;
        $case['inputs'] = $inputs;

        $document = JsonPath::decoded(CaseHydrator::report($case)->toArray());
        $notes = array_values(array_filter(JsonPath::arrayAt($document, ['note_details']), static fn ($note): bool => \is_array($note) && $note['code'] === 'package_name_invalid'));

        self::assertNotContains('--with-all-dependencies', array_column(JsonPath::arrayAt($document, ['findings']), 'package'));
        self::assertCount(1, $notes);
        self::assertSame(['packages' => ['--with-all-dependencies']], JsonPath::arrayAt($notes[0], ['data']));
        self::assertSame('lock: lockrot skips 1 entry whose name is no Composer package name', JsonPath::stringAt($notes[0], ['text']));
    }

    /** Case `move-check‡`, where Composer's solver failed on the one request of the move. */
    public function testARunWhoseSolverFailedWritesOneNoteWithTheExceptionAndTheMoveIsNotChecked(): void
    {
        $case = CaseHydrator::case('move-check‡');
        $inputs = JsonPath::arrayAt($case, ['inputs']);
        $inputs['checks'] = ['*' => ['result' => 'not_checked', 'form' => null, 'reason' => 'solver_error', 'holds' => []]];
        $inputs['move_check'] = JsonPath::arrayAt($inputs, ['move_check']) + ['error' => ['class' => 'Seld\JsonLint\ParsingException', 'message' => 'does not contain valid JSON']];
        $case['inputs'] = $inputs;

        $document = JsonPath::decoded(CaseHydrator::report($case)->toArray());
        $notes = array_values(array_filter(JsonPath::arrayAt($document, ['note_details']), static fn ($note): bool => \is_array($note) && $note['code'] === 'move_check_failed'));

        self::assertCount(1, $notes);
        self::assertSame(['class' => 'Seld\JsonLint\ParsingException', 'message' => 'does not contain valid JSON'], JsonPath::arrayAt($notes[0], ['data']));
        self::assertSame("moves: Composer's solver failed on a request (Seld\\JsonLint\\ParsingException: does not contain valid JSON), so lockrot did not check that move", JsonPath::stringAt($notes[0], ['text']));
        self::assertSame('solver_error', JsonPath::stringAt($document, ['findings', 0, 'next_step', 'check', 'reason']));
    }

    /** Case `move-check‡`, where Composer's solver refuses the lock itself, so it refuses every move. */
    public function testALockThatComposerRefusesWritesOneNoteAndTheRunNamesTheRefusal(): void
    {
        $case = CaseHydrator::case('move-check‡');
        $inputs = JsonPath::arrayAt($case, ['inputs']);
        $inputs['checks'] = [
            'baseline' => ['result' => 'refused', 'form' => null, 'reason' => null, 'holds' => []],
            '*' => ['result' => 'not_checked', 'form' => null, 'reason' => 'baseline_refused', 'holds' => []],
        ];
        $case['inputs'] = $inputs;

        $document = JsonPath::decoded(CaseHydrator::report($case)->toArray());
        $notes = self::notes($document, 'moves_not_checked');

        self::assertCount(1, $notes);
        self::assertSame('baseline_refused', JsonPath::stringAt($notes[0], ['data', 'reason']));
        self::assertSame('refused', JsonPath::stringAt($document, ['run', 'move_check', 'baseline']));
    }

    public function testALockThatComposerResolvesWritesNoMovesNotCheckedNote(): void
    {
        $document = JsonPath::decoded(CaseHydrator::report(CaseHydrator::case('move-check‡'))->toArray());

        self::assertSame([], self::notes($document, 'moves_not_checked'));
        self::assertSame('resolves', JsonPath::stringAt($document, ['run', 'move_check', 'baseline']));
    }

    /** A transport message can name a repository URL with credentials: the note keeps its first line, without them. */
    public function testTheNoteOfASolverFailureNamesNoCredential(): void
    {
        $case = CaseHydrator::case('move-check‡');
        $inputs = JsonPath::arrayAt($case, ['inputs']);
        $inputs['checks'] = ['*' => ['result' => 'not_checked', 'form' => null, 'reason' => 'solver_error', 'holds' => []]];
        $inputs['move_check'] = JsonPath::arrayAt($inputs, ['move_check']) + ['error' => ['class' => 'Composer\Downloader\TransportException', 'message' => "The \"https://ci-user:s3cr3t@repo.example.org/p2/acme/lib.json\" file could not be downloaded\nsecond line"]];
        $case['inputs'] = $inputs;

        $document = (string) json_encode(JsonPath::decoded(CaseHydrator::report($case)->toArray()));

        self::assertStringNotContainsString('s3cr3t', $document);
        self::assertStringNotContainsString('second line', $document);
        self::assertStringContainsString('repo.example.org', $document);
    }

    /**
     * @param array<mixed, mixed> $document
     *
     * @return list<array<mixed, mixed>>
     */
    private static function notes(array $document, string $code): array
    {
        return array_values(array_filter(JsonPath::arrayAt($document, ['note_details']), static fn ($note): bool => \is_array($note) && $note['code'] === $code));
    }
}
