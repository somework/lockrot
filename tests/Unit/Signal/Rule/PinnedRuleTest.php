<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal\Rule;

use Composer\Package\Loader\ArrayLoader;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Json\Schemas;
use Lockrot\Signal\Rule\PinnedRule;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use PHPUnit\Framework\TestCase;

final class PinnedRuleTest extends TestCase
{
    public function testBranchSnapshot(): void
    {
        $signal = (new PinnedRule())->evaluate(F::facts(F::package(['version' => 'dev-master'])));
        self::assertNotNull($signal);
        self::assertSame(Signal::S6, $signal->id());
        self::assertSame(Signal::LEVEL_WARN, $signal->level());
        self::assertSame('pinned to branch snapshot dev-master', $signal->summary());
        // No repository metadata, and no lock time either: nothing is known about releases, and
        // null says so where false would claim the package never released.
        self::assertSame([
            'version' => 'dev-master',
            'reason' => PinnedRule::REASON_BRANCH_SNAPSHOT,
            'has_stable_release' => null,
            'last_stable_release' => null,
            'last_stable_version' => null,
            'last_stable_dated_by' => null,
            'snapshot_time' => null,
        ], $signal->data());
    }

    public function testNoTaggedRelease(): void
    {
        $signal = (new PinnedRule())->evaluate(F::facts(F::package(['version' => '1.0.0']), F::metadata([['dev-master', '2015-01-01']])));
        self::assertNotNull($signal);
        self::assertSame('no tagged release in its repository', $signal->summary());
        self::assertSame([
            'version' => '1.0.0',
            'reason' => PinnedRule::REASON_NO_STABLE_RELEASE,
            'has_stable_release' => false,
            'last_stable_release' => null,
            'last_stable_version' => null,
            'last_stable_dated_by' => null,
            'snapshot_time' => null,
        ], $signal->data());
    }

    /**
     * `reason` is an open set, so neither the published schema nor its strict twin rejects a value
     * the code emits and the schema never learned. What does is this: every `REASON_*` constant, in
     * declaration order, is what the report schema lists under `x-known-values` — a third reason
     * added to the rule without the schema, or the other way round, fails here.
     */
    public function testTheReasonsAreTheValuesTheReportSchemaKnows(): void
    {
        $reasons = [];
        foreach ((new \ReflectionClass(PinnedRule::class))->getReflectionConstants() as $constant) {
            if (strncmp($constant->getName(), 'REASON_', 7) === 0) {
                $reasons[] = $constant->getValue();
            }
        }

        self::assertSame(['branch_snapshot', 'no_stable_release'], $reasons);
        self::assertSame(
            $reasons,
            JsonPath::arrayAt(JsonPath::decodeFile(Schemas::path(Schemas::REPORT)), ['definitions', 's6', 'properties', 'reason', 'x-known-values'])
        );
    }

    /** wallabag/rulerz on dev-master: the repository lists branches and no tag at all. */
    public function testBranchSnapshotOfAPackageThatNeverReleasedSaysSo(): void
    {
        $signal = (new PinnedRule())->evaluate(F::facts(
            F::package(['version' => 'dev-master', 'time' => '2023-12-24T00:53:44+00:00']),
            F::metadata([['dev-master', '2023-12-24T00:53:44+00:00'], ['dev-support-symfony-7', '2023-12-24T00:53:44+00:00']])
        ));
        self::assertNotNull($signal);
        self::assertSame('pinned to branch snapshot dev-master', $signal->summary(), 'the evidence line does not change');
        self::assertSame([
            'version' => 'dev-master',
            'reason' => PinnedRule::REASON_BRANCH_SNAPSHOT,
            'has_stable_release' => false,
            'last_stable_release' => null,
            'last_stable_version' => null,
            'last_stable_dated_by' => null,
            'snapshot_time' => '2023-12-24T00:53:44+00:00',
        ], $signal->data());
    }

    /** friendsofsymfony/oauth-server-bundle on dev-master: tags exist, the newest dated is 1.6.2. */
    public function testBranchSnapshotOfATaggedPackageCarriesItsNewestDatedRelease(): void
    {
        $signal = (new PinnedRule())->evaluate(F::facts(
            F::package(['version' => 'dev-master', 'time' => '2022-03-24T10:22:23+00:00']),
            F::metadata([['dev-master', '2022-03-24T10:22:23+00:00'], ['1.6.2', '2019-01-23T15:23:04+00:00'], ['1.6.1', '2018-04-18T13:46:16+00:00']])
        ));
        self::assertNotNull($signal);
        self::assertSame([
            'version' => 'dev-master',
            'reason' => PinnedRule::REASON_BRANCH_SNAPSHOT,
            'has_stable_release' => true,
            'last_stable_release' => '2019-01-23T15:23:04+00:00',
            'last_stable_version' => '1.6.2',
            'last_stable_dated_by' => null,
            'snapshot_time' => '2022-03-24T10:22:23+00:00',
        ], $signal->data());
    }

