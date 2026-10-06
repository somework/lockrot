<?php

declare(strict_types=1);

namespace Lockrot\Data\Advisory;

use Composer\Repository\AdvisoryProviderInterface;
use Composer\Repository\RepositoryInterface;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\VersionParser;
use Lockrot\Analyzer\RunNote;
use Lockrot\Deadline;

/**
 * Fetches security advisories through Composer's repository layer, as `composer audit` does
 * ({@see \Composer\Repository\RepositorySet::getMatchingSecurityAdvisories()}), and drops those
 * that {@see AdvisoryIgnore} lists. It asks for full records only: the partial records in
 * Composer's package-file cache are never the answer. Composer hard-codes a ten-second timeout for
 * the POST, so lockrot checks the deadline before each repository, not inside the call.
 * Notes and scope: docs/verdicts.md#security-advisories.
 *
 * @internal
 */
final class RepositoryAdvisoryLoader implements AdvisoryLoaderInterface
{
    /** @var list<RepositoryInterface> */
    private array $repositories;
    private bool $offline;
    private Deadline $deadline;
    private AdvisoryIgnore $ignore;
    private VersionParser $parser;

    /** @param list<RepositoryInterface> $repositories the project's Composer repositories, in configured order */
    public function __construct(array $repositories, bool $offline = false, ?Deadline $deadline = null, ?AdvisoryIgnore $ignore = null)
    {
        $this->repositories = $repositories;
        $this->offline = $offline;
        $this->deadline = $deadline ?? Deadline::never();
        $this->ignore = $ignore ?? AdvisoryIgnore::none();
        $this->parser = new VersionParser();
    }

    public function load(array $versionByName): AdvisoryBatch
    {
        $map = $this->constraints($versionByName);
        if ($map === []) {
            return AdvisoryBatch::empty();
        }
        if ($this->offline) {
            return AdvisoryBatch::unavailable(RunNote::advisoriesNotChecked(RunNote::ADVISORIES_OFFLINE, 0));
        }
        // The interface exists only on Composer 2.4 and later. The guard must stay for 2.2.
        if (!interface_exists(AdvisoryProviderInterface::class)) {
            return AdvisoryBatch::unavailable(RunNote::advisoriesNotChecked(RunNote::ADVISORIES_COMPOSER_TOO_OLD, 0));
        }

        /** @var array<string, array<string, Advisory>> $byName name => advisory id => advisory, so an advisory that two repositories serve counts once */
        $byName = [];
        $whyUnreadable = $this->ignore->whyUnreadable();
        $notes = $whyUnreadable === null ? [] : [RunNote::advisoryIgnoreUnreadable($whyUnreadable)];
        $asked = 0;
        foreach ($this->repositories as $repository) {
            if (!$repository instanceof AdvisoryProviderInterface) {
                continue;
            }
            if ($this->deadline->isPast()) {
                return new AdvisoryBatch(self::lists($byName), array_merge($notes, [RunNote::advisoriesNotChecked(RunNote::INSTALL_TIME_BUDGET, $asked)]));
            }
            ++$asked;

            try {
                if (!$repository->hasSecurityAdvisories()) {
                    continue;
                }
                $answer = $repository->getSecurityAdvisories($map, false)['advisories'];
            } catch (\Throwable $e) {
                // ComposerRepository::fetchFile() can throw a ParsingException, a
                // RepositorySecurityException or a LogicException, none of them a RuntimeException.
                // A failing repository must become a run note, never the end of the report. Only a
                // TransportException is a network failure, which the note decides.
                $notes[] = RunNote::advisoriesUnavailable($repository->getRepoName(), $e);
                continue;
            }
            foreach ($answer as $name => $advisories) {
                foreach ($advisories as $advisory) {
                    if ($this->ignore->ignores($name, $advisory)) {
                        continue;
                    }
                    $byName[$name][$advisory->advisoryId] = Advisory::fromComposer($advisory);
                }
            }
        }

        return new AdvisoryBatch(self::lists($byName), $notes);
    }

    /**
     * @param array<string, array<string, Advisory>> $byName
     *
     * @return array<string, list<Advisory>>
     */
    private static function lists(array $byName): array
    {
        $lists = [];
        foreach ($byName as $name => $advisories) {
            $lists[$name] = array_values($advisories);
        }

        return $lists;
    }

    /**
     * One `== <normalized version>` constraint per name, as `composer audit` builds it. A version
     * that the parser rejects has no constraint.
     *
     * @param array<string, string> $versionByName
     *
     * @return array<string, Constraint>
     */
    private function constraints(array $versionByName): array
    {
        $map = [];
        foreach ($versionByName as $name => $version) {
            try {
                $map[$name] = new Constraint('==', $this->parser->normalize($version));
            } catch (\UnexpectedValueException $e) {
                continue;
            }
        }

        return $map;
    }
}
