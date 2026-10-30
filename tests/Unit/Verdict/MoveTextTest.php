<?php

declare(strict_types=1);

namespace Lockrot\Tests\Unit\Verdict;

use Lockrot\Data\Repository\StableRelease;
use Lockrot\Lock\ProjectConfig;
use Lockrot\Security\Holder;
use Lockrot\Security\PhpCheck;
use Lockrot\Signal\PhpFloor;
use Lockrot\Solver\MoveCheck;
use Lockrot\Tests\Support\CaseHydrator;
use Lockrot\Tests\Support\JsonPath;
use Lockrot\Verdict\FlagSet;
use Lockrot\Verdict\GradedPackage;
use Lockrot\Verdict\Latest;
use Lockrot\Verdict\Move;
use Lockrot\Verdict\MoveFact;
use Lockrot\Verdict\MoveSubject;
use Lockrot\Verdict\MoveText;
use Lockrot\Verdict\ShellCommand;
use Lockrot\Verdict\ThroughEntry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Each move is built here with only the fields that its sentence reads, for acme/lib 1.2.0 at target php 7.4. */
final class MoveTextTest extends TestCase
{
    public function testAMoveThatComposerDidNotCheckSaysWhyAndWhatItAssumes(): void
    {
        $move = self::move(Move::UPDATE, null, [])->withCheck(MoveCheck::notChecked(MoveCheck::INSTALL_TIME_BUDGET));

        self::assertStringEndsWith("; not checked with Composer's solver (the install-time budget ran out), so it holds only if Composer resolves it", MoveText::render($move));
    }

    public function testACheckedMoveToANewMajorDoesNotSayThatLockrotLeavesItsRequirementsUnsolved(): void
    {
        $move = self::move(Move::REQUIRE, null, [Move::CROSSES_MAJOR]);

        self::assertStringContainsString('a new major brings its own requirements;', MoveText::render($move->withCheck(MoveCheck::resolves(MoveCheck::INLINE))));
        self::assertStringContainsString('a new major brings its own requirements, which lockrot does not solve', MoveText::render($move->withCheck(MoveCheck::notChecked(MoveCheck::OFFLINE))));
    }

    public function testARefusedBlockedMoveWithoutAPhpRequirementSaysThatComposerNamesNoHold(): void
    {
        $move = self::move(Move::BLOCKED, null, [])->withCheck(MoveCheck::refused());

        self::assertStringStartsWith('no move installs 2.x: Composer names no hold', MoveText::render($move));
    }

    /** A blocked move with no php of its own comes only from a refusal with no hold. */
    public function testABlockedMoveWithoutAPhpRequirementOrARefusalIsRefused(): void
    {
        $move = self::move(Move::BLOCKED, null, [])->withCheck(MoveCheck::notChecked(MoveCheck::OFFLINE));

        $this->expectException(\LogicException::class);

        MoveText::render($move);
    }

    /** The php of the release, not Composer's refusal, keeps the move off the target. */
    public function testARefusedBlockedMoveWhosePhpTheTargetDoesNotRunNamesThePhp(): void
    {
        $move = self::move(Move::BLOCKED, PhpCheck::of('>=8.2', new PhpFloor('7.4')), [])->withCheck(MoveCheck::refused());

        self::assertStringStartsWith('no move runs on the target: 2.x needs php >=8.2 and the target php 7.4 does not run it', MoveText::render($move));
    }

    public function testABlockedMoveThatComposerDidNotRefuseNamesThePhp(): void
    {
        $move = self::move(Move::BLOCKED, PhpCheck::of('>=8.2', new PhpFloor('7.4')), [])->withCheck(MoveCheck::notChecked(MoveCheck::OFFLINE));

        self::assertStringStartsWith('no move runs on the target: 2.x needs php >=8.2', MoveText::render($move));
    }

    /** @return iterable<string, array{?string, string, bool}> */
    public static function platforms(): iterable
    {
        yield 'outside the target minor' => ['7.4.29', '8.4', true];
        yield 'a patch of the target minor' => ['8.4.1', '8.4', false];
        yield 'the target minor with no patch' => ['8.4', '8.4', false];
        yield 'a minor that starts like the target' => ['8.40.1', '8.4', true];
        yield 'no override' => [null, '8.4', false];
    }

