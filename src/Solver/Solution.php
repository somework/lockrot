<?php

declare(strict_types=1);

namespace Lockrot\Solver;

use Lockrot\Security\Holder;

/**
 * What one solve gave: the versions it installs, or the holds and Composer's problem text of a
 * refusal, with the names that a refusal needs and that the {@see VersionSource} could not load, or
 * the exception that Composer failed with. It also names each name whose advisories the solve could
 * not read and that its result holds.
 *
 * @internal
 */
final class Solution
{
    /** @var array<string, string> */
    private array $installs;
    /** @var list<Holder> */
    private array $holds;
    /** @var string|\Closure(): string|null */
    private $problem;
    /** @var array<string, string> */
    private array $unloaded;
    /** @var array<string, string> */
    private array $updatedFrom = [];
    /** @var list<string> */
    private array $advisoryUnread = [];
    /** @var array<string>|null */
    private ?array $held = null;
    /** @var array{class: string, message: string}|null */
    private ?array $error = null;

    /**
     * @param array<string, string> $installs name => the pretty version that the solve installs or keeps, for each name it changes
     * @param list<Holder>          $holds
     * @param string|\Closure(): string|null $problem Composer's problem text, rendered when read
     * @param array<string, string> $unloaded name => {@see VersionSource} failure, by name
     */
    private function __construct(array $installs, array $holds, $problem, array $unloaded)
    {
        $this->installs = $installs;
        $this->holds = $holds;
        $this->problem = $problem;
        $this->unloaded = $unloaded;
    }

    /**
     * @param array<string, string> $installs
     * @param array<string, string> $updatedFrom name => the locked pretty version, for each name that the solve updates
     * @param array<string, string> $unloaded       the names that the solve asked for and could not load
     * @param list<string>          $advisoryUnread the names that the result holds and whose advisories the solve could not read
     * @param array<string>|null    $held           the name of every package that the result holds, without the names it replaces. Null when unknown
     */
    public static function resolved(array $installs, array $updatedFrom = [], array $unloaded = [], array $advisoryUnread = [], ?array $held = null): self
    {
        $solution = new self($installs, [], null, $unloaded);
        $solution->updatedFrom = $updatedFrom;
        $solution->advisoryUnread = $advisoryUnread;
        $solution->held = $held;

        return $solution;
    }

    /**
     * @param list<Holder>              $holds
     * @param string|\Closure(): string $problem
     * @param array<string, string>     $unloaded
     */
    public static function refused(array $holds, $problem, array $unloaded): self
    {
        return new self([], $holds, $problem, $unloaded);
    }

    /** A solve that Composer failed on with an exception other than a refusal. */
    public static function failed(\Throwable $e): self
    {
        $failed = new self([], [], null, []);
        $failed->error = ['class' => \get_class($e), 'message' => self::errorText($e)];

        return $failed;
    }

    /** A TypeError's message names a PHP signature and an absolute path, which a report does not print. */
    public static function errorText(\Throwable $e): string
    {
        return $e instanceof \TypeError ? 'a value has the wrong type' : $e->getMessage();
    }

    public function resolves(): bool
    {
        return $this->problem === null && $this->error === null;
    }

    /** @return array{class: string, message: string}|null */
    public function error(): ?array
    {
        return $this->error;
    }

    /** @return array<string, string> */
    public function installs(): array
    {
        return $this->installs;
    }

    /** @return array<string, string> name => the locked pretty version, for each name that the solve updates */
    public function updatedFrom(): array
    {
        return $this->updatedFrom;
    }

    /** @return list<Holder> */
    public function holds(): array
    {
        return $this->holds;
    }

    public function problem(): ?string
    {
        if ($this->problem instanceof \Closure) {
            $this->problem = ($this->problem)();
        }

        return $this->problem;
    }

    /** @return array<string, string> */
    public function unloaded(): array
    {
        return $this->unloaded;
    }

    /** Whether the result holds the package $name: false when the solve drops it, also for a release that replaces it. True when the solution does not know. */
    public function keeps(string $name): bool
    {
        return $this->held === null || \in_array($name, $this->held, true);
    }

    /** @return list<string> the names that the result holds and whose advisories the solve could not read */
    public function advisoryUnread(): array
    {
        return $this->advisoryUnread;
    }
}
