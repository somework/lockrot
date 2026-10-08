<?php

declare(strict_types=1);

namespace Lockrot\Signal\Rule;

use Lockrot\Lock\PackageOrigin;
use Lockrot\Signal\AbandonedIgnored;
use Lockrot\Signal\PackageFacts;
use Lockrot\Signal\Signal;
use Lockrot\Signal\SignalRule;
use Lockrot\Verdict\Finding;

/**
 * S1. A name that Composer's abandoned ignore list matches raises none: {@see ignored()} keeps the
 * marking instead (docs/verdicts.md#abandoned).
 *
 * @internal
 */
final class AbandonedRule implements SignalRule
{
    public function evaluate(PackageFacts $facts): ?Signal
    {
        $marking = self::marking($facts);
        if ($marking === null || $facts->abandonedIgnore() !== null) {
            return null;
        }
        $summary = $marking['marked_by'] === AbandonedIgnored::MARKED_BY_REPOSITORY ? 'marked abandoned by its repository' : 'marked abandoned in composer.lock';
        if ($marking['replacement'] !== null) {
            $summary .= ', replacement: '.$marking['replacement'];
        }

        $successor = Finding::successorOf($facts->package()->name(), $marking['replacement']);

        return new Signal(Signal::S1, Signal::LEVEL_HIGH, $summary, [
            'marked_by' => $marking['marked_by'],
            'replacement' => $marking['replacement'],
            'replacement_url' => $successor === null ? null : PackageOrigin::replacementPage(self::replacementNamedBy($facts), $successor),
        ]);
    }

    /** The marking that the list kept out of S1. Null when the list does not match, or nobody marked the package. */
    public static function ignored(PackageFacts $facts): ?AbandonedIgnored
    {
        $match = $facts->abandonedIgnore();
        $marking = self::marking($facts);
        if ($match === null || $marking === null) {
            return null;
        }
        $successor = Finding::successorOf($facts->package()->name(), $marking['replacement']);

        return new AbandonedIgnored(
            $match->by(),
            $match->rules(),
            $marking['marked_by'],
            $marking['replacement'],
            $successor === null ? null : PackageOrigin::replacementPage(self::replacementNamedBy($facts), $successor)
        );
    }

    /**
     * The repository metadata decides when there is some. Else the lock entry, whose `abandoned` is
     * true or a replacement.
     *
     * @return array{marked_by: string, replacement: ?string}|null
     */
    private static function marking(PackageFacts $facts): ?array
    {
        $metadata = $facts->metadata();
        if ($metadata !== null) {
            return $metadata->isAbandoned() ? ['marked_by' => AbandonedIgnored::MARKED_BY_REPOSITORY, 'replacement' => $metadata->replacement()] : null;
        }
        $inLock = $facts->package()->abandonedInLock();
        if ($inLock === true || (\is_string($inLock) && $inLock !== '')) {
            return ['marked_by' => AbandonedIgnored::MARKED_BY_LOCK, 'replacement' => \is_string($inLock) ? $inLock : null];
        }

        return null;
    }

    /**
     * The registry that named S1's replacement ({@see \Lockrot\Lock\PackageOrigin::registryOf()}):
     * the repository metadata's, which S1 reads first, else the lock entry's.
     */
    public static function replacementNamedBy(PackageFacts $facts): ?string
    {
        $metadata = $facts->metadata();

        return $metadata !== null ? $metadata->abandonedBy() : $facts->package()->origin()->registry();
    }
}