    /**
     * @dataProvider platforms
     */
    #[DataProvider('platforms')]
    public function testAMoveWithCommandsFirstSetsAPlatformPhpOutsideTheTargetMinor(?string $override, string $target, bool $step): void
    {
        $subject = new MoveSubject('acme/lib', '1.2.0', true, [], $target, null, null, $override);
        $move = new Move(Move::UPDATE, FlagSet::VULNERABLE, [], null, null, null, null, '2.x', '2.0.0', '2.0.0', null, null, null, false, [], [], [], [], [], null, [], [['composer', 'update', 'acme/lib']], $subject);

        $text = MoveText::render($move);

        self::assertSame($step ? 'First set config.platform.php to your PHP '.$target.' release: the lock records '.$override.'. composer update acme/lib (gets 2.0.0)' : 'composer update acme/lib (gets 2.0.0)', $text);
    }

    /**
     * wallabag at 8.4: the platform step alone does not make the lock install while Composer refuses
     * the lock itself. The moves_not_checked note names the cause of Composer's first problem.
     */
    public function testThePlatformStepOfARefusedBaselinePointsAtTheFirstCauseInTheNote(): void
    {
        $subject = new MoveSubject('acme/lib', '1.2.0', true, [], '8.4', null, null, '7.4.29', true);
        $move = new Move(Move::UPDATE, FlagSet::VULNERABLE, [], null, null, null, null, '2.x', '2.0.0', '2.0.0', null, null, null, false, [], [], [], [], [], null, [], [['composer', 'update', 'acme/lib']], $subject);

        $text = MoveText::render($move);

        self::assertSame('First set config.platform.php to your PHP 8.4 release: the lock records 7.4.29. Composer still refuses the lock itself: the moves_not_checked note names the first cause. composer update acme/lib (gets 2.0.0)', $text);
        self::assertStringNotContainsString('Run tab', $text);
        self::assertStringNotContainsString('PHP line', $text);
    }

    public function testAReplaceMoveWithNoReplacementSaysReplaceIt(): void
    {
        $subject = new MoveSubject('acme/lib', '1.2.0', true, [], '8.4', null);
        $move = new Move(Move::REPLACE, FlagSet::ABANDONED, [], null, null, null, null, null, null, null, null, null, null, null, [], [], [], [], [], null, [], [], $subject);

        self::assertSame('replace it', MoveText::render($move));
    }

    public function testAMoveWithoutCommandsTakesNoPlatformStep(): void
    {
        $subject = new MoveSubject('acme/lib', '1.2.0', true, [], '8.4', null, null, '7.4.29');
        $move = new Move(Move::FIND_ALTERNATIVE, FlagSet::ABANDONED, [], null, null, null, null, null, null, null, null, null, null, null, [], [], [], [], [], null, [], [], $subject);

        self::assertSame('find a replacement (none named)', MoveText::render($move));
    }

    /** A refused move keeps one command, the why-not step, and `composer why-not` reads config.platform.php too. */
    public function testARefusedMoveWhoseOnlyCommandIsTheWhyNotStepFirstSetsThePlatformPhp(): void
    {
        $subject = new MoveSubject('acme/lib', '1.2.0', true, [], '8.4', null, null, '7.4.29');
        $move = new Move(Move::UPDATE, FlagSet::VULNERABLE, [], null, null, null, null, '2.x', '2.0.0', '2.0.0', null, null, null, false, [], [], [], [], [], null, [], [['composer', 'why-not', 'acme/lib', '2.0.0', '--locked']], $subject);

        self::assertStringStartsWith('First set config.platform.php to your PHP 8.4 release: the lock records 7.4.29. ', MoveText::render($move->withCheck(MoveCheck::refused())));
    }

