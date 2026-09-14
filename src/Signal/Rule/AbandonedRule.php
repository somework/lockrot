<?php

declare(strict_types=1);

namespace Lockrot\Signal\Rule;

use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalRule;

final class AbandonedRule implements SignalRule
{
    public function evaluate(PackageFacts $facts): ?Signal
    {
        $metadata = $facts->metadata();
        if ($metadata !== null) {
            if (!$metadata->isAbandoned()) {
                return null;
            }
            $summary = 'flagged abandoned on Packagist';
            if ($metadata->replacement() !== null) {
                $summary .= ', replacement: '.$metadata->replacement();
            }

            return new Signal(Signal::S1, Signal::LEVEL_HIGH, $summary, ['replacement' => $metadata->replacement()]);
        }
        $inLock = $facts->package()->abandonedInLock();
        if ($inLock === true || (\is_string($inLock) && $inLock !== '')) {
            $summary = 'flagged abandoned (from composer.lock)';
            if (\is_string($inLock)) {
                $summary .= ', replacement: '.$inLock;
            }

            return new Signal(Signal::S1, Signal::LEVEL_HIGH, $summary, ['replacement' => \is_string($inLock) ? $inLock : null]);
        }

        return null;
    }
}
