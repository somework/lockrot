<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Analyzer;

use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\TestCase;

final class MoveCheckFailureTest extends TestCase
{
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
}