    /** monica's firebase/php-jwt: the joint request resolves the move with an update of its two holders. */
    public function testAMoveThatTheJointRequestResolvesNamesEachHolderAndItsRelease(): void
    {
        $subject = new MoveSubject('acme/lib', '1.2.0', true, [], '8.4', null);
        $move = (new Move(Move::UPDATE, FlagSet::VULNERABLE, [], null, null, null, null, '2.x', '2.0.0', '2.0.0', null, null, null, false, [], [], [], [], [], null, [], [['composer', 'update', 'acme/lib:^2.0.0', 'h/x', 'h/y']], $subject))
            ->withCheck(MoveCheck::resolves(MoveCheck::INLINE, '2.0.0'))
            ->withMovesWith([['package' => 'h/x', 'installed_version' => 'v5.23.0', 'version' => 'v5.30.0'], ['package' => 'h/y', 'installed_version' => 'v4.8.1', 'version' => '4.10.0']]);

        self::assertStringEndsWith("; Composer's solver checked it against the lock, with an update of h/x (to v5.30.0) and h/y (to 4.10.0)", MoveText::render($move));
    }

    /** @return iterable<string, array{string}> the kinds of move that a joint request resolves */
    public static function jointKinds(): iterable
    {
        yield 'update' => [Move::UPDATE];
        yield 'require' => [Move::REQUIRE];
    }

    /**
     * The joint request moves what requires the package: the transitive reason asks nothing more.
     *
     * @dataProvider jointKinds
     */
    #[DataProvider('jointKinds')]
    public function testATransitiveMoveThatTheJointRequestResolvesAddsNoTransitiveWords(string $kind): void
    {
        $text = MoveText::render(self::jointMove($kind, [Move::TRANSITIVE], ['c/d']));

        self::assertSame([false, false], [strpos($text, 'must allow it') !== false, strpos($text, 'comes in through') !== false], $text);
    }

    /**
     * @dataProvider jointKinds
     */
    #[DataProvider('jointKinds')]
    public function testAnUnreachedMoveThatTheJointRequestResolvesSaysOnlyThatNoPathWasFound(string $kind): void
    {
        $text = MoveText::render(self::jointMove($kind, [Move::UNREACHED], []));

        self::assertSame([true, false, false], [strpos($text, '; lockrot found no path from your requirements to it; ') !== false, strpos($text, 'must allow it') !== false, strpos($text, 'composer why') !== false], $text);
    }

    public function testATagMoveThatInstallsBelowTheTagNamesTheReleaseAfterTheTagDate(): void
    {
        $subject = new MoveSubject('acme/lib', '1.2.0', true, [], '8.4', null);
        $move = new Move(Move::TAG, FlagSet::PINNED, [], null, null, null, null, null, '2.0.0', '2.1.0', null, new Latest('2.1.0', '2026-01-01T00:00:00+00:00', false, null, null), null, false, [], [], [], [], [], null, [], [['composer', 'require', 'acme/lib:^2.0.0']], $subject);

        self::assertStringStartsWith('require a tag — the newest is 2.1.0 (2026-01-01) (gets 2.0.5, below the newest 2.1.0)', MoveText::render($move->withCheck(MoveCheck::resolves(MoveCheck::INLINE, '2.0.5'))));
    }

    public function testARequireMoveNamesTheReleaseBelowTheNewestBeforeWhatItClears(): void
    {
        $subject = new MoveSubject('acme/lib', '1.2.0', true, [], '8.4', null);
        $move = new Move(Move::REQUIRE, FlagSet::ABANDONED, [], null, null, null, null, null, '2.0.0', '2.1.0', '^2.0', null, null, false, [], [MoveFact::on(MoveFact::FLAG, FlagSet::ABANDONED, MoveFact::OUTSIDE_RANGE)], [], [], [], null, [], [['composer', 'require', 'acme/lib:^2.0']], $subject);

        self::assertStringStartsWith('require acme/lib ^2.0 (gets 2.0.5, below the newest 2.1.0) — clears abandoned', MoveText::render($move->withCheck(MoveCheck::resolves(MoveCheck::INLINE, '2.0.5'))));
    }

