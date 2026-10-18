<?php

declare(strict_types=1);

namespace Lockrot\Solver;

/**
 * A move's `check`: whether Composer's solver installs it from the lock, in which command form,
 * or why lockrot did not ask (docs/verdicts.md#the-move-check).
 *
 * @internal
 */
final class MoveCheck implements \JsonSerializable
{
    public const RESOLVES = 'resolves';
    public const REFUSED = 'refused';
    public const NOT_CHECKED = 'not_checked';

    public const INLINE = 'inline';
    public const WITH_DEPENDENCIES = 'with-dependencies';
    public const WITH_ALL_DEPENDENCIES = 'with-all-dependencies';

    /** The option that a command of each form adds. */
    public const OPTIONS = [
        self::WITH_DEPENDENCIES => '--with-dependencies',
        self::WITH_ALL_DEPENDENCIES => '--with-all-dependencies',
    ];

    public const BASELINE_REFUSED = 'baseline_refused';
    public const UNLOADED_NAME = 'unloaded_name';
    public const OFFLINE = 'offline';
    public const INSTALL_TIME_BUDGET = 'install_time_budget';
    public const NOT_FROM_COMPOSER_REPOSITORY = 'not_from_composer_repository';
    public const NO_COMPOSER_JSON = 'no_composer_json';
    public const NO_RELEASE_READ = 'no_release_read';
    public const PASS_CAP = 'pass_cap';

    /** The reasons that lockrot writes, in the order that the schema lists them. */
    public const REASONS = [self::BASELINE_REFUSED, self::UNLOADED_NAME, self::OFFLINE, self::INSTALL_TIME_BUDGET, self::NOT_FROM_COMPOSER_REPOSITORY, self::NO_COMPOSER_JSON, self::NO_RELEASE_READ, self::PASS_CAP];

    /** The reasons that leave the baseline itself unchecked (`run.move_check.reason`), in the schema's order. */
    public const BASELINE_REASONS = [self::INSTALL_TIME_BUDGET, self::NO_COMPOSER_JSON];

    /** Why lockrot did not ask Composer's solver about the move, by `check.reason`. */
    private const REASON_WORDS = [
        self::BASELINE_REFUSED => 'Composer refuses the lock itself',
        self::UNLOADED_NAME => 'a package it needs could not be loaded',
        self::OFFLINE => "a package it needs is not in Composer's cache",
        self::INSTALL_TIME_BUDGET => 'the install-time budget ran out',
        self::NOT_FROM_COMPOSER_REPOSITORY => 'no Composer repository serves it',
        self::NO_COMPOSER_JSON => 'the project has no composer.json',
        self::NO_RELEASE_READ => 'no release of the row was read',
        self::PASS_CAP => 'Composer refused each move before it',
    ];

    /** @var self::RESOLVES|self::REFUSED|self::NOT_CHECKED */
    private string $result;
    private ?string $form;
    private ?string $reason;

    /** @param self::RESOLVES|self::REFUSED|self::NOT_CHECKED $result */
    private function __construct(string $result, ?string $form, ?string $reason)
    {
        $this->result = $result;
        $this->form = $form;
        $this->reason = $reason;
    }

    /** @param self::INLINE|self::WITH_DEPENDENCIES|self::WITH_ALL_DEPENDENCIES $form */
    public static function resolves(string $form): self
    {
        return new self(self::RESOLVES, $form, null);
    }

    public static function refused(): self
    {
        return new self(self::REFUSED, null, null);
    }

    /** The words of a `check.reason`, or the reason itself when it is unknown. */
    public static function reasonWords(string $reason): string
    {
        return self::REASON_WORDS[$reason] ?? $reason;
    }

    public static function notChecked(string $reason): self
    {
        return new self(self::NOT_CHECKED, null, $reason);
    }

    /** @return self::RESOLVES|self::REFUSED|self::NOT_CHECKED */
    public function result(): string
    {
        return $this->result;
    }

    public function form(): ?string
    {
        return $this->form;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function isResolved(): bool
    {
        return $this->result === self::RESOLVES;
    }

    public function isRefused(): bool
    {
        return $this->result === self::REFUSED;
    }

    /** @return array{result: string, form: ?string, reason: ?string} */
    public function jsonSerialize(): array
    {
        return ['result' => $this->result, 'form' => $this->form, 'reason' => $this->reason];
    }
}
