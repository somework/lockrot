<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Signal\Rule;

use Lockrot\Data\Abandoned\AbandonedIgnoreMatch;
use Lockrot\Data\Repository\PackageMetadata;
use Lockrot\Lock\LockedPackage;
use Lockrot\Signal\AbandonedIgnored;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Rule\AbandonedRule;
use Lockrot\Signal\Signal;
use Lockrot\Tests\Unit\Signal\FactsBuilder as F;
use PHPUnit\Framework\TestCase;

final class AbandonedRuleTest extends TestCase
{
    public function testFlaggedWithReplacement(): void
    {
        $signal = (new AbandonedRule())->evaluate(F::facts(F::package(), F::metadata([['1.0.0', '2020-01-01']], true, 'other/pkg')));
        self::assertNotNull($signal);
        self::assertSame(Signal::S1, $signal->id());
        self::assertSame(Signal::LEVEL_HIGH, $signal->level());
        self::assertSame('marked abandoned by its repository, replacement: other/pkg', $signal->summary());
        self::assertSame('other/pkg', $signal->data()['replacement']);
    }

    public function testNotFlagged(): void
    {
        self::assertNull((new AbandonedRule())->evaluate(F::facts(F::package(), F::metadata([['1.0.0', '2020-01-01']]))));
    }

    public function testFallsBackToLockWhenNoMetadata(): void
    {
        $signal = (new AbandonedRule())->evaluate(F::facts(F::package(['abandonedInLock' => true])));
        self::assertNotNull($signal);
        self::assertStringContainsString('in composer.lock', $signal->summary());
        self::assertNull((new AbandonedRule())->evaluate(F::facts(F::package())));
    }

    /**
     * The replacement S1 carries was named by whoever S1 read: the repository's metadata when there
     * is some, the lock entry's own repository otherwise.
     */
    public function testTheReplacementIsNamedByTheRegistryS1Read(): void
    {
        $packagist = new \Composer\Package\CompletePackage('vendor/pkg', '1.0.0.0', '1.0.0');
        $packagist->setAbandoned('other/pkg');
        $packagist->setNotificationUrl('https://packagist.org/downloads/');
        $metadata = PackageMetadata::fromPackages('vendor/pkg', [$packagist], new \DateTimeImmutable('2026-09-14T00:00:00+00:00'));

        self::assertSame('packagist.org', AbandonedRule::replacementNamedBy(F::facts(F::package(['fromComposerRepository' => false]), $metadata)), 'the metadata, whatever the lock says');
        self::assertSame('packagist.org', AbandonedRule::replacementNamedBy(F::facts(F::package(['abandonedInLock' => 'other/pkg']))), 'the lock entry came from packagist.org');
        self::assertNull(AbandonedRule::replacementNamedBy(F::facts(F::package(['abandonedInLock' => 'other/pkg', 'fromComposerRepository' => false]))), 'a lock entry from no registry');
        self::assertNull(AbandonedRule::replacementNamedBy(F::facts(F::package(), F::metadata([['1.0.0', '2020-01-01']], true, 'other/pkg'))), 'metadata with no notify URL names no registry');
    }

    public function testFallsBackToLockWithStringReplacement(): void
    {
        $signal = (new AbandonedRule())->evaluate(F::facts(F::package(['abandonedInLock' => 'other/pkg'])));
        self::assertNotNull($signal);
        self::assertSame(Signal::S1, $signal->id());
        self::assertSame('marked abandoned in composer.lock, replacement: other/pkg', $signal->summary());
        self::assertSame(['replacement' => 'other/pkg'], $signal->data());
    }

    private static function listed(LockedPackage $package, ?PackageMetadata $metadata = null): PackageFacts
    {
        return new PackageFacts($package, $metadata, null, [], null, new AbandonedIgnoreMatch(AbandonedIgnoreMatch::BY_POLICY, [['pattern' => 'vendor/*', 'reason' => 'migration planned', 'constraints' => ['^1.0']]]));
    }

    public function testAListedNameRaisesNoS1FromTheMetadataOrTheLock(): void
    {
        $rule = new AbandonedRule();

        self::assertNull($rule->evaluate(self::listed(F::package(), F::metadata([['1.0.0', '2020-01-01']], true, 'other/pkg'))), 'marked by the repository');
        self::assertNull($rule->evaluate(self::listed(F::package(['abandonedInLock' => 'other/pkg']))), 'marked in the lock');
    }

    public function testTheRecordCarriesTheListAndS1sOwnData(): void
    {
        $packagist = new \Composer\Package\CompletePackage('vendor/pkg', '1.0.0.0', '1.0.0');
        $packagist->setAbandoned('other/pkg');
        $packagist->setNotificationUrl('https://packagist.org/downloads/');
        $metadata = PackageMetadata::fromPackages('vendor/pkg', [$packagist], new \DateTimeImmutable('2026-09-14T00:00:00+00:00'));

        $record = AbandonedRule::ignored(self::listed(F::package(), $metadata));

        self::assertNotNull($record);
        self::assertSame(AbandonedIgnoreMatch::BY_POLICY, $record->by());
        self::assertSame([['pattern' => 'vendor/*', 'reason' => 'migration planned', 'constraints' => ['^1.0']]], $record->rules());
        self::assertSame(AbandonedIgnored::MARKED_BY_REPOSITORY, $record->markedBy());
        self::assertSame('other/pkg', $record->replacement());
        self::assertSame('https://packagist.org/packages/other/pkg', $record->replacementUrl());
    }

    public function testAMarkingInTheLockGivesARecordMarkedByTheLock(): void
    {
        $record = AbandonedRule::ignored(self::listed(F::package(['abandonedInLock' => true])));

        self::assertNotNull($record);
        self::assertSame(AbandonedIgnored::MARKED_BY_LOCK, $record->markedBy());
        self::assertNull($record->replacement());
        self::assertNull($record->replacementUrl());

        $freeText = AbandonedRule::ignored(self::listed(F::package(['abandonedInLock' => 'Symfony'])));
        self::assertNotNull($freeText);
        self::assertSame('Symfony', $freeText->replacement());
        self::assertNull($freeText->replacementUrl(), 'free text names no package page');
    }

    public function testAListedNameNobodyMarkedLeavesNoRecord(): void
    {
        self::assertNull(AbandonedRule::ignored(self::listed(F::package(), F::metadata([['1.0.0', '2020-01-01']]))));
        self::assertNull(AbandonedRule::ignored(self::listed(F::package())));
        self::assertNull(AbandonedRule::ignored(F::facts(F::package(['abandonedInLock' => true]))), 'a marking no list covers is S1');
    }
}