    /** The finding's own release holds the move: the hold takes no grade and no flags of the installed version, and follows the root holds. */
    public function testAHoldOfTheFindingsOwnPackageNamesItsOwnRelease(): void
    {
        $graded = new GradedPackage('high', 'left-behind', ['left-behind'], null, null, '1.0.0', true);
        $holds = Holder::filled([
            new Holder(Holder::ROOT, null, null, Holder::REQUIRE, '^1.2'),
            new Holder(Holder::PACKAGE, 'a/early', '1.0.0', Holder::REQUIRE, '^1.2', 'acme/lib'),
            new Holder(Holder::PACKAGE, 'acme/lib', '2.0.0', Holder::REQUIRE, '^1', 'psr/log'),
            new Holder(Holder::PACKAGE, 'acme/lib', '2.0.0', Holder::CONFLICT, '<2', 'psr/clock'),
        ], ['acme/lib' => $graded, 'a/early' => $graded]);
        $subject = new MoveSubject('acme/lib', '1.2.0', true, [], '7.4', null);
        $move = new Move(Move::REQUIRE, FlagSet::LEFT_BEHIND, [], null, null, null, null, '2.x', '2.0.0', '2.0.0', '^2.0', null, null, true, $holds, [], [], [], [], null, [], [], $subject);

        self::assertStringEndsWith('; held by composer.json (requires ^1.2), acme/lib 2.0.0, its own release (requires psr/log ^1), acme/lib 2.0.0, its own release (conflicts with psr/clock <2) and a/early 1.0.0 (requires acme/lib ^1.2; itself left-behind)', MoveText::render($move));
    }

    public function testARootHoldFromRequireDevSaysRequireDev(): void
    {
        $holds = [new Holder(Holder::ROOT, null, null, Holder::REQUIRE_DEV, '^1.2')];
        $subject = new MoveSubject('acme/lib', '1.2.0', true, [], '7.4', null);
        $move = new Move(Move::REQUIRE, FlagSet::LEFT_BEHIND, [], null, null, null, null, '2.x', '2.0.0', '2.0.0', '^2.0', null, null, true, $holds, [], [], [], [], null, [], [], $subject);

        self::assertStringEndsWith('; held by composer.json (requires ^1.2 in require-dev)', MoveText::render($move));
    }

    /** monica's web-auth/webauthn-lib: the resolving form installs 5.3.8, below the branch's newest 5.3.9. */
    public function testAnUpdateWhoseFormInstallsBelowTheNewestReleaseNamesBoth(): void
    {
        $subject = new MoveSubject('web-auth/webauthn-lib', '5.2.0', false, ['web-auth/webauthn-symfony-bundle'], '8.4', null);
        $move = (new Move(Move::UPDATE, FlagSet::VULNERABLE, [], null, null, null, null, '5.x', '5.3.5', '5.3.9', null, null, null, false, [], [], [], [], [], null, [], [['composer', 'update', 'web-auth/webauthn-lib:^5.3.5']], $subject))
            ->withCheck(MoveCheck::resolves(MoveCheck::INLINE, '5.3.8'));

        self::assertStringStartsWith("composer update 'web-auth/webauthn-lib:^5.3.5' (gets 5.3.8, below the newest 5.3.9)", MoveText::render($move));
    }

    /** A run with `--dev` requires a require-dev package directly, so its move needs no section words. */
    public function testARequireMoveOfARequireDevPackageThatTheRunRequiresDirectlyNamesNoSection(): void
    {
        $subject = (new MoveSubject('acme/lib', '1.2.0', true, [], '8.4', null))->withNamedIn(['require-dev']);
        $move = (new Move(Move::REQUIRE, FlagSet::LEFT_BEHIND, [], null, null, null, null, '2.x', '2.0.0', '2.0.0', '^2.0', null, null, true, [], [], [], [], [], null, [], [['composer', 'require', 'acme/lib:^2.0', '--dev']], $subject))
            ->withCheck(MoveCheck::resolves(MoveCheck::INLINE, '2.0.0'));

        self::assertStringStartsWith('require acme/lib ^2.0;', MoveText::render($move));
    }