    /** A vcs or path entry, or metadata that did not load: has_stable_release is null, never false. */
    public function testBranchSnapshotWithoutMetadataDoesNotClaimItNeverReleased(): void
    {
        $signal = (new PinnedRule())->evaluate(F::facts(F::package(['version' => 'dev-main', 'time' => '2026-01-02T03:04:05+00:00', 'fromComposerRepository' => false])));
        self::assertNotNull($signal);
        $data = $signal->data();
        self::assertArrayHasKey('has_stable_release', $data);
        self::assertNull($data['has_stable_release']);
        self::assertSame('2026-01-02T03:04:05+00:00', $data['snapshot_time'], 'the lock still dates the commit');
    }

    /** Tags exist, but none carries a date lockrot trusts: true, with the last release left null. */
    public function testBranchSnapshotWhoseHighestTagIsUndated(): void
    {
        $signal = (new PinnedRule())->evaluate(F::facts(F::package(['version' => '2.x-dev', 'time' => '2024-05-06T07:08:09+00:00']), F::metadata([['2.0.0', null]])));
        self::assertNotNull($signal);
        self::assertSame([
            'version' => '2.x-dev',
            'reason' => PinnedRule::REASON_BRANCH_SNAPSHOT,
            'has_stable_release' => true,
            'last_stable_release' => null,
            'last_stable_version' => null,
            'last_stable_dated_by' => null,
            'snapshot_time' => '2024-05-06T07:08:09+00:00',
        ], $signal->data());
    }

    /**
     * A subtree split, as the repository really lists one: its tags are cut on a commit they share,
     * so the date they carry is the commit's and no release date is trusted. It has released, and
     * when is left unsaid.
     */
    public function testBranchSnapshotOfASplitWhoseTagsShareACommit(): void
    {
        $loader = new ArrayLoader();
        $on = static fn (string $version, string $time): array => ['name' => 'vendor/pkg', 'version' => $version, 'time' => $time, 'source' => ['type' => 'git', 'url' => 'https://github.com/vendor/pkg.git', 'reference' => 'split']];
        $metadata = PackageMetadata::fromPackages('vendor/pkg', [
            $loader->load($on('10.49.0', '2023-06-05T12:46:42+00:00')),
            $loader->load($on('10.20.0', '2023-06-05T12:46:42+00:00')),
            $loader->load($on('10.13.1', '2023-06-05T12:46:42+00:00')),
        ], new \DateTimeImmutable(F::NOW));
        $signal = (new PinnedRule())->evaluate(F::facts(F::package(['version' => '10.x-dev', 'time' => '2024-01-01T00:00:00+00:00']), $metadata));
        self::assertNotNull($signal);
        self::assertSame([
            'version' => '10.x-dev',
            'reason' => PinnedRule::REASON_BRANCH_SNAPSHOT,
            'has_stable_release' => true,
            'last_stable_release' => null,
            'last_stable_version' => null,
            'last_stable_dated_by' => null,
            'snapshot_time' => '2024-01-01T00:00:00+00:00',
        ], $signal->data());
    }

    /** A split package's snapshot, its newest release dated by the monorepo it was split from. */
    public function testBranchSnapshotOfASplitNamesWhatDatedItsLastRelease(): void
    {
        $metadata = new PackageMetadata(
            'symfony/polyfill-ctype',
            false,
            null,
            true,
            new \DateTimeImmutable('2024-09-09T11:45:10+00:00'),
            'v1.31.0',
            12,
            'https://github.com/symfony/polyfill-ctype.git',
            'library',
            new \DateTimeImmutable(F::NOW),
            [],
            [],
            'symfony/polyfill'
        );
        $signal = (new PinnedRule())->evaluate(F::facts(F::package(['name' => 'symfony/polyfill-ctype', 'version' => '1.x-dev']), $metadata));
        self::assertNotNull($signal);
        self::assertSame([
            'version' => '1.x-dev',
            'reason' => PinnedRule::REASON_BRANCH_SNAPSHOT,
            'has_stable_release' => true,
            'last_stable_release' => '2024-09-09T11:45:10+00:00',
            'last_stable_version' => 'v1.31.0',
            'last_stable_dated_by' => 'symfony/polyfill',
            'snapshot_time' => null,
        ], $signal->data());
    }

    /** The lock's time is a snapshot's commit date; for a tag it is not a snapshot's, and S6 leaves it out. */
    public function testNoTaggedReleaseLeavesSnapshotTimeNull(): void
    {
        $signal = (new PinnedRule())->evaluate(F::facts(F::package(['version' => '1.0.0', 'time' => '2020-01-01T00:00:00+00:00']), F::metadata([['dev-main', '2020-01-01T00:00:00+00:00']])));
        self::assertNotNull($signal);
        self::assertSame(PinnedRule::REASON_NO_STABLE_RELEASE, $signal->data()['reason']);
        self::assertFalse($signal->data()['has_stable_release']);
        self::assertNull($signal->data()['snapshot_time']);
    }

    public function testTaggedPackageIsNull(): void
    {
        self::assertNull((new PinnedRule())->evaluate(F::facts(F::package(), F::metadata([['1.0.0', '2020-01-01']]))));
        self::assertNull((new PinnedRule())->evaluate(F::facts(F::package())));
    }
}
