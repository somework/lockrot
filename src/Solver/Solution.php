<?php

declare(strict_types=1);

namespace Lockrot\Solver;

use Lockrot\Security\Holder;

/**
 * What one solve gave: the versions it installs, or the holds and Composer's problem text of a
 * refusal, with the names that a refusal needs and that the {@see VersionSource} could not load, or
 * the exception that Composer failed with.
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

    /** @param array<string, string> $installs */
    public static function resolved(array $installs): self
    {
        return new self($installs, [], null, []);
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
    public static function failed(\Exception $e): self
    {
        $failed = new self([], [], null, []);
        $failed->error = ['class' => \get_class($e), 'message' => $e->getMessage()];

        return $failed;
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
}