    public function testATagMoveOfARequireDevPackageNamesTheSection(): void
    {
        $subject = (new MoveSubject('acme/lib', '1.2.0', false, [], '8.4', null))->withNamedIn(['require-dev']);
        $move = new Move(Move::TAG, FlagSet::PINNED, [], null, null, null, null, null, '2.0.0', '2.0.0', null, new Latest('2.0.0', null, false, null, null), null, false, [], [], [], [], [], null, [], [['composer', 'require', 'acme/lib:^2.0.0', '--dev']], $subject);

        self::assertStringStartsWith('require a tag in require-dev — the newest is 2.0.0', MoveText::render($move));
    }

    /** A package that only require-dev lists reads as a root requirement of that section, also in a run without `--dev`. */
    public function testARequireMoveOfARequireDevPackageNamesTheSectionThenTheReleaseItGets(): void
    {
        $subject = (new MoveSubject('acme/lib', '1.2.0', false, [], '8.4', null))->withNamedIn(['require-dev']);
        $move = (new Move(Move::REQUIRE, FlagSet::LEFT_BEHIND, [], null, null, null, null, '2.x', '2.0.0', '2.1.0', '^2.0', null, null, true, [], [], [], [], [], null, [], [['composer', 'require', 'acme/lib:^2.0', '--dev']], $subject))
            ->withCheck(MoveCheck::resolves(MoveCheck::INLINE, '2.0.4'));

        self::assertStringStartsWith('require acme/lib ^2.0 in require-dev (gets 2.0.4, below the newest 2.1.0)', MoveText::render($move));
    }

    /** Composer moves a name that both sections list to require, so the text names the removed line (R6-3). */
    public function testARequireMoveOfANameThatBothSectionsListSaysItRemovesTheRequireDevLine(): void
    {
        $subject = (new MoveSubject('acme/lib', '1.2.0', true, [], '8.4', null))->withKeys([ProjectConfig::REQUIRE => 'acme/lib', ProjectConfig::REQUIRE_DEV => 'Acme/Lib'], []);
        $move = (new Move(Move::REQUIRE, FlagSet::LEFT_BEHIND, [], null, null, null, null, '2.x', '2.0.0', '2.0.0', '^2.0', null, null, true, [], [], [], [], [], null, [], [['composer', 'require', 'Acme/Lib:^2.0', '--no-interaction']], $subject))
            ->withCheck(MoveCheck::resolves(MoveCheck::INLINE, '2.0.0'));

        self::assertStringStartsWith('require acme/lib ^2.0, which removes it from require-dev;', MoveText::render($move));
    }

    public function testATagMoveOfANameThatBothSectionsListSaysItRemovesTheRequireDevLine(): void
    {
        $subject = (new MoveSubject('acme/lib', '1.2.0', false, [], '8.4', null))->withKeys([ProjectConfig::REQUIRE => 'acme/lib', ProjectConfig::REQUIRE_DEV => 'acme/lib'], []);
        $move = new Move(Move::TAG, FlagSet::PINNED, [], null, null, null, null, null, '2.0.0', '2.0.0', null, new Latest('2.0.0', null, false, null, null), null, false, [], [], [], [], [], null, [], [['composer', 'require', 'acme/lib:^2.0.0', '--no-interaction']], $subject);

        self::assertStringStartsWith('require a tag, which removes it from require-dev — the newest is 2.0.0', MoveText::render($move));
    }

    public function testAPhpRaiseThatRequireDevListsSaysItRemovesTheRequireDevLine(): void
    {
        $subject = (new MoveSubject('acme/lib', '1.2.0', true, [], '8.4', '7.4'))->withKeys([ProjectConfig::REQUIRE => 'acme/lib'], [ProjectConfig::REQUIRE => 'php', ProjectConfig::REQUIRE_DEV => 'php']);
        $phpCheck = PhpCheck::of('>=8.1', new PhpFloor('8.4', '>=7.4'));
        $move = (new Move(Move::RAISE_PHP, FlagSet::LEFT_BEHIND, [], null, null, null, null, '2.x', '2.0.0', '2.0.0', '^2.0', null, $phpCheck, true, [], [], [], [], [], null, [], [['composer', 'require', 'php:>=8.1', '--no-interaction', '--no-update'], ['composer', 'require', 'acme/lib:^2.0']], $subject))
            ->withCheck(MoveCheck::resolves(MoveCheck::INLINE, '2.0.0'));

        self::assertStringStartsWith('raise require.php to >=8.1, which removes it from require-dev, then ', MoveText::render($move));
    }

