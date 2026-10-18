<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Analyzer;

use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\FindingRelations;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\TestCase;

/**
 * Case `move-check‡` with its move resolved inline, so typo3/html-sanitizer is the one candidate
 * of `update now`, and the combined update answered as each test records it.
 */
final class UpdateNowTest extends TestCase
{
    private const PACKAGE = 'typo3/html-sanitizer';

    public function testACombinedUpdateThatResolvesNamesItsMembersInTheCommand(): void
    {
        $document = self::document(null);

        self::assertSame([self::PACKAGE], JsonPath::arrayAt($document, ['security', 'update_now']));
        self::assertSame(['composer', 'update', self::PACKAGE.':^2.3.2'], JsonPath::arrayAt($document, ['security', 'update_now_command']));
        self::assertSame([], FindingRelations::violations($document), 'the root recount agrees');
    }

    public function testACombinedUpdateThatIsNotCheckedHasNoMembersAndANoteSaysWhy(): void
    {
        $document = self::document(['result' => 'not_checked', 'form' => null, 'reason' => 'install_time_budget', 'holds' => []]);

        self::assertSame([[], null], [JsonPath::arrayAt($document, ['security', 'update_now']), JsonPath::arrayAt($document, ['security'])['update_now_command']]);
        self::assertSame(['reason' => 'install_time_budget', 'packages' => [self::PACKAGE]], JsonPath::arrayAt(self::note($document, 'update_now_not_checked'), ['data']));
        self::assertSame([], FindingRelations::violations($document), 'the root recount agrees');
    }

    public function testACombinedUpdateThatComposerRefusesHasNoMembers(): void
    {
        $document = self::document(['result' => 'refused', 'form' => null, 'reason' => null, 'holds' => [['source' => 'root', 'package' => null, 'version' => null, 'link' => 'conflict', 'target' => self::PACKAGE, 'constraint' => '>=2.3']]]);

        self::assertSame([[], null], [JsonPath::arrayAt($document, ['security', 'update_now']), JsonPath::arrayAt($document, ['security'])['update_now_command']]);
        self::assertArrayHasKey('problem', JsonPath::arrayAt(self::note($document, 'update_now_refused'), ['data']));
        self::assertSame([], FindingRelations::violations($document), 'the root recount agrees');
    }

    /**
     * @param array<string, mixed>|null $combined
     *
     * @return array<mixed, mixed>
     */
    private static function document(?array $combined): array
    {
        $case = CaseHydrator::case('move-check‡');
        $inputs = JsonPath::arrayAt($case, ['inputs']);
        $inputs['checks'] = ['*' => ['result' => 'resolves', 'form' => 'inline', 'reason' => null, 'holds' => []]] + ($combined === null ? [] : ['combined' => $combined]);
        $case['inputs'] = $inputs;

        return JsonPath::decoded(CaseHydrator::report($case)->toArray());
    }

    /**
     * @param array<mixed, mixed> $document
     *
     * @return array<mixed, mixed>
     */
    private static function note(array $document, string $code): array
    {
        foreach (JsonPath::arrayAt($document, ['note_details']) as $note) {
            if (\is_array($note) && $note['code'] === $code) {
                return $note;
            }
        }
        self::fail('the report has no note '.$code);
    }
}
