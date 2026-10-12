<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

use Lockrot\Score\IfApplied;
use Lockrot\Security\Holder;
use Lockrot\Security\PhpCheck;

/**
 * One move of `next_step`, or an entry of its `also[]`: what to run, what it clears, leaves and
 * adds, what holds it, and the score after it. `next_step` is the only place a move is published:
 * `security.move_in` points at it (docs/verdicts.md#move).
 *
 * @internal
 *
 * @phpstan-type Shape array{kind: string, source: string, merged: list<string>, fix_kind: ?string, reason: ?string, replacement: ?Replacement, quiet_years: ?float, to_branch: ?string, version: ?string, newest: ?string, constraint: ?string, latest: ?Latest, php_check: ?PhpCheck, crosses_major: ?bool, held_by: list<Holder>, clears: list<MoveFact>, unverified: list<MoveFact>, adds: list<MoveFact>, leaves: list<MoveFact>, clears_all: bool, if_applied: ?IfApplied, composer_moves_because: list<string>, commands: list<list<string>>, through?: list<ThroughEntry>, also?: list<self>, text: string}
 */
final class Move implements \JsonSerializable
{
    public const UPDATE = 'update';
    public const REQUIRE = 'require';
    public const RAISE_PHP = 'raise-php';
    public const BLOCKED = 'blocked';
    public const TAG = 'tag';
    public const TEST = 'test';
    public const REPLACE = 'replace';
    public const FIND_ALTERNATIVE = 'find-alternative';
    public const NO_FIX = 'no-fix';
    public const NO_SINGLE_FIX = 'no-single-fix';
    public const NO_TAG = 'no-tag';
    public const NO_MOVE = 'no-move';

    /** The kinds that move to a branch whose release the scan read. */
    public const ROW_KINDS = [self::UPDATE, self::REQUIRE, self::RAISE_PHP];

    public const HOLDERS = 'holders';
    public const CROSSES_MAJOR = 'crosses_major';
    public const TRANSITIVE = 'transitive';
    public const UNREACHED = 'unreached';

    private string $kind;
    private string $source;
    /** @var list<string> */
    private array $merged;
    private ?string $fixKind;
    private ?string $reason;
    private ?Replacement $replacement;
    private ?float $quietYears;
    private ?string $toBranch;
    private ?string $version;
    private ?string $newest;
    private ?string $constraint;
    private ?Latest $latest;
    private ?PhpCheck $phpCheck;
    private ?bool $crossesMajor;
    /** @var list<Holder> */
    private array $heldBy;
    /** @var list<MoveFact> */
    private array $clears;
    /** @var list<MoveFact> */
    private array $unverified;
    /** @var list<MoveFact> */
    private array $adds;
    /** @var list<MoveFact> */
    private array $leaves;
    private ?IfApplied $ifApplied;
    /** @var list<string> */
    private array $composerMovesBecause;
    /** @var list<list<string>> */
    private array $commands;
    /** @var list<ThroughEntry>|null null on an `also[]` move */
    private ?array $through = null;
    /** @var list<self>|null null on an `also[]` move */
    private ?array $also = null;
    private MoveSubject $subject;

    /**
     * @param list<string>       $merged
     * @param list<Holder>       $heldBy
     * @param list<MoveFact>     $clears
     * @param list<MoveFact>     $unverified
     * @param list<MoveFact>     $adds
     * @param list<MoveFact>     $leaves
     * @param list<string>       $composerMovesBecause
     * @param list<list<string>> $commands argv lists, in the order a user runs them
     */
    public function __construct(
        string $kind,
        string $source,
        array $merged,
        ?string $fixKind,
        ?string $reason,
        ?Replacement $replacement,
        ?float $quietYears,
        ?string $toBranch,
        ?string $version,
        ?string $newest,
        ?string $constraint,
        ?Latest $latest,
        ?PhpCheck $phpCheck,
        ?bool $crossesMajor,
        array $heldBy,
        array $clears,
        array $unverified,
        array $adds,
        array $leaves,
        ?IfApplied $ifApplied,
        array $composerMovesBecause,
        array $commands,
        MoveSubject $subject
    ) {
        $this->kind = $kind;
        $this->source = $source;
        $this->merged = $merged;
        $this->fixKind = $fixKind;
        $this->reason = $reason;
        $this->replacement = $replacement;
        $this->quietYears = $quietYears;
        $this->toBranch = $toBranch;
        $this->version = $version;
        $this->newest = $newest;
        $this->constraint = $constraint;
        $this->latest = $latest;
        $this->phpCheck = $phpCheck;
        $this->crossesMajor = $crossesMajor;
        $this->heldBy = $heldBy;
        $this->clears = $clears;
        $this->unverified = $unverified;
        $this->adds = $adds;
        $this->leaves = $leaves;
        $this->ifApplied = $ifApplied;
        $this->composerMovesBecause = $composerMovesBecause;
        $this->commands = $commands;
        $this->subject = $subject;
    }