    public function testThePhpRaiseBeforeAnUpdateIsProseAndTheUpdateIsTheOneCommand(): void
    {
        $subject = new MoveSubject('acme/lib', '1.2.0', true, [], '8.4', '7.4');
        $move = new Move(Move::UPDATE, FlagSet::VULNERABLE, [], null, null, null, null, '1.x', '1.2.5', '1.2.5', null, null, PhpCheck::of('>=8.1', new PhpFloor('8.4', '>=7.4')), false, [], [], [], [], [], null, [], [['composer', 'update', 'acme/lib']], $subject);

        $parts = MoveText::parts($move);

        self::assertStringStartsWith('raise require.php to >=8.1, then ', $parts[0][0]);
        self::assertSame(['composer update acme/lib'], array_column(array_values(array_filter($parts, static fn (array $part): bool => $part[1])), 0));
    }

    public function testASelfVersionHoldNamesTheHoldersVersion(): void
    {
        $hold = new Holder(Holder::PACKAGE, 'acme/suite', '3.1.0', Holder::REQUIRE, 'self.version', 'acme/lib');

        self::assertSame('requires acme/lib self.version (3.1.0)', MoveText::linkWords($hold));
    }

    /** mautic's jbroadway/urlify: each dependent group gets its own action, and `chain[0]` names the package that requires the finding. */
    public function testATransitiveMoveNamesOneActionPerDependentGroupAndTheRequirer(): void
    {
        $through = [
            ThroughEntry::unread('mautic/core-lib', 'dev-main', ThroughEntry::NOT_FROM_COMPOSER_REPOSITORY)->withInstalled(true, null),
            ThroughEntry::unread('c/unread', '2.0.0', ThroughEntry::RELEASES_UNKNOWN)->withInstalled(false, '2.0.0.0'),
            ThroughEntry::measured('l/newest', '1.0.0', 'acme/lib', ['acme/lib'], new StableRelease('1.0.0.0', '1.0.0', new \DateTimeImmutable('2025-01-01'), null, false), ['acme/lib'])->withInstalled(false, '1.0.0.0'),
        ];
        $subject = (new MoveSubject('acme/lib', '1.2.0', false, ['mautic/core-lib', 'c/unread', 'l/newest'], '8.4', null))->withChain(['mautic/core-lib', 'jbroadway/urlify', 'acme/lib']);
        $move = (new Move(Move::FIND_ALTERNATIVE, FlagSet::ABANDONED, [], null, null, null, null, null, null, null, null, null, null, null, [], [], [], [], [], null, [Move::TRANSITIVE], [], $subject))->asLead($through, []);

        self::assertStringContainsString(
            '; it comes in through mautic/core-lib (jbroadway/urlify requires it), c/unread and l/newest: change your own package mautic/core-lib, or replace or update c/unread, or replace l/newest',
            MoveText::render($move)
        );
    }

