<?php

declare(strict_types=1);

namespace Lockrot\Signal\Rule;

use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalRule;

/** @internal */
final class AbandonedRule implements SignalRule
{
    public function evaluate(PackageFacts $facts): ?Signal
    {
        $metadata = $facts->metadata();
        if ($metadata !== null) {
            if (!$metadata->isAbandoned()) {
                return null;
            }
            $summary = 'marked abandoned by its repository';
            if ($metadata->replacement() !== null) {
                $summary .= ', replacement: '.$metadata->replacement();
            }

            return new Signal(Signal::S1, Signal::LEVEL_HIGH, $summary, ['replacement' => $metadata->replacement()]);
        }
        $inLock = $facts->package()->abandonedInLock();
        if ($inLock === true || (\is_string($inLock) && $inLock !== '')) {
            $summary = 'marked abandoned in composer.lock';
            if (\is_string($inLock)) {
                $summary .= ', replacement: '.$inLock;
            }

            return new Signal(Signal::S1, Signal::LEVEL_HIGH, $summary, ['replacement' => \is_string($inLock) ? $inLock : null]);
        }

        return null;
    }

    /**
     * Who named the replacement S1 carries, as {@see \Lockrot\Lock\PackageOrigin::registryOf()}
     * names registries: the repository metadata's registry when there is metadata, which is what S1
     * reads first, the lock entry's own otherwise.
     */
    public static function replacementNamedBy(PackageFacts $facts): ?string
    {
        $metadata = $facts->metadata();

        return $metadata !== null ? $metadata->abandonedBy() : $facts->package()->origin()->registry();
    }
}
