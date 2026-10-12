<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Output;

use Lockrot\Json\Schemas;
use Lockrot\Output\CauseWords;
use Lockrot\Tests\Support\JsonPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CauseVocabularyTest extends TestCase
{
    /** The reason sets of report-2, each as its path in the schema. */
    private const SETS = [
        'installedUnmeasured' => ['definitions', 'installedUnmeasured'],
        'releaseUnmeasured' => ['definitions', 'releaseUnmeasured'],
        'branchReleaseUnmeasured' => ['definitions', 'branchReleaseUnmeasured'],
        'pushUnmeasured' => ['definitions', 'pushUnmeasured'],
        'libyearsUnmeasured' => ['definitions', 'libyearsUnmeasured'],
        'depthUnmeasured' => ['definitions', 'depthUnmeasured'],
        'checkSkipped.reason' => ['definitions', 'checkSkipped', 'properties', 'reason'],
        'checkMissing.reason' => ['definitions', 'checkMissing', 'properties', 'reason'],
        'unchecked_reason' => ['definitions', 'securityUnchecked', 'properties', 'unchecked_reason', 'oneOf', 0],
        'advisoriesNotCheckedReason' => ['definitions', 'advisoriesNotCheckedReason'],
        'activityReason' => ['definitions', 'activityReason'],
        'metadataFailureReason' => ['definitions', 'metadataFailureReason'],
        'throughEntry.newest_requires_unmeasured' => ['definitions', 'throughEntry', 'properties', 'newest_requires_unmeasured', 'oneOf', 0],
    ];

    /** @return iterable<string, array{string}> */
    public static function sets(): iterable
    {
        foreach (array_keys(self::SETS) as $set) {
            yield $set => [$set];
        }
    }

    /**
     * @dataProvider sets
     */
    #[DataProvider('sets')]
    public function testEveryIdOfTheSetHasWords(string $set): void
    {
        $known = JsonPath::arrayAt(JsonPath::decodeFile(Schemas::path(Schemas::REPORT, 2)), array_merge(self::SETS[$set], ['x-known-values']));

        self::assertNotSame([], $known, $set.' lists its ids');
        self::assertSame([], array_values(array_diff($known, array_keys(CauseWords::WORDS))), $set.': ids without words');
    }

    public function testNoTwoIdsShareTheirWords(): void
    {
        self::assertSame(array_values(CauseWords::WORDS), array_values(array_unique(CauseWords::WORDS)));
    }

    public function testTheTableWordsNoIdThatNoSetLists(): void
    {
        $schema = JsonPath::decodeFile(Schemas::path(Schemas::REPORT, 2));
        $listed = [];
        foreach (self::SETS as $path) {
            $listed = array_merge($listed, JsonPath::arrayAt($schema, array_merge($path, ['x-known-values'])));
        }

        self::assertSame([], array_values(array_diff(array_keys(CauseWords::WORDS), $listed)));
    }

    public function testAnIdTheTableDoesNotKnowReadsWithSpaces(): void
    {
        self::assertSame('no stable release', CauseWords::of('no_stable_release'));
        self::assertSame('some new cause', CauseWords::of('some_new_cause'));
    }

    /**
     * The model's fixture findings name one cause by one id: an undated installed end has the id of
     * `age.installed_unmeasured`, and `no_stable_release` goes with the release end.
     */
    public function testTheFixtureFindingsNameOneCauseByOneId(): void
    {
        $cases = JsonPath::arrayAt(JsonPath::decodeFile(__DIR__.'/../../fixtures/flags/cases.json'), ['cases']);
        $checked = 0;
        foreach ($cases as $case) {
            $finding = JsonPath::arrayAt($case, ['finding']);
            if (!JsonPath::has($finding, ['age'])) {
                continue;
            }
            $libyears = $finding['libyears_unmeasured'] ?? null;
            $age = JsonPath::arrayAt($finding, ['age']);
            if (\in_array($libyears, ['shared_commit', 'undated'], true)) {
                self::assertSame($libyears, $age['installed_unmeasured'], JsonPath::stringAt($case, ['id']));
                ++$checked;
            }
            if ($libyears === 'no_stable_release') {
                self::assertSame('no_stable_release', $age['release_unmeasured'], JsonPath::stringAt($case, ['id']));
                ++$checked;
            }
        }

        self::assertGreaterThan(0, $checked, 'some fixture finding has an undated end');
    }
}