    /**
     * The root holds, then the own releases, then the package holds on the finding's package, then
     * the other package holds. Within a group: by grade, then by name. The order does not rest on
     * the order of `held_by[]`.
     */
    public function testTheTextRanksTheHoldsByGroupThenByGradeThenByName(): void
    {
        $high = new GradedPackage('high', 'left-behind', ['left-behind'], null, null, '1.0.0', true);
        $critical = new GradedPackage('critical', null, ['vulnerable'], null, null, '1.0.0', true);
        $holds = Holder::filled([
            new Holder(Holder::PACKAGE, 'a/other', '1.0.0', Holder::REQUIRE, '^1', 'psr/log'),
            new Holder(Holder::PACKAGE, 'z/critical', '1.0.0', Holder::REQUIRE, '^1', 'psr/log'),
            new Holder(Holder::PACKAGE, 'a/plain', '1.0.0', Holder::REQUIRE, '^1.2'),
            new Holder(Holder::PACKAGE, 'c/high', '1.0.0', Holder::REQUIRE, '^1.2', 'acme/lib'),
            new Holder(Holder::PACKAGE, 'b/high', '1.0.0', Holder::REQUIRE, '^1.2'),
            new Holder(Holder::PACKAGE, 'acme/lib', '2.0.0', Holder::REQUIRE, '^1', 'psr/log'),
            new Holder(Holder::ROOT, null, null, Holder::REQUIRE_DEV, '^1.2'),
            new Holder(Holder::ROOT, null, null, Holder::REQUIRE, '^1.2'),
        ], ['z/critical' => $critical, 'c/high' => $high, 'b/high' => $high, 'a/plain' => new GradedPackage('finished', null, [], null, null, '1.0.0', true)]);

        $ordered = MoveText::inTextOrder($holds, 'acme/lib');

        self::assertSame([$holds[6], $holds[7], $holds[5], $holds[4], $holds[3], $holds[2], $holds[1], $holds[0]], $ordered);
    }

    public function testWithCheckLeavesTheOriginalMoveUnchecked(): void
    {
        $move = self::move(Move::UPDATE, null, []);

        $checked = $move->withCheck(MoveCheck::refused());

        self::assertNull($move->check());
        self::assertNotNull($checked->check());
    }

    /**
     * @dataProvider movesAndTheirParts
     *
     * @param list<list<string>>              $commands
     * @param list<array{0: string, 1: bool}> $parts
     */
    #[DataProvider('movesAndTheirParts')]
    public function testTheMoveTextIsBuiltFromProseAndTheCommandThatItNames(string $kind, string $source, ?string $override, array $commands, array $parts): void
    {
        $move = new Move($kind, $source, [], null, null, null, null, '2.x', '2.0.0', '2.0.0', null, null, null, false, [], [], [], [], [], null, [], $commands, new MoveSubject('acme/lib', '1.2.0', true, [], '8.4', null, null, $override));

        self::assertSame($parts, MoveText::parts($move));
    }

    /** @return iterable<string, array{string, string, ?string, list<list<string>>, list<array{0: string, 1: bool}>}> */
    public static function movesAndTheirParts(): iterable
    {
        $update = ['composer', 'update', 'acme/lib'];
        yield 'the command first' => [Move::UPDATE, FlagSet::VULNERABLE, null, [$update], [['composer update acme/lib', true], [' (gets 2.0.0)', false]]];
        yield 'a platform step before the command' => [Move::UPDATE, FlagSet::VULNERABLE, '7.4.29', [$update], [['First set config.platform.php to your PHP 8.4 release: the lock records 7.4.29. ', false], ['composer update acme/lib', true], [' (gets 2.0.0)', false]]];
        yield 'a command that no shell takes, before the named one' => [Move::UPDATE, FlagSet::VULNERABLE, null, [['composer', 'update', 'acme/lib;x'], $update], [['composer update acme/lib', true], [' (gets 2.0.0)', false]]];
        yield 'no command that a shell takes' => [Move::UPDATE, FlagSet::VULNERABLE, null, [['composer', 'update', 'acme/lib;x']], [['update it with Composer (gets 2.0.0)', false]]];
        yield 'no command in the text' => [Move::FIND_ALTERNATIVE, FlagSet::ABANDONED, null, [], [['find a replacement (none named)', false]]];
    }

