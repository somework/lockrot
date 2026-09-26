<?php

declare(strict_types=1);

namespace Lockrot\Verdict;

use Lockrot\Signal\Signal;

/**
 * Turns a package's signals into its one verdict, highest severity first.
 *
 * @internal
 */
final class VerdictEngine
{
    /** @param list<Signal> $signals */
    public function decide(array $signals, bool $allowlisted, bool $hasData): string
    {
        if ($allowlisted) {
            return Verdict::FINISHED;
        }
        $byId = [];
        foreach ($signals as $signal) {
            $byId[$signal->id()] = $signal;
        }
        if (isset($byId[Signal::S1]) || isset($byId[Signal::S3])) {
            return Verdict::ABANDONED;
        }
        if (isset($byId[Signal::S2], $byId[Signal::S4]) && $byId[Signal::S2]->isHigh() && $byId[Signal::S4]->isHigh()) {
            return Verdict::SILENT;
        }
        if (isset($byId[Signal::S6])) {
            return Verdict::PINNED;
        }
        if (isset($byId[Signal::S8])) {
            return Verdict::LEFT_BEHIND;
        }
        if (isset($byId[Signal::S5])) {
            return Verdict::OLD_PROMISE;
        }
        if (isset($byId[Signal::S2]) || isset($byId[Signal::S4])) {
            return Verdict::STALE;
        }
        if (!$hasData) {
            return Verdict::UNKNOWN;
        }

        return Verdict::OK;
    }
}
