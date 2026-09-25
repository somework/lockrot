<?php

declare(strict_types=1);

namespace Lockrot\SelfUpdate;

/**
 * What {@see ReleaseLocator} decided: the release to install, if any, and one line for each reason
 * a newer release was passed over — a new major version, a PHP floor above this one, a key this
 * archive does not carry — naming the newest release held back for it.
 *
 * The notes are printed before anything is installed. After the archive is replaced the running
 * process can no longer load a class it has not used yet, so everything to be said is decided here.
 *
 * @internal
 */
final class ReleaseChoice
{
    private ?Release $release;
    /** @var list<string> */
    private array $notes;

    /** @param list<string> $notes */
    public function __construct(?Release $release, array $notes)
    {
        $this->release = $release;
        $this->notes = $notes;
    }

    /** The release to install, or null when nothing is: the build is current in its line. */
    public function release(): ?Release
    {
        return $this->release;
    }

    /** @return list<string> */
    public function notes(): array
    {
        return $this->notes;
    }
}