    /**
     * The lock's `platform-overrides.php` is printed as written when it is not a stable release of
     * the target minor, so it can spell the command. The command piece is the move's own.
     */
    public function testAPlatformValueThatSpellsTheCommandStaysProse(): void
    {
        $override = '7.4 composer update acme/c0ffee12 --with-dependencies';
        $subject = new MoveSubject('acme/c0ffee12', '1.0.0', true, [], '8.4', null, null, $override);
        $move = new Move(Move::UPDATE, FlagSet::VULNERABLE, [], null, null, null, null, '1.x', '1.0.3', '1.0.3', null, null, null, false, [], [], [], [], [], null, [], [['composer', 'update', 'acme/c0ffee12', '--with-dependencies']], $subject);

        self::assertSame([
            ['First set config.platform.php to your PHP 8.4 release: the lock records '.$override.'. ', false],
            ['composer update acme/c0ffee12 --with-dependencies', true],
            [' (gets 1.0.3)', false],
        ], MoveText::parts($move));
    }

    /**
     * Over every model case and its `also[]` moves: the pieces concatenate to `next_step.text`, at
     * most one piece is a command, it is the POSIX rendering of one of the move's commands, and no
     * piece is empty or only whitespace.
     */
    /** The pieces of each case move join to the `text` that tests/fixtures/golden/moves.json records. */
    public function testThePiecesOfEveryCaseMoveAreItsRecordedTextAndAtMostOneOfItsCommands(): void
    {
        $golden = JsonPath::decodeFile(__DIR__.'/../../fixtures/golden/moves.json');
        $moves = [];
        foreach (MoveTest::casesWithInputs() as $id => [$caseId]) {
            $case = CaseHydrator::case($caseId);
            $package = JsonPath::stringAt($case, ['inputs', 'lock', 0, 'name']);
            foreach (CaseHydrator::report($case)->findings() as $finding) {
                $move = $finding->package() === $package ? $finding->nextStep() : null;
                if ($move === null) {
                    continue;
                }
                $moves[$id] = [$move, JsonPath::stringAt($golden, [$id, 'next_step', 'text'])];
                foreach ($move->also() as $index => $also) {
                    $moves[$id.' also['.$index.']'] = [$also, JsonPath::stringAt($golden, [$id, 'next_step', 'also', $index, 'text'])];
                }
            }
        }
        self::assertNotSame([], $moves);

        foreach ($moves as $name => [$move, $recorded]) {
            $parts = MoveText::parts($move);
            $commands = array_values(array_filter($parts, static fn (array $part): bool => $part[1]));
            self::assertSame($recorded, implode('', array_column($parts, 0)), $name);
            self::assertLessThanOrEqual(1, \count($commands), $name);
            foreach ($commands as [$command]) {
                self::assertContains($command, array_map([ShellCommand::class, 'posix'], $move->commands()), $name);
            }
            foreach ($parts as [$text]) {
                self::assertNotSame('', trim($text), $name);
            }
        }
    }

    /** @param list<string> $because */
    private static function move(string $kind, ?PhpCheck $php, array $because): Move
    {
        $subject = new MoveSubject('acme/lib', '1.2.0', true, [], '7.4', null);
        $commands = $kind === Move::BLOCKED ? [] : [['composer', 'update', 'acme/lib']];

        return new Move($kind, FlagSet::VULNERABLE, [], null, null, null, null, '2.x', '2.0.0', '2.0.0', '^2.0', null, $php, true, [], [], [], [], [], null, $because, $commands, $subject);
    }

    /**
     * acme/lib, which c/d requires when $dependents names it, resolved by a joint request that moves h/x.
     *
     * @param list<string> $because
     * @param list<string> $dependents
     */
    private static function jointMove(string $kind, array $because, array $dependents): Move
    {
        $subject = new MoveSubject('acme/lib', '1.2.0', false, $dependents, '8.4', null);
        $move = new Move($kind, FlagSet::LEFT_BEHIND, [], null, null, null, null, '2.x', '2.0.0', '2.0.0', '^2.0', null, null, false, [], [], [], [], [], null, $because, [['composer', 'update', 'acme/lib:^2.0', 'h/x']], $subject);

        return $move->withCheck(MoveCheck::resolves(MoveCheck::INLINE, '2.0.0'))->withMovesWith([['package' => 'h/x', 'installed_version' => '1.0.0', 'version' => '2.1.0']]);
    }
}