    /**
     * The lead move: its `through[]` entries, the `composer why-not` command of each dependent whose newest
     * release does not require the package, and the other part's move.
     *
     * @param list<ThroughEntry> $through
     * @param list<self>         $also
     */
    public function asLead(array $through, array $also): self
    {
        $clone = clone $this;
        $clone->through = $through;
        $clone->also = $also;
        foreach ($through as $entry) {
            $version = $entry->newestVersion();
            if ($entry->newestRequires() === false && $version !== null) {
                $clone->commands[] = ['composer', 'why-not', $entry->package(), $version];
            }
        }

        return $clone;
    }

    /** A copy whose fix kind is the class of its own release: a maintenance move that clears every advisory. */
    public function withFixKind(?string $fixKind): self
    {
        $clone = clone $this;
        $clone->fixKind = $fixKind;

        return $clone;
    }

    /** @param array<string, GradedPackage> $findings by package name */
    public function withFindings(array $findings): self
    {
        $clone = clone $this;
        $clone->heldBy = Holder::filled($this->heldBy, $findings);
        if ($this->replacement !== null && $this->replacement->package() !== null) {
            $clone->replacement = $this->replacement->withFinding($findings[$this->replacement->package()] ?? null);
        }
        if ($this->also !== null) {
            $clone->also = array_map(static fn (self $move): self => $move->withFindings($findings), $this->also);
        }

        return $clone;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function source(): string
    {
        return $this->source;
    }

    /** @return list<string> */
    public function merged(): array
    {
        return $this->merged;
    }

    /** Whether the move comes from `vulnerable`, alone or merged. */
    public function isSecurity(): bool
    {
        return $this->source === FlagSet::VULNERABLE || \in_array(FlagSet::VULNERABLE, $this->merged, true);
    }

    public function fixKind(): ?string
    {
        return $this->fixKind;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function replacement(): ?Replacement
    {
        return $this->replacement;
    }

    public function quietYears(): ?float
    {
        return $this->quietYears;
    }

    public function toBranch(): ?string
    {
        return $this->toBranch;
    }

    public function version(): ?string
    {
        return $this->version;
    }

    public function newest(): ?string
    {
        return $this->newest;
    }

    public function constraint(): ?string
    {
        return $this->constraint;
    }

    public function latest(): ?Latest
    {
        return $this->latest;
    }

    public function phpCheck(): ?PhpCheck
    {
        return $this->phpCheck;
    }

    public function crossesMajor(): ?bool
    {
        return $this->crossesMajor;
    }

    /** @return list<Holder> */
    public function heldBy(): array
    {
        return $this->heldBy;
    }

    /** @return list<MoveFact> */
    public function clears(): array
    {
        return $this->clears;
    }

    /** @return list<MoveFact> */
    public function unverified(): array
    {
        return $this->unverified;
    }

    /** @return list<MoveFact> */
    public function adds(): array
    {
        return $this->adds;
    }

    /** @return list<MoveFact> */
    public function leaves(): array
    {
        return $this->leaves;
    }

    public function ifApplied(): ?IfApplied
    {
        return $this->ifApplied;
    }

    /** @return list<string> */
    public function composerMovesBecause(): array
    {
        return $this->composerMovesBecause;
    }

    /** @return list<list<string>> */
    public function commands(): array
    {
        return $this->commands;
    }

    /** @return list<ThroughEntry> */
    public function through(): array
    {
        return $this->through ?? [];
    }

    /** @return list<self> */
    public function also(): array
    {
        return $this->also ?? [];
    }

    public function subject(): MoveSubject
    {
        return $this->subject;
    }

    /** @return list<string> the advisory ids that {@see clears()} names */
    public function clearedAdvisories(): array
    {
        $ids = [];
        foreach ($this->clears as $fact) {
            if (!$fact->isFlag()) {
                $ids[] = $fact->id();
            }
        }

        return $ids;
    }

    public function text(): string
    {
        return MoveText::render($this);
    }

    /** @return Shape */
    public function jsonSerialize(): array
    {
        $out = [
            'kind' => $this->kind,
            'source' => $this->source,
            'merged' => $this->merged,
            'fix_kind' => $this->fixKind,
            'reason' => $this->reason,
            'replacement' => $this->replacement,
            'quiet_years' => $this->quietYears,
            'to_branch' => $this->toBranch,
            'version' => $this->version,
            'newest' => $this->newest,
            'constraint' => $this->constraint,
            'latest' => $this->latest,
            'php_check' => $this->phpCheck,
            'crosses_major' => $this->crossesMajor,
            'held_by' => $this->heldBy,
            'clears' => $this->clears,
            'unverified' => $this->unverified,
            'adds' => $this->adds,
            'leaves' => $this->leaves,
            'clears_all' => $this->clears !== [] && $this->leaves === [] && $this->unverified === [],
            'if_applied' => $this->ifApplied,
            'composer_moves_because' => $this->composerMovesBecause,
            'commands' => $this->commands,
        ];
        if ($this->through !== null) {
            $out['through'] = $this->through;
        }
        if ($this->also !== null) {
            $out['also'] = $this->also;
        }

        return $out + ['text' => $this->text()];
    }
}
